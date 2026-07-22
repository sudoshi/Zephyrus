<?php

namespace App\Services\Patient\Pathway;

use App\Models\CarePathways\MilestoneDefinition;
use App\Models\CarePathways\PathwayStageDefinition;
use App\Models\CarePathways\PathwayVersion;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientPathwayInstance;
use App\Models\Patient\PatientPathwayMilestoneInstance;
use App\Models\Patient\PatientPathwayMilestoneStatusEvent;
use App\Models\Patient\PatientPathwayStageInstance;
use App\Models\Patient\PatientPathwayStageStatusEvent;
use App\Services\Patient\PatientHmac;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Internal-only pathway-history writer for approved source adapters.
 *
 * This service intentionally has no HTTP controller, no patient identity input,
 * and no clinical care-plan/order/consent mutation. It persists only exact,
 * version-pinned pathway assignment and observed state facts; release to a
 * patient remains the responsibility of the projection-governance layer.
 */
class PatientPathwayInstanceService
{
    /** @var list<string> */
    private const STATUSES = ['planned', 'current', 'completed', 'delayed', 'canceled'];

    public function __construct(private readonly PatientHmac $hmac) {}

    public function instantiate(
        PatientEncounterAccessGrant $grant,
        PathwayVersion $pathwayVersion,
        string $sourceSystemKey,
        string $sourceAssignmentReference,
        DateTimeInterface $sourceObservedAt,
    ): PatientPathwayInstance {
        $this->assertSourceSystemKey($sourceSystemKey);
        $this->assertOpaqueReference($sourceAssignmentReference);

        return DB::transaction(function () use ($grant, $pathwayVersion, $sourceSystemKey, $sourceAssignmentReference, $sourceObservedAt): PatientPathwayInstance {
            $lockedGrant = PatientEncounterAccessGrant::query()
                ->scopeEffective()
                ->lockForUpdate()
                ->find($grant->getKey());
            if (! $lockedGrant instanceof PatientEncounterAccessGrant || ! $lockedGrant->permits('pathway:read')) {
                throw new InvalidArgumentException('patient_pathway_instance_grant_not_effective');
            }

            $lockedVersion = PathwayVersion::query()
                ->with('release')
                ->lockForUpdate()
                ->find($pathwayVersion->getKey());
            if (! $lockedVersion instanceof PathwayVersion
                || $lockedVersion->activation_status !== 'active'
                || $lockedVersion->institutional_approval_status !== 'approved'
                || $lockedVersion->release?->state !== 'active'
                || ! (bool) $lockedVersion->release?->clinical_signoff_complete) {
                throw new InvalidArgumentException('patient_pathway_version_not_assignable');
            }

            $assignmentDigest = $this->hmac->digest(
                'patient-pathway.assignment',
                implode('|', [
                    (string) $lockedGrant->grant_uuid,
                    (string) $lockedVersion->pathway_version_uuid,
                    $sourceSystemKey,
                    $sourceAssignmentReference,
                ]),
            );

            return PatientPathwayInstance::query()->firstOrCreate(
                [
                    'access_grant_id' => $lockedGrant->getKey(),
                    'pathway_version_id' => $lockedVersion->getKey(),
                    'source_assignment_digest' => $assignmentDigest,
                ],
                [
                    'source_system_key' => $sourceSystemKey,
                    'source_observed_at' => $sourceObservedAt,
                    'instantiated_at' => now(),
                ],
            );
        }, 3);
    }

