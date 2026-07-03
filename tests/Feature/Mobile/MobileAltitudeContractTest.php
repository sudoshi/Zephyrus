<?php

namespace Tests\Feature\Mobile;

use App\Models\Barrier;
use App\Models\Bed;
use App\Models\BedRequest;
use App\Models\Ops\Approval;
use App\Models\Ops\OperationalAction;
use App\Models\Ops\Recommendation;
use App\Models\Staffing\StaffingRequest;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileAltitudeContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_you_drill_and_patient_context_use_altitude_contracts(): void
    {
        Sanctum::actingAs($this->user(), ['mobile:read']);

        $bedRequest = BedRequest::create([
            'patient_ref' => 'SECRET-MRN-ALTITUDE-1',
            'source' => 'ed',
            'service' => 'Medicine',
            'acuity_tier' => 1,
            'status' => 'pending',
        ]);

        $forYouResponse = $this->getJson('/api/mobile/v1/for-you?persona=bed_manager')
            ->assertOk();
        $forYou = $forYouResponse->getContent();
        $bedRequestItem = collect($forYouResponse->json('data'))->firstWhere('id', "bedreq-{$bedRequest->bed_request_id}");

        $this->assertNotNull($bedRequestItem);
        $this->assertSame('A2', $bedRequestItem['altitude']);
        $this->assertStringStartsWith('ptok_', $bedRequestItem['patient_context_ref']);

        $this->assertStringNotContainsString('SECRET-MRN-ALTITUDE-1', $forYou);

        $drill = $this->getJson("/api/mobile/v1/drills/bedreq-{$bedRequest->bed_request_id}?persona=bed_manager")
            ->assertOk()
            ->assertJsonPath('data.altitude', 'A2')
            ->assertJsonPath('data.domain', 'rtdc')
            ->json('data');

        $this->assertStringStartsWith('ptok_', $drill['patient_context_ref']);

        $this->getJson("/api/mobile/v1/patients/{$drill['patient_context_ref']}/operational-context?persona=bed_manager")
            ->assertOk()
            ->assertJsonPath('data.altitude', 'A2P')
            ->assertJsonPath('data.patient.patient_context_ref', $drill['patient_context_ref'])
            ->assertJsonPath('data.patient.phi_minimized', true)
            ->assertJsonPath('data.dependencies.0.dependency_type', 'bed_request');

        $this->getJson('/api/mobile/v1/patients/SECRET-MRN-ALTITUDE-1/operational-context?persona=bed_manager')
            ->assertForbidden();
    }

    public function test_mobile_write_records_activity_and_acknowledgement(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user, ['mobile:read', 'mobile:act']);

        $barrier = Barrier::create([
            'category' => 'placement',
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->postJson("/api/mobile/v1/rtdc/barriers/{$barrier->barrier_id}/resolve?persona=bed_manager")
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        $activity = $this->getJson('/api/mobile/v1/activity?persona=bed_manager')
            ->assertOk()
            ->assertJsonPath('data.0.event_type', 'barrier.resolved')
            ->json('data.0');

        $this->postJson("/api/mobile/v1/activity/{$activity['event_uuid']}/ack?persona=bed_manager")
            ->assertOk()
            ->assertJsonPath('data.acknowledged', true);
    }

    public function test_role_package_drills_resolve_altitude_contracts(): void
    {
        $user = $this->user();
        $user->role = 'admin';
        $user->save();
        Sanctum::actingAs($user, ['mobile:read']);

        $unit = Unit::create([
            'name' => '5 East',
            'type' => 'med_surg',
            'staffed_bed_count' => 1,
            'ratio_floor' => 5,
            'is_deleted' => false,
        ]);
        Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-01', 'status' => 'occupied', 'is_deleted' => false]);

        $approval = $this->approval($user);
        $staffing = StaffingRequest::create([
            'request_uuid' => (string) Str::uuid(),
            'unit_id' => $unit->unit_id,
            'unit_label' => $unit->name,
            'role' => 'rn',
            'shift_date' => now()->toDateString(),
            'shift' => 'day',
            'request_type' => 'fill_gap',
            'priority' => 'stat',
            'status' => 'open',
            'headcount_needed' => 1,
            'needed_by' => now()->addHour(),
            'is_deleted' => false,
        ]);
        $opportunityId = DB::table('prod.improvement_opportunities')->insertGetId([
            'title' => 'Transport SLA compliance',
            'department' => 'Transport',
            'priority' => 'High',
            'status' => 'Open',
            'estimated_impact' => 70,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'opportunity_id');

        $this->getJson("/api/mobile/v1/drills/ops-approval-{$approval->approval_uuid}?persona=capacity_lead")
            ->assertOk()
            ->assertJsonPath('data.altitude', 'A2')
            ->assertJsonPath('data.domain', 'ops')
            ->assertJsonPath('data.patient_context_ref', null)
            ->assertJsonPath('data.actions.0.kind', 'approve')
            ->assertJsonPath('data.actions.1.kind', 'reject');

        $this->getJson("/api/mobile/v1/drills/staffing-{$staffing->staffing_request_id}?persona=staffing_coordinator")
            ->assertOk()
            ->assertJsonPath('data.altitude', 'A2')
            ->assertJsonPath('data.domain', 'staffing')
            ->assertJsonPath('data.dependencies.0.type', 'staffing_request')
            ->assertJsonPath('data.actions.0.kind', 'fill_staffing');

        $this->getJson("/api/mobile/v1/drills/cap-{$unit->unit_id}?persona=charge_nurse")
            ->assertOk()
            ->assertJsonPath('data.altitude', 'A2')
            ->assertJsonPath('data.domain', 'rtdc')
            ->assertJsonPath('data.dependencies.0.type', 'unit_capacity')
            ->assertJsonPath('data.status.value', 'critical');

        $this->getJson("/api/mobile/v1/drills/improvement-{$opportunityId}?persona=pi_lead")
            ->assertOk()
            ->assertJsonPath('data.altitude', 'A2')
            ->assertJsonPath('data.domain', 'improvement')
            ->assertJsonPath('data.dependencies.0.type', 'improvement_opportunity')
            ->assertJsonPath('data.actions.0.kind', 'continue_on_web');
    }

    private function user(): User
    {
        $user = new User;
        $user->name = 'Mobile Altitude';
        $user->email = 'mobile-altitude@example.com';
        $user->username = 'mobile-altitude';
        $user->password = bcrypt('secret-test-password');
        $user->must_change_password = false;
        $user->is_active = true;
        $user->role = 'bed_manager';
        $user->workflow_preference = 'rtdc';
        $user->save();

        return $user;
    }

    private function approval(User $user): Approval
    {
        $recommendation = Recommendation::create([
            'recommendation_uuid' => (string) Str::uuid(),
            'recommendation_type' => 'capacity',
            'scope_type' => 'rtdc',
            'title' => 'Add surge beds',
            'rationale' => 'Capacity pressure requires human approval.',
            'risk_level' => 'high',
            'status' => 'draft',
            'created_by_source' => 'test',
            'expected_impact' => [],
            'evidence' => [],
        ]);

        $action = OperationalAction::create([
            'action_uuid' => (string) Str::uuid(),
            'recommendation_id' => $recommendation->recommendation_id,
            'action_type' => 'capacity_decision',
            'status' => 'draft',
            'payload' => [],
        ]);

        return Approval::create([
            'approval_uuid' => (string) Str::uuid(),
            'action_id' => $action->action_id,
            'status' => 'pending',
            'requested_by_user_id' => $user->id,
            'reason' => 'Contract test fixture.',
        ]);
    }
}
