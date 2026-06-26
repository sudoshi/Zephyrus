<?php

namespace Tests\Feature\Evs;

use App\Models\Evs\EvsRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvsRequestApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_assign_update_and_complete_evs_request(): void
    {
        $user = User::factory()->create();
        $ids = $this->seedLocationFixture();

        $created = $this->actingAs($user)->postJson('/api/evs/requests', [
            'request_type' => 'bed_clean',
            'priority' => 'urgent',
            'room_id' => $ids['roomId'],
            'bed_id' => $ids['bedId'],
            'unit_id' => $ids['unitId'],
            'patient_ref' => 'evs-patient-1',
            'location_label' => '7W-01',
            'turn_type' => 'standard',
            'needed_at' => now()->addMinutes(20)->toISOString(),
            'risk_flags' => ['discharge_turnover'],
        ])->assertCreated()->json('data');

        $this->assertSame('requested', $created['status']);
        $this->assertDatabaseHas('prod.evs_requests', [
            'evs_request_id' => $created['evs_request_id'],
            'location_label' => '7W-01',
            'status' => 'requested',
        ]);
        $this->assertDatabaseHas('prod.evs_events', [
            'evs_request_id' => $created['evs_request_id'],
            'event_type' => 'evs.requested',
        ]);

        $this->actingAs($user)->postJson("/api/evs/requests/{$created['evs_request_id']}/assign", [
            'assigned_team' => 'EVS Core Team',
        ])->assertOk()->assertJsonPath('data.status', 'assigned');

        $this->actingAs($user)->postJson("/api/evs/requests/{$created['evs_request_id']}/status", [
            'status' => 'in_progress',
        ])->assertOk()->assertJsonPath('data.status', 'in_progress');

        $this->actingAs($user)->postJson("/api/evs/requests/{$created['evs_request_id']}/status", [
            'status' => 'completed',
            'payload' => ['bed_released' => true],
            'note' => 'Bed terminal clean complete.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.completion_payload.bed_released', true);

        $this->assertDatabaseHas('prod.evs_events', [
            'evs_request_id' => $created['evs_request_id'],
            'event_type' => 'evs.completed',
        ]);
    }

    public function test_overview_reports_active_evs_mix(): void
    {
        $user = User::factory()->create();
        $ids = $this->seedLocationFixture();

        EvsRequest::create([
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'terminal_clean',
            'priority' => 'stat',
            'status' => 'requested',
            'room_id' => $ids['roomId'],
            'bed_id' => $ids['bedId'],
            'unit_id' => $ids['unitId'],
            'location_label' => '7W-01',
            'turn_type' => 'terminal',
            'isolation_required' => true,
            'requested_at' => now(),
            'needed_at' => now()->subMinutes(5),
            'is_deleted' => false,
        ]);

        $this->actingAs($user)->getJson('/api/evs/overview')
            ->assertOk()
            ->assertJsonPath('data.metrics.active', 1)
            ->assertJsonPath('data.metrics.at_risk', 1)
            ->assertJsonPath('data.metrics.stat', 1)
            ->assertJsonPath('data.metrics.isolation_cleans', 1);
    }

    public function test_evs_endpoints_require_authentication(): void
    {
        $this->getJson('/api/evs/overview')->assertUnauthorized();
    }

    public function test_create_rejects_invalid_turn_type(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/evs/requests', [
            'request_type' => 'bed_clean',
            'priority' => 'routine',
            'location_label' => '7W-01',
            'turn_type' => 'magic',
        ])->assertStatus(422);
    }

    /** @return array<string,int> */
    private function seedLocationFixture(): array
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
            'status' => 'dirty',
            'bed_type' => 'standard',
            'isolation_capable' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'bed_id');

        return compact('locationId', 'roomId', 'unitId', 'bedId');
    }
}
