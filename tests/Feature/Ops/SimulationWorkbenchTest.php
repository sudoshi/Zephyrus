<?php

namespace Tests\Feature\Ops;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SimulationWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    public function test_workbench_persists_simulation_run_scenarios_results_and_snapshot(): void
    {
        $user = User::factory()->create();
        $ids = $this->seedCapacityFixture();

        $response = $this->actingAs($user)
            ->getJson('/api/analytics/workbench')
            ->assertOk()
            ->assertJsonPath('data.section', 'workbench');

        $simulation = $response->json('data.simulation');
        $this->assertGreaterThanOrEqual(6, $simulation['summary']['scenarioCount']);
        $this->assertNotNull($simulation['run']['baselineSnapshotId']);
        $this->assertSame(-1, $simulation['baseline']['current_net_beds']);
        $this->assertContains('combined_capacity_plan', array_column($simulation['scenarios'], 'key'));

        $combined = collect($simulation['scenarios'])->firstWhere('key', 'combined_capacity_plan');
        $this->assertGreaterThan($simulation['baseline']['current_net_beds'], $combined['netBedForecast']);
        $this->assertContains('net_beds', array_column($combined['resultMetrics'], 'metricKey'));
        $this->assertContains('risk_score', array_column($combined['resultMetrics'], 'metricKey'));

        $this->assertDatabaseHas('ops.simulation_runs', [
            'simulation_run_id' => $simulation['run']['simulationRunId'],
            'baseline_snapshot_id' => $simulation['run']['baselineSnapshotId'],
            'scope_key' => 'capacity_workbench',
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('ops.simulation_scenarios', [
            'simulation_run_id' => $simulation['run']['simulationRunId'],
            'scenario_key' => 'combined_capacity_plan',
            'status' => 'modeled',
        ]);
        $this->assertDatabaseHas('ops.simulation_results', [
            'metric_key' => 'net_beds',
            'unit' => 'beds',
        ]);
        $this->assertDatabaseHas('ops.nodes', [
            'canonical_key' => "bed:{$ids['bedId']}",
            'node_type' => 'bed',
        ]);
    }

    public function test_simulation_scenario_can_be_promoted_to_approval_gated_action_plan(): void
    {
        $user = User::factory()->create();
        $this->seedCapacityFixture();

        $simulation = $this->actingAs($user)
            ->getJson('/api/analytics/workbench')
            ->assertOk()
            ->json('data.simulation');
        $scenario = collect($simulation['scenarios'])->firstWhere('key', 'combined_capacity_plan');

        $response = $this->actingAs($user)
            ->postJson("/api/ops/simulation-scenarios/{$scenario['scenarioId']}/promote")
            ->assertOk()
            ->assertJsonPath('data.scenario.status', 'promoted')
            ->assertJsonPath('data.recommendation.status', 'draft')
            ->assertJsonPath('data.recommendation.actions.0.approvals.0.status', 'pending');

        $this->assertDatabaseHas('ops.recommendations', [
            'recommendation_type' => 'simulation_action_plan',
            'scope_key' => $scenario['scenarioUuid'],
            'created_by_source' => 'simulation:operations_simulation_service',
        ]);
        $this->assertDatabaseHas('ops.actions', [
            'action_type' => 'promote_simulation_action_plan',
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('ops.approvals', [
            'approval_id' => $response->json('data.recommendation.actions.0.approvals.0.approvalId'),
            'status' => 'pending',
        ]);
    }

    public function test_no_action_simulation_cannot_be_promoted(): void
    {
        $user = User::factory()->create();
        $this->seedCapacityFixture();

        $simulation = $this->actingAs($user)
            ->getJson('/api/analytics/workbench')
            ->assertOk()
            ->json('data.simulation');
        $scenario = collect($simulation['scenarios'])->firstWhere('key', 'no_action');

        $this->actingAs($user)
            ->postJson("/api/ops/simulation-scenarios/{$scenario['scenarioId']}/promote")
            ->assertStatus(409)
            ->assertJsonPath('error', 'The no-action scenario cannot be promoted.');
    }

    public function test_simulation_promotion_requires_authentication(): void
    {
        $this->postJson('/api/ops/simulation-scenarios/1/promote')->assertUnauthorized();
    }

    /** @return array<string,int> */
    private function seedCapacityFixture(): array
    {
        $locationId = (int) DB::table('prod.locations')->insertGetId([
            'name' => 'Main Campus',
            'abbreviation' => 'MAIN',
            'type' => 'hospital',
            'pos_type' => 'inpatient',
            'active_status' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'location_id');

        $roomId = (int) DB::table('prod.rooms')->insertGetId([
            'location_id' => $locationId,
            'name' => 'Room 801',
            'type' => 'general',
            'active_status' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'room_id');

        $unitId = (int) DB::table('prod.units')->insertGetId([
            'name' => '8 West',
            'abbreviation' => '8W',
            'type' => 'med_surg',
            'staffed_bed_count' => 20,
            'ratio_floor' => 4,
            'access_standard_minutes' => 120,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'unit_id');

        $bedId = (int) DB::table('prod.beds')->insertGetId([
            'unit_id' => $unitId,
            'label' => '8W-01',
            'status' => 'dirty',
            'bed_type' => 'standard',
            'isolation_capable' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'bed_id');

        DB::table('prod.census_snapshots')->insert([
            'unit_id' => $unitId,
            'captured_at' => now()->toDateTimeString(),
            'staffed_beds' => 20,
            'occupied' => 18,
            'available' => 1,
            'blocked' => 2,
            'acuity_adjusted_capacity' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('prod.bed_requests')->insert([
            'patient_ref' => 'sim-bed-request-1',
            'source' => 'ed',
            'service' => 'Medicine',
            'acuity_tier' => 3,
            'required_unit_type' => 'med_surg',
            'status' => 'pending',
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('prod.bed_requests')->insert([
            'patient_ref' => 'sim-bed-request-2',
            'source' => 'ed',
            'service' => 'Medicine',
            'acuity_tier' => 3,
            'required_unit_type' => 'med_surg',
            'status' => 'pending',
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('prod.ed_visits')->insert([
            'patient_ref' => 'sim-ed-boarder-1',
            'arrived_at' => now()->subHours(6),
            'provider_seen_at' => now()->subHours(5),
            'disposition' => 'admitted',
            'admit_decision_at' => now()->subHours(4),
            'bed_assigned_at' => null,
            'unit_id' => $unitId,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('prod.rtdc_predictions')->insert([
            'unit_id' => $unitId,
            'service_date' => Carbon::today()->toDateString(),
            'horizon' => 'by_2pm',
            'discharges_definite' => 1,
            'discharges_probable' => 1,
            'discharges_possible' => 1,
            'discharges_weighted' => 2.5,
            'demand_ed' => 3,
            'demand_or' => 1,
            'demand_transfer' => 1,
            'demand_direct' => 0,
            'demand_expected' => 5,
            'capacity_now' => 1,
            'bed_need' => 4,
            'status' => 'open',
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('prod.transport_requests')->insert([
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'inpatient',
            'priority' => 'stat',
            'status' => 'requested',
            'patient_ref' => 'sim-transport-1',
            'origin' => 'ED Bay 4',
            'destination' => '8 West',
            'transport_mode' => 'stretcher',
            'requested_at' => now(),
            'needed_at' => now()->subMinutes(10),
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('prod.evs_requests')->insert([
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'bed_clean',
            'priority' => 'stat',
            'status' => 'requested',
            'room_id' => $roomId,
            'bed_id' => $bedId,
            'unit_id' => $unitId,
            'location_label' => '8W-01',
            'turn_type' => 'standard',
            'requested_at' => now(),
            'needed_at' => now()->subMinutes(5),
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('locationId', 'roomId', 'unitId', 'bedId');
    }
}
