<?php

namespace App\Services\Rtdc;

use Illuminate\Support\Facades\DB;

/**
 * Builds the RTDC "Trends & Patterns" page payload from the live `prod` schema
 * (DB_SCHEMA=prod).
 *
 * The trend spine is `prod.rtdc_reconciliations` — a per-unit, per-day record of
 * predicted vs actual discharges/admissions plus a reliability score. Aggregated
 * across non-deleted units it yields a clean multi-week daily history of patient
 * flow and forecast reliability. Census occupancy (latest snapshot per unit) and
 * day-of-week pattern roll-ups round out the page's pattern KPIs.
 *
 * Returned shape:
 *   kpis: array<string,mixed>             // headline pattern metrics
 *   flowSeries: list<DailyFlowPoint>      // daily predicted/actual dc & adm
 *   reliabilitySeries: list<ReliabilityPoint>
 *   dowPattern: list<DowRow>              // day-of-week roll-up (table)
 *   meta: array{windowDays:int, fromDate:?string, toDate:?string, units:int}
 *
 * Computation is deterministic and safe on empty tables: every accessor returns
 * zeros / empty collections rather than throwing. All unit joins enforce
 * `is_deleted = false` per the Token Canon / data conventions.
 */
class TrendsAnalyticsService
{
    /** ISO-8601 weekday (1=Mon … 7=Sun) → short label. */
    private const DOW_LABELS = [
        1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun',
    ];

    /**
     * Full Trends & Patterns payload.
     *
     * @return array{
     *     kpis: array<string,mixed>,
     *     flowSeries: list<array<string,mixed>>,
     *     reliabilitySeries: list<array<string,mixed>>,
     *     dowPattern: list<array<string,mixed>>,
     *     meta: array{windowDays:int, fromDate:?string, toDate:?string, units:int}
     * }
     */
    public function build(): array
    {
        $daily = $this->dailyReconciliation();
        $occByDay = $this->dailyOccupancy();
        $dowPattern = $this->dayOfWeekPattern();

        return [
            'kpis' => $this->kpis($daily, $occByDay, $dowPattern),
            'flowSeries' => $this->flowSeries($daily),
            'reliabilitySeries' => $this->reliabilitySeries($daily),
            'dowPattern' => $dowPattern,
            'meta' => $this->meta($daily),
        ];
    }

    // -----------------------------------------------------------------------
    // Source queries
    // -----------------------------------------------------------------------

    /**
     * House-wide reconciliation aggregates per service_date (live units only).
     *
     * @return list<object>
     */
    private function dailyReconciliation(): array
    {
        return DB::select(
            'SELECT r.service_date::text AS service_date,
                    SUM(r.predicted_discharges)::int  AS predicted_discharges,
                    SUM(r.actual_discharges)::int     AS actual_discharges,
                    SUM(r.predicted_admissions)::int  AS predicted_admissions,
                    SUM(r.actual_admissions)::int     AS actual_admissions,
                    AVG(r.reliability_score)::float8  AS reliability_score,
                    COUNT(*)::int                     AS unit_rows
             FROM prod.rtdc_reconciliations r
             JOIN prod.units u ON u.unit_id = r.unit_id AND u.is_deleted = false
             GROUP BY r.service_date
             ORDER BY r.service_date'
        );
    }

