<?php

namespace App\Services\Rtdc;

use Illuminate\Support\Facades\DB;

/**
 * Builds the RTDC Resource Planning page payload from the live `prod` schema
 * (DB_SCHEMA=prod).
 *
 * The page (resources/js/Pages/RTDC/Predictions/ResourcePlanning.jsx) answers a
 * single operational question: for the next planning horizon, does each unit's
 * STAFFED capacity cover its PREDICTED demand, and where should the next nursing
 * resource go? It does that by joining the demand side (prod.rtdc_predictions:
 * demand_expected / capacity_now / bed_need / discharges_weighted) against the
 * supply side (prod.staffing_plans: required / scheduled / actual / minimum-safe
 * per role, per unit, for today's day shift).
 *
 * Returned shape:
 *   kpis: array<string,int|float>      // house-wide planning headline numbers
 *   demandVsCapacity: list<row>        // per-unit predicted demand vs staffed capacity (chart)
 *   recommendations: list<row>         // recommended staffing actions per gapped unit (table)
 *   horizon: string                    // the planning horizon label these numbers cover
 *   serviceDate: string|null           // the prediction service date used
 *
 * `prod.rtdc_predictions.horizon` is constrained to `by_2pm` | `by_midnight`;
 * this service plans against the `by_midnight` (end-of-day) horizon — the widest
 * forward window the data supports — and falls back to `by_2pm` if midnight rows
 * are absent. Computation is deterministic (anchored on the latest available
 * prediction service_date / staffing shift_date, not on wall-clock today) and
 * safe on empty tables: every accessor returns zeros / empty collections rather
 * than throwing. ED is excluded — it is an arrival surface, not a planned ward.
 */
class ResourcePlanningAnalyticsService
{
    /** Planning horizon (widest forward window). Falls back to by_2pm. */
    private const PRIMARY_HORIZON = 'by_midnight';

    private const FALLBACK_HORIZON = 'by_2pm';

    private const HORIZON_LABELS = [
        'by_midnight' => 'By midnight',
        'by_2pm' => 'By 2 PM',
    ];

    /** Human-readable label per unit `type`. */
    private const TYPE_LABELS = [
        'ed' => 'Emergency',
        'icu' => 'Critical',
        'step_down' => 'Step-down',
        'med_surg' => 'Med/Surg',
        'or' => 'Surgical',
    ];

    /**
     * Full Resource Planning payload.
     *
     * @return array{
     *     kpis: array<string,int|float>,
     *     demandVsCapacity: list<array<string,mixed>>,
     *     recommendations: list<array<string,mixed>>,
     *     horizon: string,
     *     serviceDate: string|null
     * }
     */
    public function build(): array
    {
        $serviceDate = $this->latestPredictionDate();
        $horizon = $this->resolveHorizon($serviceDate);
        $shiftDate = $this->latestStaffingShiftDate();

        $predictions = $this->predictionsByUnit($serviceDate, $horizon);
        $staffing = $this->staffingByUnit($shiftDate);

        $demandVsCapacity = $this->demandVsCapacity($predictions, $staffing);

        return [
            'kpis' => $this->kpis($demandVsCapacity),
            'demandVsCapacity' => $demandVsCapacity,
            'recommendations' => $this->recommendations($demandVsCapacity),
            'horizon' => self::HORIZON_LABELS[$horizon] ?? ucfirst(str_replace('_', ' ', $horizon)),
            'serviceDate' => $serviceDate,
        ];
    }

    // -----------------------------------------------------------------------
    // Anchors (deterministic — latest available data, not wall-clock today)
    // -----------------------------------------------------------------------

    private function latestPredictionDate(): ?string
    {
        $row = DB::table('prod.rtdc_predictions')
            ->where('is_deleted', false)
            ->max('service_date');

        return $row !== null ? (string) $row : null;
    }

    /** Prefer the by_midnight horizon; fall back to by_2pm if absent. */
    private function resolveHorizon(?string $serviceDate): string
    {
        if ($serviceDate === null) {
            return self::PRIMARY_HORIZON;
        }

        $hasMidnight = DB::table('prod.rtdc_predictions')
            ->where('is_deleted', false)
            ->where('service_date', $serviceDate)
            ->where('horizon', self::PRIMARY_HORIZON)
            ->exists();

        return $hasMidnight ? self::PRIMARY_HORIZON : self::FALLBACK_HORIZON;
    }

    private function latestStaffingShiftDate(): ?string
    {
        $row = DB::table('prod.staffing_plans')
            ->where('is_deleted', false)
            ->max('shift_date');

        return $row !== null ? (string) $row : null;
    }

    // -----------------------------------------------------------------------
    // Source queries
    // -----------------------------------------------------------------------

