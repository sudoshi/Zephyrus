<?php

declare(strict_types=1);

namespace Tests\Feature\CarePathways;

use App\Models\CarePathways\MilestoneDefinition;
use App\Models\CarePathways\PathwayVersion;
use App\Models\Encounter;
use App\Models\Patient\PatientPathwayInstance;
use App\Nightingale\Demo\NightingaleDemoCohort;
use App\Services\CarePathways\CatalogImportService;
use App\Services\CarePathways\Flow4dPathwayAssignmentService;
use App\Services\CarePathways\PathwayInstanceReadService;
use App\Services\Patient\Projection\SyntheticPatientProjectionProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CarePathwayRawFixture;
use Tests\TestCase;

/**
 * The full bounded-activation → serving loop, proven end-to-end (FLOW-4D
 * post-Phase-D real serving, scoped to the investor-demo cohort pathways). This
 * test mirrors the exact prod runbook, in order:
 *
 *   1. adopt raw release            → inactive canonical catalog
 *   2. author executable layer      → DRAFT milestones (seeded here)
 *   3. promote executable layer     → care-pathways:promote-executable-layer
 *   4. record institutional signoff → approve + activate versions + release
 *   5. cohort grant (PR #118)       → encounter_access_grant w/ pathway:read
 *   6. assign pathway               → Flow4dPathwayAssignmentService
 *   7. serve                        → PathwayInstanceReadService (REAL, demo=false)
 *
 * Everything runs in the isolated test DB and is fully reversible; the prod
 * equivalent of steps 4/6 are the append-only writes gated behind [SU] signoff.
 */
final class Flow4dPathwayServingTest extends TestCase
{
    use CarePathwayRawFixture;
    use RefreshDatabase;

    private const ENCOUNTER_ID = 424242;

    public function test_activate_promote_assign_then_serve_real_progress(): void
    {
        // 1. Adopt the raw release (2 fixture versions, inactive).
        $this->configureCarePathwayFixture();
        $this->seedCarePathwayRawFixture();
        app(CatalogImportService::class)->adopt(1, 'test-data-steward');

        $version = PathwayVersion::query()->with('definition')->orderBy('source_rank')->firstOrFail();
        $pathwayKey = (string) $version->definition->pathway_key;

        // 2. Author executable layer — seed DRAFT milestones with day offsets.
        $this->seedDraftMilestones((int) $version->getKey());
        $this->assertSame(3, MilestoneDefinition::query()->where('review_state', 'draft')->count());

        // 3. Promote drafts → approved via the command (scoped to this version).
        $this->artisan('care-pathways:promote-executable-layer', [
            '--version-id' => [(int) $version->getKey()],
            '--confirm' => 'promote-drafts-to-approved',
        ])->assertExitCode(0);
        $this->assertSame(0, MilestoneDefinition::query()->where('review_state', 'draft')->count());
        $this->assertSame(3, MilestoneDefinition::query()->where('review_state', 'approved')->count());

        // 4. Record institutional signoff — approve + activate every version, then
        //    the release (the trigger gate requires all-approved + count match).
        $this->activateFixtureRelease();

        // 5. Cohort grant (PR #118 shape): effective, pathway:read, linked to the
        //    flow encounter via source_encounter_id.
        $grant = app(SyntheticPatientProjectionProvisioner::class)->provision('flow4d-serving')['grant'];
        DB::table('patient_experience.encounter_access_grants')
            ->where('access_grant_id', $grant->getKey())
            ->update(['source_encounter_id' => self::ENCOUNTER_ID]);

        // 6. Assign the pathway + LOS-driven milestone statuses.
        $now = CarbonImmutable::parse('2026-07-28T12:00:00Z');
        $admittedAt = $now->subDays(2)->subHours(5); // LOS = 2 days
        $summary = app(Flow4dPathwayAssignmentService::class)
            ->assignByPathwayKey(self::ENCOUNTER_ID, $pathwayKey, $admittedAt, $now);

        $this->assertNotNull($summary);
        $this->assertSame(2, $summary['los_days']);
        $this->assertSame(3, $summary['milestones']);
        $this->assertSame(2, $summary['completed']); // day 0 + day 1
        $this->assertSame(1, $summary['current']);    // day 2
        $this->assertSame(0, $summary['planned']);

        // 7. Serve — the 4D Navigator read path returns REAL progress (not the
        //    synthetic demo overlay) for this encounter.
        config(['care-pathways.assignment_enabled' => true]);
        $progress = app(PathwayInstanceReadService::class)->progressForEncounterId(self::ENCOUNTER_ID);

        $this->assertNotNull($progress);
        $this->assertFalse($progress['demo']);
        $this->assertFalse($progress['clinical_use']); // still never presented as guidance
        $this->assertSame('assigned_instance', $progress['source']);
        $this->assertSame(
            ['completed', 'completed', 'current'],
            array_column($progress['milestones'], 'status'),
        );
        $this->assertSame(2, $progress['summary']['completed']);
        $this->assertSame('day_2_m01', $progress['summary']['current_stable_key']);
    }