    /**
     * Latest census snapshot per unit per day → house occupancy %, by day.
     * Census is sparse, so this drives KPI aggregates rather than the daily line.
     *
     * @return list<object>
     */
    private function dailyOccupancy(): array
    {
        return DB::select(
            "WITH latest_per_unit_day AS (
                SELECT DISTINCT ON (cs.unit_id, date_trunc('day', cs.captured_at))
                       date_trunc('day', cs.captured_at)::date AS day,
                       cs.unit_id, cs.occupied, cs.staffed_beds
                FROM prod.census_snapshots cs
                JOIN prod.units u ON u.unit_id = cs.unit_id AND u.is_deleted = false
                ORDER BY cs.unit_id, date_trunc('day', cs.captured_at), cs.captured_at DESC
             )
             SELECT day::text AS day,
                    SUM(occupied)::int     AS occupied,
                    SUM(staffed_beds)::int AS staffed,
                    EXTRACT(ISODOW FROM day)::int AS isodow
             FROM latest_per_unit_day
             GROUP BY day
             ORDER BY day"
        );
    }

    /**
     * Day-of-week pattern: averaged actual admissions/discharges + reliability
     * across the reconciliation history (live units only).
     *
     * @return list<array<string,mixed>>
     */
    private function dayOfWeekPattern(): array
    {
        $rows = DB::select(
            'SELECT EXTRACT(ISODOW FROM r.service_date)::int AS isodow,
                    COUNT(DISTINCT r.service_date)::int       AS days,
                    SUM(r.actual_admissions)::int             AS total_admissions,
                    SUM(r.actual_discharges)::int             AS total_discharges,
                    AVG(r.reliability_score)::float8          AS reliability_score
             FROM prod.rtdc_reconciliations r
             JOIN prod.units u ON u.unit_id = r.unit_id AND u.is_deleted = false
             GROUP BY EXTRACT(ISODOW FROM r.service_date)
             ORDER BY EXTRACT(ISODOW FROM r.service_date)'
        );

        $out = [];
        foreach ($rows as $row) {
            $isodow = (int) $row->isodow;
            $days = max(1, (int) $row->days);
            $admissions = (int) $row->total_admissions;
            $discharges = (int) $row->total_discharges;
            $avgAdmissions = (int) round($admissions / $days);
            $avgDischarges = (int) round($discharges / $days);
            $netFlow = $avgAdmissions - $avgDischarges;

            $out[] = [
                'isodow' => $isodow,
                'day' => self::DOW_LABELS[$isodow] ?? "D{$isodow}",
                'avgAdmissions' => $avgAdmissions,
                'avgDischarges' => $avgDischarges,
                'netFlow' => $netFlow,
                'reliability' => (int) round(((float) $row->reliability_score) * 100),
                'sampleDays' => $days,
                // Net positive flow (more in than out) is the pressure signal.
                'status' => $this->netFlowStatus($netFlow),
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // KPI roll-ups
    // -----------------------------------------------------------------------

    /**
     * @param  list<object>  $daily
     * @param  list<object>  $occByDay
     * @param  list<array<string,mixed>>  $dowPattern
     * @return array<string,mixed>
     */
    private function kpis(array $daily, array $occByDay, array $dowPattern): array
    {
        $dayCount = count($daily);

        // --- Flow averages over the window -------------------------------
        $sumActualDc = 0;
        $sumActualAdm = 0;
        $sumPredDc = 0;
        $reliabilitySum = 0.0;
        foreach ($daily as $d) {
            $sumActualDc += (int) $d->actual_discharges;
            $sumActualAdm += (int) $d->actual_admissions;
            $sumPredDc += (int) $d->predicted_discharges;
            $reliabilitySum += (float) $d->reliability_score;
        }
        $avgDischarges = $dayCount > 0 ? (int) round($sumActualDc / $dayCount) : 0;
        $avgAdmissions = $dayCount > 0 ? (int) round($sumActualAdm / $dayCount) : 0;
        $avgReliability = $dayCount > 0 ? (int) round($reliabilitySum / $dayCount * 100) : 0;

        // Forecast bias: actual ÷ predicted discharges over the window.
        // 100% = perfectly calibrated; <100% = over-forecasting discharges.
        $forecastBias = $sumPredDc > 0 ? (int) round($sumActualDc / $sumPredDc * 100) : 0;

        // --- Occupancy averages (census) --------------------------------
        $occSumOcc = 0;
        $occSumStaffed = 0;
        $occByDow = [];
        foreach ($occByDay as $o) {
            $occSumOcc += (int) $o->occupied;
            $occSumStaffed += (int) $o->staffed;
            $staffed = (int) $o->staffed;
            $pct = $staffed > 0 ? (int) round((int) $o->occupied / $staffed * 100) : 0;
            $isodow = (int) $o->isodow;
            $occByDow[$isodow][] = $pct;
        }
        $avgOccupancy = $occSumStaffed > 0 ? (int) round($occSumOcc / $occSumStaffed * 100) : 0;

        // --- Peak occupancy day-of-week ---------------------------------
        $peakDay = '—';
        $peakOccupancy = 0;
        foreach ($occByDow as $isodow => $pcts) {
            $mean = (int) round(array_sum($pcts) / max(1, count($pcts)));
            if ($mean > $peakOccupancy) {
                $peakOccupancy = $mean;
                $peakDay = self::DOW_LABELS[$isodow] ?? "D{$isodow}";
            }
        }

        // --- Busiest admission day (from DOW pattern) -------------------
        $peakAdmissionDay = '—';
        $peakAdmissions = 0;
        foreach ($dowPattern as $row) {
            if ((int) $row['avgAdmissions'] > $peakAdmissions) {
                $peakAdmissions = (int) $row['avgAdmissions'];
                $peakAdmissionDay = (string) $row['day'];
            }
        }

        return [
            'peakOccupancyDay' => $peakDay,
            'peakOccupancy' => $peakOccupancy,
            'avgOccupancy' => $avgOccupancy,
            'avgDischarges' => $avgDischarges,
            'avgAdmissions' => $avgAdmissions,
            'avgReliability' => $avgReliability,
            'forecastBias' => $forecastBias,
            'peakAdmissionDay' => $peakAdmissionDay,
            'peakAdmissions' => $peakAdmissions,
        ];
    }

    // -----------------------------------------------------------------------
    // Chart series
    // -----------------------------------------------------------------------

    /**
     * Daily predicted/actual discharges & admissions (multi-line flow chart).
     *
     * @param  list<object>  $daily
     * @return list<array<string,mixed>>
     */
    private function flowSeries(array $daily): array
    {
        $out = [];
        foreach ($daily as $d) {
            $out[] = [
                'date' => (string) $d->service_date,
                'predictedDischarges' => (int) $d->predicted_discharges,
                'actualDischarges' => (int) $d->actual_discharges,
                'predictedAdmissions' => (int) $d->predicted_admissions,
                'actualAdmissions' => (int) $d->actual_admissions,
            ];
        }

        return $out;
    }

    /**
     * Daily forecast reliability % (single-line reliability chart).
     *
     * @param  list<object>  $daily
     * @return list<array<string,mixed>>
     */
    private function reliabilitySeries(array $daily): array
    {
        $out = [];
        foreach ($daily as $d) {
            $out[] = [
                'date' => (string) $d->service_date,
                'reliability' => (int) round(((float) $d->reliability_score) * 100),
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Meta
    // -----------------------------------------------------------------------

    /**
     * @param  list<object>  $daily
     * @return array{windowDays:int, fromDate:?string, toDate:?string, units:int}
     */
    private function meta(array $daily): array
    {
        $from = null;
        $to = null;
        if ($daily !== []) {
            $from = (string) $daily[0]->service_date;
            $to = (string) $daily[count($daily) - 1]->service_date;
        }

        $units = (int) DB::table('prod.units')
            ->where('is_deleted', false)
            ->whereIn('unit_id', function ($q): void {
                $q->select('unit_id')->from('prod.rtdc_reconciliations');
            })
            ->count();

        return [
            'windowDays' => count($daily),
            'fromDate' => $from,
            'toDate' => $to,
            'units' => $units,
        ];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** Net inflow (admissions − discharges) pressure → status vocabulary. */
    private function netFlowStatus(int $netFlow): string
    {
        if ($netFlow >= 8) {
            return 'critical';
        }
        if ($netFlow >= 3) {
            return 'warning';
        }
        if ($netFlow <= -3) {
            return 'success';
        }

        return 'neutral';
    }
}
