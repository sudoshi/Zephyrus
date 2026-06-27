<?php

namespace App\Services\Rtdc;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the RTDC Performance Metrics page payload from the live `prod` schema
 * (DB_SCHEMA=prod).
 *
 * Surfaces the throughput & reliability scorecard the operations bridge tracks:
 *   - LOS vs GMLOS (observed average length-of-stay against the geometric-mean
 *     reference, house-wide and per unit type — discharged encounters)
 *   - Discharge-by-noon rate (share of discharges completed before 12:00)
 *   - ED boarding hours (admit-decision → bed-assigned for admitted ED visits)
 *   - Bed-request turnaround (request → placement, completed requests only)
 *   - Forecast reliability (rtdc_reconciliations: predicted vs actual + the
 *     stored reliability_score) as a daily trend over the recorded window
 *
 * All computation is deterministic and safe on empty tables: every accessor
 * returns zeros / empty collections rather than throwing. A 14-day lookback
 * window is used for the encounter/ED/bed-request KPIs; the reliability trend
 * uses the full recorded reconciliation window (capped to the last 14 days).
 *
 * Returned shape:
 *   kpis: array{
 *     avgLos: float, gmlos: float, losIndex: float, losDelta: float,
 *     dischargeByNoonRate: int, dischargedTotal: int,
 *     avgBoardingHours: float, totalBoardingHours: float, boardedCount: int,
 *     avgTurnaroundHours: float, placedCount: int,
 *     forecastReliability: int, reliabilityTrend: 'up'|'down'|'flat'
 *   }
 *   reliabilityTrend: list<array{date:string,reliability:int,predicted:int,actual:int}>
 *   losByType: list<array{type:string,label:string,avgLos:float,gmlos:float,index:float,discharged:int,status:string}>
 *   reconciliationRows: list<array<string,mixed>>
 *   meta: array{windowDays:int,generatedAt:string,hasData:bool}
 */
class PerformanceAnalyticsService
{
    /** Lookback window (days) for encounter / ED / bed-request KPIs. */
    private const WINDOW_DAYS = 14;

    /** Human-readable label per unit `type`. */
    private const TYPE_LABELS = [
        'ed' => 'Emergency',
        'icu' => 'ICU',
        'step_down' => 'Step-down',
        'med_surg' => 'Med/Surg',
        'or' => 'Operating Room',
    ];

