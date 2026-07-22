<?php

namespace App\Services\Patient\Pathway;

use App\Models\CarePathways\MilestoneDefinition;
use App\Models\CarePathways\PathwayStageDefinition;
use App\Models\CarePathways\PathwayVersion;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientPathwayInstance;
use App\Services\Patient\Pathway\Source\PatientPathwaySourceSnapshot;
use App\Services\Patient\Pathway\Source\PatientPathwaySourceStatusObservation;
use App\Services\Patient\PatientHmac;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Admission boundary for an approved encounter-pathway source connector.
 *
 * Source references live only in the connector's process memory. This service
 * can append HMAC-digested, version-pinned observations after every explicit
 * deployment/configuration gate passes; it cannot make a care-plan decision,
 * infer a cancellation from absent source data, release a projection, or
 * expose a patient/staff route. A connector must resolve and authorize the
 * matching access grant before calling this service.
 */
class PatientPathwaySourceReconciliationService
{
    private const MAX_OBSERVATIONS_PER_SNAPSHOT = 200;

    /** @var list<string> */
    private const STATUSES = ['planned', 'current', 'completed', 'delayed', 'canceled'];

    public function __construct(
        private readonly PatientHmac $hmac,
        private readonly PatientPathwayInstanceService $history,
    ) {}

