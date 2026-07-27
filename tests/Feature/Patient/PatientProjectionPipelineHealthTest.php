<?php

namespace Tests\Feature\Patient;

use App\Models\CarePathways\MilestoneDefinition;
use App\Models\CarePathways\PathwayStageDefinition;
use App\Models\CarePathways\PathwayVersion;
use App\Models\Patient\PatientPathwayInstance;
use App\Models\Patient\PatientProjectionFailure;
use App\Services\Admin\SystemHealthService;
use App\Services\CarePathways\CatalogImportService;
use App\Services\Patient\Pathway\PatientPathwaySourceReconciliationService;
use App\Services\Patient\Pathway\Source\PatientPathwaySourceSnapshot;
use App\Services\Patient\Pathway\Source\PatientPathwaySourceStatusObservation;
use App\Services\Patient\Projection\PatientPathwayHistoryDraftService;
use App\Services\Patient\Projection\SyntheticPatientProjectionProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CarePathwayRawFixture;
use Tests\TestCase;

final class PatientProjectionPipelineHealthTest extends TestCase
{
    use CarePathwayRawFixture;
    use RefreshDatabase;

    public function test_monitor_is_healthy_when_patient_pathway_governance_is_disabled(): void
    {
        $snapshot = app(SystemHealthService::class)->collect('scheduled');
        $component = collect($snapshot['observations'])->firstWhere('key', 'patient_projection_pipeline');

        $this->assertSame('healthy', $component['status']);
        $this->assertFalse($component['details']['monitoringEnabled']);
        $this->assertSame(0, $component['details']['enabledGateCount']);
        $this->assertSame(4, $component['details']['requiredGateCount']);
    }

    public function test_monitor_warns_when_only_some_patient_pathway_governance_gates_are_enabled(): void
    {
        config(['nightingale.enabled' => true]);

        $snapshot = app(SystemHealthService::class)->collect('scheduled');
        $component = collect($snapshot['observations'])->firstWhere('key', 'patient_projection_pipeline');

        $this->assertSame('warning', $component['status']);
        $this->assertSame('patient_projection_monitoring_prerequisites_incomplete', $component['errorCode']);
        $this->assertFalse($component['details']['monitoringEnabled']);
        $this->assertSame(1, $component['details']['enabledGateCount']);
    }

    public function test_monitor_critically_detects_an_observed_pathway_without_a_draft_projection(): void
    {
        $this->provisionObservedPathway(now()->subMinute());

        $snapshot = app(SystemHealthService::class)->collect('scheduled');
        $component = collect($snapshot['observations'])->firstWhere('key', 'patient_projection_pipeline');

        $this->assertSame('critical', $component['status']);
        $this->assertSame('patient_projection_freshness_critical', $component['errorCode']);
        $this->assertSame(1, $component['details']['latestDraftProjectionCounts']['expected']);
        $this->assertSame(1, $component['details']['latestDraftProjectionCounts']['missing']);
        $this->assertSame(0, $component['details']['latestDraftProjectionCounts']['behindSource']);
    }

    public function test_monitor_is_healthy_for_a_current_draft_that_matches_source_history(): void
    {
        $instance = $this->provisionObservedPathway(now()->subMinute());
        app(PatientPathwayHistoryDraftService::class)->draft($instance);

        $snapshot = app(SystemHealthService::class)->collect('scheduled');
        $component = collect($snapshot['observations'])->firstWhere('key', 'patient_projection_pipeline');

        $this->assertSame('healthy', $component['status']);
        $this->assertSame(1, $component['details']['latestDraftProjectionCounts']['expected']);
        $this->assertSame(0, $component['details']['latestDraftProjectionCounts']['missing']);
        $this->assertSame(0, $component['details']['latestDraftProjectionCounts']['behindSource']);
        $this->assertSame(1, $component['details']['latestDraftProjectionCounts']['current']);
    }