    /**
     * Full Performance Metrics payload.
     *
     * @return array{
     *     kpis: array<string,mixed>,
     *     reliabilityTrend: list<array<string,int|string>>,
     *     losByType: list<array<string,mixed>>,
     *     reconciliationRows: list<array<string,mixed>>,
     *     meta: array{windowDays:int,generatedAt:string,hasData:bool}
     * }
     */
    public function build(): array
    {
        $gmlosByType = $this->gmlosByType();
        $losByType = $this->losByUnitType($gmlosByType);

        $losHouse = $this->houseWideLos($losByType);
        $dischargeNoon = $this->dischargeByNoon();
        $boarding = $this->edBoarding();
        $turnaround = $this->bedRequestTurnaround();
        $reliabilityTrend = $this->reliabilityTrend();
        $reliabilityNow = $this->currentReliability($reliabilityTrend);

        $hasData = $losHouse['discharged'] > 0
            || $dischargeNoon['total'] > 0
            || $boarding['count'] > 0
            || count($reliabilityTrend) > 0;

        return [
            'kpis' => [
                'avgLos' => $losHouse['avgLos'],
                'gmlos' => $losHouse['gmlos'],
                'losIndex' => $losHouse['index'],
                'losDelta' => round($losHouse['avgLos'] - $losHouse['gmlos'], 2),
                'dischargeByNoonRate' => $dischargeNoon['rate'],
                'dischargedTotal' => $dischargeNoon['total'],
                'avgBoardingHours' => $boarding['avgHours'],
                'totalBoardingHours' => $boarding['totalHours'],
                'boardedCount' => $boarding['count'],
                'avgTurnaroundHours' => $turnaround['avgHours'],
                'placedCount' => $turnaround['count'],
                'forecastReliability' => $reliabilityNow['value'],
                'reliabilityTrend' => $reliabilityNow['direction'],
            ],
            'reliabilityTrend' => $reliabilityTrend,
            'losByType' => $losByType,
            'reconciliationRows' => $this->reconciliationRows(),
            'meta' => [
                'windowDays' => self::WINDOW_DAYS,
                'generatedAt' => Carbon::now()->format('Y-m-d H:i'),
                'hasData' => $hasData,
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // LOS vs GMLOS
    // -----------------------------------------------------------------------

    /**
     * GMLOS reference days keyed by unit type (latest effective row per type).
     *
     * @return array<string,float>
     */
    private function gmlosByType(): array
    {
        $rows = DB::select(
            'SELECT DISTINCT ON (unit_type) unit_type, gmlos_days
             FROM prod.gmlos_references
             ORDER BY unit_type, effective_from DESC'
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->unit_type] = (float) $row->gmlos_days;
        }

        return $out;
    }

    /**
     * Observed average LOS (days) per unit type for discharged encounters in
     * the window, paired with the GMLOS reference and an LOS index.
     *
     * @param  array<string,float>  $gmlosByType
     * @return list<array<string,mixed>>
     */
    private function losByUnitType(array $gmlosByType): array
    {
        $rows = DB::select(
            "SELECT
                u.type AS unit_type,
                COUNT(*) AS discharged,
                AVG(EXTRACT(EPOCH FROM (e.discharged_at - e.admitted_at)) / 86400.0) AS avg_los_days
             FROM prod.encounters e
             JOIN prod.units u ON u.unit_id = e.unit_id AND u.is_deleted = false
             WHERE e.is_deleted = false
               AND e.status = 'discharged'
               AND e.discharged_at IS NOT NULL
               AND e.admitted_at IS NOT NULL
               AND e.discharged_at >= CURRENT_DATE - (?::int * INTERVAL '1 day')
             GROUP BY u.type
             ORDER BY u.type",
            [self::WINDOW_DAYS]
        );

        $out = [];
        foreach ($rows as $row) {
            $type = (string) $row->unit_type;
            $avgLos = round((float) $row->avg_los_days, 2);
            $gmlos = (float) ($gmlosByType[$type] ?? 0.0);
            $index = $gmlos > 0 ? round($avgLos / $gmlos, 2) : 0.0;

            $out[] = [
                'type' => $type,
                'label' => self::TYPE_LABELS[$type] ?? ucfirst(str_replace('_', '-', $type)),
                'avgLos' => $avgLos,
                'gmlos' => round($gmlos, 2),
                'index' => $index,
                'discharged' => (int) $row->discharged,
                'status' => $this->losIndexStatus($index),
            ];
        }

        return $out;
    }

    /**
     * Discharge-weighted house-wide LOS / GMLOS roll-up across types.
     *
     * @param  list<array<string,mixed>>  $losByType
     * @return array{avgLos:float,gmlos:float,index:float,discharged:int}
     */
    private function houseWideLos(array $losByType): array
    {
        $losWeighted = 0.0;
        $gmlosWeighted = 0.0;
        $discharged = 0;

        foreach ($losByType as $row) {
            $n = (int) $row['discharged'];
            if ($n === 0) {
                continue;
            }
            $losWeighted += (float) $row['avgLos'] * $n;
            $gmlosWeighted += (float) $row['gmlos'] * $n;
            $discharged += $n;
        }

        if ($discharged === 0) {
            return ['avgLos' => 0.0, 'gmlos' => 0.0, 'index' => 0.0, 'discharged' => 0];
        }

        $avgLos = round($losWeighted / $discharged, 2);
        $gmlos = round($gmlosWeighted / $discharged, 2);
        $index = $gmlos > 0 ? round($avgLos / $gmlos, 2) : 0.0;

        return ['avgLos' => $avgLos, 'gmlos' => $gmlos, 'index' => $index, 'discharged' => $discharged];
    }

    // -----------------------------------------------------------------------
    // Discharge-by-noon
    // -----------------------------------------------------------------------

    /**
     * Share of discharges completed before 12:00 over the window.
     *
     * @return array{rate:int,total:int,beforeNoon:int}
     */
    private function dischargeByNoon(): array
    {
        $row = DB::selectOne(
            "SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE e.discharged_at::time < TIME '12:00') AS before_noon
             FROM prod.encounters e
             WHERE e.is_deleted = false
               AND e.status = 'discharged'
               AND e.discharged_at IS NOT NULL
               AND e.discharged_at >= CURRENT_DATE - (?::int * INTERVAL '1 day')",
            [self::WINDOW_DAYS]
        );

        $total = (int) ($row->total ?? 0);
        $beforeNoon = (int) ($row->before_noon ?? 0);
        $rate = $total > 0 ? (int) round($beforeNoon / $total * 100) : 0;

        return ['rate' => $rate, 'total' => $total, 'beforeNoon' => $beforeNoon];
    }

    // -----------------------------------------------------------------------
    // ED boarding hours
    // -----------------------------------------------------------------------

    /**
     * Admit-decision → bed-assigned interval for admitted ED visits (window).
     *
     * @return array{avgHours:float,totalHours:float,count:int}
     */
    private function edBoarding(): array
    {
        $row = DB::selectOne(
            "SELECT
                COUNT(*) AS cnt,
                AVG(EXTRACT(EPOCH FROM (v.bed_assigned_at - v.admit_decision_at)) / 3600.0) AS avg_hrs,
                SUM(EXTRACT(EPOCH FROM (v.bed_assigned_at - v.admit_decision_at)) / 3600.0) AS total_hrs
             FROM prod.ed_visits v
             WHERE v.is_deleted = false
               AND v.disposition = 'admitted'
               AND v.bed_assigned_at IS NOT NULL
               AND v.admit_decision_at IS NOT NULL
               AND v.arrived_at >= CURRENT_DATE - (?::int * INTERVAL '1 day')",
            [self::WINDOW_DAYS]
        );

        return [
            'count' => (int) ($row->cnt ?? 0),
            'avgHours' => round((float) ($row->avg_hrs ?? 0), 2),
            'totalHours' => round((float) ($row->total_hrs ?? 0), 1),
        ];
    }

    // -----------------------------------------------------------------------
    // Bed-request turnaround
    // -----------------------------------------------------------------------

    /**
     * Request → placement turnaround for completed (placed/assigned) bed
     * requests in the window. Guards against zero-duration rows (request and
     * placement timestamped identically) so a degenerate average reads honestly.
     *
     * @return array{avgHours:float,count:int}
     */
    private function bedRequestTurnaround(): array
    {
        $row = DB::selectOne(
            "SELECT
                COUNT(*) AS cnt,
                AVG(EXTRACT(EPOCH FROM (br.updated_at - br.created_at)) / 3600.0) AS avg_hrs
             FROM prod.bed_requests br
             WHERE br.is_deleted = false
               AND br.status IN ('placed', 'assigned', 'completed', 'fulfilled')
               AND br.updated_at IS NOT NULL
               AND br.created_at IS NOT NULL
               AND br.updated_at > br.created_at
               AND br.created_at >= CURRENT_DATE - (?::int * INTERVAL '1 day')",
            [self::WINDOW_DAYS]
        );

        return [
            'count' => (int) ($row->cnt ?? 0),
            'avgHours' => round((float) ($row->avg_hrs ?? 0), 2),
        ];
    }

    // -----------------------------------------------------------------------
    // Forecast reliability (rtdc_reconciliations)
    // -----------------------------------------------------------------------

    /**
     * Daily forecast-reliability trend over the recorded reconciliation window
     * (capped to the last 14 service dates). Reliability is the mean stored
     * reliability_score per day, expressed 0..100.
     *
     * @return list<array{date:string,reliability:int,predicted:int,actual:int}>
     */
    private function reliabilityTrend(): array
    {
        $rows = DB::select(
            'SELECT
                r.service_date,
                AVG(r.reliability_score) AS avg_rel,
                SUM(r.predicted_discharges) AS predicted,
                SUM(r.actual_discharges) AS actual
             FROM prod.rtdc_reconciliations r
             WHERE r.service_date >= CURRENT_DATE - (?::int * INTERVAL \'1 day\')
             GROUP BY r.service_date
             ORDER BY r.service_date',
            [self::WINDOW_DAYS]
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'date' => (string) $row->service_date,
                'reliability' => (int) round((float) $row->avg_rel * 100),
                'predicted' => (int) round((float) $row->predicted),
                'actual' => (int) round((float) $row->actual),
            ];
        }

        return $out;
    }

    /**
     * Latest reliability value + direction vs the prior day.
     *
     * @param  list<array{date:string,reliability:int,predicted:int,actual:int}>  $trend
     * @return array{value:int,direction:'up'|'down'|'flat'}
     */
    private function currentReliability(array $trend): array
    {
        $n = count($trend);
        if ($n === 0) {
            return ['value' => 0, 'direction' => 'flat'];
        }

        $latest = (int) $trend[$n - 1]['reliability'];
        if ($n === 1) {
            return ['value' => $latest, 'direction' => 'flat'];
        }

        $prev = (int) $trend[$n - 2]['reliability'];
        $direction = $latest > $prev ? 'up' : ($latest < $prev ? 'down' : 'flat');

        return ['value' => $latest, 'direction' => $direction];
    }

    /**
     * Most-recent reconciliation rows per unit (latest service date), as a
     * reliability table for the page.
     *
     * @return list<array<string,mixed>>
     */
    private function reconciliationRows(): array
    {
        $rows = DB::select(
            'SELECT DISTINCT ON (r.unit_id)
                r.unit_id, r.service_date,
                r.predicted_discharges, r.actual_discharges,
                r.predicted_admissions, r.actual_admissions,
                r.reliability_score,
                u.name AS unit_name, u.abbreviation, u.type AS unit_type
             FROM prod.rtdc_reconciliations r
             JOIN prod.units u ON u.unit_id = r.unit_id AND u.is_deleted = false
             ORDER BY r.unit_id, r.service_date DESC'
        );

        $out = [];
        foreach ($rows as $row) {
            $predDc = (int) round((float) $row->predicted_discharges);
            $actDc = (int) $row->actual_discharges;
            $reliability = (int) round((float) $row->reliability_score * 100);

            $out[] = [
                'unitId' => (int) $row->unit_id,
                'unit' => (string) ($row->abbreviation ?: $row->unit_name),
                'type' => self::TYPE_LABELS[(string) $row->unit_type] ?? ucfirst(str_replace('_', '-', (string) $row->unit_type)),
                'serviceDate' => (string) $row->service_date,
                'predictedDischarges' => $predDc,
                'actualDischarges' => $actDc,
                'predictedAdmissions' => (int) $row->predicted_admissions,
                'actualAdmissions' => (int) $row->actual_admissions,
                'reliability' => $reliability,
                'status' => $this->reliabilityStatus($reliability),
            ];
        }

        // Surface the least-reliable units first — that is where the page earns
        // its attention.
        usort($out, fn ($a, $b): int => $a['reliability'] <=> $b['reliability']);

        return array_slice($out, 0, 12);
    }

    // -----------------------------------------------------------------------
    // Status vocabulary helpers (healthcare four-color)
    // -----------------------------------------------------------------------

    /** LOS index → status: at/under GMLOS is good; well over is critical. */
    private function losIndexStatus(float $index): string
    {
        if ($index === 0.0) {
            return 'info';
        }
        if ($index >= 1.20) {
            return 'critical';
        }
        if ($index >= 1.05) {
            return 'warning';
        }

        return 'success';
    }

    /** Reliability % → status. */
    private function reliabilityStatus(int $reliability): string
    {
        if ($reliability >= 90) {
            return 'success';
        }
        if ($reliability >= 80) {
            return 'warning';
        }

        return 'critical';
    }
}
