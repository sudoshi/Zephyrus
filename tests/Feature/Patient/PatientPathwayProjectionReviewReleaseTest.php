<?php

namespace Tests\Feature\Patient;

use App\Models\CarePathways\MilestoneDefinition;
use App\Models\CarePathways\PathwayStageDefinition;
use App\Models\CarePathways\PathwayVersion;
use App\Models\Patient\PatientEncounterProjection;
use App\Models\Patient\PatientPathwayProjectionReleaseExecution;
use App\Models\Patient\PatientPathwayProjectionReview;
use App\Models\User;
use App\Services\CarePathways\CatalogImportService;
use App\Services\Patient\Pathway\PatientPathwayInstanceService;
use App\Services\Patient\Projection\PatientPathwayHistoryDraftService;
use App\Services\Patient\Projection\PatientPathwayProjectionReviewReleaseService;
use App\Services\Patient\Projection\PatientProjectionDisclosureService;
use App\Services\Patient\Projection\SyntheticPatientProjectionProvisioner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\CarePathwayRawFixture;
use Tests\TestCase;

class PatientPathwayProjectionReviewReleaseTest extends TestCase
{
    use CarePathwayRawFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureCarePathwayFixture();
        $this->seedCarePathwayRawFixture();
        $this->activateFixtureRelease();
    }

    public function test_independent_clinical_approval_and_catalog_release_create_one_patient_visible_projection_and_outbox_fact(): void
    {
        [$fixture, $draft] = $this->draft('pathway-review-release');
        $clinicalReviewer = User::factory()->create(['role' => 'care_pathway_clinical_approver']);
        $releaseManager = User::factory()->create(['role' => 'care_pathway_release_manager']);
        $service = app(PatientPathwayProjectionReviewReleaseService::class);

        $approval = $service->approve($clinicalReviewer, $draft);
        $approvalReplay = $service->approve($clinicalReviewer, $draft);
        $release = $service->release($releaseManager, $draft);
        $releaseReplay = $service->release($releaseManager, $draft);

        $this->assertFalse($approval['replayed']);
        $this->assertTrue($approvalReplay['replayed']);
        $this->assertFalse($release['replayed']);
        $this->assertTrue($releaseReplay['replayed']);
        $this->assertSame($approval['review']->getKey(), $release['execution']->pathway_projection_review_id);
        $this->assertSame($release['release']->getKey(), $releaseReplay['release']->getKey());
        $this->assertSame('draft', $draft->fresh()->release_state);
        $this->assertSame('released', $release['release']->release_state);
        $this->assertNotNull($release['release']->released_at);
        $this->assertEquals($draft->content, $release['release']->content);
        $this->assertSame($draft->content_digest, $release['release']->content_digest);
        $this->assertSame(
            'version_pinned_pathway_history_clinical_release',
            $release['release']->provenance['projection_method'],
        );
        $this->assertArrayNotHasKey('reviewer_actor_digest', $release['release']->provenance);
        $this->assertArrayNotHasKey('source_assignment_digest', $release['release']->content);
        $this->assertSame(1, PatientPathwayProjectionReview::query()->count());
        $this->assertSame(1, PatientPathwayProjectionReleaseExecution::query()->count());
        $this->assertDatabaseHas('patient_experience.notification_outbox', [
            'destination' => 'projection',
            'event_type' => 'patient.projection.released',
            'aggregate_uuid' => (string) $release['release']->projection_uuid,
        ]);
        $this->assertSame(1, PatientEncounterProjection::query()
            ->where('source_version', 'patient-pathway-history-clinical-release-v1')
            ->where('release_state', 'released')
            ->count());
        $disclosure = app(PatientProjectionDisclosureService::class)->disclose(
            Request::create('/api/patient/v1/encounters/'.$fixture['grant']->encounter_uuid.'/pathway', 'GET'),
            $fixture['principal'],
            (string) $fixture['grant']->encounter_uuid,
            'pathway',
        );
        $this->assertSame((string) $release['release']->projection_uuid, $disclosure['data']['projection_uuid'] ?? null);
        $this->assertSame(
            'version_pinned_pathway_history_clinical_release',
            $disclosure['data']['provenance']->projection_method ?? null,
        );
        unset($fixture);
    }

    public function test_withheld_review_cannot_be_released_and_no_patient_publication_is_created(): void
    {
        [, $draft] = $this->draft('pathway-review-withheld');
        $clinicalReviewer = User::factory()->create(['role' => 'care_pathway_clinical_approver']);
        $releaseManager = User::factory()->create(['role' => 'care_pathway_release_manager']);
        $service = app(PatientPathwayProjectionReviewReleaseService::class);

        $withheld = $service->withhold($clinicalReviewer, $draft);
        $this->assertSame('withheld', $withheld['review']->decision);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('patient_pathway_release_clinical_approval_required');
        try {
            $service->release($releaseManager, $draft);
        } finally {
            $this->assertSame(0, PatientPathwayProjectionReleaseExecution::query()->count());
            $this->assertSame(0, PatientEncounterProjection::query()
                ->where('source_version', 'patient-pathway-history-clinical-release-v1')
                ->count());
        }
    }

    public function test_release_rejects_missing_capability_and_same_person_execution(): void
    {
        [, $draft] = $this->draft('pathway-review-authorization');
        $instanceManager = User::factory()->create(['role' => 'care_pathway_instance_manager']);
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $service = app(PatientPathwayProjectionReviewReleaseService::class);

        try {
            $service->approve($instanceManager, $draft);
            $this->fail('An instance manager unexpectedly approved a patient pathway release.');
        } catch (AuthorizationException) {
            $this->assertSame(0, PatientPathwayProjectionReview::query()->count());
        }

        $service->approve($superAdmin, $draft);
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Independent clinical approval and pathway release are required.');
        $service->release($superAdmin, $draft);
    }

    public function test_database_defers_but_requires_a_matching_release_execution_for_the_clinical_release_producer(): void
    {
        [, $draft] = $this->draft('pathway-review-database-guard');
        $releasedAt = now();

        try {
            DB::transaction(function () use ($draft, $releasedAt): void {
                PatientEncounterProjection::query()->create([
                    'access_grant_id' => $draft->access_grant_id,
                    'release_policy_version_id' => $draft->release_policy_version_id,
                    'projection_kind' => 'pathway',
                    'projection_sequence' => ((int) $draft->projection_sequence) + 1,
                    'content' => $draft->content,
                    'content_schema_version' => $draft->content_schema_version,
                    'content_digest' => $draft->content_digest,
                    'source_version' => 'patient-pathway-history-clinical-release-v1',
                    'provenance' => [
                        'projection_method' => 'version_pinned_pathway_history_clinical_release',
                        'source_class' => 'approved_pathway_catalog_and_append_only_observations',
                        'input_classes' => ['approved_pathway_definition', 'append_only_pathway_status'],
                        'review_state' => 'released_after_independent_clinical_and_catalog_review',
                        'producer_version' => 'patient-pathway-history-clinical-release-v1',
                    ],
                    'source_observed_at' => $draft->source_observed_at,
                    'generated_at' => $releasedAt,
                    'released_at' => $releasedAt,
                    'freshness_class' => $draft->freshness_class,
                    'uncertainty' => $draft->uncertainty,
                    'required_scope' => 'pathway:read',
                    'permitted_relationships' => $draft->permitted_relationships,
                    'release_state' => 'released',
                ]);

                // RefreshDatabase holds an outer test transaction. Force this
                // deferred constraint while the nested transaction/savepoint
                // is still active so its failure proves the invariant without
                // aborting the fixture transaction.
                DB::statement('SET CONSTRAINTS patient_experience.patient_pathway_release_execution_required IMMEDIATE');
            });
            $this->fail('A clinical pathway release without an execution unexpectedly committed.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('requires a release execution', $exception->getMessage());
        }

        $this->assertSame(0, PatientEncounterProjection::query()
            ->where('source_version', 'patient-pathway-history-clinical-release-v1')
            ->count());
    }

    public function test_review_release_feature_gate_fails_closed_before_any_decision_is_written(): void
    {
        $fixture = app(SyntheticPatientProjectionProvisioner::class)->provision('pathway-review-gate');
        $reviewer = User::factory()->create(['role' => 'care_pathway_clinical_approver']);
        $draft = PatientEncounterProjection::query()->create([
            'access_grant_id' => $fixture['grant']->getKey(),
            'release_policy_version_id' => $fixture['policy']->getKey(),
            'projection_kind' => 'pathway',
            'projection_sequence' => 2,
            'content' => $fixture['projections']['pathway']->content,
            'content_schema_version' => 'patient-pathway.v1',
            'source_version' => 'patient-pathway-history-draft-v1',
            'provenance' => [
                'projection_method' => 'version_pinned_pathway_history_draft',
                'source_class' => 'approved_pathway_catalog_and_append_only_observations',
                'input_classes' => ['approved_pathway_definition', 'append_only_pathway_status'],
                'review_state' => 'draft_pending_patient_release',
                'producer_version' => 'patient-pathway-history-draft-v1',
            ],
            'source_observed_at' => now()->subMinute(),
            'generated_at' => now(),
            'freshness_class' => 'current',
            'uncertainty' => $fixture['projections']['pathway']->uncertainty,
            'required_scope' => 'pathway:read',
            'permitted_relationships' => ['self'],
            'release_state' => 'draft',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('patient_pathway_history_releases_unavailable');
        try {
            app(PatientPathwayProjectionReviewReleaseService::class)->approve($reviewer, $draft);
        } finally {
            $this->assertSame(0, PatientPathwayProjectionReview::query()->count());
        }
    }

    /** @return array{0: array<string, mixed>, 1: PatientEncounterProjection} */
    private function draft(string $seed): array
    {
        $fixture = app(SyntheticPatientProjectionProvisioner::class)->provision($seed);
        config([
            'hummingbird-patient.enabled' => true,
            'hummingbird-patient.features.pathway' => true,
            'hummingbird-patient.features.pathway_history_drafts' => true,
            'hummingbird-patient.features.pathway_history_releases' => true,
            'hummingbird-patient.policy_version' => (string) $fixture['policy']->version,
            'care-pathways.patient_enabled' => true,
            'care-pathways.assignment_enabled' => true,
        ]);
        $version = PathwayVersion::query()->orderBy('source_rank')->firstOrFail();
        $stage = PathwayStageDefinition::query()
            ->where('pathway_version_id', $version->getKey())
            ->orderBy('display_order')
            ->firstOrFail();
        $milestone = MilestoneDefinition::query()
            ->where('pathway_version_id', $version->getKey())
            ->orderBy('sequence')
            ->firstOrFail();
        $history = app(PatientPathwayInstanceService::class);
        $instance = $history->instantiate(
            $fixture['grant'],
            $version,
            'test-pathway-review-adapter.v1',
            'opaque-pathway-review-assignment',
            now()->subMinutes(15),
        );
        $history->recordStageStatus(
            $instance,
            $stage,
            'current',
            'opaque-pathway-review-stage-event',
            now()->subMinutes(10),
        );
        $history->recordMilestoneStatus(
            $instance,
            $milestone,
            'planned',
            'opaque-pathway-review-milestone-event',
            now()->subMinutes(5),
        );
        $draft = app(PatientPathwayHistoryDraftService::class)->draft($instance)['projection'];

        return [$fixture, $draft];
    }

    private function activateFixtureRelease(): void
    {
        $summary = app(CatalogImportService::class)->adopt(1, 'test-data-steward');
        $this->seedApprovedPatientPathwayDefinitions();
        DB::table('care_pathways.definitions')->update([
            'lifecycle_state' => 'active',
            'updated_at' => now(),
        ]);
        DB::table('care_pathways.versions')->update([
            'institutional_approval_status' => 'approved',
            'activation_status' => 'active',
            'updated_at' => now(),
        ]);
        DB::table('care_pathways.milestone_definitions')->update([
            'review_state' => 'approved',
            'updated_at' => now(),
        ]);
        DB::table('care_pathways.catalog_releases')
            ->where('catalog_release_id', $summary['catalog_release_id'])
            ->update([
                'state' => 'active',
                'clinical_signoff_complete' => true,
                'clinical_signoff_count' => 2,
                'activated_by_user_id' => 999,
                'activated_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
