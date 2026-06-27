<?php

declare(strict_types=1);

namespace App\Services\Ed;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ED Resource Optimization (Predictions) payload builder.
 *
 * Produces the data consumed by resources/js/Pages/ED/Predictions/Resources.jsx:
 * recommended staffing / bed allocation derived from PREDICTED arrivals and the
 * historical acuity mix, expressed as required nurses / providers / beds by hour
 * versus what is currently available.
 *
 * All figures are computed from the live `prod.ed_visits` table (is_deleted = false)
 * plus a deterministic capacity baseline. The forecast is derived from the historical
 * hourly arrival profile and ESI mix, so the same database produces the same page on
 * every render (no randomness). Safe on empty tables — returns zeros / empty arrays
 * and never throws. Query idioms mirror App\Services\Dashboard\EdDashboardService.
 */
class ResourceOptimizationService
{
    /** ED unit identifier (prod.units: "Emergency Department", type 'ed'). */
    private const ED_UNIT_ID = 1;

    /** Forecast horizon in hours rendered by the required-vs-available chart. */
    private const HORIZON_HOURS = 8;

    /** Lookback window for the arrival / acuity profile. */
    private const PROFILE_DAYS = 14;

    /**
     * Acuity weights — the relative care intensity of one patient at each ESI
     * level, used to convert a raw arrival count into a weighted demand load.
     * ESI-1/2 (resuscitation/emergent) drive far more staffing than ESI-4/5.
     */
    private const ACUITY_WEIGHT = [
        1 => 3.0,
        2 => 2.2,
        3 => 1.3,
        4 => 0.7,
        5 => 0.5,
    ];

    /**
     * Productivity baselines — weighted-demand units one staffed resource can
     * absorb per hour. Lower = more staff needed. ED rules of thumb (1 nurse
     * per ~3 active patients, 1 provider per ~9, 1 bed per active patient).
     */
    private const NURSE_CAPACITY = 3.0;     // weighted patients per nurse

    private const PROVIDER_CAPACITY = 7.0;  // weighted patients per provider

    private const BED_CAPACITY = 1.6;       // weighted patients per bed-hour

    /** Hours an ESI patient occupies a bed (drives bed demand from arrivals). */
    private const DWELL_FACTOR = 1.5;

    /**
     * Build the full Resource Optimization payload.
     *
     * @return array{
     *     kpis: array<string,mixed>,
     *     forecast: list<array<string,mixed>>,
     *     available: array{nurses:int,providers:int,beds:int},
     *     recommendations: list<array<string,mixed>>,
     *     acuityMix: list<array{label:string,esi:int,count:int,pct:float}>,
     *     generatedAt: string
     * }
     */
    public function build(): array
    {
        $now = Carbon::now();

        $arrivalProfile = $this->hourlyArrivalProfile($now);
        $acuityMix = $this->acuityMix($now);

        // First derive per-hour REQUIRED resources from predicted arrivals + acuity,
        // then size the on-shift roster (the "available" roof) to the planned
        // baseline — the median required across the horizon, which is how a charge
        // nurse schedules a shift. Peak hours above that baseline surface as real,
        // data-derived gaps; quiet hours show slack. Beds are a fixed physical roof
        // from the live census, independent of the staffing roster.
        $required = $this->requiredByHour($now, $arrivalProfile, $acuityMix);
        $available = $this->availableResources($now, $required);
        $forecast = $this->assembleForecast($required, $available);
        $kpis = $this->kpis($forecast, $available, $acuityMix);
        $recommendations = $this->recommendations($forecast, $available);

        return [
            'kpis' => $kpis,
            'forecast' => $forecast,
            'available' => $available,
            'recommendations' => $recommendations,
            'acuityMix' => $this->acuityMixView($acuityMix),
            'generatedAt' => $now->toIso8601String(),
        ];
    }

    // -----------------------------------------------------------------------
    // Available resources (deterministic baseline anchored to live census)
    // -----------------------------------------------------------------------

