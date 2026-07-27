<?php

namespace Tests\Feature\Patient;

use App\Models\Encounter;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientMessage;
use App\Models\Patient\PatientMessageDeliveryReceipt;
use App\Models\Patient\PatientMessageRoutingEvent;
use App\Models\Patient\PatientMessageThread;
use App\Models\Patient\PatientPrincipal;
use App\Models\Patient\PatientSession;
use App\Models\PatientCommunication\PoolMembership;
use App\Models\PatientCommunication\ResponsibilityPool;
use App\Models\PatientCommunication\StaffActionEvent;
use App\Models\PatientCommunication\ThreadWorkItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\Patient\Messaging\DatabasePatientMessageHandoffConsumer;
use App\Services\Patient\Messaging\PatientCommunicationEscalationService;
use App\Services\Patient\Messaging\StaffPatientCommunicationFailure;
use App\Services\Patient\PatientHmac;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class PatientCommunicationEscalationServiceTest extends TestCase
{
    use RefreshDatabase;

    private const POLICY_VERSION = 'test-escalation-policy-v1';

    private const GUIDANCE_VERSION = 'test-escalation-guidance-v1';

    private const POOL_KEY = 'test.unit.care-team';

    private const PATIENT_QUESTION = 'Could someone explain what happens next in my care?';

    private const STAFF_REPLY = 'Your care team will explain the next step during afternoon rounds.';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_disabled_or_unapproved_staff_messaging_fails_closed(): void
    {
        $service = $this->app->make(PatientCommunicationEscalationService::class);

        foreach ([
            ['enabled' => false, 'governance_status' => 'approved'],
            ['enabled' => true, 'governance_status' => 'draft_requires_approval'],
        ] as $staffMessaging) {
            config(['hummingbird-patient.staff_messaging' => $staffMessaging]);

            try {
                $service->escalateDue();
                $this->fail('Escalation ran without an enabled and approved staff-messaging policy.');
            } catch (StaffPatientCommunicationFailure $failure) {
                $this->assertSame('communications_unavailable', $failure->errorCode);
                $this->assertSame(503, $failure->httpStatus);
                $this->assertSame(
                    'Patient communications are temporarily unavailable.',
                    $failure->getMessage(),
                );
            }
        }
    }

    public function test_due_unanswered_work_escalates_both_projections_and_appends_content_free_facts_once(): void
    {
        Carbon::setTestNow('2026-07-19 16:00:00 America/New_York');
        $fixture = $this->routedCommunication();
        $thread = $fixture['thread']->fresh();
        $workItem = $fixture['work_item']->fresh();
        $latestPatientMessage = PatientMessage::query()
            ->where('message_thread_id', $thread->getKey())
            ->latest('message_id')
            ->firstOrFail();

        $this->makeDue($workItem);
        $threadVersion = (int) $thread->version;
        $workItemVersion = (int) $workItem->row_version;
        $staffEventCount = StaffActionEvent::query()->count();
        $routingEventCount = PatientMessageRoutingEvent::query()->count();
        $receiptCount = PatientMessageDeliveryReceipt::query()->count();

        $this->assertSame(
            ['selected' => 1, 'escalated' => 1, 'skipped' => 0, 'failed' => 0],
            $this->app->make(PatientCommunicationEscalationService::class)->escalateDue(25),
        );

        $thread = $thread->fresh();
        $workItem = $workItem->fresh();
        $this->assertSame('open', $thread->status);
        $this->assertSame('escalated', $thread->ownership_state);
        $this->assertSame($threadVersion + 1, (int) $thread->version);
        $this->assertSame('open', $workItem->status);
        $this->assertSame('escalated', $workItem->ownership_state);
        $this->assertSame($workItemVersion + 1, (int) $workItem->row_version);
        $this->assertSame((int) $thread->version, (int) $workItem->source_thread_version);

        $staffEvent = StaffActionEvent::query()
            ->where('thread_work_item_id', $workItem->getKey())
            ->where('event_type', 'escalated')
            ->sole();
        $routingEvent = PatientMessageRoutingEvent::query()
            ->where('message_thread_id', $thread->getKey())
            ->where('event_type', 'escalated')
            ->sole();
        $receipt = PatientMessageDeliveryReceipt::query()
            ->where('message_id', $latestPatientMessage->getKey())
            ->where('receipt_type', 'escalated')
            ->sole();

        $this->assertSame($staffEventCount + 1, StaffActionEvent::query()->count());
        $this->assertSame($routingEventCount + 1, PatientMessageRoutingEvent::query()->count());
        $this->assertSame($receiptCount + 1, PatientMessageDeliveryReceipt::query()->count());
        $this->assertNull($staffEvent->actor_user_id);
        $this->assertSame($workItem->responsibility_pool_id, $staffEvent->from_pool_id);
        $this->assertSame($workItem->responsibility_pool_id, $staffEvent->to_pool_id);
        $this->assertSame('response_sla_exceeded', $staffEvent->reason_code);
        $this->assertSame('escalated', $staffEvent->patient_visible_state);
        $this->assertEquals([
            'schema_version' => 1,
            'content_included' => false,
            'source' => 'response_sla_monitor',
        ], $staffEvent->metadata);
        $this->assertSame('system', $routingEvent->actor_type);
        $this->assertSame('response_sla_exceeded', $routingEvent->reason_code);
        $this->assertSame('escalated', $routingEvent->patient_visible_state);
        $this->assertSame([
            'schema_version' => 1,
            'content_included' => false,
        ], $routingEvent->metadata);
        $this->assertSame('system', $receipt->actor_type);
        $this->assertSame('escalated', $receipt->patient_visible_state);

        $encodedFacts = json_encode([
            'staff_event' => $staffEvent->getAttributes(),
            'routing_event' => $routingEvent->getAttributes(),
            'receipt' => $receipt->getAttributes(),
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::PATIENT_QUESTION, $encodedFacts);
        $this->assertStringNotContainsString('encrypted_body', $encodedFacts);
        $this->assertStringNotContainsString('message_body', $encodedFacts);
        $this->assertAppendOnly($staffEvent);
        $this->assertAppendOnly($routingEvent);
        $this->assertAppendOnly($receipt);

        $this->assertSame(
            ['selected' => 0, 'escalated' => 0, 'skipped' => 0, 'failed' => 0],
            $this->app->make(PatientCommunicationEscalationService::class)->escalateDue(25),
        );
        $this->assertSame($staffEventCount + 1, StaffActionEvent::query()->count());
        $this->assertSame($routingEventCount + 1, PatientMessageRoutingEvent::query()->count());
        $this->assertSame($receiptCount + 1, PatientMessageDeliveryReceipt::query()->count());
    }

    public function test_an_append_only_fact_failure_rolls_back_both_projection_version_changes(): void
    {
        Carbon::setTestNow('2026-07-19 17:00:00 America/New_York');
        $fixture = $this->routedCommunication();
        $thread = $fixture['thread']->fresh();
        $workItem = $fixture['work_item']->fresh();
        $this->makeDue($workItem);
        $threadVersion = (int) $thread->version;
        $workItemVersion = (int) $workItem->row_version;

        $operationDigest = $this->app->make(PatientHmac::class)->digest(
            'messaging-staff-escalation',
            (string) $workItem->work_item_uuid.'|'.
                (string) $workItem->source_thread_version.'|'.
                $workItem->escalate_at->toISOString(),
        );
        $latestPatientMessage = PatientMessage::query()
            ->where('message_thread_id', $thread->getKey())
            ->latest('message_id')
            ->firstOrFail();
        PatientMessageDeliveryReceipt::query()->create([
            'receipt_uuid' => (string) Str::uuid7(),
            'message_id' => $latestPatientMessage->getKey(),
            'receipt_type' => 'routed_to_pool',
            'actor_type' => 'system',
            'actor_ref_digest' => hash('sha256', 'forced-atomicity-collision'),
            'patient_visible_state' => 'delivered',
            'idempotency_key_digest' => $this->app->make(PatientHmac::class)->digest(
                'messaging-receipt',
                $operationDigest,
            ),
            'occurred_at' => now()->subMinute(),
        ]);

        $this->assertSame(
            ['selected' => 1, 'escalated' => 0, 'skipped' => 0, 'failed' => 1],
            $this->app->make(PatientCommunicationEscalationService::class)->escalateDue(),
        );

        $thread = $thread->fresh();
        $workItem = $workItem->fresh();
        $this->assertSame('assigned', $thread->ownership_state);
        $this->assertSame($threadVersion, (int) $thread->version);
        $this->assertSame('pool_owned', $workItem->ownership_state);
        $this->assertSame($workItemVersion, (int) $workItem->row_version);
        $this->assertSame(
            0,
            StaffActionEvent::query()
                ->where('thread_work_item_id', $workItem->getKey())
                ->where('event_type', 'escalated')
                ->count(),
        );
        $this->assertSame(
            0,
            PatientMessageRoutingEvent::query()
                ->where('message_thread_id', $thread->getKey())
                ->where('event_type', 'escalated')
                ->count(),
        );
    }

    public function test_not_yet_due_responded_and_closed_items_are_not_selected(): void
    {
        Carbon::setTestNow('2026-07-19 18:00:00 America/New_York');
        $fixture = $this->routedCommunication();
        $thread = $fixture['thread']->fresh();
        $workItem = $fixture['work_item']->fresh();
        $service = $this->app->make(PatientCommunicationEscalationService::class);

        $this->assertTrue($workItem->escalate_at->isFuture());
        $this->assertSame(
            ['selected' => 0, 'escalated' => 0, 'skipped' => 0, 'failed' => 0],
            $service->escalateDue(),
        );

        $this->makeDue($workItem);
        $thread->forceFill(['ownership_state' => 'responded'])->save();
        $workItem->forceFill([
            'assigned_user_id' => $fixture['staff']->getKey(),
            'ownership_state' => 'responded',
            'responded_at' => now()->subMinute(),
        ])->save();
        $this->assertSame(
            ['selected' => 0, 'escalated' => 0, 'skipped' => 0, 'failed' => 0],
            $service->escalateDue(),
        );

        $thread->forceFill([
            'status' => 'closed',
            'ownership_state' => 'closed',
            'closed_at' => now(),
            'close_reason_code' => 'question_answered',
        ])->save();
        $workItem->forceFill([
            'status' => 'closed',
            'ownership_state' => 'closed',
            'closed_at' => now(),
            'close_reason_code' => 'question_answered',
        ])->save();
        $this->assertSame(
            ['selected' => 0, 'escalated' => 0, 'skipped' => 0, 'failed' => 0],
            $service->escalateDue(),
        );
        $this->assertSame(
            0,
            StaffActionEvent::query()
                ->where('thread_work_item_id', $workItem->getKey())
                ->where('event_type', 'escalated')
                ->count(),
        );
    }

    public function test_patient_follow_up_after_staff_response_resets_targets_and_requires_a_new_reply_before_close(): void
    {
        Carbon::setTestNow('2026-07-19 19:00:00 America/New_York');
        $fixture = $this->routedCommunication();
        $workItem = $fixture['work_item'];
        $staffToken = $this->staffToken($fixture['staff']);

        $claim = $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/claim", [
                'work_item_version' => $workItem->row_version,
                'thread_version' => $workItem->thread->version,
            ])
            ->assertOk()
            ->json('data');
        $firstReply = $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/reply", [
                'work_item_version' => $claim['work_item']['work_item_version'],
                'thread_version' => $claim['work_item']['thread_version'],
                'message' => self::STAFF_REPLY,
                'client_message_uuid' => (string) Str::uuid7(),
            ])
            ->assertOk()
            ->assertJsonPath('data.work_item.ownership_state', 'responded')
            ->json('data');

        $respondedWorkItem = $workItem->fresh();
        $firstDueAt = $respondedWorkItem->due_at;
        $firstEscalateAt = $respondedWorkItem->escalate_at;
        $this->assertNotNull($respondedWorkItem->responded_at);

        Carbon::setTestNow(now()->addMinutes(10));
        $this->assertSame(
            ['selected' => 0, 'delivered' => 0, 'failed' => 0],
            $this->app->make(DatabasePatientMessageHandoffConsumer::class)
                ->consumeBatch('escalation-follow-up-worker'),
        );
        $this->app['auth']->forgetGuards();
        $followUp = $this->withToken($fixture['patient_token'])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/patient/v1/threads/{$fixture['thread']->thread_uuid}/messages", [
                'thread_version' => $firstReply['work_item']['thread_version'],
                'message' => 'I have one more question after that update.',
                'client_message_uuid' => (string) Str::uuid7(),
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertCreated()
            ->assertJsonPath('data.thread.ownership_state', 'awaiting_team')
            ->json('data');

        $this->assertSame(
            ['selected' => 1, 'delivered' => 1, 'failed' => 0],
            $this->app->make(DatabasePatientMessageHandoffConsumer::class)
                ->consumeBatch('escalation-follow-up-worker'),
        );

        $workItem = $workItem->fresh(['thread']);
        $this->assertSame('assigned', $workItem->ownership_state);
        $this->assertSame('assigned', $workItem->thread->ownership_state);
        $this->assertSame((int) $workItem->thread->version, (int) $workItem->source_thread_version);
        $this->assertNull($workItem->responded_at);
        $this->assertTrue($workItem->due_at->greaterThan($firstDueAt));
        $this->assertTrue($workItem->escalate_at->greaterThan($firstEscalateAt));
        $this->assertTrue($workItem->due_at->isFuture());
        $this->assertTrue($workItem->escalate_at->isFuture());
        $this->assertGreaterThan(
            (int) $followUp['thread']['version'],
            (int) $workItem->thread->version,
        );

        $this->app['auth']->forgetGuards();
        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/close", [
                'work_item_version' => $workItem->row_version,
                'thread_version' => $workItem->thread->version,
                'reason_code' => 'question_answered',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'response_required');

        $secondReply = $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/reply", [
                'work_item_version' => $workItem->row_version,
                'thread_version' => $workItem->thread->version,
                'message' => 'Yes. We will also answer that follow-up before closing this conversation.',
                'client_message_uuid' => (string) Str::uuid7(),
            ])
            ->assertOk()
            ->assertJsonPath('data.work_item.ownership_state', 'responded')
            ->json('data');
        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/close", [
                'work_item_version' => $secondReply['work_item']['work_item_version'],
                'thread_version' => $secondReply['work_item']['thread_version'],
                'reason_code' => 'question_answered',
            ])
            ->assertOk()
            ->assertJsonPath('data.work_item.status', 'closed');
    }

    public function test_both_scheduler_commands_require_bounded_once_execution_and_validate_limits(): void
    {
        $this->artisan('hummingbird:escalate-patient-communications')
            ->expectsOutputToContain('Only bounded --once execution is supported; run it under the approved scheduler.')
            ->assertExitCode(Command::INVALID);
        $this->artisan('hummingbird:consume-patient-message-handoff')
            ->expectsOutputToContain('Only bounded --once execution is supported; run it under the approved scheduler/supervisor.')
            ->assertExitCode(Command::INVALID);

        foreach (['0', '501', 'not-an-integer'] as $invalidLimit) {
            $this->artisan('hummingbird:escalate-patient-communications', [
                '--once' => true,
                '--limit' => $invalidLimit,
            ])
                ->expectsOutputToContain('The batch limit must be an integer from 1 through 500.')
                ->assertExitCode(Command::INVALID);
            $this->artisan('hummingbird:consume-patient-message-handoff', [
                '--once' => true,
                '--limit' => $invalidLimit,
            ])
                ->expectsOutputToContain('The batch limit must be an integer from 1 through 500.')
                ->assertExitCode(Command::INVALID);
        }
    }

    public function test_both_scheduler_commands_emit_bounded_success_summaries(): void
    {
        $this->mock(PatientCommunicationEscalationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('escalateDue')
                ->once()
                ->with(25)
                ->andReturn(['selected' => 3, 'escalated' => 2, 'skipped' => 1, 'failed' => 0]);
        });
        $this->artisan('hummingbird:escalate-patient-communications', [
            '--once' => true,
            '--limit' => '25',
        ])
            ->expectsOutputToContain(
                'Patient-communication escalation complete: selected=3 escalated=2 skipped=1 failed=0.',
            )
            ->assertSuccessful();

        $this->mock(DatabasePatientMessageHandoffConsumer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('consumeBatch')
                ->once()
                ->with('test-scheduler', 25)
                ->andReturn(['selected' => 4, 'delivered' => 4, 'failed' => 0]);
        });
        $this->artisan('hummingbird:consume-patient-message-handoff', [
            '--once' => true,
            '--limit' => '25',
            '--worker' => 'test-scheduler',
        ])
            ->expectsOutputToContain(
                'Patient-message handoff batch complete: selected=4 delivered=4 failed=0.',
            )
            ->assertSuccessful();
    }

    public function test_both_scheduler_commands_expose_only_stable_failure_codes(): void
    {
        $secretFailure = 'database endpoint and patient text must never reach command output';
        $this->mock(PatientCommunicationEscalationService::class, function (MockInterface $mock) use ($secretFailure): void {
            $mock->shouldReceive('escalateDue')
                ->once()
                ->andThrow(new RuntimeException($secretFailure));
        });
        $this->artisan('hummingbird:escalate-patient-communications', [
            '--once' => true,
            '--limit' => '100',
        ])
            ->expectsOutputToContain('patient_communication_escalation_failed')
            ->doesntExpectOutputToContain($secretFailure)
            ->assertExitCode(Command::FAILURE);

        $this->mock(DatabasePatientMessageHandoffConsumer::class, function (MockInterface $mock) use ($secretFailure): void {
            $mock->shouldReceive('consumeBatch')
                ->once()
                ->andThrow(new RuntimeException($secretFailure));
        });
        $this->artisan('hummingbird:consume-patient-message-handoff', [
            '--once' => true,
            '--limit' => '100',
            '--worker' => 'test-scheduler',
        ])
            ->expectsOutputToContain('patient_message_handoff_failed')
            ->doesntExpectOutputToContain($secretFailure)
            ->assertExitCode(Command::FAILURE);
    }

    /**
     * @return array{
     *   unit: Unit,
     *   staff: User,
     *   pool: ResponsibilityPool,
     *   membership: PoolMembership,
     *   grant: PatientEncounterAccessGrant,
     *   patient_token: string,
     *   thread: PatientMessageThread,
     *   work_item: ThreadWorkItem
     * }
     */
    private function routedCommunication(): array
    {
        $unit = Unit::query()->create([
            'name' => 'Escalation Test Unit',
            'abbreviation' => 'ETU',
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
        $this->configureMessaging($unit);
        $pool = $this->createPool($unit);
        $membership = $this->createMembership($pool, $staff);
        $consumer = $this->app->make(DatabasePatientMessageHandoffConsumer::class);
        $this->assertSame(
            ['selected' => 0, 'delivered' => 0, 'failed' => 0],
            $consumer->consumeBatch('escalation-test-worker'),
        );

        [$grant, $patientToken] = $this->patientForEncounter($unit);
        $threadPayload = $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->withToken($patientToken)
            ->postJson("/api/patient/v1/encounters/{$grant->encounter_uuid}/threads", [
                'topic_code' => 'care_question',
                'message' => self::PATIENT_QUESTION,
                'client_message_uuid' => (string) Str::uuid7(),
                'urgent_guidance_version' => self::GUIDANCE_VERSION,
            ])
            ->assertCreated()
            ->json('data.thread');

        $this->assertSame(
            ['selected' => 1, 'delivered' => 1, 'failed' => 0],
            $consumer->consumeBatch('escalation-test-worker'),
        );

        $thread = PatientMessageThread::query()
            ->where('thread_uuid', $threadPayload['thread_uuid'])
            ->firstOrFail();
        $workItem = ThreadWorkItem::query()
            ->with('thread')
            ->where('message_thread_id', $thread->getKey())
            ->firstOrFail();

        return [
            'unit' => $unit,
            'staff' => $staff,
            'pool' => $pool,
            'membership' => $membership,
            'grant' => $grant,
            'patient_token' => $patientToken,
            'thread' => $thread,
            'work_item' => $workItem,
        ];
    }

    private function configureMessaging(Unit $unit): void
    {
        config([
            'hummingbird.patient_context.signing_key' => str_repeat('s', 32),
            'hummingbird.patient_context.ttl_minutes' => 15,
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

    private function createPool(Unit $unit): ResponsibilityPool
    {
        $digest = $this->app->make(PatientHmac::class)->digest(
            'messaging-pool-ref',
            self::POLICY_VERSION.'|'.self::POOL_KEY,
        );

        return ResponsibilityPool::query()->create([
            'pool_uuid' => (string) Str::uuid7(),
            'pool_key_digest' => $digest,
            'topic_code' => 'care_question',
            'display_name' => 'Escalation Test Care Team',
            'routing_policy_version' => self::POLICY_VERSION,
            'scope_type' => 'unit',
            'unit_id' => $unit->getKey(),
            'status' => 'active',
            'response_target_minutes' => 30,
            'escalation_target_minutes' => 60,
        ]);
    }

    private function createMembership(ResponsibilityPool $pool, User $staff): PoolMembership
    {
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
        ]);
    }

    /** @return array{0: PatientEncounterAccessGrant, 1: string} */
    private function patientForEncounter(Unit $unit): array
    {
        $encounter = Encounter::query()->create([
            'patient_ref' => 'escalation-test-'.Str::lower(Str::random(12)),
            'unit_id' => $unit->getKey(),
            'admitted_at' => now()->subDay(),
            'acuity_tier' => 2,
            'status' => 'active',
            'is_deleted' => false,
        ]);
        $principal = PatientPrincipal::query()->create([
            'principal_uuid' => (string) Str::uuid7(),
            'principal_type' => 'patient',
            'display_name' => 'Escalation Test Patient',
            'email' => 'escalation+'.Str::lower(Str::random(10)).'@example.test',
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
            'grant_reason' => 'Automated patient communication escalation test.',
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

    private function staffToken(User $staff): string
    {
        $token = $staff->createToken(
            'staff-patient-communications-escalation-test',
            ['mobile:read', 'mobile:act'],
        )->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $token;
    }

    private function makeDue(ThreadWorkItem $workItem): void
    {
        $workItem->forceFill([
            'first_routed_at' => now()->subHours(2),
            'due_at' => now()->subMinutes(90),
            'escalate_at' => now()->subHour(),
        ])->save();
        $workItem->refresh();
    }

    private function assertAppendOnly(Model $model): void
    {
        try {
            $model->forceFill(['patient_visible_state' => 'tampered'])->save();
            $this->fail($model::class.' allowed an immutable fact to be updated.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
    }
}
