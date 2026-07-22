<?php

namespace Tests\Feature\CarePathways;

use App\Services\CarePathways\ApprovedPathwayCatalogReadService;
use App\Services\CarePathways\CatalogImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Support\CarePathwayRawFixture;
use Tests\TestCase;

class ApprovedPathwayCatalogReadServiceTest extends TestCase
{
    use CarePathwayRawFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureCarePathwayFixture();
        $this->seedCarePathwayRawFixture();
        config(['care-pathways.catalog_enabled' => true]);
    }

    public function test_inactive_source_versions_are_invisible_to_the_approved_read_boundary(): void
    {
        app(CatalogImportService::class)->adopt(1, 'test-data-steward');
        $versionUuid = (string) DB::table('care_pathways.versions')->value('pathway_version_uuid');
        $reader = app(ApprovedPathwayCatalogReadService::class);

        $this->assertNull($reader->findVersion($versionUuid));
        $this->assertSame([], $reader->candidatesForDrg('002'));
    }

    public function test_feature_flag_fails_closed_even_for_an_active_release(): void
    {
        $this->activateFixtureRelease();
        $versionUuid = (string) DB::table('care_pathways.versions')->value('pathway_version_uuid');
        config(['care-pathways.catalog_enabled' => false]);
        $reader = app(ApprovedPathwayCatalogReadService::class);

        $this->assertNull($reader->findVersion($versionUuid));
        $this->assertSame([], $reader->candidatesForDrg('002'));
    }

    public function test_active_approved_read_exposes_only_approved_staff_content_and_preserves_drg_ambiguity(): void
    {
        $this->activateFixtureRelease();
        $versionUuid = (string) DB::table('care_pathways.versions')->orderBy('source_rank')->value('pathway_version_uuid');
        $reader = app(ApprovedPathwayCatalogReadService::class);

        $version = $reader->findVersion($versionUuid, 'staff_reference');
        $this->assertNotNull($version);
        $this->assertSame('staff_reference', $version['audience']);
        $this->assertTrue($version['exact_version']);
        $this->assertSame('2026-07-21', $version['source_cutoff_date']);
        $this->assertCount(1, $version['sections']);
        $this->assertSame('Approved staff guidance.', $version['sections'][0]['text']);
        $this->assertArrayNotHasKey('raw_snapshot', $version);
        $this->assertArrayNotHasKey('source_text', $version['sections'][0]);
        $this->assertNull($reader->findVersion($versionUuid, 'staff_workflow'));
        $this->assertSame([], $reader->candidatesForDrg('002', 'staff_workflow'));

        $candidates = $reader->candidatesForDrg('2', 'staff_reference');
        $this->assertCount(2, $candidates);
        $this->assertSame([1, 2], array_column($candidates, 'source_rank'));
        $this->assertSame([true, true], array_column(array_column($candidates, 'match'), 'requires_clinician_confirmation'));
    }

    public function test_patient_audience_is_rejected_in_favor_of_the_patient_projection_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('patient content must use released patient projections');

        app(ApprovedPathwayCatalogReadService::class)->candidatesForDrg('002', 'patient');
    }

    public function test_a_later_append_only_retraction_event_removes_every_affected_version(): void
    {
        $this->activateFixtureRelease();
        $sourceId = (int) DB::table('care_pathways.sources')->where('pmid', '111')->value('source_id');
        $versionUuid = (string) DB::table('care_pathways.versions')->where('source_rank', 1)->value('pathway_version_uuid');

        DB::table('care_pathways.source_status_events')->insert([
            'status_event_uuid' => (string) Str::uuid(),
            'source_id' => $sourceId,
            'supersession_state' => 'retracted',
            'reason' => 'Fixture retraction notice.',
            'evidence_url' => 'https://example.test/111/retraction',
            'observed_at' => now(),
            'effective_at' => now(),
            'recorded_by_ref' => 'test-source-monitor',
            'event_digest' => hash('sha256', 'fixture-source-111-retracted'),
            'created_at' => now(),
        ]);

        $reader = app(ApprovedPathwayCatalogReadService::class);

        $this->assertNull($reader->findVersion($versionUuid, 'staff_reference'));
        $this->assertSame([2], array_column($reader->candidatesForDrg('002', 'staff_reference'), 'source_rank'));
    }

    public function test_a_noncurrent_section_citation_removes_the_affected_version(): void
    {
        $this->activateFixtureRelease();
        $version = DB::table('care_pathways.versions')->where('source_rank', 1)->first();
        $sectionId = DB::table('care_pathways.sections')
            ->where('pathway_version_id', $version->pathway_version_id)
            ->value('pathway_section_id');
        $sourceId = DB::table('care_pathways.sources')->insertGetId([
            'source_uuid' => (string) Str::uuid(),
            'pmid' => '444',
            'title' => 'Section-only fixture source',
            'source_url' => 'https://example.test/444',
            'retraction_indicator' => 'Retracted',
            'supersession_state' => 'retracted',
            'content_digest' => hash('sha256', 'section-only-fixture-source'),
        ], 'source_id');
        DB::table('care_pathways.catalog_release_sources')->insert([
            'release_source_uuid' => (string) Str::uuid(),
            'catalog_release_id' => $version->catalog_release_id,
            'source_id' => $sourceId,
            'source_content_digest' => hash('sha256', 'section-only-fixture-source'),
        ]);
        DB::table('care_pathways.section_sources')->insert([
            'pathway_section_id' => $sectionId,
            'source_id' => $sourceId,
        ]);

        $this->assertNull(app(ApprovedPathwayCatalogReadService::class)->findVersion(
            $version->pathway_version_uuid,
            'staff_reference',
        ));
    }

    private function activateFixtureRelease(): void
    {
        $summary = app(CatalogImportService::class)->adopt(1, 'test-data-steward');
        $approvedText = 'Approved staff guidance.';

        DB::table('care_pathways.definitions')->update([
            'lifecycle_state' => 'active',
            'updated_at' => now(),
        ]);
        DB::table('care_pathways.sections')->update([
            'approved_text' => $approvedText,
            'content_mode' => 'approved',
            'review_state' => 'approved',
            'approved_digest' => hash('sha256', $approvedText),
            'updated_at' => now(),
        ]);
        DB::table('care_pathways.versions')->update([
            'institutional_approval_status' => 'approved',
            'activation_status' => 'active',
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
