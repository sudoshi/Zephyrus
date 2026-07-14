<?php

namespace App\Services\Lab\AmReadiness;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Deterministically converts an operational decision-class Laboratory-order
 * observation into the frozen feature vector, using ONLY data available at the
 * prediction instant.
 *
 * The extractor never receives a verification time, a result value, or a
 * "verified before cutoff" flag. The inputs beyond the order's current
 * processing stage are the live prediction context (now, same-family queue
 * depth, analyzer downtime, their freshness) and the configured rounds cutoff,
 * so by construction the vector cannot see whether the order eventually verifies
 * in time — this is what the leakage test asserts.
 */
final class AmReadinessFeatureExtractor
{
    /** Minutes after which a gated operational signal is considered stale. */
    private const STALE_AFTER_MINUTES = 20;

    /** Scale constants keep raw counts/minutes in a well-conditioned range. */
    private const QUEUE_SCALE = 10.0;

    private const CUTOFF_MINUTES_SCALE = 180.0;

    /**
     * Milestone codes mapped to the coarse processing stage used as a feature.
     * A verified order is deliberately absent — it has left the cohort.
     */
    private const STAGE_BY_MILESTONE = [
        'LAB_ORDERED' => 'ordered',
        'LAB_COLLECTED' => 'collected',
        'LAB_IN_TRANSIT' => 'collected',
        'LAB_RECEIVED' => 'received',
        'LAB_PROCESSING' => 'received',
        'LAB_RESULTED' => 'resulted',
        'LAB_PRELIM' => 'resulted',
    ];

    /**
     * @param  AmReadinessObservation  $observation  static, available-at-prediction order attributes
     * @return array{features: array<string, float>, availability: string, missing: list<string>}
     */
    public function extract(AmReadinessObservation $observation, CarbonInterface $now): array
    {
        $at = CarbonImmutable::instance($now);
        $stage = self::STAGE_BY_MILESTONE[strtoupper($observation->stage)] ?? $this->normalizeStage($observation->stage);

        $hour = (int) $at->format('G');
        $dow = (int) $at->format('N'); // 1 (Mon) .. 7 (Sun)
        $isOffHours = ($hour < 7 || $hour >= 19 || $dow >= 6) ? 1.0 : 0.0;

        $family = $this->normalizeFamily($observation->testFamily);
        $patientClass = strtolower((string) $observation->patientClass);

        $queueDepth = max(0, $observation->queueDepth ?? 0);
        $queueNorm = min(1.0, $queueDepth / self::QUEUE_SCALE);

        $cutoffAt = CarbonImmutable::instance($observation->roundsCutoffAt);
        $minutesToCutoff = $cutoffAt->diffInMinutes($at, false) * -1; // positive when cutoff is in the future
        $pastCutoff = $minutesToCutoff < 0 ? 1.0 : 0.0;
        $minutesToCutoffNorm = min(1.0, max(0.0, $minutesToCutoff) / self::CUTOFF_MINUTES_SCALE);

        $analyzerDowntime = ($observation->analyzerDowntime ?? false) ? 1.0 : 0.0;

        $features = [
            'stage_ordered' => $stage === 'ordered' ? 1.0 : 0.0,
            'stage_collected' => $stage === 'collected' ? 1.0 : 0.0,
            'stage_received' => $stage === 'received' ? 1.0 : 0.0,
            'stage_resulted' => $stage === 'resulted' ? 1.0 : 0.0,
            'family_chemistry' => $family === 'chemistry' ? 1.0 : 0.0,
            'family_hematology' => $family === 'hematology' ? 1.0 : 0.0,
            'family_coagulation' => $family === 'coagulation' ? 1.0 : 0.0,
            'family_other' => $family === 'other' ? 1.0 : 0.0,
            'patient_emergency' => $patientClass === 'emergency' ? 1.0 : 0.0,
            'patient_inpatient' => $patientClass === 'inpatient' ? 1.0 : 0.0,
            'shift_am_draw' => $observation->isAmDraw ? 1.0 : 0.0,
            'hour_sin' => sin(2 * M_PI * $hour / 24),
            'hour_cos' => cos(2 * M_PI * $hour / 24),
            'is_off_hours' => $isOffHours,
            'queue_depth_norm' => $queueNorm,
            'analyzer_downtime' => $analyzerDowntime,
            'minutes_to_cutoff_norm' => $minutesToCutoffNorm,
            'past_cutoff' => $pastCutoff,
            'processing_pressure' => $isOffHours * $queueNorm,
        ];

        return [
            'features' => $features,
            'availability' => $this->availability($observation, $at),
            'missing' => $this->missingSignals($observation, $at),
        ];
    }

    /**
     * available   → all gated operational signals are present and fresh.
     * low_confidence → a gated signal is stale (score returned, flagged).
     * unavailable → a gated signal is missing entirely (no score).
     */
    private function availability(AmReadinessObservation $observation, CarbonImmutable $at): string
    {
        $missing = $this->missingSignals($observation, $at);
        if (in_array('queue_depth', $missing, true) || in_array('analyzer_state', $missing, true)) {
            return 'unavailable';
        }
        if ($missing !== []) {
            return 'low_confidence';
        }

        return 'available';
    }

    /** @return list<string> */
    private function missingSignals(AmReadinessObservation $observation, CarbonImmutable $at): array
    {
        $missing = [];
        if ($observation->queueDepth === null) {
            $missing[] = 'queue_depth';
        }
        if ($observation->analyzerDowntime === null) {
            $missing[] = 'analyzer_state';
        }
        if ($observation->signalCutoffAt === null) {
            $missing[] = 'signal_freshness';
        } else {
            $lag = CarbonImmutable::instance($observation->signalCutoffAt)->diffInMinutes($at, false);
            if ($lag > self::STALE_AFTER_MINUTES) {
                $missing[] = 'signal_freshness';
            }
        }

        return $missing;
    }

    private function normalizeStage(string $stage): string
    {
        $lowered = strtolower($stage);

        return match (true) {
            str_contains($lowered, 'result'), str_contains($lowered, 'prelim') => 'resulted',
            str_contains($lowered, 'receiv'), str_contains($lowered, 'process') => 'received',
            str_contains($lowered, 'collect'), str_contains($lowered, 'transit') => 'collected',
            default => 'ordered',
        };
    }

    private function normalizeFamily(string $family): string
    {
        $lowered = strtolower($family);

        return match (true) {
            str_contains($lowered, 'metabolic'), str_contains($lowered, 'chemistr'), str_contains($lowered, 'troponin') => 'chemistry',
            str_contains($lowered, 'blood_count'), str_contains($lowered, 'hematolog'), str_contains($lowered, 'cbc') => 'hematology',
            str_contains($lowered, 'coag'), str_contains($lowered, 'inr'), str_contains($lowered, 'pt_') => 'coagulation',
            default => 'other',
        };
    }
}
