<?php

namespace App\Services\Home;

use App\Models\Home\HomeEpisode;
use App\Models\Home\RpmEnrollment;
use App\Models\Home\RpmObservation;
use Illuminate\Support\Collection;

/**
 * Home Early Warning Score — deterministic modified NEWS2 (strategy §6.1).
 *
 * OPERATIONAL TRIAGE, NOT DIAGNOSIS. Transparent components, no ML:
 *   1. NEWS2-style absolute banding per vital (RR, SpO2, SBP, HR, temp —
 *      consciousness and supplemental-O2 are not RPM-observable and are
 *      deliberately omitted; documented, not silently faked).
 *   2. Baseline deviation vs the enrollment's first-24h calibration
 *      (rpm_enrollments.baseline), so a chronically fast heart doesn't
 *      page every hour — and a quiet drift off a personal baseline does.
 *   3. Trend: a worsening slope over the trend window adds points.
 *   4. Monitoring adherence: silence is a signal — missing expected
 *      readings adds a point.
 *
 * Bands live in config('home_hospital.hews'); the score drives command-grid
 * sort order and visit intensity. Escalation authority stays human.
 */
class HewsService
{
    private const LOINC_RR = '9279-1';

    private const LOINC_SPO2 = '59408-5';

    private const LOINC_SBP = '8480-6';

    private const LOINC_HR = '8867-4';

    private const LOINC_TEMP = '8310-5';

    /**
     * @return array{score:int,band:string,components:array<string,int>,vitals:array<string,float|null>,computed_at:string}|null
     *         null when no scoreable observations exist in the lookback window.
     */
    public function computeForEpisode(HomeEpisode $episode): ?array
    {
        $enrollment = $episode->enrollments()
            ->where('status', 'active')
            ->orderByDesc('rpm_enrollment_id')
            ->first();

        if ($enrollment === null) {
            return null;
        }

        $lookback = now()->subHours((int) config('home_hospital.hews.lookback_hours', 12));

        $observations = RpmObservation::query()
            ->where('rpm_enrollment_id', $enrollment->rpm_enrollment_id)
            ->where('is_deleted', false)
            ->where('quality_flag', 'ok')
            ->where('observed_at', '>=', $lookback)
            ->orderBy('observed_at')
            ->get()
            ->groupBy('loinc_code');

        if ($observations->isEmpty()) {
            return null;
        }

        $latest = fn (string $loinc): ?float => optional($observations->get($loinc)?->last())->value;

        $vitals = [
            self::LOINC_RR => $latest(self::LOINC_RR),
            self::LOINC_SPO2 => $latest(self::LOINC_SPO2),
            self::LOINC_SBP => $latest(self::LOINC_SBP),
            self::LOINC_HR => $latest(self::LOINC_HR),
            self::LOINC_TEMP => $latest(self::LOINC_TEMP),
        ];

        $components = [
            'news2' => $this->news2Points($vitals),
            'baseline_deviation' => $this->baselineDeviationPoints($enrollment, $vitals),
            'trend' => $this->trendPoints($observations),
            'adherence' => $this->adherencePoints($enrollment, $observations),
        ];

        $score = (int) array_sum($components);

        return [
            'score' => $score,
            'band' => $this->band($score),
            'components' => $components,
            'vitals' => $vitals,
            'computed_at' => now()->toIso8601String(),
        ];
    }

    /** NEWS2 absolute banding (0–3 points per parameter, RPM-observable set). */
    private function news2Points(array $vitals): int
    {
        $points = 0;

        $rr = $vitals[self::LOINC_RR];
        if ($rr !== null) {
            $points += match (true) {
                $rr <= 8 => 3,
                $rr <= 11 => 1,
                $rr <= 20 => 0,
                $rr <= 24 => 2,
                default => 3,
            };
        }

        $spo2 = $vitals[self::LOINC_SPO2];
        if ($spo2 !== null) {
            $points += match (true) {
                $spo2 <= 91 => 3,
                $spo2 <= 93 => 2,
                $spo2 <= 95 => 1,
                default => 0,
            };
        }

        $sbp = $vitals[self::LOINC_SBP];
        if ($sbp !== null) {
            $points += match (true) {
                $sbp <= 90 => 3,
                $sbp <= 100 => 2,
                $sbp <= 110 => 1,
                $sbp <= 219 => 0,
                default => 3,
            };
        }

        $hr = $vitals[self::LOINC_HR];
        if ($hr !== null) {
            $points += match (true) {
                $hr <= 40 => 3,
                $hr <= 50 => 1,
                $hr <= 90 => 0,
                $hr <= 110 => 1,
                $hr <= 130 => 2,
                default => 3,
            };
        }

        $temp = $vitals[self::LOINC_TEMP];
        if ($temp !== null) {
            $points += match (true) {
                $temp <= 35.0 => 3,
                $temp <= 36.0 => 1,
                $temp <= 38.0 => 0,
                $temp <= 39.0 => 1,
                default => 2,
            };
        }

        return $points;
    }

