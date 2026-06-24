<?php

namespace Tests\Feature\Transport;

use App\Models\Transport\TransportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransportRequestApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_assign_update_and_handoff_transport_request(): void
    {
        $user = User::factory()->create();

        $created = $this->actingAs($user)->postJson('/api/transport/requests', [
            'request_type' => 'inpatient',
            'priority' => 'urgent',
            'patient_ref' => 'patient-transport-1',
            'encounter_ref' => 'enc-transport-1',
            'origin' => 'ED Bay 12',
            'destination' => 'CT Scanner 2',
            'transport_mode' => 'stretcher',
            'clinical_service' => 'Emergency',
            'needed_at' => now()->addMinutes(20)->toISOString(),
            'risk_flags' => ['oxygen', 'fall-risk'],
        ])->assertCreated()->json('data');

        $this->assertSame('requested', $created['status']);
        $this->assertDatabaseHas('prod.transport_requests', [
            'transport_request_id' => $created['transport_request_id'],
            'patient_ref' => 'patient-transport-1',
            'status' => 'requested',
        ]);
        $this->assertDatabaseHas('prod.transport_events', [
            'transport_request_id' => $created['transport_request_id'],
            'event_type' => 'transport.requested',
        ]);

        $this->actingAs($user)->postJson("/api/transport/requests/{$created['transport_request_id']}/assign", [
            'assigned_team' => 'Porter Pool',
        ])->assertOk()->assertJsonPath('data.status', 'assigned');

        $this->actingAs($user)->postJson("/api/transport/requests/{$created['transport_request_id']}/status", [
            'status' => 'dispatched',
        ])->assertOk()->assertJsonPath('data.status', 'dispatched');

        $this->actingAs($user)->postJson("/api/transport/requests/{$created['transport_request_id']}/handoff", [
            'handoff_to' => 'CT Charge RN',
            'handoff_summary' => 'Oxygen continued during move.',
        ])->assertOk()->assertJsonPath('data.status', 'handoff_complete');

        $this->assertDatabaseHas('prod.transport_events', [
            'transport_request_id' => $created['transport_request_id'],
            'event_type' => 'transport.handoff_complete',
        ]);
    }

    public function test_overview_reports_active_transport_mix(): void
    {
        $user = User::factory()->create();

        TransportRequest::create([
            'request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'request_type' => 'transfer',
            'priority' => 'stat',
            'status' => 'requested',
            'patient_ref' => 'transfer-patient',
            'origin' => 'Community ED',
            'destination' => 'Zephyrus ICU',
            'transport_mode' => 'critical_care',
            'requested_at' => now(),
            'needed_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($user)->getJson('/api/transport/overview')
            ->assertOk()
            ->assertJsonPath('data.metrics.active', 1)
            ->assertJsonPath('data.metrics.at_risk', 1)
            ->assertJsonPath('data.metrics.transfer_backlog', 1)
            ->assertJsonPath('data.metrics.stat', 1);
    }

    public function test_transport_endpoints_require_authentication(): void
    {
        $this->getJson('/api/transport/overview')->assertUnauthorized();
    }

    public function test_create_rejects_invalid_mode(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/transport/requests', [
            'request_type' => 'discharge',
            'priority' => 'routine',
            'patient_ref' => 'patient-invalid',
            'origin' => '6 West',
            'destination' => 'Home',
            'transport_mode' => 'spaceship',
        ])->assertStatus(422);
    }
}
