<?php

namespace Tests\Feature\CarePathways;

use App\Services\CarePathways\CatalogImportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\CarePathwayRawFixture;
use Tests\TestCase;

class CarePathwayCatalogAdoptionTest extends TestCase
{
    use CarePathwayRawFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureCarePathwayFixture();
        $this->seedCarePathwayRawFixture();
    }

    public function test_dry_run_recomputes_controls_without_writing(): void
    {
        $summary = app(CatalogImportService::class)->adopt(1, 'test-data-steward', true);

        $this->assertTrue($summary['dry_run']);
        $this->assertSame(2, $summary['pathways']);
        $this->assertSame(2, $summary['drg_codebook_entries']);
        $this->assertSame(3, $summary['drg_mappings']);
        $this->assertSame(0, DB::table('care_pathways.catalog_releases')->count());
        $this->assertSame(0, DB::table('care_pathways.versions')->count());
    }

    public function test_adoption_loads_an_inactive_unapproved_traceable_catalog(): void
    {
        $summary = app(CatalogImportService::class)->adopt(1, 'test-data-steward');

        $this->assertFalse($summary['dry_run']);
        $this->assertFalse($summary['reused']);
        $this->assertSame('inactive', $summary['state']);
        $this->assertFalse($summary['clinical_signoff_complete']);
        $this->assertSame(2, $summary['pathways']);
        $this->assertSame(3, $summary['drg_mappings']);

        $releaseId = $summary['catalog_release_id'];
        $this->assertDatabaseHas('care_pathways.catalog_releases', [
            'catalog_release_id' => $releaseId,
            'dataset_key' => 'care-pathway-test-release',
            'state' => 'inactive',
            'clinical_signoff_complete' => false,
            'pathway_count' => 2,
            'pathway_drg_association_count' => 3,
            'unique_drg_code_count' => 2,
        ]);
        $this->assertSame(2, DB::table('care_pathways.definitions')->count());
        $this->assertSame(2, DB::table('care_pathways.versions')->where('activation_status', 'inactive')->count());
        $this->assertSame(2, DB::table('care_pathways.versions')->where('institutional_approval_status', 'not_reviewed')->count());
        $this->assertSame(2, DB::table('care_pathways.sections')->whereNull('approved_text')->where('review_state', 'source_candidate')->count());
        $this->assertSame(2, DB::table('care_pathways.drg_codebook_entries')->count());
        $this->assertSame(3, DB::table('care_pathways.drg_mappings')->count());
        $this->assertSame(2, DB::table('care_pathways.evidence_claims')->count());
        $this->assertSame(3, DB::table('care_pathways.claim_sources')->count());
        $this->assertSame(2, DB::table('care_pathways.catalog_release_sources')->count());
        $this->assertSame(1, DB::table('care_pathways.source_changes')->count());
        $this->assertSame(6, DB::table('care_pathways.source_enrichments')->count());
        $this->assertSame(2, DB::table('care_pathways.completeness_resolutions')->count());
        $this->assertDatabaseHas('care_pathways.source_enrichments', [
            'source_field' => 'first_author',
            'enriched_value' => 'not_listed_by_pubmed',
            'resolution_class' => 'no_personal_author_listed',
        ]);
        $this->assertDatabaseHas('care_pathways.completeness_resolutions', [
            'source_field' => 'source_index.first_author',
            'source_blank_count' => 1,
            'residual_unknown_count' => 0,
            'source_classification' => 'one_enriched_fifteen_explicitly_not_listed',
            'resolution_type' => 'enriched',
        ]);
        $this->assertDatabaseHas('care_pathways.catalog_release_controls', [
            'control_key' => 'cms_v43_1_list_design_count_discrepancy',
            'status' => 'accepted_discrepancy',
        ]);
        $this->assertDatabaseHas('care_pathways.catalog_release_controls', [
            'control_key' => 'source_enrichment_field_facts',
            'status' => 'passed',
        ]);
        $this->assertDatabaseHas('care_pathways.catalog_release_controls', [
            'control_key' => 'source_service_line_mappings_pending',
            'status' => 'accepted_discrepancy',
        ]);
        $this->assertDatabaseHas('care_pathways.catalog_release_controls', [
            'control_key' => 'catalog_release_source_membership',
            'status' => 'passed',
        ]);
        $this->assertDatabaseHas('care_pathways.events', [
            'aggregate_type' => 'catalog_release',
            'event_type' => 'verification_release_adopted_inactive',
        ]);
    }

    public function test_explicit_negative_retraction_wording_is_classified_as_current(): void
    {
        DB::table('raw.cp_test_sources')->update([
            'retraction_indicator' => 'No PubMed retraction indicator detected',
        ]);

        app(CatalogImportService::class)->adopt(1, 'test-data-steward');

        $this->assertSame(2, DB::table('care_pathways.sources')->where('supersession_state', 'current')->count());
        $this->assertSame(0, DB::table('care_pathways.sources')->where('supersession_state', 'retracted')->count());
    }

    public function test_negation_repair_appends_an_idempotent_status_fact_and_release_control(): void
    {
        $summary = app(CatalogImportService::class)->adopt(1, 'test-data-steward');
        $sourceId = DB::table('care_pathways.sources')->insertGetId([
            'source_uuid' => (string) Str::uuid(),
            'pmid' => '333',
            'title' => 'Misclassified fixture source',
            'source_url' => 'https://example.test/333',
            'retraction_indicator' => 'No PubMed retraction indicator detected',
            'supersession_state' => 'retracted',
            'content_digest' => hash('sha256', 'misclassified-fixture-source'),
        ], 'source_id');
        DB::table('care_pathways.catalog_release_sources')->insert([
            'release_source_uuid' => (string) Str::uuid(),
            'catalog_release_id' => $summary['catalog_release_id'],
            'source_id' => $sourceId,
            'source_content_digest' => hash('sha256', 'misclassified-fixture-source'),
        ]);
        DB::table('care_pathways.claim_sources')->insert([
            'evidence_claim_id' => DB::table('care_pathways.evidence_claims')->orderBy('evidence_claim_id')->value('evidence_claim_id'),
            'source_id' => $sourceId,
        ]);

        $repair = require database_path('migrations/2026_07_21_001300_repair_care_pathway_source_retraction_negation.php');
        $repair->up();
        $repair->up();

        $this->assertDatabaseHas('care_pathways.source_status_events', [
            'source_id' => $sourceId,
            'supersession_state' => 'current',
            'recorded_by_ref' => 'care-pathway-importer-negation-repair-v1',
        ]);
        $this->assertSame('current', DB::table('care_pathways.current_source_statuses')
            ->where('source_id', $sourceId)
            ->value('supersession_state'));
        $this->assertSame(1, DB::table('care_pathways.source_status_events')->where('source_id', $sourceId)->count());
        $this->assertDatabaseHas('care_pathways.catalog_release_controls', [
            'catalog_release_id' => $summary['catalog_release_id'],
            'control_key' => 'source_retraction_negation_repair',
            'status' => 'passed',
        ]);
    }

    public function test_database_rejects_a_citation_to_a_source_outside_the_release_index(): void
    {
        app(CatalogImportService::class)->adopt(1, 'test-data-steward');
        $sourceId = DB::table('care_pathways.sources')->insertGetId([
            'source_uuid' => (string) Str::uuid(),
            'pmid' => '555',
            'title' => 'Unregistered fixture source',
            'source_url' => 'https://example.test/555',
            'retraction_indicator' => 'Not retracted',
            'supersession_state' => 'current',
            'content_digest' => hash('sha256', 'unregistered-fixture-source'),
        ], 'source_id');

        DB::statement('SAVEPOINT care_pathway_source_membership_check');
        try {
            DB::table('care_pathways.claim_sources')->insert([
                'evidence_claim_id' => DB::table('care_pathways.evidence_claims')->orderBy('evidence_claim_id')->value('evidence_claim_id'),
                'source_id' => $sourceId,
            ]);
            $this->fail('The citation membership guard accepted an unregistered source.');
        } catch (QueryException $exception) {
            DB::statement('ROLLBACK TO SAVEPOINT care_pathway_source_membership_check');
            $this->assertStringContainsString('does not belong to the citation catalog release', $exception->getMessage());
        }
    }

    public function test_exact_rerun_is_idempotent(): void
    {
        $first = app(CatalogImportService::class)->adopt(1, 'test-data-steward');
        $firstControlCount = DB::table('care_pathways.catalog_release_controls')->count();
        $second = app(CatalogImportService::class)->adopt(1, 'test-data-steward');

        $this->assertSame($first['catalog_release_id'], $second['catalog_release_id']);
        $this->assertTrue($second['reused']);
        $this->assertSame(1, DB::table('care_pathways.catalog_releases')->count());
        $this->assertSame(2, DB::table('care_pathways.versions')->count());
        $this->assertSame(3, DB::table('care_pathways.drg_mappings')->count());
        $this->assertSame(2, DB::table('care_pathways.evidence_claims')->count());
        $this->assertSame(2, DB::table('care_pathways.catalog_release_sources')->count());
        $this->assertSame(6, DB::table('care_pathways.source_enrichments')->count());
        $this->assertSame(2, DB::table('care_pathways.completeness_resolutions')->count());
        $this->assertSame($firstControlCount, DB::table('care_pathways.catalog_release_controls')->count());
    }

    public function test_same_dataset_key_with_different_source_hash_fails_closed(): void
    {
        app(CatalogImportService::class)->adopt(1, 'test-data-steward');

        DB::table('raw.cp_test_manifest')->update(['source_csv_sha256' => str_repeat('d', 64)]);
        config(['care-pathways.source_release.source_csv_sha256' => str_repeat('d', 64)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different immutable source hashes');

        try {
            app(CatalogImportService::class)->adopt(1, 'test-data-steward');
        } finally {
            $this->assertSame(1, DB::table('care_pathways.catalog_releases')->count());
            $this->assertSame(2, DB::table('care_pathways.versions')->count());
        }
    }

    public function test_missing_claim_source_fails_before_any_canonical_write(): void
    {
        DB::table('raw.cp_test_sources')->where('pmid', '222')->delete();
        config(['care-pathways.expected_controls.sources' => 1]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('absent from the complete source index');

        try {
            app(CatalogImportService::class)->adopt(1, 'test-data-steward');
        } finally {
            $this->assertSame(0, DB::table('care_pathways.catalog_releases')->count());
            $this->assertSame(0, DB::table('care_pathways.versions')->count());
        }
    }

    public function test_database_rejects_source_content_mutation_and_premature_activation(): void
    {
        $summary = app(CatalogImportService::class)->adopt(1, 'test-data-steward');
        $claimId = DB::table('care_pathways.evidence_claims')->value('evidence_claim_id');

        DB::statement('SAVEPOINT care_pathway_append_only_check');
        try {
            DB::table('care_pathways.evidence_claims')
                ->where('evidence_claim_id', $claimId)
                ->update(['claim_excerpt' => 'Mutated claim']);
            $this->fail('The append-only claim trigger accepted an update.');
        } catch (QueryException $exception) {
            DB::statement('ROLLBACK TO SAVEPOINT care_pathway_append_only_check');
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        DB::statement('SAVEPOINT care_pathway_activation_check');
        try {
            DB::table('care_pathways.catalog_releases')
                ->where('catalog_release_id', $summary['catalog_release_id'])
                ->update([
                    'state' => 'active',
                    'clinical_signoff_complete' => true,
                    'clinical_signoff_count' => 2,
                    'activated_by_user_id' => 1,
                    'activated_at' => now(),
                ]);
            $this->fail('The activation gate accepted unapproved source versions.');
        } catch (QueryException $exception) {
            DB::statement('ROLLBACK TO SAVEPOINT care_pathway_activation_check');
            $this->assertStringContainsString('cannot activate', $exception->getMessage());
        }
    }

    public function test_artisan_command_requires_an_accountable_actor(): void
    {
        $exitCode = Artisan::call('care-pathways:adopt-raw-release', ['releaseId' => 1]);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('--actor option is required', Artisan::output());
    }
}
