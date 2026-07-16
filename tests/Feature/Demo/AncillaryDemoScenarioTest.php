<?php

namespace Tests\Feature\Demo;

use App\Domain\Ocel\OcelProjector;
use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Jobs\RefreshCockpitSnapshot;
use App\Models\Ancillary\AncillaryOrder;
use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use App\Services\Demo\DemoInvariantService;
use App\Services\Demo\DemoRefreshCoordinator;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Database\Seeders\CaseManagementSeeder;
use Database\Seeders\CommandCenterDemoSeeder;
use Database\Seeders\RtdcSeeder;
use Database\Seeders\StaffingReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class AncillaryDemoScenarioTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $anchor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anchor = CarbonImmutable::parse('2026-07-11T14:00:00Z');
        CarbonImmutable::setTestNow($this->anchor);
        $this->seed([RtdcSeeder::class, CaseManagementSeeder::class, StaffingReferenceSeeder::class, CommandCenterDemoSeeder::class, AncillaryReferenceSeeder::class]);
        $unitId = (int) DB::table('prod.units')->orderBy('unit_id')->value('unit_id');
        DB::table('prod.encounters')->insert([
            'patient_ref' => 'demo-discharge-candidate', 'unit_id' => $unitId, 'status' => 'active',
            'expected_discharge_date' => $this->anchor->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('prod.ed_visits')->insert([
            'patient_ref' => 'demo-ed-patient', 'arrived_at' => $this->anchor->subHours(2),
            'triaged_at' => $this->anchor->subHours(2)->addMinutes(7), 'esi_level' => 3,
            'unit_id' => $unitId, 'is_deleted' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_preview_and_same_anchor_refresh_are_deterministic_and_owned(): void
    {
        $clock = new DemoClock($this->anchor);
        $service = app(AncillaryDemoScenarioService::class);
        $preview = $service->preview($clock);

        $this->assertSame(66, $preview['orders']);
        $this->assertSame(328, $preview['milestones']);
        $this->assertSame(6, $preview['breaches']);
        $this->assertSame([], $preview['collisions']);
        $this->assertSame([
            'rad' => ['orders' => 16, 'milestones' => 97, 'expectedBreaches' => 1],
            'lab' => ['orders' => 14, 'milestones' => 84, 'expectedBreaches' => 1],
            'pathology' => ['orders' => 6, 'milestones' => 29, 'expectedBreaches' => 1],
            'blood_bank' => ['orders' => 6, 'milestones' => 13, 'expectedBreaches' => 1],
            'rx' => ['orders' => 24, 'milestones' => 105, 'expectedBreaches' => 2],
        ], collect($preview['departments'])->mapWithKeys(fn (array $department): array => [
            $department['department'] => [
                'orders' => $department['orders'],
                'milestones' => $department['milestones'],
                'expectedBreaches' => $department['expectedBreaches'],
            ],
        ])->all());

        $provenanceTables = [
            'lab_specimens', 'lab_results', 'lab_critical_values',
            'rx_orders', 'rx_verifications', 'rx_dispenses', 'rx_administrations', 'adc_transactions',
        ];
        $first = $service->refresh($clock);
        $firstProvenanceCount = DB::table('integration.provenance_records')
            ->where('target_schema', 'prod')
            ->whereIn('target_table', $provenanceTables)
            ->count();
        $firstKeys = AncillaryOrder::query()->where('demo_owner', AncillaryDemoScenarioService::OWNER)
            ->orderBy('department')->orderBy('source_order_key')->pluck('source_order_key')->all();
        $second = $service->refresh($clock);
        $secondKeys = AncillaryOrder::query()->where('demo_owner', AncillaryDemoScenarioService::OWNER)
            ->orderBy('department')->orderBy('source_order_key')->pluck('source_order_key')->all();

        $this->assertSame($first, $second);
        $this->assertSame($firstProvenanceCount, DB::table('integration.provenance_records')
            ->where('target_schema', 'prod')
            ->whereIn('target_table', $provenanceTables)
            ->count());
        $this->assertSame($firstKeys, $secondKeys);
        $this->assertSame(66, $second['orders']);
        $this->assertSame(328, $second['milestones']);
        $this->assertGreaterThanOrEqual(6, $second['breaches']);
        $this->assertSame(66, AncillaryOrder::query()->where('demo_owner', AncillaryDemoScenarioService::OWNER)->count());
        $this->assertSame(0, AncillaryOrder::query()->where('demo_owner', AncillaryDemoScenarioService::OWNER)->whereNull('source_cutoff_at')->count());
        $this->assertSame(2, DB::table('ops.source_freshness')->whereIn('source_key', ['ancillary_orders', 'ancillary_milestones'])->count());
        $this->assertSame(0, DB::table('integration.provenance_records as p')
            ->leftJoin('prod.ancillary_milestones as m', function ($join): void {
                $join->on(DB::raw('m.ancillary_milestone_id::text'), '=', 'p.target_pk')
                    ->where('p.target_table', '=', 'ancillary_milestones');
            })
            ->where('p.target_table', 'ancillary_milestones')
            ->whereNull('m.ancillary_milestone_id')
            ->count());
    }

    public function test_refresh_uses_the_explicit_anchor_for_sla_evaluation_and_restores_the_callers_clock(): void
    {
        $wallClock = $this->anchor->addMinutes(10);
        CarbonImmutable::setTestNow($wallClock);
        $clock = new DemoClock($this->anchor);

        app(AncillaryDemoScenarioService::class)->refresh($clock);

        $invalidOpen = collect(app(DemoInvariantService::class)->run($clock))
            ->firstWhere('key', 'ancillary.open_breaches_mathematically_valid');
        $this->assertTrue($invalidOpen['passed']);
        $this->assertSame('0 invalid open breaches', $invalidOpen['observed']);
        $this->assertTrue(CarbonImmutable::now()->equalTo($wallClock));
    }

    public function test_same_day_rolling_refresh_replaces_owned_canonical_and_ocel_rows_without_growth(): void
    {
        $service = app(AncillaryDemoScenarioService::class);
        $projector = app(OcelProjector::class);
        $firstClock = new DemoClock($this->anchor);
        $service->refresh($firstClock);
        $firstProjection = $projector->projectAncillary($firstClock->windowStart(), $firstClock->windowEnd());
        $firstCounts = $this->ocelCounts();
        $firstCanonical = DB::table('integration.canonical_events')
            ->whereRaw("metadata->>'demo_owner' = ?", [AncillaryDemoScenarioService::OWNER])
            ->orderBy('canonical_event_id')
            ->firstOrFail();
        $firstOccurredAt = CarbonImmutable::parse($firstCanonical->occurred_at);

        $nextAnchor = $this->anchor->addMinutes(15);
        $nextClock = new DemoClock($nextAnchor);
        $service->refresh($nextClock);
        $secondProjection = $projector->projectAncillary($nextClock->windowStart(), $nextClock->windowEnd());
        $secondCanonical = DB::table('integration.canonical_events')
            ->where('canonical_event_id', $firstCanonical->canonical_event_id)
            ->firstOrFail();

        $this->assertSame(
            array_diff_key($firstProjection, ['window' => true]),
            array_diff_key($secondProjection, ['window' => true]),
        );
        $this->assertSame($firstCounts, $this->ocelCounts());
        $this->assertSame(336, DB::table('integration.canonical_events')
            ->whereRaw("metadata->>'demo_owner' = ?", [AncillaryDemoScenarioService::OWNER])
            ->count());
        $this->assertSame(66, AncillaryOrder::query()->where('demo_owner', AncillaryDemoScenarioService::OWNER)->count());
        $this->assertSame(328, DB::table('prod.ancillary_milestones as m')
            ->join('prod.ancillary_orders as o', 'o.ancillary_order_id', '=', 'm.ancillary_order_id')
            ->where('o.demo_owner', AncillaryDemoScenarioService::OWNER)
            ->count());
        $this->assertSame(15.0, $firstOccurredAt->diffInMinutes(CarbonImmutable::parse($secondCanonical->occurred_at)));
        $unexpectedCutoffs = AncillaryOrder::query()
            ->where('demo_owner', AncillaryDemoScenarioService::OWNER)
            ->get()
            ->reject(fn (AncillaryOrder $order): bool => CarbonImmutable::instance($order->source_cutoff_at)->equalTo($nextAnchor))
            ->map(fn (AncillaryOrder $order): array => [
                'sourceOrderKey' => $order->source_order_key,
                'department' => $order->department,
                'sourceCutoffAt' => CarbonImmutable::instance($order->source_cutoff_at)->toIso8601String(),
            ])
            ->values()
            ->all();
        $this->assertSame([], $unexpectedCutoffs);
        $this->assertSame(0, DB::table('ocel.events as e')
            ->leftJoin('prod.ancillary_milestones as m', DB::raw('m.ancillary_milestone_id::text'), '=', 'e.source_ref')
            ->where('e.source_system', 'prod.ancillary_milestones')
            ->whereNull('m.ancillary_milestone_id')
            ->count());
        $this->assertSame([], collect(app(DemoInvariantService::class)->run($nextClock))
            ->where('category', 'ancillary')->where('severity', 'critical')->where('passed', false)->values()->all());
    }

    public function test_scenarios_include_degraded_conflict_rework_discharge_and_warehouse_evidence(): void
    {
        app(AncillaryDemoScenarioService::class)->refresh(new DemoClock($this->anchor));

        $this->assertGreaterThan(0, DB::table('prod.ancillary_current_assertions as v')
            ->join('prod.ancillary_orders as o', 'o.ancillary_order_id', '=', 'v.ancillary_order_id')
            ->where('o.demo_owner', AncillaryDemoScenarioService::OWNER)
            ->where('v.assertion_count', '>', 1)
            ->count());
        $this->assertGreaterThan(0, DB::table('prod.ancillary_orders as o')
            ->join('prod.ancillary_milestones as rejected', function ($join): void {
                $join->on('rejected.ancillary_order_id', '=', 'o.ancillary_order_id')->where('rejected.milestone_code', '=', 'LAB_REJECTED');
            })
            ->join('prod.ancillary_milestones as recollect', function ($join): void {
                $join->on('recollect.ancillary_order_id', '=', 'o.ancillary_order_id')->where('recollect.milestone_code', '=', 'LAB_RECOLLECT_ORDERED');
            })
            ->where('o.demo_owner', AncillaryDemoScenarioService::OWNER)
            ->count());
        $this->assertGreaterThan(0, AncillaryOrder::query()
            ->where('demo_owner', AncillaryDemoScenarioService::OWNER)
            ->whereRaw("metadata->>'discharge_blocking' = 'true'")
            ->whereNull('terminal_at')->count());
        $this->assertGreaterThan(0, DB::table('prod.ancillary_milestones as m')
            ->join('integration.sources as s', 's.source_id', '=', 'm.source_id')
            ->join('prod.ancillary_orders as o', 'o.ancillary_order_id', '=', 'm.ancillary_order_id')
            ->where('o.demo_owner', AncillaryDemoScenarioService::OWNER)
            ->where('m.milestone_code', 'RX_ADMINISTERED')
            ->where('s.system_class', 'clinical_warehouse')
            ->whereNotNull('o.source_cutoff_at')->count());
        $this->assertGreaterThan(0, AncillaryOrder::query()
            ->where('demo_owner', AncillaryDemoScenarioService::OWNER)
            ->where('department', 'rad')
            ->whereHas('milestones', fn ($query) => $query->where('milestone_code', 'RAD_FINAL'))
            ->whereDoesntHave('milestones', fn ($query) => $query->whereIn('milestone_code', ['RAD_EXAM_START', 'RAD_EXAM_END']))
            ->count());
    }

    /** @return array{events:int,objects:int,e2o:int,o2o:int,changes:int} */
    private function ocelCounts(): array
    {
        return [
            'events' => DB::table('ocel.events')->where('source_system', 'prod.ancillary_milestones')->count(),
            'objects' => DB::table('ocel.objects')->count(),
            'e2o' => DB::table('ocel.event_object')->count(),
            'o2o' => DB::table('ocel.object_object')->count(),
            'changes' => DB::table('ocel.object_changes')->count(),
        ];
    }

    public function test_advancing_anchor_replaces_only_owned_rows_and_moves_natural_keys(): void
    {
        $service = app(AncillaryDemoScenarioService::class);
        $service->refresh(new DemoClock($this->anchor));
        $oldKeys = AncillaryOrder::query()->where('demo_owner', AncillaryDemoScenarioService::OWNER)->pluck('source_order_key')->all();
        $foreign = AncillaryOrder::factory()->radiology()->create(['source_order_key' => 'foreign-order', 'demo_owner' => null]);

        CarbonImmutable::setTestNow($this->anchor->addDay());
        $service->refresh(new DemoClock($this->anchor->addDay()));
        $newKeys = AncillaryOrder::query()->where('demo_owner', AncillaryDemoScenarioService::OWNER)->pluck('source_order_key')->all();

        $this->assertSame([], array_values(array_intersect($oldKeys, $newKeys)));
        $this->assertDatabaseHas('prod.ancillary_orders', ['ancillary_order_id' => $foreign->ancillary_order_id, 'source_order_key' => 'foreign-order']);
        $this->assertSame(66, count($newKeys));
    }

    public function test_collision_detection_refuses_non_owned_natural_key_without_replacing_it(): void
    {
        $clock = new DemoClock($this->anchor);
        $service = app(AncillaryDemoScenarioService::class);
        $service->refresh($clock);
        $collision = AncillaryOrder::query()->where('demo_owner', AncillaryDemoScenarioService::OWNER)->orderBy('ancillary_order_id')->firstOrFail();
        $collision->update(['demo_owner' => null]);
        $before = AncillaryOrder::query()->count();

        $preview = $service->preview($clock);
        $this->assertContains($collision->source_order_key, $preview['collisions']);

        try {
            $service->refresh($clock);
            $this->fail('A non-owned natural-key collision must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Refusing to replace non-owned', $exception->getMessage());
        }

        $this->assertSame($before, AncillaryOrder::query()->count());
        $this->assertDatabaseHas('prod.ancillary_orders', [
            'ancillary_order_id' => $collision->ancillary_order_id,
            'source_order_key' => $collision->source_order_key,
            'demo_owner' => null,
        ]);
    }

    public function test_ancillary_invariants_and_json_validation_surface_are_explicit(): void
    {
        $clock = new DemoClock($this->anchor);
        app(AncillaryDemoScenarioService::class)->refresh($clock);
        $findings = collect(app(DemoInvariantService::class)->run($clock))->where('category', 'ancillary')->values();

        $this->assertCount(28, $findings);
        $this->assertSame([], $findings->where('severity', 'critical')->where('passed', false)->all());
        $this->assertTrue($findings->firstWhere('key', 'ancillary.source_conflict_represented')['passed']);

        $this->artisan('zephyrus:demo-validate', [
            '--anchor' => $clock->key(),
            '--json' => true,
        ])->expectsOutputToContain('ancillary.demo_ownership_exact')->execute();
    }

    public function test_forced_critical_invariant_failure_prevents_cockpit_publish(): void
    {
        Bus::fake();
        Artisan::shouldReceive('call')->times(4)->andReturn(0);
        $source = app(SourceRegistryService::class)->ensureSource([
            'source_key' => 'demo.ancillary.foreign.primary',
            'system_class' => 'ris',
            'interface_type' => 'synthetic',
            'active_status' => 'inactive',
            'phi_allowed' => false,
        ]);
        AncillaryOrder::factory()->radiology()->create([
            'source_id' => $source->source_id,
            'source_order_key' => 'foreign-demo-source-order',
            'demo_owner' => null,
        ]);
        $coordinator = new DemoRefreshCoordinator(app(DemoInvariantService::class), app(AncillaryDemoScenarioService::class));

        $result = $coordinator->refresh(new DemoClock($this->anchor));

        $this->assertSame('failed', $result['status']);
        $this->assertFalse($result['published']);
        $this->assertContains('ancillary.demo_ownership_exact', $result['invariants']['criticalKeys']);
        Bus::assertNotDispatched(RefreshCockpitSnapshot::class);
    }

    public function test_demo_refresh_json_includes_ancillary_domain_result(): void
    {
        Bus::fake();
        config(['demo.enabled' => true, 'rounds.enabled' => false]);

        $this->artisan('zephyrus:demo-refresh', [
            '--anchor' => $this->anchor->toIso8601String(),
            '--validate' => true,
            '--json' => true,
        ])
            ->expectsOutputToContain('"domain": "ancillary"')
            ->execute();
    }
}