    public function test_assign_demo_pathways_command_serves_a_cohort_patient(): void
    {
        // Activated catalog with one version renamed to a cohort pathway_key.
        $this->configureCarePathwayFixture();
        $this->seedCarePathwayRawFixture();
        app(CatalogImportService::class)->adopt(1, 'test-data-steward');
        $version = PathwayVersion::query()->with('definition')->orderBy('source_rank')->firstOrFail();
        $cohortKey = (string) NightingaleDemoCohort::MEMBERS['demo1']['pathway_key'];
        DB::table('care_pathways.definitions')
            ->where('pathway_definition_id', $version->pathway_definition_id)
            ->update(['pathway_key' => $cohortKey]);
        $this->seedApprovedMilestones((int) $version->getKey());
        $this->activateFixtureRelease();

        // A flow encounter + a cohort-shaped grant (demo_username=demo1).
        $encounter = Encounter::create([
            'patient_ref' => 'demo-nightingale-investor-01',
            'admitted_at' => CarbonImmutable::now()->subDays(2),
            'status' => 'active',
            'is_deleted' => false,
        ]);
        $grant = app(SyntheticPatientProjectionProvisioner::class)->provision('flow4d-cohort')['grant'];
        $grant->update([
            'source_system_key' => NightingaleDemoCohort::SOURCE_SYSTEM_KEY,
            'source_encounter_id' => $encounter->getKey(),
            'metadata' => ['demo_username' => 'demo1', 'synthetic' => true],
        ]);

        $this->artisan('flow4d:assign-demo-pathways', ['--confirm' => 'assign-demo-cohort-pathways'])
            ->assertExitCode(0);

        $this->assertSame(1, PatientPathwayInstance::query()->where('access_grant_id', $grant->getKey())->count());

        config(['care-pathways.assignment_enabled' => true]);
        $progress = app(PathwayInstanceReadService::class)->progressForEncounterId((int) $encounter->getKey());
        $this->assertNotNull($progress);
        $this->assertSame('assigned_instance', $progress['source']);
        $this->assertFalse($progress['demo']);
    }

    public function test_assignment_needs_an_activated_catalog(): void
    {
        // Without activation, assign fails loudly — nothing serves by accident.
        $this->configureCarePathwayFixture();
        $this->seedCarePathwayRawFixture();
        app(CatalogImportService::class)->adopt(1, 'test-data-steward');
        $version = PathwayVersion::query()->with('definition')->orderBy('source_rank')->firstOrFail();

        $grant = app(SyntheticPatientProjectionProvisioner::class)->provision('flow4d-serving-inactive')['grant'];
        DB::table('patient_experience.encounter_access_grants')
            ->where('access_grant_id', $grant->getKey())->update(['source_encounter_id' => self::ENCOUNTER_ID]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('activate the catalog first');
        app(Flow4dPathwayAssignmentService::class)->assignByPathwayKey(
            self::ENCOUNTER_ID,
            (string) $version->definition->pathway_key,
            CarbonImmutable::now()->subDay(),
            CarbonImmutable::now(),
        );
    }

    public function test_assignment_is_null_without_a_grant(): void
    {
        $this->configureCarePathwayFixture();
        $this->seedCarePathwayRawFixture();
        app(CatalogImportService::class)->adopt(1, 'test-data-steward');
        $this->activateFixtureRelease();
        $version = PathwayVersion::query()->with('definition')->orderBy('source_rank')->firstOrFail();

        // No grant for encounter 999999 → nothing to assign.
        $this->assertNull(app(Flow4dPathwayAssignmentService::class)->assignByPathwayKey(
            999999,
            (string) $version->definition->pathway_key,
            CarbonImmutable::now()->subDay(),
            CarbonImmutable::now(),
        ));
    }

    public function test_promote_command_requires_scope_and_confirmation(): void
    {
        // No scope → refuses (never promotes the whole catalog).
        $this->artisan('care-pathways:promote-executable-layer')
            ->expectsOutputToContain('Scope is required')
            ->assertExitCode(2);

        // Scoped at a real version with drafts, but unconfirmed → refuses to write.
        $this->configureCarePathwayFixture();
        $this->seedCarePathwayRawFixture();
        app(CatalogImportService::class)->adopt(1, 'test-data-steward');
        $versionId = (int) PathwayVersion::query()->orderBy('source_rank')->value('pathway_version_id');
        $this->seedDraftMilestones($versionId);

        $this->artisan('care-pathways:promote-executable-layer', ['--version-id' => [$versionId]])
            ->expectsOutputToContain('Refusing to write')
            ->assertExitCode(2);
        $this->assertSame(3, MilestoneDefinition::query()->where('review_state', 'draft')->count());
    }

    private function seedDraftMilestones(int $versionId): void
    {
        $this->seedMilestones($versionId, 'draft');
    }

    private function seedApprovedMilestones(int $versionId): void
    {
        $this->seedMilestones($versionId, 'approved');
    }

    private function seedMilestones(int $versionId, string $reviewState): void
    {
        $rows = [
            ['stable_key' => 'arrival_m00', 'title' => 'Admitted and settled', 'phase' => 'arrival', 'sequence' => 0, 'expected_range' => ['day_offset_min' => 0, 'day_offset_max' => 0]],
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
                'review_state' => $reviewState,
            ]);
        }
    }

    private function activateFixtureRelease(): void
    {
        $summary = app(CatalogImportService::class)->adopt(1, 'test-data-steward');

        DB::table('care_pathways.definitions')->update(['lifecycle_state' => 'active', 'updated_at' => now()]);
        DB::table('care_pathways.versions')->update([
            'institutional_approval_status' => 'approved',
            'activation_status' => 'active',
            'updated_at' => now(),
        ]);
        $pathwayCount = (int) DB::table('care_pathways.versions')->count();
        DB::table('care_pathways.catalog_releases')
            ->where('catalog_release_id', $summary['catalog_release_id'])
            ->update([
                'state' => 'active',
                'clinical_signoff_complete' => true,
                'clinical_signoff_count' => $pathwayCount,
                'activated_by_user_id' => 999,
                'activated_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
