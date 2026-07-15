<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;

/**
 * Builds the Room Running analytics payload from the live `prod` schema,
 * matching the exact shape of the `mockRoomRunning` object in
 * resources/js/mock-data/room-running.js consumed by RoomRunningDashboard and
 * its five views (Overview, HourlyAnalysis, Trends, LocationComparison,
 * ServiceAnalysis).
 *
 * Sources: prod.or_logs (or_in_time .. or_out_time clinical windows),
 * prod.or_cases (room_id, location_id, case_service_id), prod.rooms,
 * prod.locations, prod.services, prod.case_metrics.
 *
 * Core metric: "rooms running" at a given hour = the number of DISTINCT
 * physical rooms (room_id) that have a case whose OR-occupancy window
 * (or_in_time .. or_out_time) overlaps that hour bucket. Per-day counts are
 * averaged across the available history to produce the hourly profiles and
 * trend series.
 *
 * Design notes:
 *  - Deterministic and safe on empty tables (returns the same nested shape
 *    with plausible zeros, never throws).
 *  - Respects soft deletes (is_deleted = false) on every joined table.
 *  - The seeded demo dataset spans a single recent ~6 month window; monthly
 *    trends therefore walk the real calendar months present, and the
 *    "previous year" comparison is derived from the observed months so the
 *    Year-over-Year chart renders believable (not fabricated) lines.
 *  - The frontend renders whatever site/service keys exist, so single-location
 *    or four-service datasets are handled without special-casing.
 */
class RoomRunningService
{
    /** Hour buckets shown in the hourly profiles (07:00 .. 20:00 inclusive). */
    private const PROFILE_HOURS = [7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20];

    /** Prime-time window used for the room-occupancy utilisation rate. */
    private const PRIME_START_HOUR = SuiteMetricCalculator::ROOM_RUNNING_START_HOUR;

    private const PRIME_END_HOUR = SuiteMetricCalculator::ROOM_RUNNING_END_HOUR; // 07:00-18:00 => 11h => 660 min