    public function test_monitor_critically_detects_a_draft_that_lags_newer_source_history(): void
    {
        $instance = $this->provisionObservedPathway(now()->subMinutes(2));
        app(PatientPathwayHistoryDraftService::class)->draft($instance);
        $version = $instance->pathwayVersion()->firstOrFail();
        $stage = PathwayStageDefinition::query()
            ->where('pathway_version_id', $version->getKey())
            ->orderBy('display_order')
            ->firstOrFail();
        $milestone = MilestoneDefinition::query()
            ->where('pathway_version_id', $version->getKey())
            ->orderBy('sequence')
            ->firstOrFail();
        $newObservationAt = now();

        app(PatientPathwaySourceReconciliationService::class)->reconcile(
            $instance->accessGrant()->firstOrFail(),
            new PatientPathwaySourceSnapshot(
                sourceSystemKey: 'test-pathway-adapter.v1',
                sourceAssignmentReference: 'opaque-source-assignment-reference',
                pathwayVersionUuid: (string) $version->pathway_version_uuid,
                sourceObservedAt: $newObservationAt,
                stageObservations: [
                    new PatientPathwaySourceStatusObservation(
                        definitionStableKey: (string) $stage->stable_key,
                        status: 'current',
                        sourceEventReference: 'opaque-source-stage-event-v2',
                        sourceObservedAt: $newObservationAt,
                    ),
                ],
                milestoneObservations: [
                    new PatientPathwaySourceStatusObservation(
                        definitionStableKey: (string) $milestone->stable_key,
                        status: 'planned',
                        sourceEventReference: 'opaque-source-milestone-event-v2',
                        sourceObservedAt: $newObservationAt,
                    ),
                ],
            ),
        );

        $snapshot = app(SystemHealthService::class)->collect('scheduled');
        $component = collect($snapshot['observations'])->firstWhere('key', 'patient_projection_pipeline');

        $this->assertSame('critical', $component['status']);
        $this->assertSame('patient_projection_freshness_critical', $component['errorCode']);
        $this->assertSame(0, $component['details']['latestDraftProjectionCounts']['missing']);
        $this->assertSame(1, $component['details']['latestDraftProjectionCounts']['behindSource']);
    }

    public function test_monitor_critically_detects_repeated_recent_draft_failures(): void
    {
        $instance = $this->provisionObservedPathway(now()->subMinute());
        app(PatientPathwayHistoryDraftService::class)->draft($instance);

        foreach (['retryable', 'manual_review', 'terminal'] as $index => $retryability) {
            PatientProjectionFailure::query()->create([
                'failure_uuid' => (string) Str::uuid(),
                'source_system_key' => 'care-pathways.pathway-history-v1',
                'projection_kind' => 'pathway',
                'failure_code' => 'patient_pathway_health_fixture_'.$index,
                'retryability' => $retryability,
                'attempt_number' => 1,
                'occurred_at' => now(),
                'context' => ['schema_version' => 1, 'content_included' => false],
            ]);
        }

        $snapshot = app(SystemHealthService::class)->collect('scheduled');
        $component = collect($snapshot['observations'])->firstWhere('key', 'patient_projection_pipeline');

        $this->assertSame('critical', $component['status']);
        $this->assertSame('patient_projection_failures_critical', $component['errorCode']);
        $this->assertSame(3, $component['details']['recentFailureCounts']['total']);
        $this->assertSame(1, $component['details']['recentFailureCounts']['retryable']);
        $this->assertSame(1, $component['details']['recentFailureCounts']['manualReview']);
        $this->assertSame(1, $component['details']['recentFailureCounts']['terminal']);
    }

