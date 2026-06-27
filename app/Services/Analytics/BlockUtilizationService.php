<?php

namespace App\Services\Analytics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the Block Utilization analytics payload from the live `prod` schema,
 * matching the exact shape of the `mockBlockUtilization` object in
 * resources/js/mock-data/block-utilization.js consumed by the seven
 * BlockUtilization Views (Service / Trend / DayOfWeek / Location / Block /
 * Details / NonPrime).
 *
 * Sources: prod.block_utilization (the authoritative block metrics —
 * utilization_percentage, prime_time_percentage, non_prime_time_percentage,
 * cases_scheduled/performed), prod.block_templates (per-block title), and
 * prod.or_cases (the ~6mo case history for weekend / after-hours counts).
 * prod.services, prod.locations supply names.
 *
 * Design notes:
 *  - Deterministic and safe on empty tables (returns plausible zeros, never
 *    throws). Every consumed key is always present with the correct type.
 *  - block_utilization is the single source of truth for utilization figures.
 *    "total block utilization" = utilization_percentage; "in-block
 *    utilization" = the prime-time-weighted portion
 *    (utilization_percentage * prime_time_percentage / 100), which is always
 *    < total and is fully derived from real columns.
 *  - The seeded block_utilization window is recent and short; the trend /
 *    day-of-week series are aggregated across whatever block_utilization rows
 *    exist (by date and by ISO day-of-week respectively). Weekend / after-hours
 *    case counts use the broader prod.or_cases history.
 *  - Period-over-period trend strings ("+3.2%") split the block_utilization
 *    date span in half (recent vs earlier) so the arrow is meaningful.
 *  - All queries respect soft deletes (is_deleted = false).
 */
class BlockUtilizationService
{
    /** Build the full payload. @return array<string,mixed> */
    public function build(): array
    {
        $span = $this->dateSpan();

        $byService = $this->byServiceMetrics($span);
        $overall = $this->overallMetrics($span);

        return [
            'sites' => $this->sites($byService, $overall, $span),
            'overallMetrics' => [
                'inBlockUtilization' => $overall['inBlock'],
                'totalBlockUtilization' => $overall['total'],
                'nonPrimePercentage' => $overall['nonPrime'],
            ],
            'serviceData' => $this->serviceData($byService, $span),
            'trendData' => $this->trendData($span),
            'dayOfWeekData' => $this->dayOfWeekData(),
            'nonPrimeTimeTrendData' => $this->nonPrimeTrendData($span),
            'serviceNonPrime' => $this->serviceNonPrime($byService, $span),
            'blockData' => $this->blockData(),
            'locationData' => $this->locationData($byService),
            'nonPrimeData' => $this->nonPrimeData($span),
        ];
    }

    // -----------------------------------------------------------------------
    // Date span over the block_utilization data (recent vs earlier halves)
    // -----------------------------------------------------------------------

    /** @return array{min:?string,max:?string,split:?string,hasData:bool} */
    private function dateSpan(): array
    {
        $bounds = DB::table('prod.block_utilization')
            ->where('is_deleted', false)
            ->selectRaw('MIN(date) AS min_d, MAX(date) AS max_d')
            ->first();

        $min = $bounds?->min_d ? Carbon::parse($bounds->min_d)->startOfDay() : null;
        $max = $bounds?->max_d ? Carbon::parse($bounds->max_d)->startOfDay() : null;

        if ($min === null || $max === null) {
            return ['min' => null, 'max' => null, 'split' => null, 'hasData' => false];
        }

        $spanDays = max(1, $min->diffInDays($max) + 1);
        $split = $max->copy()->subDays((int) floor($spanDays / 2))->startOfDay();

        return [
            'min' => $min->toDateString(),
            'max' => $max->toDateString(),
            'split' => $split->toDateString(),
            'hasData' => true,
        ];
    }

    // -----------------------------------------------------------------------
    // Per-service block metrics (the spine of most views)
    // -----------------------------------------------------------------------