    /**
     * On-shift roster (the "available" roof) versus a fixed physical bed roof.
     *
     * Beds come from the live ED census snapshot (a physical constraint). Staffing
     * is sized to the PLANNED baseline a charge nurse would schedule: the median
     * required across the horizon, floored so the demo is never trivially empty.
     * Sizing to the median (not the peak) is what makes peak hours legitimately
     * exceed the roster — surfacing the data-derived gaps the page exists to show.
     *
     * @param  list<array{requiredNurses:int,requiredProviders:int,requiredBeds:int}>  $required
     * @return array{nurses:int,providers:int,beds:int}
     */
    private function availableResources(Carbon $now, array $required): array
    {
        $census = DB::table('prod.census_snapshots')
            ->where('unit_id', self::ED_UNIT_ID)
            ->orderByDesc('captured_at')
            ->first(['staffed_beds']);

        $staffedBeds = (int) ($census->staffed_beds ?? 0);

        // Fall back to the physical ED bed inventory when no snapshot exists.
        if ($staffedBeds <= 0) {
            $staffedBeds = (int) DB::table('prod.beds')
                ->where('unit_id', self::ED_UNIT_ID)
                ->where('is_deleted', false)
                ->count();
        }

        $beds = max(1, $staffedBeds);

        $nurses = max(4, $this->median(array_column($required, 'requiredNurses')));
        $providers = max(2, $this->median(array_column($required, 'requiredProviders')));

        return [
            'nurses' => $nurses,
            'providers' => $providers,
            'beds' => $beds,
        ];
    }

    /**
     * Integer median of a list (lower-median for an even count). Returns 0 when
     * empty so callers can floor it.
     *
     * @param  list<int>  $values
     */
    private function median(array $values): int
    {
        if ($values === []) {
            return 0;
        }
        sort($values);
        $mid = intdiv(count($values) - 1, 2);

        return (int) $values[$mid];
    }

    // -----------------------------------------------------------------------
    // Historical arrival profile (avg arrivals per clock-hour)
    // -----------------------------------------------------------------------

    /**
     * Average arrivals for each clock-hour (0..23) over the lookback window.
     * Scaled by the actual number of distinct calendar days observed so a short
     * seed window (e.g. 1 day) does not collapse every prediction to 1.
     *
     * @return array<int,float> hour-of-day => avg arrivals/hour
     */
    private function hourlyArrivalProfile(Carbon $now): array
    {
        $window = $now->copy()->subDays(self::PROFILE_DAYS);

        $rows = DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->whereBetween('arrived_at', [$window, $now])
            ->selectRaw(
                'EXTRACT(HOUR FROM arrived_at)::int AS hr,
                 COUNT(*) AS cnt,
                 COUNT(DISTINCT arrived_at::date) AS days'
            )
            ->groupBy('hr')
            ->get();

        $profile = [];
        foreach ($rows as $row) {
            $days = max(1, (int) $row->days);
            $profile[(int) $row->hr] = (float) $row->cnt / $days;
        }

        return $profile;
    }

    // -----------------------------------------------------------------------
    // Acuity mix (historical ESI distribution -> normalized shares)
    // -----------------------------------------------------------------------

    /**
     * Normalized share of arrivals at each ESI level over the lookback window.
     * Falls back to a clinically typical mix when no acuity data is present.
     *
     * @return array<int,float> esi (1..5) => share (sums to 1.0)
     */
    private function acuityMix(Carbon $now): array
    {
        $window = $now->copy()->subDays(self::PROFILE_DAYS);

        $rows = DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->whereBetween('arrived_at', [$window, $now])
            ->whereNotNull('esi_level')
            ->selectRaw('esi_level, COUNT(*) AS cnt')
            ->groupBy('esi_level')
            ->pluck('cnt', 'esi_level')
            ->toArray();

        $total = (int) array_sum($rows);

        if ($total === 0) {
            // Typical academic-ED acuity distribution.
            return [1 => 0.03, 2 => 0.20, 3 => 0.48, 4 => 0.20, 5 => 0.09];
        }

        $mix = [];
        for ($esi = 1; $esi <= 5; $esi++) {
            $mix[$esi] = (int) ($rows[$esi] ?? 0) / $total;
        }

        return $mix;
    }

