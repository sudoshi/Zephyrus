<?php

namespace Tests\Feature\Staffing;

use App\Models\Org\StaffAssignment;
use App\Models\Org\StaffMember;
use App\Models\Staffing\StaffingRequest;
use App\Models\Staffing\StaffShiftAssignment;
use App\Models\User;
use App\Services\Staffing\StaffingShiftWindowService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CanonicalStaffingFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00 America/New_York');
        $this->artisan('deployment:seed-registry');
        $this->artisan('deployment:seed-staff-roles');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_candidate_pagination_exposes_qualification_availability_and_conflict_states(): void
    {
        [$request, $unitId] = $this->staffingRequest();
        $eligible = $this->staffMember('A Eligible', $unitId);
        $unqualified = $this->staffMember('B Unqualified', $unitId);
        $unavailable = $this->staffMember('C Leave', $unitId);
        $conflicted = $this->staffMember('D Conflict', $unitId);
        $outsideFacility = $this->staffMember('E Other Facility', $unitId, [], 'OTHER_HOSPITAL');

        $this->requireRoleQualification();
        $this->qualify($eligible);
        $this->qualify($unavailable);
        $this->qualify($conflicted);
        $this->qualify($outsideFacility);
        $this->availability($eligible, $request, 'available');
        $this->availability($unqualified, $request, 'available');
        $this->availability($unavailable, $request, 'leave');
        $this->availability($conflicted, $request, 'available');
        $this->availability($outsideFacility, $request, 'available');
        $window = app(StaffingShiftWindowService::class)->forRequest($request);
        StaffShiftAssignment::create([
            'shift_assignment_uuid' => (string) Str::uuid(),
            'staff_member_id' => $conflicted->staff_member_id,
            'unit_id' => $unitId,
            'facility_key' => 'SUMMIT_REGIONAL',
            'service_line_code' => 'hospital_medicine',
            'role_code' => 'staff_nurse',
            'starts_at' => $window['starts_at'],
            'ends_at' => $window['ends_at'],
            'timezone' => $window['timezone'],
            'status' => 'accepted',
        ]);

        $user = User::factory()->create(['role' => 'user']);
        $first = $this->actingAs($user)
            ->getJson("/api/staffing/requests/{$request->staffing_request_id}/candidates?per_page=2")
            ->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.display_name', 'A Eligible')
            ->assertJsonPath('data.0.eligibility_state', 'eligible')
            ->json();
        $second = $this->actingAs($user)
            ->getJson("/api/staffing/requests/{$request->staffing_request_id}/candidates?per_page=2&page=2")
            ->assertOk()
            ->json();
        $this->assertSame([], array_intersect(
            array_column($first['data'], 'staff_member_id'),
            array_column($second['data'], 'staff_member_id'),
        ));

        $all = collect($this->actingAs($user)
            ->getJson("/api/staffing/requests/{$request->staffing_request_id}/candidates?per_page=100")
            ->assertOk()
            ->json('data'))
            ->keyBy('display_name');
        $this->assertSame('unqualified', $all['B Unqualified']['eligibility_state']);
        $this->assertContains('missing_required_qualification', $all['B Unqualified']['reason_codes']);
        $this->assertSame('unavailable', $all['C Leave']['eligibility_state']);
        $this->assertContains('unavailable_or_on_leave', $all['C Leave']['reason_codes']);
        $this->assertSame('conflicted', $all['D Conflict']['eligibility_state']);
        $this->assertContains('overlapping_shift_assignment', $all['D Conflict']['reason_codes']);
    }

    public function test_fulfillment_lifecycle_is_authorized_idempotent_and_append_only(): void
    {
        [$request, $unitId] = $this->staffingRequest();
        $member = $this->staffMember('Eligible Nurse', $unitId);
        $this->requireRoleQualification();
        $this->qualify($member);
        $this->availability($member, $request, 'available');
        $path = "/api/staffing/requests/{$request->staffing_request_id}/fulfillments";
        $payload = ['staff_member_id' => $member->staff_member_id, 'source' => 'float_pool'];

        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->withHeader('Idempotency-Key', 'staffing-offer-forbidden')
            ->postJson($path, $payload)
            ->assertForbidden();
        $this->flushHeaders();
        $this->assertTrue(Gate::forUser(User::factory()->create(['role' => 'admin']))
            ->allows('manageStaffingOperations'));

        $coordinator = User::factory()->create(['role' => 'staffing_coordinator']);
        $this->actingAs($coordinator)->postJson($path, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $headers = ['Idempotency-Key' => 'staffing-offer-eligible-1'];
        $offered = $this->actingAs($coordinator)->withHeaders($headers)->postJson($path, $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'offered')
            ->json('data');
        $this->actingAs($coordinator)->withHeaders($headers)->postJson($path, $payload)
            ->assertCreated()
            ->assertJsonPath('data.fulfillment_uuid', $offered['fulfillment_uuid']);
        $this->assertNull($request->fresh()->assigned_staff_ref);
        $this->assertNull($request->fresh()->assigned_at);
        $this->assertSame(1, DB::table('prod.staffing_request_fulfillments')->count());
        $this->assertSame(1, DB::table('prod.staffing_fulfillment_commands')->count());
        $this->assertSame(1, DB::table('prod.staffing_fulfillment_events')->count());

        $this->actingAs($coordinator)->withHeaders($headers)->postJson($path, [
            ...$payload,
            'source' => 'agency',
        ])->assertConflict();

        $transitionPath = "/api/staffing/fulfillments/{$offered['fulfillment_uuid']}/transition";
        $this->actingAs($coordinator)
            ->withHeader('Idempotency-Key', 'staffing-illegal-fill-1')
            ->postJson($transitionPath, ['status' => 'filled'])
            ->assertUnprocessable();

        $accepted = $this->actingAs($coordinator)
            ->withHeader('Idempotency-Key', 'staffing-accept-1')
            ->postJson($transitionPath, ['status' => 'accepted'])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->json('data');
        $this->actingAs($coordinator)
            ->withHeader('Idempotency-Key', 'staffing-accept-1')
            ->postJson($transitionPath, ['status' => 'accepted'])
            ->assertOk()
            ->assertJsonPath('data.version', $accepted['version']);

        $this->actingAs($coordinator)
            ->withHeader('Idempotency-Key', 'staffing-fill-1')
            ->postJson($transitionPath, ['status' => 'filled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'filled');
        $this->assertSame('filled', $request->fresh()->status);
        $this->assertSame("staff:{$member->staff_member_id}", $request->fresh()->assigned_staff_ref);
        $this->assertSame(3, DB::table('prod.staffing_fulfillment_events')->count());
        $this->assertSame(3, DB::table('prod.staffing_fulfillment_commands')->count());
        $this->assertSame(1, DB::table('prod.staff_shift_assignments')->where('status', 'filled')->count());

        $this->actingAs($coordinator)
            ->postJson("/api/staffing/requests/{$request->staffing_request_id}/cancel", ['reason' => 'unsafe bypass'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->actingAs($coordinator)
            ->withHeader('Idempotency-Key', 'staffing-release-1')
            ->postJson($transitionPath, ['status' => 'released'])
            ->assertOk()
            ->assertJsonPath('data.status', 'released');
        $this->assertSame('open', $request->fresh()->status);
        $this->assertNull($request->fresh()->filled_at);
        $this->assertNull($request->fresh()->assigned_at);
        $this->assertNull($request->fresh()->assigned_staff_ref);
        $this->assertNull($request->fresh()->assigned_source);

        $eventId = DB::table('prod.staffing_fulfillment_events')->value('staffing_fulfillment_event_id');
        try {
            DB::table('prod.staffing_fulfillment_events')->where('staffing_fulfillment_event_id', $eventId)->update(['event_type' => 'tampered']);
            $this->fail('Expected the fulfillment activity ledger to reject mutation.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
    }

    public function test_acceptance_revalidates_leave_and_preserves_the_offer(): void
    {
        [$request, $unitId] = $this->staffingRequest();
        $member = $this->staffMember('Callout Nurse', $unitId);
        $this->requireRoleQualification();
        $this->qualify($member);
        $this->availability($member, $request, 'available');
        $coordinator = User::factory()->create(['role' => 'staffing_coordinator']);
        $offered = $this->actingAs($coordinator)
            ->withHeader('Idempotency-Key', 'staffing-callout-offer')
            ->postJson("/api/staffing/requests/{$request->staffing_request_id}/fulfillments", [
                'staff_member_id' => $member->staff_member_id,
                'source' => 'on_call',
            ])->assertCreated()->json('data');
        $this->availability($member, $request, 'leave', priority: 1);

        $this->actingAs($coordinator)
            ->withHeader('Idempotency-Key', 'staffing-callout-accept')
            ->postJson("/api/staffing/fulfillments/{$offered['fulfillment_uuid']}/transition", [
                'status' => 'accepted',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('staff_member_id');
        $this->assertDatabaseHas('prod.staffing_request_fulfillments', [
            'fulfillment_uuid' => $offered['fulfillment_uuid'],
            'status' => 'offered',
        ]);
        $this->assertSame(1, DB::table('prod.staffing_fulfillment_events')->count());
    }

    public function test_locked_headcount_capacity_prevents_over_acceptance_after_competing_offers(): void
    {
        [$request, $unitId] = $this->staffingRequest();
        $request->update(['headcount_needed' => 2]);
        $this->requireRoleQualification();
        $members = collect(['First Nurse', 'Second Nurse', 'Third Nurse'])->map(function (string $name) use ($request, $unitId): StaffMember {
            $member = $this->staffMember($name, $unitId);
            $this->qualify($member);
            $this->availability($member, $request, 'available');

            return $member;
        });
        $coordinator = User::factory()->create(['role' => 'staffing_coordinator']);
        $offers = $members->values()->map(function (StaffMember $member, int $index) use ($coordinator, $request): array {
            return $this->actingAs($coordinator)
                ->withHeader('Idempotency-Key', "capacity-offer-{$index}")
                ->postJson("/api/staffing/requests/{$request->staffing_request_id}/fulfillments", [
                    'staff_member_id' => $member->staff_member_id,
                    'source' => 'float_pool',
                ])->assertCreated()->json('data');
        });

        $fulfillments = $offers->take(2)->values()->map(function (array $offered, int $index) use ($coordinator): array {
            return $this->actingAs($coordinator)
                ->withHeader('Idempotency-Key', "capacity-accept-{$index}")
                ->postJson("/api/staffing/fulfillments/{$offered['fulfillment_uuid']}/transition", [
                    'status' => 'accepted',
                ])->assertOk()->json('data');
        });
        $third = $offers->last();
        $this->actingAs($coordinator)
            ->withHeader('Idempotency-Key', 'capacity-accept-third')
            ->postJson("/api/staffing/fulfillments/{$third['fulfillment_uuid']}/transition", [
                'status' => 'accepted',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('request');
        $this->assertDatabaseHas('prod.staffing_request_fulfillments', [
            'fulfillment_uuid' => $third['fulfillment_uuid'],
            'status' => 'offered',
        ]);

        foreach ($fulfillments as $index => $fulfillment) {
            $this->actingAs($coordinator)
                ->withHeader('Idempotency-Key', "capacity-fill-{$index}")
                ->postJson("/api/staffing/fulfillments/{$fulfillment['fulfillment_uuid']}/transition", [
                    'status' => 'filled',
                ])->assertOk();
        }
        $this->assertSame('filled', $request->fresh()->status);
        $this->assertSame(2, DB::table('prod.staffing_request_fulfillments')->where('status', 'filled')->count());
    }

    public function test_shift_windows_are_dst_safe_and_materialization_is_idempotent(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('staffing:materialize-canonical')
            ->assertSuccessful();
        $windows = app(StaffingShiftWindowService::class);
        $spring = $windows->forDateAndShift('2026-03-07', 'night', 'America/New_York');
        $fall = $windows->forDateAndShift('2026-10-31', 'night', 'America/New_York');
        $this->assertEquals(7 * 3600, $spring['starts_at']->diffInSeconds($spring['ends_at']));
        $this->assertEquals(9 * 3600, $fall['starts_at']->diffInSeconds($fall['ends_at']));

        [, $unitId] = $this->staffingRequest();
        $materialized = $this->staffMember('Materialized Nurse', $unitId, [
            'availability' => 'available',
            'preferred_shift' => 'day',
            'data_origin' => 'synthetic',
        ]);
        $outsideFacility = $this->staffMember('Other Facility Nurse', $unitId, [
            'availability' => 'available',
            'preferred_shift' => 'day',
            'data_origin' => 'synthetic',
        ], 'OTHER_HOSPITAL');
        $arguments = ['--from' => '2026-10-31', '--days' => 2];
        $this->artisan('staffing:materialize-canonical', [...$arguments, '--dry-run' => true])
            ->expectsOutputToContain('dry run')
            ->assertSuccessful();
        $this->assertSame(0, DB::table('hosp_org.staff_member_qualifications')->count());
        $this->assertSame(0, DB::table('prod.staff_availability_windows')->count());
        $this->artisan('staffing:materialize-canonical', $arguments)->assertSuccessful();
        $counts = [
            DB::table('hosp_org.staff_member_qualifications')->count(),
            DB::table('prod.staff_availability_windows')->count(),
        ];
        $this->artisan('staffing:materialize-canonical', $arguments)->assertSuccessful();
        $this->assertSame($counts, [
            DB::table('hosp_org.staff_member_qualifications')->count(),
            DB::table('prod.staff_availability_windows')->count(),
        ]);
        $this->assertGreaterThan(0, $counts[0]);
        $this->assertSame(2, $counts[1]);
        $this->assertSame(0, DB::table('prod.staff_availability_windows')
            ->where('staff_member_id', $outsideFacility->staff_member_id)
            ->count());

        $this->artisan('staffing:materialize-canonical', ['--from' => '2026-10-31', '--days' => 1])
            ->assertSuccessful();
        $this->assertSame(1, DB::table('prod.staff_availability_windows')
            ->where('staff_member_id', $materialized->staff_member_id)
            ->count());

        DB::table('hosp_org.staff_assignments')
            ->where('staff_member_id', $materialized->staff_member_id)
            ->update(['is_active' => false, 'updated_at' => now()]);
        $this->artisan('staffing:materialize-canonical', $arguments)->assertSuccessful();
        $this->assertDatabaseHas('hosp_org.staff_member_qualifications', [
            'staff_member_id' => $materialized->staff_member_id,
            'status' => 'expired',
        ]);
        $this->assertSame(0, DB::table('prod.staff_availability_windows')
            ->where('staff_member_id', $materialized->staff_member_id)
            ->count());
    }

    public function test_legacy_fill_is_blocked_and_mobile_uses_the_same_canonical_idempotent_fulfillment(): void
    {
        [$request, $unitId] = $this->staffingRequest();
        $member = $this->staffMember('Mobile Nurse', $unitId);
        $this->requireRoleQualification();
        $this->qualify($member);
        $this->availability($member, $request, 'available');
        $coordinator = User::factory()->create(['role' => 'staffing_coordinator']);

        $this->actingAs($coordinator)->postJson("/api/staffing/requests/{$request->staffing_request_id}/assign", [
            'assigned_source' => 'float_pool',
        ])->assertOk()->assertJsonPath('data.status', 'sourcing');
        $this->assertSame(0, DB::table('prod.staff_shift_assignments')->count());
        $this->actingAs($coordinator)->postJson("/api/staffing/requests/{$request->staffing_request_id}/status", [
            'status' => 'filled',
        ])->assertUnprocessable();

        Sanctum::actingAs($coordinator, ['mobile:read', 'mobile:act']);
        $path = "/api/mobile/v1/staffing/requests/{$request->staffing_request_id}/fill?persona=staffing_coordinator";
        $payload = ['staff_member_id' => $member->staff_member_id, 'assigned_source' => 'float_pool'];
        $this->getJson("/api/mobile/v1/staffing/requests/{$request->staffing_request_id}/candidates?persona=staffing_coordinator")
            ->assertOk()
            ->assertJsonPath('data.data.0.staff_member_id', $member->staff_member_id)
            ->assertJsonPath('data.data.0.eligibility_state', 'eligible')
            ->assertJsonPath('data.meta.total', 1);
        $this->withHeader('Idempotency-Key', str_repeat('x', 201))
            ->postJson($path, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
        $headers = ['Idempotency-Key' => 'staffing-mobile-fill-1'];
        $first = $this->withHeaders($headers)->postJson($path, $payload)
            ->assertOk()
            ->assertJsonPath('data.status', 'filled')
            ->assertJsonPath('data.canonical_fulfillment.staff_member_id', $member->staff_member_id)
            ->json('data.canonical_fulfillment');
        $second = $this->withHeaders($headers)->postJson($path, $payload)
            ->assertOk()
            ->json('data.canonical_fulfillment');
        $this->assertEquals($first, $second);

        $this->assertSame(1, DB::table('prod.staffing_request_fulfillments')->count());
        $this->assertSame(3, DB::table('prod.staffing_fulfillment_events')->count());
        $this->assertSame(1, DB::table('ops.operational_events')->where('event_type', 'staffing.request_filled')->count());
    }

    /** @return array{StaffingRequest,int} */
    private function staffingRequest(): array
    {
        $unitId = (int) DB::table('prod.units')->insertGetId([
            'name' => '3 West',
            'abbreviation' => '3W',
            'type' => 'med_surg',
            'staffed_bed_count' => 24,
            'ratio_floor' => 4,
            'access_standard_minutes' => 120,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'unit_id');
        $request = StaffingRequest::create([
            'request_uuid' => (string) Str::uuid(),
            'unit_id' => $unitId,
            'unit_label' => '3 West',
            'role' => 'rn',
            'shift_date' => '2026-10-31',
            'shift' => 'day',
            'request_type' => 'fill_gap',
            'priority' => 'urgent',
            'status' => 'requested',
            'headcount_needed' => 1,
            'metadata' => ['timezone' => 'America/New_York', 'service_line_code' => 'hospital_medicine'],
            'is_deleted' => false,
        ]);

        return [$request, $unitId];
    }

    private function staffMember(
        string $name,
        int $unitId,
        array $metadata = [],
        string $facilityKey = 'SUMMIT_REGIONAL',
    ): StaffMember {
        $member = StaffMember::create([
            'staff_key' => 'TEST:'.Str::slug($name).':'.Str::uuid(),
            'source_system' => 'TEST',
            'external_id' => (string) Str::uuid(),
            'display_name' => $name,
            'employment_status' => 'active',
            'is_active' => true,
            'metadata' => $metadata,
        ]);
        StaffAssignment::create([
            'staff_member_id' => $member->staff_member_id,
            'facility_key' => $facilityKey,
            'service_line_code' => 'hospital_medicine',
            'role_code' => 'staff_nurse',
            'unit_id' => $unitId,
            'primary_flag' => false,
            'coverage_model' => 'in_house',
            'fte' => 1,
            'review_status' => 'source_verified',
            'effective_start' => '2026-01-01',
            'is_active' => true,
        ]);

        return $member;
    }

    private function requireRoleQualification(): void
    {
        DB::table('hosp_ref.staff_qualifications')->updateOrInsert(
            ['qualification_code' => 'role.staff_nurse'],
            [
                'display_name' => 'Staff Nurse role qualification',
                'qualification_type' => 'role',
                'is_regulated' => false,
                'metadata' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        DB::table('hosp_ref.staff_role_qualification_requirements')->updateOrInsert(
            [
                'facility_key' => null,
                'unit_id' => null,
                'service_line_code' => null,
                'role_code' => 'staff_nurse',
                'qualification_code' => 'role.staff_nurse',
                'effective_start' => null,
            ],
            [
                'is_required' => true,
                'metadata' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function qualify(StaffMember $member): void
    {
        DB::table('hosp_org.staff_member_qualifications')->insert([
            'qualification_uuid' => (string) Str::uuid(),
            'staff_member_id' => $member->staff_member_id,
            'qualification_code' => 'role.staff_nurse',
            'status' => 'verified',
            'source' => 'test',
            'verified_at' => now(),
            'effective_start' => Carbon::parse('2026-01-01', 'America/New_York')->utc(),
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function availability(StaffMember $member, StaffingRequest $request, string $type, int $priority = 100): void
    {
        $window = app(StaffingShiftWindowService::class)->forRequest($request);
        DB::table('prod.staff_availability_windows')->insert([
            'availability_uuid' => (string) Str::uuid(),
            'staff_member_id' => $member->staff_member_id,
            'window_type' => $type,
            'starts_at' => $window['starts_at'],
            'ends_at' => $window['ends_at'],
            'timezone' => $window['timezone'],
            'source' => 'test',
            'priority' => $priority,
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
