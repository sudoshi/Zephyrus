<?php

namespace Tests\Feature\Ancillary;

use App\Models\Ancillary\AncillaryOrder;
use App\Models\User;
use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use App\Services\Radiology\RadiologyFlowBoardService;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Database\Seeders\CaseManagementSeeder;
use Database\Seeders\CommandCenterDemoSeeder;
use Database\Seeders\RtdcSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RadiologyFlowBoardTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $anchor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anchor = CarbonImmutable::parse('2026-07-11T14:00:00Z');
        CarbonImmutable::setTestNow($this->anchor);
        $this->seed([RtdcSeeder::class, CaseManagementSeeder::class, CommandCenterDemoSeeder::class, AncillaryReferenceSeeder::class]);
        app(AncillaryDemoScenarioService::class)->refresh(new DemoClock($this->anchor));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_service_builds_server_derived_flow_board_and_discharge_ct_lens(): void
    {
        $payload = app(RadiologyFlowBoardService::class)->build(['lens' => 'discharge'], true);

        $this->assertSame('discharge', $payload['filters']['lens']);
        $this->assertSame(1, $payload['summary']['openOrders']);
        $this->assertSame(1, $payload['summary']['dischargeBlocking']);
        $this->assertSame('CT', $payload['oldestItems'][0]['modality']);
        $this->assertSame('Discharge-pending chest CT', $payload['oldestItems'][0]['label']);
        $this->assertTrue($payload['oldestItems'][0]['encounterLinked']);
        $this->assertStringContainsString('lens=discharge', $payload['worklistHref']);
        $this->assertNotEmpty($payload['thresholds']['definitions']);
        $this->assertNotEmpty($payload['heatmap']);
        $this->assertSame(1, $payload['scanners']['downtime']);
    }

    public function test_inertia_first_render_and_api_refetch_share_the_exact_contract(): void
    {
        $user = User::factory()->create(['role' => 'radiology_manager', 'must_change_password' => false]);
        $expected = app(RadiologyFlowBoardService::class)->build(['lens' => 'discharge'], true);

        $this->actingAs($user)->get('/radiology?lens=discharge')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Radiology/FlowBoard')->where('flowBoard', $expected));
        $this->actingAs($user)->getJson('/api/radiology/flow-board?lens=discharge')
            ->assertOk()->assertExactJson($expected);
    }

    public function test_patient_context_is_redacted_when_the_role_lacks_the_detail_capability(): void
    {
        $frontline = User::factory()->create(['role' => 'user', 'must_change_password' => false]);

        $response = $this->actingAs($frontline)->getJson('/api/radiology/flow-board?lens=discharge')
            ->assertOk()
            ->assertJsonPath('canViewPatientDetail', false)
            ->assertJsonPath('canAnnotateBarriers', false)
            ->assertJsonPath('oldestItems.0.patientRef', 'Patient context restricted');

        $this->assertStringNotContainsString('demo-patient', $response->getContent());
    }

    public function test_barrier_annotation_is_policy_checked_linked_audited_and_visible_to_improvement(): void
    {
        $order = AncillaryOrder::query()->where('department', 'rad')->dischargeBlocking()->firstOrFail();
        $body = [
            'orderUuid' => $order->order_uuid,
            'reasonCode' => 'RAD_READ_QUEUE',
            'description' => 'Awaiting prioritized interpretation queue review',
            'owner' => 'Radiology operations',
        ];

        $this->actingAs(User::factory()->create(['role' => 'user', 'must_change_password' => false]))
            ->postJson('/api/radiology/barriers', $body)->assertForbidden();

        $admin = User::factory()->create(['role' => 'radiology_manager', 'must_change_password' => false]);
        $response = $this->actingAs($admin)->postJson('/api/radiology/barriers', $body)->assertCreated();
        $barrierId = $response->json('data.barrier_id');

        $this->assertDatabaseHas('prod.barriers', [
            'barrier_id' => $barrierId,
            'encounter_id' => $order->encounter_id,
            'reason_code' => 'RAD_READ_QUEUE',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('audit.user_events', [
            'actor_user_id' => $admin->id,
            'action' => 'ancillary.barrier.opened',
            'target_type' => 'ancillary_barrier',
            'target_id' => (string) $barrierId,
            'outcome' => 'success',
        ]);
        $this->actingAs($admin)->get('/improvement/active')->assertInertia(
            fn (Assert $page) => $page->where('reportedBarriers.0.barrierId', $barrierId)->where('reportedBarriers.0.reasonCode', 'RAD_READ_QUEUE'),
        );
    }

    public function test_no_data_stale_degraded_and_source_error_states_are_explicit(): void
    {
        $service = app(RadiologyFlowBoardService::class);

        $this->assertSame('no_data', $service->build(['modality' => 'ZZ'])['state']);
        $this->assertSame('degraded', $service->build(['lens' => 'degraded'])['state']);

        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'stale']);
        $this->assertSame('stale', $service->build()['state']);

        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'error']);
        $sourceError = $service->build();
        $this->assertSame('source_error', $sourceError['state']);
        $this->assertNull($sourceError['heatmap'][0]['count']);
    }

    public function test_malformed_filters_are_rejected_by_both_routes(): void
    {
        $user = User::factory()->create(['role' => 'radiology_manager', 'must_change_password' => false]);

        $this->actingAs($user)->getJson('/api/radiology/flow-board?lens=diagnostic')->assertUnprocessable();
        $this->actingAs($user)->get('/radiology?unitId=not-an-id')->assertSessionHasErrors('unitId');
    }
}
