<?php

namespace App\Services\Home;

use App\Models\Home\HomeEpisode;
use App\Models\Home\HomeTransition;
use App\Models\Home\RpmEnrollment;
use App\Models\Transport\TransportRequest;
use App\Services\Transport\RegionalTransferService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * Inbound activation checklists and outbound governed handoffs
 * (ACUM-PRD-HAH-001 §7). Nothing here is rebuilt: outbound handoffs write
 * prod.transport_requests (request_type = care_transition) and, for facility
 * destinations, ride RegionalTransferService's candidate scoring +
 * opportunity-cost ledger (regional.transfer_decisions) — the same machinery
 * an acute transfer uses.
 *
 * discharge() closes the census-spine loop (encounter discharged, slot freed,
 * kit returned) and — for routine discharges — enrolls the patient into the
 * 30-day post-discharge monitoring cohort at a step-down cadence (billable
 * under the 2026 RPM codes).
 */
class HomeTransitionService
{
    public const INBOUND_CHECKLIST = ['consent', 'home_safety_check', 'kit_delivery', 'first_visit'];

    public const OUTBOUND_CHECKLIST = ['discharge_readiness', 'med_reconciliation', 'handoff_report', 'equipment_return'];

    public function __construct(private readonly RegionalTransferService $regional) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $transitions = HomeTransition::query()
            ->with('episode:home_episode_id,patient_ref,condition_label,status')
            ->where('is_deleted', false)
            ->whereIn('status', ['pending', 'in_progress', 'blocked'])
            ->orderBy('direction')
            ->orderBy('home_transition_id')
            ->get()
            ->map(fn (HomeTransition $t): array => $this->present($t));

