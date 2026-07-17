<?php

namespace App\Services\Home;

use App\Models\Home\HomeEpisode;
use App\Models\Home\HomeEscalation;
use App\Models\Home\RpmAlert;
use Ramsey\Uuid\Uuid;

/**
 * Escalation lifecycle with the full response timing chain
 * (initiated → dispatched → arrived → resolved) — the telemetry behind the
 * 30-minute waiver floor (ACUM-PRD-HAH-001 §4.2, §8). response_minutes is
 * stamped at arrival (initiated→arrived), the number the p90 tile reads.
 *
 * closeForEdReturn() is the ADT loop-closer (§5.2): when an escalated home
 * patient is admitted/registered via the HL7 pipeline, the open escalation
 * resolves with an ed_return outcome — no manual bookkeeping.
 */
class HomeEscalationService
{
    public function open(
        HomeEpisode $episode,
        string $triggerType,
        ?RpmAlert $alert = null,
        ?string $responseMode = null,
    ): HomeEscalation {
        // One open escalation per episode: a second trigger while responding
        // joins the existing chain rather than forking a parallel clock.
        $existing = HomeEscalation::query()
            ->where('home_episode_id', $episode->home_episode_id)
            ->whereIn('status', ['open', 'responding'])
            ->where('is_deleted', false)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return HomeEscalation::create([
            'escalation_uuid' => Uuid::uuid4()->toString(),
            'home_episode_id' => $episode->home_episode_id,
            'rpm_alert_id' => $alert?->rpm_alert_id,
            'patient_ref' => $episode->patient_ref,
            'trigger_type' => $triggerType,
            'response_mode' => $responseMode,
            'status' => 'open',
            'initiated_at' => now(),
            'metadata' => $alert === null ? [] : ['rule_key' => $alert->rule_key, 'severity' => $alert->severity],
        ]);
    }

    public function markDispatched(HomeEscalation $escalation, string $responseMode): HomeEscalation
    {
        $escalation->update([
            'status' => 'responding',
            'response_mode' => $responseMode,
            'dispatched_at' => $escalation->dispatched_at ?? now(),
        ]);

        return $escalation->fresh();
    }

    public function markArrived(HomeEscalation $escalation): HomeEscalation
    {
        $arrivedAt = $escalation->arrived_at ?? now();

        $escalation->update([
            'status' => 'responding',
            'arrived_at' => $arrivedAt,
            'response_minutes' => $escalation->response_minutes
                ?? max(0, (int) round($escalation->initiated_at->diffInMinutes($arrivedAt))),
        ]);

        return $escalation->fresh();
    }

    public function resolve(HomeEscalation $escalation, string $outcome): HomeEscalation
    {
        $escalation->update([
            'status' => 'resolved',
            'resolved_at' => $escalation->resolved_at ?? now(),
            'outcome' => $outcome,
        ]);

        return $escalation->fresh();
    }

    /**
     * ADT close loop: resolve the open escalation for a patient who just
     * arrived at the hospital. Returns the resolved escalation, or null when
     * the patient has no open escalation (the overwhelmingly common case).
     */
    public function closeForEdReturn(string $patientRef): ?HomeEscalation
    {
        $open = HomeEscalation::query()
            ->where('patient_ref', $patientRef)
            ->whereIn('status', ['open', 'responding'])
            ->where('is_deleted', false)
            ->orderByDesc('initiated_at')
            ->first();

        if ($open === null) {
            return null;
        }

        $open->update([
            'status' => 'resolved',
            'response_mode' => $open->response_mode ?? 'ed_return',
            'resolved_at' => now(),
            'outcome' => 'ed_return',
            'metadata' => array_merge((array) $open->metadata, ['closed_by' => 'adt_ed_registration']),
        ]);

        return $open->fresh();
    }
}
