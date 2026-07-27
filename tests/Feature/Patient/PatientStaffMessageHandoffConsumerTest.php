<?php

namespace Tests\Feature\Patient;

use App\Models\Encounter;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientMessage;
use App\Models\Patient\PatientMessageRoutingEvent;
use App\Models\Patient\PatientMessageThread;
use App\Models\Patient\PatientNotificationDeliveryAttempt;
use App\Models\Patient\PatientNotificationOutbox;
use App\Models\Patient\PatientPrincipal;
use App\Models\Patient\PatientSession;
use App\Models\PatientCommunication\ConsumerHeartbeat;
use App\Models\PatientCommunication\PoolMembership;
use App\Models\PatientCommunication\ResponsibilityPool;
use App\Models\PatientCommunication\StaffActionEvent;
use App\Models\PatientCommunication\ThreadWorkItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\Patient\Messaging\DatabasePatientMessageHandoffConsumer;
use App\Services\Patient\PatientHmac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatientStaffMessageHandoffConsumerTest extends TestCase
{
    use RefreshDatabase;

    private const POLICY_VERSION = 'test-staff-handoff-policy-v1';

    private const GUIDANCE_VERSION = 'test-staff-handoff-guidance-v1';

    private const POOL_KEY = 'test.unit.care-team';

    public function test_staff_handoff_schema_preserves_the_separate_patient_identity_realm(): void
    {
        foreach ([
            'patient_communications.responsibility_pools',
            'patient_communications.pool_memberships',
            'patient_communications.thread_work_items',
            'patient_communications.staff_action_events',
            'patient_communications.consumer_heartbeats',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing staff handoff table {$table}.");
        }

        $this->assertFalse(Schema::hasColumn(
            'patient_communications.thread_work_items',
            'encrypted_body',
        ));
        $this->assertFalse(Schema::hasColumn(
            'patient_communications.thread_work_items',
            'message_body',
        ));
    }

    public function test_readiness_requires_approved_flags_complete_pool_coverage_and_a_fresh_worker(): void
    {
        [$unit, $staff] = $this->operationalUnitAndResponder();
        $this->configureMessaging($unit);
        $consumer = $this->app->make(DatabasePatientMessageHandoffConsumer::class);

        $this->assertFalse($consumer->readyForPolicy(self::POLICY_VERSION));

        $pool = $this->createPool($unit);
        $this->assertFalse($consumer->readyForPolicy(self::POLICY_VERSION));

        $this->createMembership($pool, $staff);
        $this->assertFalse($consumer->readyForPolicy(self::POLICY_VERSION));

        $this->assertSame(
            ['selected' => 0, 'delivered' => 0, 'failed' => 0],
            $consumer->consumeBatch('test-readiness-worker'),
        );
        $this->assertTrue($consumer->readyForPolicy(self::POLICY_VERSION));

        ConsumerHeartbeat::query()->whereKey('patient-message-staff-inbox-v1')->update([
            'last_seen_at' => now()->subMinutes(10),
        ]);
        $this->assertFalse($consumer->readyForPolicy(self::POLICY_VERSION));
    }

    public function test_fresh_patient_message_fails_closed_after_unit_transfer_and_stores_nothing(): void
    {
        [$unit, $staff] = $this->operationalUnitAndResponder();
        $this->configureMessaging($unit);
        $pool = $this->createPool($unit);
        $this->createMembership($pool, $staff);
        $consumer = $this->app->make(DatabasePatientMessageHandoffConsumer::class);
        $consumer->consumeBatch('test-transfer-worker');

        [$grant, $token] = $this->patientForEncounter($unit);
        $threadPayload = $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/threads", [
                'topic_code' => 'care_question',
                'message' => 'Route this question only to my current care team.',
                'client_message_uuid' => (string) Str::uuid7(),
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertCreated()
            ->json('data.thread');
        $consumer->consumeBatch('test-transfer-worker');

        $transferredUnit = Unit::query()->create([
            'name' => 'Transferred Test Unit',
            'abbreviation' => 'TTU',
            'type' => 'med_surg',
            'staffed_bed_count' => 8,
            'ratio_floor' => 4,
            'access_standard_minutes' => 120,
            'is_deleted' => false,
        ]);
        config([
            'hummingbird-patient.staff_messaging.pilot_unit_ids' => [
                $unit->getKey(),
                $transferredUnit->getKey(),
            ],
        ]);
        $transferredPool = $this->createPool($transferredUnit);
        $this->createMembership($transferredPool, $staff);
        Encounter::query()->findOrFail($grant->source_encounter_id)->forceFill([
            'unit_id' => $transferredUnit->getKey(),
        ])->save();

        $thread = PatientMessageThread::query()
            ->where('thread_uuid', $threadPayload['thread_uuid'])
            ->firstOrFail();
        $workItem = ThreadWorkItem::query()->where('message_thread_id', $thread->getKey())->firstOrFail();
        $factCounts = [
            PatientMessage::class => PatientMessage::query()->count(),
            PatientMessageRoutingEvent::class => PatientMessageRoutingEvent::query()->count(),
            PatientNotificationOutbox::class => PatientNotificationOutbox::query()->count(),
            StaffActionEvent::class => StaffActionEvent::query()->count(),
        ];
        $originalWorkItem = $workItem->getAttributes();

        $this->assertTrue($consumer->readyForPolicy(self::POLICY_VERSION));

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson("/api/patient/v1/threads/{$thread->thread_uuid}")
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/threads/{$thread->thread_uuid}/messages", [
                'message' => 'Do not persist this after an unresolved transfer.',
                'client_message_uuid' => (string) Str::uuid7(),
                'thread_version' => (int) $thread->version,
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'messaging_unavailable')
            ->assertJsonPath('error.message', 'Patient messaging is not available right now.')
            ->assertJsonMissing([
                'source_encounter_id',
                'unit_id',
                'responsibility_pool_id',
                'pilot_unit_ids',
            ]);

        foreach ($factCounts as $model => $count) {
            $this->assertSame($count, $model::query()->count(), "Unexpected {$model} fact persisted.");
        }
        $this->assertSame(
            $originalWorkItem,
            ThreadWorkItem::query()->findOrFail($workItem->getKey())->getAttributes(),
        );
    }

    public function test_established_pool_is_never_silently_substituted_by_an_eligible_fallback(): void
    {
        [$unit, $staff] = $this->operationalUnitAndResponder();
        $this->configureMessaging($unit);
        $unitPool = $this->createPool($unit);
        $unitMembership = $this->createMembership($unitPool, $staff);
        $consumer = $this->app->make(DatabasePatientMessageHandoffConsumer::class);
        $consumer->consumeBatch('test-continuity-worker');

        [$grant, $token] = $this->patientForEncounter($unit);
        $threadPayload = $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/threads", [
                'topic_code' => 'care_question',
                'message' => 'Keep this conversation with its accountable team.',
                'client_message_uuid' => (string) Str::uuid7(),
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertCreated()
            ->json('data.thread');
        $this->assertSame(
            ['selected' => 1, 'delivered' => 1, 'failed' => 0],
            $consumer->consumeBatch('test-continuity-worker'),
        );

        $thread = PatientMessageThread::query()
            ->where('thread_uuid', $threadPayload['thread_uuid'])
            ->firstOrFail();
        $workItem = ThreadWorkItem::query()
            ->where('message_thread_id', $thread->getKey())
            ->firstOrFail();
        $enterprisePool = $this->createPool($unit, [
            'scope_type' => 'enterprise',
            'unit_id' => null,
            'display_name' => 'Eligible Enterprise Fallback',
        ]);
        $this->createMembership($enterprisePool, $staff);

        $unitMembership->forceFill(['can_reply' => false])->save();
        $this->assertTrue($consumer->readyForPolicy(self::POLICY_VERSION));
        $factCounts = [
            PatientMessage::class => PatientMessage::query()->count(),
            PatientMessageRoutingEvent::class => PatientMessageRoutingEvent::query()->count(),
            PatientNotificationOutbox::class => PatientNotificationOutbox::query()->count(),
            StaffActionEvent::class => StaffActionEvent::query()->count(),
        ];
        $workItemBeforeRejectedFollowUp = $workItem->fresh()->getAttributes();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/threads/{$thread->thread_uuid}/messages", [
                'message' => 'Do not move this follow-up to a different pool.',
                'client_message_uuid' => (string) Str::uuid7(),
                'thread_version' => (int) $thread->version,
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'messaging_unavailable');

        foreach ($factCounts as $model => $count) {
            $this->assertSame($count, $model::query()->count(), "Unexpected {$model} fact persisted.");
        }
        $this->assertSame(
            $workItemBeforeRejectedFollowUp,
            $workItem->fresh()->getAttributes(),
        );
        $this->assertSame($unitPool->getKey(), $workItem->fresh()->responsibility_pool_id);

        // A follow-up accepted while the accountable unit is eligible must
        // still not be moved if eligibility is revoked before outbox delivery.
        $unitMembership->forceFill(['can_reply' => true])->save();
        $this->assertTrue($consumer->readyForPolicy(self::POLICY_VERSION));
        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/threads/{$thread->thread_uuid}/messages", [
                'message' => 'This accepted follow-up remains unit-accountable.',
                'client_message_uuid' => (string) Str::uuid7(),
                'thread_version' => (int) $thread->fresh()->version,
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertCreated();
        $workItemBeforeFailedDelivery = $workItem->fresh()->getAttributes();

        $unitMembership->forceFill(['can_reply' => false])->save();
        $this->assertSame(
            ['selected' => 1, 'delivered' => 0, 'failed' => 1],
            $consumer->consumeBatch('test-continuity-worker'),
        );
        $this->assertSame(
            $workItemBeforeFailedDelivery,
            $workItem->fresh()->getAttributes(),
        );
        $this->assertSame($unitPool->getKey(), $workItem->fresh()->responsibility_pool_id);
        $this->assertNotSame($enterprisePool->getKey(), $workItem->fresh()->responsibility_pool_id);
        $this->assertDatabaseHas('patient_experience.notification_delivery_attempts', [
            'status' => 'retryable_failure',
            'error_code' => 'handoff_responsibility_pool_unresolved',
        ]);
    }

    public function test_committed_create_replay_survives_a_stale_consumer_heartbeat(): void
    {
        [$unit, $staff] = $this->operationalUnitAndResponder();
        $this->configureMessaging($unit);
        $pool = $this->createPool($unit);
        $this->createMembership($pool, $staff);
        $consumer = $this->app->make(DatabasePatientMessageHandoffConsumer::class);
        $consumer->consumeBatch('test-stale-heartbeat-worker');

        [$grant, $token] = $this->patientForEncounter($unit);
        $idempotencyKey = (string) Str::uuid7();
        $payload = [
            'topic_code' => 'care_question',
            'message' => 'Recover this committed result after the worker heartbeat expires.',
            'client_message_uuid' => (string) Str::uuid7(),
            'urgent_guidance_version' => self::GUIDANCE_VERSION,
        ];
        $path = "/api/patient/v1/encounters/{$grant->encounter_uuid}/threads";
        $threadUuid = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->withToken($token)
            ->postJson($path, $payload)
            ->assertCreated()
            ->json('data.thread.thread_uuid');
        $consumer->consumeBatch('test-stale-heartbeat-worker');

        ConsumerHeartbeat::query()->whereKey('patient-message-staff-inbox-v1')->update([
            'last_seen_at' => now()->subMinutes(10),
        ]);
        $this->assertFalse($consumer->readyForPolicy(self::POLICY_VERSION));
        $ledgerCounts = [
            PatientMessageThread::query()->count(),
            PatientNotificationOutbox::query()->count(),
        ];

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->withToken($token)
            ->postJson($path, $payload)
            ->assertOk()
            ->assertJsonPath('data.thread.thread_uuid', $threadUuid)
            ->assertJsonPath('meta.idempotency_replayed', true);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->withToken($token)
            ->postJson($path, [...$payload, 'message' => 'Changed content must conflict.'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson($path, [...$payload, 'client_message_uuid' => (string) Str::uuid7()])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'messaging_unavailable');

        $this->assertSame($ledgerCounts[0], PatientMessageThread::query()->count());
        $this->assertSame($ledgerCounts[1], PatientNotificationOutbox::query()->count());
    }

    public function test_future_backoff_and_terminal_attempts_remain_unresolved_and_degrade_readiness(): void
    {
        [$unit, $staff] = $this->operationalUnitAndResponder();
        $this->configureMessaging($unit);
        $pool = $this->createPool($unit);
        $this->createMembership($pool, $staff);
        $consumer = $this->app->make(DatabasePatientMessageHandoffConsumer::class);
        $consumer->consumeBatch('test-retry-state-worker');

        $retryOutbox = $this->createUnresolvableOutbox();
        $this->assertSame(
            ['selected' => 1, 'delivered' => 0, 'failed' => 1],
            $consumer->consumeBatch('test-retry-state-worker'),
        );
        $retryAttempt = $retryOutbox->deliveryAttempts()->firstOrFail();
        $this->assertSame('retryable_failure', $retryAttempt->status);
        $this->assertTrue($retryAttempt->next_attempt_at->isFuture());

        $consumeOne = new \ReflectionMethod($consumer, 'consumeOne');
        $this->assertFalse($consumeOne->invoke(
            $consumer,
            $retryOutbox->getKey(),
            'test-stale-selection-worker',
        ));
        $this->assertDatabaseCount('patient_experience.notification_delivery_attempts', 1);

        $this->assertSame(
            ['selected' => 0, 'delivered' => 0, 'failed' => 0],
            $consumer->consumeBatch('test-retry-state-worker'),
        );
        $this->assertFalse($consumer->readyForPolicy(self::POLICY_VERSION));
        $this->assertDatabaseCount('patient_experience.notification_delivery_attempts', 1);

        $terminalOutbox = $this->createUnresolvableOutbox();
        PatientNotificationDeliveryAttempt::query()->create([
            'delivery_attempt_uuid' => (string) Str::uuid7(),
            'notification_outbox_id' => $terminalOutbox->getKey(),
            'attempt_number' => 10,
            'status' => 'terminal_failure',
            'worker_ref' => 'test-terminal-worker',
            'error_code' => 'handoff_thread_unavailable',
            'metadata' => [
                'schema_version' => 1,
                'content_included' => false,
                'destination' => 'staff_inbox',
            ],
            'occurred_at' => now(),
        ]);

        $this->assertSame(
            ['selected' => 0, 'delivered' => 0, 'failed' => 0],
            $consumer->consumeBatch('test-retry-state-worker'),
        );
        $this->assertFalse($consumer->readyForPolicy(self::POLICY_VERSION));
        $this->assertSame(
            'degraded',
            ConsumerHeartbeat::query()->findOrFail('patient-message-staff-inbox-v1')->status,
        );
    }

    public function test_stale_duplicate_failure_recording_observes_the_first_workers_future_backoff(): void
    {
        $consumer = $this->app->make(DatabasePatientMessageHandoffConsumer::class);
        $outbox = $this->createUnresolvableOutbox();
        $recordFailure = new \ReflectionMethod($consumer, 'recordFailure');
        $failure = new \RuntimeException('handoff_thread_unavailable');

        $recordFailure->invoke($consumer, $outbox->getKey(), 'first-test-worker', $failure);
        $recordFailure->invoke($consumer, $outbox->getKey(), 'stale-second-test-worker', $failure);

        $attempts = $outbox->deliveryAttempts()->orderBy('attempt_number')->get();
        $this->assertCount(1, $attempts);
        $this->assertSame(1, $attempts->first()->attempt_number);
        $this->assertSame('retryable_failure', $attempts->first()->status);
        $this->assertSame('handoff_thread_unavailable', $attempts->first()->error_code);
        $this->assertTrue($attempts->first()->next_attempt_at->isFuture());
        $this->assertSame('first-test-worker', $attempts->first()->worker_ref);
    }

    public function test_post_batch_heartbeat_stays_degraded_until_every_unexpired_outbox_is_resolved(): void
    {
        [$unit, $staff] = $this->operationalUnitAndResponder();
        $this->configureMessaging($unit);
        $pool = $this->createPool($unit);
        $this->createMembership($pool, $staff);
        $consumer = $this->app->make(DatabasePatientMessageHandoffConsumer::class);
        $consumer->consumeBatch('test-aggregate-worker');

        [$grant, $token] = $this->patientForEncounter($unit);
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/threads", [
                'topic_code' => 'care_question',
                'message' => 'Create one valid handoff.',
                'client_message_uuid' => (string) Str::uuid7(),
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertCreated();

        $original = PatientNotificationOutbox::query()->firstOrFail();
        PatientNotificationOutbox::query()->create([
            'principal_id' => $original->principal_id,
            'access_grant_id' => $original->access_grant_id,
            'aggregate_type' => $original->aggregate_type,
            'aggregate_uuid' => $original->aggregate_uuid,
            'event_type' => 'patient.messaging.message_submitted',
            'destination' => 'staff_inbox',
            'routing_metadata' => $original->routing_metadata,
            'idempotency_key_digest' => hash('sha256', (string) Str::uuid7()),
            'available_at' => now(),
            'occurred_at' => now(),
        ]);

        $this->assertSame(
            ['selected' => 1, 'delivered' => 1, 'failed' => 0],
            $consumer->consumeBatch('test-aggregate-worker', 1),
        );
        $this->assertFalse($consumer->readyForPolicy(self::POLICY_VERSION));
        $this->assertSame(
            'degraded',
            ConsumerHeartbeat::query()->findOrFail('patient-message-staff-inbox-v1')->status,
        );

        $this->assertSame(
            ['selected' => 1, 'delivered' => 1, 'failed' => 0],
            $consumer->consumeBatch('test-aggregate-worker', 1),
        );
        $this->assertTrue($consumer->readyForPolicy(self::POLICY_VERSION));
        $this->assertSame(
            'ready',
            ConsumerHeartbeat::query()->findOrFail('patient-message-staff-inbox-v1')->status,
        );
    }

    public function test_content_free_handoff_is_exactly_once_and_projects_accountable_pool_ownership(): void
    {
        [$unit, $staff] = $this->operationalUnitAndResponder();
        $this->configureMessaging($unit);
        $pool = $this->createPool($unit);
        $this->createMembership($pool, $staff);
        $consumer = $this->app->make(DatabasePatientMessageHandoffConsumer::class);
        $consumer->consumeBatch('test-handoff-worker');

        [$grant, $token] = $this->patientForEncounter($unit);
        $threadPayload = $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/threads", [
                'topic_code' => 'care_question',
                'message' => 'I have a non-urgent question for the care team.',
                'client_message_uuid' => (string) Str::uuid7(),
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertCreated()
            ->json('data.thread');

        $this->assertSame(
            ['selected' => 1, 'delivered' => 1, 'failed' => 0],
            $consumer->consumeBatch('test-handoff-worker'),
        );
        $this->assertSame(
            ['selected' => 0, 'delivered' => 0, 'failed' => 0],
            $consumer->consumeBatch('test-handoff-worker'),
        );

        $thread = PatientMessageThread::query()
            ->where('thread_uuid', $threadPayload['thread_uuid'])
            ->firstOrFail();
        $workItem = ThreadWorkItem::query()->where('message_thread_id', $thread->getKey())->firstOrFail();

        $this->assertSame('assigned', $thread->ownership_state);
        $this->assertSame((int) $thread->version, (int) $workItem->source_thread_version);
        $this->assertSame('pool_owned', $workItem->ownership_state);
        $this->assertSame($pool->getKey(), $workItem->responsibility_pool_id);
        $this->assertNull($workItem->assigned_user_id);
        $this->assertDatabaseCount('patient_communications.thread_work_items', 1);
        $this->assertDatabaseCount('patient_communications.staff_action_events', 1);
        $this->assertDatabaseHas('patient_experience.notification_delivery_attempts', [
            'status' => 'delivered',
        ]);
        $this->assertDatabaseHas('patient_experience.message_delivery_receipts', [
            'receipt_type' => 'assigned',
            'patient_visible_state' => 'assigned',
        ]);
        $this->assertDatabaseHas('patient_experience.message_routing_events', [
            'event_type' => 'assigned',
            'patient_visible_state' => 'assigned',
        ]);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson("/api/patient/v1/threads/{$thread->thread_uuid}")
            ->assertOk()
            ->assertJsonPath('data.thread.ownership_state', 'assigned')
            ->assertJsonPath('data.thread.messages.0.delivery_state', 'assigned')
            ->assertJsonMissing([
                'responsibility_pool_ref_digest',
                'pool_uuid',
                'staff_user_id',
                'worker_ref',
            ]);
    }

    public function test_historical_policy_outbox_remains_resolvable_after_current_policy_changes(): void
    {
        [$unit, $staff] = $this->operationalUnitAndResponder();
        $this->configureMessaging($unit);
        $pool = $this->createPool($unit);
        $this->createMembership($pool, $staff);
        $consumer = $this->app->make(DatabasePatientMessageHandoffConsumer::class);
        $consumer->consumeBatch('test-history-worker');

        [$grant, $token] = $this->patientForEncounter($unit);
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/threads", [
                'topic_code' => 'care_question',
                'message' => 'Please preserve the historical routing mapping.',
                'client_message_uuid' => (string) Str::uuid7(),
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertCreated();

        config([
            'hummingbird-patient.messaging.policy_version' => 'test-staff-handoff-policy-v2',
        ]);

        $this->assertSame(
            ['selected' => 1, 'delivered' => 1, 'failed' => 0],
            $consumer->consumeBatch('test-history-worker'),
        );
        $this->assertDatabaseCount('patient_communications.thread_work_items', 1);
        $this->assertFalse($consumer->readyForPolicy('test-staff-handoff-policy-v2'));
        $this->assertSame(
            'degraded',
            ConsumerHeartbeat::query()->findOrFail('patient-message-staff-inbox-v1')->status,
        );
    }

    public function test_unresolvable_outbox_retries_without_creating_a_phantom_work_item(): void
    {
        [$unit, $staff] = $this->operationalUnitAndResponder();
        $this->configureMessaging($unit);
        $pool = $this->createPool($unit);
        $this->createMembership($pool, $staff);
        $consumer = $this->app->make(DatabasePatientMessageHandoffConsumer::class);
        $consumer->consumeBatch('test-failure-worker');

        PatientNotificationOutbox::query()->create([
            'outbox_uuid' => (string) Str::uuid7(),
            'aggregate_type' => 'patient_message_thread',
            'aggregate_uuid' => (string) Str::uuid7(),
            'event_type' => 'patient.messaging.message_submitted',
            'destination' => 'staff_inbox',
            'routing_metadata' => [
                'schema_version' => 1,
                'content_included' => false,
                'routing_policy_version' => self::POLICY_VERSION,
                'responsibility_pool_ref_digest' => str_repeat('a', 64),
            ],
            'idempotency_key_digest' => hash('sha256', (string) Str::uuid7()),
            'available_at' => now(),
            'occurred_at' => now(),
        ]);

        $this->assertSame(
            ['selected' => 1, 'delivered' => 0, 'failed' => 1],
            $consumer->consumeBatch('test-failure-worker'),
        );
        $this->assertDatabaseCount('patient_communications.thread_work_items', 0);
        $this->assertDatabaseHas('patient_experience.notification_delivery_attempts', [
            'status' => 'retryable_failure',
            'error_code' => 'handoff_thread_unavailable',
        ]);
        $this->assertSame(
            'degraded',
            ConsumerHeartbeat::query()->findOrFail('patient-message-staff-inbox-v1')->status,
        );
        $this->assertFalse($consumer->readyForPolicy(self::POLICY_VERSION));

        $this->assertSame(
            ['selected' => 0, 'delivered' => 0, 'failed' => 0],
            $consumer->consumeBatch('test-failure-worker'),
        );
        $this->assertDatabaseCount('patient_experience.notification_delivery_attempts', 1);
        $this->assertSame(
            'degraded',
            ConsumerHeartbeat::query()->findOrFail('patient-message-staff-inbox-v1')->status,
        );
    }

    private function createUnresolvableOutbox(): PatientNotificationOutbox
    {
        return PatientNotificationOutbox::query()->create([
            'outbox_uuid' => (string) Str::uuid7(),
            'aggregate_type' => 'patient_message_thread',
            'aggregate_uuid' => (string) Str::uuid7(),
            'event_type' => 'patient.messaging.message_submitted',
            'destination' => 'staff_inbox',
            'routing_metadata' => [
                'schema_version' => 1,
                'content_included' => false,
                'routing_policy_version' => self::POLICY_VERSION,
                'responsibility_pool_ref_digest' => str_repeat('a', 64),
            ],
            'idempotency_key_digest' => hash('sha256', (string) Str::uuid7()),
            'available_at' => now(),
            'occurred_at' => now(),
        ]);
    }

    /** @return array{0: Unit, 1: User} */
    private function operationalUnitAndResponder(): array
    {
        $unit = Unit::query()->create([
            'name' => 'Handoff Test Unit',
            'abbreviation' => 'HTU',
            'type' => 'med_surg',
            'staffed_bed_count' => 12,
            'ratio_floor' => 4,
            'access_standard_minutes' => 120,
            'is_deleted' => false,
        ]);
        $staff = User::factory()->create([
            'role' => 'charge_nurse',
            'is_active' => true,
        ]);

        return [$unit, $staff];
    }

    private function configureMessaging(Unit $unit): void
    {
        config([
            'hummingbird-patient.enabled' => true,
            'hummingbird-patient.features.messaging' => true,
            'hummingbird-patient.messaging' => [
                'governance_status' => 'approved',
                'policy_version' => self::POLICY_VERSION,
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
                'urgent_guidance_text' => 'For immediate help, use the approved bedside or emergency route.',
                'default_response_window' => 'The test care team usually responds within one test hour.',
                'encryption_key_version' => 'test-encryption-key-v1',
                'handoff_consumer' => DatabasePatientMessageHandoffConsumer::class,
                'topics' => [
                    'care_question' => [
                        'label' => 'Question for my care team',
                        'description' => 'Ask a non-urgent question about your current hospital care.',
                        'responsibility_pool_key' => self::POOL_KEY,
                    ],
                ],
            ],
            'hummingbird-patient.staff_messaging' => [
                'enabled' => true,
                'governance_status' => 'approved',
                'consumer_key' => 'patient-message-staff-inbox-v1',
                'pilot_unit_ids' => [$unit->getKey()],
                'heartbeat_ttl_seconds' => 120,
                'batch_size' => 100,
            ],
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createPool(Unit $unit, array $overrides = []): ResponsibilityPool
    {
        $digest = $this->app->make(PatientHmac::class)->digest(
            'messaging-pool-ref',
            self::POLICY_VERSION.'|'.self::POOL_KEY,
        );

        return ResponsibilityPool::query()->create([
            'pool_uuid' => (string) Str::uuid7(),
            'pool_key_digest' => $digest,
            'topic_code' => 'care_question',
            'display_name' => 'Handoff Test Care Team',
            'routing_policy_version' => self::POLICY_VERSION,
            'scope_type' => 'unit',
            'unit_id' => $unit->getKey(),
            'status' => 'active',
            'response_target_minutes' => 30,
            'escalation_target_minutes' => 60,
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createMembership(
        ResponsibilityPool $pool,
        User $staff,
        array $overrides = [],
    ): PoolMembership {
        return PoolMembership::query()->create([
            'membership_uuid' => (string) Str::uuid7(),
            'responsibility_pool_id' => $pool->getKey(),
            'staff_user_id' => $staff->getKey(),
            'membership_role' => 'supervisor',
            'availability_state' => 'active',
            'can_claim' => true,
            'can_reply' => true,
            'can_reroute' => true,
            'can_close' => true,
            'effective_from' => now()->subMinute(),
            ...$overrides,
        ]);
    }

    /** @return array{0: PatientEncounterAccessGrant, 1: string} */
    private function patientForEncounter(Unit $unit): array
    {
        $encounter = Encounter::query()->create([
            'patient_ref' => 'handoff-test-'.Str::lower(Str::random(12)),
            'unit_id' => $unit->getKey(),
            'admitted_at' => now()->subDay(),
            'acuity_tier' => 2,
            'status' => 'active',
            'is_deleted' => false,
        ]);
        $principal = PatientPrincipal::query()->create([
            'principal_uuid' => (string) Str::uuid7(),
            'principal_type' => 'patient',
            'display_name' => 'Staff Handoff Test Patient',
            'email' => 'handoff+'.Str::lower(Str::random(10)).'@example.test',
            'password' => Hash::make('NotARealPatient1!'),
            'status' => 'active',
            'is_active' => true,
            'locale' => 'en-US',
            'timezone' => 'America/New_York',
        ]);
        $grant = PatientEncounterAccessGrant::query()->create([
            'grant_uuid' => (string) Str::uuid7(),
            'principal_id' => $principal->getKey(),
            'encounter_uuid' => (string) Str::uuid7(),
            'source_encounter_id' => $encounter->getKey(),
            'source_encounter_ref_digest' => hash('sha256', (string) Str::uuid7()),
            'source_system_key' => 'test-ehr',
            'relationship' => 'self',
            'scopes' => ['messaging:read', 'messaging:write'],
            'purpose_of_use' => 'treatment',
            'status' => 'active',
            'valid_from' => now()->subMinute(),
            'grant_reason' => 'Automated staff handoff test.',
            'version' => 1,
        ]);
        $sessionUuid = (string) Str::uuid7();
        PatientSession::query()->create([
            'session_uuid' => $sessionUuid,
            'principal_id' => $principal->getKey(),
            'auth_method' => 'password',
            'status' => 'active',
            'last_authenticated_at' => now(),
            'last_seen_at' => now(),
            'expires_at' => now()->addDay(),
            'idle_expires_at' => now()->addDay(),
        ]);

        return [
            $grant,
            $principal->createToken('patient-access:'.$sessionUuid, ['patient:access'])->plainTextToken,
        ];
    }
}
