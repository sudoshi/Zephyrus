<?php

namespace App\Services\Home;

use App\Models\Home\RpmAlert;
use App\Models\Home\RpmEnrollment;
use App\Models\Home\RpmObservation;
use Ramsey\Uuid\Uuid;

/**
 * Evaluates a projected observation against the patient's personalized
 * thresholds (rpm_enrollments.monitoring_plan.thresholds, falling back to
 * config('home_hospital.vital_thresholds')) and opens/refreshes a patient
 * alert (ACUM-PRD-HAH-001 §6.1, alarm governance §8).
 *
 * Alarm-fatigue guardrails: one OPEN alert per (episode, rule) — repeated
 * breaches refresh the open alert's metadata instead of stacking rows; the
 * severity may escalate warning→critical in place but never silently
 * downgrades. Alert burden per patient-day is itself a tracked KPI.
 */
class RpmAlertEvaluator
{
    /** Returns the open/updated alert when the observation breaches, else null. */
    public function evaluate(RpmObservation $observation, RpmEnrollment $enrollment): ?RpmAlert
    {
        $breach = $this->classify($observation, $enrollment);

        if ($breach === null) {
            return null;
        }

        [$severity, $bound] = $breach;

        if (! $observation->is_breach) {
            $observation->update(['is_breach' => true]);
        }

        $ruleKey = "{$observation->loinc_code}.{$bound}";

        $open = RpmAlert::query()
            ->where('home_episode_id', $enrollment->home_episode_id)
            ->where('rule_key', $ruleKey)
            ->where('status', 'open')
            ->where('is_deleted', false)
            ->first();

        if ($open !== null) {
            $open->update([
                // Escalate in place, never downgrade an open alert.
                'severity' => $severity === 'critical' ? 'critical' : $open->severity,
                'rpm_observation_id' => $observation->rpm_observation_id,
                'metadata' => array_merge((array) $open->metadata, [
                    'last_value' => $observation->value,
                    'last_observed_at' => $observation->observed_at?->toIso8601String(),
                    'breach_count' => (int) ($open->metadata['breach_count'] ?? 1) + 1,
                ]),
            ]);

            return $open->fresh();
        }

        return RpmAlert::create([
            'alert_uuid' => Uuid::uuid4()->toString(),
            'home_episode_id' => $enrollment->home_episode_id,
            'rpm_enrollment_id' => $enrollment->rpm_enrollment_id,
            'rpm_observation_id' => $observation->rpm_observation_id,
            'patient_ref' => $observation->patient_ref,
            'rule_key' => $ruleKey,
            'severity' => $severity,
            'status' => 'open',
            'opened_at' => now(),
            'metadata' => [
                'loinc_code' => $observation->loinc_code,
                'display' => $observation->display,
                'value' => $observation->value,
                'unit' => $observation->unit,
                'threshold_source' => $this->hasPersonalThresholds($enrollment, $observation->loinc_code)
                    ? 'personalized'
                    : 'default',
                'breach_count' => 1,
            ],
        ]);
    }

    /** @return array{0:'warning'|'critical',1:string}|null [severity, low|high] */
    private function classify(RpmObservation $observation, RpmEnrollment $enrollment): ?array
    {
        $thresholds = $this->thresholdsFor($enrollment, $observation->loinc_code);
        if ($thresholds === []) {
            return null;
        }

        $value = (float) $observation->value;

        if (isset($thresholds['critical_low']) && $value <= (float) $thresholds['critical_low']) {
            return ['critical', 'low'];
        }
        if (isset($thresholds['critical_high']) && $value >= (float) $thresholds['critical_high']) {
            return ['critical', 'high'];
        }
        if (isset($thresholds['warning_low']) && $value <= (float) $thresholds['warning_low']) {
            return ['warning', 'low'];
        }
        if (isset($thresholds['warning_high']) && $value >= (float) $thresholds['warning_high']) {
            return ['warning', 'high'];
        }

        return null;
    }

    /** @return array<string, float|int> */
    private function thresholdsFor(RpmEnrollment $enrollment, string $loinc): array
    {
        $personal = (array) ($enrollment->monitoring_plan['thresholds'][$loinc] ?? []);
        $defaults = (array) config("home_hospital.vital_thresholds.{$loinc}", []);

        return array_merge($defaults, $personal);
    }

    private function hasPersonalThresholds(RpmEnrollment $enrollment, string $loinc): bool
    {
        return (array) ($enrollment->monitoring_plan['thresholds'][$loinc] ?? []) !== [];
    }
}
