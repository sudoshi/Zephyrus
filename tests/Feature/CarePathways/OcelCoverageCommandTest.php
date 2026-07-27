<?php

declare(strict_types=1);

namespace Tests\Feature\CarePathways;

use App\Models\CarePathways\MilestoneDefinition;
use App\Services\CarePathways\CatalogImportService;
use App\Services\CarePathways\OcelActivityCoverageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CarePathwayRawFixture;
use Tests\TestCase;

/**
 * The OCEL mapping coverage report (FLOW-4D plan Phase D2): the honest gap list
 * of governed milestones with no OCEL activity mapping.
 */
final class OcelCoverageCommandTest extends TestCase
{
    use CarePathwayRawFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureCarePathwayFixture();
        $this->seedCarePathwayRawFixture();
        app(CatalogImportService::class)->adopt(1, 'test-data-steward');
    }

    public function test_report_is_empty_when_no_version_has_an_executable_layer(): void
    {
        $report = app(OcelActivityCoverageService::class)->report();

        $this->assertSame(0, $report['totals']['executable_versions']);
        $this->assertSame([], $report['pathways']);
        $this->assertNull($report['totals']['coverage']);
    }

    public function test_command_reports_the_empty_layer_honestly(): void
    {
        $this->artisan('care-pathways:ocel-coverage')
            ->expectsOutputToContain('No governed version has a persisted executable milestone layer yet.')
            ->assertExitCode(0);
    }

    public function test_report_counts_mapped_and_lists_the_unmapped_gap(): void
    {
        $versionId = (int) DB::table('care_pathways.versions')->orderBy('source_rank')->value('pathway_version_id');
        $this->seedMilestones($versionId);

        // Map exactly one of the three milestone activities to a real OCEL activity.
        config(['care-pathways-ocel-map.activities' => [
            'day_1_m01' => 'antibiotic_administration',
            'day_2_m01' => 'not_a_real_ocel_activity', // target outside the vocabulary → flagged
        ]]);

        $report = app(OcelActivityCoverageService::class)->report();

        $this->assertSame(1, $report['totals']['executable_versions']);
        $this->assertSame(3, $report['totals']['activities']);
        $this->assertSame(2, $report['totals']['mapped']);
        $this->assertSame(1, $report['totals']['unmapped']);
        $this->assertSame(1, $report['totals']['unknown_targets']);

        $pathway = $report['pathways'][0];
        $this->assertSame(['arrival_m00'], $pathway['unmapped']); // the honest gap
        $this->assertSame(['day_2_m01 → not_a_real_ocel_activity'], $pathway['unknown_targets']);
    }

    public function test_json_output_is_machine_readable(): void
    {
        $versionId = (int) DB::table('care_pathways.versions')->orderBy('source_rank')->value('pathway_version_id');
        $this->seedMilestones($versionId);

        $this->artisan('care-pathways:ocel-coverage --json')->assertExitCode(0);
    }

    private function seedMilestones(int $versionId): void
    {
        $rows = [
            ['stable_key' => 'arrival_m00', 'title' => 'Admitted and settled', 'phase' => 'arrival', 'sequence' => 0, 'expected_range' => ['display' => 'Today']],
            ['stable_key' => 'day_1_m01', 'title' => 'Initial workup complete', 'phase' => 'day_1', 'sequence' => 1, 'expected_range' => ['day_offset_min' => 0, 'day_offset_max' => 1]],
            ['stable_key' => 'day_2_m01', 'title' => 'Repeat imaging reviewed', 'phase' => 'day_2', 'sequence' => 2, 'expected_range' => ['day_offset_min' => 1, 'day_offset_max' => 2]],
        ];

        foreach ($rows as $row) {
            MilestoneDefinition::query()->create([
                'pathway_version_id' => $versionId,
                'milestone_uuid' => (string) Str::uuid(),
                'stable_key' => $row['stable_key'],
                'title' => $row['title'],
                'phase' => $row['phase'],
                'sequence' => $row['sequence'],
                'predecessor_keys' => [],
                'expected_range' => $row['expected_range'],
                'review_state' => 'approved',
            ]);
        }
    }
}
