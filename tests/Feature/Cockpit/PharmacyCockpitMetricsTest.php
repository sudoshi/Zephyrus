<?php

namespace Tests\Feature\Cockpit;

use App\Models\Ops\MetricDefinition;
use App\Models\User;
use App\Services\Cockpit\MetricValueWriter;
use App\Services\Cockpit\SnapshotBuilder;
use App\Services\Cockpit\StatusEngine;
use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use App\Services\Pharmacy\PharmacyCockpitHealthService;
use App\Services\Pharmacy\PharmacyFlowBoardService;
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

final class PharmacyCockpitMetricsTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = [
        'flow.ancillary_rx_verification_queue',
        'flow.ancillary_rx_oldest_stat',
        'flow.ancillary_rx_sepsis_at_risk',
        'flow.ancillary_rx_shortage_stockouts',
    ];

    private const DRILL_KEYS = [
        'flow.ancillary_rx_verification_queue',
        'flow.ancillary_rx_oldest_stat',
        'flow.ancillary_rx_shortage_stockouts',
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

    public function test_snapshot_values_reconcile_to_the_flow_board_and_status_engine_definitions(): void
    {
        $board = app(PharmacyFlowBoardService::class)->build();
        $flowHealth = app(PharmacyFlowBoardService::class)->cockpitHealth();
        $pharmacy = app(PharmacyCockpitHealthService::class)->build();

        // The Cockpit metric values equal what the Flow Board reports for the
        // same frozen anchor (§ acceptance: Cockpit and workspace reconcile).
        $this->assertSame($board['data']['summary']['verificationQueueDepth'], $flowHealth['verificationQueue']['depth']);
        $this->assertSame($board['data']['verificationQueue']['depth'], $pharmacy['verificationQueue']['value']);
        $this->assertSame($board['data']['verificationQueue']['oldestAgeMinutes'], $pharmacy['verificationQueue']['oldestAgeMinutes']);

        $sepsisClass = collect($board['data']['clockClasses'])->firstWhere('clockClass', 'sepsis');
        $this->assertSame($sepsisClass['openBreaches'] + (int) $sepsisClass['openWarnings'], $pharmacy['sepsisAtRisk']['value']);
        $this->assertSame($flowHealth['sepsisAtRisk']['value'], $pharmacy['sepsisAtRisk']['value']);
        $this->assertSame($flowHealth['shortageStockouts']['stations'], $pharmacy['shortageStockouts']['value']);

        $payload = app(SnapshotBuilder::class)->build();
        $tiles = collect($payload['domains']['flow']['tiles'])->keyBy('key');
        $queue = $tiles->get(self::KEYS[0]);
        $stat = $tiles->get(self::KEYS[1]);
        $sepsis = $tiles->get(self::KEYS[2]);
        $stockouts = $tiles->get(self::KEYS[3]);

        $this->assertSame((float) $flowHealth['verificationQueue']['depth'], $queue['value']);
        $this->assertSame((float) $flowHealth['oldestStatAgeMinutes'], $stat['value']);
        $this->assertSame((float) $pharmacy['sepsisAtRisk']['value'], $sepsis['value']);
        $this->assertSame((float) $flowHealth['shortageStockouts']['stations'], $stockouts['value']);

        // Real-time signals are current; the sepsis signal is administration-
        // qualified but still current while the batch tail is within cadence.
        $this->assertSame('live', $queue['metadata']['provenance']);
        $this->assertSame('current', $queue['metadata']['dataState']);
        $this->assertSame('fresh', $stat['metadata']['sourceState']);
        $this->assertSame('Pharmacy administration warehouse', $sepsis['metadata']['sourceLabel']);
        $this->assertSame('batch', $sepsis['metadata']['administrationState']);
        $this->assertSame('/pharmacy?source=cockpit', $queue['metadata']['workspaceHref']);
        $this->assertSame('/pharmacy?lens=stat&source=cockpit', $stat['metadata']['workspaceHref']);
        $this->assertSame('/pharmacy?lens=sepsis&source=cockpit', $sepsis['metadata']['workspaceHref']);
        $this->assertSame('/pharmacy?lens=shortage&source=cockpit', $stockouts['metadata']['workspaceHref']);
        $this->assertNotNull($queue['metadata']['sourceCutoffAt']);

        $engine = app(StatusEngine::class);
        foreach ([$queue, $stat, $sepsis, $stockouts] as $tile) {
            $definition = MetricDefinition::query()->where('metric_key', $tile['key'])->firstOrFail();
            $this->assertSame($engine->resolveStatus($tile['value'], $definition)->value, $tile['status']);
        }
    }

    public function test_refresh_persists_aggregate_history_without_alerts_or_individual_detail(): void
    {
        $payload = app(SnapshotBuilder::class)->refresh();
        $tiles = collect($payload['domains']['flow']['tiles'])->whereIn('key', self::KEYS)->values();
        $this->assertCount(4, $tiles);
        $this->assertSame([], collect($payload['alerts'])->whereIn('key', self::KEYS)->values()->all());

        $definitions = DB::table('ops.metric_definitions')->whereIn('metric_key', self::KEYS)->get();
        $this->assertCount(4, $definitions);
        $this->assertTrue($definitions->every(fn (object $definition): bool => $definition->alert_template === null));
        $history = DB::table('ops.metric_values')->where('grain', MetricValueWriter::GRAIN)->whereIn('metric_key', self::KEYS)->get();
        $this->assertCount(4, $history);
        $this->assertTrue($history->every(fn (object $row): bool => $row->metric_definition_id !== null));

        $serialized = json_encode($tiles->pluck('metadata')->all(), JSON_THROW_ON_ERROR);
        foreach (['patientRef', 'patient_ref', 'medicationLabel', 'medication_label', 'pharmacist', 'verifier', 'user_id', 'ndc'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    public function test_flow_drill_activates_the_reserved_pharmacy_row_from_cached_server_metrics(): void
    {
        $snapshot = app(SnapshotBuilder::class)->refresh();
        $tiles = collect($snapshot['domains']['flow']['tiles'])->keyBy('key');
        $table = collect($this->actingAs(User::factory()->create())
            ->getJson('/api/cockpit/drill/flow')->assertOk()->json('tables'))
            ->firstWhere('caption', 'Ancillary operational health');

        $this->assertNotNull($table);
        $this->assertSame(['Radiology', 'Laboratory', 'Pharmacy'], collect($table['rows'])->pluck('service.v')->all());
        $pharmacy = collect($table['rows'])->firstWhere('service.v', 'Pharmacy');
        $this->assertSame($tiles->get(self::DRILL_KEYS[0])['display'], $pharmacy['measure1']['v']);
        $this->assertSame($tiles->get(self::DRILL_KEYS[1])['display'], $pharmacy['measure2']['v']);
        $this->assertSame($tiles->get(self::DRILL_KEYS[2])['display'], $pharmacy['measure3']['v']);
        $this->assertSame('/pharmacy?source=cockpit', $pharmacy['measure1']['href']);
        $this->assertSame('/pharmacy?lens=stat&source=cockpit', $pharmacy['measure2']['href']);
        $this->assertSame('/pharmacy?lens=shortage&source=cockpit', $pharmacy['measure3']['href']);
        $this->assertSame('fresh', $pharmacy['source']['tag']['text']);
        $this->assertNotSame('—', $pharmacy['cutoff']['v']);
        $this->assertStringNotContainsString('patient', strtolower(json_encode($pharmacy, JSON_THROW_ON_ERROR)));
    }

    public function test_stale_administration_cannot_create_a_false_sepsis_success_or_failure(): void
    {
        // Collapse the warehouse cadence tolerance so the demo batch tail (a few
        // hours old) is now beyond cadence and classifies stale — the append-only
        // administration ledger is never mutated to force this state.
        config()->set('integrations.ancillary.warehouse_stale_after_minutes', 1);
        Cache::forget(SnapshotBuilder::CACHE_KEY);

        $pharmacy = app(PharmacyCockpitHealthService::class)->build();
        $this->assertNull($pharmacy['sepsisAtRisk']['value']);
        $this->assertSame('stale', $pharmacy['sepsisAtRisk']['administrationState']);
        // Recorded breaches remain visible as historical facts, but no all-clear
        // (success) or new failure is asserted from the stale tail.
        $this->assertGreaterThanOrEqual(0, $pharmacy['sepsisAtRisk']['openBreaches']);

        $payload = app(SnapshotBuilder::class)->build();
        $tiles = collect($payload['domains']['flow']['tiles'])->keyBy('key');
        // A null sepsis value is skipped entirely — it can never render green or
        // fire an alert from stale administration evidence.
        $this->assertFalse($tiles->has(self::KEYS[2]));
        $this->assertSame([], collect($payload['alerts'])->where('key', self::KEYS[2])->values()->all());

        // The real-time signals (queue, oldest STAT, stockouts) are unaffected by
        // the administration tail and remain current.
        $this->assertTrue($tiles->has(self::KEYS[0]));
        $this->assertSame('fresh', $tiles->get(self::KEYS[0])['metadata']['sourceState']);
    }

    public function test_stale_pharmacy_source_neutralizes_all_last_known_metrics_and_never_shows_success(): void
    {
        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'stale']);
        Cache::forget(SnapshotBuilder::CACHE_KEY);
        $payload = app(SnapshotBuilder::class)->build();
        $tiles = collect($payload['domains']['flow']['tiles'])->whereIn('key', self::DRILL_KEYS)->values();

        $this->assertCount(3, $tiles);
        $this->assertTrue($tiles->every(fn (array $tile): bool => $tile['status'] === 'normal'));
        $this->assertTrue($tiles->every(fn (array $tile): bool => $tile['metadata']['dataState'] === 'degraded'));
        $this->assertTrue($tiles->every(fn (array $tile): bool => $tile['metadata']['sourceState'] === 'stale'));
        $this->assertTrue($tiles->every(fn (array $tile): bool => str_starts_with($tile['sub'], 'Last known')));
        $this->assertSame([], collect($payload['alerts'])->whereIn('key', self::DRILL_KEYS)->values()->all());

        app(SnapshotBuilder::class)->refresh();
        $table = collect($this->actingAs(User::factory()->create())
            ->getJson('/api/cockpit/drill/flow')->assertOk()->json('tables'))
            ->firstWhere('caption', 'Ancillary operational health');
        $pharmacy = collect($table['rows'])->firstWhere('service.v', 'Pharmacy');
        $this->assertSame('stale', $pharmacy['source']['tag']['text']);
        $this->assertSame('neutral', $pharmacy['status']['chip']);
    }
}