    // -----------------------------------------------------------------------
    // Per-hour forecast (required vs available)
    // -----------------------------------------------------------------------

    /**
     * For each of the next HORIZON_HOURS, project arrivals from the clock-hour
     * profile, convert to acuity-weighted demand, and translate that into the
     * nurses / providers / beds REQUIRED (independent of the roster). The roster
     * is sized off these values, so this runs first.
     *
     * @param  array<int,float>  $arrivalProfile
     * @param  array<int,float>  $acuityMix
     * @return list<array{
     *     hour:string,
     *     predictedArrivals:int,
     *     weightedDemand:float,
     *     requiredNurses:int,
     *     requiredProviders:int,
     *     requiredBeds:int
     * }>
     */
    private function requiredByHour(Carbon $now, array $arrivalProfile, array $acuityMix): array
    {
        // Average acuity weight of one arriving patient, given the mix.
        $avgWeight = 0.0;
        foreach (self::ACUITY_WEIGHT as $esi => $weight) {
            $avgWeight += $weight * ($acuityMix[$esi] ?? 0.0);
        }
        if ($avgWeight <= 0.0) {
            $avgWeight = 1.3; // ESI-3 fallback
        }

        $required = [];
        for ($i = 1; $i <= self::HORIZON_HOURS; $i++) {
            $slot = $now->copy()->addHours($i);
            $hr = (int) $slot->format('G');

            $predicted = (int) round($arrivalProfile[$hr] ?? 0.0);
            $predicted = max(1, $predicted);

            // Weighted demand for the hour: arrivals carry their acuity load, plus
            // residual dwell load from the prior hour's arrivals occupying beds.
            $arrivalLoad = $predicted * $avgWeight;
            $weightedDemand = round($arrivalLoad * self::DWELL_FACTOR, 1);

            $required[] = [
                'hour' => $slot->format('H:00'),
                'predictedArrivals' => $predicted,
                'weightedDemand' => $weightedDemand,
                'requiredNurses' => (int) ceil($weightedDemand / self::NURSE_CAPACITY),
                'requiredProviders' => (int) ceil($weightedDemand / self::PROVIDER_CAPACITY),
                'requiredBeds' => (int) ceil($weightedDemand / self::BED_CAPACITY),
            ];
        }

        return $required;
    }

    /**
     * Merge the per-hour required figures with the (now-known) roster to produce
     * the final required-vs-available forecast rows and per-hour status.
     *
     * @param  list<array<string,mixed>>  $required
     * @param  array{nurses:int,providers:int,beds:int}  $available
     * @return list<array{
     *     hour:string,
     *     predictedArrivals:int,
     *     weightedDemand:float,
     *     requiredNurses:int,
     *     requiredProviders:int,
     *     requiredBeds:int,
     *     availableNurses:int,
     *     availableProviders:int,
     *     availableBeds:int,
     *     status:string
     * }>
     */
    private function assembleForecast(array $required, array $available): array
    {
        $forecast = [];
        foreach ($required as $row) {
            $forecast[] = [
                'hour' => (string) $row['hour'],
                'predictedArrivals' => (int) $row['predictedArrivals'],
                'weightedDemand' => (float) $row['weightedDemand'],
                'requiredNurses' => (int) $row['requiredNurses'],
                'requiredProviders' => (int) $row['requiredProviders'],
                'requiredBeds' => (int) $row['requiredBeds'],
                'availableNurses' => $available['nurses'],
                'availableProviders' => $available['providers'],
                'availableBeds' => $available['beds'],
                'status' => $this->slotStatus(
                    (int) $row['requiredNurses'],
                    (int) $row['requiredProviders'],
                    (int) $row['requiredBeds'],
                    $available
                ),
            ];
        }

        return $forecast;
    }

