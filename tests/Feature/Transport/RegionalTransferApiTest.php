<?php

namespace Tests\Feature\Transport;

use App\Models\Transport\TransportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class RegionalTransferApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_regional_transfer_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('regional.facilities'));
        $this->assertTrue(Schema::hasColumn('regional.facilities', 'building_key'));
        $this->assertTrue(Schema::hasColumn('regional.facilities', 'service_area_key'));
        $this->assertTrue(Schema::hasColumn('regional.facilities', 'is_external'));
        $this->assertTrue(Schema::hasTable('regional.network_model_versions'));
        $this->assertTrue(Schema::hasTable('regional.facility_capabilities'));
        $this->assertTrue(Schema::hasTable('regional.transfer_decisions'));
        $this->assertTrue(Schema::hasTable('regional.route_simulation_runs'));
    }

    public function test_regional_summary_seeds_network_and_scores_active_transfers(): void
    {
        $user = User::factory()->create();
        $transfer = $this->transferRequest([
            'priority' => 'stat',
            'clinical_service' => 'Critical Care',
            'transport_mode' => 'critical_care',
            'risk_flags' => ['monitor'],
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/transport/regional-summary')
            ->assertOk()
            ->assertJsonPath('data.counts.networkFacilities', 5)
            ->assertJsonPath('data.counts.externalFacilities', 1)
            ->assertJsonPath('data.counts.modelVersions', 3)
            ->assertJsonPath('data.counts.routeScenarios', 4)
            ->assertJsonPath('data.counts.activeTransfers', 1)
            ->assertJsonPath('data.recommendations.0.transportRequestId', $transfer->transport_request_id);

        $candidates = $response->json('data.recommendations.0.candidates');
        $this->assertNotEmpty($candidates);
        $this->assertSame('zephyrus_main', $candidates[0]['facilityCode']);
        $this->assertGreaterThanOrEqual(75, $candidates[0]['score']);
        $this->assertDatabaseHas('regional.facilities', [
            'facility_code' => 'zephyrus_main',
            'accepts_transfers' => true,
            'building_key' => 'main_tower',
        ]);
        $this->assertDatabaseHas('regional.facility_capabilities', [
            'capability_key' => 'critical_care_transport',
        ]);
        $this->assertDatabaseHas('regional.network_model_versions', [
            'version_key' => 'critical-care-surge-v1',
            'status' => 'approved',
        ]);
        $this->assertSame('phase8-network-v1', $response->json('data.routeSimulation.modelVersionKey'));
        $this->assertSame('transfer_center_agent', $response->json('data.transferCenterAgent.agentKey'));
        $this->assertNotEmpty($response->json('data.comparison'));
    }

    public function test_regional_decision_records_selected_candidate_and_transport_event(): void
    {
        $user = User::factory()->create();
        $transfer = $this->transferRequest();

        $response = $this->actingAs($user)
            ->postJson("/api/transport/requests/{$transfer->transport_request_id}/regional-decision", [
                'selected_facility_code' => 'zephyrus_main',
                'decision_status' => 'accepted',
                'note' => 'Accept with ICU bed 4E and critical-care transport.',
            ])
            ->assertOk()
            ->assertJsonPath('data.transportRequestId', $transfer->transport_request_id)
            ->assertJsonPath('data.decisionStatus', 'accepted')
            ->assertJsonPath('data.selectedFacility.facilityCode', 'zephyrus_main');

        $this->assertDatabaseHas('regional.transfer_decisions', [
            'transfer_decision_id' => $response->json('data.decisionId'),
            'transport_request_id' => $transfer->transport_request_id,
            'decision_status' => 'accepted',
        ]);
        $this->assertDatabaseHas('prod.transport_events', [
            'transport_request_id' => $transfer->transport_request_id,
            'event_type' => 'regional.transfer_decision',
            'source' => 'regional_transfer_service',
        ]);
        $this->assertSame('zephyrus_main', $transfer->refresh()->metadata['regional_transfer']['selected_facility_code']);
    }

    public function test_regional_route_simulation_run_is_persisted(): void
    {
        $user = User::factory()->create();
        $this->transferRequest();

        $response = $this->actingAs($user)
            ->postJson('/api/transport/regional-simulation', [
                'model_version_key' => 'transport-staffed-up-v1',
            ])
            ->assertOk()
            ->assertJsonPath('data.modelVersionKey', 'transport-staffed-up-v1')
            ->assertJsonCount(4, 'data.scenarios');

        $this->assertDatabaseHas('regional.route_simulation_runs', [
            'route_simulation_run_id' => $response->json('data.runId'),
            'model_version_key' => 'transport-staffed-up-v1',
        ]);
    }

    public function test_transfer_center_agent_drafts_audited_recommendation(): void
    {
        $user = User::factory()->create();
        $transfer = $this->transferRequest([
            'priority' => 'stat',
            'clinical_service' => 'Critical Care',
            'transport_mode' => 'critical_care',
            'risk_flags' => ['monitor'],
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/transport/requests/{$transfer->transport_request_id}/regional-agent-draft")
            ->assertOk()
            ->assertJsonPath('data.transportRequestId', $transfer->transport_request_id)
            ->assertJsonPath('data.decisionStatus', 'draft')
            ->assertJsonPath('data.recommendedDecision', 'accepted')
            ->assertJsonPath('data.selectedFacility.facilityCode', 'zephyrus_main');

        $this->assertDatabaseHas('regional.transfer_decisions', [
            'transfer_decision_id' => $response->json('data.decisionId'),
            'transport_request_id' => $transfer->transport_request_id,
            'decision_status' => 'draft',
        ]);
        $this->assertDatabaseHas('prod.transport_events', [
            'transport_request_id' => $transfer->transport_request_id,
            'event_type' => 'regional.transfer_agent_draft',
            'source' => 'regional_transfer_service',
        ]);
        $this->assertSame('accepted', $transfer->refresh()->metadata['regional_agent_draft']['recommended_decision']);
    }

    public function test_regional_decision_rejects_non_transfer_requests(): void
    {
        $user = User::factory()->create();
        $request = $this->transferRequest(['request_type' => 'inpatient']);

        $this->actingAs($user)
            ->postJson("/api/transport/requests/{$request->transport_request_id}/regional-decision", [
                'selected_facility_code' => 'zephyrus_main',
                'decision_status' => 'accepted',
            ])
            ->assertStatus(422);
    }

    public function test_regional_transfer_endpoints_require_authentication(): void
    {
        $this->getJson('/api/transport/regional-summary')->assertUnauthorized();
        $this->postJson('/api/transport/regional-simulation')->assertUnauthorized();
        $this->postJson('/api/transport/requests/1/regional-agent-draft')->assertUnauthorized();
    }

    /** @param array<string,mixed> $overrides */
    private function transferRequest(array $overrides = []): TransportRequest
    {
        return TransportRequest::create(array_merge([
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'transfer',
            'priority' => 'urgent',
            'status' => 'requested',
            'patient_ref' => 'transfer-patient-regional',
            'origin' => 'Community Hospital ED',
            'destination' => 'Zephyrus ICU',
            'transport_mode' => 'critical_care',
            'clinical_service' => 'Critical Care',
            'requested_at' => now(),
            'needed_at' => now()->addMinutes(35),
            'risk_flags' => ['monitor'],
            'metadata' => [],
        ], $overrides));
    }
}
