<?php

namespace Tests\Feature\Cockpit;

use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Models\Ancillary\AncillaryBreach;
use App\Models\Ancillary\AncillaryMilestone;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Integration\Source;
use App\Models\Radiology\Exam;
use App\Models\Radiology\Read;
use App\Models\Radiology\Scanner;
use App\Models\Radiology\ScannerDowntime;
use App\Models\User;
use App\Services\Cockpit\MetricValueWriter;
use App\Services\Cockpit\SnapshotBuilder;
use App\Services\Cockpit\StatusEngine;
use App\Services\Radiology\RadiologyCockpitHealthService;
use App\Services\Radiology\RadiologyFlowBoardService;
use App\Services\Radiology\RadiologyReadsService;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Database\Seeders\CockpitKpiDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RadiologyCockpitMetricsTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $anchor;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anchor = CarbonImmutable::parse('2026-07-12T16:00:00Z');
        CarbonImmutable::setTestNow($this->anchor);
        Cache::forget(SnapshotBuilder::CACHE_KEY);
        $this->seed([CockpitKpiDefinitionSeeder::class, AncillaryReferenceSeeder::class]);
        $this->source = app(SourceRegistryService::class)->ensureSource([
            'source_key' => 'test.radiology.cockpit',
            'source_name' => 'Test Radiology Cockpit Source',
            'system_class' => 'radiology_reporting',
            'interface_type' => 'hl7v2',
            'active_status' => 'active',
            'phi_allowed' => false,
            'metadata' => ['ancillary_ingest' => ['enabled' => true, 'message_families' => ['ORM', 'ORU'], 'departments' => ['rad']]],
        ]);
        $this->seedRadiologyFacts();
        $this->sourceFreshness('ancillary_orders', 'current', 60, 'Radiology operational feeds');
        $this->sourceFreshness('ancillary_milestones', 'current', 60, 'Radiology reporting feeds');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_snapshot_emits_exact_radiology_aggregates_with_provenance_cutoff_and_status_engine_parity(): void
    {
        $flowBoard = app(RadiologyFlowBoardService::class);
        $flowHealth = $flowBoard->cockpitHealth();
        $board = $flowBoard->build();
        $workspace = app(RadiologyCockpitHealthService::class)->build();
        $this->assertSame($board['summary']['openBreaches'], $flowHealth['openBreaches']);
        $this->assertSame($board['scanners']['downtime'], $flowHealth['scannersDown']);
        $this->assertSame($board['scanners']['total'], $flowHealth['scannerTotal']);
        $this->assertSame($flowHealth['openBreaches'], $workspace['openBreaches']['value']);
        $this->assertSame(app(RadiologyReadsService::class)->cockpitHealth()['oldestUnreadAgeMinutes'], $workspace['oldestUnread']['value']);

        $payload = app(SnapshotBuilder::class)->build();
        $tiles = collect($payload['domains']['flow']['tiles'])->keyBy('key');
        $breaches = $tiles->get('flow.ancillary_rad_open_breaches');
        $unread = $tiles->get('flow.ancillary_rad_oldest_unread');
        $scanners = $tiles->get('flow.ancillary_rad_scanners_down');

        $this->assertSame(1.0, $breaches['value']);
        $this->assertSame(75.0, $unread['value']);
        $this->assertSame(2.0, $scanners['value']);
        $this->assertSame('live', $breaches['metadata']['provenance']);
        $this->assertSame('current', $unread['metadata']['dataState']);
        $this->assertSame('Radiology reporting feeds', $unread['metadata']['sourceLabel']);
        $this->assertSame('fresh', $scanners['metadata']['sourceState']);
        $this->assertNotNull($breaches['metadata']['sourceCutoffAt']);
        $this->assertSame('/radiology', $breaches['metadata']['workspaceHref']);
        $this->assertSame([
            ['priority' => 'stat', 'count' => 1, 'oldestAgeMinutes' => 75],
            ['priority' => 'urgent', 'count' => 1, 'oldestAgeMinutes' => 35],
        ], $unread['metadata']['unreadByPriority']);

        $engine = app(StatusEngine::class);
        foreach ([$breaches, $unread, $scanners] as $tile) {
            $definition = DB::table('ops.metric_definitions')->where('metric_key', $tile['key'])->first();
            $model = \App\Models\Ops\MetricDefinition::query()->findOrFail($definition->metric_definition_id);
            $this->assertSame($engine->resolveStatus($tile['value'], $model)->value, $tile['status']);
        }
        $this->assertSame('warn', $breaches['status']);
        $this->assertSame('crit', $unread['status']);
        $this->assertSame('crit', $scanners['status']);
    }

    public function test_refresh_persists_metric_history_but_template_gated_ancillary_values_never_enter_alert_ticker(): void
    {
        $payload = app(SnapshotBuilder::class)->refresh();
        $keys = [
            'flow.ancillary_rad_open_breaches',
            'flow.ancillary_rad_oldest_unread',
            'flow.ancillary_rad_scanners_down',
        ];

        $this->assertSame([], collect($payload['alerts'])->whereIn('key', $keys)->values()->all());
        $definitions = DB::table('ops.metric_definitions')->whereIn('metric_key', $keys)->get();
        $this->assertCount(3, $definitions);
        $this->assertTrue($definitions->every(fn (object $definition): bool => $definition->alert_template === null));

        $history = DB::table('ops.metric_values')->where('grain', MetricValueWriter::GRAIN)->whereIn('metric_key', $keys)->get();
        $this->assertCount(3, $history);
        $this->assertTrue($history->every(fn (object $row): bool => $row->metric_definition_id !== null));
        $metadata = json_decode($history->firstWhere('metric_key', 'flow.ancillary_rad_oldest_unread')->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('current', $metadata['dataState']);
        $this->assertSame('fresh', $metadata['sourceState']);
    }

    public function test_flow_drill_adds_reconciled_ancillary_health_rows_and_reserves_future_services(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/cockpit/drill/flow')
            ->assertOk();
        $table = collect($response->json('tables'))->firstWhere('caption', 'Ancillary operational health');

        $this->assertNotNull($table);
        $this->assertSame(['Radiology', 'Laboratory', 'Pharmacy'], collect($table['rows'])->pluck('service.v')->all());
        $radiology = collect($table['rows'])->firstWhere('service.v', 'Radiology');
        $this->assertSame('1 order', $radiology['measure1']['v']);
        $this->assertSame('1 hr 15 min 0 sec', $radiology['measure2']['v']);
        $this->assertSame('2 scanners', $radiology['measure3']['v']);
        $this->assertSame('fresh', $radiology['source']['tag']['text']);
        $this->assertSame('critical', $radiology['status']['chip']);
        $this->assertNotSame('—', $radiology['cutoff']['v']);

        foreach (['Laboratory', 'Pharmacy'] as $future) {
            $row = collect($table['rows'])->firstWhere('service.v', $future);
            $this->assertSame('not available', $row['source']['tag']['text']);
            $this->assertSame('neutral', $row['status']['chip']);
        }

        DB::table('ops.source_freshness')->where('source_key', 'ancillary_milestones')->update(['status' => 'stale']);
        Cache::forget(SnapshotBuilder::CACHE_KEY);
        $mixedSourceTable = collect($this->actingAs(User::factory()->create())
            ->getJson('/api/cockpit/drill/flow')->assertOk()->json('tables'))
            ->firstWhere('caption', 'Ancillary operational health');
        $this->assertSame(
            'stale',
            collect($mixedSourceTable['rows'])->firstWhere('service.v', 'Radiology')['source']['tag']['text'],
        );
    }

    public function test_stale_sources_neutralize_last_known_metrics_and_missing_report_evidence_cannot_render_success(): void
    {
        DB::table('ops.source_freshness')->whereIn('source_key', ['ancillary_orders', 'ancillary_milestones'])->update(['status' => 'stale']);
        $stale = app(SnapshotBuilder::class)->build();
        $tiles = collect($stale['domains']['flow']['tiles'])->whereIn('key', [
            'flow.ancillary_rad_open_breaches',
            'flow.ancillary_rad_oldest_unread',
            'flow.ancillary_rad_scanners_down',
        ]);

        $this->assertCount(3, $tiles);
        $this->assertTrue($tiles->every(fn (array $tile): bool => $tile['status'] === 'normal'));
        $this->assertTrue($tiles->every(fn (array $tile): bool => $tile['metadata']['dataState'] === 'degraded'));
        $this->assertTrue($tiles->every(fn (array $tile): bool => str_starts_with($tile['sub'], 'Last known')));
        $this->assertSame([], collect($stale['alerts'])->filter(
            fn (array $alert): bool => str_starts_with($alert['key'], 'flow.ancillary_rad_'),
        )->values()->all());

        // The ledger is deliberately append-only; TRUNCATE is the only valid
        // test-fixture reset and remains transactional under PostgreSQL.
        DB::statement('TRUNCATE TABLE prod.ancillary_breaches, prod.ancillary_milestones CASCADE');
        Cache::forget(SnapshotBuilder::CACHE_KEY);
        $missing = app(SnapshotBuilder::class)->build();
        $missingTiles = collect($missing['domains']['flow']['tiles'])->keyBy('key');
        $this->assertFalse($missingTiles->has('flow.ancillary_rad_oldest_unread'));
        $this->assertNotSame('ok', $missingTiles->get('flow.ancillary_rad_open_breaches')['status']);
        $this->assertNotSame('ok', $missingTiles->get('flow.ancillary_rad_scanners_down')['status']);
    }

    private function seedRadiologyFacts(): void
    {
        $stat = $this->openUnread('stat', 75);
        $this->openUnread('urgent', 35);
        AncillaryBreach::factory()->for($stat, 'order')->create();

        $finalOrder = AncillaryOrder::factory()->radiology()->terminal()->create([
            'source_id' => $this->source->source_id,
            'priority' => 'routine',
            'ordered_at' => $this->anchor->subMinutes(50),
            'source_cutoff_at' => $this->anchor->subMinutes(4),
        ]);
        $finalExam = Exam::factory()->create([
            'ancillary_order_id' => $finalOrder->ancillary_order_id,
            'source_id' => $this->source->source_id,
            'status' => 'complete',
            'started_at' => $this->anchor->subMinutes(25),
            'completed_at' => $this->anchor->subMinutes(20),
        ]);
        Read::factory()->create([
            'rad_exam_id' => $finalExam->rad_exam_id,
            'source_id' => $this->source->source_id,
            'status' => 'final',
            'final_at' => $this->anchor->subMinutes(5),
            'preliminary_at' => null,
            'corrected_at' => null,
        ]);
        $this->milestone($finalOrder, 'RAD_FINAL', 5, 4);

        $operational = Scanner::factory()->create(['source_id' => $this->source->source_id, 'label' => 'CT 1']);
        $maintenance = Scanner::factory()->create(['source_id' => $this->source->source_id, 'label' => 'CT 2']);
        Scanner::factory()->create(['source_id' => $this->source->source_id, 'label' => 'MRI 1', 'modality_code' => 'MRI', 'status' => 'downtime']);
        ScannerDowntime::factory()->active()->create([
            'rad_scanner_id' => $maintenance->rad_scanner_id,
            'source_id' => $this->source->source_id,
        ]);
        ScannerDowntime::factory()->active()->create([
            'rad_scanner_id' => $maintenance->rad_scanner_id,
            'source_id' => $this->source->source_id,
            'source_downtime_key' => 'duplicate-active-window',
        ]);
        $this->assertNotNull($operational);
    }

    private function openUnread(string $priority, int $ageMinutes): AncillaryOrder
    {
        $order = AncillaryOrder::factory()->radiology()->create([
            'source_id' => $this->source->source_id,
            'priority' => $priority,
            'ordered_at' => $this->anchor->subMinutes($ageMinutes + 30),
            'source_cutoff_at' => $this->anchor->subMinutes(2),
            'current_state' => 'acquired',
            'current_milestone_code' => 'RAD_IMAGES_AVAILABLE',
            'current_milestone_at' => $this->anchor->subMinutes($ageMinutes),
        ]);
        Exam::factory()->create([
            'ancillary_order_id' => $order->ancillary_order_id,
            'source_id' => $this->source->source_id,
            'status' => 'complete',
            'started_at' => $this->anchor->subMinutes($ageMinutes + 15),
            'completed_at' => $this->anchor->subMinutes($ageMinutes),
        ]);
        $this->milestone($order, 'RAD_EXAM_END', $ageMinutes, $ageMinutes);
        $this->milestone($order, 'RAD_IMAGES_AVAILABLE', $ageMinutes, $ageMinutes);

        return $order;
    }

    private function milestone(AncillaryOrder $order, string $code, int $occurredMinutesAgo, int $receivedMinutesAgo): void
    {
        AncillaryMilestone::factory()->for($order, 'order')->create([
            'source_id' => $this->source->source_id,
            'milestone_code' => $code,
            'occurred_at' => $this->anchor->subMinutes($occurredMinutesAgo),
            'received_at' => $this->anchor->subMinutes($receivedMinutesAgo),
            'assertion_key' => 'cockpit-'.Str::uuid(),
        ]);
    }

    private function sourceFreshness(string $key, string $status, int $warning, string $label): void
    {
        DB::table('ops.source_freshness')->updateOrInsert(['source_key' => $key], [
            'source_label' => $label,
            'source_schema' => 'prod',
            'source_table' => $key,
            'freshness_column' => 'received_at',
            'latest_observed_at' => $this->anchor,
            'expected_lag_minutes' => 15,
            'warning_lag_minutes' => $warning,
            'record_count' => 1,
            'status' => $status,
            'checked_at' => $this->anchor,
            'metadata' => '{}',
            'created_at' => $this->anchor,
            'updated_at' => $this->anchor,
        ]);
    }
}
