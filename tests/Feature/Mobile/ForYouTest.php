<?php

namespace Tests\Feature\Mobile;

use App\Models\Barrier;
use App\Models\BedRequest;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientMessage;
use App\Models\Patient\PatientMessageThread;
use App\Models\Patient\PatientNotificationOutbox;
use App\Models\Patient\PatientPrincipal;
use App\Models\PatientCommunication\PoolMembership;
use App\Models\PatientCommunication\ResponsibilityPool;
use App\Models\PatientCommunication\ThreadWorkItem;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 1 — the For You queue BFF (/api/mobile/v1/for-you): token-gated, tier-ranked,
 * and PHI-minimized (no patient identifiers in the payload).
 */
class ForYouTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_MESSAGE = 'SECRET patient question that must never reach For You';

    private function accessToken(): string
    {
        $user = User::factory()->create([
            'must_change_password' => false,
            'is_active' => true,
            'workflow_preference' => 'rtdc',
        ]);

        return $this->postJson('/api/auth/token', [
            'username' => $user->username,
            'password' => 'password',
        ])->json('access_token');
    }

    public function test_for_you_requires_a_token(): void
    {
        $this->getJson('/api/mobile/v1/for-you')->assertStatus(401);
    }

    public function test_for_you_ranks_critical_first_and_counts_items(): void
    {
        // A high-acuity pending placement (critical) and an open barrier (warning).
        BedRequest::create(['patient_ref' => 'MRN-1', 'source' => 'ed', 'acuity_tier' => 1]);
        Barrier::create(['category' => 'placement']);

        $this->withToken($this->accessToken())->getJson('/api/mobile/v1/for-you')
            ->assertOk()
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('data.0.type', 'bed_request')
            ->assertJsonPath('data.0.tier', 'critical')
            ->assertJsonPath('data.1.type', 'barrier');
    }

    public function test_for_you_omits_patient_identifiers(): void
    {
        BedRequest::create(['patient_ref' => 'SECRET-MRN-999', 'source' => 'transfer', 'acuity_tier' => 3]);

        $body = $this->withToken($this->accessToken())->getJson('/api/mobile/v1/for-you')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('SECRET-MRN-999', $body);
    }

    public function test_patient_communication_attention_is_optional_when_any_governance_flag_is_disabled(): void
    {
        $staff = $this->staff('charge_nurse');
        $pool = $this->pool();
        $this->membership($pool, $staff);
        $this->workItem($pool, 'pool_owned');

        foreach ([
            'hummingbird-patient.enabled',
            'hummingbird-patient.features.messaging',
            'hummingbird-patient.staff_messaging.enabled',
        ] as $disabledFlag) {
            $this->enablePatientCommunications();
            config([$disabledFlag => false]);

            $response = $this->forYou($staff)
                ->assertOk()
                ->assertJsonPath('meta.classification', 'phi_minimized_restricted')
                ->assertJsonPath('meta.offline_cache_allowed', false)
                ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

            $this->assertSame([], $this->communicationItems($response));
        }

        $this->enablePatientCommunications();
        config(['hummingbird-patient.staff_messaging.governance_status' => 'draft_requires_approval']);

        $this->assertSame([], $this->communicationItems($this->forYou($staff)->assertOk()));
    }

    public function test_patient_communication_attention_requires_capability_and_fresh_active_pool_membership_on_every_request(): void
    {
        $this->enablePatientCommunications();
        $pool = $this->pool();
        $workItem = $this->workItem($pool, 'pool_owned');

        $memberWithoutCapability = $this->staff('transport');
        $this->membership($pool, $memberWithoutCapability);
        $this->assertSame([], $this->communicationItems($this->forYou($memberWithoutCapability)->assertOk()));

        $capableStaff = $this->staff('charge_nurse');
        $this->assertSame([], $this->communicationItems($this->forYou($capableStaff)->assertOk()));

        $membership = $this->membership($pool, $capableStaff);
        $this->assertSame(
            ['patient-communication-'.$workItem->work_item_uuid],
            collect($this->communicationItems($this->forYou($capableStaff)->assertOk()))
                ->pluck('id')
                ->all(),
        );

        $membership->update([
            'availability_state' => 'ended',
            'effective_until' => now(),
        ]);

        $this->assertSame([], $this->communicationItems($this->forYou($capableStaff)->assertOk()));
    }

    public function test_patient_communication_attention_is_content_free_and_uses_only_a_governed_unit_label(): void
    {
        $this->enablePatientCommunications();
        $unit = $this->unit('Governed 7 North');
        $staff = $this->staff('charge_nurse');
        $pool = $this->pool($unit, 'SECRET internal pool display name');
        $this->membership($pool, $staff);
        $fixture = $this->workItem($pool, 'pool_owned');

        $response = $this->forYou($staff)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'patient-communication-'.$fixture->work_item_uuid)
            ->assertJsonPath('data.0.type', 'patient_communication')
            ->assertJsonPath('data.0.domain', 'communications')
            ->assertJsonPath('data.0.unit', 'Governed 7 North')
            ->assertJsonPath('data.0.primary_action.kind', 'view')
            ->assertJsonPath('data.0.primary_action.method', 'GET')
            ->assertJsonPath('data.0.primary_action.requires_online', true)
            ->assertJsonPath(
                'data.0.primary_action.endpoint',
                '/api/mobile/v1/patient-communications/threads/'.$fixture->work_item_uuid,
            )
            ->assertJsonMissingPath('data.0.patient_context_ref');

        $body = $response->getContent();
        foreach ([
            self::SECRET_MESSAGE,
            'SECRET Patient Display Name',
            'SECRET internal pool display name',
            'SECRET topic label',
            'SECRET topic description and free text',
            'SECRET grant reason and free text',
            'SECRET-source-system',
            (string) $fixture->thread->thread_uuid,
            (string) $fixture->accessGrant->grant_uuid,
            (string) $fixture->accessGrant->encounter_uuid,
            (string) $fixture->message->message_uuid,
        ] as $forbiddenValue) {
            $this->assertStringNotContainsString($forbiddenValue, $body);
        }

        foreach ([
            'patient_context_ref',
            'message_body',
            'encrypted_body',
            'body_digest',
            'access_grant_id',
            'grant_uuid',
            'encounter_uuid',
            'source_encounter_ref_digest',
            'responsibility_pool_ref_digest',
            'topic_description',
        ] as $forbiddenKey) {
            $this->assertStringNotContainsString('"'.$forbiddenKey.'"', $body);
        }

        $enterprisePool = $this->pool(displayName: 'Enterprise pool');
        $this->membership($enterprisePool, $staff);
        $enterpriseItem = $this->workItem($enterprisePool, 'pool_owned');
        $enterpriseResult = collect($this->communicationItems($this->forYou($staff)->assertOk()))
            ->firstWhere('id', 'patient-communication-'.$enterpriseItem->work_item_uuid);

        $this->assertIsArray($enterpriseResult);
        $this->assertNull($enterpriseResult['unit']);
    }

    public function test_patient_communication_assignment_and_pool_isolation_are_enforced(): void
    {
        $this->enablePatientCommunications();
        $memberA = $this->staff('charge_nurse');
        $memberB = $this->staff('hospitalist');
        $poolA = $this->pool(displayName: 'Pool A');
        $poolB = $this->pool(displayName: 'Pool B');
        $this->membership($poolA, $memberA);
        $this->membership($poolA, $memberB);

        $poolOwned = $this->workItem($poolA, 'pool_owned');
        $assignedA = $this->workItem($poolA, 'assigned', $memberA);
        $acknowledgedB = $this->workItem($poolA, 'acknowledged', $memberB);
        $assignedWithoutMembership = $this->workItem($poolB, 'assigned', $memberA);
        $assignedEscalated = $this->workItem($poolA, 'escalated', $memberA);

        $idsA = $this->communicationIds($this->forYou($memberA)->assertOk());
        $idsB = $this->communicationIds($this->forYou($memberB)->assertOk());

        $this->assertContains('patient-communication-'.$poolOwned->work_item_uuid, $idsA);
        $this->assertContains('patient-communication-'.$poolOwned->work_item_uuid, $idsB);
        $this->assertContains('patient-communication-'.$assignedA->work_item_uuid, $idsA);
        $this->assertNotContains('patient-communication-'.$assignedA->work_item_uuid, $idsB);
        $this->assertContains('patient-communication-'.$acknowledgedB->work_item_uuid, $idsB);
        $this->assertNotContains('patient-communication-'.$acknowledgedB->work_item_uuid, $idsA);
        $this->assertNotContains('patient-communication-'.$assignedWithoutMembership->work_item_uuid, $idsA);
        $this->assertNotContains('patient-communication-'.$assignedEscalated->work_item_uuid, $idsA);
        $this->assertNotContains('patient-communication-'.$assignedEscalated->work_item_uuid, $idsB);
    }

    public function test_patient_communication_attention_ranks_urgency_and_caps_additions_at_fifty(): void
    {
        $this->enablePatientCommunications();
        $staff = $this->staff('charge_nurse');
        $pool = $this->pool();
        $this->membership($pool, $staff);

        for ($index = 0; $index < 50; $index++) {
            $this->workItem($pool, 'pool_owned');
        }

        $warning = $this->workItem($pool, 'rerouted');
        $critical = $this->workItem($pool, 'escalated');

        $items = $this->communicationItems($this->forYou($staff)->assertOk());

        $this->assertCount(50, $items);
        $this->assertSame('patient-communication-'.$critical->work_item_uuid, $items[0]['id']);
        $this->assertSame('critical', $items[0]['tier']);
        $this->assertSame('patient-communication-'.$warning->work_item_uuid, $items[1]['id']);
        $this->assertSame('warning', $items[1]['tier']);
        $this->assertSame(48, collect($items)->where('tier', 'info')->count());
    }

    public function test_patient_communication_attention_classifies_state_and_deadline_urgency(): void
    {
        $this->enablePatientCommunications();
        $staff = $this->staff('charge_nurse');
        $pool = $this->pool();
        $this->membership($pool, $staff);

        $escalated = $this->workItem($pool, 'escalated');
        $overEscalation = $this->workItem($pool, 'pool_owned');
        $overEscalation->update([
            'first_routed_at' => now()->subMinutes(61),
            'due_at' => now()->subMinutes(31),
            'escalate_at' => now()->subMinute(),
        ]);
        $rerouted = $this->workItem($pool, 'rerouted');
        $overdue = $this->workItem($pool, 'pool_owned');
        $overdue->update([
            'first_routed_at' => now()->subMinutes(31),
            'due_at' => now()->subMinute(),
            'escalate_at' => now()->addMinutes(29),
        ]);
        $new = $this->workItem($pool, 'pool_owned');
        $assigned = $this->workItem($pool, 'assigned', $staff);

        $items = collect($this->communicationItems($this->forYou($staff)->assertOk()));
        $byId = $items->keyBy('id');

        $this->assertSame('critical', $byId['patient-communication-'.$escalated->work_item_uuid]['tier']);
        $this->assertSame('critical', $byId['patient-communication-'.$overEscalation->work_item_uuid]['tier']);
        $this->assertSame('warning', $byId['patient-communication-'.$rerouted->work_item_uuid]['tier']);
        $this->assertSame('warning', $byId['patient-communication-'.$overdue->work_item_uuid]['tier']);
        $this->assertSame('info', $byId['patient-communication-'.$new->work_item_uuid]['tier']);
        $this->assertSame('info', $byId['patient-communication-'.$assigned->work_item_uuid]['tier']);
        $this->assertSame(
            ['critical', 'critical', 'warning', 'warning', 'info', 'info'],
            $items->pluck('tier')->all(),
        );
    }

    public function test_enabled_projection_schema_failure_returns_safe_no_cache_503_instead_of_false_all_clear(): void
    {
        $this->enablePatientCommunications();
        $staff = $this->staff('charge_nurse');

        Schema::partialMock()
            ->shouldReceive('hasTable')
            ->once()
            ->with('patient_communications.responsibility_pools')
            ->andReturnFalse();

        $response = $this->forYou($staff)
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'communications_unavailable')
            ->assertJsonPath('error.message', 'Patient communications are temporarily unavailable.')
            ->assertJsonPath('meta.classification', 'phi_minimized_restricted')
            ->assertJsonPath('meta.offline_cache_allowed', false)
            ->assertJsonMissingPath('data')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Expires', '0');

        $this->assertStringNotContainsString('responsibility_pools', $response->getContent());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    private function enablePatientCommunications(): void
    {
        config([
            'hummingbird-patient.enabled' => true,
            'hummingbird-patient.features.messaging' => true,
            'hummingbird-patient.staff_messaging.enabled' => true,
            'hummingbird-patient.staff_messaging.governance_status' => 'approved',
        ]);
    }

    private function staff(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function unit(string $name = 'For You Test Unit'): Unit
    {
        return Unit::query()->create([
            'name' => $name,
            'abbreviation' => 'FY'.Str::upper(Str::random(4)),
            'type' => 'med_surg',
            'staffed_bed_count' => 12,
            'ratio_floor' => 4,
            'access_standard_minutes' => 120,
            'is_deleted' => false,
        ]);
    }

    private function pool(?Unit $unit = null, string $displayName = 'For You Test Pool'): ResponsibilityPool
    {
        return ResponsibilityPool::query()->create([
            'pool_uuid' => (string) Str::uuid7(),
            'pool_key_digest' => hash('sha256', (string) Str::uuid7()),
            'topic_code' => 'care_question',
            'display_name' => $displayName,
            'routing_policy_version' => 'for-you-test-v1',
            'scope_type' => $unit === null ? 'enterprise' : 'unit',
            'unit_id' => $unit?->getKey(),
            'status' => 'active',
            'response_target_minutes' => 30,
            'escalation_target_minutes' => 60,
        ]);
    }

    private function membership(ResponsibilityPool $pool, User $staff): PoolMembership
    {
        return PoolMembership::query()->create([
            'membership_uuid' => (string) Str::uuid7(),
            'responsibility_pool_id' => $pool->getKey(),
            'staff_user_id' => $staff->getKey(),
            'membership_role' => 'responder',
            'availability_state' => 'active',
            'can_claim' => true,
            'can_reply' => true,
            'can_reroute' => false,
            'can_close' => false,
            'effective_from' => now()->subMinute(),
        ]);
    }

    private function workItem(
        ResponsibilityPool $pool,
        string $ownershipState,
        ?User $assignee = null,
    ): ThreadWorkItem {
        $principal = PatientPrincipal::query()->create([
            'principal_uuid' => (string) Str::uuid7(),
            'principal_type' => 'patient',
            'display_name' => 'SECRET Patient Display Name',
            'status' => 'active',
            'is_active' => true,
        ]);
        $accessGrant = PatientEncounterAccessGrant::query()->create([
            'grant_uuid' => (string) Str::uuid7(),
            'principal_id' => $principal->getKey(),
            'encounter_uuid' => (string) Str::uuid7(),
            'source_encounter_ref_digest' => hash('sha256', 'SECRET-encounter-'.Str::uuid7()),
            'source_system_key' => 'SECRET-source-system',
            'relationship' => 'self',
            'scopes' => ['care_pathway', 'care_team', 'messaging:read', 'messaging:write'],
            'purpose_of_use' => 'patient_access',
            'status' => 'active',
            'valid_from' => now()->subHour(),
            'grant_reason' => 'SECRET grant reason and free text',
            'version' => 1,
        ]);
        $thread = PatientMessageThread::query()->create([
            'thread_uuid' => (string) Str::uuid7(),
            'access_grant_id' => $accessGrant->getKey(),
            'opened_by_principal_id' => $principal->getKey(),
            'topic_code' => 'care_question',
            'topic_label' => 'SECRET topic label',
            'topic_description' => 'SECRET topic description and free text',
            'status' => 'open',
            'ownership_state' => 'assigned',
            'routing_policy_version' => 'for-you-test-v1',
            'expected_response_window' => 'SECRET response window free text',
            'urgent_guidance_version' => 'SECRET-guidance-v1',
            'responsibility_pool_ref_digest' => hash('sha256', 'SECRET-pool-'.Str::uuid7()),
            'creation_idempotency_key_digest' => hash('sha256', 'SECRET-idempotency-'.Str::uuid7()),
            'creation_request_payload_digest' => hash('sha256', 'SECRET-payload-'.Str::uuid7()),
            'version' => 2,
            'last_message_at' => now(),
        ]);
        $message = PatientMessage::query()->create([
            'message_uuid' => (string) Str::uuid7(),
            'message_thread_id' => $thread->getKey(),
            'sender_type' => 'patient',
            'sender_principal_id' => $principal->getKey(),
            'visibility' => 'patient_visible',
            'message_kind' => 'message',
            'encrypted_body' => self::SECRET_MESSAGE,
            'encryption_key_version' => 'SECRET-key-v1',
            'body_digest' => hash('sha256', self::SECRET_MESSAGE),
            'body_character_count' => strlen(self::SECRET_MESSAGE),
            'delivery_state' => 'accepted',
            'sent_at' => now(),
        ]);
        $outbox = PatientNotificationOutbox::query()->create([
            'outbox_uuid' => (string) Str::uuid7(),
            'principal_id' => $principal->getKey(),
            'access_grant_id' => $accessGrant->getKey(),
            'aggregate_type' => 'patient_message_thread',
            'aggregate_uuid' => (string) $thread->thread_uuid,
            'event_type' => 'patient.messaging.message_submitted',
            'destination' => 'staff_inbox',
            'routing_metadata' => ['content_included' => false],
            'idempotency_key_digest' => hash('sha256', 'SECRET-outbox-'.Str::uuid7()),
            'available_at' => now(),
            'occurred_at' => now(),
        ]);
        $firstRoutedAt = now()->subMinute();

        $workItem = ThreadWorkItem::query()->create([
            'work_item_uuid' => (string) Str::uuid7(),
            'message_thread_id' => $thread->getKey(),
            'access_grant_id' => $accessGrant->getKey(),
            'responsibility_pool_id' => $pool->getKey(),
            'assigned_user_id' => $assignee?->getKey(),
            'status' => 'open',
            'ownership_state' => $ownershipState,
            'source_thread_version' => 2,
            'row_version' => 1,
            'last_outbox_id' => $outbox->getKey(),
            'first_routed_at' => $firstRoutedAt,
            'due_at' => $firstRoutedAt->copy()->addMinutes(30),
            'escalate_at' => $firstRoutedAt->copy()->addMinutes(60),
            'last_message_at' => now(),
        ]);

        $workItem->setRelation('thread', $thread);
        $workItem->setRelation('accessGrant', $accessGrant);
        $workItem->setRelation('message', $message);

        return $workItem;
    }

    private function forYou(User $staff): TestResponse
    {
        $token = $staff->createToken('for-you-test', ['mobile:read'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson('/api/mobile/v1/for-you');
    }

    /** @return array<int, array<string, mixed>> */
    private function communicationItems(TestResponse $response): array
    {
        return collect($response->json('data') ?? [])
            ->where('type', 'patient_communication')
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function communicationIds(TestResponse $response): array
    {
        return collect($this->communicationItems($response))->pluck('id')->all();
    }
}
