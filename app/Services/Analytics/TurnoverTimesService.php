<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;

/**
 * Builds the Turnover Times analytics payload from the live `prod` schema,
 * matching the exact shape of the `mockTurnoverTimes` object in
 * resources/js/mock-data/turnover-times.js consumed by TurnoverTimesDashboard
 * and its five Views (Overview / Trends / Hourly / Location / Service).
 *
 * Sources: prod.case_metrics (turnover_time), prod.or_cases, prod.or_logs,
 * prod.locations, prod.rooms, prod.services.
 *
 * Design notes:
 *  - Deterministic and safe on empty tables: every section short-circuits to an
 *    empty map/array, never throws. The page keeps its mock import as the
 *    default fallback, so an empty DB still renders the original demo.
 *  - Median is PERCENTILE_CONT(0.5); average is AVG(turnover_time), both over
 *    case_metrics.turnover_time. All queries respect is_deleted = false.
 *  - The seeded demo carries ~6 months of history, which feeds the per-month
 *    trend series. Rooms/services with duplicate names are collapsed by name
 *    (the live schema has repeated service_id / room name rows).
 */
class TurnoverTimesService
{
    /**
     * Distribution buckets, in render order, as the labels the pie chart uses.
     * Mirrors mockTurnoverTimes[*].turnoverDistribution keys.
     *
     * @var list<string>
     */
    private const BUCKETS = [
        '0-15 min',
        '15-30 min',
        '30-45 min',
        '45-60 min',
        '60-90 min',
        '90+ min',
    ];

    /** @return array<string,mixed> */
    public function build(): array
    {
        return [
            'sites' => $this->sites(),
            'services' => $this->services(),
            'dayOfWeek' => $this->dayOfWeekBySite(),
            'timeOfDay' => $this->timeOfDayBySite(),
        ];
    }

    // -----------------------------------------------------------------------
    // sites — keyed by location name
    // -----------------------------------------------------------------------

    /** @return array<string,array<string,mixed>> */
    private function sites(): array
    {
        $rows = DB::select(
            'SELECT l.name AS site,
                    ROUND(PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY cm.turnover_time)::numeric) AS median_to,
                    ROUND(AVG(cm.turnover_time)::numeric, 1) AS avg_to,
                    COUNT(*) AS total_cases,
                    COUNT(cm.turnover_time) AS total_turnovers
             FROM prod.or_cases oc
             JOIN prod.locations l ON l.location_id = oc.location_id
             LEFT JOIN prod.case_metrics cm ON cm.case_id = oc.case_id AND cm.is_deleted = false
             WHERE oc.is_deleted = false
               AND l.is_deleted = false
             GROUP BY l.name
             ORDER BY l.name'
        );

        $sites = [];
        foreach ($rows as $r) {
            $name = (string) $r->site;
            $sites[$name] = [
                'medianTurnoverTime' => (int) round((float) ($r->median_to ?? 0)),
                'averageTurnoverTime' => round((float) ($r->avg_to ?? 0), 1),
                'totalCases' => (int) $r->total_cases,
                'totalTurnovers' => (int) $r->total_turnovers,
                'turnoverDistribution' => $this->distribution($name),
                'rooms' => $this->rooms($name),
                'trends' => $this->trends($name),
                'dayOfWeek' => $this->dayOfWeekSeries($name),
                'timeOfDay' => $this->timeOfDaySeries($name),
            ];
        }

