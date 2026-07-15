<?php

namespace App\Services\Radiology\BreachRisk;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Deterministically converts an operational imaging-order observation into the
 * frozen feature vector, using ONLY data available at the prediction instant.
 *
 * The extractor never receives an outcome field, a stop milestone, or a breach
 * row. The single input beyond the order's static attributes is the live
 * prediction context (now, same-modality queue depth, scanner-down state and
 * their freshness), so by construction the vector cannot see whether the order
 * eventually breaches — this is what the leakage test asserts.
 */
final class BreachRiskFeatureExtractor
{
    /** Minutes after which a gated operational signal is considered stale. */
    private const STALE_AFTER_MINUTES = 20;

    /** Scale constants keep raw counts/minutes in a well-conditioned range. */
    private const QUEUE_SCALE = 12.0;

    private const STAGE_AGE_SCALE = 120.0;

    /**
     * @param  BreachRiskObservation  $observation  static, available-at-prediction order attributes
     * @return array{features: array<string, float>, availability: string, missing: list<string>}
     */
    public function extract(BreachRiskObservation $observation, CarbonInterface $now): array
    {
        $at = CarbonImmutable::instance($now);
        $orderedAt = CarbonImmutable::instance($observation->orderedAt);
        $stageStartedAt = $observation->stageStartedAt !== null
            ? CarbonImmutable::instance($observation->stageStartedAt)
            : $orderedAt;

        $hour = (int) $at->format('G');
        $dow = (int) $at->format('N'); // 1 (Mon) .. 7 (Sun)
        $isWeekend = $dow >= 6 ? 1.0 : 0.0;
        $isOffHours = ($hour < 7 || $hour >= 19 || $dow >= 6) ? 1.0 : 0.0;

        $modality = strtoupper((string) ($observation->modality ?? ''));
        $priority = strtolower((string) $observation->priority);
        $patientClass = strtolower((string) $observation->patientClass);

        $queueDepth = max(0, $observation->queueDepth ?? 0);
        $queueNorm = min(1.0, $queueDepth / self::QUEUE_SCALE);
        $stageAge = max(0.0, $stageStartedAt->diffInMinutes($at, false));
        $stageAgeNorm = min(1.0, $stageAge / self::STAGE_AGE_SCALE);
        $scannerDown = ($observation->scannerDown ?? false) ? 1.0 : 0.0;

        $features = [
            'hour_sin' => sin(2 * M_PI * $hour / 24),
            'hour_cos' => cos(2 * M_PI * $hour / 24),
            'is_weekend' => $isWeekend,
            'is_off_hours' => $isOffHours,
            'modality_ct' => $modality === 'CT' ? 1.0 : 0.0,
            'modality_mri' => $modality === 'MRI' ? 1.0 : 0.0,
            'modality_us' => $modality === 'US' ? 1.0 : 0.0,
            'modality_other' => in_array($modality, ['CT', 'MRI', 'US'], true) ? 0.0 : 1.0,
            'priority_stat' => $priority === 'stat' ? 1.0 : 0.0,
            'priority_urgent' => $priority === 'urgent' ? 1.0 : 0.0,
            'patient_emergency' => $patientClass === 'emergency' ? 1.0 : 0.0,
            'patient_inpatient' => $patientClass === 'inpatient' ? 1.0 : 0.0,
            'queue_depth_norm' => $queueNorm,
            'stage_age_norm' => $stageAgeNorm,
            'scanner_down' => $scannerDown,
            'staffing_pressure' => $isOffHours * $queueNorm,
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
    private function availability(BreachRiskObservation $observation, CarbonImmutable $at): string
    {
        $missing = $this->missingSignals($observation, $at);
        if (in_array('queue_depth', $missing, true) || in_array('scanner_state', $missing, true)) {
            return 'unavailable';
        }
        if ($missing !== []) {
            return 'low_confidence';
        }

        return 'available';
    }

    /** @return list<string> */
    private function missingSignals(BreachRiskObservation $observation, CarbonImmutable $at): array
    {
        $missing = [];
        if ($observation->queueDepth === null) {
            $missing[] = 'queue_depth';
        }
        if ($observation->scannerDown === null) {
            $missing[] = 'scanner_state';
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
}
