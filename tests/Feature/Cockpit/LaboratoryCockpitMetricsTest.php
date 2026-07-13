<?php

namespace Tests\Feature\Cockpit;

use App\Models\Ops\MetricDefinition;
use App\Models\User;
use App\Services\Cockpit\MetricValueWriter;
use App\Services\Cockpit\SnapshotBuilder;
use App\Services\Cockpit\StatusEngine;
use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use App\Services\Lab\LabCockpitHealthService;
use App\Services\Lab\LabDecisionPendingService;
use App\Services\Lab\LabFlowBoardService;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Database\Seeders\CaseManagementSeeder;
use Database\Seeders\CockpitKpiDefinitionSeeder;
use Database\Seeders\CommandCenterDemoSeeder;
use Database\Seeders\RtdcSeeder;
use Database\Seeders\StaffingReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LaboratoryCockpitMetricsTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = [
        'flow.ancillary_lab_stat_compliance',
        'flow.ancillary_lab_oldest_decision_pending',
        'flow.ancillary_lab_critical_callbacks',
    ];

    private CarbonImmutable $anchor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anchor = CarbonImmutable::parse('2026-07-11T14:00:00Z');
        CarbonImmutable::setTestNow($this->anchor);
        Cache::forget(SnapshotBuilder::CACHE_KEY);
        $this->seed([
            RtdcSeeder::class,
            CaseManagementSeeder::class,
            StaffingReferenceSeeder::class,
            CommandCenterDemoSeeder::class,
            CockpitKpiDefinitionSeeder::class,
            AncillaryReferenceSeeder::class,
        ]);
        app(AncillaryDemoScenarioService::class)->refresh(new DemoClock($this->anchor));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_snapshot_values_reconcile_to_workspace_services_and_status_engine_definitions(): void
    {
        $flowBoard = app(LabFlowBoardService::class)->build();
        $flowHealth = app(LabFlowBoardService::class)->cockpitHealth();
        $pendingBoard = app(LabDecisionPendingService::class)->build();
        $pendingHealth = app(LabDecisionPendingService::class)->cockpitHealth();
        $laboratory = app(LabCockpitHealthService::class)->build();
        $oldest = (int) collect($pendingBoard['destinationAggregates'])->max('oldestAgeMinutes');

        $this->assertSame($flowBoard['summary']['statCompliancePercent'], $flowHealth['statCompliancePercent']);
        $this->assertSame($flowBoard['summary']['statOrders'], $flowHealth['statOrders']);
        $this->assertSame($flowBoard['summary']['statCompliant'], $flowHealth['statCompliant']);
        $this->assertSame($flowBoard['criticalCallbacks']['open'], $flowHealth['criticalCallbacks']['open']);
        $this->assertSame($oldest, $pendingHealth['oldestAgeMinutes']);
        $this->assertSame($pendingBoard['summary']['resolvedBeforeLimit'], $pendingHealth['pendingCount']);
        $this->assertSame($flowHealth['statCompliancePercent'], $laboratory['statCompliance']['value']);
        $this->assertSame($pendingHealth['oldestAgeMinutes'], $laboratory['oldestDecisionPending']['value']);
        $this->assertSame($flowHealth['criticalCallbacks']['open'], $laboratory['criticalCallbacks']['value']);
        $this->assertSame($laboratory['criticalCallbacks']['value'], $laboratory['criticalCallbacks']['atRiskCount']);

        $tiles = collect(app(SnapshotBuilder::class)->build()['domains']['flow']['tiles'])->keyBy('key');
        $stat = $tiles->get(self::KEYS[0]);
        $pending = $tiles->get(self::KEYS[1]);
        $callbacks = $tiles->get(self::KEYS[2]);
        $this->assertSame((float) $flowBoard['summary']['statCompliancePercent'], $stat['value']);
        $this->assertSame((float) $oldest, $pending['value']);
        $this->assertSame((float) $flowBoard['criticalCallbacks']['open'], $callbacks['value']);
        $this->assertSame('live', $stat['metadata']['provenance']);
        $this->assertSame('current', $pending['metadata']['dataState']);
        $this->assertSame('fresh', $callbacks['metadata']['sourceState']);
        $this->assertSame('/lab', $stat['metadata']['workspaceHref']);
        $this->assertSame($pendingHealth['byDecisionClass'], $pending['metadata']['byDecisionClass']);
        $this->assertSame($flowHealth['criticalCallbacks']['byState'], $callbacks['metadata']['byState']);
        $this->assertNotNull($stat['metadata']['sourceCutoffAt']);

        $engine = app(StatusEngine::class);
        foreach ([$stat, $pending, $callbacks] as $tile) {
            $definition = MetricDefinition::query()->where('metric_key', $tile['key'])->firstOrFail();
            $this->assertSame($engine->resolveStatus($tile['value'], $definition)->value, $tile['status']);
        }
        $this->assertSame('crit', $stat['status']);
        $this->assertSame('crit', $pending['status']);
        $this->assertSame('warn', $callbacks['status']);

        foreach ($pendingBoard['data'] as $item) {
            match ($item['decisionClass']) {
                'ed_disposition' => DB::table('prod.ed_visits')->where('ed_visit_id', $item['destination']['id'])->update(['departed_at' => $this->anchor]),
                'discharge_gate' => DB::table('prod.encounters')->where('encounter_id', $item['destination']['id'])->update(['discharged_at' => $this->anchor, 'status' => 'discharged']),
                'or_gate' => DB::table('prod.or_cases')->where('case_id', $item['destination']['id'])->update(['is_deleted' => true]),
            };
        }
        $unresolved = app(LabCockpitHealthService::class)->build()['oldestDecisionPending'];
        $this->assertSame(0, $unresolved['pendingCount']);
        $this->assertSame('degraded', $unresolved['sourceState']);
        $this->assertNull($unresolved['value']);
    }

    public function test_refresh_persists_aggregate_history_without_alerts_or_individual_result_detail(): void
    {
        $payload = app(SnapshotBuilder::class)->refresh();
        $tiles = collect($payload['domains']['flow']['tiles'])->whereIn('key', self::KEYS)->values();
        $this->assertCount(3, $tiles);
        $this->assertSame([], collect($payload['alerts'])->whereIn('key', self::KEYS)->values()->all());

        $definitions = DB::table('ops.metric_definitions')->whereIn('metric_key', self::KEYS)->get();
        $this->assertCount(3, $definitions);
        $this->assertTrue($definitions->every(fn (object $definition): bool => $definition->alert_template === null));
        $history = DB::table('ops.metric_values')->where('grain', MetricValueWriter::GRAIN)->whereIn('metric_key', self::KEYS)->get();
        $this->assertCount(3, $history);
        $this->assertTrue($history->every(fn (object $row): bool => $row->metric_definition_id !== null));

        $serialized = json_encode($tiles->pluck('metadata')->all(), JSON_THROW_ON_ERROR);
        foreach (['patientRef', 'patient_id', 'resultUuid', 'specimenUuid', 'resultContent', 'sourceCriticalKey'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
        $this->assertStringContainsString('byDecisionClass', $serialized);
        $this->assertStringContainsString('byState', $serialized);
    }

    public function test_flow_drill_activates_the_reserved_laboratory_row_from_cached_server_metrics(): void
    {
        $snapshot = app(SnapshotBuilder::class)->refresh();
        $tiles = collect($snapshot['domains']['flow']['tiles'])->keyBy('key');
        $table = collect($this->actingAs(User::factory()->create())
            ->getJson('/api/cockpit/drill/flow')->assertOk()->json('tables'))
            ->firstWhere('caption', 'Ancillary operational health');

        $this->assertNotNull($table);
        $this->assertSame(['Radiology', 'Laboratory', 'Pharmacy'], collect($table['rows'])->pluck('service.v')->all());
        $lab = collect($table['rows'])->firstWhere('service.v', 'Laboratory');
        $this->assertSame($tiles->get(self::KEYS[0])['display'], $lab['measure1']['v']);
        $this->assertSame($tiles->get(self::KEYS[1])['display'], $lab['measure2']['v']);
        $this->assertSame($tiles->get(self::KEYS[2])['display'], $lab['measure3']['v']);
        $this->assertSame('fresh', $lab['source']['tag']['text']);
        $this->assertSame('critical', $lab['status']['chip']);
        $this->assertNotSame('—', $lab['cutoff']['v']);

        $pharmacy = collect($table['rows'])->firstWhere('service.v', 'Pharmacy');
        $this->assertSame('not available', $pharmacy['source']['tag']['text']);
        $this->assertSame('neutral', $pharmacy['status']['chip']);
        $this->assertStringNotContainsString('patient', strtolower(json_encode($lab, JSON_THROW_ON_ERROR)));
        $this->assertStringNotContainsString('resultuuid', strtolower(json_encode($lab, JSON_THROW_ON_ERROR)));
    }

    public function test_stale_laboratory_evidence_is_last_known_neutral_and_never_successful(): void
    {
        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'stale']);
        Cache::forget(SnapshotBuilder::CACHE_KEY);
        $payload = app(SnapshotBuilder::class)->build();
        $tiles = collect($payload['domains']['flow']['tiles'])->whereIn('key', self::KEYS)->values();

        $this->assertCount(3, $tiles);
        $this->assertTrue($tiles->every(fn (array $tile): bool => $tile['status'] === 'normal'));
        $this->assertTrue($tiles->every(fn (array $tile): bool => $tile['metadata']['dataState'] === 'degraded'));
        $this->assertTrue($tiles->every(fn (array $tile): bool => $tile['metadata']['sourceState'] === 'stale'));
        $this->assertTrue($tiles->every(fn (array $tile): bool => str_starts_with($tile['sub'], 'Last known')));
        $this->assertSame([], collect($payload['alerts'])->whereIn('key', self::KEYS)->values()->all());

        app(SnapshotBuilder::class)->refresh();
        $table = collect($this->actingAs(User::factory()->create())
            ->getJson('/api/cockpit/drill/flow')->assertOk()->json('tables'))
            ->firstWhere('caption', 'Ancillary operational health');
        $lab = collect($table['rows'])->firstWhere('service.v', 'Laboratory');
        $this->assertSame('stale', $lab['source']['tag']['text']);
        $this->assertSame('neutral', $lab['status']['chip']);
    }
}
