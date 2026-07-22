<?php

namespace Tests\Feature\Patient;

use App\Models\CarePathways\MilestoneDefinition;
use App\Models\CarePathways\PathwayStageDefinition;
use App\Models\CarePathways\PathwayVersion;
use App\Models\Patient\PatientPathwayInstance;
use App\Models\Patient\PatientPathwayStageInstance;
use App\Services\CarePathways\CatalogImportService;
use App\Services\Patient\Pathway\PatientPathwayInstanceService;
use App\Services\Patient\Projection\PatientPathwayHistoryDraftService;
use App\Services\Patient\Projection\SyntheticPatientProjectionProvisioner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Support\CarePathwayRawFixture;
use Tests\TestCase;

class PatientPathwayInstanceHistoryTest extends TestCase
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

    public function test_instance_pins_one_approved_version_and_preserves_append_only_stage_and_milestone_history(): void
    {
        $fixture = app(SyntheticPatientProjectionProvisioner::class)->provision('pathway-history');
        $version = PathwayVersion::query()->orderBy('source_rank')->firstOrFail();
        [$stage, $otherVersionStage] = $this->stageDefinitions();
        $milestone = MilestoneDefinition::query()->where('pathway_version_id', $version->getKey())->firstOrFail();
        $service = app(PatientPathwayInstanceService::class);

        $instance = $service->instantiate(
            $fixture['grant'],
            $version,
            'test-pathway-adapter',
            'encounter-pathway-assignment-opaque-reference',
            now()->subMinutes(5),
        );
        $replay = $service->instantiate(
            $fixture['grant'],
            $version,
            'test-pathway-adapter',
            'encounter-pathway-assignment-opaque-reference',
            now()->subMinutes(5),
        );

        $planned = $service->recordStageStatus(
            $instance,
            $stage,
            'planned',
            'stage-event-planned-opaque-reference',
            now()->subMinutes(4),
        );
        $completed = $service->recordStageStatus(
            $instance,
            $stage,
            'completed',
            'stage-event-completed-opaque-reference',
            now()->subMinutes(2),
        );
        $milestoneEvent = $service->recordMilestoneStatus(
            $instance,
            $milestone,
            'current',
            'milestone-event-current-opaque-reference',
            now()->subMinute(),
        );

        $this->assertSame($instance->getKey(), $replay->getKey());
        $this->assertSame((int) $version->getKey(), (int) $instance->pathway_version_id);
        $this->assertSame(1, PatientPathwayInstance::query()->count());
        $this->assertSame(2, DB::table('patient_experience.pathway_stage_status_events')->count());
        $this->assertSame(1, DB::table('patient_experience.pathway_milestone_status_events')->count());
        $this->assertSame('completed', DB::table('patient_experience.current_pathway_stage_statuses')
            ->where('pathway_stage_instance_id', $completed->pathway_stage_instance_id)
            ->value('status'));
        $this->assertSame('current', DB::table('patient_experience.current_pathway_milestone_statuses')
            ->where('pathway_milestone_instance_id', $milestoneEvent->pathway_milestone_instance_id)
            ->value('status'));
        $this->assertNotSame('encounter-pathway-assignment-opaque-reference', (string) $instance->source_assignment_digest);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('patient_pathway_stage_definition_not_usable');
        $service->recordStageStatus(
            $instance,
            $otherVersionStage,
            'planned',
            'must-not-cross-version',
            now(),
        );

        unset($planned);
    }

    public function test_database_rejects_in_place_definition_or_history_mutation_and_cross_version_membership(): void
    {
        $fixture = app(SyntheticPatientProjectionProvisioner::class)->provision('pathway-history-guards');
        $version = PathwayVersion::query()->orderBy('source_rank')->firstOrFail();
        [$stage, $otherVersionStage] = $this->stageDefinitions();
        $service = app(PatientPathwayInstanceService::class);
        $instance = $service->instantiate(
            $fixture['grant'],
            $version,
            'test-pathway-adapter',
            'guard-assignment-opaque-reference',
            now()->subMinute(),
        );
        $event = $service->recordStageStatus(
            $instance,
            $stage,
            'current',
            'guard-stage-event-opaque-reference',
            now(),
        );

        DB::statement('SAVEPOINT pathway_stage_definition_immutable');
        try {
            DB::table('care_pathways.stage_definitions')
                ->where('stage_definition_id', $stage->getKey())
                ->update(['approved_label' => 'Rewritten historical wording']);
            $this->fail('Stage definition mutation unexpectedly succeeded.');
        } catch (QueryException $exception) {
            DB::statement('ROLLBACK TO SAVEPOINT pathway_stage_definition_immutable');
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        DB::statement('SAVEPOINT pathway_stage_event_append_only');
        try {
            DB::table('patient_experience.pathway_stage_status_events')
                ->where('pathway_stage_status_event_id', $event->getKey())
                ->update(['status' => 'completed']);
            $this->fail('Stage status history mutation unexpectedly succeeded.');
        } catch (QueryException $exception) {
            DB::statement('ROLLBACK TO SAVEPOINT pathway_stage_event_append_only');
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        DB::statement('SAVEPOINT pathway_stage_cross_version');
        try {
            PatientPathwayStageInstance::query()->create([
                'pathway_instance_id' => $instance->getKey(),
                'stage_definition_id' => $otherVersionStage->getKey(),
                'instantiated_at' => now(),
            ]);
            $this->fail('Cross-version stage membership unexpectedly succeeded.');
        } catch (QueryException $exception) {
            DB::statement('ROLLBACK TO SAVEPOINT pathway_stage_cross_version');
            $this->assertStringContainsString('pinned pathway version', $exception->getMessage());
        }
    }

    public function test_approved_version_pinned_history_creates_one_draft_only_patient_pathway_projection(): void
    {
        $fixture = app(SyntheticPatientProjectionProvisioner::class)->provision('pathway-history-draft');
        config([
            'hummingbird-patient.enabled' => true,
            'hummingbird-patient.features.pathway' => true,
            'hummingbird-patient.features.pathway_history_drafts' => true,
            'hummingbird-patient.policy_version' => (string) $fixture['policy']->version,
            'care-pathways.patient_enabled' => true,
        ]);
        $version = PathwayVersion::query()->orderBy('source_rank')->firstOrFail();
        [$stage] = $this->stageDefinitions();
        $milestone = MilestoneDefinition::query()
            ->where('pathway_version_id', $version->getKey())
            ->firstOrFail();
        $history = app(PatientPathwayInstanceService::class);
        $instance = $history->instantiate(
            $fixture['grant'],
            $version,
            'test-pathway-adapter',
            'draft-assignment-opaque-reference',
            now()->subMinutes(15),
        );
        $history->recordStageStatus(
            $instance,
            $stage,
            'current',
            'draft-stage-event-opaque-reference',
            now()->subMinutes(10),
        );
        $history->recordMilestoneStatus(
            $instance,
            $milestone,
            'planned',
            'draft-milestone-event-opaque-reference',
            now()->subMinutes(5),
        );

        $first = app(PatientPathwayHistoryDraftService::class)->draft($instance);
        $projection = $first['projection'];

        $this->assertFalse($first['replayed']);
        $this->assertSame('draft', $projection->release_state);
        $this->assertNull($projection->released_at);
        $this->assertSame('My Path', $projection->content['headline']);
        $this->assertSame('Arriving and getting settled', $projection->content['current_stage']);
        $this->assertSame('current', $projection->content['stages'][0]['status']);
        $this->assertSame('planned', $projection->content['milestones'][0]['status']);
        $this->assertTrue($projection->content['milestones'][0]['can_change']);
        $this->assertArrayNotHasKey('source_assignment_digest', $projection->content);
        $this->assertArrayNotHasKey('source_event_digest', $projection->content);
        $this->assertArrayNotHasKey('pathway_instance_uuid', $projection->content);
        $this->assertDatabaseMissing('patient_experience.encounter_projections', [
            'encounter_projection_id' => $projection->getKey(),
            'release_state' => 'released',
        ]);

        $replay = app(PatientPathwayHistoryDraftService::class)->draft($instance);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($projection->getKey(), $replay['projection']->getKey());
        $this->assertSame(1, DB::table('patient_experience.encounter_projections')
            ->where('access_grant_id', $fixture['grant']->getKey())
            ->where('projection_kind', 'pathway')
            ->where('release_state', 'draft')
            ->count());
        $this->assertSame(1, DB::table('patient_experience.source_projection_cursors')
            ->where('source_system_key', 'care-pathways.pathway-history-v1')
            ->where('projection_kind', 'pathway')
            ->count());
    }

    public function test_pathway_history_draft_service_fails_closed_without_each_required_gate(): void
    {
        $fixture = app(SyntheticPatientProjectionProvisioner::class)->provision('pathway-history-draft-gates');
        $version = PathwayVersion::query()->orderBy('source_rank')->firstOrFail();
        $instance = app(PatientPathwayInstanceService::class)->instantiate(
            $fixture['grant'],
            $version,
            'test-pathway-adapter',
            'draft-gate-assignment-opaque-reference',
            now()->subMinute(),
        );

        config([
            'hummingbird-patient.enabled' => false,
            'hummingbird-patient.features.pathway' => true,
            'hummingbird-patient.features.pathway_history_drafts' => true,
            'care-pathways.patient_enabled' => true,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('patient_pathway_history_drafts_unavailable');
        app(PatientPathwayHistoryDraftService::class)->draft($instance);
    }

    public function test_pathway_history_draft_worker_records_only_content_free_failure_for_unprojectable_input(): void
    {
        $fixture = app(SyntheticPatientProjectionProvisioner::class)->provision('pathway-history-draft-failure');
        config([
            'hummingbird-patient.enabled' => true,
            'hummingbird-patient.features.pathway' => true,
            'hummingbird-patient.features.pathway_history_drafts' => true,
            'hummingbird-patient.policy_version' => (string) $fixture['policy']->version,
            'care-pathways.patient_enabled' => true,
        ]);
        $version = PathwayVersion::query()->orderBy('source_rank')->firstOrFail();
        app(PatientPathwayInstanceService::class)->instantiate(
            $fixture['grant'],
            $version,
            'test-pathway-adapter',
            'draft-failure-assignment-opaque-reference',
            now()->subMinute(),
        );

        $result = app(PatientPathwayHistoryDraftService::class)->draftPending(1);

        $this->assertSame([
            'selected' => 1,
            'drafted' => 0,
            'replayed' => 0,
            'failed' => 1,
        ], $result);
        $failure = DB::table('patient_experience.source_projection_failures')
            ->where('source_system_key', 'care-pathways.pathway-history-v1')
            ->where('projection_kind', 'pathway')
            ->sole();
        $this->assertSame('pathway_history_draft_input_rejected', $failure->failure_code);
        $this->assertSame('manual_review', $failure->retryability);
        $context = json_decode($failure->context, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $context['schema_version']);
        $this->assertFalse($context['content_included']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $context['instance_digest']);
        $this->assertStringNotContainsString('draft-failure-assignment-opaque-reference', $failure->context);
        $this->assertSame(0, DB::table('patient_experience.encounter_projections')
            ->where('access_grant_id', $fixture['grant']->getKey())
            ->where('projection_kind', 'pathway')
            ->where('release_state', 'draft')
            ->count());
    }

    /** @return array{PathwayStageDefinition, PathwayStageDefinition} */
    private function stageDefinitions(): array
    {
        $versions = PathwayVersion::query()->orderBy('source_rank')->get();
        $definitions = $versions->map(function (PathwayVersion $version, int $index): PathwayStageDefinition {
            return PathwayStageDefinition::query()->firstOrCreate(
                ['pathway_version_id' => $version->getKey(), 'stable_key' => 'arrival_stage'],
                [
                    'stage_uuid' => (string) Str::uuid(),
                    'display_order' => 1,
                    'approved_label' => 'Arriving and getting settled',
                    'approved_explanation' => 'Your team helps you get settled and reviews the next steps.',
                    'expected_range' => ['display' => 'Today'],
                    'review_state' => 'approved',
                    'content_digest' => hash('sha256', 'arrival-stage-'.$index),
                ],
            );
        });

        return [$definitions->get(0), $definitions->get(1)];
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