    public function test_monitor_records_only_aggregate_critical_freshness_evidence_and_alerts(): void
    {
        $instance = $this->provisionObservedPathway(now()->subMinutes(245));
        app(PatientPathwayHistoryDraftService::class)->draft($instance);

        $snapshot = app(SystemHealthService::class)->collect('scheduled');
        $component = collect($snapshot['observations'])->firstWhere('key', 'patient_projection_pipeline');

        $this->assertSame('critical', $component['status']);
        $this->assertSame('patient_projection_freshness_critical', $component['errorCode']);
        $this->assertTrue($component['details']['monitoringEnabled']);
        $this->assertSame(1, $component['details']['effectivePathwayInstances']);
        $this->assertSame(1, $component['details']['observedPathwayInstances']);
        $this->assertSame(0, $component['details']['unobservedPathwayInstances']);
        $this->assertSame(0, $component['details']['latestDraftProjectionCounts']['missing']);
        $this->assertSame(1, $component['details']['latestDraftProjectionCounts']['stale']);
        $this->assertGreaterThanOrEqual(240, $component['details']['oldestObservationAgeMinutes']);
        $this->assertStringNotContainsString(
            'opaque-source-assignment-reference',
            json_encode($component['details'], JSON_THROW_ON_ERROR),
        );
        $this->assertStringNotContainsString(
            'opaque-source-stage-event',
            json_encode($component['details'], JSON_THROW_ON_ERROR),
        );
        $this->assertDatabaseHas('integration.operational_alert_deliveries', [
            'alert_domain' => 'system_health',
            'alert_code' => 'system_health_component_critical',
            'subject_type' => 'system_health_component',
            'subject_reference' => 'patient_projection_pipeline',
        ]);
    }

    private function provisionObservedPathway(Carbon $sourceObservedAt): PatientPathwayInstance
    {
        [$fixture, $version, $stage, $milestone] = $this->prepareObservedPathway();
        config(['nightingale.policy_version' => (string) $fixture['policy']->version]);

        return app(PatientPathwaySourceReconciliationService::class)->reconcile(
            $fixture['grant'],
            new PatientPathwaySourceSnapshot(
                sourceSystemKey: 'test-pathway-adapter.v1',
                sourceAssignmentReference: 'opaque-source-assignment-reference',
                pathwayVersionUuid: (string) $version->pathway_version_uuid,
                sourceObservedAt: $sourceObservedAt,
                stageObservations: [
                    new PatientPathwaySourceStatusObservation(
                        definitionStableKey: (string) $stage->stable_key,
                        status: 'current',
                        sourceEventReference: 'opaque-source-stage-event',
                        sourceObservedAt: $sourceObservedAt,
                    ),
                ],
                milestoneObservations: [
                    new PatientPathwaySourceStatusObservation(
                        definitionStableKey: (string) $milestone->stable_key,
                        status: 'planned',
                        sourceEventReference: 'opaque-source-milestone-event',
                        sourceObservedAt: $sourceObservedAt,
                    ),
                ],
            ),
        )['instance'];
    }

    /** @return array{array{grant: \App\Models\Patient\PatientEncounterAccessGrant, policy: \App\Models\Patient\PatientReleasePolicyVersion}, PathwayVersion, PathwayStageDefinition, MilestoneDefinition} */
    private function prepareObservedPathway(): array
    {
        $this->configureCarePathwayFixture();
        $this->seedCarePathwayRawFixture();
        $this->activateFixtureRelease();
        $this->enablePathwayDraftMonitoring();

        $fixture = app(SyntheticPatientProjectionProvisioner::class)->provision('patient-projection-health');
        $version = PathwayVersion::query()->orderBy('source_rank')->firstOrFail();
        $stage = PathwayStageDefinition::query()
            ->where('pathway_version_id', $version->getKey())
            ->orderBy('display_order')
            ->firstOrFail();
        $milestone = MilestoneDefinition::query()
            ->where('pathway_version_id', $version->getKey())
            ->orderBy('sequence')
            ->firstOrFail();

        return [$fixture, $version, $stage, $milestone];
    }

    private function enablePathwayDraftMonitoring(): void
    {
        config([
            'nightingale.enabled' => true,
            'nightingale.features.pathway' => true,
            'nightingale.features.pathway_history_drafts' => true,
            'nightingale.features.pathway_source_reconciliation' => true,
            'nightingale.pathway_source_reconciliation.approved_sources' => ['test-pathway-adapter.v1'],
            'care-pathways.patient_enabled' => true,
            'care-pathways.assignment_enabled' => true,
        ]);
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
