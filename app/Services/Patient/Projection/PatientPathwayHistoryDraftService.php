<?php

namespace App\Services\Patient\Projection;

use App\Models\CarePathways\PathwayVersion;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientEncounterProjection;
use App\Models\Patient\PatientPathwayInstance;
use App\Models\Patient\PatientProjectionCursor;
use App\Models\Patient\PatientProjectionFailure;
use App\Models\Patient\PatientReleasePolicyVersion;
use App\Services\Patient\PatientHmac;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Builds a patient-language *draft* from source-adapter-written pathway
 * history. This internal service never creates a clinical assignment, writes
 * back to a source system, or releases a patient projection. A separate
 * approved source adapter and release workflow remain mandatory.
 */
class PatientPathwayHistoryDraftService
{
    private const PROJECTION_KIND = 'pathway';

    public function __construct(private readonly PatientHmac $hmac) {}

    /**
     * @return array{projection: PatientEncounterProjection, replayed: bool}
     */
    public function draft(PatientPathwayInstance $instance): array
    {
        $this->assertAvailable();

        return DB::transaction(function () use ($instance): array {
            $lockedInstance = PatientPathwayInstance::query()
                ->lockForUpdate()
                ->find($instance->getKey());
            if (! $lockedInstance instanceof PatientPathwayInstance) {
                throw new InvalidArgumentException('patient_pathway_instance_not_found');
            }

            $grant = PatientEncounterAccessGrant::query()
                ->scopeEffective()
                ->lockForUpdate()
                ->find($lockedInstance->access_grant_id);
            if (! $grant instanceof PatientEncounterAccessGrant || ! $grant->permits('pathway:read')) {
                throw new InvalidArgumentException('patient_pathway_draft_grant_not_effective');
            }

            $version = PathwayVersion::query()
                ->with('release')
                ->lockForUpdate()
                ->find($lockedInstance->pathway_version_id);
            if (! $version instanceof PathwayVersion
                || $version->activation_status !== 'active'
                || $version->institutional_approval_status !== 'approved'
                || $version->release?->state !== 'active'
                || ! (bool) $version->release?->clinical_signoff_complete) {
                throw new InvalidArgumentException('patient_pathway_draft_version_not_approved');
            }

            $policy = PatientReleasePolicyVersion::query()
                ->effective()
                ->where('version', (string) config('hummingbird-patient.policy_version'))
                ->lockForUpdate()
                ->first();
            if (! $policy instanceof PatientReleasePolicyVersion) {
                throw new RuntimeException('patient_pathway_draft_release_policy_unavailable');
            }

            $stages = $this->stageRows($lockedInstance);
            $milestones = $this->milestoneRows($lockedInstance);
            if ($stages === [] && $milestones === []) {
                throw new InvalidArgumentException('patient_pathway_draft_no_approved_observations');
            }

            $sourceObservedAt = $this->latestObservation($lockedInstance, $stages, $milestones);
            $content = $this->content($stages, $milestones);
            $sourceVersion = (string) config(
                'hummingbird-patient.pathway_history_drafts.producer_version',
                'patient-pathway-history-draft-v1',
            );
            $guard = app(PatientProjectionContentGuard::class);
            $contentDigest = $guard->digest(self::PROJECTION_KIND, 'patient-pathway.v1', $content);

            // Serialize one instance's projection sequence and exact replay
            // recovery without retaining source assignment/event identifiers.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [
                $this->hmac->digest(
                    'patient-pathway-draft-lock',
                    (string) $lockedInstance->pathway_instance_uuid,
                ),
            ]);

            $existing = PatientEncounterProjection::query()
                ->where('access_grant_id', $grant->getKey())
                ->where('projection_kind', self::PROJECTION_KIND)
                ->where('release_state', 'draft')
                ->where('source_version', $sourceVersion)
                ->where('content_digest', $contentDigest)
                ->where('source_observed_at', $sourceObservedAt)
                ->lockForUpdate()
                ->first();
            if ($existing instanceof PatientEncounterProjection) {
                return ['projection' => $existing, 'replayed' => true];
            }

            $cursor = PatientProjectionCursor::query()->create([
                'source_system_key' => (string) config(
                    'hummingbird-patient.pathway_history_drafts.source_system_key',
                    'care-pathways.pathway-history-v1',
                ),
                'projection_kind' => self::PROJECTION_KIND,
                'cursor_digest' => $this->cursorDigest(
                    $lockedInstance,
                    $version,
                    $stages,
                    $milestones,
                    $sourceObservedAt,
                ),
                'source_version' => $sourceVersion,
                'status' => 'projected',
                'source_observed_at' => $sourceObservedAt,
                'projected_at' => now(),
                'metadata' => ['schema_version' => 1, 'draft_only' => true],
            ]);
            $previous = PatientEncounterProjection::query()
                ->where('access_grant_id', $grant->getKey())
                ->where('projection_kind', self::PROJECTION_KIND)
                ->orderByDesc('projection_sequence')
                ->lockForUpdate()
                ->first();
            $projection = PatientEncounterProjection::query()->create([
                'access_grant_id' => $grant->getKey(),
                'release_policy_version_id' => $policy->getKey(),
                'projection_cursor_id' => $cursor->getKey(),
                'projection_kind' => self::PROJECTION_KIND,
                'projection_sequence' => ((int) $previous?->projection_sequence) + 1,
                'content' => $content,
                'content_schema_version' => 'patient-pathway.v1',
                'content_digest' => $contentDigest,
                'source_version' => $sourceVersion,
                'provenance' => [
                    'projection_method' => 'version_pinned_pathway_history_draft',
                    'source_class' => 'approved_pathway_catalog_and_append_only_observations',
                    'input_classes' => ['approved_pathway_definition', 'append_only_pathway_status'],
                    'review_state' => 'draft_pending_patient_release',
                    'producer_version' => $sourceVersion,
                    'trace_digest' => $this->hmac->digest(
                        'patient-pathway-draft-trace',
                        (string) $lockedInstance->pathway_instance_uuid.'|'.$contentDigest,
                    ),
                ],
                'source_observed_at' => $sourceObservedAt,
                'generated_at' => now(),
                'freshness_class' => $this->freshness($sourceObservedAt),
                'uncertainty' => [
                    'level' => 'medium',
                    'explanation' => 'Care plans and timing can change as your care needs change.',
                    'can_change' => true,
                    'reviewed_at' => $sourceObservedAt->toISOString(),
                ],
                'required_scope' => PatientProjectionDisclosureService::REQUIRED_SCOPES[self::PROJECTION_KIND],
                'permitted_relationships' => ['self'],
                'release_state' => 'draft',
            ]);

            return ['projection' => $projection, 'replayed' => false];
        }, 3);
    }

    /** @return array{selected: int} */
    public function previewPending(int $limit): array
    {
        $this->assertAvailable();
        $this->assertLimit($limit);

        return ['selected' => $this->pendingInstances($limit)->count()];
    }

    /** @return array{selected: int, drafted: int, replayed: int, failed: int} */
    public function draftPending(int $limit): array
    {
        $this->assertAvailable();
        $this->assertLimit($limit);
        $result = ['selected' => 0, 'drafted' => 0, 'replayed' => 0, 'failed' => 0];

        foreach ($this->pendingInstances($limit) as $instance) {
            $result['selected']++;
            try {
                $draft = $this->draft($instance);
                $result[$draft['replayed'] ? 'replayed' : 'drafted']++;
            } catch (Throwable $exception) {
                // This worker cannot disclose source identifiers or exception
                // messages in its result. The immutable draft remains absent;
                // a governed monitor can inspect aggregate failure counts.
                $this->recordFailure($instance, $exception);
                $result['failed']++;
            }
        }

        return $result;
    }

    private function assertAvailable(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || ! (bool) config('hummingbird-patient.enabled')
            || ! (bool) config('hummingbird-patient.features.pathway')
            || ! (bool) config('hummingbird-patient.features.pathway_history_drafts')
            || ! (bool) config('care-pathways.patient_enabled')) {
            throw new RuntimeException('patient_pathway_history_drafts_unavailable');
        }

        $this->hmac->assertAvailable();
    }

    private function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('patient_pathway_draft_limit_invalid');
        }
    }

    private function recordFailure(PatientPathwayInstance $instance, Throwable $exception): void
    {
        try {
            $failureCode = $exception instanceof InvalidArgumentException
                ? 'pathway_history_draft_input_rejected'
                : 'pathway_history_draft_processing_failed';
            $instanceDigest = $this->hmac->digest(
                'patient-pathway-draft-failure-instance',
                (string) $instance->pathway_instance_uuid,
            );
            if (PatientProjectionFailure::query()
                ->where('source_system_key', (string) config(
                    'hummingbird-patient.pathway_history_drafts.source_system_key',
                    'care-pathways.pathway-history-v1',
                ))
                ->where('projection_kind', self::PROJECTION_KIND)
                ->where('failure_code', $failureCode)
                ->whereRaw("context->>'instance_digest' = ?", [$instanceDigest])
                ->exists()) {
                return;
            }

            PatientProjectionFailure::query()->create([
                'source_system_key' => (string) config(
                    'hummingbird-patient.pathway_history_drafts.source_system_key',
                    'care-pathways.pathway-history-v1',
                ),
                'projection_kind' => self::PROJECTION_KIND,
                'failure_code' => $failureCode,
                'retryability' => $exception instanceof InvalidArgumentException
                    ? 'manual_review'
                    : 'retryable',
                'attempt_number' => 1,
                'source_observed_at' => $instance->source_observed_at,
                'occurred_at' => now(),
                'context' => [
                    'schema_version' => 1,
                    'content_included' => false,
                    'instance_digest' => $instanceDigest,
                ],
            ]);
        } catch (Throwable) {
            // The primary worker result must still remain non-disclosing and
            // bounded if even the independent failure ledger is unavailable.
        }
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, PatientPathwayInstance> */
    private function pendingInstances(int $limit): \Illuminate\Database\Eloquent\Collection
    {
        return PatientPathwayInstance::query()
            ->orderBy('pathway_instance_id')
            ->limit($limit)
            ->get();
    }

    /** @return list<stdClass> */
    private function stageRows(PatientPathwayInstance $instance): array
    {
        return DB::table('patient_experience.pathway_stage_instances as instances')
            ->join(
                'care_pathways.stage_definitions as definitions',
                'definitions.stage_definition_id',
                '=',
                'instances.stage_definition_id',
            )
            ->join(
                'patient_experience.current_pathway_stage_statuses as statuses',
                'statuses.pathway_stage_instance_id',
                '=',
                'instances.pathway_stage_instance_id',
            )
            ->where('instances.pathway_instance_id', $instance->getKey())
            ->where('definitions.review_state', 'approved')
            ->orderBy('definitions.display_order')
            ->select([
                'definitions.stage_uuid',
                'definitions.approved_label',
                'definitions.approved_explanation',
                'definitions.display_order',
                'statuses.status',
                'statuses.source_observed_at',
            ])
            ->get()
            ->all();
    }

    /** @return list<stdClass> */
    private function milestoneRows(PatientPathwayInstance $instance): array
    {
        return DB::table('patient_experience.pathway_milestone_instances as instances')
            ->join(
                'care_pathways.milestone_definitions as definitions',
                'definitions.milestone_definition_id',
                '=',
                'instances.milestone_definition_id',
            )
            ->join(
                'patient_experience.current_pathway_milestone_statuses as statuses',
                'statuses.pathway_milestone_instance_id',
                '=',
                'instances.pathway_milestone_instance_id',
            )
            ->where('instances.pathway_instance_id', $instance->getKey())
            ->where('definitions.review_state', 'approved')
            ->orderBy('definitions.sequence')
            ->orderBy('definitions.milestone_definition_id')
            ->select([
                'definitions.milestone_uuid',
                'definitions.title',
                'statuses.status',
                'statuses.source_observed_at',
            ])
            ->get()
            ->all();
    }

    /**
     * @param  list<stdClass>  $stages
     * @param  list<stdClass>  $milestones
     * @return array<string, mixed>
     */
    private function content(array $stages, array $milestones): array
    {
        $stageContent = array_map(function (stdClass $stage): array {
            $summary = trim((string) $stage->approved_explanation);

            return [
                'stage_uuid' => (string) $stage->stage_uuid,
                'title' => (string) $stage->approved_label,
                'status' => (string) $stage->status,
                'summary' => $summary === ''
                    ? 'Your care team is monitoring this stage of your hospital care.'
                    : $summary,
                'can_change' => in_array((string) $stage->status, ['planned', 'current', 'delayed'], true),
            ];
        }, $stages);
        $milestoneContent = array_map(fn (stdClass $milestone): array => [
            'milestone_uuid' => (string) $milestone->milestone_uuid,
            'title' => (string) $milestone->title,
            'status' => (string) $milestone->status,
            'can_change' => in_array((string) $milestone->status, ['planned', 'current', 'delayed'], true),
        ], $milestones);

        $content = [
            'headline' => 'My Path',
            'summary' => 'This draft summarizes confirmed stages of your hospital care. Care plans and timing can change as your care needs change.',
            'stages' => $stageContent,
            'milestones' => $milestoneContent,
            'notices' => ['This draft must be reviewed and released before it is shown to you.'],
        ];
        $currentStage = collect($stageContent)
            ->firstWhere('status', 'current')['title']
            ?? collect($stageContent)->firstWhere('status', 'delayed')['title']
            ?? collect($stageContent)->firstWhere('status', 'planned')['title']
            ?? null;
        if (is_string($currentStage)) {
            $content['current_stage'] = $currentStage;
        }

        return $content;
    }

    /** @param list<stdClass> $stages @param list<stdClass> $milestones */
    private function latestObservation(
        PatientPathwayInstance $instance,
        array $stages,
        array $milestones,
    ): Carbon {
        $timestamps = [$instance->source_observed_at];
        foreach (array_merge($stages, $milestones) as $row) {
            $timestamps[] = $row->source_observed_at;
        }

        return collect($timestamps)
            ->filter()
            ->map(fn (mixed $value): Carbon => $value instanceof Carbon ? $value : Carbon::parse((string) $value))
            ->sort()
            ->last();
    }

    /** @param list<stdClass> $stages @param list<stdClass> $milestones */
    private function cursorDigest(
        PatientPathwayInstance $instance,
        PathwayVersion $version,
        array $stages,
        array $milestones,
        Carbon $sourceObservedAt,
    ): string {
        return $this->hmac->digest('patient-pathway-draft-cursor', json_encode([
            'instance' => (string) $instance->pathway_instance_uuid,
            'version' => (string) $version->pathway_version_uuid,
            'source_observed_at' => $sourceObservedAt->toISOString(),
            'stages' => array_map(fn (stdClass $row): array => [
                'uuid' => (string) $row->stage_uuid,
                'status' => (string) $row->status,
                'observed_at' => (string) $row->source_observed_at,
            ], $stages),
            'milestones' => array_map(fn (stdClass $row): array => [
                'uuid' => (string) $row->milestone_uuid,
                'status' => (string) $row->status,
                'observed_at' => (string) $row->source_observed_at,
            ], $milestones),
        ], JSON_THROW_ON_ERROR));
    }

    private function freshness(Carbon $sourceObservedAt): string
    {
        $minutes = max(0, $sourceObservedAt->diffInMinutes(now()));
        if ($minutes <= (int) config('hummingbird-patient.pathway_history_drafts.current_after_minutes', 30)) {
            return 'current';
        }

        return $minutes <= (int) config('hummingbird-patient.pathway_history_drafts.stale_after_minutes', 240)
            ? 'aging'
            : 'stale';
    }
}