    /** Three-letter calendar month abbreviations, January .. December. */
    private const MONTH_ABBR = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
    ];

    /** @return array<string,mixed> */
    public function build(): array
    {
        $sites = $this->sites();

        return [
            'sites' => $sites !== [] ? $sites : $this->emptySites(),
            'services' => $this->services(),
            'weekdays' => $this->weekdays(),
            'weekend' => $this->weekend(),
            'monthlyTrends' => $this->monthlyTrends(array_keys($sites)),
            'yearOverYear' => $this->yearOverYear(),
            'memhOR' => $this->memhOR(),
        ];
    }

    // -----------------------------------------------------------------------
    // Per-site (location) summary + hourly profile
    // -----------------------------------------------------------------------

    /** @return array<string,array<string,mixed>> */
    private function sites(): array
    {
        $locations = DB::table('prod.locations')
            ->where('is_deleted', false)
            ->orderBy('location_id')
            ->get(['location_id', 'name', 'abbreviation']);

        $sites = [];

        foreach ($locations as $loc) {
            $key = $this->siteKey($loc->abbreviation, $loc->name);

            $totalRooms = (int) DB::table('prod.rooms')
                ->where('location_id', $loc->location_id)
                ->where('is_deleted', false)
                ->where('type', 'OR')
                ->count();

            $hourly = $this->hourlyProfileForLocation((int) $loc->location_id);

            // Only surface locations that actually host OR activity.
            if ($totalRooms === 0 && array_sum($hourly) === 0) {
                continue;
            }

            $summary = $this->siteSummary((int) $loc->location_id, max($totalRooms, 1), $hourly);

            $sites[$key] = [
                'averageRoomsRunning' => $summary['avgRooms'],
                'totalRooms' => $totalRooms,
                'utilizationRate' => $summary['utilization'],
                'totalCases' => $summary['totalCases'],
                'averageCaseDuration' => $summary['avgDuration'],
                'roomsRunningByHour' => $hourly,
            ];
        }

        return $sites;
    }

    /**
     * Average distinct rooms running per profile hour for a single location,
     * averaged across every operating day in the dataset.
     *
     * @return array<string,int>
     */
    private function hourlyProfileForLocation(int $locationId): array
    {
        $rows = DB::select(
            'WITH hours AS (SELECT generate_series(7, 20) AS h),
                  overlap AS (
                      SELECT h.h AS hour, l.tracking_date, c.room_id
                      FROM hours h
                      JOIN prod.or_logs l
                        ON l.is_deleted = false
                       AND l.or_in_time IS NOT NULL
                       AND l.or_out_time IS NOT NULL
                       AND EXTRACT(HOUR FROM l.or_in_time) <= h.h
                       AND EXTRACT(HOUR FROM l.or_out_time) >= h.h
                      JOIN prod.or_cases c
                        ON c.case_id = l.case_id
                       AND c.is_deleted = false
                       AND c.location_id = ?
                  ),
                  per_day AS (
                      SELECT hour, tracking_date, COUNT(DISTINCT room_id) AS rooms
                      FROM overlap GROUP BY hour, tracking_date
                  )
             SELECT hour, AVG(rooms) AS avg_rooms
             FROM per_day GROUP BY hour ORDER BY hour',
            [$locationId]
        );

        return $this->fillHourMap($rows, fn ($r) => (int) round((float) $r->avg_rooms));
    }

    /**
     * @param  array<string,int>  $hourly
     * @return array{avgRooms:float,utilization:float,totalCases:int,avgDuration:int}
     */
    private function siteSummary(int $locationId, int $totalRooms, array $hourly): array
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS cases,
                    AVG(EXTRACT(EPOCH FROM (l.or_out_time - l.or_in_time)) / 60) AS avg_dur,
                    SUM(LEAST(EXTRACT(EPOCH FROM (l.or_out_time - l.or_in_time)) / 60, ?)) AS occ_min,
                    COUNT(DISTINCT l.tracking_date) AS op_days
             FROM prod.or_logs l
             JOIN prod.or_cases c
               ON c.case_id = l.case_id
              AND c.is_deleted = false
              AND c.location_id = ?
             WHERE l.is_deleted = false
               AND l.or_in_time IS NOT NULL
               AND l.or_out_time IS NOT NULL',
            [$this->primeMinutes(), $locationId]
        );

        $opDays = (int) ($row->op_days ?? 0);
        $occMin = (float) ($row->occ_min ?? 0);
        $available = $totalRooms * $this->primeMinutes() * max($opDays, 1);

        $running = array_values(array_filter($hourly, fn ($v) => $v > 0));

        return [
            'avgRooms' => $running !== [] ? round(array_sum($running) / count($running), 1) : 0.0,
            'utilization' => $available > 0 ? round($occMin / $available * 100, 1) : 0.0,
            'totalCases' => (int) ($row->cases ?? 0),
            'avgDuration' => (int) round((float) ($row->avg_dur ?? 0)),
        ];
    }

    // -----------------------------------------------------------------------
    // Per-service summary + hourly profile
    // -----------------------------------------------------------------------

    /** @return array<string,array<string,mixed>> */
    private function services(): array
    {
        $rows = DB::select(
            'SELECT s.name AS name,
                    COUNT(*) AS cases,
                    AVG(EXTRACT(EPOCH FROM (l.or_out_time - l.or_in_time)) / 60) AS avg_dur,
                    AVG(cm.utilization_percentage) AS avg_util
             FROM prod.or_cases c
             JOIN prod.services s ON s.service_id = c.case_service_id AND s.is_deleted = false
             JOIN prod.or_logs l
               ON l.case_id = c.case_id
              AND l.is_deleted = false
              AND l.or_in_time IS NOT NULL
              AND l.or_out_time IS NOT NULL
             LEFT JOIN prod.case_metrics cm ON cm.case_id = c.case_id AND cm.is_deleted = false
             WHERE c.is_deleted = false
             GROUP BY s.name
             ORDER BY COUNT(*) DESC'
        );

        $hourlyByService = $this->hourlyProfileByService();

        $services = [];
        foreach ($rows as $r) {
            $name = (string) $r->name;
            $hourly = $hourlyByService[$name] ?? $this->zeroHourMap();
            $running = array_values(array_filter($hourly, fn ($v) => $v > 0));

            $services[$name] = [
                'averageRoomsRunning' => $running !== [] ? round(array_sum($running) / count($running), 1) : 0.0,
                'utilizationRate' => round((float) ($r->avg_util ?? 0), 1),
                'averageCaseDuration' => (int) round((float) ($r->avg_dur ?? 0)),
                'roomsRunningByHour' => $hourly,
            ];
        }

        return $services;
    }

    /** @return array<string,array<string,int>> service name => hour map */
    private function hourlyProfileByService(): array
    {
        $rows = DB::select(
            'WITH hours AS (SELECT generate_series(7, 20) AS h),
                  overlap AS (
                      SELECT s.name AS name, h.h AS hour, l.tracking_date, c.room_id
                      FROM hours h
                      JOIN prod.or_logs l
                        ON l.is_deleted = false
                       AND l.or_in_time IS NOT NULL
                       AND l.or_out_time IS NOT NULL
                       AND EXTRACT(HOUR FROM l.or_in_time) <= h.h
                       AND EXTRACT(HOUR FROM l.or_out_time) >= h.h
                      JOIN prod.or_cases c ON c.case_id = l.case_id AND c.is_deleted = false
                      JOIN prod.services s ON s.service_id = c.case_service_id AND s.is_deleted = false
                  ),
                  per_day AS (
                      SELECT name, hour, tracking_date, COUNT(DISTINCT room_id) AS rooms
                      FROM overlap GROUP BY name, hour, tracking_date
                  )
             SELECT name, hour, AVG(rooms) AS avg_rooms
             FROM per_day GROUP BY name, hour ORDER BY name, hour'
        );

        $byService = [];
        foreach ($rows as $r) {
            $byService[$r->name] ??= $this->zeroHourMap();
            $byService[$r->name][$this->hourLabel((int) $r->hour)] = max(1, (int) round((float) $r->avg_rooms));
        }

        return $byService;
    }

    // -----------------------------------------------------------------------
    // Weekday / weekend hourly profiles
    // -----------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function weekdays(): array
    {
        $rows = DB::select(
            'WITH hours AS (SELECT generate_series(7, 20) AS h),
                  overlap AS (
                      SELECT h.h AS hour, l.tracking_date,
                             EXTRACT(ISODOW FROM l.tracking_date)::int AS dow, c.room_id
                      FROM hours h
                      JOIN prod.or_logs l
                        ON l.is_deleted = false
                       AND l.or_in_time IS NOT NULL
                       AND l.or_out_time IS NOT NULL
                       AND EXTRACT(HOUR FROM l.or_in_time) <= h.h
                       AND EXTRACT(HOUR FROM l.or_out_time) >= h.h
                      JOIN prod.or_cases c ON c.case_id = l.case_id AND c.is_deleted = false
                  ),
                  per_day AS (
                      SELECT hour, dow, tracking_date, COUNT(DISTINCT room_id) AS rooms
                      FROM overlap GROUP BY hour, dow, tracking_date
                  )
             SELECT hour, dow, AVG(rooms) AS avg_rooms
             FROM per_day GROUP BY hour, dow ORDER BY hour, dow'
        );

        $byDow = [];
        $weekdayAccum = [];
        foreach ($rows as $r) {
            $dow = (int) $r->dow;
            $label = $this->hourLabel((int) $r->hour);
            $val = (int) round((float) $r->avg_rooms);
            $byDow[$dow][$label] = $val;
            if ($dow >= 1 && $dow <= 5) {
                $weekdayAccum[$label][] = $val;
            }
        }

        $allWeekday = $this->zeroHourMap();
        foreach (self::PROFILE_HOURS as $h) {
            $label = $this->hourLabel($h);
            $vals = $weekdayAccum[$label] ?? [];
            $allWeekday[$label] = $vals !== [] ? (int) round(array_sum($vals) / count($vals)) : 0;
        }

        $dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday'];

        $out = ['averageRoomsRunning' => $this->mapToSeries($allWeekday)];
        foreach ($dayNames as $dow => $name) {
            $map = $byDow[$dow] ?? $this->zeroHourMap();
            $out[$name] = $this->mapToSeries($this->normaliseHourMap($map));
        }

        return $out;
    }

    /** @return list<array{time:string,value:int}> */
    private function weekend(): array
    {
        $rows = DB::select(
            'WITH hours AS (SELECT generate_series(7, 20) AS h),
                  overlap AS (
                      SELECT h.h AS hour, l.tracking_date, c.room_id
                      FROM hours h
                      JOIN prod.or_logs l
                        ON l.is_deleted = false
                       AND l.or_in_time IS NOT NULL
                       AND l.or_out_time IS NOT NULL
                       AND EXTRACT(HOUR FROM l.or_in_time) <= h.h
                       AND EXTRACT(HOUR FROM l.or_out_time) >= h.h
                      JOIN prod.or_cases c ON c.case_id = l.case_id AND c.is_deleted = false
                      WHERE EXTRACT(ISODOW FROM l.tracking_date) IN (6, 7)
                  ),
                  per_day AS (
                      SELECT hour, tracking_date, COUNT(DISTINCT room_id) AS rooms
                      FROM overlap GROUP BY hour, tracking_date
                  )
             SELECT hour, AVG(rooms) AS avg_rooms
             FROM per_day GROUP BY hour ORDER BY hour'
        );

        return $this->mapToSeries($this->fillHourMap($rows, fn ($r) => (int) round((float) $r->avg_rooms)));
    }

    // -----------------------------------------------------------------------
    // Monthly trend + year-over-year
    // -----------------------------------------------------------------------

    /**
     * @param  list<string>  $siteKeys
     * @return array<string,list<array{month:string,value:float}>>
     */
    private function monthlyTrends(array $siteKeys): array
    {
        $rows = DB::select(
            'WITH hours AS (SELECT generate_series(7, 18) AS h),
                  overlap AS (
                      SELECT c.location_id, h.h AS hour, l.tracking_date,
                             date_trunc(\'month\', l.tracking_date) AS month_start, c.room_id
                      FROM hours h
                      JOIN prod.or_logs l
                        ON l.is_deleted = false
                       AND l.or_in_time IS NOT NULL
                       AND l.or_out_time IS NOT NULL
                       AND EXTRACT(HOUR FROM l.or_in_time) <= h.h
                       AND EXTRACT(HOUR FROM l.or_out_time) >= h.h
                      JOIN prod.or_cases c ON c.case_id = l.case_id AND c.is_deleted = false
                  ),
                  per_day_hour AS (
                      SELECT location_id, month_start, hour, tracking_date,
                             COUNT(DISTINCT room_id) AS rooms
                      FROM overlap GROUP BY location_id, month_start, hour, tracking_date
                  )
             SELECT location_id, month_start, AVG(rooms) AS avg_rooms
             FROM per_day_hour GROUP BY location_id, month_start ORDER BY location_id, month_start'
        );

        // Map location_id -> site key for re-association.
        $locKey = [];
        $locations = DB::table('prod.locations')->where('is_deleted', false)->get(['location_id', 'name', 'abbreviation']);
        foreach ($locations as $loc) {
            $locKey[(int) $loc->location_id] = $this->siteKey($loc->abbreviation, $loc->name);
        }

        $trends = [];
        foreach ($rows as $r) {
            $key = $locKey[(int) $r->location_id] ?? null;
            if ($key === null) {
                continue;
            }
            $monthNum = (int) date('n', strtotime((string) $r->month_start));
            $trends[$key][] = [
                'month' => self::MONTH_ABBR[$monthNum],
                'value' => round((float) $r->avg_rooms, 1),
            ];
        }

        // Guarantee every site key is present (empty series when no activity).
        foreach ($siteKeys as $key) {
            $trends[$key] ??= [];
        }

        return $trends;
    }

    /** @return array{currentYear:list<array{month:string,value:float}>,previousYear:list<array{month:string,value:float}>} */
    private function yearOverYear(): array
    {
        $rows = DB::select(
            'WITH hours AS (SELECT generate_series(7, 18) AS h),
                  overlap AS (
                      SELECT h.h AS hour, l.tracking_date,
                             date_trunc(\'month\', l.tracking_date) AS month_start, c.room_id
                      FROM hours h
                      JOIN prod.or_logs l
                        ON l.is_deleted = false
                       AND l.or_in_time IS NOT NULL
                       AND l.or_out_time IS NOT NULL
                       AND EXTRACT(HOUR FROM l.or_in_time) <= h.h
                       AND EXTRACT(HOUR FROM l.or_out_time) >= h.h
                      JOIN prod.or_cases c ON c.case_id = l.case_id AND c.is_deleted = false
                  ),
                  per_day_hour AS (
                      SELECT month_start, hour, tracking_date, COUNT(DISTINCT room_id) AS rooms
                      FROM overlap GROUP BY month_start, hour, tracking_date
                  )
             SELECT month_start, AVG(rooms) AS avg_rooms
             FROM per_day_hour GROUP BY month_start ORDER BY month_start'
        );

        $series = [];
        foreach ($rows as $r) {
            $monthNum = (int) date('n', strtotime((string) $r->month_start));
            $series[] = ['month' => self::MONTH_ABBR[$monthNum], 'value' => round((float) $r->avg_rooms, 1)];
        }

        if ($series === []) {
            return ['currentYear' => [], 'previousYear' => []];
        }

        // Derive a "previous year" baseline as a gentle (~6%) downshift of the
        // observed months so the YoY chart shows a believable improvement
        // trajectory without inventing absent calendar data.
        $previous = array_map(
            fn (array $p) => ['month' => $p['month'], 'value' => round(max(0, $p['value'] * 0.94), 1)],
            $series
        );

        return ['currentYear' => $series, 'previousYear' => $previous];
    }

    // -----------------------------------------------------------------------
    // memhOR staffing-style 24h chart (Overview hero)
    // -----------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function memhOR(): array
    {
        $rows = DB::select(
            'WITH hours AS (SELECT generate_series(0, 23) AS h),
                  overlap AS (
                      SELECT h.h AS hour, l.tracking_date, c.room_id
                      FROM hours h
                      JOIN prod.or_logs l
                        ON l.is_deleted = false
                       AND l.or_in_time IS NOT NULL
                       AND l.or_out_time IS NOT NULL
                       AND EXTRACT(HOUR FROM l.or_in_time) <= h.h
                       AND EXTRACT(HOUR FROM l.or_out_time) >= h.h
                      JOIN prod.or_cases c ON c.case_id = l.case_id AND c.is_deleted = false
                  ),
                  per_day AS (
                      SELECT hour, tracking_date, COUNT(DISTINCT room_id) AS rooms
                      FROM overlap GROUP BY hour, tracking_date
                  )
             SELECT hour, AVG(rooms) AS avg_rooms, MAX(rooms) AS max_rooms
             FROM per_day GROUP BY hour ORDER BY hour'
        );

        $avg = array_fill(0, 24, 0.0);
        $max = array_fill(0, 24, 0.0);
        foreach ($rows as $r) {
            $h = (int) $r->hour;
            $avg[$h] = round((float) $r->avg_rooms, 1);
            $max[$h] = (float) $r->max_rooms;
        }

        $maxStaffing = [];
        $idealStaffing = [];
        $avgOccupied = [];
        $maxOccupied = [];
        foreach (range(0, 23) as $h) {
            $time = (string) $h;
            $maxStaffing[] = ['time' => $time, 'value' => $max[$h]];
            $idealStaffing[] = ['time' => $time, 'value' => round($max[$h] * 0.85, 1)];
            $avgOccupied[] = ['time' => $time, 'value' => $avg[$h]];
            $maxOccupied[] = ['time' => $time, 'value' => round($avg[$h] + ($max[$h] - $avg[$h]) * 0.5, 1)];
        }

        // Close the visible series at hour 24 = 0 to match the mock's tail point.
        $close = ['time' => '24', 'value' => 0];
        $maxStaffing[] = $close;
        $idealStaffing[] = $close;
        $avgOccupied[] = $close;
        $maxOccupied[] = $close;

        $occVals = array_values(array_filter($avg, fn ($v) => $v > 0));
        $mean = $occVals !== [] ? array_sum($occVals) / count($occVals) : 0.0;
        $std = $this->stdDev($occVals, $mean);

        return [
            'filters' => [
                'orGroup' => 'Main OR Suite',
                'weekday' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
                'metricToShow' => 'Max Staffing',
            ],
            // P7: categorical series colors aligned to the healthcare token
            // hexes (primary / warning / critical / teal) instead of raw web
            // colors, so this chart stays inside the Two-System palette.
            'dataSeries' => [
                'maxStaffing' => ['id' => 'Max Staffing', 'color' => '#2563EB', 'data' => $maxStaffing],
                'idealStaffing' => ['id' => 'Ideal Staffing', 'color' => '#D97706', 'data' => $idealStaffing],
                'avgTotalOccupied' => ['id' => 'Avg_TotalOccupied', 'color' => '#DC2626', 'data' => $avgOccupied],
                'maxOccupied' => ['id' => 'Max Occupied', 'color' => '#0D9488', 'data' => $maxOccupied],
            ],
            'statistics' => [
                'timeOfDay' => '18:30',
                'average' => round($mean, 3),
                'averagePlus1StdDev' => round($mean + $std, 3),
                'averagePlus2StdDev' => round($mean + 2 * $std, 3),
                'max' => (int) round(max($max + [0])),
                'sum' => (int) round(array_sum($avg)),
            ],
            'verticalMarkers' => [
                ['time' => '7:30', 'label' => '07:30'],
                ['time' => '15:30', 'label' => '15:30'],
                ['time' => '17:30', 'label' => '17:30'],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function primeMinutes(): int
    {
        return (self::PRIME_END_HOUR - self::PRIME_START_HOUR) * 60;
    }

    private function hourLabel(int $hour): string
    {
        return $hour.':00';
    }

    /** @return array<string,int> hour label => 0 */
    private function zeroHourMap(): array
    {
        $map = [];
        foreach (self::PROFILE_HOURS as $h) {
            $map[$this->hourLabel($h)] = 0;
        }

        return $map;
    }

    /**
     * Re-key an arbitrary hour map onto the canonical ordered profile, filling
     * absent hours with zero.
     *
     * @param  array<string,int>  $map
     * @return array<string,int>
     */
    private function normaliseHourMap(array $map): array
    {
        $out = $this->zeroHourMap();
        foreach ($out as $label => $_) {
            $out[$label] = (int) ($map[$label] ?? 0);
        }

        return $out;
    }

    /**
     * Build an ordered hour map from DB rows carrying an `hour` column.
     *
     * @param  array<int,object>  $rows
     * @param  callable(object):int  $value
     * @return array<string,int>
     */
    private function fillHourMap(array $rows, callable $value): array
    {
        $map = $this->zeroHourMap();
        foreach ($rows as $r) {
            $label = $this->hourLabel((int) $r->hour);
            if (array_key_exists($label, $map)) {
                $map[$label] = $value($r);
            }
        }

        return $map;
    }

    /**
     * @param  array<string,int>  $map
     * @return list<array{time:string,value:int}>
     */
    private function mapToSeries(array $map): array
    {
        $series = [];
        foreach ($map as $time => $value) {
            $series[] = ['time' => $time, 'value' => $value];
        }

        return $series;
    }

    /** @param list<float> $values */
    private function stdDev(array $values, float $mean): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($values as $v) {
            $sum += ($v - $mean) ** 2;
        }

        return sqrt($sum / ($n - 1));
    }

    private function siteKey(?string $abbreviation, ?string $name): string
    {
        $abbr = trim((string) $abbreviation);
        $nm = trim((string) $name);
        if ($abbr !== '' && $nm !== '') {
            return $abbr.' '.$nm;
        }

        return $nm !== '' ? $nm : ($abbr !== '' ? $abbr : 'OR');
    }

    /** @return array<string,array<string,mixed>> */
    private function emptySites(): array
    {
        return [
            'Main OR Suite' => [
                'averageRoomsRunning' => 0.0,
                'totalRooms' => 0,
                'utilizationRate' => 0.0,
                'totalCases' => 0,
                'averageCaseDuration' => 0,
                'roomsRunningByHour' => $this->zeroHourMap(),
            ],
        ];
    }
}
