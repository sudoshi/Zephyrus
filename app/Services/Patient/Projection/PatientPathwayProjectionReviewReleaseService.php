<?php

namespace App\Services\Patient\Projection;

use App\Authorization\Capability;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientEncounterProjection;
use App\Models\Patient\PatientPathwayProjectionReleaseExecution;
use App\Models\Patient\PatientPathwayProjectionReview;
use App\Models\Patient\PatientReleasePolicyVersion;
use App\Models\User;
use App\Services\Authorization\RoleCapabilityService;
use App\Services\Patient\PatientHmac;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Internal, two-person release boundary for a version-pinned pathway draft.
 *
 * A clinical approver records a content-free decision first. A different
 * catalog release manager then creates a new patient-visible projection and
 * its immutable execution fact in the same transaction. There is no HTTP
 * route, worker shortcut, clinical order/care-plan mutation, or source
 * writeback path here.
 */
class PatientPathwayProjectionReviewReleaseService
{
    private const DRAFT_PROJECTION_METHOD = 'version_pinned_pathway_history_draft';

    private const RELEASE_PROJECTION_METHOD = 'version_pinned_pathway_history_clinical_release';

    public function __construct(
        private readonly PatientHmac $hmac,
        private readonly RoleCapabilityService $capabilities,
    ) {}

    /** @return array{review: PatientPathwayProjectionReview, replayed: bool} */
    public function approve(User $clinicalReviewer, PatientEncounterProjection $draft): array
    {
        return $this->recordReview($clinicalReviewer, $draft, 'approved');
    }

    /** @return array{review: PatientPathwayProjectionReview, replayed: bool} */
    public function withhold(User $clinicalReviewer, PatientEncounterProjection $draft): array
    {
        return $this->recordReview($clinicalReviewer, $draft, 'withheld');
    }

