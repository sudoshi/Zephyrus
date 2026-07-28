<?php

declare(strict_types=1);

namespace Tests\Feature\CarePathways;

use App\Models\CarePathways\MilestoneDefinition;
use App\Models\CarePathways\PathwayVersion;
use App\Services\CarePathways\CatalogImportService;
use App\Services\CarePathways\PathwayInstanceReadService;
use App\Services\Patient\Pathway\PatientPathwayInstanceService;
use App\Services\Patient\Projection\SyntheticPatientProjectionProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CarePathwayRawFixture;
use Tests\TestCase;

/**
 * The read-only assigned-pathway progress projection (FLOW-4D plan Phase D4).
 * Serving is dark: both the assignment gate AND the patient_experience tables
 * must be present. Reads never mutate the append-only status history.
 */
final class PathwayInstanceReadServiceTest extends TestCase
{
    use CarePathwayRawFixture;
    use RefreshDatabase;

    private PathwayVersion $version;

    /** @var array<string, MilestoneDefinition> */
    private array $milestones = [];

    private int $accessGrantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureCarePathwayFixture();
        $this->seedCarePathwayRawFixture();
        $this->activateFixtureRelease();

        $this->version = PathwayVersion::query()->orderBy('source_rank')->firstOrFail();
        $this->seedThreeMilestones();

        $grant = app(SyntheticPatientProjectionProvisioner::class)->provision('flow4d-pathway-progress')['grant'];
        $this->accessGrantId = (int) $grant->getKey();

        $service = app(PatientPathwayInstanceService::class);
        $instance = $service->instantiate($grant, $this->version, 'test-pathway-adapter', 'flow4d-assignment-ref', now()->subMinutes(20));
        $service->recordMilestoneStatus($instance, $this->milestones['arrival_m00'], 'completed', 'flow4d-m0-completed', now()->subMinutes(15));
        $service->recordMilestoneStatus($instance, $this->milestones['day_1_m01'], 'current', 'flow4d-m1-current', now()->subMinutes(5));
        $service->recordMilestoneStatus($instance, $this->milestones['day_2_m01'], 'planned', 'flow4d-m2-planned', now()->subMinute());

        config(['care-pathways.assignment_enabled' => true]);
    }

    public function test_projects_ordered_milestone_progress_for_the_access_grant(): void
    {
        $progress = app(PathwayInstanceReadService::class)->progressForAccessGrant($this->accessGrantId);

        $this->assertNotNull($progress);
        $this->assertFalse($progress['demo']);
        $this->assertFalse($progress['clinical_use']); // Phase D never serves guidance
        $this->assertNull($progress['notice']);
        $this->assertSame('assigned_instance', $progress['source']);

        $this->assertSame(
            ['arrival_m00', 'day_1_m01', 'day_2_m01'],
            array_column($progress['milestones'], 'stable_key'),
        );
        $this->assertSame(
            ['completed', 'current', 'planned'],
            array_column($progress['milestones'], 'status'),
        );

        $this->assertSame(1, $progress['summary']['completed']);
        $this->assertSame(1, $progress['summary']['current']);
        $this->assertSame(1, $progress['summary']['planned']);
        $this->assertSame(3, $progress['summary']['total']);
        $this->assertSame('day_1_m01', $progress['summary']['current_stable_key']);
        $this->assertSame('1 of 3 milestones complete', $progress['summary']['elements_met_label']);

        // Timing carried through from the governed milestone definitions.
        $day1 = $progress['milestones'][1];
        $this->assertSame(['day_offset_min' => 0, 'day_offset_max' => 1, 'display' => null], $day1['expected']);
    }

    public function test_resolves_progress_from_a_flow_encounter_id(): void
    {
        // The journey spine calls with a flow encounter id; the service resolves
        // the active grant via encounter_access_grants.source_encounter_id.
        DB::table('patient_experience.encounter_access_grants')
            ->where('access_grant_id', $this->accessGrantId)
            ->update(['source_encounter_id' => 987654]);

        $viaEncounter = app(PathwayInstanceReadService::class)->progressForEncounterId(987654);
        $viaGrant = app(PathwayInstanceReadService::class)->progressForAccessGrant($this->accessGrantId);

        $this->assertNotNull($viaEncounter);
        $this->assertSame($viaGrant, $viaEncounter);
    }

    public function test_is_inert_when_the_serving_gate_is_off(): void
    {
        config(['care-pathways.assignment_enabled' => false]);
        $service = app(PathwayInstanceReadService::class);

        $this->assertFalse($service->available());
        $this->assertNull($service->progressForAccessGrant($this->accessGrantId));
        $this->assertNull($service->progressForEncounterId(987654));
    }

    public function test_a_null_encounter_id_short_circuits_even_with_the_gate_on(): void
    {
        // The journey spine calls progressForEncounterId($encounter?->getKey());
        // a patient with no active encounter passes null — must return null
        // without touching the DB, gate on or off.
        $this->assertNull(app(PathwayInstanceReadService::class)->progressForEncounterId(null));
    }

    public function test_reads_never_mutate_the_append_only_status_history(): void
    {
        $before = DB::table('patient_experience.pathway_milestone_status_events')->count();

        app(PathwayInstanceReadService::class)->progressForAccessGrant($this->accessGrantId);
        app(PathwayInstanceReadService::class)->progressForAccessGrant($this->accessGrantId);

        $this->assertSame($before, DB::table('patient_experience.pathway_milestone_status_events')->count());
        $this->assertSame(3, $before); // one per recorded milestone, nothing added by reads
    }

    public function test_returns_null_when_the_grant_has_no_instance(): void
    {
        // A grant with no pathway assignment yields nothing (honest empty).
        $otherGrant = app(SyntheticPatientProjectionProvisioner::class)->provision('flow4d-no-instance')['grant'];

        $this->assertNull(app(PathwayInstanceReadService::class)->progressForAccessGrant((int) $otherGrant->getKey()));
    }

    private function seedThreeMilestones(): void
    {
        $rows = [
            ['stable_key' => 'arrival_m00', 'title' => 'Admitted and settled', 'phase' => 'arrival', 'sequence' => 0, 'expected_range' => ['display' => 'Today']],
            ['stable_key' => 'day_1_m01', 'title' => 'Initial workup complete', 'phase' => 'day_1', 'sequence' => 1, 'expected_range' => ['day_offset_min' => 0, 'day_offset_max' => 1]],
            ['stable_key' => 'day_2_m01', 'title' => 'Repeat imaging reviewed', 'phase' => 'day_2', 'sequence' => 2, 'expected_range' => ['day_offset_min' => 1, 'day_offset_max' => 2]],
        ];

        foreach ($rows as $row) {
            $this->milestones[$row['stable_key']] = MilestoneDefinition::query()->create([
                'pathway_version_id' => $this->version->getKey(),
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

    private function activateFixtureRelease(): void
    {
        $summary = app(CatalogImportService::class)->adopt(1, 'test-data-steward');

        DB::table('care_pathways.definitions')->update(['lifecycle_state' => 'active', 'updated_at' => now()]);
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