        return $sites;
    }

    /** @return array<string,int> */
    private function distribution(string $site): array
    {
        $rows = DB::select(
            "SELECT CASE
                        WHEN cm.turnover_time < 15 THEN '0-15 min'
                        WHEN cm.turnover_time < 30 THEN '15-30 min'
                        WHEN cm.turnover_time < 45 THEN '30-45 min'
                        WHEN cm.turnover_time < 60 THEN '45-60 min'
                        WHEN cm.turnover_time < 90 THEN '60-90 min'
                        ELSE '90+ min'
                    END AS bucket,
                    COUNT(*) AS n
             FROM prod.case_metrics cm
             JOIN prod.or_cases oc ON oc.case_id = cm.case_id
             JOIN prod.locations l ON l.location_id = oc.location_id
             WHERE cm.is_deleted = false
               AND oc.is_deleted = false
               AND cm.turnover_time IS NOT NULL
               AND l.name = ?
             GROUP BY bucket",
            [$site]
        );

        $counts = [];
        foreach ($rows as $r) {
            $counts[(string) $r->bucket] = (int) $r->n;
        }

        // Emit buckets in canonical order; skip empty buckets so the pie chart
        // shows only ranges that actually occur (data-driven, not status).
        $dist = [];
        foreach (self::BUCKETS as $bucket) {
            if (($counts[$bucket] ?? 0) > 0) {
                $dist[$bucket] = $counts[$bucket];
            }
        }

        return $dist;
    }

    /** @return list<array{room:string,medianTurnoverTime:int,averageTurnoverTime:float}> */
    private function rooms(string $site): array
    {
        $rows = DB::select(
            'SELECT r.name AS room,
                    ROUND(PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY cm.turnover_time)::numeric) AS median_to,
                    ROUND(AVG(cm.turnover_time)::numeric, 1) AS avg_to
             FROM prod.case_metrics cm
             JOIN prod.or_cases oc ON oc.case_id = cm.case_id
             JOIN prod.rooms r ON r.room_id = oc.room_id
             JOIN prod.locations l ON l.location_id = oc.location_id
             WHERE cm.is_deleted = false
               AND oc.is_deleted = false
               AND r.is_deleted = false
               AND cm.turnover_time IS NOT NULL
               AND l.name = ?
             GROUP BY r.name
             ORDER BY r.name',
            [$site]
        );

        $rooms = [];
        foreach ($rows as $r) {
            $rooms[] = [
                'room' => (string) $r->room,
                'medianTurnoverTime' => (int) round((float) ($r->median_to ?? 0)),
                'averageTurnoverTime' => round((float) ($r->avg_to ?? 0), 1),
            ];
        }

        return $rooms;
    }

    /** @return array{medianTurnoverTime:list<array{month:string,value:int}>,averageTurnoverTime:list<array{month:string,value:float}>} */
    private function trends(string $site): array
    {
        $rows = DB::select(
            "SELECT TO_CHAR(DATE_TRUNC('month', oc.surgery_date), 'Mon') AS month,
                    DATE_TRUNC('month', oc.surgery_date) AS m,
                    ROUND(PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY cm.turnover_time)::numeric) AS median_to,
                    ROUND(AVG(cm.turnover_time)::numeric, 1) AS avg_to
             FROM prod.case_metrics cm
             JOIN prod.or_cases oc ON oc.case_id = cm.case_id
             JOIN prod.locations l ON l.location_id = oc.location_id
             WHERE cm.is_deleted = false
               AND oc.is_deleted = false
               AND cm.turnover_time IS NOT NULL
               AND l.name = ?
             GROUP BY 1, 2
             ORDER BY 2",
            [$site]
        );

        $median = [];
        $average = [];
        foreach ($rows as $r) {
            $month = trim((string) $r->month);
            $median[] = ['month' => $month, 'value' => (int) round((float) ($r->median_to ?? 0))];
            $average[] = ['month' => $month, 'value' => round((float) ($r->avg_to ?? 0), 1)];
        }

        return [
            'medianTurnoverTime' => $median,
            'averageTurnoverTime' => $average,
        ];
    }

    /**
     * Per-site day-of-week series (Mon..Sun) as an array of objects. TrendsView
     * reads locationData.dayOfWeek as an array of {day, medianTurnoverTime,
     * averageTurnoverTime}.
     *
     * @return list<array{day:string,medianTurnoverTime:int,averageTurnoverTime:float}>
     */
    private function dayOfWeekSeries(string $site): array
    {
        $rows = DB::select(
            "SELECT TRIM(TO_CHAR(oc.surgery_date, 'Day')) AS day,
                    EXTRACT(DOW FROM oc.surgery_date)::int AS dnum,
                    ROUND(PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY cm.turnover_time)::numeric) AS median_to,
                    ROUND(AVG(cm.turnover_time)::numeric, 1) AS avg_to
             FROM prod.case_metrics cm
             JOIN prod.or_cases oc ON oc.case_id = cm.case_id
             JOIN prod.locations l ON l.location_id = oc.location_id
             WHERE cm.is_deleted = false
               AND oc.is_deleted = false
               AND cm.turnover_time IS NOT NULL
               AND l.name = ?
             GROUP BY 1, 2
             ORDER BY (EXTRACT(DOW FROM oc.surgery_date)::int + 6) % 7",
            [$site]
        );

        $series = [];
        foreach ($rows as $r) {
            $series[] = [
                'day' => (string) $r->day,
                'medianTurnoverTime' => (int) round((float) ($r->median_to ?? 0)),
                'averageTurnoverTime' => round((float) ($r->avg_to ?? 0), 1),
            ];
        }

        return $series;
    }

    /**
     * Per-site time-of-day series bucketed into 2-hour windows by
     * procedure_start_time, returned as an array of objects.
     *
     * @return list<array{range:string,medianTurnoverTime:int,averageTurnoverTime:float}>
     */
    private function timeOfDaySeries(string $site): array
    {
        $rows = DB::select(
            'SELECT (FLOOR(EXTRACT(HOUR FROM lg.procedure_start_time) / 2) * 2)::int AS bucket_start,
                    ROUND(PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY cm.turnover_time)::numeric) AS median_to,
                    ROUND(AVG(cm.turnover_time)::numeric, 1) AS avg_to
             FROM prod.case_metrics cm
             JOIN prod.or_cases oc ON oc.case_id = cm.case_id
             JOIN prod.or_logs lg ON lg.case_id = oc.case_id AND lg.is_deleted = false
             JOIN prod.locations l ON l.location_id = oc.location_id
             WHERE cm.is_deleted = false
               AND oc.is_deleted = false
               AND cm.turnover_time IS NOT NULL
               AND lg.procedure_start_time IS NOT NULL
               AND l.name = ?
             GROUP BY 1
             ORDER BY 1',
            [$site]
        );

        $series = [];
        foreach ($rows as $r) {
            $start = (int) $r->bucket_start;
            $end = $start + 2;
            $series[] = [
                'range' => sprintf('%d:00-%d:00', $start, $end),
                'medianTurnoverTime' => (int) round((float) ($r->median_to ?? 0)),
                'averageTurnoverTime' => round((float) ($r->avg_to ?? 0), 1),
            ];
        }

        return $series;
    }

    // -----------------------------------------------------------------------
    // services — keyed by service name (collapsed across duplicate ids)
    // -----------------------------------------------------------------------

    /** @return array<string,array{medianTurnoverTime:int,averageTurnoverTime:float,totalCases:int,totalTurnovers:int}> */
    private function services(): array
    {
        $rows = DB::select(
            'SELECT s.name AS service,
                    ROUND(PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY cm.turnover_time)::numeric) AS median_to,
                    ROUND(AVG(cm.turnover_time)::numeric, 1) AS avg_to,
                    COUNT(*) AS total_cases,
                    COUNT(cm.turnover_time) AS total_turnovers
             FROM prod.or_cases oc
             JOIN prod.services s ON s.service_id = oc.case_service_id
             LEFT JOIN prod.case_metrics cm ON cm.case_id = oc.case_id AND cm.is_deleted = false
             WHERE oc.is_deleted = false
               AND s.is_deleted = false
             GROUP BY s.name
             ORDER BY s.name'
        );

        $services = [];
        foreach ($rows as $r) {
            $services[(string) $r->service] = [
                'medianTurnoverTime' => (int) round((float) ($r->median_to ?? 0)),
                'averageTurnoverTime' => round((float) ($r->avg_to ?? 0), 1),
                'totalCases' => (int) $r->total_cases,
                'totalTurnovers' => (int) $r->total_turnovers,
            ];
        }

        return $services;
    }

    // -----------------------------------------------------------------------
    // top-level dayOfWeek / timeOfDay maps (keyed by site → {label: {median,
    // average}}) — mirrors the mock's top-level shape for completeness.
    // -----------------------------------------------------------------------

    /** @return array<string,array<string,array{median:int,average:float}>> */
    private function dayOfWeekBySite(): array
    {
        $out = [];
        foreach ($this->siteNames() as $site) {
            $map = [];
            foreach ($this->dayOfWeekSeries($site) as $row) {
                $map[$row['day']] = [
                    'median' => $row['medianTurnoverTime'],
                    'average' => $row['averageTurnoverTime'],
                ];
            }
            $out[$site] = $map;
        }

        return $out;
    }

    /** @return array<string,array<string,array{median:int,average:float}>> */
    private function timeOfDayBySite(): array
    {
        $out = [];
        foreach ($this->siteNames() as $site) {
            $map = [];
            foreach ($this->timeOfDaySeries($site) as $row) {
                $map[$row['range']] = [
                    'median' => $row['medianTurnoverTime'],
                    'average' => $row['averageTurnoverTime'],
                ];
            }
            $out[$site] = $map;
        }

        return $out;
    }

    /** @return list<string> */
    private function siteNames(): array
    {
        $rows = DB::select(
            'SELECT DISTINCT l.name AS site
             FROM prod.or_cases oc
             JOIN prod.locations l ON l.location_id = oc.location_id
             WHERE oc.is_deleted = false
               AND l.is_deleted = false
             ORDER BY l.name'
        );

        return array_map(static fn ($r): string => (string) $r->site, $rows);
    }
}
