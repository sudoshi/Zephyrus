<?php

namespace Tests\Feature\Demo;

use App\Models\Staffing\StaffingRequest;
use App\Models\Transport\TransportRequest;
use App\Services\Demo\OperationalDemoDataService;
use Database\Seeders\RtdcSeeder;
use Database\Seeders\StaffingReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationalDemoDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_summit_operational_scenario_is_current_complete_and_idempotent(): void
    {
        Carbon::setTestNow('2026-07-09 12:00:00 America/New_York');
        $this->seed(RtdcSeeder::class);
        $this->seed(StaffingReferenceSeeder::class);
        $service = app(OperationalDemoDataService::class);

        $preview = $service->preview();
        $this->assertSame(25, $preview['unit_count']);
        $this->assertSame(500, $preview['inpatient_beds']);
        $this->assertSame([], $preview['collisions']);
        $this->assertGreaterThan(250, $preview['staffing_plan_count']);
        $this->assertSame(5, $preview['staffing_gap_count']);
        $this->assertGreaterThan(2000, $preview['workforce_member_count']);
        $this->assertGreaterThan(40, $preview['workforce_role_count']);
        $this->assertSame(180, $preview['historical_transport_count']);

        StaffingRequest::create([
            'request_uuid' => (string) Str::uuid(),
            'unit_label' => 'Legacy Demo Unit',
            'role' => 'rn',
            'shift_date' => now()->toDateString(),
            'shift' => 'day',
            'request_type' => 'fill_gap',
            'priority' => 'urgent',
            'status' => 'open',
            'headcount_needed' => 1,
            'requested_by' => OperationalDemoDataService::LEGACY_OWNER,
        ]);
        TransportRequest::create([
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'inpatient',
            'priority' => 'urgent',
            'status' => 'requested',
            'patient_ref' => 'LEGACY-DEMO-PATIENT',
            'encounter_ref' => 'LEGACY-DEMO-ENCOUNTER',
            'origin' => 'Legacy Demo Origin',
            'destination' => 'Legacy Demo Destination',
            'transport_mode' => 'wheelchair',
            'requested_by' => OperationalDemoDataService::LEGACY_OWNER,
            'requested_at' => now()->subDay(),
            'needed_at' => now()->subHours(23),
        ]);

        $first = $service->rollForward();
        $firstStaffKeys = DB::table('hosp_org.staff_members')
            ->where('source_system', OperationalDemoDataService::WORKFORCE_SOURCE)
            ->orderBy('staff_key')
            ->pluck('staff_key')
            ->all();
        $second = $service->rollForward();
        $secondStaffKeys = DB::table('hosp_org.staff_members')
            ->where('source_system', OperationalDemoDataService::WORKFORCE_SOURCE)
            ->orderBy('staff_key')
            ->pluck('staff_key')
            ->all();

        $this->assertSame($first, $second);
        $this->assertSame(0, StaffingRequest::query()->where('requested_by', OperationalDemoDataService::LEGACY_OWNER)->count());
        $this->assertSame(0, TransportRequest::query()->where('requested_by', OperationalDemoDataService::LEGACY_OWNER)->count());
        $this->assertSame($firstStaffKeys, $secondStaffKeys, 'stable scenario keys make the roster idempotent');
        $this->assertSame(5, $second['staffing_requests']);
        $this->assertSame($preview['workforce_member_count'], $second['workforce_members']);
        $this->assertSame(12, $second['workforce_inactive']);
        $this->assertSame($second['workforce_members'], $second['workforce_assignments']);
        $this->assertSame(25, $second['workforce_units']);
        $this->assertGreaterThan(40, $second['workforce_roles']);
        $this->assertSame(20, $second['transport_active']);
        $this->assertSame(180, $second['transport_history']);
        $this->assertSame($second['staffing_plans'], DB::table('prod.staffing_plans')->where('notes', OperationalDemoDataService::OWNER)->count());
        $this->assertSame(200, DB::table('prod.transport_requests')->where('requested_by', OperationalDemoDataService::OWNER)->count());
        $this->assertSame(0, DB::table('prod.transport_requests')->where('requested_by', OperationalDemoDataService::OWNER)->whereNull('encounter_ref')->count());
        $this->assertSame(0, DB::table('prod.transport_requests')->where('requested_by', OperationalDemoDataService::OWNER)->whereNull('metadata')->count());
        $this->assertSame(5, DB::table('prod.staffing_plans')->where('notes', OperationalDemoDataService::OWNER)->whereIn('status', ['gap', 'critical_gap'])->count());
        $this->assertSame(0, DB::table('hosp_org.staff_members')->where('source_system', OperationalDemoDataService::WORKFORCE_SOURCE)->whereNotNull('user_id')->count());
        $this->assertSame(25, DB::table('hosp_org.staff_assignments as sa')
            ->join('hosp_org.staff_members as sm', 'sm.staff_member_id', '=', 'sa.staff_member_id')
            ->where('sm.source_system', OperationalDemoDataService::WORKFORCE_SOURCE)
            ->where('sa.is_active', true)
            ->whereNotNull('sa.unit_id')
            ->distinct('sa.unit_id')
            ->count('sa.unit_id'));
        foreach (['day', 'evening', 'night'] as $shift) {
            $this->assertSame(25, DB::table('hosp_org.staff_assignments as sa')
                ->join('hosp_org.staff_members as sm', 'sm.staff_member_id', '=', 'sa.staff_member_id')
                ->where('sm.source_system', OperationalDemoDataService::WORKFORCE_SOURCE)
                ->whereNotNull('sa.unit_id')
                ->whereRaw("sm.metadata->>'preferred_shift' = ?", [$shift])
                ->distinct('sa.unit_id')
                ->count('sa.unit_id'), "every operational unit must have {$shift}-shift roster coverage");
        }
        foreach (['full_time', 'part_time', 'per_diem', 'float_pool', 'traveler', 'on_call', 'inactive'] as $employmentClass) {
            $this->assertGreaterThan(0, DB::table('hosp_org.staff_members')
                ->where('source_system', OperationalDemoDataService::WORKFORCE_SOURCE)
                ->whereRaw("metadata->>'employment_class' = ?", [$employmentClass])
                ->count(), "{$employmentClass} employment class must be represented");
        }
        foreach (['critical_care_nurse', 'emergency_nurse', 'perioperative_nurse', 'behavioral_health_technician', 'respiratory_therapist', 'transport_tech', 'environmental_services', 'pharmacist'] as $role) {
            $this->assertGreaterThan(0, DB::table('hosp_org.staff_assignments as sa')
                ->join('hosp_org.staff_members as sm', 'sm.staff_member_id', '=', 'sa.staff_member_id')
                ->where('sm.source_system', OperationalDemoDataService::WORKFORCE_SOURCE)
                ->where('sa.role_code', $role)
                ->count(), "{$role} must be represented");
        }

        $evidence = DB::table('hosp_org.staff_assignments as sa')
            ->join('hosp_org.staff_members as sm', 'sm.staff_member_id', '=', 'sa.staff_member_id')
            ->where('sm.source_system', OperationalDemoDataService::WORKFORCE_SOURCE)
            ->where('sa.role_code', 'critical_care_nurse')
            ->value('sa.evidence');
        $calculation = data_get(json_decode((string) $evidence, true), 'roster_calculation');
        $this->assertEqualsCanonicalizing(['day', 'evening', 'night'], array_keys($calculation['coverage_by_shift']));
        $this->assertSame(1664.0, (float) $calculation['productive_hours_per_fte']);
        $this->assertSame(1.18, (float) $calculation['relief_factor']);
        $this->assertEqualsWithDelta(
            $calculation['annual_coverage_hours'] / $calculation['productive_hours_per_fte'] * $calculation['relief_factor'],
            $calculation['roster_fte'],
            0.01,
        );

        Carbon::setTestNow();
    }

    public function test_roll_forward_command_requires_explicit_synthetic_guard_and_mode(): void
    {
        $this->seed(RtdcSeeder::class);

        config(['demo_data.enabled' => false]);
        $this->artisan('zephyrus:demo-roll-forward', ['--dry-run' => true])
            ->expectsOutputToContain('Demo data is disabled')
            ->assertExitCode(1);

        config([
            'demo_data.enabled' => true,
            'demo_data.facility_allowlist' => ['SUMMIT_REGIONAL'],
        ]);
        $this->artisan('zephyrus:demo-roll-forward')
            ->expectsOutputToContain('Choose --dry-run or --commit explicitly')
            ->assertExitCode(1);
        $this->artisan('zephyrus:demo-roll-forward', ['--dry-run' => true])
            ->expectsOutputToContain('Canonical staffing references are empty')
            ->assertExitCode(1);

        $this->seed(StaffingReferenceSeeder::class);
        $this->artisan('zephyrus:demo-roll-forward', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run complete')
            ->assertSuccessful();
        $this->assertSame(0, DB::table('prod.transport_requests')->where('requested_by', OperationalDemoDataService::OWNER)->count());
    }
}
