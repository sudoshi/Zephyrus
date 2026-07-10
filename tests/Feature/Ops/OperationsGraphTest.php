<?php

namespace Tests\Feature\Ops;

use App\Models\User;
use App\Services\Ops\OperationsGraphProjector;
use App\Services\Ops\OperationsRecommendationService;
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
        $this->assertTrue(Schema::hasTable('ops.simulation_runs'));
        $this->assertTrue(Schema::hasTable('ops.simulation_scenarios'));
        $this->assertTrue(Schema::hasTable('ops.simulation_results'));
        $this->assertTrue(Schema::hasTable('ops.interventions'));
        $this->assertTrue(Schema::hasTable('ops.intervention_metrics'));
        $this->assertTrue(Schema::hasTable('ops.outcome_attribution'));
        $this->assertTrue(Schema::hasTable('ops.agent_definitions'));
        $this->assertTrue(Schema::hasTable('ops.agent_runs'));
        $this->assertTrue(Schema::hasTable('ops.agent_tool_calls'));
        $this->assertTrue(Schema::hasTable('ops.agent_approvals'));
        $this->assertTrue(Schema::hasTable('ops.agent_evaluations'));
        $this->assertTrue(Schema::hasTable('ops.agent_safety_events'));
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

    public function test_projector_serializes_rebuilds_with_a_transaction_scoped_lock(): void
    {
        $this->seedOperationalFixture();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(OperationsGraphProjector::class)->rebuild();

        $this->assertTrue(
            collect($queries)->contains(
                fn (string $sql): bool => str_contains($sql, 'pg_advisory_xact_lock')
            )
        );
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

    public function test_ops_recommendations_api_generates_graph_backed_draft_actions(): void
    {
        $user = User::factory()->create();
        $ids = $this->seedOperationalFixture();
        $boarderId = (int) DB::table('prod.ed_visits')->insertGetId([
            'patient_ref' => 'ops-boarder-1',
            'arrived_at' => now()->subHours(7),
            'triaged_at' => now()->subHours(6),
            'esi_level' => 3,
            'provider_seen_at' => now()->subHours(5),
            'disposition' => 'admitted',
            'admit_decision_at' => now()->subHours(4),
            'bed_assigned_at' => null,
            'unit_id' => $ids['unitId'],
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'ed_visit_id');
        DB::table('prod.transport_requests')
            ->where('transport_request_id', $ids['transportRequestId'])
            ->update([
                'priority' => 'stat',
                'needed_at' => now()->subMinutes(10),
                'updated_at' => now(),
            ]);

        $response = $this->actingAs($user)
            ->getJson('/api/ops/recommendations')
            ->assertOk()
            ->assertJsonPath('data.summary.draftActions', 4)
            ->assertJsonPath('data.summary.pendingApprovals', 4);

        $recommendations = collect($response->json('data.recommendations'));
        $this->assertContains('ed_boarding', $recommendations->pluck('type'));
        $this->assertContains('bed_pressure', $recommendations->pluck('type'));
        $this->assertContains('transport_sla_risk', $recommendations->pluck('type'));
        $this->assertContains('flow_barrier', $recommendations->pluck('type'));

        $edBoarding = $recommendations->firstWhere('type', 'ed_boarding');
        $this->assertContains("ed_visit:{$boarderId}", array_column($edBoarding['evidence']['graph_nodes'], 'canonicalKey'));
        $this->assertContains('prod.ed_visits', $edBoarding['evidence']['source_tables']);
        $this->assertSame('pending', $edBoarding['actions'][0]['approvals'][0]['status']);
        $this->assertSame('Capacity huddle', $edBoarding['actions'][0]['ownerName']);
        $this->assertNotNull($edBoarding['actions'][0]['expiresAtIso']);

        $this->assertDatabaseHas('ops.recommendations', [
            'recommendation_type' => 'ed_boarding',
            'scope_key' => 'ed',
            'status' => 'draft',
            'created_by_source' => 'rules:operations_recommendation_service',
        ]);
        $this->assertDatabaseHas('ops.actions', [
            'action_type' => 'create_capacity_huddle_item',
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('ops.approvals', [
            'status' => 'pending',
            'reason' => 'Human approval required before executing graph-backed operational action.',
        ]);
    }

    public function test_projector_builds_evs_request_nodes_and_edges(): void
    {
        $ids = $this->seedOperationalFixture();
        $evsRequestId = (int) DB::table('prod.evs_requests')->insertGetId([
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'bed_clean',
            'priority' => 'urgent',
            'status' => 'requested',
            'room_id' => $ids['roomId'],
            'bed_id' => $ids['bedId'],
            'unit_id' => $ids['unitId'],
            'patient_ref' => 'ops-patient-1',
            'location_label' => '7W-01',
            'turn_type' => 'standard',
            'requested_at' => now(),
            'needed_at' => now()->addMinutes(20),
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'evs_request_id');

        app(OperationsGraphProjector::class)->rebuild();

        $this->assertDatabaseHas('ops.nodes', [
            'canonical_key' => "evs_request:{$evsRequestId}",
            'node_type' => 'evs_request',
            'status' => 'requested',
        ]);
        foreach (['cleans_bed', 'cleans_room', 'supports_unit', 'cleans_for_encounter'] as $edgeType) {
            $this->assertDatabaseHas('ops.edges', ['edge_type' => $edgeType, 'is_active' => true]);
        }
    }

    public function test_ops_recommendations_include_blocked_beds_and_or_pacu_pressure(): void
    {
        $user = User::factory()->create();
        $ids = $this->seedOperationalFixture();
        DB::table('prod.beds')
            ->where('bed_id', $ids['bedId'])
            ->update(['status' => 'dirty', 'updated_at' => now()]);
        DB::table('prod.census_snapshots')
            ->where('unit_id', $ids['unitId'])
            ->update(['blocked' => 2, 'updated_at' => now()]);

        $or = $this->seedSurgicalFixture();
        $caseId = $this->insertOrCase($or, now()->toDateString(), $or['schedStatusId'], 'ops-surgery-1');
        DB::table('prod.or_logs')->insert([
            'case_id' => $caseId,
            'tracking_date' => now()->toDateString(),
            'or_in_time' => now()->subHours(4),
            'or_out_time' => now()->subHours(2),
            'pacu_in_time' => now()->subHours(2),
            'pacu_out_time' => null,
            'primary_procedure' => 'Laparoscopic procedure',
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/ops/recommendations')
            ->assertOk();
        $recommendations = collect($response->json('data.recommendations'));
        $blockedBeds = $recommendations->firstWhere('type', 'blocked_beds');
        $orPacu = $recommendations->firstWhere('type', 'or_pacu_pressure');

        $this->assertNotNull($blockedBeds);
        $this->assertNotNull($orPacu);
        $this->assertContains('prod.beds', $blockedBeds['evidence']['source_tables']);
        $this->assertContains('prod.evs_requests', $blockedBeds['evidence']['source_tables']);
        $this->assertContains('bed', array_column($blockedBeds['evidence']['graph_nodes'], 'nodeType'));
        $this->assertSame('request_evs_bed_readiness', $blockedBeds['actions'][0]['type']);
        $this->assertSame('pending', $blockedBeds['actions'][0]['approvals'][0]['status']);
        $this->assertContains('prod.or_logs', $orPacu['evidence']['source_tables']);
        $this->assertContains('or_case', array_column($orPacu['evidence']['graph_nodes'], 'nodeType'));
        $this->assertSame('protect_or_pacu_flow', $orPacu['actions'][0]['type']);

        $this->assertDatabaseHas('ops.recommendations', [
            'recommendation_type' => 'blocked_beds',
            'scope_key' => 'bed_readiness',
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('ops.actions', [
            'action_type' => 'protect_or_pacu_flow',
            'status' => 'draft',
        ]);
    }

    public function test_agent_inbox_lists_pending_approvals_and_active_actions(): void
    {
        $user = User::factory()->create();
        $this->seedOperationalFixture();

        $this->actingAs($user)
            ->getJson('/api/ops/agent-inbox')
            ->assertOk()
            ->assertJsonPath('data.summary.pendingApprovals', 2)
            ->assertJsonPath('data.summary.activeActions', 2)
            ->assertJsonPath('data.approvals.0.status', 'pending')
            ->assertJsonPath('data.actions.0.status', 'draft');

        $this->assertDatabaseCount('ops.state_snapshots', 0);
    }

    public function test_ops_action_lifecycle_can_approve_assign_start_and_complete(): void
    {
        $user = User::factory()->create();
        $this->seedOperationalFixture();

        $recommendations = $this->actingAs($user)
            ->getJson('/api/ops/recommendations')
            ->assertOk()
            ->json('data.recommendations');
        $action = collect($recommendations)->firstWhere('type', 'bed_pressure')['actions'][0];
        $approvalId = $action['approvals'][0]['approvalId'];
        $actionId = $action['actionId'];

        $this->actingAs($user)
            ->postJson("/api/ops/approvals/{$approvalId}/decision", [
                'decision' => 'approved',
                'reason' => 'Approved by capacity lead.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approvals.0.status', 'approved');

        $dueAt = now()->addMinutes(45)->seconds(0)->toISOString();
        $this->actingAs($user)
            ->postJson("/api/ops/actions/{$actionId}/assign", [
                'owner_name' => 'Bed placement lead',
                'due_at' => $dueAt,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.ownerName', 'Bed placement lead');

        $this->actingAs($user)
            ->postJson("/api/ops/actions/{$actionId}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'executing');

        $this->actingAs($user)
            ->postJson("/api/ops/actions/{$actionId}/complete", [
                'note' => 'Bed placement review completed.',
                'completion_payload' => ['resolved_bed_requests' => 2],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.completionPayload.resolved_bed_requests', 2)
            ->assertJsonPath('data.completionPayload.note', 'Bed placement review completed.');

        $this->assertDatabaseHas('ops.approvals', [
            'approval_id' => $approvalId,
            'status' => 'approved',
            'decided_by_user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('ops.actions', [
            'action_id' => $actionId,
            'status' => 'completed',
            'owner_name' => 'Bed placement lead',
        ]);
        $this->assertDatabaseHas('ops.recommendations', [
            'recommendation_type' => 'bed_pressure',
            'status' => 'completed',
        ]);
    }

    public function test_ops_action_lifecycle_rejects_assignment_before_approval(): void
    {
        $user = User::factory()->create();
        $this->seedOperationalFixture();

        $recommendations = $this->actingAs($user)
            ->getJson('/api/ops/recommendations')
            ->assertOk()
            ->json('data.recommendations');
        $actionId = collect($recommendations)->firstWhere('type', 'bed_pressure')['actions'][0]['actionId'];

        $this->actingAs($user)
            ->postJson("/api/ops/actions/{$actionId}/assign", [
                'owner_name' => 'Bed placement lead',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'Only approved actions can be assigned.');
    }

    public function test_ops_action_lifecycle_does_not_duplicate_approved_active_work_on_regeneration(): void
    {
        $user = User::factory()->create();
        $this->seedOperationalFixture();

        $recommendations = $this->actingAs($user)
            ->getJson('/api/ops/recommendations')
            ->assertOk()
            ->json('data.recommendations');
        $bedPressure = collect($recommendations)->firstWhere('type', 'bed_pressure');
        $approvalId = $bedPressure['actions'][0]['approvals'][0]['approvalId'];

        $this->actingAs($user)
            ->postJson("/api/ops/approvals/{$approvalId}/decision", [
                'decision' => 'approved',
            ])
            ->assertOk();

        $second = $this->actingAs($user)
            ->getJson('/api/ops/recommendations')
            ->assertOk()
            ->json('data.recommendations');
        $secondBedPressure = collect($second)->firstWhere('type', 'bed_pressure');

        $this->assertSame($bedPressure['recommendationUuid'], $secondBedPressure['recommendationUuid']);
        $this->assertSame($bedPressure['actions'][0]['actionUuid'], $secondBedPressure['actions'][0]['actionUuid']);
        $this->assertSame('approved', $secondBedPressure['status']);
        $this->assertSame('approved', $secondBedPressure['actions'][0]['status']);
        $this->assertSame(1, DB::table('ops.recommendations')->where('recommendation_type', 'bed_pressure')->count());
        $this->assertSame(1, DB::table('ops.actions')->where('action_type', 'review_bed_placement_gap')->count());
    }

    public function test_operations_recommendation_service_is_idempotent_for_open_drafts(): void
    {
        $this->seedOperationalFixture();

        $service = app(OperationsRecommendationService::class);
        $first = $service->generate();
        $second = $service->generate();

        $this->assertSame($first['summary']['total'], $second['summary']['total']);
        $this->assertSame(
            $first['recommendations'][0]['recommendationUuid'],
            $second['recommendations'][0]['recommendationUuid']
        );
        $this->assertSame(
            $first['recommendations'][0]['actions'][0]['actionUuid'],
            $second['recommendations'][0]['actions'][0]['actionUuid']
        );
        $this->assertSame(
            $first['recommendations'][0]['actions'][0]['approvals'][0]['approvalUuid'],
            $second['recommendations'][0]['actions'][0]['approvals'][0]['approvalUuid']
        );
    }

    public function test_ops_graph_api_requires_authentication(): void
    {
        $this->getJson('/api/ops/graph/snapshot')->assertUnauthorized();
        $this->getJson('/api/ops/recommendations')->assertUnauthorized();
        $this->getJson('/api/ops/agent-inbox')->assertUnauthorized();
        $this->postJson('/api/ops/approvals/1/decision', ['decision' => 'approved'])->assertUnauthorized();
        $this->postJson('/api/ops/actions/1/assign', ['owner_name' => 'Bed placement'])->assertUnauthorized();
    }

    /** @return array<string,int> */
    private function seedOperationalFixture(): array
    {
        DB::table('ops.source_freshness')->delete();

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

    /** @return array<string,int> */
    private function seedSurgicalFixture(): array
    {
        $locId = (int) DB::table('prod.locations')->insertGetId([
            'name' => 'Test OR Suite',
            'abbreviation' => 'TOR',
            'type' => 'surgical',
            'pos_type' => 'inpatient',
            'active_status' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'location_id');

        $specId = (int) DB::table('prod.specialties')->insertGetId([
            'name' => 'General Surgery',
            'code' => 'TGSURG',
            'active_status' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'specialty_id');

        $surgeonId = (int) DB::table('prod.providers')->insertGetId([
            'name' => 'Dr. Test',
            'npi' => '9999999999',
            'specialty_id' => $specId,
            'type' => 'surgeon',
            'active_status' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'provider_id');

        $serviceId = (int) DB::table('prod.services')->insertGetId([
            'name' => 'Test Surgery',
            'code' => 'TSURG',
            'active_status' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'service_id');

        $roomId = (int) DB::table('prod.rooms')->insertGetId([
            'location_id' => $locId,
            'name' => 'OR-T1',
            'type' => 'OR',
            'active_status' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'room_id');

        foreach ([
            [1, 'Scheduled', 'SCHED'],
            [2, 'In Progress', 'INPROG'],
            [3, 'Delayed', 'DELAY'],
            [4, 'Completed', 'COMP'],
            [5, 'Cancelled', 'CANC'],
        ] as [$sid, $name, $code]) {
            DB::table('prod.case_statuses')->updateOrInsert(
                ['status_id' => $sid],
                [
                    'name' => $name,
                    'code' => $code,
                    'active_status' => true,
                    'is_deleted' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $asaId = (int) DB::table('prod.asa_ratings')->insertGetId([
            'name' => 'ASA II',
            'code' => 'ASA2T',
            'description' => 'Test',
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'asa_id');

        $caseTypeId = (int) DB::table('prod.case_types')->insertGetId([
            'name' => 'Elective',
            'code' => 'ELECT',
            'active_status' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'case_type_id');

        $caseClassId = (int) DB::table('prod.case_classes')->insertGetId([
            'name' => 'Inpatient',
            'code' => 'INPT',
            'active_status' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'case_class_id');

        $patientClassId = (int) DB::table('prod.patient_classes')->insertGetId([
            'name' => 'Inpatient',
            'code' => 'INPAT',
            'active_status' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'patient_class_id');

        $schedStatusId = (int) DB::table('prod.case_statuses')->where('code', 'SCHED')->value('status_id');

        return compact(
            'locId',
            'roomId',
            'surgeonId',
            'serviceId',
            'asaId',
            'caseTypeId',
            'caseClassId',
            'patientClassId',
            'schedStatusId'
        );
    }

    private function insertOrCase(array $or, string $surgeryDate, int $statusId, string $patientId): int
    {
        return (int) DB::table('prod.or_cases')->insertGetId([
            'patient_id' => $patientId,
            'surgery_date' => $surgeryDate,
            'room_id' => $or['roomId'],
            'location_id' => $or['locId'],
            'primary_surgeon_id' => $or['surgeonId'],
            'case_service_id' => $or['serviceId'],
            'scheduled_start_time' => now()->addHour(),
            'scheduled_duration' => 120,
            'record_create_date' => now()->subDays(3),
            'status_id' => $statusId,
            'asa_rating_id' => $or['asaId'],
            'case_type_id' => $or['caseTypeId'],
            'case_class_id' => $or['caseClassId'],
            'patient_class_id' => $or['patientClassId'],
            'safety_status' => 'Normal',
            'journey_progress' => 0,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'case_id');
    }
}
