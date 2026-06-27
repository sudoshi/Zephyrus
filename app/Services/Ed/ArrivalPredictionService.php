<?php

declare(strict_types=1);

namespace App\Services\Ed;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the ED Arrival Prediction payload from the live `prod` schema.
 *
 * Derives an arrivals-by-hour-of-day profile from prod.ed_visits.arrived_at,
 * then projects a deterministic forecast curve for the next 24 hours with a
 * confidence band. All output is computed from real data plus deterministic
 * derivation (no randomness), so the demo is stable across page loads.
 *
 * Safe on empty tables (returns zeros / empty series, never throws). Query
 * idioms mirror App\Services\Dashboard\EdDashboardService.
 */
class ArrivalPredictionService
{
    /** Hours of forecast horizon rendered on the chart. */
    private const FORECAST_HOURS = 24;

    /** Look-back window for building the hourly arrival profile. */
    private const PROFILE_LOOKBACK_DAYS = 30;

    /**
     * Default population-level diurnal weights (relative arrival propensity by
     * clock hour, 0..23). Used to smooth a sparse live profile so the forecast
     * curve has a realistic ED shape (overnight trough, late-morning and early-
     * evening peaks) even when only a day or two of history exists. Sums are
     * irrelevant; only the relative shape is used.
     */
    private const DIURNAL_WEIGHTS = [
        0 => 0.55, 1 => 0.45, 2 => 0.40, 3 => 0.38, 4 => 0.40, 5 => 0.50,
        6 => 0.65, 7 => 0.80, 8 => 1.00, 9 => 1.15, 10 => 1.25, 11 => 1.30,
        12 => 1.25, 13 => 1.20, 14 => 1.15, 15 => 1.10, 16 => 1.15, 17 => 1.20,
        18 => 1.25, 19 => 1.20, 20 => 1.05, 21 => 0.90, 22 => 0.78, 23 => 0.65,
    ];

    /**
     * Assemble the full Arrival Prediction page payload.
     *
     * @return array{
     *     kpis:array<string,mixed>,
     *     forecast:list<array{hour:string,clock:int,predicted:int,lower:int,upper:int,historical:int}>,
     *     hourlyProfile:list<array{hour:string,clock:int,average:float,arrivals:int}>,
     *     meta:array<string,mixed>
     * }
     */
    public function build(): array
    {
        $now = Carbon::now();

        $profile = $this->hourlyProfile($now);   // per-hour expected arrivals/hour
        $observed = $this->observedByHour($now);  // raw counts per clock hour (look-back)

        $forecast = $this->forecast($now, $profile);
        $hourlyProfile = $this->profileSeries($profile, $observed);
        $kpis = $this->kpis($now, $profile, $forecast);
        $meta = $this->meta($now);

        return [
            'kpis' => $kpis,
            'forecast' => $forecast,
            'hourlyProfile' => $hourlyProfile,
            'meta' => $meta,
        ];
    }

    // -----------------------------------------------------------------------
    // Hourly arrival profile (expected arrivals per clock-hour, per day)
    // -----------------------------------------------------------------------

    /**
     * Expected arrivals per hour for each clock hour (0..23), blended from the
     * live look-back history and the population diurnal shape so the curve is
     * stable and well-formed even on sparse seed data.
     *
     * @return array<int,float> clock-hour => expected arrivals/hour
     */
    private function hourlyProfile(Carbon $now): array
    {
        $window = $now->copy()->subDays(self::PROFILE_LOOKBACK_DAYS);

        // Average arrivals per clock hour = total arrivals in that hour / number
        // of distinct calendar days that contributed any arrival in the window.
        $rows = DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->where('arrived_at', '>=', $window)
            ->where('arrived_at', '<=', $now)
            ->selectRaw(
                'EXTRACT(HOUR FROM arrived_at)::int AS hr,
                 COUNT(*) AS cnt'
            )
            ->groupBy('hr')
            ->pluck('cnt', 'hr')
            ->toArray();

        $distinctDays = (int) DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->where('arrived_at', '>=', $window)
            ->where('arrived_at', '<=', $now)
            ->distinct()
            ->selectRaw('DATE(arrived_at) AS d')
            ->get()
            ->count();
        $distinctDays = max(1, $distinctDays);

        // Overall mean arrivals/hour across the observed history (anchors scale).
        $totalArrivals = array_sum($rows);
        $observedMeanPerHour = $totalArrivals > 0
            ? $totalArrivals / ($distinctDays * 24)
            : 0.0;

        // Weight on live data grows with history depth; with very little data we
        // lean on the population shape, with ample data we trust observation.
        $liveWeight = min(1.0, $distinctDays / 7.0);
        $shapeWeight = 1.0 - $liveWeight;

        // Scale the diurnal shape so its mean matches the observed mean (or a
        // sensible floor of 2/hr when there is no history at all).
        $shapeMean = array_sum(self::DIURNAL_WEIGHTS) / 24.0;
        $scaleAnchor = $observedMeanPerHour > 0 ? $observedMeanPerHour : 2.0;
        $shapeScale = $shapeMean > 0 ? $scaleAnchor / $shapeMean : 0.0;

        $profile = [];
        for ($hr = 0; $hr < 24; $hr++) {
            $observedRate = ((int) ($rows[$hr] ?? 0)) / $distinctDays;
            $shapeRate = (self::DIURNAL_WEIGHTS[$hr] ?? 1.0) * $shapeScale;
            $profile[$hr] = round($liveWeight * $observedRate + $shapeWeight * $shapeRate, 3);
        }

        return $profile;
    }

