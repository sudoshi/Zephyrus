<?php

namespace Tests\Feature\Rounds;

use App\Contracts\Patient\PatientMessageHandoffReadiness;
use App\Models\Audit\UserEvent;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientMessage;
use App\Models\Patient\PatientMessageThread;
use App\Models\Patient\PatientNotificationOutbox;
use App\Models\Patient\PatientPrincipal;
use App\Models\PatientCommunication\PoolMembership;
use App\Models\PatientCommunication\ResponsibilityPool;
use App\Models\PatientCommunication\RoundQuestionPromotion;
use App\Models\PatientCommunication\RoundQuestionPromotionOutcome;
use App\Models\PatientCommunication\ThreadWorkItem;
use App\Models\Rounds\RoundPatient;
use App\Models\Rounds\RoundQuestion;
use App\Models\User;
use App\Services\Patient\Messaging\PatientMessageCipher;
use App\Services\Patient\Messaging\PatientMessagingService;
use App\Services\Patient\PatientHmac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\Support\SeedsRoundsStory;
use Tests\TestCase;

class PatientRoundQuestionPromotionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsRoundsStory;

    private const POLICY_VERSION = 'test-rounds-question-policy-v1';

    private const QUESTION = 'Could we discuss what I should prepare for after today?';

    private const CORRECTED_QUESTION = 'Correction: could we discuss what I should prepare before tomorrow morning?';

    private const PATIENT_STATUS = 'Your question was shared with your care team for possible review. It may not be discussed in a particular round.';

    private const PATIENT_OUTCOME_STATUS = 'Your care team has completed their review of the question you shared. If you still need help, please send a message to your care team.';

    private User $staff;

    private PatientEncounterAccessGrant $grant;

    private PatientMessageThread $thread;

    private PatientMessage $message;

    private string $roundPatientUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoundsStory();
        $board = $this->createRoundsRun();
        $this->roundPatientUuid = $this->boardRowFor($board, 'ROUNDS-PAT-ROUTINE')['round_patient_uuid'];

        $this->configureBridge();
        $this->staff = User::factory()->create(['role' => 'charge_nurse', 'is_active' => true]);
        $this->staff->units()->attach($this->roundsUnit->getKey(), ['role' => 'charge']);
        [$this->grant, $this->thread, $this->message] = $this->createPatientQuestionFixture();
    }

    public function test_staff_can_discover_an_eligible_patient_question_only_in_the_matching_round_workspace(): void
    {
        $candidatesUrl = "/api/rounds/patients/{$this->roundPatientUuid}/patient-question-threads";

        $this->actingAs($this->staff)
            ->getJson($candidatesUrl)
            ->assertOk()
            ->assertJsonPath('data.patient_questions.0.thread_uuid', (string) $this->thread->thread_uuid)
            ->assertJsonPath('data.patient_questions.0.thread_version', $this->thread->version)
            ->assertJsonPath('data.patient_questions.0.message_uuid', (string) $this->message->message_uuid)
            ->assertJsonPath('data.patient_questions.0.question_text', self::QUESTION);

        $otherRoundPatientUuid = RoundPatient::query()
            ->where('prod_encounter_id', $this->roundsEncounters['ROUNDS-PAT-ACUTE']->getKey())
            ->value('round_patient_uuid');
        $this->assertIsString($otherRoundPatientUuid);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staff)
            ->getJson("/api/rounds/patients/{$otherRoundPatientUuid}/patient-question-threads")
            ->assertOk()
            ->assertJsonPath('data.patient_questions', []);

        config(['rounds.patient_question_bridge_enabled' => false]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staff)
            ->getJson($candidatesUrl)
            ->assertNotFound();
    }

    public function test_patient_can_withdraw_an_unshared_rounds_question_by_closing_its_conversation(): void
    {
        $closeRequest = Request::create('/api/patient/v1/threads/'.$this->thread->thread_uuid.'/close', 'POST');
        $closeRequest->headers->set('Idempotency-Key', (string) Str::uuid7());

        $result = $this->app->make(PatientMessagingService::class)->closeThread(
            $closeRequest,
            $this->grant->principal,
            (string) $this->thread->thread_uuid,
            [
                'thread_version' => $this->thread->version,
                'close_reason' => 'created_in_error',
                'idempotency_key' => (string) Str::uuid7(),
            ],
        );

        $this->assertSame('closed', $result['thread']['status']);
        $this->assertSame('created_in_error', $result['thread']['close_reason']);
        $this->assertSame(2, $result['thread']['version']);

        $this->actingAs($this->staff)
            ->getJson("/api/rounds/patients/{$this->roundPatientUuid}/patient-question-threads")
            ->assertOk()
            ->assertJsonPath('data.patient_questions', []);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staff)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson(
                "/api/rounds/patients/{$this->roundPatientUuid}/patient-question-threads/{$this->thread->thread_uuid}/promote",
                [
                    'message_uuid' => (string) $this->message->message_uuid,
                    'thread_version' => 2,
                ],
            )
            ->assertNotFound();

        $this->assertDatabaseCount('rounds.questions', 0);
        $this->assertDatabaseCount('patient_communications.round_question_promotions', 0);
        $this->assertDatabaseHas('patient_experience.message_routing_events', [
            'message_thread_id' => $this->thread->getKey(),
            'event_type' => 'closed',
            'reason_code' => 'created_in_error',
        ]);
    }

    public function test_staff_can_explicitly_promote_a_matching_patient_question_once_with_exact_replay(): void
    {
        $url = "/api/rounds/patients/{$this->roundPatientUuid}/patient-question-threads/{$this->thread->thread_uuid}/promote";
        $key = (string) Str::uuid7();
        $payload = [
            'message_uuid' => (string) $this->message->message_uuid,
            'thread_version' => $this->thread->version,
        ];

        $this->actingAs($this->staff)
            ->withHeader('Idempotency-Key', $key)
            ->postJson($url, $payload)
            ->assertCreated()
            ->assertJsonPath('data.questions.0.question_text', self::QUESTION);

        $question = RoundQuestion::query()->firstOrFail();
        $promotion = RoundQuestionPromotion::query()->firstOrFail();
        $this->assertSame($this->staff->getKey(), $question->raised_by);
        $this->assertSame('charge_nurse', $question->raised_role);
        $this->assertSame($this->thread->getKey(), $promotion->message_thread_id);
        $this->assertSame($this->message->getKey(), $promotion->source_message_id);
        $this->assertSame($question->getKey(), $promotion->round_question_id);
        $this->assertNotNull($promotion->patient_status_message_id);
        $this->assertStringNotContainsString(self::QUESTION, json_encode($promotion->getAttributes(), JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('patient_communications.round_question_promotions', 1);
        $this->assertDatabaseCount('rounds.questions', 1);
        $this->assertDatabaseCount('patient_experience.messages', 2);

        $patientThread = $this->app->make(PatientMessagingService::class)->showThread(
            Request::create('/api/patient/v1/threads/'.$this->thread->thread_uuid, 'GET'),
            $this->grant->principal,
            (string) $this->thread->thread_uuid,
        );
        $status = collect($patientThread['thread']['messages'])
            ->firstWhere('message_kind', 'system_status');
        $this->assertSame(self::PATIENT_STATUS, $status['body'] ?? null);
        $this->assertSame('Care team', $status['sender_display_role'] ?? null);
        $this->assertStringNotContainsString(self::QUESTION, json_encode($status, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('round_patient', json_encode($status, JSON_THROW_ON_ERROR));
        $this->assertSame(2, $this->thread->refresh()->version);
        $this->assertSame(2, ThreadWorkItem::query()->firstOrFail()->source_thread_version);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staff)
            ->withHeader('Idempotency-Key', $key)
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('data.questions.0.question_uuid', (string) $question->question_uuid);

        $this->assertDatabaseCount('patient_communications.round_question_promotions', 1);
        $this->assertDatabaseCount('rounds.questions', 1);
        $this->assertDatabaseCount('patient_experience.messages', 2);
        $this->assertDatabaseHas('rounds.events', [
            'aggregate_type' => 'question',
            'aggregate_id' => $question->getKey(),
            'event_type' => 'question.promoted_from_patient_message',
        ]);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staff)
            ->getJson("/api/rounds/patients/{$this->roundPatientUuid}/patient-question-threads")
            ->assertOk()
            ->assertJsonPath('data.patient_questions', []);

        $audit = UserEvent::query()
            ->where('action', 'rounds.patient_question_promoted')
            ->firstOrFail();
        $this->assertStringNotContainsString(self::QUESTION, json_encode([
            'metadata' => $audit->metadata,
            'changes' => $audit->changes,
        ], JSON_THROW_ON_ERROR));
    }

    public function test_patient_receives_one_safe_outcome_after_staff_resolves_a_promoted_question(): void
    {
        $this->actingAs($this->staff)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson(
                "/api/rounds/patients/{$this->roundPatientUuid}/patient-question-threads/{$this->thread->thread_uuid}/promote",
                [
                    'message_uuid' => (string) $this->message->message_uuid,
                    'thread_version' => $this->thread->version,
                ],
            )
            ->assertCreated();

        $question = RoundQuestion::query()->firstOrFail();
        $promotion = RoundQuestionPromotion::query()->firstOrFail();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staff)
            ->postJson("/api/rounds/questions/{$question->question_uuid}/resolve", ['status' => 'answered'])
            ->assertOk();

        $outcome = RoundQuestionPromotionOutcome::query()->firstOrFail();
        $this->assertSame($promotion->getKey(), $outcome->round_question_promotion_id);
        $this->assertSame($this->staff->getKey(), $outcome->resolved_by_user_id);
        $this->assertSame('answered', $outcome->resolved_status);
        $this->assertDatabaseCount('patient_communications.round_question_promotion_outcomes', 1);
        $this->assertDatabaseCount('patient_experience.messages', 3);
        $this->assertSame('answered', $question->refresh()->status);
        $this->assertSame(3, $this->thread->refresh()->version);
        $this->assertSame(3, ThreadWorkItem::query()->firstOrFail()->source_thread_version);

        $patientThread = $this->app->make(PatientMessagingService::class)->showThread(
            Request::create('/api/patient/v1/threads/'.$this->thread->thread_uuid, 'GET'),
            $this->grant->principal,
            (string) $this->thread->thread_uuid,
        );
        $statuses = collect($patientThread['thread']['messages'])
            ->where('message_kind', 'system_status')
            ->values();
        $this->assertCount(2, $statuses);
        $this->assertSame(self::PATIENT_STATUS, $statuses->first()['body'] ?? null);
        $this->assertSame(self::PATIENT_OUTCOME_STATUS, $statuses->last()['body'] ?? null);
        $this->assertStringNotContainsString(self::QUESTION, json_encode($statuses, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('round', Str::lower(json_encode($statuses->last(), JSON_THROW_ON_ERROR)));

        $this->assertDatabaseHas('rounds.events', [
            'aggregate_type' => 'question',
            'aggregate_id' => $question->getKey(),
            'event_type' => 'question.patient_outcome_published',
        ]);
        $audit = UserEvent::query()
            ->where('action', 'rounds.patient_question_outcome_published')
            ->firstOrFail();
        $this->assertStringNotContainsString(self::QUESTION, json_encode([
            'metadata' => $audit->metadata,
            'changes' => $audit->changes,
        ], JSON_THROW_ON_ERROR));

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staff)
            ->postJson("/api/rounds/questions/{$question->question_uuid}/resolve", ['status' => 'answered'])
            ->assertOk();
        $this->assertDatabaseCount('patient_communications.round_question_promotion_outcomes', 1);
        $this->assertDatabaseCount('patient_experience.messages', 3);
    }

    public function test_bridge_disablement_suppresses_outcome_disclosure_without_blocking_staff_resolution(): void
    {
        $this->actingAs($this->staff)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson(
                "/api/rounds/patients/{$this->roundPatientUuid}/patient-question-threads/{$this->thread->thread_uuid}/promote",
                [
                    'message_uuid' => (string) $this->message->message_uuid,
                    'thread_version' => $this->thread->version,
                ],
            )
            ->assertCreated();
        $question = RoundQuestion::query()->firstOrFail();

        config(['rounds.patient_question_bridge_enabled' => false]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staff)
            ->postJson("/api/rounds/questions/{$question->question_uuid}/resolve", ['status' => 'dismissed'])
            ->assertOk();

        $this->assertSame('dismissed', $question->refresh()->status);
        $this->assertDatabaseCount('patient_communications.round_question_promotion_outcomes', 0);
        $this->assertDatabaseCount('patient_experience.messages', 2);
    }

    public function test_bridge_fails_closed_when_disabled_or_the_selected_round_patient_is_not_the_granted_encounter(): void
    {
        $url = "/api/rounds/patients/{$this->roundPatientUuid}/patient-question-threads/{$this->thread->thread_uuid}/promote";
        $payload = [
            'message_uuid' => (string) $this->message->message_uuid,
            'thread_version' => $this->thread->version,
        ];

        config(['rounds.patient_question_bridge_enabled' => false]);
        $this->actingAs($this->staff)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson($url, $payload)
            ->assertNotFound();
        $this->assertDatabaseCount('rounds.questions', 0);

        config(['rounds.patient_question_bridge_enabled' => true]);
        $otherRoundPatientUuid = RoundPatient::query()
            ->where('prod_encounter_id', $this->roundsEncounters['ROUNDS-PAT-ACUTE']->getKey())
            ->value('round_patient_uuid');
        $this->assertIsString($otherRoundPatientUuid);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staff)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/rounds/patients/{$otherRoundPatientUuid}/patient-question-threads/{$this->thread->thread_uuid}/promote", $payload)
            ->assertNotFound();
        $this->assertDatabaseCount('rounds.questions', 0);
        $this->assertDatabaseCount('patient_communications.round_question_promotions', 0);

        $this->grant->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revocation_reason' => 'Automated test revocation.',
        ])->save();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staff)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson($url, $payload)
            ->assertNotFound();
        $this->assertDatabaseCount('rounds.questions', 0);
    }

    public function test_a_retracted_patient_message_cannot_be_promoted(): void
    {
        PatientMessage::query()->create([
            'message_uuid' => (string) Str::uuid7(),
            'message_thread_id' => $this->thread->getKey(),
            'sender_type' => 'patient',
            'sender_principal_id' => $this->grant->principal_id,
            'visibility' => 'patient_visible',
            'message_kind' => 'retraction',
            'relates_to_message_id' => $this->message->getKey(),
            'body_character_count' => 0,
            'delivery_state' => 'accepted',
            'sent_at' => now()->addSecond(),
        ]);

        $this->actingAs($this->staff)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson(
                "/api/rounds/patients/{$this->roundPatientUuid}/patient-question-threads/{$this->thread->thread_uuid}/promote",
                [
                    'message_uuid' => (string) $this->message->message_uuid,
                    'thread_version' => $this->thread->version,
                ],
            )
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'rounds_policy');
        $this->assertDatabaseCount('rounds.questions', 0);
        $this->assertDatabaseCount('patient_communications.round_question_promotions', 0);
    }

    public function test_staff_can_only_discover_and_promote_a_superseding_correction(): void
    {
        $correction = $this->appendCorrectionToQuestion();

        $this->actingAs($this->staff)
            ->getJson("/api/rounds/patients/{$this->roundPatientUuid}/patient-question-threads")
            ->assertOk()
            ->assertJsonCount(1, 'data.patient_questions')
            ->assertJsonPath('data.patient_questions.0.message_uuid', (string) $correction->message_uuid)
            ->assertJsonPath('data.patient_questions.0.question_text', self::CORRECTED_QUESTION);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staff)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson(
                "/api/rounds/patients/{$this->roundPatientUuid}/patient-question-threads/{$this->thread->thread_uuid}/promote",
                [
                    'message_uuid' => (string) $this->message->message_uuid,
                    'thread_version' => $this->thread->refresh()->version,
                ],
            )
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'rounds_policy');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staff)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson(
                "/api/rounds/patients/{$this->roundPatientUuid}/patient-question-threads/{$this->thread->thread_uuid}/promote",
                [
                    'message_uuid' => (string) $correction->message_uuid,
                    'thread_version' => $this->thread->refresh()->version,
                ],
            )
            ->assertCreated()
            ->assertJsonPath('data.questions.0.question_text', self::CORRECTED_QUESTION);

        $promotion = RoundQuestionPromotion::query()->firstOrFail();
        $this->assertSame($correction->getKey(), $promotion->source_message_id);
        $this->assertSame(self::CORRECTED_QUESTION, RoundQuestion::query()->firstOrFail()->question_text);
        $this->assertDatabaseCount('patient_communications.round_question_promotions', 1);
    }

    /** @return array{0: PatientEncounterAccessGrant, 1: PatientMessageThread, 2: PatientMessage} */
    private function createPatientQuestionFixture(): array
    {
        $encounter = $this->roundsEncounters['ROUNDS-PAT-ROUTINE'];
        $principal = PatientPrincipal::query()->create([
            'principal_uuid' => (string) Str::uuid7(),
            'principal_type' => 'patient',
            'display_name' => 'Rounds Question Test Patient',
            'email' => 'rounds-question+'.Str::lower(Str::random(10)).'@example.test',
            'password' => bcrypt('NotARealPatient1!'),
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
            'grant_reason' => 'Automated patient rounds-question test.',
            'version' => 1,
        ]);
        $hmac = $this->app->make(PatientHmac::class);
        $pool = ResponsibilityPool::query()->create([
            'pool_uuid' => (string) Str::uuid7(),
            'pool_key_digest' => $hmac->digest(
                'messaging-pool-ref',
                self::POLICY_VERSION.'|test.unit.care-coordination',
            ),
            'topic_code' => 'rounds_question',
            'display_name' => 'Rounds question test team',
            'routing_policy_version' => self::POLICY_VERSION,
            'scope_type' => 'unit',
            'unit_id' => $this->roundsUnit->getKey(),
            'status' => 'active',
            'response_target_minutes' => 30,
            'escalation_target_minutes' => 60,
        ]);
        PoolMembership::query()->create([
            'membership_uuid' => (string) Str::uuid7(),
            'responsibility_pool_id' => $pool->getKey(),
            'staff_user_id' => $this->staff->getKey(),
            'membership_role' => 'supervisor',
            'availability_state' => 'active',
            'can_claim' => true,
            'can_reply' => true,
            'can_reroute' => true,
            'can_close' => true,
            'effective_from' => now()->subMinute(),
        ]);
        $thread = PatientMessageThread::query()->create([
            'thread_uuid' => (string) Str::uuid7(),
            'access_grant_id' => $grant->getKey(),
            'opened_by_principal_id' => $principal->getKey(),
            'topic_code' => 'rounds_question',
            'topic_label' => 'Question for care-team rounds',
            'topic_description' => 'A non-urgent question for a care-team conversation.',
            'status' => 'open',
            'ownership_state' => 'awaiting_team',
            'routing_policy_version' => self::POLICY_VERSION,
            'expected_response_window' => 'Within one test hour.',
            'urgent_guidance_version' => 'test-guidance-v1',
            'responsibility_pool_ref_digest' => $pool->pool_key_digest,
            'creation_idempotency_key_digest' => $hmac->digest('test-thread-key', (string) Str::uuid7()),
            'creation_request_payload_digest' => $hmac->digest('test-thread-payload', (string) Str::uuid7()),
            'version' => 1,
            'last_message_at' => now(),
        ]);
        $messageUuid = (string) Str::uuid7();
        $cipher = $this->app->make(PatientMessageCipher::class);
        $message = PatientMessage::query()->create([
            'message_uuid' => $messageUuid,
            'message_thread_id' => $thread->getKey(),
            'sender_type' => 'patient',
            'sender_principal_id' => $principal->getKey(),
            'visibility' => 'patient_visible',
            'message_kind' => 'message',
            'encrypted_body' => $cipher->encrypt(
                self::QUESTION,
                'test-rounds-question-key-v1',
                $cipher->contextFor((string) $thread->thread_uuid, $messageUuid),
            ),
            'encryption_key_version' => 'test-rounds-question-key-v1',
            'body_digest' => $hmac->digest('messaging-body', self::QUESTION),
            'body_character_count' => mb_strlen(self::QUESTION),
            'delivery_state' => 'routed',
            'sent_at' => now(),
        ]);
        $outbox = PatientNotificationOutbox::query()->create([
            'outbox_uuid' => (string) Str::uuid7(),
            'principal_id' => $principal->getKey(),
            'access_grant_id' => $grant->getKey(),
            'aggregate_type' => 'patient_message_thread',
            'aggregate_uuid' => (string) $thread->thread_uuid,
            'event_type' => 'patient.messaging.thread_opened',
            'destination' => 'staff_inbox',
            'routing_metadata' => ['schema_version' => 1],
            'idempotency_key_digest' => $hmac->digest('test-outbox-key', (string) Str::uuid7()),
            'available_at' => now(),
            'occurred_at' => now(),
        ]);
        ThreadWorkItem::query()->create([
            'work_item_uuid' => (string) Str::uuid7(),
            'message_thread_id' => $thread->getKey(),
            'access_grant_id' => $grant->getKey(),
            'responsibility_pool_id' => $pool->getKey(),
            'status' => 'open',
            'ownership_state' => 'pool_owned',
            'source_thread_version' => 1,
            'row_version' => 1,
            'last_outbox_id' => $outbox->getKey(),
            'first_routed_at' => now(),
            'due_at' => now()->addMinutes(30),
            'escalate_at' => now()->addMinutes(60),
            'last_message_at' => now(),
        ]);

        return [$grant, $thread, $message];
    }

    private function appendCorrectionToQuestion(): PatientMessage
    {
        $messageUuid = (string) Str::uuid7();
        $cipher = $this->app->make(PatientMessageCipher::class);
        $hmac = $this->app->make(PatientHmac::class);
        $correction = PatientMessage::query()->create([
            'message_uuid' => $messageUuid,
            'message_thread_id' => $this->thread->getKey(),
            'sender_type' => 'patient',
            'sender_principal_id' => $this->grant->principal_id,
            'visibility' => 'patient_visible',
            'message_kind' => 'correction',
            'relates_to_message_id' => $this->message->getKey(),
            'encrypted_body' => $cipher->encrypt(
                self::CORRECTED_QUESTION,
                'test-rounds-question-key-v1',
                $cipher->contextFor((string) $this->thread->thread_uuid, $messageUuid),
            ),
            'encryption_key_version' => 'test-rounds-question-key-v1',
            'body_digest' => $hmac->digest('messaging-body', self::CORRECTED_QUESTION),
            'body_character_count' => mb_strlen(self::CORRECTED_QUESTION),
            'delivery_state' => 'routed',
            'sent_at' => now()->addSecond(),
        ]);
        $this->thread->forceFill([
            'version' => 2,
            'last_message_at' => $correction->sent_at,
        ])->save();
        ThreadWorkItem::query()
            ->where('message_thread_id', $this->thread->getKey())
            ->update([
                'source_thread_version' => 2,
                'last_message_at' => $correction->sent_at,
            ]);

        return $correction;
    }

    private function configureBridge(): void
    {
        config([
            'rounds.patient_question_bridge_enabled' => true,
            'hummingbird-patient.enabled' => true,
            'hummingbird-patient.features.messaging' => true,
            'hummingbird-patient.features.rounds_questions' => true,
            'hummingbird-patient.messaging' => [
                'governance_status' => 'approved',
                'policy_version' => self::POLICY_VERSION,
                'urgent_guidance_version' => 'test-guidance-v1',
                'urgent_guidance_text' => 'For immediate help, use the approved bedside route.',
                'default_response_window' => 'Within one test hour.',
                'encryption_key_version' => 'test-rounds-question-key-v1',
                'handoff_consumer' => RoundsQuestionTestHandoffReadiness::class,
                'topics' => [
                    'rounds_question' => [
                        'label' => 'Question for care-team rounds',
                        'description' => 'Share a non-urgent question your care team may review before a care conversation.',
                        'responsibility_pool_key' => 'test.unit.care-coordination',
                    ],
                ],
            ],
            'hummingbird-patient.staff_messaging' => [
                'enabled' => true,
                'governance_status' => 'approved',
            ],
        ]);
    }
}

class RoundsQuestionTestHandoffReadiness implements PatientMessageHandoffReadiness
{
    public function readyForPolicy(string $policyVersion): bool
    {
        return $policyVersion === 'test-rounds-question-policy-v1';
    }

    public function routableForGrant(
        string $policyVersion,
        string $topicCode,
        string $responsibilityPoolKey,
        PatientEncounterAccessGrant $grant,
    ): bool {
        return $policyVersion === 'test-rounds-question-policy-v1'
            && $topicCode === 'rounds_question'
            && $responsibilityPoolKey === 'test.unit.care-coordination'
            && $grant->exists;
    }
}
