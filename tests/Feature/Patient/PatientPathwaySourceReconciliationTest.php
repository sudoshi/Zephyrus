<?php

namespace Tests\Feature\Patient;

use App\Models\CarePathways\MilestoneDefinition;
use App\Models\CarePathways\PathwayStageDefinition;
use App\Models\CarePathways\PathwayVersion;
use App\Models\Patient\PatientPathwayInstance;
use App\Services\CarePathways\CatalogImportService;
use App\Services\Patient\Pathway\PatientPathwaySourceReconciliationService;
use App\Services\Patient\Pathway\Source\PatientPathwaySourceSnapshot;
use App\Services\Patient\Pathway\Source\PatientPathwaySourceStatusObservation;
use App\Services\Patient\Projection\SyntheticPatientProjectionProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\CarePathwayRawFixture;
use Tests\TestCase;

class PatientPathwaySourceReconciliationTest extends TestCase
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

    public function test_allowlisted_connector_appends_version_pinned_history_and_exact_replay_never_releases_patient_content(): void
    {
        $fixture = app(SyntheticPatientProjectionProvisioner::class)->provision('pathway-source-reconciliation');
        $this->enableSourceReconciliation();
        $version = PathwayVersion::query()->orderBy('source_rank')->firstOrFail();
        $stage = PathwayStageDefinition::query()
            ->where('pathway_version_id', $version->getKey())
            ->orderBy('display_order')
            ->firstOrFail();
        $milestone = MilestoneDefinition::query()
            ->where('pathway_version_id', $version->getKey())
            ->orderBy('sequence')
            ->firstOrFail();
        $snapshot = new PatientPathwaySourceSnapshot(
            sourceSystemKey: 'test-pathway-adapter.v1',
            sourceAssignmentReference: 'opaque-source-assignment-reference',
            pathwayVersionUuid: (string) $version->pathway_version_uuid,
            sourceObservedAt: now()->subMinutes(10),
            stageObservations: [
                new PatientPathwaySourceStatusObservation(
                    definitionStableKey: (string) $stage->stable_key,
                    status: 'current',
                    sourceEventReference: 'opaque-source-stage-event',
                    sourceObservedAt: now()->subMinutes(5),
                ),
            ],
            milestoneObservations: [
                new PatientPathwaySourceStatusObservation(
                    definitionStableKey: (string) $milestone->stable_key,
                    status: 'planned',
                    sourceEventReference: 'opaque-source-milestone-event',
                    sourceObservedAt: now()->subMinutes(2),
                ),
            ],
        );
        $service = app(PatientPathwaySourceReconciliationService::class);

        $first = $service->reconcile($fixture['grant'], $snapshot);
        $replay = $service->reconcile($fixture['grant'], $snapshot);
        $absenceIsNotCancellation = $service->reconcile($fixture['grant'], new PatientPathwaySourceSnapshot(
            sourceSystemKey: 'test-pathway-adapter.v1',
            sourceAssignmentReference: 'opaque-source-assignment-reference',
            pathwayVersionUuid: (string) $version->pathway_version_uuid,
            sourceObservedAt: now(),
        ));

        $this->assertFalse($first['assignment_replayed']);
        $this->assertSame(1, $first['stage_events_appended']);
        $this->assertSame(1, $first['milestone_events_appended']);
        $this->assertTrue($replay['assignment_replayed']);
        $this->assertSame(0, $replay['stage_events_appended']);
        $this->assertSame(0, $replay['milestone_events_appended']);
        $this->assertTrue($absenceIsNotCancellation['assignment_replayed']);
        $this->assertSame(0, $absenceIsNotCancellation['stage_events_appended']);
        $this->assertSame(0, $absenceIsNotCancellation['milestone_events_appended']);
        $this->assertSame($first['instance']->getKey(), $replay['instance']->getKey());
        $this->assertSame(1, PatientPathwayInstance::query()->count());
        $this->assertSame(1, DB::table('patient_experience.pathway_stage_status_events')->count());
        $this->assertSame(1, DB::table('patient_experience.pathway_milestone_status_events')->count());
        $this->assertSame('current', DB::table('patient_experience.current_pathway_stage_statuses')->value('status'));
        $this->assertSame('planned', DB::table('patient_experience.current_pathway_milestone_statuses')->value('status'));
        $this->assertNotSame('opaque-source-assignment-reference', (string) $first['instance']->source_assignment_digest);
        $this->assertFalse(DB::table('patient_experience.encounter_projections')
            ->where('source_version', 'test-pathway-adapter.v1')
            ->exists());
    }

    public function test_reconciliation_rejects_unapproved_sources_and_missing_gates_before_writing_history(): void
    {
        $fixture = app(SyntheticPatientProjectionProvisioner::class)->provision('pathway-source-reconciliation-gates');
        $version = PathwayVersion::query()->orderBy('source_rank')->firstOrFail();
        $snapshot = new PatientPathwaySourceSnapshot(
            sourceSystemKey: 'test-pathway-adapter.v1',
            sourceAssignmentReference: 'opaque-source-assignment-reference',
            pathwayVersionUuid: (string) $version->pathway_version_uuid,
            sourceObservedAt: now(),
        );
        $service = app(PatientPathwaySourceReconciliationService::class);

        try {
            $service->reconcile($fixture['grant'], $snapshot);
            $this->fail('Disabled reconciliation unexpectedly accepted a source snapshot.');
        } catch (RuntimeException $exception) {
            $this->assertSame('patient_pathway_source_reconciliation_unavailable', $exception->getMessage());
        }

        $this->enableSourceReconciliation([]);
        try {
            $service->reconcile($fixture['grant'], $snapshot);
            $this->fail('An unapproved connector source unexpectedly reconciled history.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('patient_pathway_source_not_approved', $exception->getMessage());
        }

        $this->assertSame(0, PatientPathwayInstance::query()->count());
        $this->assertSame(0, DB::table('patient_experience.pathway_stage_status_events')->count());
    }

    public function test_invalid_observation_rolls_back_the_entire_connector_snapshot(): void
    {
        $fixture = app(SyntheticPatientProjectionProvisioner::class)->provision('pathway-source-reconciliation-atomicity');
        $this->enableSourceReconciliation();
        $version = PathwayVersion::query()->orderBy('source_rank')->firstOrFail();
        $stage = PathwayStageDefinition::query()
            ->where('pathway_version_id', $version->getKey())
            ->orderBy('display_order')
            ->firstOrFail();
        $snapshot = new PatientPathwaySourceSnapshot(
            sourceSystemKey: 'test-pathway-adapter.v1',
            sourceAssignmentReference: 'opaque-source-assignment-reference',
            pathwayVersionUuid: (string) $version->pathway_version_uuid,
            sourceObservedAt: now()->subMinute(),
            stageObservations: [
                new PatientPathwaySourceStatusObservation(
                    definitionStableKey: (string) $stage->stable_key,
                    status: 'current',
                    sourceEventReference: 'opaque-source-stage-event',
                    sourceObservedAt: now()->subMinute(),
                ),
            ],
            milestoneObservations: [
                new PatientPathwaySourceStatusObservation(
                    definitionStableKey: 'unmapped_milestone',
                    status: 'planned',
                    sourceEventReference: 'opaque-source-milestone-event',
                    sourceObservedAt: now(),
                ),
            ],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('patient_pathway_source_milestone_definition_not_approved');
        try {
            app(PatientPathwaySourceReconciliationService::class)->reconcile($fixture['grant'], $snapshot);
        } finally {
            $this->assertSame(0, PatientPathwayInstance::query()->count());
            $this->assertSame(0, DB::table('patient_experience.pathway_stage_status_events')->count());
            $this->assertSame(0, DB::table('patient_experience.pathway_milestone_status_events')->count());
        }
    }

    /** @param list<string> $approvedSources */
    private function enableSourceReconciliation(array $approvedSources = ['test-pathway-adapter.v1']): void
    {
        config([
            'hummingbird-patient.enabled' => true,
            'hummingbird-patient.features.pathway' => true,
            'hummingbird-patient.features.pathway_source_reconciliation' => true,
            'hummingbird-patient.pathway_source_reconciliation.approved_sources' => $approvedSources,
            'care-pathways.patient_enabled' => true,
            'care-pathways.assignment_enabled' => true,
        ]);
    }

    private function activateFixtureRelease(): void
    {
        $summary = app(CatalogImportService::class)->adopt(1, 'test-data-steward');
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