    /**
     * Predicted demand / capacity per non-deleted, non-ED unit for the chosen
     * service date + horizon.
     *
     * @return array<int,object>
     */
    private function predictionsByUnit(?string $serviceDate, string $horizon): array
    {
        if ($serviceDate === null) {
            return [];
        }

        $rows = DB::select(
            "SELECT
                p.unit_id,
                u.name AS unit_name,
                u.abbreviation,
                u.type AS unit_type,
                p.demand_expected,
                p.capacity_now,
                p.bed_need,
                p.discharges_weighted
             FROM prod.rtdc_predictions p
             JOIN prod.units u ON u.unit_id = p.unit_id AND u.is_deleted = false
             WHERE p.is_deleted = false
               AND p.service_date = ?
               AND p.horizon = ?
               AND u.type <> 'ed'
             ORDER BY u.type, u.abbreviation",
            [$serviceDate, $horizon]
        );

        $byUnit = [];
        foreach ($rows as $row) {
            $byUnit[(int) $row->unit_id] = $row;
        }

        return $byUnit;
    }

    /**
     * Today-shift staffing supply aggregated per unit. Returns the headline
     * nursing-line counts (required / scheduled / actual / minimum-safe) plus
     * the per-role RN/charge/tech breakdown and the worst role status.
     *
     * @return array<int,array{
     *     required:int, scheduled:int, actual:int, minimumSafe:int,
     *     census:int, status:string, roles:array<string,array{required:int,actual:int}>
     * }>
     */
    private function staffingByUnit(?string $shiftDate): array
    {
        if ($shiftDate === null) {
            return [];
        }

        $rows = DB::table('prod.staffing_plans')
            ->where('is_deleted', false)
            ->where('shift_date', $shiftDate)
            ->where('shift', 'day')
            ->whereNotNull('unit_id')
            ->get([
                'unit_id', 'role', 'required_count', 'scheduled_count',
                'actual_count', 'minimum_safe_count', 'census', 'status',
            ]);

        // Worst-status ranking so a unit's headline status reflects its weakest role.
        $rank = ['balanced' => 0, 'surplus' => 0, 'watch' => 1, 'gap' => 2, 'critical_gap' => 3];

        $byUnit = [];
        foreach ($rows as $row) {
            $unitId = (int) $row->unit_id;
            $byUnit[$unitId] ??= [
                'required' => 0,
                'scheduled' => 0,
                'actual' => 0,
                'minimumSafe' => 0,
                'census' => 0,
                'status' => 'balanced',
                'roles' => [],
            ];

            $byUnit[$unitId]['required'] += (int) $row->required_count;
            $byUnit[$unitId]['scheduled'] += (int) $row->scheduled_count;
            $byUnit[$unitId]['actual'] += (int) $row->actual_count;
            $byUnit[$unitId]['minimumSafe'] += (int) $row->minimum_safe_count;
            // census is a per-unit attribute repeated on each role row; take max.
            $byUnit[$unitId]['census'] = max($byUnit[$unitId]['census'], (int) $row->census);

            $role = strtolower((string) $row->role);
            $byUnit[$unitId]['roles'][$role] ??= ['required' => 0, 'actual' => 0];
            $byUnit[$unitId]['roles'][$role]['required'] += (int) $row->required_count;
            $byUnit[$unitId]['roles'][$role]['actual'] += (int) $row->actual_count;

            $status = (string) $row->status;
            if (($rank[$status] ?? 0) > ($rank[$byUnit[$unitId]['status']] ?? 0)) {
                $byUnit[$unitId]['status'] = $status;
            }
        }

        return $byUnit;
    }

    // -----------------------------------------------------------------------
    // Per-unit demand vs staffed capacity (chart + table source of truth)
    // -----------------------------------------------------------------------

