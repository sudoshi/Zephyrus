<?php

namespace Tests\Feature\Staffing;

use App\Models\Staffing\StaffingPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_assign_and_fill_staffing_request(): void
    {
        $user = User::factory()->create();
        $unitId = $this->seedUnit('7 West', '7W');

        $created = $this->actingAs($user)->postJson('/api/staffing/requests', [
            'unit_id' => $unitId,
            'unit_label' => '7 West',
            'role' => 'rn',
            'shift' => 'day',
            'request_type' => 'fill_gap',
            'priority' => 'urgent',
            'headcount_needed' => 2,
            'hours_needed' => 12,
            'needed_by' => now()->addHours(2)->toISOString(),
        ])->assertCreated()->json('data');

        $this->assertSame('requested', $created['status']);
        $this->assertSame('Registered Nurse', $created['role_label']);
        $this->assertDatabaseHas('prod.staffing_requests', [
            'staffing_request_id' => $created['staffing_request_id'],
            'role' => 'rn',
            'status' => 'requested',
        ]);
        $this->assertDatabaseHas('prod.staffing_events', [
            'staffing_request_id' => $created['staffing_request_id'],
            'event_type' => 'staffing.requested',
        ]);

        $this->actingAs($user)->postJson("/api/staffing/requests/{$created['staffing_request_id']}/assign", [
            'assigned_source' => 'float_pool',
            'assigned_staff_ref' => 'float-rn-12',
            'owner_name' => 'House Supervisor',
        ])->assertOk()
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.assigned_source', 'float_pool');

        $this->actingAs($user)->postJson("/api/staffing/requests/{$created['staffing_request_id']}/status", [
            'status' => 'filled',
            'payload' => ['filled_by' => 'float-rn-12'],
            'note' => 'Float pool RN accepted the shift.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'filled')
            ->assertJsonPath('data.resolution_payload.filled_by', 'float-rn-12');

        $this->assertDatabaseHas('prod.staffing_events', [
            'staffing_request_id' => $created['staffing_request_id'],
            'event_type' => 'staffing.filled',
        ]);
    }

    public function test_overview_reports_units_at_risk_and_coverage(): void
    {
        $user = User::factory()->create();
        $unit7w = $this->seedUnit('7 West', '7W');
        $unit3e = $this->seedUnit('3 East ICU', '3E');

        // 7 West RN day shift short by 2 (and below minimum safe).
        $this->seedPlan($unit7w, '7 West', 'rn', required: 6, scheduled: 4, actual: 4, minimumSafe: 5, status: 'gap');
        // 3 East ICU RN day shift short by 1.
        $this->seedPlan($unit3e, '3 East ICU', 'rn', required: 5, scheduled: 4, actual: 4, minimumSafe: 4, status: 'gap');
        // A fully covered unit should not appear at risk.
        $this->seedPlan($unit7w, '7 West', 'tech', required: 2, scheduled: 2, actual: 2, minimumSafe: 1, status: 'balanced');

        $this->actingAs($user)->getJson('/api/staffing/overview')
            ->assertOk()
            ->assertJsonPath('data.metrics.at_risk_units', 2)
            ->assertJsonPath('data.metrics.total_gap_headcount', 3)
            ->assertJsonPath('data.coverage.coverage_pct', 77); // 10 available / 13 required
    }

    public function test_plans_endpoint_returns_unit_risk_rollup(): void
    {
        $user = User::factory()->create();
        $unitId = $this->seedUnit('5 South', '5S');
        $this->seedPlan($unitId, '5 South', 'rn', required: 5, scheduled: 3, actual: 3, minimumSafe: 4, status: 'critical_gap');

        $this->actingAs($user)->getJson('/api/staffing/plans')
            ->assertOk()
            ->assertJsonPath('data.units_at_risk.0.unit_label', '5 South')
            ->assertJsonPath('data.units_at_risk.0.gap_headcount', 2)
            ->assertJsonPath('data.units_at_risk.0.below_minimum_safe', true);
    }

    public function test_staffing_endpoints_require_authentication(): void
    {
        $this->getJson('/api/staffing/overview')->assertUnauthorized();
    }

    public function test_create_rejects_invalid_role(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/staffing/requests', [
            'unit_label' => '7 West',
            'role' => 'wizard',
            'shift' => 'day',
            'request_type' => 'fill_gap',
            'priority' => 'routine',
            'headcount_needed' => 1,
        ])->assertStatus(422);
    }

    private function seedUnit(string $name, string $abbreviation): int
    {
        return (int) DB::table('prod.units')->insertGetId([
            'name' => $name,
            'abbreviation' => $abbreviation,
            'type' => 'med_surg',
            'staffed_bed_count' => 24,
            'ratio_floor' => 4,
            'access_standard_minutes' => 120,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'unit_id');
    }

    private function seedPlan(
        int $unitId,
        string $unitLabel,
        string $role,
        int $required,
        int $scheduled,
        int $actual,
        int $minimumSafe,
        string $status,
    ): void {
        StaffingPlan::create([
            'plan_uuid' => (string) Str::uuid(),
            'unit_id' => $unitId,
            'unit_label' => $unitLabel,
            'role' => $role,
            'shift_date' => now()->toDateString(),
            'shift' => 'day',
            'required_count' => $required,
            'scheduled_count' => $scheduled,
            'actual_count' => $actual,
            'minimum_safe_count' => $minimumSafe,
            'census' => 22,
            'ratio_target' => 4,
            'status' => $status,
            'is_deleted' => false,
        ]);
    }
}