    /**
     * @return array{
     *     instance: PatientPathwayInstance,
     *     assignment_replayed: bool,
     *     stage_events_appended: int,
     *     milestone_events_appended: int
     * }
     */
    public function reconcile(
        PatientEncounterAccessGrant $grant,
        PatientPathwaySourceSnapshot $snapshot,
    ): array {
        $this->assertAvailable();
        $this->assertSnapshot($snapshot);
        $this->assertApprovedSource($snapshot->sourceSystemKey);

        return DB::transaction(function () use ($grant, $snapshot): array {
            $lockedGrant = PatientEncounterAccessGrant::query()
                ->effective()
                ->lockForUpdate()
                ->find($grant->getKey());
            if (! $lockedGrant instanceof PatientEncounterAccessGrant || ! $lockedGrant->permits('pathway:read')) {
                throw new InvalidArgumentException('patient_pathway_source_grant_not_effective');
            }

            $version = PathwayVersion::query()
                ->lockForUpdate()
                ->where('pathway_version_uuid', $snapshot->pathwayVersionUuid)
                ->first();
            if (! $version instanceof PathwayVersion) {
                throw new InvalidArgumentException('patient_pathway_source_version_not_found');
            }

            // One connector event stream cannot race a duplicate handoff for
            // the same grant/version/assignment. The source reference is never
            // stored in this lock value or returned from this service.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [
                $this->hmac->digest(
                    'patient-pathway-source-reconciliation-lock',
                    implode('|', [
                        (string) $lockedGrant->grant_uuid,
                        (string) $version->pathway_version_uuid,
                        $snapshot->sourceSystemKey,
                        $snapshot->sourceAssignmentReference,
                    ]),
                ),
            ]);

            $instance = $this->history->instantiate(
                $lockedGrant,
                $version,
                $snapshot->sourceSystemKey,
                $snapshot->sourceAssignmentReference,
                $snapshot->sourceObservedAt,
            );
            $stageDefinitions = $this->definitionsByStableKey(
                PathwayStageDefinition::query()
                    ->where('pathway_version_id', $version->getKey())
                    ->where('review_state', 'approved')
                    ->lockForUpdate()
                    ->get(),
            );
            $milestoneDefinitions = $this->definitionsByStableKey(
                MilestoneDefinition::query()
                    ->where('pathway_version_id', $version->getKey())
                    ->where('review_state', 'approved')
                    ->lockForUpdate()
                    ->get(),
            );

            $stageEventsAppended = 0;
            foreach ($this->ordered($snapshot->stageObservations) as $observation) {
                $definition = $stageDefinitions->get($observation->definitionStableKey);
                if (! $definition instanceof PathwayStageDefinition) {
                    throw new InvalidArgumentException('patient_pathway_source_stage_definition_not_approved');
                }

                $event = $this->history->recordStageStatus(
                    $instance,
                    $definition,
                    $observation->status,
                    $observation->sourceEventReference,
                    $observation->sourceObservedAt,
                );
                $stageEventsAppended += $event->wasRecentlyCreated ? 1 : 0;
            }

            $milestoneEventsAppended = 0;
            foreach ($this->ordered($snapshot->milestoneObservations) as $observation) {
                $definition = $milestoneDefinitions->get($observation->definitionStableKey);
                if (! $definition instanceof MilestoneDefinition) {
                    throw new InvalidArgumentException('patient_pathway_source_milestone_definition_not_approved');
                }

                $event = $this->history->recordMilestoneStatus(
                    $instance,
                    $definition,
                    $observation->status,
                    $observation->sourceEventReference,
                    $observation->sourceObservedAt,
                );
                $milestoneEventsAppended += $event->wasRecentlyCreated ? 1 : 0;
            }

            return [
                'instance' => $instance,
                'assignment_replayed' => ! $instance->wasRecentlyCreated,
                'stage_events_appended' => $stageEventsAppended,
                'milestone_events_appended' => $milestoneEventsAppended,
            ];
        }, 3);
    }

    private function assertAvailable(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || ! (bool) config('hummingbird-patient.enabled')
            || ! (bool) config('hummingbird-patient.features.pathway')
            || ! (bool) config('hummingbird-patient.features.pathway_source_reconciliation')
            || ! (bool) config('care-pathways.patient_enabled')
            || ! (bool) config('care-pathways.assignment_enabled')) {
            throw new RuntimeException('patient_pathway_source_reconciliation_unavailable');
        }

        $this->hmac->assertAvailable();
    }

    private function assertApprovedSource(string $sourceSystemKey): void
    {
        if (! in_array(
            $sourceSystemKey,
            (array) config('hummingbird-patient.pathway_source_reconciliation.approved_sources', []),
            true,
        )) {
            throw new InvalidArgumentException('patient_pathway_source_not_approved');
        }
    }

    private function assertSnapshot(PatientPathwaySourceSnapshot $snapshot): void
    {
        if (preg_match('/^[a-z][a-z0-9._-]{1,119}$/', $snapshot->sourceSystemKey) !== 1) {
            throw new InvalidArgumentException('patient_pathway_source_system_invalid');
        }
        if (! Str::isUuid($snapshot->pathwayVersionUuid)) {
            throw new InvalidArgumentException('patient_pathway_source_version_identifier_invalid');
        }
        $this->assertOpaqueReference($snapshot->sourceAssignmentReference);

        $observationGroups = [
            'stage' => $snapshot->stageObservations,
            'milestone' => $snapshot->milestoneObservations,
        ];
        if (count($snapshot->stageObservations) + count($snapshot->milestoneObservations) > self::MAX_OBSERVATIONS_PER_SNAPSHOT) {
            throw new InvalidArgumentException('patient_pathway_source_observation_limit_exceeded');
        }

        $seen = [];
        foreach ($observationGroups as $kind => $observations) {
            foreach ($observations as $observation) {
                if (! $observation instanceof PatientPathwaySourceStatusObservation) {
                    throw new InvalidArgumentException('patient_pathway_source_observation_invalid');
                }
                if (preg_match('/^[a-z][a-z0-9_]{1,118}[a-z0-9]$/', $observation->definitionStableKey) !== 1
                    || ! in_array($observation->status, self::STATUSES, true)) {
                    throw new InvalidArgumentException('patient_pathway_source_observation_invalid');
                }
                $this->assertOpaqueReference($observation->sourceEventReference);

                $key = $kind."\x1f".$observation->definitionStableKey."\x1f".$observation->sourceEventReference;
                if (isset($seen[$key])) {
                    throw new InvalidArgumentException('patient_pathway_source_observation_duplicate');
                }
                $seen[$key] = true;
            }
        }
    }

    private function assertOpaqueReference(string $reference): void
    {
        if (trim($reference) === '' || mb_strlen($reference) > 1024) {
            throw new InvalidArgumentException('patient_pathway_source_reference_invalid');
        }
    }

    /** @template T of PathwayStageDefinition|MilestoneDefinition
     * @param  Collection<int, T>  $definitions
     * @return Collection<string, T>
     */
    private function definitionsByStableKey(Collection $definitions): Collection
    {
        return $definitions->keyBy(fn (PathwayStageDefinition|MilestoneDefinition $definition): string => (string) $definition->stable_key);
    }

    /**
     * @param  list<PatientPathwaySourceStatusObservation>  $observations
     * @return list<PatientPathwaySourceStatusObservation>
     */
    private function ordered(array $observations): array
    {
        usort($observations, function (PatientPathwaySourceStatusObservation $left, PatientPathwaySourceStatusObservation $right): int {
            return [
                $left->sourceObservedAt->format(DATE_ATOM),
                $left->definitionStableKey,
                $left->sourceEventReference,
            ] <=> [
                $right->sourceObservedAt->format(DATE_ATOM),
                $right->definitionStableKey,
                $right->sourceEventReference,
            ];
        });

        return $observations;
    }
}
