<?php

namespace Tests\Feature\Patient;

use App\Contracts\Patient\PatientMessageHandoffReadiness;
use App\Models\Encounter;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientMessage;
use App\Models\Patient\PatientMessageThread;
use App\Models\Patient\PatientNotificationOutbox;
use App\Models\Patient\PatientPrincipal;
use App\Models\Patient\PatientSession;
use App\Models\PatientCommunication\PoolMembership;
use App\Models\PatientCommunication\ResponsibilityPool;
use App\Models\PatientCommunication\ThreadWorkItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\Patient\Messaging\PatientMessageCipher;
use App\Services\Patient\PatientHmac;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class PatientMessagingApiTest extends TestCase
{
    use RefreshDatabase;

    private const GUIDANCE_VERSION = 'test-urgent-guidance-v1';

    private const TEST_GUIDANCE = 'Automated-test guidance only: use the approved bedside or emergency route for urgent help.';

    public function test_messaging_schema_has_immutable_content_and_event_guards(): void
    {
        foreach ([
            'patient_experience.message_threads',
            'patient_experience.messages',
            'patient_experience.message_delivery_receipts',
            'patient_experience.message_routing_events',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing patient messaging table {$table}.");
        }

        foreach ([
            'thread_uuid',
            'access_grant_id',
            'topic_code',
            'topic_label',
            'ownership_state',
            'routing_policy_version',
            'urgent_guidance_version',
            'responsibility_pool_ref_digest',
            'version',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('patient_experience.message_threads', $column),
                "Missing message-thread column {$column}.",
            );
        }

        $triggers = collect(DB::select(<<<'SQL'
SELECT trigger_name
FROM information_schema.triggers
WHERE trigger_schema = 'patient_experience'
  AND event_object_table IN ('messages', 'message_delivery_receipts', 'message_routing_events')
SQL))->pluck('trigger_name')->unique()->sort()->values()->all();

        $this->assertSame([
            'patient_message_delivery_receipts_append_only',
            'patient_message_routing_events_append_only',
            'patient_messages_append_only',
            'patient_messages_relationship_scope',
        ], $triggers);
    }

    public function test_approved_topics_are_encounter_scoped_and_hide_routing_metadata(): void
    {
        $this->enableMessaging();
        [$principal, $grant, $token] = $this->patientWithMessagingGrant();

        $response = $this->withToken($token)
            ->getJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/message-topics")
            ->assertOk()
            ->assertJsonPath('data.topics.0.code', 'care_question')
            ->assertJsonPath('data.topics.0.label', 'Question for my care team')
            ->assertJsonPath('data.immediate_help.version', self::GUIDANCE_VERSION)
            ->assertJsonPath('meta.policy_version', 'test-messaging-policy-v1')
            ->assertJsonMissing(['responsibility_pool_key'])
            ->assertJsonMissing(['responsibility_pool_ref_digest'])
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

        $this->assertStringNotContainsString('test.unit.care-team', $response->getContent());
        $this->assertDatabaseHas('patient_experience.access_audit_events', [
            'principal_id' => $principal->getKey(),
            'access_grant_id' => $grant->getKey(),
            'event_type' => 'patient.messaging.topics_viewed',
            'outcome' => 'succeeded',
        ]);
    }

    public function test_patient_care_preference_is_an_encrypted_routed_message_not_a_care_plan_mutation(): void
    {
        $this->enableMessaging();
        [, $grant, $token] = $this->patientWithMessagingGrant();
        $body = 'Being able to understand each next step before it happens matters to me.';
        config([
            'hummingbird-patient.messaging.topics.care_preference' => [
                'label' => 'What matters to you',
                'description' => 'Share a non-urgent preference for this hospital stay. Your care team can review it, but it does not change your care plan or create a clinical order on its own.',
                'responsibility_pool_key' => 'test.unit.care-team',
            ],
        ]);

        $this->withToken($token)
            ->getJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/message-topics")
            ->assertOk()
            ->assertJsonPath('data.topics.1.code', 'care_preference')
            ->assertJsonPath('data.topics.1.label', 'What matters to you')
            ->assertJsonPath(
                'data.topics.1.description',
                'Share a non-urgent preference for this hospital stay. Your care team can review it, but it does not change your care plan or create a clinical order on its own.',
            )
            ->assertJsonMissing(['responsibility_pool_key'])
            ->assertJsonMissing(['care_plan'])
            ->assertJsonMissing(['clinical_order']);

        $this->app['auth']->forgetGuards();
        $thread = $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/threads", [
                'topic_code' => 'care_preference',
                'message' => $body,
                'client_message_uuid' => (string) Str::uuid7(),
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertCreated()
            ->assertJsonPath('data.thread.topic.code', 'care_preference')
            ->assertJsonPath('data.thread.topic.label', 'What matters to you')
            ->assertJsonMissing(['message'])
            ->assertJsonMissing(['care_plan'])
            ->assertJsonMissing(['clinical_order'])
            ->json('data.thread');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson("/api/patient/v1/threads/{$thread['thread_uuid']}")
            ->assertOk()
            ->assertJsonPath('data.thread.messages.0.body', $body)
            ->assertJsonMissing(['care_plan'])
            ->assertJsonMissing(['clinical_order']);

        $storedMessage = PatientMessage::query()->firstOrFail();
        $this->assertStringNotContainsString($body, (string) $storedMessage->encrypted_body);
        $this->assertSame('care_preference', $thread['topic']['code']);
        $this->assertDatabaseCount('patient_experience.message_threads', 1);
        $this->assertDatabaseCount('patient_experience.messages', 1);
        $this->assertDatabaseHas('patient_experience.notification_outbox', [
            'aggregate_uuid' => $thread['thread_uuid'],
            'event_type' => 'patient.messaging.thread_opened',
            'destination' => 'staff_inbox',
            'encrypted_payload' => null,
            'payload_digest' => null,
        ]);
    }

    public function test_feature_flag_is_not_enough_without_an_approved_local_policy(): void
    {
        $this->enableMessaging();
        config(['hummingbird-patient.messaging.governance_status' => 'draft_requires_approval']);
        [, $grant, $token] = $this->patientWithMessagingGrant();

        $this->withToken($token)
            ->getJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/message-topics")
            ->assertStatus(503)
            ->assertJsonPath('data', null)
            ->assertJsonPath('error.code', 'messaging_unavailable')
            ->assertJsonPath('error.message', 'Patient messaging is not available right now.')
            ->assertJsonMissing(['governance_status'])
            ->assertJsonMissing(['exception']);

        $this->assertDatabaseCount('patient_experience.message_threads', 0);
        $this->assertDatabaseCount('patient_experience.messages', 0);
    }

    public function test_rounds_question_topic_requires_its_own_patient_composition_flag(): void
    {
        $this->enableMessaging();
        [, $grant, $token] = $this->patientWithMessagingGrant();
        config([
            'hummingbird-patient.messaging.topics.rounds_question' => [
                'label' => 'Question for care-team rounds',
                'description' => 'Share a non-urgent question your care team may review before a care conversation.',
                'responsibility_pool_key' => 'test.unit.care-team',
            ],
            'hummingbird-patient.features.rounds_questions' => false,
        ]);

        $this->withToken($token)
            ->getJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/message-topics")
            ->assertOk()
            ->assertJsonCount(1, 'data.topics')
            ->assertJsonMissingPath('data.topics.1');

        config(['hummingbird-patient.features.rounds_questions' => true]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/message-topics")
            ->assertOk()
            ->assertJsonCount(2, 'data.topics')
            ->assertJsonPath('data.topics.1.code', 'rounds_question')
            ->assertJsonPath('data.topics.1.label', 'Question for care-team rounds');

        // A visible topic never bypasses handoff readiness. The test consumer
        // has no approved rounds route, so the message is not accepted.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/threads", [
                'topic_code' => 'rounds_question',
                'message' => 'Please discuss what I should prepare for after today.',
                'client_message_uuid' => (string) Str::uuid7(),
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'messaging_unavailable');
        $this->assertDatabaseCount('patient_experience.message_threads', 0);
    }

    public function test_every_required_disclosure_policy_value_fails_closed_when_missing(): void
    {
        $this->enableMessaging();
        [, $grant, $token] = $this->patientWithMessagingGrant();

        foreach ([
            'policy_version',
            'urgent_guidance_version',
            'urgent_guidance_text',
            'default_response_window',
            'topics',
        ] as $requiredKey) {
            $this->enableMessaging();
            config(["hummingbird-patient.messaging.{$requiredKey}" => null]);
            $this->app['auth']->forgetGuards();

            $this->withToken($token)
                ->getJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/message-topics")
                ->assertStatus(503)
                ->assertJsonPath('error.code', 'messaging_unavailable');
        }

        $this->assertDatabaseCount('patient_experience.message_threads', 0);
    }

    public function test_disclosures_survive_handoff_outage_while_every_mutation_fails_closed(): void
    {
        $this->enableMessaging();
        [, $grant, $token] = $this->patientWithMessagingGrant();
        $thread = $this->createThread($grant, $token);
        config([
            'hummingbird-patient.messaging.handoff_consumer' => UnreadyPatientMessageHandoff::class,
        ]);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/message-topics")
            ->assertOk()
            ->assertJsonPath('data.immediate_help.version', self::GUIDANCE_VERSION);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/threads")
            ->assertOk()
            ->assertJsonCount(1, 'data.threads')
            ->assertJsonPath('data.immediate_help.version', self::GUIDANCE_VERSION);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson("/api/patient/v1/threads/{$thread['thread_uuid']}")
            ->assertOk()
            ->assertJsonPath('data.thread.messages.0.body', 'I have a non-urgent question for my care team.')
            ->assertJsonPath('data.immediate_help.version', self::GUIDANCE_VERSION);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/threads", [
                'topic_code' => 'care_question',
                'message' => 'This new thread must not be accepted.',
                'client_message_uuid' => (string) Str::uuid7(),
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'messaging_unavailable');

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/threads/{$thread['thread_uuid']}/messages", [
                'message' => 'This follow-up must not be accepted.',
                'client_message_uuid' => (string) Str::uuid7(),
                'thread_version' => 1,
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'messaging_unavailable');

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/threads/{$thread['thread_uuid']}/close", [
                'thread_version' => 1,
                'close_reason' => 'no_longer_needed',
            ])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'messaging_unavailable');

        $this->assertDatabaseCount('patient_experience.message_threads', 1);
        $this->assertDatabaseCount('patient_experience.messages', 1);
    }

    public function test_exact_create_send_and_close_replays_survive_handoff_outage_but_changed_payloads_conflict(): void
    {
        $this->enableMessaging();
        [, $grant, $token] = $this->patientWithMessagingGrant();

        $createKey = (string) Str::uuid7();
        $createPayload = [
            'topic_code' => 'care_question',
            'message' => 'Please preserve this exact create result.',
            'client_message_uuid' => (string) Str::uuid7(),
            'urgent_guidance_version' => self::GUIDANCE_VERSION,
        ];
        $createPath = "/api/patient/v1/encounters/{$grant->encounter_uuid}/threads";
        $thread = $this->withHeader('Idempotency-Key', $createKey)
            ->withToken($token)
            ->postJson($createPath, $createPayload)
            ->assertCreated()
            ->json('data.thread');
        $this->projectAccountableWorkItem($grant, $thread);

        $sendKey = (string) Str::uuid7();
        $sendPayload = [
            'message' => 'Please preserve this exact send result.',
            'client_message_uuid' => (string) Str::uuid7(),
            'thread_version' => 1,
            'urgent_guidance_version' => self::GUIDANCE_VERSION,
        ];
        $sendPath = "/api/patient/v1/threads/{$thread['thread_uuid']}/messages";
        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', $sendKey)
            ->withToken($token)
            ->postJson($sendPath, $sendPayload)
            ->assertCreated();

        $closeKey = (string) Str::uuid7();
        $closePayload = ['thread_version' => 2, 'close_reason' => 'no_longer_needed'];
        $closePath = "/api/patient/v1/threads/{$thread['thread_uuid']}/close";
        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', $closeKey)
            ->withToken($token)
            ->postJson($closePath, $closePayload)
            ->assertOk();

        $ledgerCounts = [
            'threads' => PatientMessageThread::query()->count(),
            'messages' => PatientMessage::query()->count(),
            'outbox' => PatientNotificationOutbox::query()->count(),
        ];
        config(['hummingbird-patient.messaging.handoff_consumer' => UnreadyPatientMessageHandoff::class]);

        foreach ([
            [$createPath, $createKey, $createPayload],
            [$sendPath, $sendKey, $sendPayload],
            [$closePath, $closeKey, $closePayload],
        ] as [$path, $key, $payload]) {
            $this->app['auth']->forgetGuards();
            $this->withHeader('Idempotency-Key', $key)
                ->withToken($token)
                ->postJson($path, $payload)
                ->assertOk()
                ->assertJsonPath('meta.idempotency_replayed', true);
        }

        foreach ([
            [$createPath, $createKey, [...$createPayload, 'message' => 'Changed create content.']],
            [$sendPath, $sendKey, [...$sendPayload, 'message' => 'Changed send content.']],
            [$closePath, $closeKey, [...$closePayload, 'close_reason' => 'created_in_error']],
        ] as [$path, $key, $payload]) {
            $this->app['auth']->forgetGuards();
            $this->withHeader('Idempotency-Key', $key)
                ->withToken($token)
                ->postJson($path, $payload)
                ->assertConflict()
                ->assertJsonPath('error.code', 'idempotency_conflict')
                ->assertJsonMissing(['exception', 'consumer_key', 'worker_ref']);
        }

        $this->assertSame($ledgerCounts['threads'], PatientMessageThread::query()->count());
        $this->assertSame($ledgerCounts['messages'], PatientMessage::query()->count());
        $this->assertSame($ledgerCounts['outbox'], PatientNotificationOutbox::query()->count());
    }

    public function test_exact_create_replay_survives_topic_retirement_while_a_fresh_unknown_topic_stays_hidden(): void
    {
        $this->enableMessaging();
        [, $grant, $token] = $this->patientWithMessagingGrant();
        $idempotencyKey = (string) Str::uuid7();
        $payload = [
            'topic_code' => 'care_question',
            'message' => 'Preserve this result if the original topic is later retired.',
            'client_message_uuid' => (string) Str::uuid7(),
            'urgent_guidance_version' => self::GUIDANCE_VERSION,
        ];
        $path = "/api/patient/v1/encounters/{$grant->encounter_uuid}/threads";
        $threadUuid = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->withToken($token)
            ->postJson($path, $payload)
            ->assertCreated()
            ->json('data.thread.thread_uuid');
        $factCounts = [
            PatientMessageThread::query()->count(),
            PatientMessage::query()->count(),
            PatientNotificationOutbox::query()->count(),
        ];

        config([
            'hummingbird-patient.messaging.topics' => [
                'different_question' => [
                    'label' => 'Different approved question',
                    'description' => 'A replacement topic used only by this replay regression test.',
                    'responsibility_pool_key' => 'test.unit.different-care-team',
                ],
            ],
        ]);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->withToken($token)
            ->postJson($path, $payload)
            ->assertOk()
            ->assertJsonPath('data.thread.thread_uuid', $threadUuid)
            ->assertJsonPath('meta.idempotency_replayed', true);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson($path, [...$payload, 'client_message_uuid' => (string) Str::uuid7()])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonPath('error.message', 'The requested resource was not found.')
            ->assertJsonMissing([
                'topic_code',
                'responsibility_pool_key',
                'routing_policy_version',
            ]);

        $this->assertSame($factCounts[0], PatientMessageThread::query()->count());
        $this->assertSame($factCounts[1], PatientMessage::query()->count());
        $this->assertSame($factCounts[2], PatientNotificationOutbox::query()->count());
    }

    public function test_encounter_lifecycle_failures_are_generic_audited_and_store_no_new_content(): void
    {
        $this->enableMessaging();

        $cases = [];
        foreach (['inactive', 'inconsistent_discharge', 'deleted', 'missing'] as $state) {
            [$principal, $grant, $token] = $this->patientWithMessagingGrant();
            $this->app['auth']->forgetGuards();
            $thread = $this->createThread($grant, $token);
            $encounter = Encounter::query()->findOrFail($grant->source_encounter_id);

            match ($state) {
                'inactive' => $encounter->forceFill([
                    'status' => 'discharged',
                    'discharged_at' => now(),
                ])->save(),
                'inconsistent_discharge' => $encounter->forceFill([
                    'status' => 'active',
                    'discharged_at' => now(),
                ])->save(),
                'deleted' => $encounter->forceFill(['is_deleted' => true])->save(),
                'missing' => $encounter->delete(),
            };

            $cases[] = [$principal, $grant, $token, $thread, $state];
        }

        $messageCount = PatientMessage::query()->count();
        $outboxCount = PatientNotificationOutbox::query()->count();

        foreach ($cases as [$principal, $grant, $token, $thread, $state]) {
            foreach ([
                "/api/patient/v1/encounters/{$grant->encounter_uuid}/message-topics",
                "/api/patient/v1/encounters/{$grant->encounter_uuid}/threads",
                "/api/patient/v1/threads/{$thread['thread_uuid']}",
            ] as $path) {
                $this->app['auth']->forgetGuards();
                $response = $this->withToken($token)
                    ->getJson($path)
                    ->assertNotFound()
                    ->assertJsonPath('error.code', 'not_found')
                    ->assertJsonPath('error.message', 'The requested resource was not found.')
                    ->assertJsonMissing([
                        'source_encounter_id',
                        'unit_id',
                        'is_deleted',
                        'discharged_at',
                    ]);

                $this->assertStringNotContainsString($state, $response->getContent());
            }

            $this->assertSame(
                3,
                DB::table('patient_experience.access_audit_events')
                    ->where('principal_id', $principal->getKey())
                    ->where('event_type', 'patient.messaging.access_denied')
                    ->where('outcome', 'denied')
                    ->where('reason_code', 'resource_unavailable')
                    ->count(),
            );
        }

        [$inactivePrincipal, $inactiveGrant, $inactiveToken, $inactiveThread] = $cases[0];
        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($inactiveToken)
            ->postJson("/api/patient/v1/threads/{$inactiveThread['thread_uuid']}/messages", [
                'message' => 'This message must not survive encounter discharge.',
                'client_message_uuid' => (string) Str::uuid7(),
                'thread_version' => 1,
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'messaging_unavailable')
            ->assertJsonMissing([
                'source_encounter_id',
                'unit_id',
                'is_deleted',
                'discharged_at',
            ]);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($inactiveToken)
            ->postJson("/api/patient/v1/encounters/{$inactiveGrant->encounter_uuid}/threads", [
                'topic_code' => 'care_question',
                'message' => 'This new thread must not survive encounter discharge.',
                'client_message_uuid' => (string) Str::uuid7(),
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'messaging_unavailable')
            ->assertJsonMissing([
                'source_encounter_id',
                'unit_id',
                'is_deleted',
                'discharged_at',
            ]);

        $this->assertSame($messageCount, PatientMessage::query()->count());
        $this->assertSame($outboxCount, PatientNotificationOutbox::query()->count());
        $this->assertSame('active', $inactivePrincipal->status);
        $this->assertSame('active', $inactiveGrant->status);
    }

    public function test_fresh_follow_up_requires_an_established_accountable_work_item_and_stores_zero_facts(): void
    {
        $this->enableMessaging();
        [, $grant, $token] = $this->patientWithMessagingGrant();
        $thread = $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/threads", [
                'topic_code' => 'care_question',
                'message' => 'Wait for accountable routing before accepting a follow-up.',
                'client_message_uuid' => (string) Str::uuid7(),
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertCreated()
            ->json('data.thread');

        $factCounts = collect([
            'patient_experience.messages',
            'patient_experience.message_delivery_receipts',
            'patient_experience.message_routing_events',
            'patient_experience.notification_outbox',
            'patient_experience.access_audit_events',
        ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()]);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/threads/{$thread['thread_uuid']}/messages", [
                'message' => 'This follow-up must not resurrect an unrouted thread.',
                'client_message_uuid' => (string) Str::uuid7(),
                'thread_version' => 1,
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'messaging_unavailable')
            ->assertJsonMissing([
                'work_item_uuid',
                'responsibility_pool_id',
                'unit_id',
            ]);

        $factCounts->each(function (int $count, string $table): void {
            $this->assertSame($count, DB::table($table)->count(), "Unexpected fact persisted in {$table}.");
        });
        $this->assertDatabaseCount('patient_communications.thread_work_items', 0);
    }

    public function test_existing_disclosures_use_retained_keys_when_the_current_write_key_is_unavailable(): void
    {
        $this->enableMessaging();
        $versionOneKey = 'base64:'.base64_encode(str_repeat('a', 32));
        config([
            'hummingbird-patient.hmac_secret' => str_repeat('h', 32),
            'hummingbird-patient.messaging.encryption_key_version' => 'message-key-v1',
            'hummingbird-patient.messaging.encryption_key' => $versionOneKey,
        ]);
        [, $grant, $token] = $this->patientWithMessagingGrant();
        $thread = $this->createThread($grant, $token);
        config([
            'hummingbird-patient.messaging.encryption_key_version' => 'message-key-v2',
            'hummingbird-patient.messaging.encryption_key' => null,
            'hummingbird-patient.messaging.previous_encryption_keys_json' => json_encode([
                'message-key-v1' => $versionOneKey,
            ], JSON_THROW_ON_ERROR),
        ]);
        $originalEnvironment = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->app['auth']->forgetGuards();
            $this->withToken($token)
                ->getJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/message-topics")
                ->assertOk()
                ->assertJsonPath('data.immediate_help.version', self::GUIDANCE_VERSION);

            $this->app['auth']->forgetGuards();
            $this->withToken($token)
                ->getJson("/api/patient/v1/threads/{$thread['thread_uuid']}")
                ->assertOk()
                ->assertJsonPath('data.thread.messages.0.body', 'I have a non-urgent question for my care team.');

            $this->app['auth']->forgetGuards();
            $this->withHeader('Idempotency-Key', (string) Str::uuid7())
                ->withToken($token)
                ->postJson("/api/patient/v1/threads/{$thread['thread_uuid']}/messages", [
                    'message' => 'This must wait for the new write key.',
                    'client_message_uuid' => (string) Str::uuid7(),
                    'thread_version' => 1,
                    'urgent_guidance_version' => self::GUIDANCE_VERSION,
                ])
                ->assertStatus(503)
                ->assertJsonPath('error.code', 'messaging_unavailable');
        } finally {
            $this->app['env'] = $originalEnvironment;
        }

        $this->assertDatabaseCount('patient_experience.message_threads', 1);
        $this->assertDatabaseCount('patient_experience.messages', 1);
    }

    public function test_mutations_require_uuid_replay_keys_and_the_current_guidance_version(): void
    {
        $this->enableMessaging();
        [, $grant, $token] = $this->patientWithMessagingGrant();
        $payload = [
            'topic_code' => 'care_question',
            'message' => 'Please share this non-urgent question.',
            'client_message_uuid' => (string) Str::uuid7(),
            'urgent_guidance_version' => self::GUIDANCE_VERSION,
        ];
        $path = "/api/patient/v1/encounters/{$grant->encounter_uuid}/threads";

        $this->withToken($token)
            ->postJson($path, $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonValidationErrors(['idempotency_key']);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', 'not-a-uuid')
            ->withToken($token)
            ->postJson($path, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key']);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson($path, array_merge($payload, ['urgent_guidance_version' => 'outdated']))
            ->assertConflict()
            ->assertJsonPath('error.code', 'urgent_guidance_changed');

        $this->assertDatabaseCount('patient_experience.message_threads', 0);
        $this->assertDatabaseCount('patient_experience.messages', 0);
    }

    public function test_mutation_payloads_reject_unknown_properties_and_do_not_coerce_json_types(): void
    {
        $this->enableMessaging();
        [, $grant, $token] = $this->patientWithMessagingGrant();
        $path = "/api/patient/v1/encounters/{$grant->encounter_uuid}/threads";

        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson($path, [
                'topic_code' => ['care_question'],
                'message' => 42,
                'client_message_uuid' => false,
                'urgent_guidance_version' => ['version' => self::GUIDANCE_VERSION],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'topic_code',
                'message',
                'client_message_uuid',
                'urgent_guidance_version',
            ]);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson($path, [
                'topic_code' => 'care_question',
                'message' => 'A valid message with an invalid extra property.',
                'client_message_uuid' => (string) Str::uuid7(),
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
                'routing_override' => 'must-never-be-accepted',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['routing_override']);

        $this->app['auth']->forgetGuards();
        $thread = $this->createThread($grant, $token);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/threads/{$thread['thread_uuid']}/messages", [
                'message' => 'A numeric-string version is not a JSON integer.',
                'client_message_uuid' => (string) Str::uuid7(),
                'thread_version' => '1',
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
                'unexpected' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['thread_version', 'unexpected']);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/threads/{$thread['thread_uuid']}/close", [
                'thread_version' => '1',
                'close_reason' => ['no_longer_needed'],
                'unexpected' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['thread_version', 'close_reason', 'unexpected']);

        $source = PatientMessage::query()
            ->where('message_thread_id', PatientMessageThread::query()
                ->where('thread_uuid', $thread['thread_uuid'])
                ->value('message_thread_id'))
            ->firstOrFail();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/threads/{$thread['thread_uuid']}/messages/{$source->message_uuid}/amend", [
                'action' => 'retraction',
                'message' => 'A retraction must not carry a replacement body.',
                'client_message_uuid' => (string) Str::uuid7(),
                'thread_version' => '1',
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
                'unexpected' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['message', 'thread_version', 'unexpected']);

        $this->assertDatabaseCount('patient_experience.message_threads', 1);
        $this->assertDatabaseCount('patient_experience.messages', 1);
    }

    public function test_thread_creation_is_atomic_encrypted_audited_and_content_free_in_outbox(): void
    {
        $this->enableMessaging();
        [$principal, $grant, $token] = $this->patientWithMessagingGrant();
        $idempotencyKey = (string) Str::uuid7();
        $clientMessageUuid = (string) Str::uuid7();
        $body = 'Could someone explain what the care team plans to discuss today?';

        $response = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->withToken($token)
            ->postJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/threads", [
                'topic_code' => 'care_question',
                'message' => $body,
                'client_message_uuid' => $clientMessageUuid,
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertCreated()
            ->assertJsonPath('data.thread.status', 'open')
            ->assertJsonPath('data.thread.ownership_state', 'awaiting_team')
            ->assertJsonPath('data.thread.version', 1)
            ->assertJsonPath('meta.idempotency_replayed', false)
            ->assertJsonMissing(['message'])
            ->assertJsonMissing(['responsibility_pool_ref_digest'])
            ->assertJsonMissing(['creation_idempotency_key_digest']);

        $threadUuid = (string) $response->json('data.thread.thread_uuid');
        $thread = PatientMessageThread::query()->where('thread_uuid', $threadUuid)->firstOrFail();
        $message = PatientMessage::query()->where('message_thread_id', $thread->getKey())->firstOrFail();
        $rawMessage = DB::table('patient_experience.messages')->where('message_id', $message->getKey())->first();

        $cipher = $this->app->make(PatientMessageCipher::class);
        $this->assertSame(
            $body,
            $cipher->decrypt(
                (string) $message->encrypted_body,
                (string) $message->encryption_key_version,
                $cipher->contextFor($threadUuid, (string) $message->message_uuid),
            ),
        );
        $this->assertIsString($rawMessage->encrypted_body);
        $this->assertSame($rawMessage->encrypted_body, $message->encrypted_body);
        $this->assertStringNotContainsString($body, (string) $rawMessage->encrypted_body);
        $this->assertNotSame(hash('sha256', $body), (string) $message->body_digest);
        $this->assertSame(mb_strlen($body), (int) $message->body_character_count);

        $this->assertDatabaseCount('patient_experience.message_delivery_receipts', 1);
        $this->assertDatabaseCount('patient_experience.message_routing_events', 1);
        $this->assertDatabaseHas('patient_experience.notification_outbox', [
            'principal_id' => $principal->getKey(),
            'access_grant_id' => $grant->getKey(),
            'aggregate_type' => 'patient_message_thread',
            'aggregate_uuid' => $threadUuid,
            'event_type' => 'patient.messaging.thread_opened',
            'destination' => 'staff_inbox',
            'encrypted_payload' => null,
            'payload_digest' => null,
        ]);
        $outbox = PatientNotificationOutbox::query()->firstOrFail();
        $this->assertSame(1, $outbox->routing_metadata['schema_version']);
        $this->assertFalse($outbox->routing_metadata['content_included']);
        $this->assertSame('test-messaging-policy-v1', $outbox->routing_metadata['routing_policy_version']);
        $this->assertSame(
            (string) $thread->responsibility_pool_ref_digest,
            $outbox->routing_metadata['responsibility_pool_ref_digest'],
        );
        $this->assertStringNotContainsString($body, json_encode($outbox->getAttributes(), JSON_THROW_ON_ERROR));

        $audit = DB::table('patient_experience.access_audit_events')
            ->where('event_type', 'patient.messaging.thread_created')
            ->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString($body, json_encode($audit, JSON_THROW_ON_ERROR));
    }

    public function test_message_cipher_uses_a_dedicated_versioned_key_ring(): void
    {
        $this->enableMessaging();
        $versionOneKey = 'base64:'.base64_encode(str_repeat('a', 32));
        $versionTwoKey = 'base64:'.base64_encode(str_repeat('b', 32));
        config([
            'hummingbird-patient.messaging.encryption_key_version' => 'message-key-v1',
            'hummingbird-patient.messaging.encryption_key' => $versionOneKey,
        ]);
        [, $grant, $token] = $this->patientWithMessagingGrant();
        $body = 'A message encrypted under the first dedicated key.';

        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/threads", [
                'topic_code' => 'care_question',
                'message' => $body,
                'client_message_uuid' => (string) Str::uuid7(),
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertCreated();

        $message = PatientMessage::query()->firstOrFail();
        $ciphertext = (string) $message->encrypted_body;
        $this->assertSame('message-key-v1', $message->encryption_key_version);
        $this->assertStringNotContainsString($body, $ciphertext);

        config([
            'hummingbird-patient.messaging.encryption_key_version' => 'message-key-v2',
            'hummingbird-patient.messaging.encryption_key' => $versionTwoKey,
            'hummingbird-patient.messaging.previous_encryption_keys_json' => json_encode([
                'message-key-v1' => $versionOneKey,
            ], JSON_THROW_ON_ERROR),
        ]);
        $rotatedCipher = new PatientMessageCipher($this->app);
        $context = $rotatedCipher->contextFor(
            (string) $message->thread->thread_uuid,
            (string) $message->message_uuid,
        );
        $this->assertSame($body, $rotatedCipher->decrypt($ciphertext, 'message-key-v1', $context));
        $this->assertNotSame($ciphertext, $rotatedCipher->encrypt($body, 'message-key-v2', $context));
        $this->expectException(\RuntimeException::class);
        $rotatedCipher->decrypt(
            $ciphertext,
            'message-key-v1',
            $rotatedCipher->contextFor((string) $message->thread->thread_uuid, (string) Str::uuid7()),
        );
    }

    public function test_thread_creation_replay_is_exactly_once_and_payload_change_conflicts(): void
    {
        $this->enableMessaging();
        [, $grant, $token] = $this->patientWithMessagingGrant();
        $idempotencyKey = (string) Str::uuid7();
        $payload = [
            'topic_code' => 'care_question',
            'message' => 'What should I focus on today?',
            'client_message_uuid' => (string) Str::uuid7(),
            'urgent_guidance_version' => self::GUIDANCE_VERSION,
        ];
        $path = "/api/patient/v1/encounters/{$grant->encounter_uuid}/threads";

        $first = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->withToken($token)
            ->postJson($path, $payload)
            ->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->withToken($token)
            ->postJson($path, $payload)
            ->assertOk()
            ->assertJsonPath('data.thread.thread_uuid', $first->json('data.thread.thread_uuid'))
            ->assertJsonPath('meta.idempotency_replayed', true);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson($path, $payload)
            ->assertOk()
            ->assertJsonPath('data.thread.thread_uuid', $first->json('data.thread.thread_uuid'))
            ->assertJsonPath('meta.idempotency_replayed', true);

        $this->assertDatabaseCount('patient_experience.message_threads', 1);
        $this->assertDatabaseCount('patient_experience.messages', 1);
        $this->assertDatabaseCount('patient_experience.message_routing_events', 1);
        $this->assertDatabaseCount('patient_experience.notification_outbox', 1);
        $this->assertDatabaseCount('patient_experience.access_audit_events', 3);
        $replayAudits = DB::table('patient_experience.access_audit_events')
            ->where('event_type', 'patient.messaging.thread_creation_replayed')
            ->get();
        $this->assertCount(2, $replayAudits);
        $this->assertCount(2, $replayAudits->pluck('request_uuid')->unique());
        $this->assertTrue($replayAudits->every(
            static fn (object $audit): bool => $audit->idempotency_key_digest === null,
        ));

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->withToken($token)
            ->postJson($path, array_merge($payload, ['message' => 'Different content']))
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');

        $this->assertDatabaseCount('patient_experience.message_threads', 1);
        $this->assertDatabaseCount('patient_experience.messages', 1);
    }

    public function test_follow_up_messages_use_optimistic_concurrency_and_exact_replay(): void
    {
        $this->enableMessaging();
        [, $grant, $token] = $this->patientWithMessagingGrant();
        $thread = $this->createThread($grant, $token);
        $threadUuid = (string) $thread['thread_uuid'];
        $idempotencyKey = (string) Str::uuid7();
        $payload = [
            'message' => 'I have one more question for the team.',
            'client_message_uuid' => (string) Str::uuid7(),
            'thread_version' => 1,
            'urgent_guidance_version' => self::GUIDANCE_VERSION,
        ];

        $this->app['auth']->forgetGuards();
        $first = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->withToken($token)
            ->postJson("/api/patient/v1/threads/{$threadUuid}/messages", $payload)
            ->assertCreated()
            ->assertJsonPath('data.thread.version', 2)
            ->assertJsonPath('data.message.body', $payload['message'])
            ->assertJsonPath('meta.idempotency_replayed', false);

        $messageUuid = $first->json('data.message.message_uuid');
        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->withToken($token)
            ->postJson("/api/patient/v1/threads/{$threadUuid}/messages", $payload)
            ->assertOk()
            ->assertJsonPath('data.message.message_uuid', $messageUuid)
            ->assertJsonPath('meta.idempotency_replayed', true);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/threads/{$threadUuid}/messages", $payload)
            ->assertOk()
            ->assertJsonPath('data.message.message_uuid', $messageUuid)
            ->assertJsonPath('meta.idempotency_replayed', true);

        $this->assertDatabaseCount('patient_experience.messages', 2);
        $this->assertDatabaseCount('patient_experience.message_routing_events', 2);
        $this->assertDatabaseCount('patient_experience.notification_outbox', 2);
        $this->assertSame(
            2,
            DB::table('patient_experience.access_audit_events')
                ->where('event_type', 'patient.messaging.message_send_replayed')
                ->whereNull('idempotency_key_digest')
                ->count(),
        );

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/threads/{$threadUuid}/messages", [
                'message' => 'This uses an obsolete version.',
                'client_message_uuid' => (string) Str::uuid7(),
                'thread_version' => 1,
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'stale_thread_version');

        $this->assertDatabaseCount('patient_experience.messages', 2);
    }

    public function test_patient_can_append_one_content_free_retraction_or_a_corrected_message_without_mutating_history(): void
    {
        $this->enableMessaging();
        [, $grant, $token] = $this->patientWithMessagingGrant();
        $thread = $this->createThread($grant, $token);
        $threadUuid = (string) $thread['thread_uuid'];
        $source = PatientMessage::query()
            ->where('message_thread_id', PatientMessageThread::query()
                ->where('thread_uuid', $threadUuid)
                ->value('message_thread_id'))
            ->firstOrFail();
        $correctionKey = (string) Str::uuid7();
        $correctionPayload = [
            'action' => 'correction',
            'message' => 'Correction: this is the question I need the care team to review.',
            'client_message_uuid' => (string) Str::uuid7(),
            'thread_version' => 1,
            'urgent_guidance_version' => self::GUIDANCE_VERSION,
        ];
        $path = "/api/patient/v1/threads/{$threadUuid}/messages/{$source->message_uuid}/amend";

        $this->app['auth']->forgetGuards();
        $response = $this->withHeader('Idempotency-Key', $correctionKey)
            ->withToken($token)
            ->postJson($path, $correctionPayload)
            ->assertCreated()
            ->assertJsonPath('data.thread.version', 2)
            ->assertJsonPath('data.message.message_kind', 'correction')
            ->assertJsonPath('data.message.body', $correctionPayload['message'])
            ->assertJsonPath('data.message.relates_to_message_uuid', (string) $source->message_uuid)
            ->assertJsonPath('meta.idempotency_replayed', false);

        $correctionUuid = (string) $response->json('data.message.message_uuid');
        $this->assertDatabaseHas('patient_experience.messages', [
            'message_uuid' => $correctionUuid,
            'message_kind' => 'correction',
            'relates_to_message_id' => $source->getKey(),
        ]);
        $this->assertDatabaseHas('patient_experience.notification_outbox', [
            'aggregate_uuid' => $threadUuid,
            'event_type' => 'patient.messaging.message_corrected',
            'destination' => 'staff_inbox',
            'encrypted_payload' => null,
            'payload_digest' => null,
        ]);
        $outbox = PatientNotificationOutbox::query()
            ->where('event_type', 'patient.messaging.message_corrected')
            ->firstOrFail();
        $this->assertFalse((bool) $outbox->routing_metadata['content_included']);
        $this->assertStringNotContainsString(
            $correctionPayload['message'],
            json_encode($outbox->getAttributes(), JSON_THROW_ON_ERROR),
        );
        $this->assertDatabaseHas('patient_experience.access_audit_events', [
            'event_type' => 'patient.messaging.message_corrected',
            'resource_uuid' => $correctionUuid,
            'outcome' => 'succeeded',
        ]);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', $correctionKey)
            ->withToken($token)
            ->postJson($path, $correctionPayload)
            ->assertOk()
            ->assertJsonPath('data.message.message_uuid', $correctionUuid)
            ->assertJsonPath('meta.idempotency_replayed', true);

        $this->app['auth']->forgetGuards();
        $secondAmendment = $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson($path, array_merge($correctionPayload, [
                'client_message_uuid' => (string) Str::uuid7(),
                'thread_version' => 2,
            ]));
        $secondAmendment
            ->assertConflict()
            ->assertJsonPath('error.code', 'message_not_amendable');

        $this->assertDatabaseCount('patient_experience.messages', 2);
        $this->assertDatabaseCount('patient_experience.message_routing_events', 2);
        $this->assertDatabaseCount('patient_experience.notification_outbox', 2);
        $this->assertSame(
            1,
            DB::table('patient_experience.access_audit_events')
                ->where('event_type', 'patient.messaging.message_amend_replayed')
                ->whereNull('idempotency_key_digest')
                ->count(),
        );

        $this->app['auth']->forgetGuards();
        $retractionThread = $this->createThread($grant, $token);
        $retractionThreadUuid = (string) $retractionThread['thread_uuid'];
        $retractionSource = PatientMessage::query()
            ->where('message_thread_id', PatientMessageThread::query()
                ->where('thread_uuid', $retractionThreadUuid)
                ->value('message_thread_id'))
            ->firstOrFail();
        $retractionPayload = [
            'action' => 'retraction',
            'client_message_uuid' => (string) Str::uuid7(),
            'thread_version' => 1,
            'urgent_guidance_version' => self::GUIDANCE_VERSION,
        ];
        $retractionPath = "/api/patient/v1/threads/{$retractionThreadUuid}/messages/{$retractionSource->message_uuid}/amend";

        $this->app['auth']->forgetGuards();
        $retraction = $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson($retractionPath, $retractionPayload)
            ->assertCreated()
            ->assertJsonPath('data.thread.version', 2)
            ->assertJsonPath('data.message.message_kind', 'retraction')
            ->assertJsonPath('data.message.body', null)
            ->assertJsonPath('data.message.relates_to_message_uuid', (string) $retractionSource->message_uuid)
            ->assertJsonPath('meta.idempotency_replayed', false);

        $this->assertDatabaseHas('patient_experience.messages', [
            'message_uuid' => $retraction->json('data.message.message_uuid'),
            'message_kind' => 'retraction',
            'relates_to_message_id' => $retractionSource->getKey(),
            'encrypted_body' => null,
            'encryption_key_version' => null,
            'body_digest' => null,
            'body_character_count' => 0,
        ]);
        $this->assertDatabaseHas('patient_experience.notification_outbox', [
            'aggregate_uuid' => $retractionThreadUuid,
            'event_type' => 'patient.messaging.message_retracted',
            'destination' => 'staff_inbox',
            'encrypted_payload' => null,
            'payload_digest' => null,
        ]);
    }

    public function test_send_conflicts_when_operation_key_and_client_uuid_resolve_to_different_messages(): void
    {
        $this->enableMessaging();
        [, $grant, $token] = $this->patientWithMessagingGrant();
        $thread = $this->createThread($grant, $token);
        $threadUuid = (string) $thread['thread_uuid'];
        $firstKey = (string) Str::uuid7();
        $firstClientUuid = (string) Str::uuid7();
        $secondKey = (string) Str::uuid7();
        $secondClientUuid = (string) Str::uuid7();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', $firstKey)
            ->withToken($token)
            ->postJson("/api/patient/v1/threads/{$threadUuid}/messages", [
                'message' => 'First independently keyed follow-up.',
                'client_message_uuid' => $firstClientUuid,
                'thread_version' => 1,
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', $secondKey)
            ->withToken($token)
            ->postJson("/api/patient/v1/threads/{$threadUuid}/messages", [
                'message' => 'Second independently keyed follow-up.',
                'client_message_uuid' => $secondClientUuid,
                'thread_version' => 2,
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', $firstKey)
            ->withToken($token)
            ->postJson("/api/patient/v1/threads/{$threadUuid}/messages", [
                'message' => 'A request that tries to join two prior operations.',
                'client_message_uuid' => $secondClientUuid,
                'thread_version' => 2,
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');

        $this->assertDatabaseCount('patient_experience.messages', 3);
        $this->assertDatabaseCount('patient_experience.message_routing_events', 3);
        $this->assertDatabaseCount('patient_experience.notification_outbox', 3);
    }

    public function test_guidance_change_blocks_send_until_the_current_wording_is_presented(): void
    {
        $this->enableMessaging();
        [, $grant, $token] = $this->patientWithMessagingGrant();
        $thread = $this->createThread($grant, $token);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/threads/{$thread['thread_uuid']}/messages", [
                'message' => 'Please route this after I review the current guidance.',
                'client_message_uuid' => (string) Str::uuid7(),
                'thread_version' => 1,
                'urgent_guidance_version' => 'obsolete-guidance',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'urgent_guidance_changed');

        $this->assertDatabaseCount('patient_experience.messages', 1);
        $this->assertDatabaseCount('patient_experience.notification_outbox', 1);
    }

    public function test_patient_can_close_a_thread_and_cannot_send_more_content(): void
    {
        $this->enableMessaging();
        [, $grant, $token] = $this->patientWithMessagingGrant();
        $thread = $this->createThread($grant, $token);
        $threadUuid = (string) $thread['thread_uuid'];
        $closeKey = (string) Str::uuid7();
        $closePayload = [
            'thread_version' => 1,
            'close_reason' => 'no_longer_needed',
        ];

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', $closeKey)
            ->withToken($token)
            ->postJson("/api/patient/v1/threads/{$threadUuid}/close", $closePayload)
            ->assertOk()
            ->assertJsonPath('data.thread.status', 'closed')
            ->assertJsonPath('data.thread.ownership_state', 'closed')
            ->assertJsonPath('data.thread.version', 2)
            ->assertJsonPath('meta.idempotency_replayed', false);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', $closeKey)
            ->withToken($token)
            ->postJson("/api/patient/v1/threads/{$threadUuid}/close", $closePayload)
            ->assertOk()
            ->assertJsonPath('meta.idempotency_replayed', true);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/threads/{$threadUuid}/close", $closePayload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'thread_closed');

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/threads/{$threadUuid}/messages", [
                'message' => 'This must not be stored after closure.',
                'client_message_uuid' => (string) Str::uuid7(),
                'thread_version' => 2,
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'thread_closed');

        $this->assertDatabaseCount('patient_experience.messages', 1);
        $this->assertDatabaseCount('patient_experience.message_routing_events', 2);
        $this->assertDatabaseCount('patient_experience.notification_outbox', 2);
        $this->assertSame(
            1,
            DB::table('patient_experience.access_audit_events')
                ->where('event_type', 'patient.messaging.thread_close_replayed')
                ->whereNull('idempotency_key_digest')
                ->count(),
        );
    }

    public function test_cross_principal_and_revoked_grant_access_are_indistinguishable(): void
    {
        $this->enableMessaging();
        [, $grant, $token] = $this->patientWithMessagingGrant();
        $thread = $this->createThread($grant, $token);
        [$otherPrincipal, , $otherToken] = $this->patientWithMessagingGrant();

        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)
            ->getJson("/api/patient/v1/threads/{$thread['thread_uuid']}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonMissing(['thread_uuid'])
            ->assertJsonMissing(['message']);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($otherToken)
            ->postJson("/api/patient/v1/threads/{$thread['thread_uuid']}/messages", [
                'message' => 'This cross-principal write must be denied.',
                'client_message_uuid' => (string) Str::uuid7(),
                'thread_version' => 1,
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->assertDatabaseHas('patient_experience.access_audit_events', [
            'principal_id' => $otherPrincipal->getKey(),
            'event_type' => 'patient.messaging.access_denied',
            'action' => 'send_thread',
            'outcome' => 'denied',
        ]);

        $this->assertDatabaseHas('patient_experience.access_audit_events', [
            'principal_id' => $otherPrincipal->getKey(),
            'event_type' => 'patient.messaging.access_denied',
            'outcome' => 'denied',
            'reason_code' => 'resource_unavailable',
        ]);

        $grant->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revocation_reason' => 'Automated test revocation.',
            'version' => 2,
        ])->save();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson("/api/patient/v1/threads/{$thread['thread_uuid']}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/message-topics")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/threads")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_revoked_or_rescoped_grants_block_every_mutation_replay_branch(): void
    {
        $this->enableMessaging();
        [, $createGrant, $createToken] = $this->patientWithMessagingGrant();
        $createKey = (string) Str::uuid7();
        $createPayload = [
            'topic_code' => 'care_question',
            'message' => 'Create replay access must be checked again.',
            'client_message_uuid' => (string) Str::uuid7(),
            'urgent_guidance_version' => self::GUIDANCE_VERSION,
        ];
        $createPath = "/api/patient/v1/encounters/{$createGrant->encounter_uuid}/threads";

        $this->withHeader('Idempotency-Key', $createKey)
            ->withToken($createToken)
            ->postJson($createPath, $createPayload)
            ->assertCreated();
        $createGrant->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revocation_reason' => 'Replay authorization regression test.',
            'version' => 2,
        ])->save();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', $createKey)
            ->withToken($createToken)
            ->postJson($createPath, $createPayload)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        [, $sendGrant, $sendToken] = $this->patientWithMessagingGrant();
        $this->app['auth']->forgetGuards();
        $sendThread = $this->createThread($sendGrant, $sendToken);
        $sendKey = (string) Str::uuid7();
        $sendPayload = [
            'message' => 'Send replay access must be checked again.',
            'client_message_uuid' => (string) Str::uuid7(),
            'thread_version' => 1,
            'urgent_guidance_version' => self::GUIDANCE_VERSION,
        ];

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', $sendKey)
            ->withToken($sendToken)
            ->postJson("/api/patient/v1/threads/{$sendThread['thread_uuid']}/messages", $sendPayload)
            ->assertCreated();
        $sendGrant->forceFill(['scopes' => ['messaging:read'], 'version' => 2])->save();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', $sendKey)
            ->withToken($sendToken)
            ->postJson("/api/patient/v1/threads/{$sendThread['thread_uuid']}/messages", $sendPayload)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        [, $closeGrant, $closeToken] = $this->patientWithMessagingGrant();
        $this->app['auth']->forgetGuards();
        $closeThread = $this->createThread($closeGrant, $closeToken);
        $closeKey = (string) Str::uuid7();
        $closePayload = ['thread_version' => 1, 'close_reason' => 'no_longer_needed'];

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', $closeKey)
            ->withToken($closeToken)
            ->postJson("/api/patient/v1/threads/{$closeThread['thread_uuid']}/close", $closePayload)
            ->assertOk();
        $closeGrant->forceFill(['scopes' => ['messaging:read'], 'version' => 2])->save();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Idempotency-Key', $closeKey)
            ->withToken($closeToken)
            ->postJson("/api/patient/v1/threads/{$closeThread['thread_uuid']}/close", $closePayload)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->assertDatabaseCount('patient_experience.access_audit_events', 8);
        $this->assertSame(
            0,
            DB::table('patient_experience.access_audit_events')
                ->whereIn('event_type', [
                    'patient.messaging.thread_creation_replayed',
                    'patient.messaging.message_send_replayed',
                    'patient.messaging.thread_close_replayed',
                ])
                ->count(),
        );
        $this->assertSame(
            3,
            DB::table('patient_experience.access_audit_events')
                ->where('event_type', 'patient.messaging.access_denied')
                ->count(),
        );
    }

    public function test_staff_internal_content_is_never_returned_and_message_facts_are_append_only(): void
    {
        $this->enableMessaging();
        [$principal, $grant, $token] = $this->patientWithMessagingGrant();
        $threadData = $this->createThread($grant, $token);
        $thread = PatientMessageThread::query()->where('thread_uuid', $threadData['thread_uuid'])->firstOrFail();
        $original = PatientMessage::query()->where('message_thread_id', $thread->getKey())->firstOrFail();
        $hmac = $this->app->make(PatientHmac::class);
        $cipher = $this->app->make(PatientMessageCipher::class);

        $this->app['auth']->forgetGuards();
        $otherThreadData = $this->createThread($grant, $token);
        $otherThread = PatientMessageThread::query()
            ->where('thread_uuid', $otherThreadData['thread_uuid'])
            ->firstOrFail();

        try {
            DB::transaction(function () use ($cipher, $hmac, $original, $otherThread, $principal): void {
                $messageUuid = (string) Str::uuid7();
                PatientMessage::query()->create([
                    'message_uuid' => $messageUuid,
                    'message_thread_id' => $otherThread->getKey(),
                    'sender_type' => 'patient',
                    'sender_principal_id' => $principal->getKey(),
                    'visibility' => 'patient_visible',
                    'message_kind' => 'correction',
                    'relates_to_message_id' => $original->getKey(),
                    'encrypted_body' => $cipher->encrypt(
                        'A cross-thread correction that must fail.',
                        'test-encryption-key-v1',
                        $cipher->contextFor((string) $otherThread->thread_uuid, $messageUuid),
                    ),
                    'encryption_key_version' => 'test-encryption-key-v1',
                    'body_digest' => $hmac->digest(
                        'messaging-body',
                        'A cross-thread correction that must fail.',
                    ),
                    'body_character_count' => mb_strlen('A cross-thread correction that must fail.'),
                    'delivery_state' => 'accepted',
                    'sent_at' => now(),
                ]);
            });
            $this->fail('Cross-thread message correction unexpectedly passed the database guard.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $internalMessageUuid = (string) Str::uuid7();
        PatientMessage::query()->create([
            'message_uuid' => $internalMessageUuid,
            'message_thread_id' => $thread->getKey(),
            'sender_type' => 'staff',
            'sender_actor_ref_digest' => $hmac->digest('messaging-actor-ref', 'test-staff-actor'),
            'visibility' => 'staff_internal',
            'message_kind' => 'message',
            'encrypted_body' => $cipher->encrypt(
                'Internal routing note that the patient must never receive.',
                'test-encryption-key-v1',
                $cipher->contextFor((string) $thread->thread_uuid, $internalMessageUuid),
            ),
            'encryption_key_version' => 'test-encryption-key-v1',
            'body_digest' => $hmac->digest('messaging-body', 'Internal routing note that the patient must never receive.'),
            'body_character_count' => 58,
            'delivery_state' => 'accepted',
            'sent_at' => now(),
        ]);
        $correctionMessageUuid = (string) Str::uuid7();
        $correction = PatientMessage::query()->create([
            'message_uuid' => $correctionMessageUuid,
            'message_thread_id' => $thread->getKey(),
            'sender_type' => 'patient',
            'sender_principal_id' => $principal->getKey(),
            'visibility' => 'patient_visible',
            'message_kind' => 'correction',
            'relates_to_message_id' => $original->getKey(),
            'encrypted_body' => $cipher->encrypt(
                'A patient-visible correction.',
                'test-encryption-key-v1',
                $cipher->contextFor((string) $thread->thread_uuid, $correctionMessageUuid),
            ),
            'encryption_key_version' => 'test-encryption-key-v1',
            'body_digest' => $hmac->digest('messaging-body', 'A patient-visible correction.'),
            'body_character_count' => mb_strlen('A patient-visible correction.'),
            'delivery_state' => 'accepted',
            'sent_at' => now(),
        ]);
        PatientMessage::query()->create([
            'message_uuid' => (string) Str::uuid7(),
            'message_thread_id' => $thread->getKey(),
            'sender_type' => 'patient',
            'sender_principal_id' => $principal->getKey(),
            'visibility' => 'patient_visible',
            'message_kind' => 'retraction',
            'relates_to_message_id' => $correction->getKey(),
            'body_character_count' => 0,
            'delivery_state' => 'accepted',
            'sent_at' => now(),
        ]);

        $this->app['auth']->forgetGuards();
        $response = $this->withToken($token)
            ->getJson("/api/patient/v1/threads/{$thread->thread_uuid}")
            ->assertOk()
            ->assertJsonPath('data.thread.history_truncated', false)
            ->assertJsonCount(3, 'data.thread.messages')
            ->assertJsonPath('data.thread.messages.1.message_kind', 'correction')
            ->assertJsonPath('data.thread.messages.2.message_kind', 'retraction');

        $this->assertStringNotContainsString('Internal routing note', $response->getContent());

        try {
            $original->forceFill(['delivery_state' => 'delivered'])->save();
            $this->fail('Append-only message model unexpectedly allowed an update.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        try {
            DB::table('patient_experience.messages')
                ->where('message_id', $original->getKey())
                ->update(['delivery_state' => 'delivered']);
            $this->fail('Append-only message trigger unexpectedly allowed an update.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_failed_message_decryption_never_records_a_false_disclosure_success(): void
    {
        $this->enableMessaging();
        [, $grant, $token] = $this->patientWithMessagingGrant();
        $threadData = $this->createThread($grant, $token);
        $thread = PatientMessageThread::query()
            ->where('thread_uuid', $threadData['thread_uuid'])
            ->firstOrFail();
        $hmac = $this->app->make(PatientHmac::class);

        PatientMessage::query()->create([
            'message_uuid' => (string) Str::uuid7(),
            'message_thread_id' => $thread->getKey(),
            'sender_type' => 'staff',
            'sender_actor_ref_digest' => $hmac->digest('messaging-actor-ref', 'test-staff-actor'),
            'visibility' => 'patient_visible',
            'message_kind' => 'message',
            'encrypted_body' => 'not-a-valid-encrypted-message-payload',
            'encryption_key_version' => 'test-encryption-key-v1',
            'body_digest' => $hmac->digest('messaging-body', 'A deliberately corrupt fixture.'),
            'body_character_count' => mb_strlen('A deliberately corrupt fixture.'),
            'delivery_state' => 'accepted',
            'sent_at' => now()->addSecond(),
        ]);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson("/api/patient/v1/threads/{$thread->thread_uuid}")
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'service_unavailable');

        $this->assertSame(
            0,
            DB::table('patient_experience.access_audit_events')
                ->where('event_type', 'patient.messaging.thread_viewed')
                ->count(),
        );
    }

    public function test_thread_detail_returns_the_newest_history_window_and_marks_truncation(): void
    {
        $this->enableMessaging();
        [, $grant, $token] = $this->patientWithMessagingGrant();
        $threadData = $this->createThread($grant, $token);
        $thread = PatientMessageThread::query()
            ->where('thread_uuid', $threadData['thread_uuid'])
            ->firstOrFail();
        $hmac = $this->app->make(PatientHmac::class);
        $sentAt = now()->addMinute();
        $rows = [];
        $newestMessageUuid = null;

        for ($index = 0; $index < 1001; $index++) {
            $newestMessageUuid = (string) Str::uuid7();
            $rows[] = [
                'message_uuid' => $newestMessageUuid,
                'message_thread_id' => $thread->getKey(),
                'sender_type' => 'system',
                'sender_actor_ref_digest' => $hmac->digest('messaging-actor-ref', 'history-window-fixture'),
                'visibility' => 'patient_visible',
                'message_kind' => 'system_status',
                'body_character_count' => 0,
                'delivery_state' => 'accepted',
                'sent_at' => $sentAt,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('patient_experience.messages')->insert($chunk);
        }

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson("/api/patient/v1/threads/{$thread->thread_uuid}")
            ->assertOk()
            ->assertJsonPath('data.thread.history_truncated', true)
            ->assertJsonCount(1000, 'data.thread.messages')
            ->assertJsonPath('data.thread.messages.999.message_uuid', $newestMessageUuid);

        $this->assertDatabaseHas('patient_experience.access_audit_events', [
            'event_type' => 'patient.messaging.thread_viewed',
            'resource_uuid' => (string) $thread->thread_uuid,
            'outcome' => 'succeeded',
        ]);
    }

    public function test_outbox_failure_rolls_back_thread_message_receipt_routing_and_audit(): void
    {
        $this->enableMessaging();
        [$principal, $grant, $token] = $this->patientWithMessagingGrant();
        $idempotencyKey = (string) Str::uuid7();
        $hmac = $this->app->make(PatientHmac::class);
        $operationDigest = $hmac->digest(
            'messaging-idempotency',
            $principal->principal_uuid.'|thread-create|'.$idempotencyKey,
        );

        PatientNotificationOutbox::query()->create([
            'outbox_uuid' => (string) Str::uuid7(),
            'principal_id' => $principal->getKey(),
            'access_grant_id' => $grant->getKey(),
            'aggregate_type' => 'collision_fixture',
            'aggregate_uuid' => (string) Str::uuid7(),
            'event_type' => 'patient.messaging.collision_fixture',
            'destination' => 'staff_inbox',
            'routing_metadata' => ['schema_version' => 1],
            'idempotency_key_digest' => $hmac->digest('messaging-outbox', $operationDigest),
            'available_at' => now(),
            'occurred_at' => now(),
        ]);

        $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->withToken($token)
            ->postJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/threads", [
                'topic_code' => 'care_question',
                'message' => 'This entire transaction must roll back.',
                'client_message_uuid' => (string) Str::uuid7(),
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'service_unavailable')
            ->assertJsonMissing(['exception']);

        $this->assertDatabaseCount('patient_experience.message_threads', 0);
        $this->assertDatabaseCount('patient_experience.messages', 0);
        $this->assertDatabaseCount('patient_experience.message_delivery_receipts', 0);
        $this->assertDatabaseCount('patient_experience.message_routing_events', 0);
        $this->assertDatabaseCount('patient_experience.access_audit_events', 0);
        $this->assertDatabaseCount('patient_experience.notification_outbox', 1);
    }

    /**
     * @return array{0: PatientPrincipal, 1: PatientEncounterAccessGrant, 2: string}
     */
    private function patientWithMessagingGrant(): array
    {
        $unit = Unit::query()->create([
            'name' => 'Patient Messaging Test Unit '.Str::upper(Str::random(6)),
            'abbreviation' => Str::upper(Str::random(6)),
            'type' => 'med_surg',
            'staffed_bed_count' => 12,
            'ratio_floor' => 4,
            'access_standard_minutes' => 120,
            'is_deleted' => false,
        ]);
        $encounter = Encounter::query()->create([
            'patient_ref' => 'messaging-test-'.Str::lower(Str::random(12)),
            'unit_id' => $unit->getKey(),
            'admitted_at' => now()->subDay(),
            'acuity_tier' => 2,
            'status' => 'active',
            'is_deleted' => false,
        ]);
        $principal = PatientPrincipal::query()->create([
            'principal_uuid' => (string) Str::uuid7(),
            'principal_type' => 'patient',
            'display_name' => 'Messaging Test Patient',
            'email' => 'messaging+'.Str::lower(Str::random(10)).'@example.test',
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
            'grant_reason' => 'Automated patient messaging test.',
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
        $token = $principal->createToken('patient-access:'.$sessionUuid, ['patient:access'])->plainTextToken;

        return [$principal, $grant, $token];
    }

    /** @return array<string, mixed> */
    private function createThread(PatientEncounterAccessGrant $grant, string $token): array
    {
        $thread = $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($token)
            ->postJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/threads", [
                'topic_code' => 'care_question',
                'message' => 'I have a non-urgent question for my care team.',
                'client_message_uuid' => (string) Str::uuid7(),
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertCreated()
            ->json('data.thread');

        $this->projectAccountableWorkItem($grant, $thread);

        return $thread;
    }

    /** @param array<string, mixed> $threadData */
    private function projectAccountableWorkItem(
        PatientEncounterAccessGrant $grant,
        array $threadData,
    ): void {
        $thread = PatientMessageThread::query()
            ->where('thread_uuid', $threadData['thread_uuid'])
            ->firstOrFail();
        $encounter = Encounter::query()->findOrFail($grant->source_encounter_id);
        $outbox = PatientNotificationOutbox::query()
            ->where('aggregate_type', 'patient_message_thread')
            ->where('aggregate_uuid', $thread->thread_uuid)
            ->latest('notification_outbox_id')
            ->firstOrFail();
        $pool = ResponsibilityPool::query()->firstOrCreate(
            [
                'routing_policy_version' => (string) $thread->routing_policy_version,
                'pool_key_digest' => (string) $thread->responsibility_pool_ref_digest,
                'topic_code' => (string) $thread->topic_code,
                'scope_type' => 'unit',
                'unit_id' => $encounter->unit_id,
            ],
            [
                'display_name' => 'Patient Messaging Test Care Team',
                'status' => 'active',
                'response_target_minutes' => 30,
                'escalation_target_minutes' => 60,
            ],
        );
        $responder = User::factory()->create([
            'role' => 'charge_nurse',
            'is_active' => true,
        ]);
        PoolMembership::query()->create([
            'responsibility_pool_id' => $pool->getKey(),
            'staff_user_id' => $responder->getKey(),
            'membership_role' => 'responder',
            'availability_state' => 'active',
            'can_claim' => true,
            'can_reply' => true,
            'can_reroute' => false,
            'can_close' => false,
            'effective_from' => now()->subMinute(),
        ]);
        config([
            'hummingbird-patient.staff_messaging.pilot_unit_ids' => collect(
                config('hummingbird-patient.staff_messaging.pilot_unit_ids', []),
            )
                ->push((int) $encounter->unit_id)
                ->unique()
                ->values()
                ->all(),
        ]);

        ThreadWorkItem::query()->firstOrCreate(
            ['message_thread_id' => $thread->getKey()],
            [
                'access_grant_id' => $grant->getKey(),
                'responsibility_pool_id' => $pool->getKey(),
                'status' => 'open',
                'ownership_state' => 'pool_owned',
                'source_thread_version' => (int) $thread->version,
                'row_version' => 1,
                'last_outbox_id' => $outbox->getKey(),
                'first_routed_at' => $thread->created_at,
                'due_at' => $thread->created_at->copy()->addMinutes(30),
                'escalate_at' => $thread->created_at->copy()->addMinutes(60),
                'last_message_at' => $thread->last_message_at,
            ],
        );
    }

    private function enableMessaging(): void
    {
        config([
            'hummingbird-patient.enabled' => true,
            'hummingbird-patient.features.messaging' => true,
            'hummingbird-patient.messaging' => [
                'governance_status' => 'approved',
                'policy_version' => 'test-messaging-policy-v1',
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
                'urgent_guidance_text' => self::TEST_GUIDANCE,
                'default_response_window' => 'The test care team usually responds within one test hour.',
                'encryption_key_version' => 'test-encryption-key-v1',
                'handoff_consumer' => TestPatientMessageHandoffReadiness::class,
                'topics' => [
                    'care_question' => [
                        'label' => 'Question for my care team',
                        'description' => 'Ask a non-urgent question about your current hospital care.',
                        'responsibility_pool_key' => 'test.unit.care-team',
                    ],
                ],
            ],
        ]);
    }
}

class TestPatientMessageHandoffReadiness implements PatientMessageHandoffReadiness
{
    public function readyForPolicy(string $policyVersion): bool
    {
        return $policyVersion === 'test-messaging-policy-v1';
    }

    public function routableForGrant(
        string $policyVersion,
        string $topicCode,
        string $responsibilityPoolKey,
        PatientEncounterAccessGrant $grant,
    ): bool {
        return $policyVersion === 'test-messaging-policy-v1'
            && in_array($topicCode, ['care_question', 'care_preference'], true)
            && $responsibilityPoolKey === 'test.unit.care-team'
            && $grant->exists;
    }
}

class UnreadyPatientMessageHandoff implements PatientMessageHandoffReadiness
{
    public function readyForPolicy(string $policyVersion): bool
    {
        return false;
    }

    public function routableForGrant(
        string $policyVersion,
        string $topicCode,
        string $responsibilityPoolKey,
        PatientEncounterAccessGrant $grant,
    ): bool {
        return false;
    }
}
