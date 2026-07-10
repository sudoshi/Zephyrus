<?php

namespace Tests\Feature\Staffing;

use App\Models\Staffing\StaffingPlan;
use App\Models\Staffing\StaffingRequest;
use App\Models\User;
use App\Services\Demo\OperationalDemoDataService;
use Database\Seeders\RtdcSeeder;
use Database\Seeders\StaffingReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_sla_preserves_whole_second_precision(): void
    {
        Carbon::setTestNow('2026-07-09T16:00:00Z');

        try {
            $user = User::factory()->create();
            $unitId = $this->seedUnit('7 West', '7W');

            $this->actingAs($user)->postJson('/api/staffing/requests', [
                'unit_id' => $unitId,
                'unit_label' => '7 West',
                'role' => 'rn',
                'shift' => 'day',
                'request_type' => 'fill_gap',
                'priority' => 'urgent',
                'headcount_needed' => 1,
                'needed_by' => now()->addSeconds(91)->toISOString(),
            ])->assertCreated()
                ->assertJsonPath('data.sla.label', '1 min 31 sec remaining');
        } finally {
            Carbon::setTestNow();
        }
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

    public function test_empty_staffing_maps_are_json_objects_and_missing_coverage_is_unknown(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/staffing/requests', [
            'unit_label' => '7 West',
            'role' => 'rn',
            'shift' => 'day',
            'request_type' => 'fill_gap',
            'priority' => 'routine',
            'headcount_needed' => 1,
        ])->assertCreated();

        $decoded = json_decode($response->getContent());
        $this->assertInstanceOf(\stdClass::class, $decoded->data->resolution_payload);
        $this->assertInstanceOf(\stdClass::class, $decoded->data->metadata);

        $this->actingAs($user)->getJson('/api/staffing/overview')
            ->assertOk()
            ->assertJsonPath('data.coverage.coverage_pct', null)
            ->assertJsonPath('data.metrics.coverage_pct', null)
            ->assertJsonPath('data.workforce.metrics.active_members', 0)
            ->assertJsonPath('data.resource_options.0.available', null)
            ->assertJsonPath('data.source.status', 'fresh');
    }

    public function test_staffing_overview_and_directory_expose_the_canonical_workforce(): void
    {
        Carbon::setTestNow('2026-07-09 12:00:00 America/New_York');
        $this->seed(RtdcSeeder::class);
        $this->seed(StaffingReferenceSeeder::class);
        $result = app(OperationalDemoDataService::class)->rollForward();
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/staffing/overview')
            ->assertOk()
            ->assertJsonPath('data.workforce.available', true)
            ->assertJsonPath('data.workforce.metrics.active_members', $result['workforce_active'])
            ->assertJsonPath('data.workforce.metrics.inactive_members', 12)
            ->assertJsonPath('data.workforce.metrics.unit_count', 25)
            ->assertJsonPath('data.workforce.assumptions.productive_hours_per_fte', 1664)
            ->assertJsonPath('data.workforce.assumptions.relief_factor', 1.18)
            ->assertJsonPath('data.source.synthetic', true);

        $directory = $this->actingAs($user)->getJson('/api/staffing/workforce?role=critical_care_nurse&shift=night&status=active&per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('data.0.role_code', 'critical_care_nurse')
            ->assertJsonPath('data.0.preferred_shift', 'night')
            ->assertJsonPath('data.0.is_active', true)
            ->json();
        $this->assertGreaterThan(10, $directory['meta']['total']);

        Carbon::setTestNow();
    }

    public function test_old_synthetic_request_is_expired_instead_of_thousands_of_minutes_overdue(): void
    {
        $user = User::factory()->create();
        $request = StaffingRequest::create([
            'request_uuid' => (string) Str::uuid(),
            'unit_label' => '6 East',
            'role' => 'rn',
            'shift_date' => now()->subDays(5)->toDateString(),
            'shift' => 'day',
            'request_type' => 'fill_gap',
            'priority' => 'urgent',
            'status' => 'open',
            'headcount_needed' => 2,
            'requested_by' => 'demo-seeder',
            'needed_by' => now()->subDays(5),
            'is_deleted' => false,
        ]);

        $this->actingAs($user)->getJson('/api/staffing/requests')
            ->assertOk()
            ->assertJsonPath('data.0.staffing_request_id', $request->staffing_request_id)
            ->assertJsonPath('data.0.freshness_status', 'expired')
            ->assertJsonPath('data.0.sla.at_risk', false)
            ->assertJsonPath('data.0.sla.label', 'Expired synthetic request');
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
