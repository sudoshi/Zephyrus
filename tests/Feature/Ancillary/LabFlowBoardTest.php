<?php

namespace Tests\Feature\Ancillary;

use App\Models\Ancillary\AncillaryOrder;
use App\Models\User;
use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use App\Services\Lab\LabFlowBoardService;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Database\Seeders\CaseManagementSeeder;
use Database\Seeders\CommandCenterDemoSeeder;
use Database\Seeders\RtdcSeeder;
use Database\Seeders\StaffingReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LabFlowBoardTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $anchor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anchor = CarbonImmutable::parse('2026-07-11T14:00:00Z');
        CarbonImmutable::setTestNow($this->anchor);
        $this->seed([RtdcSeeder::class, CaseManagementSeeder::class, StaffingReferenceSeeder::class, CommandCenterDemoSeeder::class, AncillaryReferenceSeeder::class]);
        app(AncillaryDemoScenarioService::class)->refresh(new DemoClock($this->anchor));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_service_owns_current_flow_compliance_clocks_quality_and_decision_drills(): void
    {
        $payload = app(LabFlowBoardService::class)->build();

        $this->assertSame('degraded', $payload['state']);
        $this->assertSame(13, $payload['summary']['currentOrders']);
        $this->assertSame(8, $payload['summary']['openOrders']);
        $this->assertSame(3, $payload['summary']['statOrders']);
        $this->assertSame(1, $payload['summary']['statCompliant']);
        $this->assertSame(33.3, $payload['summary']['statCompliancePercent']);
        $this->assertSame(3, $payload['summary']['pendingDecisions']);
        $this->assertSame(1, $payload['summary']['openCriticalCallbacks']);
        $this->assertSame('available', $payload['coverage']['transport']['status']);
        $this->assertSame('available', $payload['coverage']['middleware']['status']);
        $this->assertSame('segmented', $payload['tat']['collectToReceive']['granularity']);
        $this->assertGreaterThan(0, $payload['tat']['collectToReceive']['count']);
        $this->assertGreaterThan(0, $payload['tat']['receiveToResult']['count']);
        $this->assertSame(2, $payload['criticalCallbacks']['total']);
        $this->assertSame(1, $payload['criticalCallbacks']['open']);
        $this->assertSame(['benchmark', 'benchmark', 'local_policy'], array_column(array_column($payload['qualityStrip'], 'reference'), 'kind'));
        $this->assertSame(['External benchmark not configured', 'External benchmark not configured', 'Site policy not configured'], array_column(array_column($payload['qualityStrip'], 'reference'), 'label'));
        $this->assertNotEmpty($payload['stageDistribution']);
        $this->assertCount(3, collect($payload['oldestItems'])->filter(fn (array $item): bool => $item['decisionContext'] !== null));
        $this->assertNotEmpty($payload['definitions']);
    }

    public function test_missing_optional_feeds_are_coarse_not_zero_and_empty_stale_error_states_are_explicit(): void
    {
        $service = app(LabFlowBoardService::class);
        AncillaryOrder::factory()->lab()->create([
            'ordered_at' => $this->anchor->subMinutes(20), 'source_cutoff_at' => $this->anchor->subMinutes(2),
            'metadata' => ['test_family' => 'missing_feed_fixture'],
        ]);
        $coarse = $service->build(['testFamily' => 'missing_feed_fixture']);

        $this->assertSame('degraded', $coarse['state']);
        $this->assertSame('missing', $coarse['coverage']['transport']['status']);
        $this->assertSame('coarse', $coarse['coverage']['transport']['granularity']);
        $this->assertNull($coarse['tat']['collectToReceive']['medianMinutes']);
        $this->assertStringContainsString('not reported as zero', $coarse['coverage']['transport']['explanation']);

        $this->assertSame('no_data', $service->build(['testFamily' => 'not_present'])['state']);
        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'stale']);
        $this->assertSame('stale', $service->build()['state']);
        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'error']);
        $this->assertSame('source_error', $service->build()['state']);
    }

    public function test_inertia_api_privacy_filter_validation_and_barrier_write_contracts(): void
    {
        $manager = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
        $expected = app(LabFlowBoardService::class)->build(['lens' => 'discharge_gate'], true, true);
        $expectedJson = json_decode(json_encode($expected, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        $this->actingAs($manager)->get('/lab?lens=discharge_gate')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Lab/FlowBoard')->where('flowBoard', $expectedJson));
        $this->actingAs($manager)->getJson('/api/lab/flow-board?lens=discharge_gate')->assertOk()->assertExactJson($expectedJson);

        $frontline = User::factory()->create(['role' => 'user', 'must_change_password' => false]);
        $redacted = $this->actingAs($frontline)->getJson('/api/lab/flow-board?lens=discharge_gate')->assertOk()
            ->assertJsonPath('canViewPatientDetail', false)->assertJsonPath('canAnnotateBarriers', false);
        $this->assertStringNotContainsString('demo-discharge-candidate', $redacted->getContent());
        $this->actingAs($manager)->getJson('/api/lab/flow-board?lens=diagnostic')->assertUnprocessable();
        $this->actingAs($manager)->get('/lab?unitId=bad')->assertSessionHasErrors('unitId');

        $order = AncillaryOrder::query()->where('department', 'lab')->whereRaw("metadata->>'discharge_blocking' = 'true'")->firstOrFail();
        $body = ['orderUuid' => $order->order_uuid, 'reasonCode' => 'LAB_RECOLLECT_REQUIRED', 'description' => 'Recollection required before discharge', 'owner' => 'Laboratory operations'];
        $this->actingAs($frontline)->postJson('/api/lab/barriers', $body)->assertForbidden();
        $response = $this->actingAs($manager)->postJson('/api/lab/barriers', $body)->assertCreated();
        $this->assertDatabaseHas('prod.barriers', ['barrier_id' => $response->json('data.barrier_id'), 'encounter_id' => $order->encounter_id, 'reason_code' => 'LAB_RECOLLECT_REQUIRED']);
        $this->assertDatabaseHas('audit.user_events', ['actor_user_id' => $manager->id, 'action' => 'ancillary.barrier.opened', 'target_type' => 'ancillary_barrier', 'outcome' => 'success']);
    }

    public function test_routes_are_named_owned_and_api_reads_require_authentication(): void
    {
        $this->assertSame('lab', Route::getRoutes()->getByName('lab.flow-board')?->uri());
        $this->assertSame('api/lab/flow-board', Route::getRoutes()->getByName('api.lab.flow-board')?->uri());
        $this->assertSame('api/lab/barriers', Route::getRoutes()->getByName('api.lab.barriers.store')?->uri());
        $this->getJson('/api/lab/flow-board')->assertUnauthorized();
    }
}
