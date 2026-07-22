<?php

namespace Tests\Feature\Mobile;

use App\Models\Barrier;
use App\Models\BedRequest;
use App\Models\Encounter;
use App\Models\Evs\EvsRequest;
use App\Models\Ops\Approval;
use App\Models\Ops\OperationalAction;
use App\Models\Ops\OperationalEvent;
use App\Models\Ops\Recommendation;
use App\Models\Org\StaffAssignment;
use App\Models\Org\StaffMember;
use App\Models\Staffing\StaffingRequest;
use App\Models\Transport\TransportRequest;
use App\Models\Unit;
use App\Models\User;
use App\Services\Mobile\MobilePatientContextReferenceStore;
use App\Services\Mobile\MobilePatientContextService;
use App\Services\Mobile\OperationalActivityLedger;
use App\Services\Staffing\StaffingShiftWindowService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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

    public function test_mobile_idempotency_key_replay_does_not_duplicate_activity_ledger_rows(): void
    {
        $user = $this->actingAsMobile(['mobile:read', 'mobile:act']);
        $barrier = Barrier::create([
            'category' => 'placement',
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $path = "/api/mobile/v1/rtdc/barriers/{$barrier->barrier_id}/resolve?persona=charge_nurse";
        $headers = ['Idempotency-Key' => 'mobile-replay-barrier-resolution-1'];

        $this->withHeaders($headers)->postJson($path)->assertOk();
        $this->withHeaders($headers)->postJson($path)->assertOk();

        $this->assertSame('resolved', $barrier->fresh()->status);

        $event = $this->assertOneOperationalEvent('barrier.resolved', $user, 'charge_nurse', 'barrier');
        $this->assertSame($event->event_uuid, OperationalEvent::query()->first()?->event_uuid);
    }

    public function test_mobile_idempotency_key_replay_does_not_duplicate_domain_audit_rows(): void
    {
        $user = $this->actingAsMobile(['mobile:read', 'mobile:act']);

        $bedRequest = BedRequest::create([
            'patient_ref' => 'SECRET-MRN-BED-REPLAY',
            'source' => 'ed',
            'service' => 'Medicine',
            'acuity_tier' => 2,
            'status' => 'pending',
        ]);
        $bedPath = "/api/mobile/v1/rtdc/bed-requests/{$bedRequest->bed_request_id}/decision?persona=bed_manager";
        $bedHeaders = ['Idempotency-Key' => 'mobile-replay-bed-reject-1'];
        $bedBody = ['action' => 'rejected', 'reason' => 'No staffed destination.'];

        $this->withHeaders($bedHeaders)->postJson($bedPath, $bedBody)->assertOk();
        $this->withHeaders($bedHeaders)->postJson($bedPath, $bedBody)->assertOk();

        $this->assertSame(1, DB::table('prod.bed_placement_decisions')
            ->where('bed_request_id', $bedRequest->bed_request_id)
            ->count());
        $this->assertSame(1, OperationalEvent::query()->where('event_type', 'recommendation.rejected')->count());

        $transport = $this->transportRequest(['status' => 'requested']);
        $transportPath = "/api/mobile/v1/transport/requests/{$transport->transport_request_id}/status?persona=transport";
        $transportHeaders = ['Idempotency-Key' => 'mobile-replay-transport-assign-1'];
        $transportBody = ['status' => 'assigned'];

        $this->withHeaders($transportHeaders)->postJson($transportPath, $transportBody)->assertOk();
        $this->withHeaders($transportHeaders)->postJson($transportPath, $transportBody)->assertOk();

        $this->assertSame(1, DB::table('prod.transport_events')
            ->where('transport_request_id', $transport->transport_request_id)
            ->count());
        $this->assertSame(1, OperationalEvent::query()->where('event_type', 'transport.claimed')->count());

        $evs = $this->evsRequest(['status' => 'requested']);
        $evsPath = "/api/mobile/v1/evs/requests/{$evs->evs_request_id}/status?persona=evs";
        $evsHeaders = ['Idempotency-Key' => 'mobile-replay-evs-assign-1'];
        $evsBody = ['status' => 'assigned', 'assigned_team' => 'EVS mobile'];

        $this->withHeaders($evsHeaders)->postJson($evsPath, $evsBody)->assertOk();
        $this->withHeaders($evsHeaders)->postJson($evsPath, $evsBody)->assertOk();

        $this->assertSame(1, DB::table('prod.evs_events')
            ->where('evs_request_id', $evs->evs_request_id)
            ->count());
        $this->assertSame(1, OperationalEvent::query()->where('event_type', 'evs.claimed')->count());

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
        $staffingPath = "/api/mobile/v1/staffing/requests/{$staffing->staffing_request_id}/fill?persona=staffing_coordinator";
        $staffingHeaders = ['Idempotency-Key' => 'mobile-replay-staffing-fill-1'];
        $staffMemberId = $this->eligibleStaffingCandidate($staffing);
        $staffingBody = ['staff_member_id' => $staffMemberId, 'assigned_source' => 'float_pool', 'owner_name' => 'Float pool lead'];

        $this->withHeaders($staffingHeaders)->postJson($staffingPath, $staffingBody)->assertOk();
        $this->withHeaders($staffingHeaders)->postJson($staffingPath, $staffingBody)->assertOk();

        $this->assertSame(1, DB::table('prod.staffing_events')
            ->where('staffing_request_id', $staffing->staffing_request_id)
            ->count());
        $this->assertSame(3, DB::table('prod.staffing_fulfillment_events')
            ->where('staffing_request_id', $staffing->staffing_request_id)
            ->count());
        $this->assertSame(1, OperationalEvent::query()->where('event_type', 'staffing.request_filled')->count());

        $approval = $this->approvalFor($user, source: 'rules');
        $approvalPath = "/api/mobile/v1/ops/approvals/{$approval->approval_uuid}/decision?persona=capacity_lead";
        $approvalHeaders = ['Idempotency-Key' => 'mobile-replay-approval-approve-1'];
        $approvalBody = ['decision' => 'approved', 'reason' => 'Matches current plan.'];

        $this->withHeaders($approvalHeaders)->postJson($approvalPath, $approvalBody)->assertOk();
        $this->withHeaders($approvalHeaders)->postJson($approvalPath, $approvalBody)->assertOk();

        $this->assertSame('approved', $approval->fresh()->status);
        $this->assertSame(1, OperationalEvent::query()->where('event_type', 'recommendation.approved')->count());
    }

    public function test_mobile_idempotency_key_rejects_same_key_different_payload_before_mutation(): void
    {
        $this->actingAsMobile(['mobile:read', 'mobile:act']);
        $transport = $this->transportRequest(['status' => 'requested']);
        $path = "/api/mobile/v1/transport/requests/{$transport->transport_request_id}/status?persona=transport";
        $headers = ['Idempotency-Key' => 'mobile-replay-transport-conflict-1'];

        $this->withHeaders($headers)->postJson($path, [
            'status' => 'assigned',
        ])->assertOk();

        $this->withHeaders($headers)->postJson($path, [
            'status' => 'dispatched',
        ])->assertStatus(409);

        $this->assertSame(1, DB::table('prod.transport_events')
            ->where('transport_request_id', $transport->transport_request_id)
            ->count());
        $this->assertSame(1, OperationalEvent::query()->where('event_type', 'transport.claimed')->count());
    }

    public function test_mobile_ops_approvals_require_capacity_lead_persona(): void
    {
        $transportUser = $this->actingAsPersonaMobile(['mobile:read', 'mobile:act'], 'transport');
        $approval = $this->approvalFor($transportUser, source: 'rules');

        $this->getJson('/api/mobile/v1/ops/inbox?persona=transport')
            ->assertForbidden();

        $this->postJson("/api/mobile/v1/ops/approvals/{$approval->approval_uuid}/decision?persona=transport", [
            'decision' => 'approved',
        ])->assertForbidden();

        $this->assertSame('pending', $approval->fresh()->status);

        $this->actingAsPersonaMobile(['mobile:read', 'mobile:act'], 'capacity_lead');

        $this->getJson('/api/mobile/v1/ops/inbox?persona=capacity_lead')
            ->assertOk()
            ->assertJsonPath('data.0.approval_uuid', $approval->approval_uuid);

        $this->postJson("/api/mobile/v1/ops/approvals/{$approval->approval_uuid}/decision?persona=capacity_lead", [
            'decision' => 'approved',
        ])->assertOk();

        $this->assertSame('approved', $approval->fresh()->status);
    }

    public function test_mobile_write_routes_require_matching_persona(): void
    {
        $this->actingAsMobile(['mobile:read', 'mobile:act']);

        $bedRequest = BedRequest::create([
            'patient_ref' => 'SECRET-MRN-WRITE-GATE',
            'source' => 'ed',
            'service' => 'Medicine',
            'acuity_tier' => 2,
            'status' => 'pending',
        ]);
        $this->postJson("/api/mobile/v1/rtdc/bed-requests/{$bedRequest->bed_request_id}/decision?persona=transport", [
            'action' => 'rejected',
            'reason' => 'Wrong persona should not decide.',
        ])->assertForbidden();
        $this->assertSame('pending', $bedRequest->fresh()->status);
        $this->assertSame(0, DB::table('prod.bed_placement_decisions')->count());

        $barrier = Barrier::create([
            'category' => 'placement',
            'status' => 'open',
            'opened_at' => now(),
        ]);
        $this->postJson("/api/mobile/v1/rtdc/barriers/{$barrier->barrier_id}/resolve?persona=transport")
            ->assertForbidden();
        $this->assertSame('open', $barrier->fresh()->status);

        $transport = $this->transportRequest(['status' => 'requested']);
        $this->postJson("/api/mobile/v1/transport/requests/{$transport->transport_request_id}/status?persona=evs", [
            'status' => 'assigned',
            'assigned_team' => 'Wrong team',
        ])->assertForbidden();
        $this->assertSame('requested', $transport->fresh()->status);

        $evs = $this->evsRequest(['status' => 'requested']);
        $this->postJson("/api/mobile/v1/evs/requests/{$evs->evs_request_id}/status?persona=transport", [
            'status' => 'assigned',
            'assigned_team' => 'Wrong team',
        ])->assertForbidden();
        $this->assertSame('requested', $evs->fresh()->status);

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
        $this->postJson("/api/mobile/v1/staffing/requests/{$staffing->staffing_request_id}/fill?persona=transport", [
            'assigned_source' => 'float_pool',
        ])->assertForbidden();
        $this->assertSame('requested', $staffing->fresh()->status);
        $this->assertSame(0, OperationalEvent::query()->count());
    }

    public function test_transport_status_and_handoff_writes_emit_exactly_one_operational_event_each(): void
    {
        $user = $this->actingAsMobile(['mobile:read', 'mobile:act']);
        $transport = $this->transportRequest(['status' => 'requested']);

        $this->withHeader('Idempotency-Key', 'mobile-transport-event-claim')
            ->postJson("/api/mobile/v1/transport/requests/{$transport->transport_request_id}/status?persona=transport", [
                'status' => 'assigned',
            ])->assertOk();

        $this->assertOneOperationalEvent('transport.claimed', $user, 'transport', 'transport_request');

        foreach (['dispatched', 'arrived_pickup', 'picked_up', 'en_route', 'arrived_destination'] as $index => $status) {
            $this->withHeader('Idempotency-Key', "mobile-transport-event-progress-{$index}")
                ->postJson("/api/mobile/v1/transport/requests/{$transport->transport_request_id}/status?persona=transport", [
                    'status' => $status,
                ])->assertOk();
        }
        OperationalEvent::query()->delete();

        $this->withHeader('Idempotency-Key', 'mobile-transport-event-handoff')
            ->postJson("/api/mobile/v1/transport/requests/{$transport->transport_request_id}/handoff?persona=transport", [
                'handoff_to' => '3 West charge',
                'receiver_role' => 'charge_nurse',
                'acceptance_status' => 'accepted',
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
        $staffMemberId = $this->eligibleStaffingCandidate($staffing);

        $this->withHeader('Idempotency-Key', 'mobile-staffing-fill-event-1')
            ->postJson("/api/mobile/v1/staffing/requests/{$staffing->staffing_request_id}/fill?persona=staffing_coordinator", [
                'staff_member_id' => $staffMemberId,
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
            $mock->shouldReceive('replay')->once()->andReturn(null);
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

        $this->withHeader('Idempotency-Key', 'mobile-transport-ledger-rollback')
            ->postJson("/api/mobile/v1/transport/requests/{$transport->transport_request_id}/status?persona=transport", [
                'status' => 'assigned',
            ])->assertStatus(500);

        $this->assertSame('requested', $transport->fresh()->status);
    }

    public function test_mobile_list_payloads_do_not_leak_raw_patient_refs(): void
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
        $this->transportRequest([
            'patient_ref' => $rawPatientRef,
            'encounter_ref' => $rawEncounterRef,
            'priority' => 'stat',
        ]);
        $this->evsRequest([
            'patient_ref' => $rawPatientRef,
            'encounter_ref' => $rawEncounterRef,
            'needed_at' => now()->subMinutes(10),
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
        $listBodies = [
            'activity' => $activityBody,
            'for-you' => $forYouBody,
            'rtdc-bed-requests' => $this->getJson('/api/mobile/v1/rtdc/bed-requests')->assertOk()->getContent(),
            'transport-queue' => $this->getJson('/api/mobile/v1/transport/queue?persona=transport')->assertOk()->getContent(),
            'evs-queue' => $this->getJson('/api/mobile/v1/evs/queue')->assertOk()->getContent(),
            'altitude-home' => $this->getJson('/api/mobile/v1/altitude/home?persona=bed_manager')->assertOk()->getContent(),
            'altitude-workspace' => $this->getJson('/api/mobile/v1/altitude/workspace/rtdc?persona=bed_manager')->assertOk()->getContent(),
        ];

        foreach ($listBodies as $surface => $body) {
            $this->assertStringNotContainsString($rawPatientRef, $body, "{$surface} leaked a raw patient ref.");
            $this->assertStringNotContainsString($rawEncounterRef, $body, "{$surface} leaked a raw encounter ref.");
        }
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

    public function test_eddy_context_packet_includes_patient_flow_4d_context_for_flow_scopes(): void
    {
        $this->assertSame(0, Artisan::call('facility:import-catalog', [
            'path' => base_path('tests/Fixtures/facility/model_catalog_fixture.json'),
            '--facility-code' => 'ZEPHYRUS-500',
            '--facility-name' => 'Mobile Eddy Flow Context Facility',
            '--source-name' => 'mobile-eddy-flow-context-catalog',
            '--map-operational' => true,
        ]));
        config([
            'patient_flow.demo_barriers_enabled' => true,
            'patient_flow.demo_barriers_replace_replay' => true,
        ]);

        $this->actingAsPersonaMobile(['mobile:read'], 'bed_manager');

        $bedManager = $this->getJson('/api/mobile/v1/eddy/context/house?persona=bed_manager')
            ->assertOk()
            ->assertJsonPath('data.scope_type', 'patient_flow_4d')
            ->assertJsonPath('data.context.patient_flow_4d.surface', 'patient_flow_4d')
            ->assertJsonPath('data.context.patient_flow_4d.role.id', 'bed_manager')
            ->assertJsonPath('data.context.patient_flow_4d.redaction.patient_identifiers_included', true)
            ->assertJsonStructure([
                'data' => [
                    'context' => [
                        'patient_flow_4d' => [
                            'current_metrics',
                            'top_barriers' => [['barrier_code', 'label', 'owner_role', 'eddy_summary']],
                            'barrier_owner_map',
                            'recommended_focus_areas',
                            'source_lineage' => ['timer_sources', 'demo_scenario', 'generated_from'],
                            'action_allowlist',
                        ],
                    ],
                ],
            ])
            ->json('data.context.patient_flow_4d');

        $this->assertNotEmpty($bedManager['top_barriers']);
        $this->assertContains('draft_huddle_summary', $bedManager['action_allowlist']);

        $this->actingAsPersonaMobile(['mobile:read'], 'executive');

        $executiveBody = $this->getJson('/api/mobile/v1/eddy/context/house?persona=executive')
            ->assertOk()
            ->assertJsonPath('data.scope_type', 'patient_flow_4d')
            ->assertJsonPath('data.context.patient_flow_4d.role.id', 'executive')
            ->assertJsonPath('data.context.patient_flow_4d.redaction.patient_identifiers_included', false)
            ->assertJsonPath('data.context.patient_flow_4d.redaction.aggregate_only', true)
            ->getContent();

        $this->assertStringNotContainsString('"patient_id"', $executiveBody);
        $this->assertStringNotContainsString('"patient_display_id"', $executiveBody);
        $this->assertStringNotContainsString('"encounter_id"', $executiveBody);
        $this->assertStringNotContainsString('DEMO-FLOW-', $executiveBody);
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
            ->assertNotFound();

        $this->assertNull(app(MobilePatientContextService::class)->resolvePatientRef('SECRET-MRN-POLICY'));
        $this->assertFalse(app(MobilePatientContextService::class)->hasPatientContext('SECRET-MRN-POLICY'));

        $rawEddyResponse = $this->getJson('/api/mobile/v1/eddy/context/SECRET-MRN-POLICY?persona=bed_manager')
            ->assertForbidden();
        $this->assertStringNotContainsString('SECRET-MRN-POLICY', $rawEddyResponse->getContent());

        $this->getJson("/api/mobile/v1/patients/{$patientContextRef}/operational-context?persona=bed_manager")
            ->assertOk()
            ->assertJsonPath('data.patient.patient_context_ref', $patientContextRef);

        $this->actingAsScopedMobile(['mobile:read'], workflow: 'transport');

        $this->getJson("/api/mobile/v1/patients/{$patientContextRef}/operational-context?persona=transport")
            ->assertForbidden();
    }

    public function test_patient_context_handles_use_indexed_expiring_revocable_mappings(): void
    {
        $patientRef = 'SECRET-MRN-INDEXED-CONTEXT';
        BedRequest::create([
            'patient_ref' => $patientRef,
            'source' => 'ed',
            'service' => 'Medicine',
            'acuity_tier' => 2,
            'status' => 'pending',
        ]);

        $references = app(MobilePatientContextReferenceStore::class);
        $contextRef = $references->issue($patientRef);

        $this->assertSame($patientRef, $references->resolve($contextRef));
        $this->assertDatabaseHas('ops.patient_operational_context_cache', [
            'patient_context_ref' => $contextRef,
            'patient_ref' => $patientRef,
        ]);

        $this->assertTrue($references->revoke($contextRef));
        $this->assertNull($references->resolve($contextRef));

        $this->assertSame($contextRef, $references->issue($patientRef));
        $this->assertSame($patientRef, $references->resolve($contextRef));
    }

    public function test_patient_context_reference_honors_configured_ttl_and_expiry(): void
    {
        config(['hummingbird.patient_context.ttl_minutes' => 15]);
        $references = app(MobilePatientContextReferenceStore::class);
        $patientRef = 'SECRET-MRN-TTL-EXPIRY';
        BedRequest::create([
            'patient_ref' => $patientRef,
            'source' => 'ed',
            'service' => 'Medicine',
            'acuity_tier' => 2,
            'status' => 'pending',
        ]);

        $contextRef = $references->issue($patientRef);
        $this->assertSame($patientRef, $references->resolve($contextRef));

        // issue() stamps the configured TTL window (default 15m, clamped 1..1440)
        // on the mapping row.
        $expiresAt = DB::table('ops.patient_operational_context_cache')
            ->where('patient_context_ref', $contextRef)
            ->value('expires_at');
        $this->assertNotNull($expiresAt);
        $this->assertEqualsWithDelta(
            now()->addMinutes(15)->getTimestamp(),
            CarbonImmutable::parse($expiresAt)->getTimestamp(),
            60,
        );

        // Once the window elapses the handle no longer resolves, so a stale
        // handle cannot re-open the patient context even though the row is kept.
        DB::table('ops.patient_operational_context_cache')
            ->where('patient_context_ref', $contextRef)
            ->update(['expires_at' => now()->subSecond()]);
        $this->assertNull($references->resolve($contextRef));

        // Reissuing refreshes the same deterministic handle back to a live window.
        $reissued = $references->issue($patientRef);
        $this->assertSame($contextRef, $reissued);
        $this->assertSame($patientRef, $references->resolve($reissued));
    }

    public function test_active_inpatient_encounter_grants_only_assigned_unit_personas_patient_context(): void
    {
        $assignedUnit = Unit::create([
            'name' => 'Reference Inpatient Unit',
            'abbreviation' => 'RIU',
            'type' => 'med_surg',
            'staffed_bed_count' => 8,
            'ratio_floor' => 4,
            'is_deleted' => false,
        ]);
        $otherUnit = Unit::create([
            'name' => 'Unrelated Inpatient Unit',
            'abbreviation' => 'UIU',
            'type' => 'med_surg',
            'staffed_bed_count' => 8,
            'ratio_floor' => 4,
            'is_deleted' => false,
        ]);
        $encounter = Encounter::create([
            'patient_ref' => 'demo-hummingbird-inpatient-a2p',
            'unit_id' => $assignedUnit->unit_id,
            'admitted_at' => now()->subDay(),
            'expected_discharge_date' => now()->addDays(2)->toDateString(),
            'acuity_tier' => 2,
            'status' => 'active',
            'is_deleted' => false,
        ]);
        $contextRef = app(MobilePatientContextService::class)->contextRefFor($encounter->patient_ref);

        foreach (['charge_nurse', 'bedside_nurse', 'hospitalist', 'intensivist'] as $role) {
            $assigned = $this->actingAsPersonaMobile(['mobile:read'], $role);
            $assigned->units()->attach($assignedUnit->unit_id, ['role' => $role, 'is_primary' => true]);

            $this->getJson("/api/mobile/v1/patients/{$contextRef}/operational-context?persona={$role}")
                ->assertOk()
                ->assertJsonPath('data.patient.patient_context_ref', $contextRef)
                ->assertJsonPath('data.header.current_location', 'Reference Inpatient Unit');

            $unassigned = $this->actingAsPersonaMobile(['mobile:read'], $role);
            $unassigned->units()->attach($otherUnit->unit_id, ['role' => $role, 'is_primary' => true]);

            $this->getJson("/api/mobile/v1/patients/{$contextRef}/operational-context?persona={$role}")
                ->assertForbidden();
        }

        $assigned = $this->actingAsPersonaMobile(['mobile:read'], 'charge_nurse');
        $assigned->units()->attach($assignedUnit->unit_id, ['role' => 'charge_nurse', 'is_primary' => true]);

        $this->getJson('/api/mobile/v1/patients/demo-hummingbird-inpatient-a2p/operational-context?persona=charge_nurse')
            ->assertNotFound();

        $encounter->update(['status' => 'discharged', 'discharged_at' => now()]);
        $this->getJson("/api/mobile/v1/patients/{$contextRef}/operational-context?persona=charge_nurse")
            ->assertForbidden();

        $encounter->update(['status' => 'active', 'discharged_at' => null, 'is_deleted' => true]);
        $this->getJson("/api/mobile/v1/patients/{$contextRef}/operational-context?persona=charge_nurse")
            ->assertForbidden();
    }

    public function test_task_scoped_personas_reach_patient_context_only_while_their_task_is_active(): void
    {
        $service = app(MobilePatientContextService::class);

        // Transport is a task-scoped persona: it may see a patient's operational
        // context only while it owns an active transport task for that patient.
        $transportRef = 'SECRET-MRN-TRANSPORT-OWNERSHIP';
        $transport = $this->transportRequest(['status' => 'requested', 'patient_ref' => $transportRef]);
        $transportContext = $service->contextRefFor($transportRef);

        $this->actingAsPersonaMobile(['mobile:read'], 'transport');
        $this->getJson("/api/mobile/v1/patients/{$transportContext}/operational-context?persona=transport")
            ->assertOk()
            ->assertJsonPath('data.patient.patient_context_ref', $transportContext);

        // Once the task is completed the ownership expires: the same persona,
        // same context handle, is now denied.
        $transport->update(['status' => 'completed']);
        $this->actingAsPersonaMobile(['mobile:read'], 'transport');
        $this->getJson("/api/mobile/v1/patients/{$transportContext}/operational-context?persona=transport")
            ->assertForbidden();

        // A transport persona with no task for an otherwise-known patient is denied.
        $unrelatedRef = 'SECRET-MRN-TRANSPORT-UNRELATED';
        BedRequest::create([
            'patient_ref' => $unrelatedRef,
            'source' => 'ed',
            'service' => 'Medicine',
            'acuity_tier' => 2,
            'status' => 'pending',
        ]);
        $unrelatedContext = $service->contextRefFor($unrelatedRef);
        $this->actingAsPersonaMobile(['mobile:read'], 'transport');
        $this->getJson("/api/mobile/v1/patients/{$unrelatedContext}/operational-context?persona=transport")
            ->assertForbidden();

        // EVS ownership is gated symmetrically: active grants, cancelled revokes.
        $evsRef = 'SECRET-MRN-EVS-OWNERSHIP';
        $evs = $this->evsRequest(['status' => 'requested', 'patient_ref' => $evsRef]);
        $evsContext = $service->contextRefFor($evsRef);

        $this->actingAsPersonaMobile(['mobile:read'], 'evs');
        $this->getJson("/api/mobile/v1/patients/{$evsContext}/operational-context?persona=evs")
            ->assertOk()
            ->assertJsonPath('data.patient.patient_context_ref', $evsContext);

        $evs->update(['status' => 'canceled']);
        $this->actingAsPersonaMobile(['mobile:read'], 'evs');
        $this->getJson("/api/mobile/v1/patients/{$evsContext}/operational-context?persona=evs")
            ->assertForbidden();
    }

    public function test_patient_context_denies_roles_without_an_operational_grant(): void
    {
        $service = app(MobilePatientContextService::class);
        $patientRef = 'SECRET-MRN-UNAUTHORIZED-ROLE';
        BedRequest::create([
            'patient_ref' => $patientRef,
            'source' => 'ed',
            'service' => 'Medicine',
            'acuity_tier' => 2,
            'status' => 'pending',
        ]);
        $contextRef = $service->contextRefFor($patientRef);

        // Roles that hold neither broad access, an active task, nor a shared unit
        // are denied the patient context. Because the role is re-evaluated on every
        // request, a user whose role changes into one of these loses access on the
        // next call rather than retaining a stale grant.
        foreach (['or_nurse', 'pi_lead', 'staffing_coordinator', 'periop_manager'] as $role) {
            $this->actingAsPersonaMobile(['mobile:read'], $role);
            $this->getJson("/api/mobile/v1/patients/{$contextRef}/operational-context?persona={$role}")
                ->assertForbidden();
        }
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

    private function eligibleStaffingCandidate(StaffingRequest $request): int
    {
        $this->artisan('deployment:seed-registry');
        $this->artisan('deployment:seed-staff-roles');
        $member = StaffMember::create([
            'staff_key' => 'MOBILE:'.Str::uuid(),
            'source_system' => 'MOBILE_TEST',
            'external_id' => (string) Str::uuid(),
            'display_name' => 'Mobile Float Nurse',
            'employment_status' => 'active',
            'is_active' => true,
            'metadata' => [],
        ]);
        StaffAssignment::create([
            'staff_member_id' => $member->staff_member_id,
            'facility_key' => 'SUMMIT_REGIONAL',
            'service_line_code' => 'hospital_medicine',
            'role_code' => 'staff_nurse',
            'unit_id' => $request->unit_id,
            'primary_flag' => false,
            'coverage_model' => 'float',
            'fte' => 1,
            'review_status' => 'source_verified',
            'effective_start' => now()->subYear()->toDateString(),
            'is_active' => true,
        ]);
        DB::table('hosp_ref.staff_qualifications')->updateOrInsert(
            ['qualification_code' => 'role.staff_nurse'],
            [
                'display_name' => 'Staff Nurse role qualification',
                'qualification_type' => 'role',
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
            ['is_required' => true, 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now()],
        );
        DB::table('hosp_org.staff_member_qualifications')->insert([
            'qualification_uuid' => (string) Str::uuid(),
            'staff_member_id' => $member->staff_member_id,
            'qualification_code' => 'role.staff_nurse',
            'status' => 'verified',
            'source' => 'mobile-test',
            'verified_at' => now(),
            'effective_start' => now()->subYear(),
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $window = app(StaffingShiftWindowService::class)->forRequest($request);
        DB::table('prod.staff_availability_windows')->insert([
            'availability_uuid' => (string) Str::uuid(),
            'staff_member_id' => $member->staff_member_id,
            'window_type' => 'available',
            'starts_at' => $window['starts_at'],
            'ends_at' => $window['ends_at'],
            'timezone' => $window['timezone'],
            'source' => 'mobile-test',
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $member->staff_member_id;
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
