<?php

namespace Tests\Feature\Ops;

use App\Models\User;
use App\Services\Ops\OperationsGraphProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationsGraphTest extends TestCase
{
    use RefreshDatabase;

    public function test_ops_graph_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('ops.nodes'));
        $this->assertTrue(Schema::hasTable('ops.edges'));
        $this->assertTrue(Schema::hasTable('ops.state_snapshots'));
        $this->assertTrue(Schema::hasTable('ops.constraints'));
        $this->assertTrue(Schema::hasTable('ops.recommendations'));
        $this->assertTrue(Schema::hasTable('ops.actions'));
        $this->assertTrue(Schema::hasTable('ops.approvals'));
    }

    public function test_projector_builds_graph_from_operational_tables(): void
    {
        $ids = $this->seedOperationalFixture();

        $snapshot = app(OperationsGraphProjector::class)->rebuild();

        $this->assertSame(10, $snapshot->node_count);
        $this->assertSame(9, $snapshot->edge_count);
        $this->assertDatabaseHas('ops.nodes', ['canonical_key' => "unit:{$ids['unitId']}", 'node_type' => 'unit']);
        $this->assertDatabaseHas('ops.nodes', ['canonical_key' => "bed:{$ids['bedId']}", 'node_type' => 'bed']);
        $this->assertDatabaseHas('ops.nodes', ['canonical_key' => "encounter:{$ids['encounterId']}", 'node_type' => 'encounter']);
        $this->assertDatabaseHas('ops.nodes', ['canonical_key' => "ed_visit:{$ids['edVisitId']}", 'node_type' => 'ed_visit']);
        $this->assertDatabaseHas('ops.nodes', ['canonical_key' => "bed_request:{$ids['bedRequestId']}", 'node_type' => 'bed_request']);
        $this->assertDatabaseHas('ops.nodes', ['canonical_key' => "barrier:{$ids['barrierId']}", 'node_type' => 'barrier']);
        $this->assertDatabaseHas('ops.nodes', ['canonical_key' => "transport_request:{$ids['transportRequestId']}", 'node_type' => 'transport_request']);

        foreach ([
            'located_in',
            'contains_bed',
            'assigned_to_unit',
            'occupies_bed',
            'admits_to_unit',
            'requests_bed_for',
            'blocks_encounter',
            'impacts_unit',
            'moves_patient_for',
        ] as $edgeType) {
            $this->assertDatabaseHas('ops.edges', ['edge_type' => $edgeType, 'is_active' => true]);
        }

        $payload = $snapshot->state_payload;
        $this->assertSame(1, $payload['by_type']['unit']);
        $this->assertSame(1, $payload['by_type']['bed']);
        $this->assertSame(1, $payload['edge_types']['contains_bed']);
    }

    public function test_ops_graph_api_returns_snapshot_and_node_timeline(): void
    {
        $user = User::factory()->create();
        $ids = $this->seedOperationalFixture();

        $this->actingAs($user)->getJson('/api/ops/graph/snapshot')
            ->assertOk()
            ->assertJsonPath('data.by_type.unit', 1)
            ->assertJsonPath('data.by_type.encounter', 1)
            ->assertJsonPath('data.edge_types.contains_bed', 1)
            ->assertJsonPath('data.edge_types.moves_patient_for', 1);

        $unitNodeId = DB::table('ops.nodes')
            ->where('canonical_key', "unit:{$ids['unitId']}")
            ->value('graph_node_id');

        $response = $this->actingAs($user)->getJson("/api/ops/graph/nodes/{$unitNodeId}")
            ->assertOk()
            ->assertJsonPath('data.node.canonical_key', "unit:{$ids['unitId']}")
            ->assertJsonPath('data.node.current_state.latest_census.occupied', 1);

        $data = $response->json('data');
        $this->assertContains('contains_bed', array_column($data['outgoing_edges'], 'edge_type'));
        $this->assertContains('assigned_to_unit', array_column($data['incoming_edges'], 'edge_type'));
        $this->assertContains('source_projection', array_column($data['timeline'], 'event_type'));
    }

    public function test_ops_graph_api_requires_authentication(): void
    {
        $this->getJson('/api/ops/graph/snapshot')->assertUnauthorized();
    }

    /** @return array<string,int> */
    private function seedOperationalFixture(): array
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
            'name' => 'Room 701',
            'type' => 'general',
            'active_status' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'room_id');

        DB::table('prod.services')->insert([
            'name' => 'Hospital Medicine',
            'code' => 'HOSP',
            'active_status' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $unitId = (int) DB::table('prod.units')->insertGetId([
            'name' => '7 West',
            'abbreviation' => '7W',
            'type' => 'med_surg',
            'staffed_bed_count' => 24,
            'ratio_floor' => 4,
            'access_standard_minutes' => 120,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'unit_id');

        $bedId = (int) DB::table('prod.beds')->insertGetId([
            'unit_id' => $unitId,
            'label' => '7W-01',
            'status' => 'occupied',
            'bed_type' => 'standard',
            'isolation_capable' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'bed_id');

        DB::table('prod.census_snapshots')->insert([
            'unit_id' => $unitId,
            'captured_at' => now()->toDateTimeString(),
            'staffed_beds' => 24,
            'occupied' => 1,
            'available' => 23,
            'blocked' => 0,
            'acuity_adjusted_capacity' => 24,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $encounterId = (int) DB::table('prod.encounters')->insertGetId([
            'patient_ref' => 'ops-patient-1',
            'unit_id' => $unitId,
            'bed_id' => $bedId,
            'admitted_at' => now()->subHours(2),
            'expected_discharge_date' => now()->addDay()->toDateString(),
            'acuity_tier' => 3,
            'status' => 'active',
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'encounter_id');

        $edVisitId = (int) DB::table('prod.ed_visits')->insertGetId([
            'patient_ref' => 'ops-patient-1',
            'arrived_at' => now()->subHours(6),
            'triaged_at' => now()->subHours(5),
            'esi_level' => 3,
            'provider_seen_at' => now()->subHours(4),
            'disposition' => 'admitted',
            'admit_decision_at' => now()->subHours(3),
            'bed_assigned_at' => now()->subHours(2),
            'unit_id' => $unitId,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'ed_visit_id');

        $bedRequestId = (int) DB::table('prod.bed_requests')->insertGetId([
            'patient_ref' => 'ops-patient-1',
            'source' => 'ed',
            'service' => 'Hospital Medicine',
            'acuity_tier' => 3,
            'isolation_required' => 'none',
            'required_unit_type' => 'med_surg',
            'status' => 'pending',
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'bed_request_id');

        $barrierId = (int) DB::table('prod.barriers')->insertGetId([
            'encounter_id' => $encounterId,
            'unit_id' => $unitId,
            'category' => 'placement',
            'reason_code' => 'awaiting_clean',
            'description' => 'Awaiting room readiness',
            'owner' => 'Flow RN',
            'status' => 'open',
            'opened_at' => now()->subHour(),
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'barrier_id');

        $transportRequestId = (int) DB::table('prod.transport_requests')->insertGetId([
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'inpatient',
            'priority' => 'urgent',
            'status' => 'requested',
            'patient_ref' => 'ops-patient-1',
            'encounter_ref' => "encounter:{$encounterId}",
            'origin' => 'ED Bay 12',
            'destination' => '7 West',
            'transport_mode' => 'stretcher',
            'requested_at' => now(),
            'needed_at' => now()->addMinutes(20),
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'transport_request_id');

        return compact(
            'locationId',
            'roomId',
            'unitId',
            'bedId',
            'encounterId',
            'edVisitId',
            'bedRequestId',
            'barrierId',
            'transportRequestId'
        );
    }
}