    /**
     * +1 per vital drifted >15% off the personal baseline mean, capped at +2.
     * No baseline calibrated (first 24h still running) → contributes 0.
     */
    private function baselineDeviationPoints(RpmEnrollment $enrollment, array $vitals): int
    {
        $baseline = (array) ($enrollment->baseline['means'] ?? []);
        if ($baseline === []) {
            return 0;
        }

        $drifted = 0;
        foreach ($vitals as $loinc => $value) {
            $mean = $baseline[$loinc] ?? null;
            if ($value === null || $mean === null || (float) $mean == 0.0) {
                continue;
            }
            if (abs($value - (float) $mean) / (float) $mean > 0.15) {
                $drifted++;
            }
        }

        return min(2, $drifted);
    }

    /**
     * Worsening short-window trend: SpO2 falling ≥3 points absolute or heart
     * rate rising ≥15% across the trend window each add +1 (cap +2).
     * First-vs-last inside the window — transparent, not a regression fit.
     */
    private function trendPoints(Collection $observations): int
    {
        $windowStart = now()->subHours((int) config('home_hospital.hews.trend_window_hours', 6));
        $points = 0;

        $spo2 = $observations->get(self::LOINC_SPO2)?->filter(
            fn (RpmObservation $o): bool => $o->observed_at >= $windowStart
        )->values();
        if ($spo2 !== null && $spo2->count() >= 2 && ($spo2->first()->value - $spo2->last()->value) >= 3.0) {
            $points++;
        }

        $hr = $observations->get(self::LOINC_HR)?->filter(
            fn (RpmObservation $o): bool => $o->observed_at >= $windowStart
        )->values();
        if ($hr !== null && $hr->count() >= 2 && $hr->first()->value > 0
            && (($hr->last()->value - $hr->first()->value) / $hr->first()->value) >= 0.15) {
            $points++;
        }

        return $points;
    }

    /**
     * +1 when fewer than half the expected readings arrived in the adherence
     * window (per the enrollment's monitoring-plan cadence). Silence is a
     * signal in a home ward — a dark kit is not a stable patient.
     */
    private function adherencePoints(RpmEnrollment $enrollment, Collection $observations): int
    {
        $cadence = (array) ($enrollment->monitoring_plan['cadence_minutes'] ?? []);
        if ($cadence === []) {
            return 0;
        }

        $windowHours = (int) config('home_hospital.hews.adherence_window_hours', 6);
        $windowStart = now()->subHours($windowHours);

        $expected = 0;
        $received = 0;
        foreach ($cadence as $loinc => $minutes) {
            $minutes = (int) $minutes;
            if ($minutes <= 0) {
                continue;
            }
            $expected += (int) floor(($windowHours * 60) / $minutes);
            $received += $observations->get((string) $loinc)?->filter(
                fn (RpmObservation $o): bool => $o->observed_at >= $windowStart
            )->count() ?? 0;
        }

        return ($expected > 0 && $received < $expected / 2) ? 1 : 0;
    }

    private function band(int $score): string
    {
        $bands = (array) config('home_hospital.hews.bands', ['low' => 0, 'medium' => 5, 'high' => 7]);

        return match (true) {
            $score >= (int) ($bands['high'] ?? 7) => 'high',
            $score >= (int) ($bands['medium'] ?? 5) => 'medium',
            default => 'low',
        };
    }
}
