<?php

declare(strict_types=1);

namespace App\Services\Ed;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the ED Resource Analytics page payload from the live `prod` schema.
 *
 * Consumed by resources/js/Pages/ED/Analytics/Resources.jsx. Surfaces ED
 * utilization / occupancy over time, throughput (arrivals vs discharges by
 * hour), and bed-hours consumption — all derived from prod.ed_visits over a
 * trailing window anchored to wall-clock now.
 *
 * Where ed_visits has no source column (treatment-room count, staffed-bed
 * capacity), values are deterministically derived from the live census so the
 * demo is stable across reloads — never randomised. Query idioms mirror
 * App\Services\Dashboard\EdDashboardService.
 *
 * Deterministic and safe on empty tables (returns zeros / empty arrays, never
 * throws). All time windows are clamped to `now` so seeded "future"
 * departures are not double-counted.
 *
 * @phpstan-type Kpis array{
 *     currentCensus:int, capacity:int, avgOccupancy:int, peakOccupancy:int,
 *     peakHour:string, totalArrivals:int, totalDischarges:int, bedHours:int,
 *     turnoverRate:float, avgLos:int, windowHours:int
 * }
 */
class ResourceAnalyticsService
{
    /** ED unit identifier (prod.units: "Emergency Department", type 'ed'). */
    private const ED_UNIT_ID = 1;

    /** Trailing hourly buckets rendered by the utilization / throughput charts. */
    private const WINDOW_HOURS = 12;

    /** Fallback staffed-bed count when no census snapshot exists (typical mid-size ED). */
    private const FALLBACK_CAPACITY = 20;

    /**
     * Assemble the full ED Resource Analytics payload.
     *
     * @return array{
     *     kpis:Kpis,
     *     utilizationSeries:list<array{hour:string,census:int,occupancy:int,capacity:int}>,
     *     throughputSeries:list<array{hour:string,arrivals:int,discharges:int,net:int}>,
     *     bedHoursByEsi:list<array{esi:int,label:string,visits:int,bedHours:int,sharePct:int}>,
     *     generatedAt:string
     * }
     */
    public function build(): array
    {
        $now = Carbon::now();
        $capacity = $this->capacity($now);

        $utilization = $this->utilizationSeries($now, $capacity);
        $throughput = $this->throughputSeries($now);
        $bedHoursByEsi = $this->bedHoursByEsi($now);
        $kpis = $this->kpis($now, $capacity, $utilization, $throughput, $bedHoursByEsi);

        return [
            'kpis' => $kpis,
            'utilizationSeries' => $utilization,
            'throughputSeries' => $throughput,
            'bedHoursByEsi' => $bedHoursByEsi,
            'generatedAt' => $now->toIso8601String(),
        ];
    }

    // -----------------------------------------------------------------------
    // Capacity (staffed beds) — from census snapshot, deterministic fallback
    // -----------------------------------------------------------------------

    /**
     * Staffed-bed capacity for the ED. Prefers the latest census snapshot;
     * falls back to a fixed deterministic figure so occupancy % is meaningful
     * even before any snapshot is seeded.
     */
    private function capacity(Carbon $now): int
    {
        $row = DB::table('prod.census_snapshots')
            ->where('unit_id', self::ED_UNIT_ID)
            ->where('captured_at', '<=', $now)
            ->orderByDesc('captured_at')
            ->first(['staffed_beds']);

        $staffed = (int) ($row->staffed_beds ?? 0);

        return $staffed > 0 ? $staffed : self::FALLBACK_CAPACITY;
    }

    // -----------------------------------------------------------------------
    // Utilization / occupancy over time (hourly census vs capacity)
    // -----------------------------------------------------------------------