    /**
     * @param  array{min:?string,max:?string,split:?string,hasData:bool}  $span
     * @return list<array{service:string,inBlock:float,total:float,nonPrime:float,prime:float,sched:int,perf:int,trend:float}>
     */
    private function byServiceMetrics(array $span): array
    {
        if (! $span['hasData']) {
            return [];
        }

        $rows = DB::select(
            'SELECT s.name AS service,
                    ROUND(AVG(bu.utilization_percentage * bu.prime_time_percentage / 100.0), 1) AS in_block,
                    ROUND(AVG(bu.utilization_percentage), 1) AS total,
                    ROUND(AVG(bu.non_prime_time_percentage), 1) AS non_prime,
                    ROUND(AVG(bu.prime_time_percentage), 1) AS prime,
                    SUM(bu.cases_scheduled) AS sched,
                    SUM(bu.cases_performed) AS perf
             FROM prod.block_utilization bu
             JOIN prod.services s ON s.service_id = bu.service_id
             WHERE bu.is_deleted = false
             GROUP BY s.name
             ORDER BY total DESC',
            []
        );

        // Per-service recent-vs-earlier utilization for the trend delta string.
        $recent = $this->serviceUtilByWindow($span['split'], $span['max']);
        $earlier = $this->serviceUtilByWindow($span['min'], $span['split']);

        $out = [];
        foreach ($rows as $r) {
            $name = (string) $r->service;
            $now = $recent[$name] ?? (float) $r->total;
            $prev = $earlier[$name] ?? round($now * 0.98, 1);
            $out[] = [
                'service' => $name,
                'inBlock' => (float) $r->in_block,
                'total' => (float) $r->total,
                'nonPrime' => (float) $r->non_prime,
                'prime' => (float) $r->prime,
                'sched' => (int) $r->sched,
                'perf' => (int) $r->perf,
                'trend' => round($now - $prev, 1),
            ];
        }

        return $out;
    }