        return [
            'inbound' => $transitions->where('direction', 'inbound')->values(),
            'outbound' => $transitions->where('direction', 'outbound')->values(),
            'postDischargeCohort' => $this->postDischargeCohort(),
            // Acute episodes eligible for an outbound handoff / discharge.
            'activeEpisodes' => HomeEpisode::query()
                ->active()
                ->whereHas('program', fn ($q) => $q->where('program_type', 'ahcah_acute'))
                ->orderBy('patient_ref')
                ->get(['home_episode_id', 'episode_uuid', 'patient_ref', 'condition_label'])
                ->map(fn (HomeEpisode $e): array => [
                    'episodeUuid' => $e->episode_uuid,
                    'patientRef' => $e->patient_ref,
                    'conditionLabel' => $e->condition_label,
                ])->values()->all(),
        ];
    }

    public function ensureInbound(HomeEpisode $episode): HomeTransition
    {
        $existing = HomeTransition::query()
            ->where('home_episode_id', $episode->home_episode_id)
            ->where('direction', 'inbound')
            ->where('is_deleted', false)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return HomeTransition::create([
            'transition_uuid' => Uuid::uuid4()->toString(),
            'home_episode_id' => $episode->home_episode_id,
            'patient_ref' => $episode->patient_ref,
            'direction' => 'inbound',
            'status' => 'in_progress',
            'checklist' => array_fill_keys(self::INBOUND_CHECKLIST, 'pending'),
            'started_at' => now(),
        ]);
    }

    public function completeChecklistItem(HomeTransition $transition, string $item): HomeTransition
    {
        $checklist = (array) $transition->checklist;

        if (! array_key_exists($item, $checklist)) {
            throw ValidationException::withMessages(['item' => "Unknown checklist item [{$item}]."]);
        }

        $checklist[$item] = 'complete';
        $allComplete = collect($checklist)->every(fn ($state): bool => $state === 'complete');

        $transition->update([
            'checklist' => $checklist,
            'status' => $allComplete ? 'completed' : 'in_progress',
            'completed_at' => $allComplete ? now() : null,
        ]);

        return $transition->fresh();
    }

    /**
     * Open the outbound handoff: transition row + care_transition transport
     * request; facility destinations (SNF) also get a regional transfer
     * decision with candidate scoring + opportunity cost.
     *
     * @return array{transition: HomeTransition, transportRequestId: int, decisionId: int|null}
     */
    public function startOutbound(
        HomeEpisode $episode,
        string $receivingEntityType,
        ?string $handoffOwner = null,
        ?int $userId = null,
    ): array {
        $existing = HomeTransition::query()
            ->where('home_episode_id', $episode->home_episode_id)
            ->where('direction', 'outbound')
            ->whereIn('status', ['pending', 'in_progress', 'blocked'])
            ->where('is_deleted', false)
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'transition' => 'An outbound handoff is already open for this episode.',
            ]);
        }

        return DB::transaction(function () use ($episode, $receivingEntityType, $handoffOwner, $userId): array {
            $request = TransportRequest::create([
                'request_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'request_type' => 'care_transition',
                'priority' => 'routine',
                'status' => 'requested',
                'patient_ref' => $episode->patient_ref,
                'encounter_ref' => $episode->encounter_id !== null ? (string) $episode->encounter_id : null,
                'origin' => 'HOME virtual ward',
                'destination' => match ($receivingEntityType) {
                    'pcp' => 'Primary care follow-up',
                    'home_health' => 'Home health agency',
                    'snf' => 'Skilled nursing facility',
                    default => 'Community follow-up',
                },
                // CHECK-constrained vocabulary; a PCP/home-health handoff has
                // no clinical transport → 'ambulatory' (patient self-conveys).
                'transport_mode' => $receivingEntityType === 'snf' ? 'stretcher' : 'ambulatory',
                'clinical_service' => 'home_hospital',
                'requested_by' => $handoffOwner,
                'requested_at' => now(),
                'metadata' => ['home_episode_uuid' => $episode->episode_uuid],
                'created_by_user_id' => $userId,
            ]);

            $decisionId = null;
            $facilityId = null;

            if ($receivingEntityType === 'snf') {
                $decision = $this->regional->decide($request, ['decision_status' => 'draft'], $userId);
                $decisionId = (int) $decision['decisionId'];
                $facilityId = DB::table('regional.transfer_decisions')
                    ->where('transfer_decision_id', $decisionId)
                    ->value('selected_facility_id');
            }

            $transition = HomeTransition::create([
                'transition_uuid' => Uuid::uuid4()->toString(),
                'home_episode_id' => $episode->home_episode_id,
                'patient_ref' => $episode->patient_ref,
                'direction' => 'outbound',
                'status' => 'in_progress',
                'checklist' => array_fill_keys(self::OUTBOUND_CHECKLIST, 'pending'),
                'handoff_owner' => $handoffOwner,
                'receiving_entity_type' => $receivingEntityType,
                'regional_facility_id' => $facilityId,
                'transport_request_id' => $request->transport_request_id,
                'started_at' => now(),
            ]);

            return [
                'transition' => $transition,
                'transportRequestId' => (int) $request->transport_request_id,
                'decisionId' => $decisionId,
            ];
        });
    }

    /**
     * Close the episode on the census spine and (routine discharges) enroll
     * the 30-day post-discharge monitoring cohort at step-down cadence.
     */
    public function discharge(HomeEpisode $episode, string $disposition): HomeEpisode
    {
        if ($episode->status !== 'active') {
            throw ValidationException::withMessages([
                'episode' => 'Only an active episode can be discharged.',
            ]);
        }

        return DB::transaction(function () use ($episode, $disposition): HomeEpisode {
            $encounter = $episode->encounter;
            if ($encounter !== null) {
                $encounter->update(['status' => 'discharged', 'discharged_at' => now(), 'modified_by' => 'home-hospital']);
                $encounter->bed?->update(['status' => 'available', 'modified_by' => 'home-hospital']);
            }

            foreach ($episode->enrollments()->where('status', 'active')->get() as $enrollment) {
                $enrollment->update(['status' => 'ended', 'ended_at' => now()]);
                $enrollment->kit?->update(['status' => 'available']);
            }

            $episode->update([
                'status' => 'completed',
                'disposition' => $disposition,
                'ended_at' => now(),
            ]);

            if ($disposition === 'routine_discharge') {
                $this->enrollPostDischargeCohort($episode);
            }

            return $episode->fresh();
        });
    }

    /**
     * The 30-day post-discharge cohort: a lower-acuity episode on the
     * post_discharge_rpm program line — NO slot/encounter (it is not an
     * avoided bed-day), step-down monitoring cadence (BP/SpO2 twice daily,
     * weight daily), 30-day window.
     */
    private function enrollPostDischargeCohort(HomeEpisode $acute): void
    {
        $program = \App\Models\Home\HomeProgram::query()
            ->where('program_type', 'post_discharge_rpm')
            ->where('is_active', true)
            ->first();

        if ($program === null) {
            return;
        }

        $cohortEpisode = HomeEpisode::create([
            'episode_uuid' => Uuid::uuid4()->toString(),
            'home_program_id' => $program->home_program_id,
            'home_referral_id' => $acute->home_referral_id,
            'patient_ref' => $acute->patient_ref,
            'condition_code' => $acute->condition_code,
            'condition_label' => $acute->condition_label,
            'admission_source' => $acute->admission_source,
            'acuity_tier' => 5,
            'status' => 'active',
            'service_zone' => $acute->service_zone,
            'target_los_days' => 30.0,
            'expected_discharge_date' => now()->addDays(30)->toDateString(),
            'started_at' => now(),
            'metadata' => ['cohort' => 'post_discharge', 'acute_episode_uuid' => $acute->episode_uuid],
        ]);

        RpmEnrollment::create([
            'enrollment_uuid' => Uuid::uuid4()->toString(),
            'home_episode_id' => $cohortEpisode->home_episode_id,
            'rpm_kit_id' => $acute->enrollments()->latest('rpm_enrollment_id')->value('rpm_kit_id')
                ?? \App\Models\Home\RpmKit::query()->where('is_deleted', false)->orderBy('rpm_kit_id')->value('rpm_kit_id'),
            'patient_ref' => $acute->patient_ref,
            'status' => 'active',
            // Step-down cadence: BP + SpO2 q12h, weight daily.
            'monitoring_plan' => ['cadence_minutes' => ['8480-6' => 720, '8462-4' => 720, '59408-5' => 720, '29463-7' => 1440]],
            'started_at' => now(),
            'metadata' => ['cohort' => 'post_discharge'],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function postDischargeCohort(): array
    {
        return HomeEpisode::query()
            ->with('program:home_program_id,code')
            ->active()
            ->whereHas('program', fn ($q) => $q->where('program_type', 'post_discharge_rpm'))
            ->orderBy('started_at')
            ->get()
            ->map(fn (HomeEpisode $e): array => [
                'episodeUuid' => $e->episode_uuid,
                'patientRef' => $e->patient_ref,
                'conditionLabel' => $e->condition_label ?? $e->condition_code,
                'dayOfCohort' => $e->started_at !== null ? max(1, (int) $e->started_at->diffInDays(now()) + 1) : null,
                'windowDays' => 30,
                'endsOn' => $e->expected_discharge_date?->toDateString(),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function present(HomeTransition $t): array
    {
        return [
            'transitionUuid' => $t->transition_uuid,
            'patientRef' => $t->patient_ref,
            'conditionLabel' => $t->episode?->condition_label,
            'direction' => $t->direction,
            'status' => $t->status,
            'checklist' => (array) $t->checklist,
            'handoffOwner' => $t->handoff_owner,
            'receivingEntityType' => $t->receiving_entity_type,
            'barriers' => (array) $t->barriers,
            'startedAt' => $t->started_at?->toIso8601String(),
        ];
    }
}
