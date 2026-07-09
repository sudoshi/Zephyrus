<?php

namespace Tests\Feature\Transport;

use App\Models\Transport\TransportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            'requested_by' => 'operations-demo:summit-500-current-operations-v1',
            'metadata' => ['data_origin' => 'synthetic'],
        ]);

        $this->actingAs($user)->getJson('/api/transport/overview')
            ->assertOk()
            ->assertJsonPath('data.metrics.active', 1)
            ->assertJsonPath('data.metrics.at_risk', 1)
            ->assertJsonPath('data.metrics.transfer_backlog', 1)
            ->assertJsonPath('data.metrics.stat', 1)
            ->assertJsonPath('data.source.synthetic', true);
    }

    public function test_transport_endpoints_require_authentication(): void
    {
        $this->getJson('/api/transport/overview')->assertUnauthorized();
    }

    public function test_empty_overview_count_maps_are_json_objects(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/transport/overview')
            ->assertOk();

        $decoded = json_decode($response->getContent());
        $this->assertInstanceOf(\stdClass::class, $decoded->data->by_type);
        $this->assertInstanceOf(\stdClass::class, $decoded->data->by_status);
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

    public function test_empty_transport_maps_are_json_objects(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/transport/requests', [
            'request_type' => 'inpatient',
            'priority' => 'routine',
            'patient_ref' => 'patient-map-contract',
            'origin' => '6 East',
            'destination' => 'CT 2',
            'transport_mode' => 'stretcher',
        ])->assertCreated();

        $decoded = json_decode($response->getContent());
        $this->assertInstanceOf(\stdClass::class, $decoded->data->handoff);
        $this->assertInstanceOf(\stdClass::class, $decoded->data->metadata);
    }

    public function test_completed_today_uses_completion_event_time_not_request_date(): void
    {
        $user = User::factory()->create();
        $request = TransportRequest::create([
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'inpatient',
            'priority' => 'routine',
            'status' => 'completed',
            'patient_ref' => 'completed-today',
            'origin' => '6 East',
            'destination' => 'MRI',
            'transport_mode' => 'stretcher',
            'requested_at' => now()->subDay(),
            'completed_at' => now(),
            'is_deleted' => false,
        ]);
        DB::table('prod.transport_events')->insert([
            'event_uuid' => (string) Str::uuid(),
            'transport_request_id' => $request->transport_request_id,
            'event_type' => 'transport.completed',
            'from_status' => 'arrived_destination',
            'to_status' => 'completed',
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $this->actingAs($user)->getJson('/api/transport/overview')
            ->assertOk()
            ->assertJsonPath('data.metrics.completed_today', 1);
    }

    public function test_vendor_share_uses_explicit_vendor_assignment(): void
    {
        $internal = TransportRequest::create([
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'inpatient',
            'priority' => 'routine',
            'status' => 'assigned',
            'patient_ref' => 'internal-team',
            'origin' => 'A',
            'destination' => 'B',
            'transport_mode' => 'wheelchair',
            'requested_at' => now(),
            'assigned_team' => 'Summit Patient Transport',
            'is_deleted' => false,
        ]);
        TransportRequest::create([
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'discharge',
            'priority' => 'routine',
            'status' => 'assigned',
            'patient_ref' => 'external-vendor',
            'origin' => 'A',
            'destination' => 'Home',
            'transport_mode' => 'nemt',
            'requested_at' => now(),
            'assigned_vendor' => 'Ride Health',
            'is_deleted' => false,
        ]);

        $measure = collect(app(\App\Services\Transport\TransportOperationsService::class)->measures())
            ->firstWhere('key', 'vendor_acceptance_cancellation');

        $this->assertNotNull($internal);
        $this->assertSame(50.0, (float) $measure['value']);
    }

    public function test_dispatch_scope_filters_before_pagination(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 55; $i++) {
            TransportRequest::create([
                'request_uuid' => (string) Str::uuid(),
                'request_type' => 'inpatient',
                'priority' => 'stat',
                'status' => 'completed',
                'patient_ref' => "history-{$i}",
                'origin' => 'A',
                'destination' => 'B',
                'transport_mode' => 'wheelchair',
                'requested_at' => now()->subDay(),
                'completed_at' => now()->subDay(),
                'is_deleted' => false,
            ]);
        }
        TransportRequest::create([
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'inpatient',
            'priority' => 'routine',
            'status' => 'requested',
            'patient_ref' => 'live-dispatch',
            'origin' => '6 East',
            'destination' => 'CT 2',
            'transport_mode' => 'stretcher',
            'requested_at' => now(),
            'needed_at' => now()->addMinutes(20),
            'is_deleted' => false,
        ]);

        $this->actingAs($user)->getJson('/api/transport/requests?scope=dispatch')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.patient_ref', 'live-dispatch')
            ->assertJsonPath('meta.total', 1);
    }
}