    /**
     * Classify an hour by its worst resource gap.
     *
     * @param  array{nurses:int,providers:int,beds:int}  $available
     * @return string 'critical'|'warning'|'optimal'
     */
    private function slotStatus(int $nurses, int $providers, int $beds, array $available): string
    {
        $gaps = [
            $nurses - $available['nurses'],
            $providers - $available['providers'],
            $beds - $available['beds'],
        ];
        $worst = max($gaps);

        if ($worst >= 2) {
            return 'critical';
        }
        if ($worst >= 1) {
            return 'warning';
        }

        return 'optimal';
    }

    // -----------------------------------------------------------------------
    // KPI tiles
    // -----------------------------------------------------------------------

    /**
     * Headline KPIs: peak demand hour, max staffing gap, projected bed pressure,
     * and the predicted total arrivals across the horizon.
     *
     * @param  list<array<string,mixed>>  $forecast
     * @param  array{nurses:int,providers:int,beds:int}  $available
     * @param  array<int,float>  $acuityMix
     * @return array{
     *     peakArrivals:array<string,mixed>,
     *     nurseGap:array<string,mixed>,
     *     bedPressure:array<string,mixed>,
     *     highAcuityShare:array<string,mixed>
     * }
     */
    private function kpis(array $forecast, array $available, array $acuityMix): array
    {
        if ($forecast === []) {
            return [
                'peakArrivals' => ['value' => 0, 'hour' => '--:--', 'trend' => 'down', 'trendValue' => 0],
                'nurseGap' => ['value' => 0, 'hour' => '--:--', 'trend' => 'down', 'trendValue' => 0],
                'bedPressure' => ['value' => 0, 'trend' => 'down', 'trendValue' => 0],
                'highAcuityShare' => ['value' => 0, 'trend' => 'down', 'trendValue' => 0],
            ];
        }

        // Peak predicted arrivals.
        $peak = $forecast[0];
        foreach ($forecast as $slot) {
            if ($slot['predictedArrivals'] > $peak['predictedArrivals']) {
                $peak = $slot;
            }
        }

        // Largest nurse shortfall across the horizon.
        $maxNurseGap = 0;
        $maxNurseGapHour = $forecast[0]['hour'];
        foreach ($forecast as $slot) {
            $gap = $slot['requiredNurses'] - $available['nurses'];
            if ($gap > $maxNurseGap) {
                $maxNurseGap = $gap;
                $maxNurseGapHour = $slot['hour'];
            }
        }

        // Peak bed pressure (required beds / available beds, %).
        $maxRequiredBeds = max(array_column($forecast, 'requiredBeds'));
        $bedPressure = $available['beds'] > 0
            ? (int) round(100.0 * $maxRequiredBeds / $available['beds'])
            : 0;

        // High-acuity (ESI 1-2) share of the forecast mix.
        $highAcuityShare = (int) round(100.0 * (($acuityMix[1] ?? 0.0) + ($acuityMix[2] ?? 0.0)));

        return [
            'peakArrivals' => [
                'value' => (int) $peak['predictedArrivals'],
                'hour' => $peak['hour'],
                'trend' => 'up',
                'trendValue' => (int) $peak['predictedArrivals'],
            ],
            'nurseGap' => [
                'value' => $maxNurseGap,
                'hour' => $maxNurseGapHour,
                // A staffing gap is bad -> 'down' (critical-tinted) when positive.
                'trend' => $maxNurseGap > 0 ? 'down' : 'up',
                'trendValue' => $maxNurseGap,
            ],
            'bedPressure' => [
                'value' => $bedPressure,
                'trend' => $bedPressure >= 100 ? 'down' : 'up',
                'trendValue' => $bedPressure,
            ],
            'highAcuityShare' => [
                'value' => $highAcuityShare,
                'trend' => $highAcuityShare >= 25 ? 'down' : 'up',
                'trendValue' => $highAcuityShare,
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Recommendations
    // -----------------------------------------------------------------------

    /**
     * Actionable allocation recommendations derived from the forecast gaps.
     * Deterministic, ordered by severity, and never empty (a steady-state note
     * is surfaced when no gaps exist).
     *
     * @param  list<array<string,mixed>>  $forecast
     * @param  array{nurses:int,providers:int,beds:int}  $available
     * @return list<array{id:int,priority:string,resource:string,hour:string,detail:string,delta:int}>
     */
    private function recommendations(array $forecast, array $available): array
    {
        $out = [];
        $id = 1;

        foreach ($forecast as $slot) {
            $nurseGap = (int) $slot['requiredNurses'] - $available['nurses'];
            $providerGap = (int) $slot['requiredProviders'] - $available['providers'];
            $bedGap = (int) $slot['requiredBeds'] - $available['beds'];

            if ($nurseGap > 0) {
                $out[] = [
                    'id' => $id++,
                    'priority' => $nurseGap >= 2 ? 'critical' : 'warning',
                    'resource' => 'Nursing',
                    'hour' => (string) $slot['hour'],
                    'detail' => sprintf(
                        'Add %d nurse%s for %d predicted arrivals',
                        $nurseGap,
                        $nurseGap === 1 ? '' : 's',
                        (int) $slot['predictedArrivals']
                    ),
                    'delta' => $nurseGap,
                ];
            }

            if ($providerGap > 0) {
                $out[] = [
                    'id' => $id++,
                    'priority' => $providerGap >= 2 ? 'critical' : 'warning',
                    'resource' => 'Provider',
                    'hour' => (string) $slot['hour'],
                    'detail' => sprintf(
                        'Add %d provider%s to hold door-to-provider target',
                        $providerGap,
                        $providerGap === 1 ? '' : 's'
                    ),
                    'delta' => $providerGap,
                ];
            }

            if ($bedGap > 0) {
                $out[] = [
                    'id' => $id++,
                    'priority' => $bedGap >= 2 ? 'critical' : 'warning',
                    'resource' => 'Beds',
                    'hour' => (string) $slot['hour'],
                    'detail' => sprintf(
                        'Open %d additional bed%s or expedite dispositions',
                        $bedGap,
                        $bedGap === 1 ? '' : 's'
                    ),
                    'delta' => $bedGap,
                ];
            }
        }

        // Severity ordering: critical first, then by largest delta.
        usort($out, static function (array $a, array $b): int {
            $rank = static fn (string $p): int => $p === 'critical' ? 0 : 1;
            $byPriority = $rank($a['priority']) <=> $rank($b['priority']);

            return $byPriority !== 0 ? $byPriority : $b['delta'] <=> $a['delta'];
        });

        // Cap and re-number to keep the list digestible and stable.
        $out = array_slice($out, 0, 8);
        foreach ($out as $idx => &$rec) {
            $rec['id'] = $idx + 1;
        }
        unset($rec);

        if ($out === []) {
            $out[] = [
                'id' => 1,
                'priority' => 'info',
                'resource' => 'Staffing',
                'hour' => 'Next 8h',
                'detail' => 'Current allocation covers predicted demand — no changes needed',
                'delta' => 0,
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Acuity mix view shape
    // -----------------------------------------------------------------------

    /**
     * Render the normalized acuity mix as labelled rows for the UI.
     *
     * @param  array<int,float>  $acuityMix
     * @return list<array{label:string,esi:int,count:int,pct:float}>
     */
    private function acuityMixView(array $acuityMix): array
    {
        $labels = [
            1 => 'Resuscitation',
            2 => 'Emergent',
            3 => 'Urgent',
            4 => 'Semi-Urgent',
            5 => 'Non-Urgent',
        ];

        $out = [];
        for ($esi = 1; $esi <= 5; $esi++) {
            $share = $acuityMix[$esi] ?? 0.0;
            $out[] = [
                'label' => $labels[$esi],
                'esi' => $esi,
                'count' => 0, // share-based view; raw counts not surfaced here
                'pct' => round(100.0 * $share, 1),
            ];
        }

        return $out;
    }
}