    /**
     * Hourly ED census (patients physically present at each hour boundary) and
     * occupancy % against staffed-bed capacity, for the trailing window.
     *
     * A visit is "present" in hour H when it arrived on/before the end of H and
     * had not departed before the start of H (departure clamped to `now`).
     *
     * @return list<array{hour:string,census:int,occupancy:int,capacity:int}>
     */
    private function utilizationSeries(Carbon $now, int $capacity): array
    {
        $rows = DB::select(
            'WITH bounds AS (
                 SELECT date_trunc(\'hour\', ?::timestamp) AS top_hour
             ),
             hours AS (
                 SELECT generate_series(
                     (SELECT top_hour FROM bounds) - (?::int - 1) * interval \'1 hour\',
                     (SELECT top_hour FROM bounds),
                     interval \'1 hour\'
                 ) AS h
             )
             SELECT
                 to_char(h, \'HH24:00\') AS hour,
                 COUNT(v.ed_visit_id) FILTER (
                     WHERE v.arrived_at <= LEAST(h + interval \'1 hour\', ?::timestamp)
                       AND (v.departed_at IS NULL OR v.departed_at >= h)
                 ) AS census
             FROM hours
             LEFT JOIN prod.ed_visits v
                 ON v.is_deleted = false
             GROUP BY h
             ORDER BY h',
            [
                $now->toDateTimeString(),
                self::WINDOW_HOURS,
                $now->toDateTimeString(),
            ]
        );

        $cap = max(1, $capacity);
        $out = [];
        foreach ($rows as $row) {
            $census = (int) $row->census;
            $out[] = [
                'hour' => (string) $row->hour,
                'census' => $census,
                'occupancy' => (int) round($census / $cap * 100),
                'capacity' => $capacity,
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Throughput (hourly arrivals vs discharges)
    // -----------------------------------------------------------------------

    /**
     * Hourly arrivals vs completed discharges over the trailing window. "net"
     * is arrivals minus discharges (positive = department filling).
     *
     * @return list<array{hour:string,arrivals:int,discharges:int,net:int}>
     */
    private function throughputSeries(Carbon $now): array
    {
        $rows = DB::select(
            'WITH bounds AS (
                 SELECT date_trunc(\'hour\', ?::timestamp) AS top_hour
             ),
             hours AS (
                 SELECT generate_series(
                     (SELECT top_hour FROM bounds) - (?::int - 1) * interval \'1 hour\',
                     (SELECT top_hour FROM bounds),
                     interval \'1 hour\'
                 ) AS h
             )
             SELECT
                 to_char(h, \'HH24:00\') AS hour,
                 COUNT(v.ed_visit_id) FILTER (
                     WHERE v.arrived_at >= h
                       AND v.arrived_at < h + interval \'1 hour\'
                       AND v.arrived_at <= ?::timestamp
                 ) AS arrivals,
                 COUNT(v.ed_visit_id) FILTER (
                     WHERE v.departed_at >= h
                       AND v.departed_at < h + interval \'1 hour\'
                       AND v.departed_at <= ?::timestamp
                       AND v.disposition IS NOT NULL
                 ) AS discharges
             FROM hours
             LEFT JOIN prod.ed_visits v
                 ON v.is_deleted = false
             GROUP BY h
             ORDER BY h',
            [
                $now->toDateTimeString(),
                self::WINDOW_HOURS,
                $now->toDateTimeString(),
                $now->toDateTimeString(),
            ]
        );

        $out = [];
        foreach ($rows as $row) {
            $arrivals = (int) $row->arrivals;
            $discharges = (int) $row->discharges;
            $out[] = [
                'hour' => (string) $row->hour,
                'arrivals' => $arrivals,
                'discharges' => $discharges,
                'net' => $arrivals - $discharges,
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Bed-hours consumed by acuity (ESI) over the trailing window
    // -----------------------------------------------------------------------

    /**
     * Total occupied bed-hours by ESI level over the trailing window. Bed-hours
     * = sum of each visit's in-department time (arrival -> departure, open
     * visits clamped to `now`) overlapping the window, expressed in hours.
     *
     * @return list<array{esi:int,label:string,visits:int,bedHours:int,sharePct:int}>
     */
    private function bedHoursByEsi(Carbon $now): array
    {
        $windowStart = $now->copy()->startOfHour()->subHours(self::WINDOW_HOURS - 1);

        $rows = DB::select(
            'SELECT
                 COALESCE(esi_level, 3) AS esi,
                 COUNT(*) AS visits,
                 CAST(ROUND(SUM(
                     GREATEST(0, EXTRACT(EPOCH FROM (
                         LEAST(COALESCE(departed_at, ?::timestamp), ?::timestamp)
                         - GREATEST(arrived_at, ?::timestamp)
                     )) / 3600.0)
                 )) AS integer) AS bed_hours
             FROM prod.ed_visits
             WHERE is_deleted = false
               AND arrived_at <= ?::timestamp
               AND (departed_at IS NULL OR departed_at >= ?::timestamp)
             GROUP BY COALESCE(esi_level, 3)
             ORDER BY esi',
            [
                $now->toDateTimeString(),
                $now->toDateTimeString(),
                $windowStart->toDateTimeString(),
                $now->toDateTimeString(),
                $windowStart->toDateTimeString(),
            ]
        );

        $labels = [
            1 => 'ESI 1 — Resuscitation',
            2 => 'ESI 2 — Emergent',
            3 => 'ESI 3 — Urgent',
            4 => 'ESI 4 — Less Urgent',
            5 => 'ESI 5 — Non-Urgent',
        ];

        $byEsi = [];
        $totalBedHours = 0;
        foreach ($rows as $row) {
            $esi = max(1, min(5, (int) $row->esi));
            $bedHours = (int) $row->bed_hours;
            $byEsi[$esi] = [
                'esi' => $esi,
                'label' => $labels[$esi],
                'visits' => (int) $row->visits,
                'bedHours' => $bedHours,
            ];
            $totalBedHours += $bedHours;
        }

        $out = [];
        for ($esi = 1; $esi <= 5; $esi++) {
            $entry = $byEsi[$esi] ?? [
                'esi' => $esi,
                'label' => $labels[$esi],
                'visits' => 0,
                'bedHours' => 0,
            ];
            $entry['sharePct'] = $totalBedHours > 0
                ? (int) round($entry['bedHours'] / $totalBedHours * 100)
                : 0;
            $out[] = $entry;
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // KPI tiles (headline figures over the window)
    // -----------------------------------------------------------------------

    /**
     * Headline KPIs: current census, avg / peak occupancy, window throughput
     * totals, total bed-hours, turnover rate, and median LOS.
     *
     * @param  list<array{hour:string,census:int,occupancy:int,capacity:int}>  $utilization
     * @param  list<array{hour:string,arrivals:int,discharges:int,net:int}>  $throughput
     * @param  list<array{esi:int,label:string,visits:int,bedHours:int,sharePct:int}>  $bedHoursByEsi
     * @return Kpis
     */
    private function kpis(
        Carbon $now,
        int $capacity,
        array $utilization,
        array $throughput,
        array $bedHoursByEsi
    ): array {
        $currentCensus = $utilization !== [] ? (int) end($utilization)['census'] : 0;

        $occupancies = array_column($utilization, 'occupancy');
        $avgOccupancy = $occupancies !== [] ? (int) round(array_sum($occupancies) / count($occupancies)) : 0;

        $peakOccupancy = 0;
        $peakHour = '—';
        foreach ($utilization as $bucket) {
            if ($bucket['occupancy'] >= $peakOccupancy) {
                $peakOccupancy = $bucket['occupancy'];
                $peakHour = $bucket['hour'];
            }
        }

        $totalArrivals = (int) array_sum(array_column($throughput, 'arrivals'));
        $totalDischarges = (int) array_sum(array_column($throughput, 'discharges'));
        $bedHours = (int) array_sum(array_column($bedHoursByEsi, 'bedHours'));

        // Turnover: completed departures per staffed bed over the window.
        $turnoverRate = $capacity > 0 ? round($totalDischarges / $capacity, 1) : 0.0;

        $avgLos = $this->medianLosMinutes($now);

        return [
            'currentCensus' => $currentCensus,
            'capacity' => $capacity,
            'avgOccupancy' => $avgOccupancy,
            'peakOccupancy' => $peakOccupancy,
            'peakHour' => $peakHour,
            'totalArrivals' => $totalArrivals,
            'totalDischarges' => $totalDischarges,
            'bedHours' => $bedHours,
            'turnoverRate' => $turnoverRate,
            'avgLos' => $avgLos,
            'windowHours' => self::WINDOW_HOURS,
        ];
    }

    /**
     * Median length-of-stay (minutes) for completed visits whose departure
     * falls within the trailing window.
     */
    private function medianLosMinutes(Carbon $now): int
    {
        $windowStart = $now->copy()->startOfHour()->subHours(self::WINDOW_HOURS - 1);

        $row = DB::selectOne(
            'SELECT CAST(percentile_cont(0.5) WITHIN GROUP (
                 ORDER BY EXTRACT(EPOCH FROM departed_at - arrived_at) / 60
             ) AS integer) AS los
             FROM prod.ed_visits
             WHERE is_deleted = false
               AND departed_at IS NOT NULL
               AND departed_at >= ?::timestamp
               AND departed_at <= ?::timestamp',
            [$windowStart->toDateTimeString(), $now->toDateTimeString()]
        );

        return (int) ($row->los ?? 0);
    }
}
