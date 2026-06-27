<?php

declare(strict_types=1);

namespace App\Services\Ed;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ED Acuity Prediction service.
 *
 * Builds the payload for resources/js/Pages/ED/Predictions/Acuity.jsx — the
 * predicted ESI acuity mix for incoming patients over the next four hours,
 * derived from the historical ESI-level proportions in prod.ed_visits plus a
 * deterministic per-hour arrival profile.
 *
 * Two distributions are produced:
 *   • historicalMix  — the observed ESI mix over the trailing 30-day window
 *     (the "model" the forecast is calibrated against), and the live in-ED mix.
 *   • predictedMix   — the expected ESI breakdown of the patients projected to
 *     arrive in each of the next four clock-hours, computed by spreading the
 *     hourly predicted arrival volume across the historical ESI proportions.
 *
 * Everything is deterministic and safe on empty tables (returns zeros / empty
 * arrays, never throws). Query idioms mirror App\Services\Dashboard\EdDashboardService.
 *
 * No source system supplies acuity-confidence or a model label, so those few
 * presentation fields are deterministically derived from the ed_visit cohort
 * (stable per dataset), never randomised.
 */
class AcuityPredictionService
{
    /** ED unit identifier (prod.units: "Emergency Department", type 'ed'). */
    private const ED_UNIT_ID = 1;

    /** Calibration window for the historical ESI mix (days). */
    private const HISTORY_DAYS = 30;

    /** Arrival-profile lookback used to project hourly volume (days). */
    private const PROFILE_DAYS = 14;

    /** Number of forward hourly buckets to forecast. */
    private const FORECAST_HOURS = 4;

    /**
     * The five ESI levels with their canonical labels and the design-system
     * status tone each maps to (paired with a label/icon on the frontend, never
     * colour alone). High acuity (1-2) is critical/warning; mid (3) is info;
     * low (4-5) is success.
     *
     * @var array<int,array{label:string,tone:string,short:string}>
     */
    private const ESI = [
        1 => ['label' => 'ESI 1 — Resuscitation', 'tone' => 'critical', 'short' => 'ESI 1'],
        2 => ['label' => 'ESI 2 — Emergent', 'tone' => 'warning', 'short' => 'ESI 2'],
        3 => ['label' => 'ESI 3 — Urgent', 'tone' => 'info', 'short' => 'ESI 3'],
        4 => ['label' => 'ESI 4 — Less Urgent', 'tone' => 'success', 'short' => 'ESI 4'],
        5 => ['label' => 'ESI 5 — Non-Urgent', 'tone' => 'success', 'short' => 'ESI 5'],
    ];

    /**
     * Assemble the full Acuity Prediction payload.
     *
     * @return array{
     *     generatedAt:string,
     *     kpis:array<string,mixed>,
     *     historicalMix:list<array<string,mixed>>,
     *     liveMix:list<array<string,mixed>>,
     *     predictedMix:array{hours:list<string>,series:list<array<string,mixed>>,totals:list<array<string,mixed>>},
     *     forecastRows:list<array<string,mixed>>
     * }
     */
    public function build(): array
    {
        $now = Carbon::now();

        $historyCounts = $this->esiCounts(
            $now->copy()->subDays(self::HISTORY_DAYS),
            $now
        );
        $historyTotal = (int) array_sum($historyCounts);

        $liveCounts = $this->liveEsiCounts($now);
        $liveTotal = (int) array_sum($liveCounts);

        // Proportion of each ESI level over the calibration window. When the
        // table is empty this stays an all-zero map so downstream maths is safe.
        $proportions = $this->proportions($historyCounts, $historyTotal);

        $hourlyArrivals = $this->hourlyArrivalForecast($now);
        $predicted = $this->predictedMix($hourlyArrivals, $proportions);

        $kpis = $this->kpis($proportions, $predicted, $liveCounts, $liveTotal, $historyTotal);

        return [
            'generatedAt' => $now->toIso8601String(),
            'kpis' => $kpis,
            'historicalMix' => $this->mixRows($historyCounts, $historyTotal),
            'liveMix' => $this->mixRows($liveCounts, $liveTotal),
            'predictedMix' => $predicted,
            'forecastRows' => $this->forecastRows($predicted, $proportions),
        ];
    }

    // -----------------------------------------------------------------------
    // Raw ESI counts
    // -----------------------------------------------------------------------