    /**
     * Raw arrival counts per clock hour over the look-back window (for the
     * historical overlay on the profile chart).
     *
     * @return array<int,int> clock-hour => count
     */
    private function observedByHour(Carbon $now): array
    {
        $window = $now->copy()->subDays(self::PROFILE_LOOKBACK_DAYS);

        $rows = DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->where('arrived_at', '>=', $window)
            ->where('arrived_at', '<=', $now)
            ->selectRaw('EXTRACT(HOUR FROM arrived_at)::int AS hr, COUNT(*) AS cnt')
            ->groupBy('hr')
            ->pluck('cnt', 'hr')
            ->toArray();

        $out = [];
        for ($hr = 0; $hr < 24; $hr++) {
            $out[$hr] = (int) ($rows[$hr] ?? 0);
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Forecast curve (next N hours + confidence band)
    // -----------------------------------------------------------------------

    /**
     * Project the hourly profile forward over the forecast horizon, anchored to
     * the current wall-clock hour. The confidence band widens with both the
     * Poisson sampling uncertainty (sqrt of the rate) and the distance into the
     * horizon, so near-term predictions are tighter than far-term ones.
     *
     * @param  array<int,float>  $profile
     * @return list<array{hour:string,clock:int,predicted:int,lower:int,upper:int,historical:int}>
     */
    private function forecast(Carbon $now, array $profile): array
    {
        $historical = $this->observedByHour($now);
        $distinctDays = max(1, count($historical) > 0 ? $this->distinctProfileDays($now) : 1);

        $out = [];
        for ($i = 1; $i <= self::FORECAST_HOURS; $i++) {
            $slot = $now->copy()->addHours($i);
            $clock = (int) $slot->format('G');
            $rate = (float) ($profile[$clock] ?? 0.0);

            $predicted = (int) round($rate);

            // Poisson-style spread (sqrt(rate)) inflated by horizon distance.
            $horizonFactor = 1.0 + ($i / self::FORECAST_HOURS) * 0.6;
            $spread = sqrt(max($rate, 0.0)) * 1.64 * $horizonFactor; // ~90% band
            $spread = max($spread, $rate > 0 ? 1.0 : 0.0);

            $lower = max(0, (int) floor($rate - $spread));
            $upper = (int) ceil($rate + $spread);

            // Per-day historical average for this clock hour, for the overlay.
            $histAvg = (int) round(((int) ($historical[$clock] ?? 0)) / $distinctDays);

            $out[] = [
                'hour' => $slot->format('H:00'),
                'clock' => $clock,
                'predicted' => $predicted,
                'lower' => $lower,
                'upper' => $upper,
                'historical' => $histAvg,
            ];
        }

        return $out;
    }

    private function distinctProfileDays(Carbon $now): int
    {
        $window = $now->copy()->subDays(self::PROFILE_LOOKBACK_DAYS);

        return max(1, DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->where('arrived_at', '>=', $window)
            ->where('arrived_at', '<=', $now)
            ->distinct()
            ->selectRaw('DATE(arrived_at) AS d')
            ->get()
            ->count());
    }

    // -----------------------------------------------------------------------
    // Hourly profile series (24h shape for the secondary chart)
    // -----------------------------------------------------------------------

    /**
     * @param  array<int,float>  $profile
     * @param  array<int,int>  $observed
     * @return list<array{hour:string,clock:int,average:float,arrivals:int}>
     */
    private function profileSeries(array $profile, array $observed): array
    {
        $out = [];
        for ($hr = 0; $hr < 24; $hr++) {
            $out[] = [
                'hour' => sprintf('%02d:00', $hr),
                'clock' => $hr,
                'average' => round((float) ($profile[$hr] ?? 0.0), 1),
                'arrivals' => (int) ($observed[$hr] ?? 0),
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // KPI tiles
    // -----------------------------------------------------------------------

    /**
     * @param  array<int,float>  $profile
     * @param  list<array{predicted:int,clock:int}>  $forecast
     * @return array{
     *     next12h:array<string,mixed>,
     *     next24h:array<string,mixed>,
     *     peakHour:array<string,mixed>,
     *     currentRate:array<string,mixed>
     * }
     */
    private function kpis(Carbon $now, array $profile, array $forecast): array
    {
        $next12 = array_sum(array_map(
            static fn (array $f): int => (int) $f['predicted'],
            array_slice($forecast, 0, 12)
        ));
        $next24 = array_sum(array_map(
            static fn (array $f): int => (int) $f['predicted'],
            $forecast
        ));

        // Peak hour within the forecast horizon.
        $peak = ['predicted' => -1, 'hour' => '--:--'];
        foreach ($forecast as $f) {
            if ((int) $f['predicted'] > (int) $peak['predicted']) {
                $peak = $f;
            }
        }
        $peakValue = max(0, (int) $peak['predicted']);
        $peakLabel = $peakValue > 0 ? (string) $peak['hour'] : '--:--';

        // Current observed arrival rate (last 60 minutes).
        $lastHour = (int) DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->where('arrived_at', '>=', $now->copy()->subHour())
            ->where('arrived_at', '<=', $now)
            ->count();

        // Expected rate for the current clock hour, for the up/down trend.
        $currentClock = (int) $now->format('G');
        $expectedNow = (int) round((float) ($profile[$currentClock] ?? 0.0));
        $rateTrend = $lastHour === $expectedNow
            ? 'neutral'
            : ($lastHour > $expectedNow ? 'up' : 'down');

        // Next-hour vs current-hour expectation drives the next-12h trend arrow.
        $nextHourExpected = (int) ($forecast[0]['predicted'] ?? 0);
        $next12Trend = $nextHourExpected >= $expectedNow ? 'up' : 'down';

        return [
            'next12h' => [
                'value' => (int) $next12,
                'label' => 'Predicted arrivals (next 12h)',
                'trend' => $next12Trend,
                'trendValue' => abs($nextHourExpected - $expectedNow),
                'description' => 'Sum of hourly forecast',
            ],
            'next24h' => [
                'value' => (int) $next24,
                'label' => 'Predicted arrivals (next 24h)',
                'trend' => 'neutral',
                'trendValue' => null,
                'description' => 'Full forecast horizon',
            ],
            'peakHour' => [
                'value' => $peakLabel,
                'count' => $peakValue,
                'label' => 'Forecast peak hour',
                'trend' => 'up',
                'trendValue' => $peakValue,
                'description' => $peakValue > 0
                    ? $peakValue.' arrivals expected'
                    : 'No arrivals forecast',
            ],
            'currentRate' => [
                'value' => $lastHour,
                'expected' => $expectedNow,
                'label' => 'Arrivals (last 60 min)',
                'trend' => $rateTrend,
                'trendValue' => abs($lastHour - $expectedNow),
                'description' => 'vs '.$expectedNow.' expected this hour',
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Meta (window + model description)
    // -----------------------------------------------------------------------

    /**
     * @return array{
     *     generatedAt:string,
     *     historyDays:int,
     *     totalHistorical:int,
     *     horizonHours:int,
     *     hasData:bool
     * }
     */
    private function meta(Carbon $now): array
    {
        $window = $now->copy()->subDays(self::PROFILE_LOOKBACK_DAYS);

        $total = (int) DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->where('arrived_at', '>=', $window)
            ->where('arrived_at', '<=', $now)
            ->count();

        return [
            'generatedAt' => $now->toIso8601String(),
            'historyDays' => $this->distinctProfileDays($now),
            'totalHistorical' => $total,
            'horizonHours' => self::FORECAST_HOURS,
            'hasData' => $total > 0,
        ];
    }
}
