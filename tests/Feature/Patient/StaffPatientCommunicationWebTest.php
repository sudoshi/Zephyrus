<?php

namespace Tests\Feature\Patient;

use App\Models\Audit\UserEvent;
use App\Models\User;
use App\Services\Patient\Messaging\StaffPatientCommunicationFailure;
use App\Services\Patient\Messaging\StaffPatientCommunicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class StaffPatientCommunicationWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'hummingbird-patient.enabled' => true,
            'hummingbird-patient.features.messaging' => true,
            'hummingbird-patient.staff_messaging.enabled' => true,
            'hummingbird-patient.staff_messaging.governance_status' => 'approved',
        ]);
    }

    public function test_workspace_is_feature_and_capability_gated_and_exposes_content_free_inbox(): void
    {
        $eligible = User::factory()->create([
            'role' => 'charge_nurse',
            'is_active' => true,
        ]);

        $this->actingAs($eligible)
            ->get('/patient-communications')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertInertia(fn (Assert $page) => $page
                ->component('PatientCommunications/Index')
                ->where('initialInbox.count', 0)
                ->has('initialInbox.items', 0)
                ->where('auth.can.view_patient_communications', true)
                ->where('auth.can.respond_patient_communications', true)
                ->where('features.patient_communications', true)
                ->where('endpoints.inbox', route('patient-communications.inbox'))
                ->where('endpoints.routeCandidates', route('patient-communications.threads.route-candidates', ['workItemUuid' => '__WORK_ITEM_UUID__']))
                ->where('endpoints.release', route('patient-communications.threads.release', ['workItemUuid' => '__WORK_ITEM_UUID__']))
                ->where('endpoints.reassign', route('patient-communications.threads.reassign', ['workItemUuid' => '__WORK_ITEM_UUID__']))
                ->where('endpoints.reroute', route('patient-communications.threads.reroute', ['workItemUuid' => '__WORK_ITEM_UUID__']))
            );

        $this->assertDatabaseHas('audit.user_events', [
            'actor_user_id' => $eligible->getKey(),
            'action' => 'patient_communications.inbox_viewed',
            'category' => 'access',
            'outcome' => 'success',
            'source_surface' => 'web',
        ]);

        $ineligible = User::factory()->create([
            'role' => 'transport',
            'is_active' => true,
        ]);
        $this->actingAs($ineligible)
            ->get('/patient-communications')
            ->assertForbidden();

        config(['hummingbird-patient.staff_messaging.enabled' => false]);
        $this->actingAs($eligible)
            ->get('/patient-communications')
            ->assertNotFound()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_shared_service_audits_web_and_mobile_calls_as_distinct_surfaces(): void
    {
        $staff = User::factory()->create([
            'role' => 'charge_nurse',
            'is_active' => true,
        ]);

        $this->actingAs($staff)
            ->getJson('/patient-communications/inbox')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('data.count', 0)
            ->assertJsonPath('meta.classification', 'patient_communication_restricted')
            ->assertJsonPath('meta.offline_writes_allowed', false);

        $token = $staff->createToken('staff-web-surface-test', ['mobile:read'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson('/api/mobile/v1/patient-communications/inbox')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('data.count', 0)
            ->assertJsonPath('links.web', url('/patient-communications'));

        config(['hummingbird-patient.staff_messaging.enabled' => false]);
        $this->withToken($token)
            ->getJson('/api/mobile/v1/patient-communications/inbox')
            ->assertNotFound()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

        $surfaces = UserEvent::query()
            ->where('actor_user_id', $staff->getKey())
            ->where('action', 'patient_communications.inbox_viewed')
            ->pluck('source_surface')
            ->all();

        $this->assertContains('web', $surfaces);
        $this->assertContains('hummingbird', $surfaces);
    }

    public function test_web_mutation_uses_strict_json_header_idempotency_and_returns_conflict_without_retry(): void
    {
        $staff = User::factory()->create([
            'role' => 'charge_nurse',
            'is_active' => true,
        ]);
        $workItemUuid = (string) Str::uuid7();
        $idempotencyKey = (string) Str::uuid7();

        $this->mock(StaffPatientCommunicationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('claim')
                ->once()
                ->andThrow(StaffPatientCommunicationFailure::staleVersion());
        });

        $this->actingAs($staff)
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson("/patient-communications/threads/{$workItemUuid}/claim", [
                'work_item_version' => 1,
                'thread_version' => 2,
            ])
            ->assertConflict()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('error.code', 'stale_version')
            ->assertJsonPath('meta.classification', 'patient_communication_restricted')
            ->assertJsonPath('meta.offline_writes_allowed', false);
    }

    public function test_web_mutation_rejects_missing_idempotency_header_before_service_execution(): void
    {
        $staff = User::factory()->create([
            'role' => 'charge_nurse',
            'is_active' => true,
        ]);
        $workItemUuid = (string) Str::uuid7();

        $this->mock(StaffPatientCommunicationService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('reply');
        });

        $this->actingAs($staff)
            ->postJson("/patient-communications/threads/{$workItemUuid}/reply", [
                'work_item_version' => 1,
                'thread_version' => 2,
                'message' => 'A patient-visible response.',
                'client_message_uuid' => (string) Str::uuid7(),
            ])
            ->assertUnprocessable()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonValidationErrors('idempotency_key');
    }

    public function test_web_routes_delegate_governed_routing_discovery_and_reroute(): void
    {
        $staff = User::factory()->create([
            'role' => 'charge_nurse',
            'is_active' => true,
        ]);
        $workItemUuid = (string) Str::uuid7();
        $poolUuid = (string) Str::uuid7();
        $eventUuid = (string) Str::uuid7();

        $this->mock(StaffPatientCommunicationService::class, function (MockInterface $mock) use ($workItemUuid, $poolUuid, $eventUuid): void {
            $mock->shouldReceive('routeCandidates')
                ->once()
                ->andReturn([
                    'work_item_uuid' => $workItemUuid,
                    'work_item_version' => 3,
                    'thread_version' => 5,
                    'actions' => [
                        'can_release' => false,
                        'can_reassign' => false,
                        'can_reroute' => true,
                    ],
                    'reason_options' => [
                        'release' => [],
                        'reassign' => [],
                        'reroute' => [['code' => 'wrong_team', 'label' => 'Wrong care team']],
                    ],
                    'reassign_candidates' => [],
                    'reroute_candidates' => [[
                        'pool_uuid' => $poolUuid,
                        'label' => 'Hospital Medicine Care Team',
                        'scope_type' => 'facility',
                        'unit' => null,
                    ]],
                ]);
            $mock->shouldReceive('reroute')
                ->once()
                ->andReturn([
                    'work_item' => ['work_item_uuid' => $workItemUuid],
                    'message' => null,
                    'event_uuid' => $eventUuid,
                    'replayed' => false,
                ]);
        });

        $this->actingAs($staff)
            ->getJson("/patient-communications/threads/{$workItemUuid}/route-candidates")
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('data.work_item_uuid', $workItemUuid)
            ->assertJsonPath('data.actions.can_reroute', true)
            ->assertJsonPath('data.reroute_candidates.0.pool_uuid', $poolUuid)
            ->assertJsonMissingPath('data.reroute_candidates.0.responsibility_pool_id');

        $this->actingAs($staff)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/patient-communications/threads/{$workItemUuid}/reroute", [
                'work_item_version' => 3,
                'thread_version' => 5,
                'target_pool_uuid' => $poolUuid,
                'reason_code' => 'wrong_team',
            ])
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('data.event_uuid', $eventUuid)
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('meta.offline_writes_allowed', false);
    }

    public function test_web_reroute_preserves_the_content_minimized_exact_replay_receipt(): void
    {
        $staff = User::factory()->create([
            'role' => 'charge_nurse',
            'is_active' => true,
        ]);
        $workItemUuid = (string) Str::uuid7();
        $poolUuid = (string) Str::uuid7();
        $eventUuid = (string) Str::uuid7();

        $this->mock(StaffPatientCommunicationService::class, function (MockInterface $mock) use ($eventUuid): void {
            $mock->shouldReceive('reroute')
                ->once()
                ->andReturn([
                    'work_item' => null,
                    'message' => null,
                    'event_uuid' => $eventUuid,
                    'replayed' => true,
                ]);
        });

        $response = $this->actingAs($staff)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/patient-communications/threads/{$workItemUuid}/reroute", [
                'work_item_version' => 3,
                'thread_version' => 5,
                'target_pool_uuid' => $poolUuid,
                'reason_code' => 'wrong_team',
            ])
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('data.work_item', null)
            ->assertJsonPath('data.message', null)
            ->assertJsonPath('data.event_uuid', $eventUuid)
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('meta.offline_writes_allowed', false);

        $this->assertSame([
            'work_item' => null,
            'message' => null,
            'event_uuid' => $eventUuid,
            'replayed' => true,
        ], $response->json('data'));
        $this->assertStringNotContainsString($workItemUuid, $response->getContent());
        $this->assertStringNotContainsString($poolUuid, $response->getContent());
    }
}