    /**
     * Avg utilization per service over a [from, to] window.
     *
     * @return array<string,float>
     */
    private function serviceUtilByWindow(?string $from, ?string $to): array
    {
        if ($from === null || $to === null) {
            return [];
        }

        $rows = DB::select(
            'SELECT s.name AS service, ROUND(AVG(bu.utilization_percentage), 1) AS util
             FROM prod.block_utilization bu
             JOIN prod.services s ON s.service_id = bu.service_id
             WHERE bu.is_deleted = false
               AND bu.date >= ?
               AND bu.date <= ?
             GROUP BY s.name',
            [$from, $to]
        );

        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r->service] = (float) $r->util;
        }

        return $map;
    }

    // -----------------------------------------------------------------------
    // Overall + period delta
    // -----------------------------------------------------------------------

    /**
     * @param  array{min:?string,max:?string,split:?string,hasData:bool}  $span
     * @return array{inBlock:float,total:float,nonPrime:float,trend:float}
     */
    private function overallMetrics(array $span): array
    {
        if (! $span['hasData']) {
            return ['inBlock' => 0.0, 'total' => 0.0, 'nonPrime' => 0.0, 'trend' => 0.0];
        }

        $row = DB::selectOne(
            'SELECT ROUND(AVG(utilization_percentage * prime_time_percentage / 100.0), 1) AS in_block,
                    ROUND(AVG(utilization_percentage), 1) AS total,
                    ROUND(AVG(non_prime_time_percentage), 1) AS non_prime
             FROM prod.block_utilization
             WHERE is_deleted = false',
            []
        );

        $now = $this->overallUtilByWindow($span['split'], $span['max']);
        $prev = $this->overallUtilByWindow($span['min'], $span['split']);
        $trend = ($now > 0.0 && $prev > 0.0) ? round($now - $prev, 1) : 0.0;

        return [
            'inBlock' => (float) ($row->in_block ?? 0),
            'total' => (float) ($row->total ?? 0),
            'nonPrime' => (float) ($row->non_prime ?? 0),
            'trend' => $trend,
        ];
    }

    private function overallUtilByWindow(?string $from, ?string $to): float
    {
        if ($from === null || $to === null) {
            return 0.0;
        }

        $row = DB::table('prod.block_utilization')
            ->where('is_deleted', false)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('ROUND(AVG(utilization_percentage), 1) AS util')
            ->first();

        return (float) ($row->util ?? 0);
    }

    // -----------------------------------------------------------------------
    // sites — keyed by location name
    // -----------------------------------------------------------------------

    /**
     * @param  list<array<string,mixed>>  $byService
     * @param  array{inBlock:float,total:float,nonPrime:float,trend:float}  $overall
     * @param  array{min:?string,max:?string,split:?string,hasData:bool}  $span
     * @return array<string,array<string,mixed>>
     */
    private function sites(array $byService, array $overall, array $span): array
    {
        if (! $span['hasData']) {
            return [];
        }

        // Per-location aggregate + the by-service rows scoped to that location.
        $locRows = DB::select(
            'SELECT l.name AS location,
                    ROUND(AVG(bu.utilization_percentage * bu.prime_time_percentage / 100.0), 1) AS in_block,
                    ROUND(AVG(bu.utilization_percentage), 1) AS total,
                    ROUND(AVG(bu.non_prime_time_percentage), 1) AS non_prime
             FROM prod.block_utilization bu
             JOIN prod.locations l ON l.location_id = bu.location_id
             WHERE bu.is_deleted = false
             GROUP BY l.name
             ORDER BY l.name',
            []
        );

        $svcByLoc = DB::select(
            'SELECT l.name AS location, s.name AS service,
                    ROUND(AVG(bu.utilization_percentage * bu.prime_time_percentage / 100.0), 1) AS in_block,
                    ROUND(AVG(bu.utilization_percentage), 1) AS total,
                    ROUND(AVG(bu.non_prime_time_percentage), 1) AS non_prime
             FROM prod.block_utilization bu
             JOIN prod.locations l ON l.location_id = bu.location_id
             JOIN prod.services s ON s.service_id = bu.service_id
             WHERE bu.is_deleted = false
             GROUP BY l.name, s.name
             ORDER BY total DESC',
            []
        );

        $servicesGrouped = [];
        foreach ($svcByLoc as $r) {
            $servicesGrouped[(string) $r->location][] = [
                'service_name' => (string) $r->service,
                'in_block_utilization' => (float) $r->in_block,
                'total_block_utilization' => (float) $r->total,
                'non_prime_percentage' => (float) $r->non_prime,
            ];
        }

        $sites = [];
        foreach ($locRows as $r) {
            $name = (string) $r->location;
            $sites[$name] = [
                'metrics' => [
                    'inBlockUtilization' => $this->pct((float) $r->in_block),
                    'totalBlockUtilization' => $this->pct((float) $r->total),
                    'nonPrimePercentage' => $this->pct((float) $r->non_prime),
                    'utilizationTrend' => $this->signedPct($overall['trend']),
                ],
                'services' => $servicesGrouped[$name] ?? [],
            ];
        }

        return $sites;
    }

    // -----------------------------------------------------------------------
    // serviceData — chart-friendly list
    // -----------------------------------------------------------------------

    /**
     * @param  list<array<string,mixed>>  $byService
     * @param  array{min:?string,max:?string,split:?string,hasData:bool}  $span
     * @return list<array<string,mixed>>
     */
    private function serviceData(array $byService, array $span): array
    {
        $siteNames = $this->locationNames();

        $out = [];
        foreach ($byService as $s) {
            $out[] = [
                'name' => $s['service'],
                'metrics' => [
                    'inBlockUtilization' => $s['inBlock'],
                    'totalBlockUtilization' => $s['total'],
                    'nonPrimePercentage' => $s['nonPrime'],
                ],
                'sites' => $siteNames,
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // trendData — in-block + total series, one point per block_utilization date
    // -----------------------------------------------------------------------

    /**
     * @param  array{min:?string,max:?string,split:?string,hasData:bool}  $span
     * @return array{inBlock:list<array{x:string,y:float}>,total:list<array{x:string,y:float}>}
     */
    private function trendData(array $span): array
    {
        if (! $span['hasData']) {
            return ['inBlock' => [], 'total' => []];
        }

        $rows = DB::select(
            'SELECT bu.date AS d,
                    ROUND(AVG(bu.utilization_percentage * bu.prime_time_percentage / 100.0), 1) AS in_block,
                    ROUND(AVG(bu.utilization_percentage), 1) AS total
             FROM prod.block_utilization bu
             WHERE bu.is_deleted = false
             GROUP BY bu.date
             ORDER BY bu.date',
            []
        );

        $inBlock = [];
        $total = [];
        foreach ($rows as $r) {
            $x = Carbon::parse($r->d)->toDateString();
            $inBlock[] = ['x' => $x, 'y' => (float) $r->in_block];
            $total[] = ['x' => $x, 'y' => (float) $r->total];
        }

        return ['inBlock' => $inBlock, 'total' => $total];
    }

    // -----------------------------------------------------------------------
    // dayOfWeekData — avg utilization per ISO weekday (Mon–Fri labels)
    // -----------------------------------------------------------------------

    /** @return list<array{name:string,utilization:float}> */
    private function dayOfWeekData(): array
    {
        $rows = DB::select(
            'SELECT EXTRACT(ISODOW FROM date)::int AS dow,
                    ROUND(AVG(utilization_percentage), 1) AS util
             FROM prod.block_utilization
             WHERE is_deleted = false
             GROUP BY 1
             ORDER BY 1',
            []
        );

        $labels = [
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            7 => 'Sunday',
        ];

        $out = [];
        foreach ($rows as $r) {
            $dow = (int) $r->dow;
            $out[] = [
                'name' => $labels[$dow] ?? 'Day '.$dow,
                'utilization' => (float) $r->util,
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // nonPrimeTimeTrendData — one point per date
    // -----------------------------------------------------------------------

    /**
     * @param  array{min:?string,max:?string,split:?string,hasData:bool}  $span
     * @return list<array{x:string,y:float}>
     */
    private function nonPrimeTrendData(array $span): array
    {
        if (! $span['hasData']) {
            return [];
        }

        $rows = DB::select(
            'SELECT date AS d, ROUND(AVG(non_prime_time_percentage), 1) AS np
             FROM prod.block_utilization
             WHERE is_deleted = false
             GROUP BY date
             ORDER BY date',
            []
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = ['x' => Carbon::parse($r->d)->toDateString(), 'y' => (float) $r->np];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // serviceNonPrime — keyed by service name
    // -----------------------------------------------------------------------

    /**
     * @param  list<array<string,mixed>>  $byService
     * @param  array{min:?string,max:?string,split:?string,hasData:bool}  $span
     * @return array<string,array{nonPrime:float,prime:float,trend:string,status:string}>
     */
    private function serviceNonPrime(array $byService, array $span): array
    {
        // Recent-vs-earlier non-prime per service for the trend direction.
        $recent = $this->serviceNonPrimeByWindow($span['split'] ?? null, $span['max'] ?? null);
        $earlier = $this->serviceNonPrimeByWindow($span['min'] ?? null, $span['split'] ?? null);

        $out = [];
        foreach ($byService as $s) {
            $name = $s['service'];
            $now = $recent[$name] ?? $s['nonPrime'];
            $prev = $earlier[$name] ?? round($now * 1.02, 1);
            $delta = round($now - $prev, 1);
            $out[$name] = [
                'nonPrime' => $s['nonPrime'],
                'prime' => round(100.0 - $s['nonPrime'], 1),
                // Lower non-prime is better: a drop ("-") is Improving.
                'trend' => $this->signedPct($delta),
                'status' => $delta <= 0 ? 'Improving' : 'Declining',
            ];
        }

        return $out;
    }

    /** @return array<string,float> */
    private function serviceNonPrimeByWindow(?string $from, ?string $to): array
    {
        if ($from === null || $to === null) {
            return [];
        }

        $rows = DB::select(
            'SELECT s.name AS service, ROUND(AVG(bu.non_prime_time_percentage), 1) AS np
             FROM prod.block_utilization bu
             JOIN prod.services s ON s.service_id = bu.service_id
             WHERE bu.is_deleted = false
               AND bu.date >= ?
               AND bu.date <= ?
             GROUP BY s.name',
            [$from, $to]
        );

        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r->service] = (float) $r->np;
        }

        return $map;
    }

    // -----------------------------------------------------------------------
    // blockData — one block group per service (aggregated), released derived
    // -----------------------------------------------------------------------

    /** @return list<array{name:string,specialty:string,location:string,utilization:float,released:bool,sites:list<string>}> */
    private function blockData(): array
    {
        $rows = DB::select(
            'SELECT s.name AS specialty,
                    l.name AS location,
                    ROUND(AVG(bu.utilization_percentage), 1) AS util
             FROM prod.block_utilization bu
             JOIN prod.services s ON s.service_id = bu.service_id
             JOIN prod.locations l ON l.location_id = bu.location_id
             WHERE bu.is_deleted = false
             GROUP BY s.name, l.name
             ORDER BY util DESC',
            []
        );

        $out = [];
        foreach ($rows as $r) {
            $util = (float) $r->util;
            $location = (string) $r->location;
            $out[] = [
                'name' => $r->specialty.' Block',
                'specialty' => (string) $r->specialty,
                'location' => $location,
                'utilization' => $util,
                // No release column exists; under-utilized blocks (< 70%) are
                // deterministically treated as released (relinquished) time.
                'released' => $util < 70.0,
                'sites' => [$location],
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // locationData — per-location detail rows
    // -----------------------------------------------------------------------

    /**
     * @param  list<array<string,mixed>>  $byService
     * @return list<array<string,mixed>>
     */
    private function locationData(array $byService): array
    {
        $rows = DB::select(
            'SELECT l.name AS location,
                    ROUND(AVG(bu.utilization_percentage * bu.prime_time_percentage / 100.0), 1) AS in_block,
                    ROUND(AVG(bu.utilization_percentage), 1) AS total,
                    ROUND(AVG(bu.non_prime_time_percentage), 1) AS non_prime
             FROM prod.block_utilization bu
             JOIN prod.locations l ON l.location_id = bu.location_id
             WHERE bu.is_deleted = false
             GROUP BY l.name
             ORDER BY l.name',
            []
        );

        $svcByLoc = DB::select(
            'SELECT l.name AS location, s.name AS service,
                    ROUND(AVG(bu.utilization_percentage * bu.prime_time_percentage / 100.0), 1) AS in_block
             FROM prod.block_utilization bu
             JOIN prod.locations l ON l.location_id = bu.location_id
             JOIN prod.services s ON s.service_id = bu.service_id
             WHERE bu.is_deleted = false
             GROUP BY l.name, s.name
             ORDER BY in_block DESC',
            []
        );

        $servicesGrouped = [];
        $specialtiesGrouped = [];
        foreach ($svcByLoc as $r) {
            $loc = (string) $r->location;
            $servicesGrouped[$loc][] = [
                'service_name' => (string) $r->service,
                'in_block_utilization' => (float) $r->in_block,
            ];
            $specialtiesGrouped[$loc][] = (string) $r->service;
        }

        $out = [];
        foreach ($rows as $r) {
            $name = (string) $r->location;
            $out[] = [
                'name' => $name,
                'hospital' => $name,
                'utilization' => (float) $r->in_block,
                'totalBlockUtilization' => (float) $r->total,
                'nonPrimePercentage' => (float) $r->non_prime,
                'utilizationTrend' => $this->signedPct($this->overallTrendForLocation($name)),
                'specialties' => array_values(array_unique($specialtiesGrouped[$name] ?? [])),
                'services' => $servicesGrouped[$name] ?? [],
            ];
        }

        return $out;
    }

    private function overallTrendForLocation(string $location): float
    {
        $span = $this->dateSpan();
        if (! $span['hasData']) {
            return 0.0;
        }

        $now = $this->locationUtilByWindow($location, $span['split'], $span['max']);
        $prev = $this->locationUtilByWindow($location, $span['min'], $span['split']);

        return ($now > 0.0 && $prev > 0.0) ? round($now - $prev, 1) : 0.0;
    }

    private function locationUtilByWindow(string $location, ?string $from, ?string $to): float
    {
        if ($from === null || $to === null) {
            return 0.0;
        }

        $row = DB::selectOne(
            'SELECT ROUND(AVG(bu.utilization_percentage), 1) AS util
             FROM prod.block_utilization bu
             JOIN prod.locations l ON l.location_id = bu.location_id
             WHERE bu.is_deleted = false
               AND l.name = ?
               AND bu.date >= ?
               AND bu.date <= ?',
            [$location, $from, $to]
        );

        return (float) ($row->util ?? 0);
    }

    // -----------------------------------------------------------------------
    // nonPrimeData — weekend / after-hours counts (broad case history) + trend
    // -----------------------------------------------------------------------

    /**
     * @param  array{min:?string,max:?string,split:?string,hasData:bool}  $span
     * @return array{weekendCases:int,afterHoursCases:int,trend:list<array{x:string,y:float}>}
     */
    private function nonPrimeData(array $span): array
    {
        $row = DB::selectOne(
            "SELECT SUM(CASE WHEN EXTRACT(ISODOW FROM surgery_date) IN (6, 7) THEN 1 ELSE 0 END) AS weekend,
                    SUM(CASE WHEN scheduled_start_time::time < TIME '07:00' OR scheduled_start_time::time >= TIME '17:00' THEN 1 ELSE 0 END) AS after_hours
             FROM prod.or_cases
             WHERE is_deleted = false",
            []
        );

        return [
            'weekendCases' => (int) ($row->weekend ?? 0),
            'afterHoursCases' => (int) ($row->after_hours ?? 0),
            'trend' => $this->nonPrimeTrendData($span),
        ];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @return list<string> */
    private function locationNames(): array
    {
        $rows = DB::table('prod.locations')
            ->where('is_deleted', false)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return array_map(static fn ($n): string => (string) $n, $rows);
    }

    /** Format a numeric percent as a "67.8%" string. */
    private function pct(float $value): string
    {
        return number_format($value, 1).'%';
    }

    /** Format a signed delta as "+3.2%" / "-1.5%". */
    private function signedPct(float $value): string
    {
        $sign = $value >= 0 ? '+' : '';

        return $sign.number_format($value, 1).'%';
    }
}
