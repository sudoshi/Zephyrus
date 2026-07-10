<?php

namespace Tests\Feature\Transport;

use App\Models\Transport\TransportAssignment;
use App\Models\Transport\TransportEvent;
use App\Models\Transport\TransportRequest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransportLifecycleGovernanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_transition_graph_authorization_and_idempotency_are_server_enforced(): void
    {
        $dispatcher = $this->user('ops_leader');
        $frontline = $this->user('user');
        $transport = $this->user('transport');

        $this->actingAs($frontline)
            ->withHeader('Idempotency-Key', 'forbidden-create')
            ->postJson('/api/transport/requests', $this->createPayload())
            ->assertForbidden();

        $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', '')
            ->postJson('/api/transport/requests', $this->createPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $payload = $this->createPayload();
        $payload['assigned_team'] = 'Bypass Team';
        $payload['requested_by'] = 'demo-seeder';
        $first = $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', 'governed-create-1')
            ->postJson('/api/transport/requests', $payload)
            ->assertCreated()
            ->json('data');
        $replay = $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', 'governed-create-1')
            ->postJson('/api/transport/requests', $payload)
            ->assertCreated()
            ->json('data');

        $this->assertSame($first['transport_request_id'], $replay['transport_request_id']);
        $this->assertNull($first['assigned_team']);
        $this->assertSame("user:{$dispatcher->id}", $first['requested_by']);
        $this->assertDatabaseCount('prod.transport_commands', 1);
        $this->assertDatabaseCount('prod.transport_events', 1);

        $different = $payload;
        $different['priority'] = 'stat';
        $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', 'governed-create-1')
            ->postJson('/api/transport/requests', $different)
            ->assertConflict();

        $id = $first['transport_request_id'];
        $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', 'illegal-jump-1')
            ->postJson("/api/transport/requests/{$id}/status", ['status' => 'en_route'])
            ->assertConflict();
        $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', 'assignment-bypass-1')
            ->postJson("/api/transport/requests/{$id}/status", ['status' => 'assigned'])
            ->assertUnprocessable();
        $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', 'ambiguous-assignment-1')
            ->postJson("/api/transport/requests/{$id}/assign", [
                'resource_key' => 'porter_pool',
                'assigned_team' => 'Another Team',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resource_key', 'assigned_team']);

        $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', 'governed-assign-1')
            ->postJson("/api/transport/requests/{$id}/assign", ['resource_key' => 'porter_pool'])
            ->assertOk()
            ->assertJsonPath('data.status', 'assigned');

        $this->actingAs($transport)
            ->withHeader('Idempotency-Key', 'wrong-actor-progress-1')
            ->postJson("/api/transport/requests/{$id}/status", ['status' => 'dispatched'])
            ->assertForbidden();
        $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', 'dispatcher-progress-1')
            ->postJson("/api/transport/requests/{$id}/status", ['status' => 'dispatched'])
            ->assertOk();

        $lateReplay = $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', 'governed-create-1')
            ->postJson('/api/transport/requests', $payload)
            ->assertCreated()
            ->json('data');
        $this->assertSame('requested', $lateReplay['status']);
        $this->assertSame($first['lifecycle_version'], $lateReplay['lifecycle_version']);
        $this->assertSame('dispatched', TransportRequest::query()->findOrFail($id)->status);
    }

    public function test_handoff_evidence_is_required_before_completion_and_resources_release_at_terminal_state(): void
    {
        $dispatcher = $this->user('ops_leader');
        $created = $this->createViaApi($dispatcher, 'handoff-create');
        $id = $created['transport_request_id'];

        $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', 'handoff-assign')
            ->postJson("/api/transport/requests/{$id}/assign", ['resource_key' => 'porter_pool'])
            ->assertOk();

        foreach (['dispatched', 'arrived_pickup', 'picked_up', 'en_route', 'arrived_destination'] as $index => $status) {
            $this->actingAs($dispatcher)
                ->withHeader('Idempotency-Key', "handoff-progress-{$index}")
                ->postJson("/api/transport/requests/{$id}/status", ['status' => $status])
                ->assertOk();
        }

        $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', 'premature-completion')
            ->postJson("/api/transport/requests/{$id}/status", ['status' => 'completed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', 'incomplete-handoff')
            ->postJson("/api/transport/requests/{$id}/handoff", ['handoff_to' => '4 West RN'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['receiver_role', 'acceptance_status']);
        $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', 'riskless-handoff')
            ->postJson("/api/transport/requests/{$id}/handoff", [
                'handoff_to' => '4 West RN',
                'receiver_role' => 'registered_nurse',
                'acceptance_status' => 'accepted_with_risks',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('outstanding_risks');
        $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', 'predated-handoff')
            ->postJson("/api/transport/requests/{$id}/handoff", [
                'handoff_to' => '4 West RN',
                'receiver_role' => 'registered_nurse',
                'acceptance_status' => 'accepted',
                'accepted_at' => now()->subDay()->toISOString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accepted_at');

        $handoff = [
            'handoff_to' => '4 West RN',
            'receiver_role' => 'registered_nurse',
            'acceptance_status' => 'accepted_with_risks',
            'handoff_summary' => 'Portable oxygen remains in use.',
            'documents' => [['type' => 'transfer_packet', 'reference' => 'packet-42']],
            'outstanding_risks' => ['oxygen'],
        ];
        $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', 'accepted-handoff')
            ->postJson("/api/transport/requests/{$id}/handoff", $handoff)
            ->assertOk()
            ->assertJsonPath('data.status', 'handoff_complete')
            ->assertJsonPath('data.handoff_evidence.acceptance_status', 'accepted_with_risks');
        $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', 'accepted-handoff')
            ->postJson("/api/transport/requests/{$id}/handoff", $handoff)
            ->assertOk()
            ->assertJsonPath('data.status', 'handoff_complete');

        $this->assertDatabaseCount('prod.transport_handoff_evidence', 1);
        $this->assertSame(1, TransportEvent::query()->where('event_type', 'transport.handoff_started')->count());
        $this->assertSame(1, TransportEvent::query()->where('event_type', 'transport.handoff_complete')->count());

        $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', 'complete-after-handoff')
            ->postJson("/api/transport/requests/{$id}/status", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->assertDatabaseHas('prod.transport_assignments', [
            'transport_request_id' => $id,
            'status' => 'completed',
        ]);
        $this->assertNotNull(TransportAssignment::query()->where('transport_request_id', $id)->value('released_at'));
    }

    public function test_legacy_handoff_complete_state_can_capture_missing_receiver_evidence(): void
    {
        $dispatcher = $this->user('ops_leader');
        $request = $this->requestRow([
            'status' => 'handoff_complete',
            'lifecycle_version' => 8,
        ]);

        $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', 'legacy-handoff-evidence')
            ->postJson("/api/transport/requests/{$request->transport_request_id}/handoff", [
                'handoff_to' => '4 West RN',
                'receiver_role' => 'registered_nurse',
                'acceptance_status' => 'accepted',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'handoff_complete')
            ->assertJsonPath('data.handoff_evidence.acceptance_status', 'accepted');
        $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', 'legacy-handoff-complete')
            ->postJson("/api/transport/requests/{$request->transport_request_id}/status", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_resource_capacity_and_transporter_overlap_are_fail_closed(): void
    {
        config()->set('transport.resources.0.capacity', 1);
        $dispatcher = $this->user('ops_leader');
        $first = $this->createViaApi($dispatcher, 'capacity-create-1');
        $second = $this->createViaApi($dispatcher, 'capacity-create-2');

        $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', 'capacity-assign-1')
            ->postJson("/api/transport/requests/{$first['transport_request_id']}/assign", ['resource_key' => 'porter_pool'])
            ->assertOk();
        $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', 'capacity-assign-2')
            ->postJson("/api/transport/requests/{$second['transport_request_id']}/assign", ['resource_key' => 'porter_pool'])
            ->assertConflict();

        $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', 'capacity-cancel-1')
            ->postJson("/api/transport/requests/{$first['transport_request_id']}/cancel", ['reason' => 'Request withdrawn before pickup.'])
            ->assertOk();
        $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', 'capacity-assign-3')
            ->postJson("/api/transport/requests/{$second['transport_request_id']}/assign", ['resource_key' => 'porter_pool'])
            ->assertOk();

        $resource = collect($this->actingAs($dispatcher)
            ->getJson('/api/transport/resources')
            ->assertOk()
            ->json('data'))
            ->firstWhere('key', 'porter_pool');
        $this->assertNotNull($resource);
        $this->assertSame(1, $resource['capacity']);
        $this->assertSame(1, $resource['busy']);
        $this->assertSame(0, $resource['available']);

        $transporter = $this->user('transport');
        Sanctum::actingAs($transporter, ['mobile:read', 'mobile:act']);
        $criticalCare = $this->requestRow([
            'patient_ref' => 'mobile-critical-care',
            'transport_mode' => 'critical_care',
        ]);
        $criticalJob = collect($this->withHeader('X-Hummingbird-Role', 'transport')
            ->getJson('/api/mobile/v1/transport/queue')
            ->assertOk()
            ->json('data.jobs'))
            ->firstWhere('id', $criticalCare->transport_request_id);
        $this->assertFalse($criticalJob['available_to_claim']);
        $this->assertNotContains('assigned', $criticalJob['allowed_transitions']);
        $this->withHeader('X-Hummingbird-Role', 'transport')
            ->withHeader('Idempotency-Key', 'mobile-claim-capability-denied')
            ->postJson("/api/mobile/v1/transport/requests/{$criticalCare->transport_request_id}/status", ['status' => 'assigned'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('resource_key');
        $mobileOne = $this->requestRow(['patient_ref' => 'mobile-one']);
        $mobileTwo = $this->requestRow(['patient_ref' => 'mobile-two']);
        $this->withHeader('X-Hummingbird-Role', 'transport')
            ->withHeader('Idempotency-Key', 'mobile-claim-capacity-1')
            ->postJson("/api/mobile/v1/transport/requests/{$mobileOne->transport_request_id}/status", ['status' => 'assigned'])
            ->assertOk();
        $this->withHeader('X-Hummingbird-Role', 'transport')
            ->withHeader('Idempotency-Key', 'mobile-claim-capacity-2')
            ->postJson("/api/mobile/v1/transport/requests/{$mobileTwo->transport_request_id}/status", ['status' => 'assigned'])
            ->assertConflict();
    }

    public function test_web_and_mobile_cursor_pagination_is_deterministic_and_scope_stable(): void
    {
        $reader = $this->user('ops_leader');
        foreach (range(1, 6) as $index) {
            $this->requestRow([
                'patient_ref' => "cursor-{$index}",
                'priority' => $index <= 4 ? 'routine' : 'urgent',
                'needed_at' => now()->addMinutes(30),
            ]);
        }
        $withoutDeadline = $this->requestRow([
            'patient_ref' => 'cursor-without-deadline',
            'priority' => 'routine',
            'needed_at' => null,
        ]);

        $first = $this->actingAs($reader)
            ->getJson('/api/transport/requests?scope=active&priority=routine&per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('meta.has_more', true);
        $firstIds = collect($first->json('data'))->pluck('transport_request_id')->all();
        $cursor = $first->json('meta.next_cursor');
        $this->assertNotEmpty($cursor);

        $second = $this->actingAs($reader)
            ->getJson('/api/transport/requests?'.http_build_query([
                'scope' => 'active',
                'priority' => 'routine',
                'per_page' => 2,
                'cursor' => $cursor,
            ]))
            ->assertOk();
        $secondIds = collect($second->json('data'))->pluck('transport_request_id')->all();
        $this->assertCount(2, $secondIds);
        $this->assertSame([], array_values(array_intersect($firstIds, $secondIds)));
        $this->assertSame($firstIds, collect($firstIds)->sortDesc()->values()->all());
        $this->assertSame($secondIds, collect($secondIds)->sortDesc()->values()->all());

        $third = $this->actingAs($reader)
            ->getJson('/api/transport/requests?'.http_build_query([
                'scope' => 'active',
                'priority' => 'routine',
                'per_page' => 2,
                'cursor' => $second->json('meta.next_cursor'),
            ]))
            ->assertOk()
            ->assertJsonPath('meta.has_more', false);
        $this->assertSame([$withoutDeadline->transport_request_id], collect($third->json('data'))->pluck('transport_request_id')->all());

        $mobile = $this->user('transport');
        Sanctum::actingAs($mobile, ['mobile:read']);
        $mobilePage = $this->withHeader('X-Hummingbird-Role', 'transport')
            ->getJson('/api/mobile/v1/transport/queue?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('meta.has_more', true);
        $this->assertNotEmpty($mobilePage->json('meta.next_cursor'));
        $this->assertArrayHasKey('available_to_claim', $mobilePage->json('data.jobs.0'));

        $this->actingAs($reader)
            ->getJson('/api/transport/requests?cursor=not-a-cursor')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cursor');
        $missingDirection = rtrim(strtr(base64_encode(json_encode([
            'priority_rank' => 2,
            'needed_at_sort' => 'infinity',
            'transport_request_id' => 1,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $this->actingAs($reader)
            ->getJson('/api/transport/requests?'.http_build_query(['cursor' => $missingDirection]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cursor');
        Sanctum::actingAs($mobile, ['mobile:read']);
        $this->withHeader('X-Hummingbird-Role', 'transport')
            ->getJson('/api/mobile/v1/transport/queue?cursor=not-a-cursor')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cursor');
    }

    public function test_mobile_claim_progress_escalation_and_replay_share_the_canonical_lifecycle(): void
    {
        $owner = $this->user('transport');
        $other = $this->user('transport');
        $request = $this->requestRow(['patient_ref' => 'mobile-canonical']);

        Sanctum::actingAs($owner, ['mobile:read', 'mobile:act']);
        $headers = [
            'X-Hummingbird-Role' => 'transport',
            'Idempotency-Key' => 'mobile-canonical-claim',
        ];
        $first = $this->withHeaders($headers)
            ->postJson("/api/mobile/v1/transport/requests/{$request->transport_request_id}/status", ['status' => 'assigned'])
            ->assertOk()
            ->assertJsonPath('data.claimed_by_me', true)
            ->json('data');
        $replay = $this->withHeaders($headers)
            ->postJson("/api/mobile/v1/transport/requests/{$request->transport_request_id}/status", ['status' => 'assigned'])
            ->assertOk()
            ->json('data');
        $this->assertSame($first['lifecycle_version'], $replay['lifecycle_version']);
        $this->assertSame(1, TransportEvent::query()->where('event_type', 'transport.claimed')->count());

        Sanctum::actingAs($other, ['mobile:read', 'mobile:act']);
        $this->withHeaders([
            'X-Hummingbird-Role' => 'transport',
            'Idempotency-Key' => 'mobile-wrong-owner',
        ])->postJson("/api/mobile/v1/transport/requests/{$request->transport_request_id}/status", ['status' => 'dispatched'])
            ->assertForbidden();
        $this->withHeader('X-Hummingbird-Role', 'transport')
            ->getJson('/api/mobile/v1/transport/queue')
            ->assertOk()
            ->assertJsonMissing(['id' => $request->transport_request_id]);

        Sanctum::actingAs($owner, ['mobile:read', 'mobile:act']);
        $this->withHeaders([
            'X-Hummingbird-Role' => 'transport',
            'Idempotency-Key' => 'mobile-owner-dispatch',
        ])->postJson("/api/mobile/v1/transport/requests/{$request->transport_request_id}/status", ['status' => 'dispatched'])
            ->assertOk();
        $lateReplay = $this->withHeaders($headers)
            ->postJson("/api/mobile/v1/transport/requests/{$request->transport_request_id}/status", ['status' => 'assigned'])
            ->assertOk()
            ->json('data');
        $this->assertSame('assigned', $lateReplay['status']);
        $this->assertSame($first['lifecycle_version'], $lateReplay['lifecycle_version']);
        $this->assertSame('dispatched', $request->fresh()->status);
        $escalated = $this->withHeaders([
            'X-Hummingbird-Role' => 'transport',
            'Idempotency-Key' => 'mobile-owner-escalate',
        ])->postJson("/api/mobile/v1/transport/requests/{$request->transport_request_id}/status", [
            'status' => 'escalated',
            'reason' => 'Elevator outage blocks the route.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'escalated')
            ->assertJsonPath('data.allowed_transitions.0', 'dispatched');
        $this->assertNotContains('escalated', $escalated->json('data.allowed_transitions'));
        $this->withHeaders([
            'X-Hummingbird-Role' => 'transport',
            'Idempotency-Key' => 'mobile-owner-recover',
        ])->postJson("/api/mobile/v1/transport/requests/{$request->transport_request_id}/status", ['status' => 'dispatched'])
            ->assertOk()
            ->assertJsonPath('data.status', 'dispatched');
    }

    public function test_transport_event_ledger_rejects_mutation(): void
    {
        $request = $this->requestRow();
        $event = TransportEvent::query()->create([
            'event_uuid' => (string) Str::uuid(),
            'transport_request_id' => $request->transport_request_id,
            'event_type' => 'transport.requested',
            'to_status' => 'requested',
            'payload' => [],
            'occurred_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('prod.transport_events')
            ->where('transport_event_id', $event->transport_event_id)
            ->update(['event_type' => 'tampered']);
    }

    public function test_parent_request_delete_cannot_cascade_through_the_append_only_ledger(): void
    {
        $request = $this->requestRow();
        TransportEvent::query()->create([
            'event_uuid' => (string) Str::uuid(),
            'transport_request_id' => $request->transport_request_id,
            'event_type' => 'transport.requested',
            'to_status' => 'requested',
            'payload' => [],
            'occurred_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $request->delete();
    }

    public function test_transport_command_ledger_rejects_mutation(): void
    {
        $dispatcher = $this->user('ops_leader');
        $this->createViaApi($dispatcher, 'immutable-command');

        $this->expectException(QueryException::class);
        DB::table('prod.transport_commands')
            ->where('idempotency_key', 'immutable-command')
            ->update(['command_type' => 'tampered']);
    }

    public function test_transport_handoff_evidence_rejects_mutation(): void
    {
        $request = $this->requestRow();
        DB::table('prod.transport_handoff_evidence')->insert([
            'evidence_uuid' => (string) Str::uuid(),
            'transport_request_id' => $request->transport_request_id,
            'handoff_to' => '4 West RN',
            'receiver_role' => 'registered_nurse',
            'acceptance_status' => 'accepted',
            'accepted_at' => now(),
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('prod.transport_handoff_evidence')
            ->where('transport_request_id', $request->transport_request_id)
            ->delete();
    }

    public function test_migration_grandfathers_terminal_history_and_governs_active_legacy_assignments(): void
    {
        $migration = require database_path('migrations/2026_07_10_000400_govern_transport_lifecycle.php');
        $migration->down();

        $terminalId = $this->insertLegacyRequest([
            'request_type' => 'inpatient',
            'status' => 'completed',
            'patient_ref' => 'legacy-terminal',
            'assigned_team' => 'Legacy Porter Pool',
            'metadata' => json_encode(['source' => 'legacy-import']),
            'completed_at' => now()->subHour(),
        ]);
        $activeId = $this->insertLegacyRequest([
            'request_type' => 'transfer',
            'status' => 'assigned',
            'patient_ref' => 'legacy-active',
            'assigned_team' => 'Legacy Porter Pool',
            'assigned_at' => now()->subMinutes(15),
        ]);
        $dischargeId = $this->insertLegacyRequest([
            'request_type' => 'discharge',
            'status' => 'completed',
            'patient_ref' => 'legacy-discharge',
            'completed_at' => now()->subHour(),
        ]);
        $unresolvedId = $this->insertLegacyRequest([
            'request_type' => 'inpatient',
            'status' => 'dispatched',
            'patient_ref' => 'legacy-unresolved-assignment',
            'dispatched_at' => now()->subMinutes(10),
        ]);
        $escalatedId = $this->insertLegacyRequest([
            'request_type' => 'transfer',
            'status' => 'escalated',
            'patient_ref' => 'legacy-escalated',
            'assigned_team' => 'Legacy Porter Pool',
        ]);

        $migration->up();

        $terminal = DB::table('prod.transport_requests')->where('transport_request_id', $terminalId)->first();
        $terminalMetadata = json_decode((string) $terminal->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($terminal->handoff_required);
        $this->assertSame('legacy-import', $terminalMetadata['source']);
        $this->assertTrue($terminalMetadata['transport_governance']['grandfathered']);
        $this->assertSame('pre_lifecycle_terminal_record', $terminalMetadata['transport_governance']['reason']);
        $this->assertSame(1, $terminal->lifecycle_version);

        $active = DB::table('prod.transport_requests')->where('transport_request_id', $activeId)->first();
        $this->assertTrue($active->handoff_required);
        $this->assertDatabaseHas('prod.transport_assignments', [
            'transport_request_id' => $activeId,
            'status' => 'active',
        ]);
        $this->assertSame(2, DB::table('prod.transport_resources')->where('display_name', 'Legacy Porter Pool')->value('capacity'));

        $discharge = DB::table('prod.transport_requests')->where('transport_request_id', $dischargeId)->first();
        $this->assertFalse($discharge->handoff_required);
        $this->assertNull($discharge->metadata);

        $unresolvedResource = DB::table('prod.transport_assignments as ta')
            ->join('prod.transport_resources as tr', 'tr.transport_resource_id', '=', 'ta.transport_resource_id')
            ->where('ta.transport_request_id', $unresolvedId)
            ->value('tr.display_name');
        $this->assertSame('Legacy unresolved assignment', $unresolvedResource);
        $this->assertDatabaseHas('prod.transport_assignments', [
            'transport_request_id' => $unresolvedId,
            'status' => 'active',
        ]);
        $this->assertSame(
            'assigned',
            DB::table('prod.transport_requests')
                ->where('transport_request_id', $escalatedId)
                ->value('escalated_from_status'),
        );
    }

    public function test_configured_resource_sync_is_validated_and_idempotent(): void
    {
        $configuredCount = count(config('transport.resources'));
        $this->artisan('transport:sync-resources', ['--dry-run' => true])
            ->expectsOutputToContain("{$configuredCount} configured transport resources validated")
            ->assertSuccessful();
        $this->assertDatabaseCount('prod.transport_resources', 0);

        $this->artisan('transport:sync-resources')->assertSuccessful();
        $first = DB::table('prod.transport_resources')->orderBy('resource_key')->pluck('resource_uuid', 'resource_key')->all();
        $this->artisan('transport:sync-resources')->assertSuccessful();
        $second = DB::table('prod.transport_resources')->orderBy('resource_key')->pluck('resource_uuid', 'resource_key')->all();
        $this->assertCount($configuredCount, $first);
        $this->assertSame($first, $second);

        $invalid = config('transport.resources');
        $invalid[1]['key'] = $invalid[0]['key'];
        config()->set('transport.resources', $invalid);
        $this->artisan('transport:sync-resources', ['--dry-run' => true])->assertFailed();

        config()->set('transport.resources.1.key', 'critical_care_team');
        config()->set('transport.mobile_transporter_capabilities', []);
        $this->artisan('transport:sync-resources', ['--dry-run' => true])->assertFailed();
    }

    /** @return array<string,mixed> */
    private function createPayload(): array
    {
        return [
            'request_type' => 'inpatient',
            'priority' => 'urgent',
            'patient_ref' => 'patient-governed',
            'encounter_ref' => 'encounter-governed',
            'origin' => 'ED 4',
            'destination' => '4 West',
            'transport_mode' => 'stretcher',
            'needed_at' => now()->addMinutes(20)->toISOString(),
        ];
    }

    /** @return array<string,mixed> */
    private function createViaApi(User $dispatcher, string $key): array
    {
        return $this->actingAs($dispatcher)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/transport/requests', array_merge($this->createPayload(), [
                'patient_ref' => "patient-{$key}",
                'encounter_ref' => "encounter-{$key}",
            ]))
            ->assertCreated()
            ->json('data');
    }

    private function requestRow(array $overrides = []): TransportRequest
    {
        return TransportRequest::query()->create(array_merge([
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'inpatient',
            'priority' => 'routine',
            'status' => 'requested',
            'patient_ref' => 'patient-'.Str::uuid(),
            'origin' => 'ED',
            'destination' => '4 West',
            'transport_mode' => 'stretcher',
            'requested_at' => now(),
            'needed_at' => now()->addMinutes(20),
            'handoff_required' => true,
            'lifecycle_version' => 1,
            'is_deleted' => false,
        ], $overrides));
    }

    /** @param  array<string,mixed>  $overrides */
    private function insertLegacyRequest(array $overrides): int
    {
        return (int) DB::table('prod.transport_requests')->insertGetId(array_merge([
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'inpatient',
            'priority' => 'routine',
            'status' => 'requested',
            'patient_ref' => 'legacy-'.Str::uuid(),
            'origin' => 'ED',
            'destination' => '4 West',
            'transport_mode' => 'stretcher',
            'requested_at' => now()->subHours(2),
            'needed_at' => now()->subHour(),
            'is_deleted' => false,
            'created_at' => now()->subHours(2),
            'updated_at' => now(),
        ], $overrides), 'transport_request_id');
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }
}
