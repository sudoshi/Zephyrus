<?php

namespace Tests\Feature\Ancillary;

use App\Models\Ancillary\AncillaryOrder;
use App\Models\Pharmacy\MedicationOrder;
use App\Models\User;
use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use App\Services\Pharmacy\PharmacyFlowBoardService;
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

class PharmacyFlowBoardTest extends TestCase
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

    public function test_service_owns_queue_depth_clock_segments_branches_and_breach_summary(): void
    {
        $payload = app(PharmacyFlowBoardService::class)->build();

        $this->assertSame('degraded', $payload['state']);
        $this->assertTrue($payload['degradedMode']);
        $this->assertSame('fresh', $payload['freshnessStatus']);
        $this->assertNotEmpty($payload['appliedSlaDefinitions']);
        $this->assertSame(24, $payload['data']['summary']['currentOrders']);
        $this->assertSame(21, $payload['data']['summary']['openOrders']);
        $this->assertSame(3, $payload['data']['summary']['statOrders']);
        $this->assertSame(1, $payload['data']['summary']['statCompliant']);
        $this->assertSame(33.3, $payload['data']['summary']['statCompliancePercent']);
        $this->assertSame(3, $payload['data']['summary']['verificationQueueDepth']);
        $this->assertSame(2, $payload['data']['summary']['openBreaches']);
        $this->assertSame(1, $payload['data']['summary']['shortageOrders']);
        $this->assertSame(2, $payload['data']['summary']['dischargeOrders']);
        $this->assertSame(1, $payload['data']['summary']['controlledOrders']);
        $this->assertSame(1, $payload['data']['summary']['degradedOrders']);

        $queue = $payload['data']['verificationQueue'];
        $this->assertSame(3, $queue['depth']);
        $this->assertSame(237, $queue['oldestAgeMinutes']);
        $this->assertSame(224.0, $queue['medianAgeMinutes']);
        $this->assertSame([0, 0, 0, 3], array_column($queue['ageDistribution'], 'count'));

        $classes = collect($payload['data']['clockClasses'])->keyBy('clockClass');
        $this->assertSame(['rx.stat_dispense', 'rx.first_dose_admin', 'rx.sepsis_abx'], array_column($payload['data']['clockClasses'], 'metricKey'));
        $this->assertSame(['breach', 1, 0, 1, false], [$classes['stat']['state'], $classes['stat']['openBreaches'], $classes['stat']['openWarnings'], $classes['stat']['clearedBreaches'], $classes['stat']['adminTail']]);
        $this->assertSame(['normal', 0, 0, 2, true], [$classes['first_dose']['state'], $classes['first_dose']['openBreaches'], $classes['first_dose']['openWarnings'], $classes['first_dose']['clearedBreaches'], $classes['first_dose']['adminTail']]);
        $this->assertSame(['breach', 1, 1, 1, true], [$classes['sepsis']['state'], $classes['sepsis']['openBreaches'], $classes['sepsis']['openWarnings'], $classes['sepsis']['clearedBreaches'], $classes['sepsis']['adminTail']]);
        $this->assertSame(2, $classes['sepsis']['openOrders']);

        $segments = $payload['data']['segments'];
        $this->assertSame([11, 40.0, 105.0, 'real_time'], [$segments['orderToDispense']['count'], $segments['orderToDispense']['medianMinutes'], $segments['orderToDispense']['p90Minutes'], $segments['orderToDispense']['basis']]);
        $this->assertSame([3, 25.0, 149.0, 'as_of_cutoff'], [$segments['dispenseToAdmin']['count'], $segments['dispenseToAdmin']['medianMinutes'], $segments['dispenseToAdmin']['p90Minutes'], $segments['dispenseToAdmin']['basis']]);
        $this->assertSame('batch', $segments['dispenseToAdmin']['freshness']['status']);
        $this->assertSame($this->anchor->subMinutes(300)->toAtomString(), $segments['dispenseToAdmin']['sourceCutoffAt']);
        $this->assertSame('fresh', $segments['orderToDispense']['freshness']['status']);

        $branches = collect($payload['data']['preparationBranches']['branches'])->keyBy('branch');
        $this->assertSame([17, 5, 2, 0], [$branches['adc']['orders'], $branches['iv_room']['orders'], $branches['central']['orders'], $branches['unknown']['orders']]);
        $this->assertSame(1, $branches['iv_room']['degradedOrders']);
        $this->assertSame('partial', $payload['data']['preparationBranches']['ivwms']['status']);
        $this->assertStringContainsString('not reported as zero', $payload['data']['preparationBranches']['ivwms']['explanation']);

        $timers = $payload['data']['sepsisTimers'];
        $this->assertSame(['complete', 'breached', 'warning'], array_column($timers, 'state'));
        $this->assertSame(['administered_as_of', 'no_evidence_as_of_cutoff', 'no_evidence_as_of_cutoff'], array_column(array_column($timers, 'adminSegment'), 'state'));
        $this->assertSame(200, $timers[0]['adminSegment']['elapsedMinutes']);
        $this->assertSame($this->anchor->subMinutes(300)->toAtomString(), $timers[1]['adminSegment']['sourceCutoffAt']);
        $this->assertSame('complete', collect($timers[1]['segments'])->firstWhere('code', 'RX_DISPENSED')['state']);
        $this->assertSame('pending', collect($timers[2]['segments'])->firstWhere('code', 'RX_DISPENSED')['state']);

        $this->assertCount(8, $payload['data']['oldestItems']);
        $this->assertSame(437, $payload['data']['oldestItems'][0]['ageMinutes']);
        $this->assertSame('RX_VERIFIED', $payload['data']['oldestItems'][0]['currentStage']);

        $sepsisLens = app(PharmacyFlowBoardService::class)->build(['lens' => 'sepsis', 'source' => 'cockpit']);
        $this->assertSame('sepsis', $sepsisLens['filters']['lens']);
        $this->assertSame('cockpit', $sepsisLens['filters']['source']);
        $this->assertSame(3, $sepsisLens['data']['summary']['currentOrders']);
        $this->assertSame(1, app(PharmacyFlowBoardService::class)->build(['lens' => 'shortage'])['data']['summary']['currentOrders']);
        // Preparation branch comes from formulary-driven rx_orders.preparation_branch,
        // not order metadata: the central-routed ondansetron conflict order counts as ADC.
        $this->assertSame(2, app(PharmacyFlowBoardService::class)->build(['branch' => 'central'])['data']['summary']['currentOrders']);
        $this->assertSame(1, app(PharmacyFlowBoardService::class)->build(['lens' => 'degraded'])['data']['summary']['currentOrders']);
        $this->assertSame(3, app(PharmacyFlowBoardService::class)->build(['clockClass' => 'stat'])['data']['summary']['currentOrders']);
    }

    public function test_sepsis_display_cannot_imply_a_current_administration_when_warehouse_is_stale(): void
    {
        config(['integrations.ancillary.warehouse_stale_after_minutes' => 60]);
        $payload = app(PharmacyFlowBoardService::class)->build();

        $this->assertSame('stale', $payload['administrationFreshness']['status']);
        $this->assertSame('as_of_cutoff', $payload['data']['segments']['dispenseToAdmin']['basis']);
        $this->assertSame('stale', $payload['data']['segments']['dispenseToAdmin']['freshness']['status']);

        $classes = collect($payload['data']['clockClasses'])->keyBy('clockClass');
        $this->assertSame('unknown', $classes['sepsis']['state']);
        $this->assertNull($classes['sepsis']['openWarnings']);
        $this->assertSame(1, $classes['sepsis']['openBreaches']);
        $this->assertSame('unknown', $classes['first_dose']['state']);
        $this->assertSame('breach', $classes['stat']['state']);
        $this->assertStringContainsString('neither compliance nor breach', $classes['sepsis']['explanation']);

        $timers = collect($payload['data']['sepsisTimers']);
        $open = $timers->where('adminSegment.administeredAt', null)->values();
        $this->assertCount(2, $open);
        foreach ($open as $timer) {
            $this->assertSame('unknown', $timer['state']);
            $this->assertSame('unknown', $timer['adminSegment']['state']);
            $this->assertNull($timer['adminSegment']['administeredAt']);
            $this->assertNotNull($timer['adminSegment']['sourceCutoffAt']);
            $this->assertStringContainsString('neither a success nor a failure', $timer['adminSegment']['explanation']);
        }
        // A warehouse-recorded administration remains an as-of fact, not a real-time claim.
        $administered = $timers->firstWhere('adminSegment.state', 'administered_as_of');
        $this->assertSame('complete', $administered['state']);
        $this->assertSame($this->anchor->subMinutes(300)->toAtomString(), $administered['adminSegment']['sourceCutoffAt']);
    }

    public function test_degraded_empty_stale_and_error_states_are_explicit(): void
    {
        $service = app(PharmacyFlowBoardService::class);

        $baseline = $service->build();
        $this->assertSame('degraded', $baseline['state']);
        $this->assertStringContainsString('cutoff-qualified', $baseline['stateMessage']);

        $empty = $service->build(['status' => 'held']);
        $this->assertSame('no_data', $empty['state']);
        $this->assertSame('unknown', $empty['freshness']['status']);
        $this->assertSame([], $empty['data']['sepsisTimers']);
        $this->assertSame([], $empty['data']['oldestItems']);
        $this->assertSame(0, $empty['data']['verificationQueue']['depth']);
        $this->assertNull($empty['data']['verificationQueue']['oldestAgeMinutes']);
        $this->assertSame(0, $empty['data']['segments']['orderToDispense']['count']);

        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'stale']);
        $this->assertSame('stale', $service->build()['state']);
        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'error']);
        $this->assertSame('source_error', $service->build()['state']);
    }

    public function test_inertia_api_privacy_filter_validation_and_barrier_write_contracts(): void
    {
        $manager = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
        $expected = app(PharmacyFlowBoardService::class)->build(['lens' => 'sepsis'], true, true);
        $expectedJson = json_decode(json_encode($expected, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        $this->actingAs($manager)->get('/pharmacy?lens=sepsis')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Pharmacy/FlowBoard')->where('flowBoard', $expectedJson));
        $this->actingAs($manager)->getJson('/api/pharmacy/flow-board?lens=sepsis')->assertOk()->assertExactJson($expectedJson);

        $frontline = User::factory()->create(['role' => 'user', 'must_change_password' => false]);
        $redacted = $this->actingAs($frontline)->getJson('/api/pharmacy/flow-board?lens=sepsis')->assertOk()
            ->assertJsonPath('canViewPatientDetail', false)->assertJsonPath('canAnnotateBarriers', false);
        $this->assertStringNotContainsString('sim-ed-', $redacted->getContent());
        $this->actingAs($manager)->getJson('/api/pharmacy/flow-board?lens=diagnostic')->assertUnprocessable();
        $this->actingAs($manager)->getJson('/api/pharmacy/flow-board?source=untrusted')->assertUnprocessable();
        $this->actingAs($manager)->getJson('/api/pharmacy/flow-board?branch=robot')->assertUnprocessable();
        $validUnitId = (int) DB::table('prod.units')->where('is_deleted', false)->value('unit_id');
        $this->actingAs($manager)->getJson('/api/pharmacy/flow-board?unitId='.$validUnitId.'&source=ancillary_services')->assertOk()
            ->assertJsonPath('filters.unitId', $validUnitId)->assertJsonPath('filters.source', 'ancillary_services');
        $this->actingAs($manager)->get('/pharmacy?unitId=bad')->assertSessionHasErrors('unitId');

        // The overdue STAT order carries the open rx.stat_dispense breach; the governed
        // barrier write links it and is audited.
        $statOrder = MedicationOrder::query()->where('clock_class', 'stat')->where('order_status', 'verified')->firstOrFail();
        $order = AncillaryOrder::query()->findOrFail($statOrder->ancillary_order_id);
        $body = ['orderUuid' => $order->order_uuid, 'reasonCode' => 'RX_VERIFICATION_DELAY', 'description' => 'STAT dispense blocked awaiting sourcing', 'owner' => 'Pharmacy operations'];
        $this->actingAs($frontline)->postJson('/api/pharmacy/barriers', $body)->assertForbidden();
        $response = $this->actingAs($manager)->postJson('/api/pharmacy/barriers', $body)->assertCreated();
        $barrierId = $response->json('data.barrier_id');
        $this->assertDatabaseHas('prod.barriers', ['barrier_id' => $barrierId, 'encounter_id' => $order->encounter_id, 'reason_code' => 'RX_VERIFICATION_DELAY']);
        $this->assertDatabaseHas('prod.ancillary_breaches', ['ancillary_order_id' => $order->ancillary_order_id, 'status' => 'open', 'barrier_id' => $barrierId]);
        $this->assertDatabaseHas('audit.user_events', ['actor_user_id' => $manager->id, 'action' => 'ancillary.barrier.opened', 'target_type' => 'ancillary_barrier', 'outcome' => 'success']);
        $this->actingAs($manager)->postJson('/api/pharmacy/barriers', [...$body, 'reasonCode' => 'LAB_RECOLLECT_REQUIRED'])->assertUnprocessable();

        $withBarrier = app(PharmacyFlowBoardService::class)->build();
        $this->assertSame('RX_VERIFICATION_DELAY', $withBarrier['data']['barrierPareto'][0]['reasonCode']);
    }

    public function test_no_pharmacist_or_user_performance_scoring_appears_in_the_contract(): void
    {
        $payload = app(PharmacyFlowBoardService::class)->build([], true, true);
        $this->assertFalse($payload['privacy']['individualPerformanceIncluded']);
        $this->assertFalse($payload['privacy']['directPatientIdentifiersIncluded']);

        $forbidden = ['user', 'staff', 'pharmacist', 'nurse', 'verifier', 'technician', 'employee', 'badge', 'performed_by', 'performedby', 'actor'];
        $keys = [];
        $collect = function (mixed $value) use (&$collect, &$keys): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $nested) {
                if (is_string($key)) {
                    $keys[] = strtolower($key);
                }
                $collect($nested);
            }
        };
        $collect(json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));

        foreach ($keys as $key) {
            foreach ($forbidden as $fragment) {
                $this->assertStringNotContainsString($fragment, $key, "Contract key '{$key}' leaks a user/staff dimension.");
            }
        }
    }

    public function test_routes_are_named_owned_and_api_reads_require_authentication(): void
    {
        $this->assertSame('pharmacy', Route::getRoutes()->getByName('pharmacy.flow-board')?->uri());
        $this->assertSame('api/pharmacy/flow-board', Route::getRoutes()->getByName('api.pharmacy.flow-board')?->uri());
        $this->assertSame('api/pharmacy/barriers', Route::getRoutes()->getByName('api.pharmacy.barriers.store')?->uri());
        $this->getJson('/api/pharmacy/flow-board')->assertUnauthorized();
    }
}