    /**
     * ESI-level counts for visits that arrived inside a window.
     *
     * @return array<int,int> keyed by ESI level 1..5 (always all five keys)
     */
    private function esiCounts(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->whereNotNull('esi_level')
            ->where('arrived_at', '>=', $from)
            ->where('arrived_at', '<=', $to)
            ->selectRaw('esi_level, COUNT(*) AS cnt')
            ->groupBy('esi_level')
            ->pluck('cnt', 'esi_level')
            ->toArray();

        return $this->normaliseEsiMap($rows);
    }

    /**
     * ESI-level counts for patients currently in the department.
     *
     * @return array<int,int> keyed by ESI level 1..5 (always all five keys)
     */
    private function liveEsiCounts(Carbon $now): array
    {
        $rows = DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->whereNotNull('esi_level')
            ->where('arrived_at', '<=', $now)
            ->whereNull('departed_at')
            ->selectRaw('esi_level, COUNT(*) AS cnt')
            ->groupBy('esi_level')
            ->pluck('cnt', 'esi_level')
            ->toArray();

        return $this->normaliseEsiMap($rows);
    }

    /**
     * Coerce a sparse {esi_level => count} result into a dense 1..5 int map.
     *
     * @param  array<int|string,int|string>  $rows
     * @return array<int,int>
     */
    private function normaliseEsiMap(array $rows): array
    {
        $out = [];
        foreach (array_keys(self::ESI) as $esi) {
            $out[$esi] = (int) ($rows[$esi] ?? 0);
        }

        return $out;
    }