    /**
     * One row per planned unit pairing predicted demand against staffed supply.
     *
     * `predictedDemand` = expected incoming bed demand at the horizon.
     * `staffedCapacity` = beds the present RN line can safely cover, derived as
     *   actual RNs at the unit's safe ratio (census / minimum-safe RNs), capped
     *   so we never claim more capacity than the unit physically reports.
     * `bedNeed` is the prediction's own residual bed-need signal.
     *
     * @param  array<int,object>  $predictions
     * @param  array<int,array<string,mixed>>  $staffing
     * @return list<array<string,mixed>>
     */
    private function demandVsCapacity(array $predictions, array $staffing): array
    {
        $out = [];

        foreach ($predictions as $unitId => $p) {
            $staff = $staffing[$unitId] ?? null;

            $predictedDemand = (int) $p->demand_expected;
            $bedNeed = (int) $p->bed_need;
            $expectedDischarges = (int) round((float) $p->discharges_weighted);
            $physicalCapacity = (int) $p->capacity_now;

            // Supply side from staffing.
            $rnActual = (int) ($staff['roles']['rn']['actual'] ?? 0);
            $rnRequired = (int) ($staff['roles']['rn']['required'] ?? 0);
            $census = (int) ($staff['census'] ?? 0);
            $minimumSafeRn = (int) ($staff['roles']['rn']['required'] ?? 0); // safe RN floor
            $staffStatus = (string) ($staff['status'] ?? 'balanced');

            // Beds the present RN line can safely cover, plus capacity freed by
            // expected discharges. Falls back to the prediction's own capacity_now
            // when no staffing row exists for the unit.
            $perRnBeds = $minimumSafeRn > 0 && $census > 0
                ? $census / $minimumSafeRn
                : 0.0;
            $staffedCapacity = $perRnBeds > 0
                ? (int) round($rnActual * $perRnBeds) - $census + $expectedDischarges
                : $physicalCapacity + $expectedDischarges;
            $staffedCapacity = max(0, $staffedCapacity);

            $gap = $predictedDemand - $staffedCapacity;
            $coverage = $predictedDemand > 0
                ? (int) round($staffedCapacity / $predictedDemand * 100)
                : 100;

            // Recommended additional RNs to close the gap at the safe ratio.
            $recommendedRn = 0;
            if ($gap > 0 && $perRnBeds > 0) {
                $recommendedRn = (int) ceil($gap / $perRnBeds);
            } elseif ($gap > 0) {
                $recommendedRn = (int) ceil($gap / 4); // 1:4 default safe ratio
            }

            $type = (string) $p->unit_type;

            $out[] = [
                'unitId' => $unitId,
                'unit' => (string) ($p->abbreviation ?: $p->unit_name),
                'unitName' => (string) $p->unit_name,
                'type' => self::TYPE_LABELS[$type] ?? ucfirst(str_replace('_', '-', $type)),
                'predictedDemand' => $predictedDemand,
                'staffedCapacity' => $staffedCapacity,
                'bedNeed' => $bedNeed,
                'expectedDischarges' => $expectedDischarges,
                'gap' => $gap,
                'coverage' => $coverage,
                'rnActual' => $rnActual,
                'rnRequired' => $rnRequired,
                'recommendedRn' => $recommendedRn,
                'staffStatus' => $staffStatus,
                'status' => $this->gapStatus($gap, $bedNeed, $staffStatus),
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // House-wide planning KPIs
    // -----------------------------------------------------------------------

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,int|float>
     */
    private function kpis(array $rows): array
    {
        $totalDemand = 0;
        $totalCapacity = 0;
        $totalBedNeed = 0;
        $totalRecommendedRn = 0;
        $unitsAtRisk = 0;

        foreach ($rows as $row) {
            $totalDemand += (int) $row['predictedDemand'];
            $totalCapacity += (int) $row['staffedCapacity'];
            $totalBedNeed += (int) $row['bedNeed'];
            $totalRecommendedRn += (int) $row['recommendedRn'];
            if (in_array($row['status'], ['critical', 'warning'], true)) {
                $unitsAtRisk++;
            }
        }

        $coverage = $totalDemand > 0
            ? (int) round($totalCapacity / $totalDemand * 100)
            : 100;
        $netGap = $totalDemand - $totalCapacity;

        return [
            'predictedDemand' => $totalDemand,
            'staffedCapacity' => $totalCapacity,
            'coverage' => $coverage,
            'netGap' => $netGap,
            'bedNeed' => $totalBedNeed,
            'unitsAtRisk' => $unitsAtRisk,
            'unitsPlanned' => count($rows),
            'recommendedRn' => $totalRecommendedRn,
        ];
    }

    // -----------------------------------------------------------------------
    // Recommended staffing actions (table) — only gapped units, worst first
    // -----------------------------------------------------------------------

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function recommendations(array $rows): array
    {
        $recs = array_values(array_filter(
            $rows,
            fn (array $r): bool => (int) $r['gap'] > 0 || (int) $r['bedNeed'] > 0 || $r['status'] !== 'success'
        ));

        usort($recs, function (array $a, array $b): int {
            return ((int) $b['gap']) <=> ((int) $a['gap'])
                ?: ((int) $b['bedNeed']) <=> ((int) $a['bedNeed']);
        });

        return array_map(function (array $r): array {
            $gap = (int) $r['gap'];
            $recommendedRn = (int) $r['recommendedRn'];

            if ($recommendedRn > 0) {
                $action = 'Add '.$recommendedRn.' RN'.($recommendedRn === 1 ? '' : 's');
            } elseif ((int) $r['bedNeed'] > 0) {
                $action = 'Open '.(int) $r['bedNeed'].' bed'.((int) $r['bedNeed'] === 1 ? '' : 's');
            } else {
                $action = 'Hold & monitor';
            }

            $priority = match ($r['status']) {
                'critical' => 'High',
                'warning' => 'Medium',
                default => 'Low',
            };

            return [
                'unitId' => $r['unitId'],
                'unit' => $r['unit'],
                'unitName' => $r['unitName'],
                'type' => $r['type'],
                'predictedDemand' => $r['predictedDemand'],
                'staffedCapacity' => $r['staffedCapacity'],
                'gap' => $gap,
                'coverage' => $r['coverage'],
                'bedNeed' => $r['bedNeed'],
                'recommendedRn' => $recommendedRn,
                'action' => $action,
                'priority' => $priority,
                'status' => $r['status'],
            ];
        }, $recs);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** Map a demand/capacity gap to the four-color status vocabulary. */
    private function gapStatus(int $gap, int $bedNeed, string $staffStatus): string
    {
        if ($gap >= 3 || $bedNeed >= 2 || $staffStatus === 'critical_gap') {
            return 'critical';
        }
        if ($gap >= 1 || $bedNeed >= 1 || in_array($staffStatus, ['gap', 'watch'], true)) {
            return 'warning';
        }

        return 'success';
    }
}