    public function recordStageStatus(
        PatientPathwayInstance $instance,
        PathwayStageDefinition $definition,
        string $status,
        string $sourceEventReference,
        DateTimeInterface $sourceObservedAt,
    ): PatientPathwayStageStatusEvent {
        $this->assertStatus($status);
        $this->assertOpaqueReference($sourceEventReference);

        return DB::transaction(function () use ($instance, $definition, $status, $sourceEventReference, $sourceObservedAt): PatientPathwayStageStatusEvent {
            $lockedInstance = $this->lockedInstance($instance);
            $lockedDefinition = PathwayStageDefinition::query()
                ->lockForUpdate()
                ->find($definition->getKey());

            if (! $lockedDefinition instanceof PathwayStageDefinition
                || (int) $lockedDefinition->pathway_version_id !== (int) $lockedInstance->pathway_version_id
                || $lockedDefinition->review_state !== 'approved') {
                throw new InvalidArgumentException('patient_pathway_stage_definition_not_usable');
            }

            $stage = PatientPathwayStageInstance::query()->firstOrCreate(
                [
                    'pathway_instance_id' => $lockedInstance->getKey(),
                    'stage_definition_id' => $lockedDefinition->getKey(),
                ],
                ['instantiated_at' => now()],
            );
            $eventDigest = $this->hmac->digest(
                'patient-pathway.stage-event',
                implode('|', [
                    (string) $lockedInstance->pathway_instance_uuid,
                    (string) $lockedDefinition->stage_uuid,
                    $sourceEventReference,
                ]),
            );

            return PatientPathwayStageStatusEvent::query()->firstOrCreate(
                [
                    'pathway_stage_instance_id' => $stage->getKey(),
                    'source_event_digest' => $eventDigest,
                ],
                [
                    'status' => $status,
                    'source_observed_at' => $sourceObservedAt,
                ],
            );
        }, 3);
    }

    public function recordMilestoneStatus(
        PatientPathwayInstance $instance,
        MilestoneDefinition $definition,
        string $status,
        string $sourceEventReference,
        DateTimeInterface $sourceObservedAt,
    ): PatientPathwayMilestoneStatusEvent {
        $this->assertStatus($status);
        $this->assertOpaqueReference($sourceEventReference);

        return DB::transaction(function () use ($instance, $definition, $status, $sourceEventReference, $sourceObservedAt): PatientPathwayMilestoneStatusEvent {
            $lockedInstance = $this->lockedInstance($instance);
            $lockedDefinition = MilestoneDefinition::query()
                ->lockForUpdate()
                ->find($definition->getKey());

            if (! $lockedDefinition instanceof MilestoneDefinition
                || (int) $lockedDefinition->pathway_version_id !== (int) $lockedInstance->pathway_version_id
                || $lockedDefinition->review_state !== 'approved') {
                throw new InvalidArgumentException('patient_pathway_milestone_definition_not_usable');
            }

            $milestone = PatientPathwayMilestoneInstance::query()->firstOrCreate(
                [
                    'pathway_instance_id' => $lockedInstance->getKey(),
                    'milestone_definition_id' => $lockedDefinition->getKey(),
                ],
                ['instantiated_at' => now()],
            );
            $eventDigest = $this->hmac->digest(
                'patient-pathway.milestone-event',
                implode('|', [
                    (string) $lockedInstance->pathway_instance_uuid,
                    (string) $lockedDefinition->milestone_uuid,
                    $sourceEventReference,
                ]),
            );

            return PatientPathwayMilestoneStatusEvent::query()->firstOrCreate(
                [
                    'pathway_milestone_instance_id' => $milestone->getKey(),
                    'source_event_digest' => $eventDigest,
                ],
                [
                    'status' => $status,
                    'source_observed_at' => $sourceObservedAt,
                ],
            );
        }, 3);
    }

    private function lockedInstance(PatientPathwayInstance $instance): PatientPathwayInstance
    {
        $locked = PatientPathwayInstance::query()->lockForUpdate()->find($instance->getKey());
        if (! $locked instanceof PatientPathwayInstance) {
            throw new InvalidArgumentException('patient_pathway_instance_not_found');
        }

        return $locked;
    }

    private function assertStatus(string $status): void
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('patient_pathway_status_invalid');
        }
    }

    private function assertSourceSystemKey(string $sourceSystemKey): void
    {
        if (preg_match('/^[a-z][a-z0-9._-]{1,119}$/', $sourceSystemKey) !== 1) {
            throw new InvalidArgumentException('patient_pathway_source_system_invalid');
        }
    }

    private function assertOpaqueReference(string $reference): void
    {
        if (trim($reference) === '' || mb_strlen($reference) > 1024) {
            throw new InvalidArgumentException('patient_pathway_source_reference_invalid');
        }
    }
}