    /**
     * Per-ESI proportions of a count map (sums to ~1.0; all-zero when empty).
     *
     * @param  array<int,int>  $counts
     * @return array<int,float>
     */
    private function proportions(array $counts, int $total): array
    {
        $out = [];
        foreach ($counts as $esi => $cnt) {
            $out[$esi] = $total > 0 ? $cnt / $total : 0.0;
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Hourly arrival forecast (volume only — acuity is layered on after)
    // -----------------------------------------------------------------------

    /**
     * Projected arrival volume for each of the next FORECAST_HOURS clock-hours,
     * using the average arrivals per clock-hour over the profile window.
     *
     * @return list<array{label:string,hour:int,volume:int}>
     */
    private function hourlyArrivalForecast(Carbon $now): array
    {
        $profile = DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->where('arrived_at', '>=', $now->copy()->subDays(self::PROFILE_DAYS))
            ->where('arrived_at', '<=', $now)
            ->selectRaw('EXTRACT(HOUR FROM arrived_at)::int AS hr, COUNT(*) AS cnt')
            ->groupBy('hr')
            ->pluck('cnt', 'hr')
            ->toArray();

        $hasProfile = array_sum($profile) > 0;

        $forecast = [];
        for ($i = 1; $i <= self::FORECAST_HOURS; $i++) {
            $slot = $now->copy()->addHours($i);
            $hr = (int) $slot->format('G');
            $perDay = (int) ($profile[$hr] ?? 0);
            // Average arrivals at this clock-hour; at least one expected arrival
            // when we have ANY history so the forecast is never blank.
            $volume = $hasProfile ? max(1, (int) round($perDay / self::PROFILE_DAYS)) : 0;
            $forecast[] = [
                'label' => $slot->format('H:00'),
                'hour' => $hr,
                'volume' => $volume,
            ];
        }

        return $forecast;
    }

    /**
     * Spread the projected arrival volume across the historical ESI proportions
     * to produce the predicted acuity mix per forecast hour.
     *
     * Each hour's projected volume is apportioned against the calibration
     * proportions so that EVERY hour's per-ESI counts sum exactly to that hour's
     * volume — i.e. each stacked column height equals the projected arrivals for
     * that hour. Fractional remainders are CARRIED forward hour-to-hour, so over
     * the whole horizon the cumulative ESI breakdown faithfully tracks the
     * historical mix even at the low per-hour volumes typical of an ED.
     *
     * @param  list<array{label:string,hour:int,volume:int}>  $hourlyArrivals
     * @param  array<int,float>  $proportions
     * @return array{hours:list<string>,series:list<array{esi:int,short:string,label:string,tone:string,data:list<int>,total:int}>,totals:list<array{label:string,volume:int}>}
     */
    private function predictedMix(array $hourlyArrivals, array $proportions): array
    {
        $hours = array_map(static fn (array $h): string => $h['label'], $hourlyArrivals);
        $hourCount = count($hourlyArrivals);

        // Per-ESI series, each holding one predicted count per forecast hour.
        $series = [];
        foreach (self::ESI as $esi => $meta) {
            $series[$esi] = [
                'esi' => $esi,
                'short' => $meta['short'],
                'label' => $meta['label'],
                'tone' => $meta['tone'],
                'data' => array_fill(0, $hourCount, 0),
                'total' => 0,
            ];
        }

        // Running fractional carry per ESI level. Apportioning hour-by-hour with
        // a carried remainder makes each column sum to its volume while the
        // horizon mix converges on the calibration proportions.
        $carry = array_fill_keys(array_keys(self::ESI), 0.0);

        foreach ($hourlyArrivals as $hourIndex => $slot) {
            $volume = (int) $slot['volume'];
            if ($volume <= 0) {
                continue;
            }

            // Add this hour's fractional demand to the carry, then take floors.
            $alloc = [];
            $remainders = [];
            $assigned = 0;
            foreach (self::ESI as $esi => $_meta) {
                $carry[$esi] += $volume * ($proportions[$esi] ?? 0.0);
                $floor = (int) floor($carry[$esi]);
                $alloc[$esi] = $floor;
                $remainders[$esi] = $carry[$esi] - $floor;
                $assigned += $floor;
            }

            // Reconcile to exactly $volume seats for this hour.
            $leftover = $volume - $assigned;
            if ($leftover > 0) {
                arsort($remainders);
                foreach (array_keys($remainders) as $esi) {
                    if ($leftover <= 0) {
                        break;
                    }
                    $alloc[$esi]++;
                    $leftover--;
                }
            } elseif ($leftover < 0) {
                // Over-allocated (possible when carries crossed simultaneously);
                // claw back from the smallest fractional remainders first.
                asort($remainders);
                foreach (array_keys($remainders) as $esi) {
                    if ($leftover >= 0) {
                        break;
                    }
                    if ($alloc[$esi] > 0) {
                        $alloc[$esi]--;
                        $leftover++;
                    }
                }
            }

            // Commit the integer seats and reduce the carry by what was emitted.
            foreach (self::ESI as $esi => $_meta) {
                $emitted = (int) $alloc[$esi];
                $series[$esi]['data'][$hourIndex] = $emitted;
                $series[$esi]['total'] += $emitted;
                $carry[$esi] -= $emitted;
            }
        }

        $totals = [];
        foreach ($hourlyArrivals as $slot) {
            $totals[] = ['label' => $slot['label'], 'volume' => (int) $slot['volume']];
        }

        return [
            'hours' => array_values($hours),
            'series' => array_values($series),
            'totals' => $totals,
        ];
    }

    /**
     * Largest-remainder apportionment of an integer volume across proportions.
     *
     * @param  array<int,float>  $proportions
     * @return array<int,int> keyed by ESI level, summing exactly to $volume
     */
    private function apportion(int $volume, array $proportions): array
    {
        $alloc = [];
        $remainders = [];
        $assigned = 0;

        foreach ($proportions as $esi => $share) {
            $exact = $volume * $share;
            $floor = (int) floor($exact);
            $alloc[$esi] = $floor;
            $remainders[$esi] = $exact - $floor;
            $assigned += $floor;
        }

        $leftover = $volume - $assigned;
        if ($leftover > 0) {
            // Hand out the residual seats to the largest fractional remainders,
            // breaking ties toward the lower (higher-acuity) ESI level for a
            // deterministic, clinically-conservative result.
            arsort($remainders);
            foreach (array_keys($remainders) as $esi) {
                if ($leftover <= 0) {
                    break;
                }
                $alloc[$esi]++;
                $leftover--;
            }
        }

        return $alloc;
    }

    // -----------------------------------------------------------------------
    // Presentation rows
    // -----------------------------------------------------------------------

    /**
     * Flatten a count map into renderable mix rows (count + percentage).
     *
     * @param  array<int,int>  $counts
     * @return list<array{esi:int,short:string,label:string,tone:string,count:int,percent:float}>
     */
    private function mixRows(array $counts, int $total): array
    {
        $rows = [];
        foreach (self::ESI as $esi => $meta) {
            $count = (int) ($counts[$esi] ?? 0);
            $rows[] = [
                'esi' => $esi,
                'short' => $meta['short'],
                'label' => $meta['label'],
                'tone' => $meta['tone'],
                'count' => $count,
                'percent' => $total > 0 ? round(100.0 * $count / $total, 1) : 0.0,
            ];
        }

        return $rows;
    }

    /**
     * Per-ESI forecast summary: total predicted incoming volume + the share
     * (against the calibration proportions) over the whole forecast horizon.
     *
     * @param  array{series:list<array{esi:int,short:string,label:string,tone:string,total:int}>}  $predicted
     * @param  array<int,float>  $proportions
     * @return list<array{esi:int,short:string,label:string,tone:string,predicted:int,percent:float}>
     */
    private function forecastRows(array $predicted, array $proportions): array
    {
        $grandTotal = 0;
        foreach ($predicted['series'] as $s) {
            $grandTotal += (int) $s['total'];
        }

        $rows = [];
        foreach ($predicted['series'] as $s) {
            $esi = (int) $s['esi'];
            $rows[] = [
                'esi' => $esi,
                'short' => $s['short'],
                'label' => $s['label'],
                'tone' => $s['tone'],
                'predicted' => (int) $s['total'],
                'percent' => $grandTotal > 0
                    ? round(100.0 * (int) $s['total'] / $grandTotal, 1)
                    : round(100.0 * ($proportions[$esi] ?? 0.0), 1),
            ];
        }

        return $rows;
    }

    // -----------------------------------------------------------------------
    // KPIs
    // -----------------------------------------------------------------------

    /**
     * Headline KPIs for the four metric tiles.
     *
     * @param  array<int,float>  $proportions
     * @param  array{series:list<array{esi:int,total:int}>,totals:list<array{volume:int}>}  $predicted
     * @param  array<int,int>  $liveCounts
     * @return array{
     *     predictedArrivals:int,
     *     predictedHighAcuity:int,
     *     predictedHighAcuityPct:float,
     *     highAcuityTrend:string,
     *     liveHighAcuity:int,
     *     liveHighAcuityPct:float,
     *     dominantEsi:string,
     *     dominantEsiPct:float,
     *     modelConfidence:int,
     *     sampleSize:int,
     *     horizonHours:int
     * }
     */
    private function kpis(
        array $proportions,
        array $predicted,
        array $liveCounts,
        int $liveTotal,
        int $historyTotal
    ): array {
        $predictedArrivals = 0;
        foreach ($predicted['totals'] as $t) {
            $predictedArrivals += (int) $t['volume'];
        }

        $predictedHighAcuity = 0;
        foreach ($predicted['series'] as $s) {
            if ((int) $s['esi'] <= 2) {
                $predictedHighAcuity += (int) $s['total'];
            }
        }
        $predictedHighAcuityPct = $predictedArrivals > 0
            ? round(100.0 * $predictedHighAcuity / $predictedArrivals, 1)
            : 0.0;

        // Live high-acuity load currently in the department.
        $liveHighAcuity = (int) ($liveCounts[1] ?? 0) + (int) ($liveCounts[2] ?? 0);
        $liveHighAcuityPct = $liveTotal > 0
            ? round(100.0 * $liveHighAcuity / $liveTotal, 1)
            : 0.0;

        // Forecast high-acuity share against the calibration baseline (ESI 1+2).
        $baselineHighAcuityPct = round(
            100.0 * (($proportions[1] ?? 0.0) + ($proportions[2] ?? 0.0)),
            1
        );
        // "up" means more high-acuity than the live floor is currently carrying
        // — surfaced with an icon/label on the frontend, never colour alone.
        $highAcuityTrend = $predictedHighAcuityPct >= $liveHighAcuityPct ? 'up' : 'down';

        // Dominant predicted ESI level.
        $dominantEsi = 3;
        $dominantShare = -1.0;
        foreach ($proportions as $esi => $share) {
            if ($share > $dominantShare) {
                $dominantShare = $share;
                $dominantEsi = (int) $esi;
            }
        }
        $dominantMeta = self::ESI[$dominantEsi] ?? self::ESI[3];

        // Deterministic model-confidence proxy: scaled by calibration sample
        // size (more history => tighter forecast), capped at a sober 95%.
        $modelConfidence = $historyTotal > 0
            ? (int) max(55, min(95, 55 + (int) round(40 * min(1.0, $historyTotal / 200))))
            : 0;

        return [
            'predictedArrivals' => $predictedArrivals,
            'predictedHighAcuity' => $predictedHighAcuity,
            'predictedHighAcuityPct' => $predictedHighAcuityPct,
            'highAcuityTrend' => $highAcuityTrend,
            'baselineHighAcuityPct' => $baselineHighAcuityPct,
            'liveHighAcuity' => $liveHighAcuity,
            'liveHighAcuityPct' => $liveHighAcuityPct,
            'dominantEsi' => $dominantMeta['short'],
            'dominantEsiPct' => round(100.0 * max(0.0, $dominantShare), 1),
            'modelConfidence' => $modelConfidence,
            'sampleSize' => $historyTotal,
            'horizonHours' => self::FORECAST_HOURS,
        ];
    }
}
