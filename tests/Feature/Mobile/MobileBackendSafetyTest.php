<?php

namespace Tests\Feature\Mobile;

use App\Models\Barrier;
use App\Models\BedRequest;
use App\Models\Evs\EvsRequest;
use App\Models\Ops\Approval;
use App\Models\Ops\OperationalAction;
use App\Models\Ops\OperationalEvent;
use App\Models\Ops\Recommendation;
use App\Models\Staffing\StaffingRequest;
use App\Models\Transport\TransportRequest;
use App\Models\User;
use App\Services\Mobile\MobilePatientContextService;
use App\Services\Mobile\OperationalActivityLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class MobileBackendSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_rtdc_bed_decision_emits_exactly_one_operational_event(): void
    {
        $user = $this->actingAsMobile(['mobile:read', 'mobile:act']);
        $bedRequest = BedRequest::create([
            'patient_ref' => 'SECRET-MRN-BED-1',
            'source' => 'ed',
            'service' => 'Medicine',
            'acuity_tier' => 2,
            'status' => 'pending',
        ]);

        $this->postJson("/api/mobile/v1/rtdc/bed-requests/{$bedRequest->bed_request_id}/decision?persona=bed_manager", [
            'action' => 'rejected',
            'reason' => 'No safe destination yet.',
        ])->assertOk();

        $this->assertOneOperationalEvent('recommendation.rejected', $user, 'bed_manager', 'bed_request');
    }

    public function test_rtdc_barrier_resolution_emits_exactly_one_operational_event(): void
    {
        $user = $this->actingAsMobile(['mobile:read', 'mobile:act']);
        $barrier = Barrier::create([
            'category' => 'placement',
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->postJson("/api/mobile/v1/rtdc/barriers/{$barrier->barrier_id}/resolve?persona=charge_nurse")
            ->assertOk();

        $this->assertOneOperationalEvent('barrier.resolved', $user, 'charge_nurse', 'barrier');
    }

    public function test_transport_status_and_handoff_writes_emit_exactly_one_operational_event_each(): void
    {
        $user = $this->actingAsMobile(['mobile:read', 'mobile:act']);
        $transport = $this->transportRequest(['status' => 'requested']);

        $this->postJson("/api/mobile/v1/transport/requests/{$transport->transport_request_id}/status?persona=transport", [
            'status' => 'assigned',
            'assigned_team' => 'Mobile transport',
        ])->assertOk();

        $this->assertOneOperationalEvent('transport.claimed', $user, 'transport', 'transport_request');

        OperationalEvent::query()->delete();
        $transport = $transport->fresh();

        $this->postJson("/api/mobile/v1/transport/requests/{$transport->transport_request_id}/handoff?persona=transport", [
            'handoff_to' => '3 West charge',
            'handoff_summary' => 'Arrived to destination.',
        ])->assertOk();

        $this->assertOneOperationalEvent('transport.handoff_completed', $user, 'transport', 'transport_request');
    }

    public function test_evs_status_write_emits_exactly_one_operational_event(): void
    {
        $user = $this->actingAsMobile(['mobile:read', 'mobile:act']);
        $evs = $this->evsRequest(['status' => 'requested']);

        $this->postJson("/api/mobile/v1/evs/requests/{$evs->evs_request_id}/status?persona=evs", [
            'status' => 'assigned',
            'assigned_team' => 'EVS mobile',
        ])->assertOk();

        $this->assertOneOperationalEvent('evs.claimed', $user, 'evs', 'evs_request');
    }

    public function test_staffing_fill_write_emits_exactly_one_operational_event(): void
    {
        $user = $this->actingAsMobile(['mobile:read', 'mobile:act']);
        $staffing = StaffingRequest::create([
            'request_uuid' => (string) Str::uuid(),
            'unit_label' => '3 West',
            'role' => 'rn',
            'shift_date' => now()->toDateString(),
            'shift' => 'day',
            'request_type' => 'fill_gap',
            'priority' => 'urgent',
            'status' => 'requested',
            'headcount_needed' => 1,
            'is_deleted' => false,
        ]);

        $this->postJson("/api/mobile/v1/staffing/requests/{$staffing->staffing_request_id}/fill?persona=staffing_coordinator", [
            'assigned_source' => 'float_pool',
            'owner_name' => 'Float pool lead',
        ])->assertOk();

        $this->assertOneOperationalEvent('staffing.request_filled', $user, 'staffing_coordinator', 'staffing_request');
    }

    public function test_ops_and_eddy_approval_decisions_emit_exactly_one_operational_event_each(): void
    {
        $user = $this->actingAsMobile(['mobile:read', 'mobile:act']);
        $opsApproval = $this->approvalFor($user, source: 'rules');

        $this->postJson("/api/mobile/v1/ops/approvals/{$opsApproval->approval_uuid}/decision?persona=capacity_lead", [
            'decision' => 'approved',
            'reason' => 'Matches current bed plan.',
        ])->assertOk();

        $this->assertOneOperationalEvent('recommendation.approved', $user, 'capacity_lead', 'action');

        OperationalEvent::query()->delete();
        $eddyApproval = $this->approvalFor($user, source: 'eddy');

        $this->postJson("/api/mobile/v1/eddy/approvals/{$eddyApproval->approval_uuid}/decision?persona=capacity_lead", [
            'decision' => 'rejected',
            'reason' => 'Unsafe until staffing is filled.',
        ])->assertOk();

        $this->assertOneOperationalEvent('recommendation.rejected', $user, 'capacity_lead', 'action');
    }

    public function test_activity_ack_requires_mobile_act(): void
    {
        $user = $this->actingAsMobile(['mobile:read']);
        $event = app(OperationalActivityLedger::class)->record('barrier.resolved', [
            'actor_user_id' => $user->id,
            'actor_role' => 'charge_nurse',
            'domain' => 'rtdc',
            'scope' => ['barrier_id' => 99],
            'status' => ['previous' => 'open', 'current' => 'resolved', 'severity' => 'info'],
            'entities' => [['entity_type' => 'barrier', 'entity_ref' => '99']],
        ]);

        $this->postJson("/api/mobile/v1/activity/{$event['event_uuid']}/ack?persona=charge_nurse")
            ->assertForbidden();

        $this->assertSame(0, DB::table('ops.operational_event_acknowledgements')->count());
    }

    public function test_rtdc_barrier_resolution_rolls_back_when_ledger_write_fails(): void
    {
        $this->actingAsMobile(['mobile:read', 'mobile:act']);
        $barrier = Barrier::create([
            'category' => 'placement',
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->mock(OperationalActivityLedger::class, function (MockInterface $mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('ledger unavailable'));
        });

        $this->postJson("/api/mobile/v1/rtdc/barriers/{$barrier->barrier_id}/resolve?persona=charge_nurse")
            ->assertStatus(500);

        $this->assertSame('open', $barrier->fresh()->status);
    }

    public function test_transport_status_rolls_back_when_ledger_write_fails(): void
    {
        $this->actingAsMobile(['mobile:read', 'mobile:act']);
        $transport = $this->transportRequest(['status' => 'requested']);

        $this->mock(OperationalActivityLedger::class, function (MockInterface $mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('ledger unavailable'));
        });

        $this->postJson("/api/mobile/v1/transport/requests/{$transport->transport_request_id}/status?persona=transport", [
            'status' => 'assigned',
            'assigned_team' => 'Mobile transport',
        ])->assertStatus(500);

        $this->assertSame('requested', $transport->fresh()->status);
    }

    public function test_activity_and_for_you_payloads_do_not_leak_raw_patient_refs(): void
    {
        $this->actingAsMobile(['mobile:read']);
        $rawPatientRef = 'SECRET-MRN-ACTIVITY-1';
        $rawEncounterRef = 'SECRET-ENC-ACTIVITY-1';

        BedRequest::create([
            'patient_ref' => $rawPatientRef,
            'source' => 'ed',
            'service' => 'Medicine',
            'acuity_tier' => 1,
            'status' => 'pending',
        ]);

        app(OperationalActivityLedger::class)->record('transport.progressed', [
            'actor_role' => 'transport',
            'domain' => 'transport',
            'scope' => [
                'patient_ref' => $rawPatientRef,
                'encounter_ref' => $rawEncounterRef,
                'transport_request_id' => 'tx-activity-1',
            ],
            'status' => ['previous' => 'assigned', 'current' => 'en_route', 'severity' => 'warning'],
            'entities' => [[
                'entity_type' => 'transport_request',
                'entity_ref' => 'tx-activity-1',
                'patient_ref' => $rawPatientRef,
                'encounter_ref' => $rawEncounterRef,
            ]],
        ]);

        $activityBody = $this->getJson('/api/mobile/v1/activity?persona=bed_manager')
            ->assertOk()
            ->assertJsonPath('data.0.patient_context_ref', fn (?string $ref): bool => str_starts_with((string) $ref, 'ptok_'))
            ->getContent();

        $forYouBody = $this->getJson('/api/mobile/v1/for-you?persona=bed_manager')
            ->assertOk()
            ->assertJsonPath('data.0.patient_context_ref', fn (?string $ref): bool => str_starts_with((string) $ref, 'ptok_'))
            ->getContent();

        $this->assertStringNotContainsString($rawPatientRef, $activityBody);
        $this->assertStringNotContainsString($rawEncounterRef, $activityBody);
        $this->assertStringNotContainsString($rawPatientRef, $forYouBody);
        $this->assertStringNotContainsString($rawEncounterRef, $forYouBody);
    }

    public function test_eddy_context_packets_are_phi_minimized_and_do_not_grant_ops_approve(): void
    {
        $this->actingAsMobile(['mobile:read', 'ops:approve']);
        $rawPatientRef = 'SECRET-MRN-EDDY-CONTEXT';
        $rawEncounterRef = 'SECRET-ENC-EDDY-CONTEXT';

        BedRequest::create([
            'patient_ref' => $rawPatientRef,
            'source' => 'ed',
            'service' => 'Medicine',
            'acuity_tier' => 2,
            'status' => 'pending',
        ]);

        $event = app(OperationalActivityLedger::class)->record('transport.progressed', [
            'actor_role' => 'transport',
            'domain' => 'transport',
            'scope' => [
                'patient_ref' => $rawPatientRef,
                'encounter_ref' => $rawEncounterRef,
                'transport_request_id' => 'tx-eddy-1',
            ],
            'status' => ['previous' => 'assigned', 'current' => 'en_route', 'severity' => 'warning'],
            'entities' => [[
                'entity_type' => 'transport_request',
                'entity_ref' => 'tx-eddy-1',
                'patient_ref' => $rawPatientRef,
                'encounter_ref' => $rawEncounterRef,
            ]],
        ]);

        $patientContextRef = app(MobilePatientContextService::class)->contextRefFor($rawPatientRef);

        foreach ([$event['event_uuid'], $patientContextRef] as $scopeRef) {
            $body = $this->getJson("/api/mobile/v1/eddy/context/{$scopeRef}?persona=bed_manager")
                ->assertOk()
                ->assertJsonPath('data.phi_policy.minimized_by_default', true)
                ->assertJsonPath('data.phi_policy.ops_approve_not_available', true)
                ->getContent();

            $this->assertStringNotContainsString($rawPatientRef, $body);
            $this->assertStringNotContainsString($rawEncounterRef, $body);
            $this->assertStringNotContainsString('ops:approve', $body);
        }
    }

    public function test_scoped_user_cannot_spoof_an_unassigned_persona(): void
    {
        $this->actingAsScopedMobile(['mobile:read'], workflow: 'transport');

        $this->getJson('/api/mobile/v1/activity?persona=bed_manager')
            ->assertForbidden();
    }

    public function test_workflow_preference_does_not_authorize_privileged_personas(): void
    {
        $this->actingAsScopedMobile(['mobile:read'], workflow: 'rtdc');

        foreach (['bed_manager', 'capacity_lead', 'house_supervisor'] as $persona) {
            $this->getJson("/api/mobile/v1/activity?persona={$persona}")
                ->assertForbidden();
        }
    }

    public function test_patient_context_requires_context_token_and_authorized_persona_scope(): void
    {
        $bedRequest = BedRequest::create([
            'patient_ref' => 'SECRET-MRN-POLICY',
            'source' => 'ed',
            'service' => 'Medicine',
            'acuity_tier' => 2,
            'status' => 'pending',
        ]);
        $patientContextRef = app(MobilePatientContextService::class)->contextRefFor($bedRequest->patient_ref);

        $this->actingAsPersonaMobile(['mobile:read'], 'bed_manager');

        $this->getJson('/api/mobile/v1/patients/SECRET-MRN-POLICY/operational-context?persona=bed_manager')
            ->assertForbidden();

        $this->getJson("/api/mobile/v1/patients/{$patientContextRef}/operational-context?persona=bed_manager")
            ->assertOk()
            ->assertJsonPath('data.patient.patient_context_ref', $patientContextRef);

        $this->actingAsScopedMobile(['mobile:read'], workflow: 'transport');

        $this->getJson("/api/mobile/v1/patients/{$patientContextRef}/operational-context?persona=transport")
            ->assertForbidden();
    }

    public function test_new_mobile_bff_endpoints_return_uniform_envelopes(): void
    {
        $this->actingAsMobile(['mobile:read']);
        $bedRequest = BedRequest::create([
            'patient_ref' => 'SECRET-MRN-ENVELOPE',
            'source' => 'ed',
            'service' => 'Medicine',
            'acuity_tier' => 1,
            'status' => 'pending',
        ]);
        $patientContextRef = app(MobilePatientContextService::class)->contextRefFor($bedRequest->patient_ref);
        $event = app(OperationalActivityLedger::class)->record('barrier.resolved', [
            'actor_role' => 'charge_nurse',
            'domain' => 'rtdc',
            'scope' => ['barrier_id' => 123],
            'status' => ['previous' => 'open', 'current' => 'resolved', 'severity' => 'info'],
            'entities' => [['entity_type' => 'barrier', 'entity_ref' => '123']],
        ]);

        foreach ([
            '/api/mobile/v1/altitude/home?persona=bed_manager',
            '/api/mobile/v1/altitude/workspace/rtdc?persona=bed_manager',
            "/api/mobile/v1/drills/bedreq-{$bedRequest->bed_request_id}?persona=bed_manager",
            "/api/mobile/v1/patients/{$patientContextRef}/operational-context?persona=bed_manager",
            '/api/mobile/v1/activity?persona=bed_manager',
            "/api/mobile/v1/eddy/context/{$event['event_uuid']}?persona=bed_manager",
        ] as $path) {
            $this->assertMobileEnvelope($this->getJson($path)->assertOk(), $path);
        }
    }

    private function actingAsMobile(array $abilities): User
    {
        $user = User::factory()->create([
            'must_change_password' => false,
            'is_active' => true,
            'role' => 'admin',
            'workflow_preference' => 'rtdc',
        ]);

        Sanctum::actingAs($user, $abilities);

        return $user;
    }

    private function actingAsScopedMobile(array $abilities, string $workflow = 'rtdc'): User
    {
        $user = User::factory()->create([
            'must_change_password' => false,
            'is_active' => true,
            'role' => 'user',
            'workflow_preference' => $workflow,
        ]);

        Sanctum::actingAs($user, $abilities);

        return $user;
    }

    private function actingAsPersonaMobile(array $abilities, string $role): User
    {
        $user = User::factory()->create([
            'must_change_password' => false,
            'is_active' => true,
            'role' => $role,
            'workflow_preference' => 'rtdc',
        ]);

        Sanctum::actingAs($user, $abilities);

        return $user;
    }

    private function transportRequest(array $attributes = []): TransportRequest
    {
        return TransportRequest::create(array_merge([
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'inpatient',
            'priority' => 'urgent',
            'status' => 'requested',
            'patient_ref' => 'SECRET-MRN-TRANSPORT',
            'encounter_ref' => 'SECRET-ENC-TRANSPORT',
            'origin' => 'ED',
            'destination' => '3 West',
            'transport_mode' => 'wheelchair',
            'requested_at' => now(),
            'needed_at' => now()->addMinutes(15),
            'is_deleted' => false,
        ], $attributes));
    }

    private function evsRequest(array $attributes = []): EvsRequest
    {
        return EvsRequest::create(array_merge([
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'bed_clean',
            'priority' => 'urgent',
            'status' => 'requested',
            'patient_ref' => 'SECRET-MRN-EVS',
            'encounter_ref' => 'SECRET-ENC-EVS',
            'location_label' => '3W-12',
            'turn_type' => 'standard',
            'isolation_required' => false,
            'requested_at' => now(),
            'needed_at' => now()->addMinutes(20),
            'is_deleted' => false,
        ], $attributes));
    }

    private function approvalFor(User $user, string $source): Approval
    {
        $recommendation = Recommendation::create([
            'recommendation_uuid' => (string) Str::uuid(),
            'recommendation_type' => "{$source}_capacity_action",
            'scope_type' => 'rtdc',
            'title' => 'Capacity action',
            'rationale' => 'House pressure requires a governed decision.',
            'risk_level' => 'high',
            'status' => 'draft',
            'created_by_source' => $source,
            'expected_impact' => [],
            'evidence' => ['tier' => 'T1'],
        ]);

        $action = OperationalAction::create([
            'action_uuid' => (string) Str::uuid(),
            'recommendation_id' => $recommendation->recommendation_id,
            'action_type' => 'flag_barrier',
            'status' => 'draft',
            'payload' => ['unit' => '3W', 'barrier' => 'capacity'],
        ]);

        return Approval::create([
            'approval_uuid' => (string) Str::uuid(),
            'action_id' => $action->action_id,
            'status' => 'pending',
            'requested_by_user_id' => $user->id,
            'reason' => 'Mobile safety test.',
        ]);
    }

    private function assertOneOperationalEvent(string $eventType, User $user, string $role, string $entityType): OperationalEvent
    {
        $this->assertSame(1, OperationalEvent::query()->count());

        $event = OperationalEvent::with(['entities', 'targets'])->firstOrFail();

        $this->assertSame($eventType, $event->event_type);
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame($role, $event->actor_role);
        $this->assertArrayHasKey('previous', $event->status);
        $this->assertArrayHasKey('current', $event->status);
        $this->assertNotSame('', (string) $event->status['previous']);
        $this->assertNotSame('', (string) $event->status['current']);
        $this->assertNotEmpty($event->relay['affected_roles'] ?? []);
        $this->assertGreaterThan(0, $event->targets->count());
        $this->assertTrue(
            $event->entities->contains('entity_type', $entityType),
            "Expected operational event to include {$entityType} entity.",
        );

        return $event;
    }

    private function assertMobileEnvelope(TestResponse $response, string $path): void
    {
        $response->assertJsonStructure([
            'data',
            'meta' => ['as_of', 'stale', 'version'],
            'links',
        ]);

        $this->assertIsArray($response->json('links'), "{$path} must expose a links object.");
    }
}
