<?php

declare(strict_types=1);

namespace Tests\Feature\CarePathways;

use App\Models\CarePathways\MilestoneDefinition;
use App\Services\CarePathways\CatalogImportService;
use App\Services\CarePathways\ReferenceModelCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CarePathwayRawFixture;
use Tests\TestCase;

/**
 * The DB adapter over the pure ReferenceModelCompiler (FLOW-4D plan Phase D1):
 * reads a governed version's definition metadata + persisted milestone layer and
 * compiles. Read-only; returns null for a version with no executable layer —
 * which, for the inactive catalog release, is every version until milestones are
 * authored.
 */
final class ReferenceModelCompilerVersionTest extends TestCase
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

    public function test_returns_null_for_a_version_with_no_milestones(): void
    {
        $versionId = (int) DB::table('care_pathways.versions')->orderBy('source_rank')->value('pathway_version_id');

        $this->assertNull(app(ReferenceModelCompiler::class)->compileVersion($versionId));
    }

    public function test_returns_null_for_an_unknown_version(): void
    {
        $this->assertNull(app(ReferenceModelCompiler::class)->compileVersion(999999));
    }

    public function test_compiles_a_version_with_a_persisted_milestone_layer(): void
    {
        $versionId = (int) DB::table('care_pathways.versions')->orderBy('source_rank')->value('pathway_version_id');
        $this->seedMilestones($versionId);

        $model = app(ReferenceModelCompiler::class)->compileVersion($versionId);

        $this->assertNotNull($model);
        // Ordered by sequence then stable_key, sourced straight from the catalog.
        $this->assertSame(['arrival_m00', 'day_1_m01', 'day_2_m01'], $model['activities']);
        $this->assertSame('arrival_m00', $model['trigger']);
        $this->assertSame('Encounter', $model['case_type']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $model['digest']);

        // The adapter fills governance pinning from the version + release rows.
        $this->assertNotNull($model['source']['pathway_version_uuid']);
        $this->assertSame($model['semantic_version'], $model['source']['semantic_version']);
        $this->assertNotNull($model['source']['catalog_release_uuid']);
    }

    public function test_compilation_is_stable_across_calls(): void
    {
        $versionId = (int) DB::table('care_pathways.versions')->orderBy('source_rank')->value('pathway_version_id');
        $this->seedMilestones($versionId);
        $compiler = app(ReferenceModelCompiler::class);

        $this->assertSame(
            $compiler->compileVersion($versionId)['digest'],
            $compiler->compileVersion($versionId)['digest'],
        );
    }

    private function seedMilestones(int $versionId): void
    {
        $rows = [
            ['stable_key' => 'day_2_m01', 'title' => 'Repeat imaging reviewed', 'phase' => 'day_2', 'sequence' => 2, 'expected_range' => ['day_offset_min' => 1, 'day_offset_max' => 2]],
            ['stable_key' => 'day_1_m01', 'title' => 'Initial workup complete', 'phase' => 'day_1', 'sequence' => 1, 'expected_range' => ['day_offset_min' => 0, 'day_offset_max' => 1]],
            ['stable_key' => 'arrival_m00', 'title' => 'Admitted and settled', 'phase' => 'arrival', 'sequence' => 0, 'expected_range' => ['display' => 'Today']],
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
