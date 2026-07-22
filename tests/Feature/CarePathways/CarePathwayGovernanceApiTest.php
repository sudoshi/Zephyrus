<?php

namespace Tests\Feature\CarePathways;

use App\Models\User;
use App\Services\CarePathways\CatalogGovernanceReadService;
use App\Services\CarePathways\CatalogImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CarePathwayRawFixture;
use Tests\TestCase;

class CarePathwayGovernanceApiTest extends TestCase
{
    use CarePathwayRawFixture;
    use RefreshDatabase;

    private User $dataSteward;

    private string $releaseUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureCarePathwayFixture();
        $this->seedCarePathwayRawFixture();
        app(CatalogImportService::class)->adopt(1, 'test-data-steward');
        config([
            'care-pathways.governance_enabled' => true,
            'care-pathways.catalog_enabled' => false,
            'care-pathways.assignment_enabled' => false,
            'care-pathways.rounds_enabled' => false,
            'care-pathways.staff_mobile_enabled' => false,
            'care-pathways.patient_enabled' => false,
            'care-pathways.eddy_reference_enabled' => false,
            'care-pathways.eddy_instance_enabled' => false,
            'care-pathways.writeback_enabled' => false,
        ]);

        $this->dataSteward = User::factory()->create(['role' => 'data_steward']);
        $this->releaseUuid = (string) DB::table('care_pathways.catalog_releases')->value('catalog_release_uuid');
    }

    public function test_governance_flag_authentication_and_catalog_capability_fail_closed(): void
    {
        $this->getJson('/api/care-pathways/v1/summary')->assertUnauthorized();

        $frontline = User::factory()->create(['role' => 'user']);
        $this->actingAs($frontline)
            ->getJson('/api/care-pathways/v1/summary')
            ->assertForbidden();

        config(['care-pathways.governance_enabled' => false]);
        $this->assertNull(app(CatalogGovernanceReadService::class)->summary());
        $this->actingAs($this->dataSteward)
            ->getJson('/api/care-pathways/v1/summary')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_summary_exposes_inactive_governance_state_and_no_serving_release(): void
    {
        $response = $this->actingAs($this->dataSteward)
            ->getJson('/api/care-pathways/v1/summary')
            ->assertOk()
            ->assertJsonPath('data.release.catalog_release_uuid', $this->releaseUuid)
            ->assertJsonPath('data.release.state', 'inactive')
            ->assertJsonPath('data.release.clinical_signoff_complete', false)
            ->assertJsonPath('data.catalog.definitions', 2)
            ->assertJsonPath('data.catalog.versions', 2)
            ->assertJsonPath('data.catalog.institutionally_approved_versions', 0)
            ->assertJsonPath('data.catalog.active_versions', 0)
            ->assertJsonPath('data.release_readiness.may_serve_approved_catalog', false)
            ->assertJsonPath('data.release_readiness.patient_projection_released', false)
            ->assertJsonPath('data.release_readiness.eddy_retrieval_released', false)
            ->assertJsonPath('data.authorization.view_catalog', true)
            ->assertJsonPath('data.authorization.adopt_source', true)
            ->assertJsonPath('data.authorization.author_content', false)
            ->assertJsonPath('data.authorization.approve_clinical', false)
            ->assertJsonPath('data.authorization.activate_catalog', false)
            ->assertJsonPath('meta.schema', 'care_pathway_governance.v1')
            ->assertJsonPath('meta.catalog_release_uuid', $this->releaseUuid)
            ->assertJsonPath('meta.clinical_approval_warning', CatalogGovernanceReadService::CLINICAL_APPROVAL_WARNING)
            ->assertJsonPath('meta.patient_serving', false)
            ->assertJsonPath('meta.hummingbird_serving', false)
            ->assertJsonPath('meta.eddy_serving', false);

        $this->assertSame([
            'approved_catalog' => false,
            'assignment' => false,
            'rounds' => false,
            'staff_mobile' => false,
            'patient' => false,
            'eddy_reference' => false,
            'eddy_instance' => false,
            'writeback' => false,
        ], $response->json('data.serving_flags'));
    }

    public function test_pathway_search_preserves_drg_ambiguity_and_review_queue_filters(): void
    {
        $byDrg = $this->actingAs($this->dataSteward)
            ->getJson('/api/care-pathways/v1/pathways?drg=2&per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.pagination.per_page', 1)
            ->json('data');

        $this->assertCount(1, $byDrg);
        $this->assertTrue($byDrg[0]['drg_candidates'][1]['requires_clinician_confirmation']);
        $this->assertArrayNotHasKey('raw_snapshot', $byDrg[0]['version']);

        $this->actingAs($this->dataSteward)
            ->getJson('/api/care-pathways/v1/pathways?evidence_state=verified&disposition=signoff')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pathway.name', 'Acute Example A')
            ->assertJsonPath('data.0.version.exact_version', true)
            ->assertJsonPath('data.0.evidence.release_disposition', config('care-pathways.status_labels.signoff_queue'));

        $this->actingAs($this->dataSteward)
            ->getJson('/api/care-pathways/v1/pathways?mdc=MDC%2002&service_line=Cardiology')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pathway.name', 'Acute Example B');
    }

    public function test_version_and_claim_reads_are_exact_and_omit_raw_import_payloads(): void
    {
        $version = DB::table('care_pathways.versions')->where('source_rank', 1)->first();
        $this->insertAuthoringDefinitions((int) $version->pathway_version_id);

        $versionPayload = $this->actingAs($this->dataSteward)
            ->getJson("/api/care-pathways/v1/versions/{$version->pathway_version_uuid}")
            ->assertOk()
            ->assertJsonPath('data.version.version_uuid', (string) $version->pathway_version_uuid)
            ->assertJsonPath('data.version.content_digest', (string) $version->content_digest)
            ->assertJsonPath('data.version.exact_version', true)
            ->assertJsonPath('data.sections.0.source_text', 'Source-only admission criteria A.')
            ->assertJsonPath('data.sections.0.approved_text', null)
            ->assertJsonPath('data.authoring.milestones.0.expected_range.unit', 'hours')
            ->assertJsonPath('data.authoring.activities.0.executable', false)
            ->assertJsonPath('data.authoring.goals.0.target.operator', '<=')
            ->assertJsonPath('data.authoring.education.0.approved_content', null)
            ->json();

        $serializedVersion = json_encode($versionPayload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('raw_snapshot', $serializedVersion);
        $this->assertStringNotContainsString('pathway_version_id', $serializedVersion);
        $this->assertStringNotContainsString('milestone_definition_id', $serializedVersion);

        $claims = $this->actingAs($this->dataSteward)
            ->getJson("/api/care-pathways/v1/versions/{$version->pathway_version_uuid}/claims")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.version.version_uuid', (string) $version->pathway_version_uuid)
            ->assertJsonPath('meta.version.exact_version', true)
            ->assertJsonCount(2, 'data.0.sources')
            ->json();

        $serializedClaims = json_encode($claims, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('provenance', $serializedClaims);
        $this->assertStringNotContainsString('evidence_claim_id', $serializedClaims);
    }

    public function test_source_index_supports_cited_uncited_currency_and_detail_review(): void
    {
        $this->insertUncitedSource();

        $this->actingAs($this->dataSteward)
            ->getJson('/api/care-pathways/v1/sources?cited=true')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $uncited = $this->actingAs($this->dataSteward)
            ->getJson('/api/care-pathways/v1/sources?cited=false&status=current')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pmid', '333')
            ->assertJsonPath('data.0.uncited', true)
            ->assertJsonPath('data.0.current_status', 'current');

        $sourceUuid = (string) DB::table('care_pathways.sources')->where('pmid', '111')->value('source_uuid');
        $source = $this->actingAs($this->dataSteward)
            ->getJson("/api/care-pathways/v1/sources/{$sourceUuid}")
            ->assertOk()
            ->assertJsonPath('data.source_uuid', $sourceUuid)
            ->assertJsonPath('data.current_status', 'current')
            ->json();

        $serialized = json_encode($source, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('source_record', $serialized);
        $this->assertStringNotContainsString('raw_record', $serialized);
        $this->assertStringNotContainsString('provenance', $serialized);
        $this->assertSame(1, $uncited->json('meta.pagination.total'));
    }

    public function test_controls_review_approval_and_event_ledgers_are_release_scoped(): void
    {
        $version = DB::table('care_pathways.versions')->where('source_rank', 1)->first();
        $definition = DB::table('care_pathways.definitions')
            ->where('pathway_definition_id', $version->pathway_definition_id)
            ->first();
        $reviewUuid = (string) Str::uuid();
        $approvalUuid = (string) Str::uuid();
        $eventUuid = (string) Str::uuid();

        DB::table('care_pathways.reviews')->insert([
            'review_uuid' => $reviewUuid,
            'pathway_version_id' => $version->pathway_version_id,
            'reviewer_role' => 'care_pathway_evidence_reviewer',
            'reviewer_user_id' => $this->dataSteward->getKey(),
            'review_scope' => 'evidence',
            'decision' => 'changes_requested',
            'reason' => 'Fixture review requires clarification.',
            'issues' => json_encode(['issue' => 'clarify applicability'], JSON_THROW_ON_ERROR),
        ]);
        DB::table('care_pathways.approvals')->insert([
            'approval_uuid' => $approvalUuid,
            'pathway_version_id' => $version->pathway_version_id,
            'approval_type' => 'institutional_clinical',
            'actor_user_id' => $this->dataSteward->getKey(),
            'decision' => 'rejected',
            'conditions' => 'Not suitable for activation.',
        ]);
        DB::table('care_pathways.events')->insert([
            'event_uuid' => $eventUuid,
            'aggregate_type' => 'pathway_definition',
            'aggregate_id' => $definition->pathway_definition_id,
            'aggregate_uuid' => $definition->pathway_uuid,
            'event_type' => 'fixture_definition_reviewed',
            'actor_ref' => 'test-reviewer',
            'metadata' => json_encode(['release_uuid' => $this->releaseUuid], JSON_THROW_ON_ERROR),
        ]);

        $this->actingAs($this->dataSteward)
            ->getJson('/api/care-pathways/v1/controls?status=passed')
            ->assertOk()
            ->assertJsonPath('meta.catalog_release_uuid', $this->releaseUuid);
        $this->actingAs($this->dataSteward)
            ->getJson('/api/care-pathways/v1/reviews?decision=changes_requested')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.review_uuid', $reviewUuid);
        $this->actingAs($this->dataSteward)
            ->getJson('/api/care-pathways/v1/approvals?decision=rejected')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.approval_uuid', $approvalUuid);
        $this->actingAs($this->dataSteward)
            ->getJson('/api/care-pathways/v1/events?event_type=fixture_definition_reviewed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event_uuid', $eventUuid)
            ->assertJsonPath('data.0.aggregate_type', 'pathway_definition');

        $this->assertSame('inactive', DB::table('care_pathways.catalog_releases')->value('state'));
        $this->assertSame(0, DB::table('care_pathways.versions')->where('activation_status', 'active')->count());
    }

    public function test_validation_and_unknown_release_fail_closed(): void
    {
        $this->actingAs($this->dataSteward)
            ->getJson('/api/care-pathways/v1/pathways?drg=1234')
            ->assertUnprocessable();
        $this->actingAs($this->dataSteward)
            ->getJson('/api/care-pathways/v1/sources?verified_before=not-a-date')
            ->assertUnprocessable();
        $this->actingAs($this->dataSteward)
            ->getJson('/api/care-pathways/v1/sources?cited=yes')
            ->assertUnprocessable();
        $this->actingAs($this->dataSteward)
            ->getJson('/api/care-pathways/v1/summary?release_uuid='.Str::uuid())
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    private function insertAuthoringDefinitions(int $versionId): void
    {
        DB::table('care_pathways.milestone_definitions')->insert([
            'milestone_uuid' => (string) Str::uuid(),
            'pathway_version_id' => $versionId,
            'stable_key' => 'arrival-assessment',
            'title' => 'Arrival assessment',
            'phase' => 'arrival',
            'sequence' => 1,
            'predecessor_keys' => json_encode([], JSON_THROW_ON_ERROR),
            'expected_range' => json_encode(['value' => 2, 'unit' => 'hours'], JSON_THROW_ON_ERROR),
            'review_state' => 'draft',
        ]);
        DB::table('care_pathways.activity_definitions')->insert([
            'activity_uuid' => (string) Str::uuid(),
            'pathway_version_id' => $versionId,
            'stable_key' => 'initial-assessment',
            'activity_type' => 'assessment',
            'title' => 'Initial assessment',
            'timing' => json_encode(['phase' => 'arrival'], JSON_THROW_ON_ERROR),
            'preconditions' => json_encode([], JSON_THROW_ON_ERROR),
            'executable' => false,
            'review_state' => 'draft',
        ]);
        DB::table('care_pathways.goal_definitions')->insert([
            'goal_uuid' => (string) Str::uuid(),
            'pathway_version_id' => $versionId,
            'stable_key' => 'assessment-complete',
            'goal_text' => 'Complete initial assessment.',
            'target' => json_encode(['operator' => '<=', 'value' => 2, 'unit' => 'hours'], JSON_THROW_ON_ERROR),
            'review_state' => 'draft',
        ]);
        DB::table('care_pathways.education_definitions')->insert([
            'education_uuid' => (string) Str::uuid(),
            'pathway_version_id' => $versionId,
            'stable_key' => 'patient-overview',
            'audience' => 'patient',
            'language_code' => 'en',
            'title' => 'What to expect',
            'review_state' => 'draft',
        ]);
    }

    private function insertUncitedSource(): void
    {
        $digest = hash('sha256', 'uncited-fixture-source');
        $sourceId = DB::table('care_pathways.sources')->insertGetId([
            'source_uuid' => (string) Str::uuid(),
            'pmid' => '333',
            'source_url' => 'https://example.test/333',
            'title' => 'Uncited Source Three',
            'publication_types' => json_encode(['Guideline'], JSON_THROW_ON_ERROR),
            'source_type' => 'bibliographic',
            'retraction_indicator' => 'Not retracted',
            'supersession_state' => 'current',
            'verified_date' => '2026-07-20',
            'content_digest' => $digest,
            'provenance' => json_encode(['fixture' => true], JSON_THROW_ON_ERROR),
        ], 'source_id');

        DB::table('care_pathways.catalog_release_sources')->insert([
            'release_source_uuid' => (string) Str::uuid(),
            'catalog_release_id' => DB::table('care_pathways.catalog_releases')->value('catalog_release_id'),
            'source_id' => $sourceId,
            'source_content_digest' => $digest,
        ]);
    }
}