    /**
     * @return array{
     *     release: PatientEncounterProjection,
     *     execution: PatientPathwayProjectionReleaseExecution,
     *     replayed: bool
     * }
     */
    public function release(User $releaseManager, PatientEncounterProjection $draft): array
    {
        $this->assertAvailable();

        return DB::transaction(function () use ($releaseManager, $draft): array {
            $manager = $this->lockedUser($releaseManager);
            $this->assertReleaseManager($manager);
            [$lockedDraft, $grant, $policy] = $this->lockedDraftContext($draft);
            $review = PatientPathwayProjectionReview::query()
                ->where('draft_projection_id', $lockedDraft->getKey())
                ->lockForUpdate()
                ->first();
            if (! $review instanceof PatientPathwayProjectionReview || $review->decision !== 'approved') {
                throw new InvalidArgumentException('patient_pathway_release_clinical_approval_required');
            }
            $managerActorDigest = $this->actorDigest($manager);
            if (hash_equals((string) $review->reviewer_actor_digest, $managerActorDigest)) {
                throw new AuthorizationException('Independent clinical approval and pathway release are required.');
            }

            $existing = PatientPathwayProjectionReleaseExecution::query()
                ->with('releasedProjection')
                ->where('pathway_projection_review_id', $review->getKey())
                ->lockForUpdate()
                ->first();
            if ($existing instanceof PatientPathwayProjectionReleaseExecution) {
                if (! hash_equals((string) $existing->release_manager_actor_digest, $managerActorDigest)
                    || ! ($existing->releasedProjection instanceof PatientEncounterProjection)) {
                    throw new InvalidArgumentException('patient_pathway_release_already_executed');
                }

                return [
                    'release' => $existing->releasedProjection,
                    'execution' => $existing,
                    'replayed' => true,
                ];
            }

            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [
                $this->hmac->digest(
                    'patient-pathway-release-lock',
                    (string) $lockedDraft->projection_uuid.'|'.(string) $review->review_uuid,
                ),
            ]);
            $latest = PatientEncounterProjection::query()
                ->where('access_grant_id', $grant->getKey())
                ->where('projection_kind', 'pathway')
                ->orderByDesc('projection_sequence')
                ->lockForUpdate()
                ->first();
            $releasedAt = now();
            $producerVersion = (string) config(
                'hummingbird-patient.pathway_history_releases.producer_version',
                'patient-pathway-history-clinical-release-v1',
            );
            $release = PatientEncounterProjection::query()->create([
                'access_grant_id' => $grant->getKey(),
                'release_policy_version_id' => $policy->getKey(),
                'projection_kind' => 'pathway',
                'projection_sequence' => ((int) $latest?->projection_sequence) + 1,
                'content' => (array) $lockedDraft->content,
                'content_schema_version' => (string) $lockedDraft->content_schema_version,
                'content_digest' => (string) $lockedDraft->content_digest,
                'source_version' => $producerVersion,
                'provenance' => [
                    'projection_method' => self::RELEASE_PROJECTION_METHOD,
                    'source_class' => 'approved_pathway_catalog_and_append_only_observations',
                    'input_classes' => ['approved_pathway_definition', 'append_only_pathway_status'],
                    'review_state' => 'released_after_independent_clinical_and_catalog_review',
                    'producer_version' => $producerVersion,
                    'trace_digest' => $this->hmac->digest(
                        'patient-pathway-release-trace',
                        (string) $lockedDraft->projection_uuid.'|'.(string) $review->review_uuid,
                    ),
                ],
                'source_observed_at' => $lockedDraft->source_observed_at,
                'generated_at' => $releasedAt,
                'released_at' => $releasedAt,
                'freshness_class' => $lockedDraft->freshness_class,
                'uncertainty' => (array) $lockedDraft->uncertainty,
                'required_scope' => 'pathway:read',
                'permitted_relationships' => array_values((array) $lockedDraft->permitted_relationships),
                'release_state' => 'released',
            ]);
            $execution = PatientPathwayProjectionReleaseExecution::query()->create([
                'pathway_projection_review_id' => $review->getKey(),
                'released_projection_id' => $release->getKey(),
                'release_manager_actor_digest' => $managerActorDigest,
                'release_digest' => $this->hmac->digest(
                    'patient-pathway-release-execution',
                    implode('|', [
                        (string) $review->review_uuid,
                        (string) $release->projection_uuid,
                        $managerActorDigest,
                    ]),
                ),
                'released_at' => $releasedAt,
            ]);

            return ['release' => $release, 'execution' => $execution, 'replayed' => false];
        }, 3);
    }

    /** @return array{review: PatientPathwayProjectionReview, replayed: bool} */
    private function recordReview(
        User $clinicalReviewer,
        PatientEncounterProjection $draft,
        string $decision,
    ): array {
        $this->assertAvailable();

        return DB::transaction(function () use ($clinicalReviewer, $draft, $decision): array {
            $reviewer = $this->lockedUser($clinicalReviewer);
            $this->assertClinicalReviewer($reviewer);
            [$lockedDraft, , $policy] = $this->lockedDraftContext($draft);
            $existing = PatientPathwayProjectionReview::query()
                ->where('draft_projection_id', $lockedDraft->getKey())
                ->lockForUpdate()
                ->first();
            $reviewerActorDigest = $this->actorDigest($reviewer);
            if ($existing instanceof PatientPathwayProjectionReview) {
                if ($existing->decision !== $decision
                    || ! hash_equals((string) $existing->reviewer_actor_digest, $reviewerActorDigest)) {
                    throw new InvalidArgumentException('patient_pathway_review_already_decided');
                }

                return ['review' => $existing, 'replayed' => true];
            }

            $reviewedAt = now();
            $review = PatientPathwayProjectionReview::query()->create([
                'draft_projection_id' => $lockedDraft->getKey(),
                'release_policy_version_id' => $policy->getKey(),
                'reviewer_actor_digest' => $reviewerActorDigest,
                'decision' => $decision,
                'reason_code' => $decision === 'approved'
                    ? 'clinical_pathway_review_approved'
                    : 'clinical_pathway_review_withheld',
                'review_digest' => $this->hmac->digest(
                    'patient-pathway-review',
                    implode('|', [
                        (string) $lockedDraft->projection_uuid,
                        (string) $policy->version,
                        $reviewerActorDigest,
                        $decision,
                    ]),
                ),
                'reviewed_at' => $reviewedAt,
            ]);

            return ['review' => $review, 'replayed' => false];
        }, 3);
    }

    /** @return array{0: PatientEncounterProjection, 1: PatientEncounterAccessGrant, 2: PatientReleasePolicyVersion} */
    private function lockedDraftContext(PatientEncounterProjection $draft): array
    {
        $lockedDraft = PatientEncounterProjection::query()
            ->lockForUpdate()
            ->find($draft->getKey());
        if (! $lockedDraft instanceof PatientEncounterProjection
            || $lockedDraft->projection_kind !== 'pathway'
            || $lockedDraft->release_state !== 'draft'
            || $lockedDraft->released_at !== null
            || $lockedDraft->source_version !== (string) config(
                'hummingbird-patient.pathway_history_drafts.producer_version',
                'patient-pathway-history-draft-v1',
            )
            || (string) ($lockedDraft->provenance['projection_method'] ?? '') !== self::DRAFT_PROJECTION_METHOD
            || ! in_array($lockedDraft->freshness_class, ['current', 'aging'], true)) {
            throw new InvalidArgumentException('patient_pathway_release_draft_not_eligible');
        }

        $grant = PatientEncounterAccessGrant::query()
            ->effective()
            ->lockForUpdate()
            ->find($lockedDraft->access_grant_id);
        if (! $grant instanceof PatientEncounterAccessGrant || ! $grant->permits('pathway:read')) {
            throw new InvalidArgumentException('patient_pathway_release_grant_not_effective');
        }
        $policy = PatientReleasePolicyVersion::query()
            ->effective()
            ->where('version', (string) config('hummingbird-patient.policy_version'))
            ->lockForUpdate()
            ->first();
        if (! $policy instanceof PatientReleasePolicyVersion
            || (int) $lockedDraft->release_policy_version_id !== (int) $policy->getKey()) {
            throw new InvalidArgumentException('patient_pathway_release_policy_not_effective');
        }

        return [$lockedDraft, $grant, $policy];
    }

    private function lockedUser(User $actor): User
    {
        $locked = User::query()->lockForUpdate()->find($actor->getKey());
        if (! $locked instanceof User || ! $locked->is_active) {
            throw new AuthorizationException('The pathway review actor is not authorized.');
        }

        return $locked;
    }

    private function assertClinicalReviewer(User $reviewer): void
    {
        if (! $this->capabilities->allows($reviewer, Capability::ApproveCarePathwayClinical)) {
            throw new AuthorizationException('Clinical pathway approval is required.');
        }
    }

    private function assertReleaseManager(User $manager): void
    {
        if (! $this->capabilities->allows($manager, Capability::ActivateCarePathwayCatalog)) {
            throw new AuthorizationException('Care-pathway catalog release authority is required.');
        }
    }

    private function assertAvailable(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || ! (bool) config('hummingbird-patient.enabled')
            || ! (bool) config('hummingbird-patient.features.pathway')
            || ! (bool) config('hummingbird-patient.features.pathway_history_releases')
            || ! (bool) config('care-pathways.patient_enabled')
            || ! (bool) config('care-pathways.assignment_enabled')) {
            throw new RuntimeException('patient_pathway_history_releases_unavailable');
        }

        $this->hmac->assertAvailable();
    }

    /**
     * The patient schema records a non-reversible reviewer attestation, never
     * a foreign key or raw staff identifier. Authorization is evaluated
     * against the freshly locked staff account before this digest is written.
     */
    private function actorDigest(User $actor): string
    {
        return $this->hmac->digest('patient-pathway-release-actor', (string) $actor->getKey());
    }
}
