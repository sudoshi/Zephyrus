<?php

namespace Tests\Feature\Patient;

use App\Models\Audit\UserEvent;
use App\Models\Encounter;
use App\Models\Facility\FacilitySpace;
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
use App\Services\Patient\PatientHmac;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class StaffPatientCommunicationApiTest extends TestCase
{
    use RefreshDatabase;

    private const POLICY_VERSION = 'test-staff-api-policy-v1';

    private const GUIDANCE_VERSION = 'test-staff-api-guidance-v1';

    private const POOL_KEY = 'test.unit.care-team';

    private const PATIENT_QUESTION = 'Could someone explain what the next care step will be?';

    private const STAFF_REPLY = 'Your care team will review the next step with you this afternoon.';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_feature_disabled_returns_a_generic_not_found_for_reads_and_writes(): void
    {
        $staff = User::factory()->create([
            'role' => 'charge_nurse',
            'is_active' => true,
        ]);
        $token = $this->staffToken($staff);
        $workItemUuid = (string) Str::uuid7();

        foreach ([
            'hummingbird-patient.enabled',
            'hummingbird-patient.features.messaging',
            'hummingbird-patient.staff_messaging.enabled',
        ] as $flag) {
            config([
                'hummingbird-patient.enabled' => true,
                'hummingbird-patient.features.messaging' => true,
                'hummingbird-patient.staff_messaging.enabled' => true,
                'hummingbird-patient.staff_messaging.governance_status' => 'approved',
                $flag => false,
            ]);

            $this->withToken($token)
                ->getJson('/api/mobile/v1/patient-communications/inbox')
                ->assertNotFound()
                ->assertExactJson([
                    'error' => [
                        'code' => 'not_found',
                        'message' => 'The requested resource is not available.',
                    ],
                ]);
        }

        config([
            'hummingbird-patient.enabled' => true,
            'hummingbird-patient.features.messaging' => true,
            'hummingbird-patient.staff_messaging.enabled' => true,
            'hummingbird-patient.staff_messaging.governance_status' => 'draft_requires_approval',
        ]);

        $this->withToken($token)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItemUuid}/claim", [
                'work_item_version' => 1,
                'thread_version' => 1,
            ])
            ->assertNotFound()
            ->assertExactJson([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'The requested resource is not available.',
                ],
            ]);
    }

    public function test_capability_token_ability_and_effective_membership_are_independent_requirements(): void
    {
        $fixture = $this->routedCommunication();
        $workItem = $fixture['work_item'];

        $memberWithoutCapability = User::factory()->create([
            'role' => 'transport',
            'is_active' => true,
        ]);
        $this->createMembership($fixture['pool'], $memberWithoutCapability);

        $this->withToken($this->staffToken($memberWithoutCapability))
            ->getJson('/api/mobile/v1/patient-communications/inbox')
            ->assertForbidden();

        $eligibleNonMember = User::factory()->create([
            'role' => 'charge_nurse',
            'is_active' => true,
        ]);
        $eligibleNonMemberToken = $this->staffToken($eligibleNonMember);

        $this->withToken($eligibleNonMemberToken)
            ->getJson('/api/mobile/v1/patient-communications/inbox')
            ->assertOk()
            ->assertJsonPath('data.count', 0)
            ->assertJsonCount(0, 'data.items');

        $this->withToken($eligibleNonMemberToken)
            ->getJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $readOnlyToken = $this->staffToken($fixture['staff'], ['mobile:read']);
        $this->withToken($readOnlyToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/claim", [
                'work_item_version' => $workItem->row_version,
                'thread_version' => $workItem->thread->version,
            ])
            ->assertForbidden();

        $this->assertSame('pool_owned', $workItem->fresh()->ownership_state);
        $this->assertNull($workItem->fresh()->assigned_user_id);
    }

    public function test_inbox_is_content_free_while_authorized_detail_decrypts_only_canonical_messages(): void
    {
        $fixture = $this->routedCommunication();
        $workItem = $fixture['work_item'];
        $staffToken = $this->staffToken($fixture['staff']);

        $inbox = $this->withToken($staffToken)
            ->getJson('/api/mobile/v1/patient-communications/inbox')
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.work_item_uuid', (string) $workItem->work_item_uuid)
            ->assertJsonPath('data.items.0.topic.code', 'care_question')
            ->assertJsonPath('data.items.0.pool.label', 'Staff API Test Care Team')
            ->assertJsonPath('data.items.0.ownership_state', 'pool_owned')
            ->assertJsonPath('meta.classification', 'patient_communication_restricted')
            ->assertJsonPath('meta.offline_writes_allowed', false)
            ->assertJsonMissingPath('data.items.0.messages');

        $this->assertStringNotContainsString(self::PATIENT_QUESTION, $inbox->getContent());
        $this->assertStringNotContainsString('encrypted_body', $inbox->getContent());
        $this->assertStringNotContainsString('responsibility_pool_ref_digest', $inbox->getContent());

        $detail = $this->withToken($staffToken)
            ->getJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}")
            ->assertOk()
            ->assertJsonPath('data.work_item_uuid', (string) $workItem->work_item_uuid)
            ->assertJsonPath('data.thread_uuid', (string) $fixture['thread']->thread_uuid)
            ->assertJsonPath('data.messages.0.sender_display_role', 'Patient')
            ->assertJsonPath('data.messages.0.body', self::PATIENT_QUESTION)
            ->assertJsonPath('data.messages.0.delivery_state', 'assigned')
            ->assertJsonPath('data.has_earlier_messages', false);

        foreach ([
            'encrypted_body',
            'body_digest',
            'sender_actor_ref_digest',
            'sender_principal_id',
            'responsibility_pool_ref_digest',
            'routing_policy_version',
        ] as $forbiddenField) {
            $this->assertStringNotContainsString($forbiddenField, $detail->getContent());
        }

        $this->assertDatabaseHas('audit.user_events', [
            'actor_user_id' => $fixture['staff']->getKey(),
            'action' => 'patient_communications.inbox_viewed',
            'category' => 'access',
            'outcome' => 'success',
            'source_surface' => 'hummingbird',
        ]);
        $this->assertDatabaseHas('audit.user_events', [
            'actor_user_id' => $fixture['staff']->getKey(),
            'action' => 'patient_communications.thread_viewed',
            'target_type' => 'patient_message_thread',
            'target_id' => (string) $workItem->work_item_uuid,
            'outcome' => 'success',
        ]);
    }

    public function test_staff_disclosure_fails_closed_before_reconciliation_for_inactive_or_moved_encounters(): void
    {
        $fixture = $this->routedCommunication();
        $workItem = $fixture['work_item'];
        $staffToken = $this->staffToken($fixture['staff']);
        $encounter = Encounter::query()->findOrFail($fixture['grant']->source_encounter_id);

        $assertHidden = function () use ($staffToken, $workItem): void {
            $this->app['auth']->forgetGuards();
            $inbox = $this->withToken($staffToken)
                ->getJson('/api/mobile/v1/patient-communications/inbox')
                ->assertOk()
                ->assertJsonPath('data.count', 0)
                ->assertJsonCount(0, 'data.items');
            $this->assertStringNotContainsString(self::PATIENT_QUESTION, $inbox->getContent());

            $this->app['auth']->forgetGuards();
            $detail = $this->withToken($staffToken)
                ->getJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}")
                ->assertNotFound()
                ->assertJsonPath('error.code', 'not_found');
            $this->assertStringNotContainsString(self::PATIENT_QUESTION, $detail->getContent());
        };

        // Canonical activeness requires all three signals to agree.
        $encounter->forceFill(['status' => 'active', 'discharged_at' => now()])->save();
        $assertHidden();

        $encounter->forceFill([
            'status' => 'active',
            'discharged_at' => null,
            'is_deleted' => true,
        ])->save();
        $assertHidden();

        $encounter->forceFill([
            'status' => 'discharged',
            'discharged_at' => now(),
            'is_deleted' => false,
        ])->save();
        $assertHidden();

        $destinationUnit = Unit::query()->create([
            'name' => 'Staff API Transfer Destination',
            'abbreviation' => 'SATD',
            'type' => 'med_surg',
            'staffed_bed_count' => 10,
            'ratio_floor' => 4,
            'access_standard_minutes' => 120,
            'is_deleted' => false,
        ]);
        config([
            'hummingbird-patient.staff_messaging.pilot_unit_ids' => [
                $fixture['unit']->getKey(),
                $destinationUnit->getKey(),
            ],
        ]);
        $encounter->forceFill([
            'unit_id' => $destinationUnit->getKey(),
            'status' => 'active',
            'discharged_at' => null,
            'is_deleted' => false,
        ])->save();
        $assertHidden();

        $this->app['auth']->forgetGuards();
        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/claim", [
                'work_item_version' => $workItem->row_version,
                'thread_version' => $workItem->thread->version,
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->assertSame('open', $workItem->fresh()->status);
        $this->assertNull($workItem->fresh()->assigned_user_id);
    }

    public function test_claim_reply_and_close_are_replay_safe_and_patient_visible(): void
    {
        $fixture = $this->routedCommunication();
        $workItem = $fixture['work_item'];
        $staffToken = $this->staffToken($fixture['staff']);

        $claimKey = (string) Str::uuid7();
        $claimPayload = [
            'work_item_version' => $workItem->row_version,
            'thread_version' => $workItem->thread->version,
        ];
        $claim = $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', $claimKey)
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/claim", $claimPayload)
            ->assertOk()
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.work_item.assigned_to_me', true)
            ->assertJsonPath('data.work_item.ownership_state', 'acknowledged')
            ->assertJsonPath('data.work_item.work_item_version', 2)
            ->assertJsonPath('data.work_item.thread_version', 3)
            ->json('data');

        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', $claimKey)
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/claim", $claimPayload)
            ->assertOk()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.work_item.work_item_uuid', (string) $workItem->work_item_uuid);
        $this->assertSame(1, $this->staffEventCount($workItem, 'claimed'));

        $replyKey = (string) Str::uuid7();
        $clientMessageUuid = (string) Str::uuid7();
        $replyPayload = [
            'work_item_version' => $claim['work_item']['work_item_version'],
            'thread_version' => $claim['work_item']['thread_version'],
            'message' => self::STAFF_REPLY,
            'client_message_uuid' => $clientMessageUuid,
        ];
        $reply = $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', $replyKey)
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/reply", $replyPayload)
            ->assertOk()
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.message.sender_display_role', 'Care team')
            ->assertJsonPath('data.message.body', self::STAFF_REPLY)
            ->assertJsonPath('data.work_item.ownership_state', 'responded')
            ->json('data');
        $messageUuid = $reply['message']['message_uuid'];
        $this->assertTrue(Str::isUuid($messageUuid));

        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', $replyKey)
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/reply", $replyPayload)
            ->assertOk()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.message.message_uuid', $messageUuid)
            ->assertJsonPath('data.message.body', self::STAFF_REPLY);
        $this->assertSame(1, $this->staffEventCount($workItem, 'replied'));
        $this->assertSame(1, PatientMessage::query()->where('client_message_uuid', $clientMessageUuid)->count());

        $closeKey = (string) Str::uuid7();
        $closePayload = [
            'work_item_version' => $reply['work_item']['work_item_version'],
            'thread_version' => $reply['work_item']['thread_version'],
            'reason_code' => 'other',
        ];
        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', $closeKey)
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/close", $closePayload)
            ->assertOk()
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.work_item.status', 'closed')
            ->assertJsonPath('data.work_item.ownership_state', 'closed');

        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', $closeKey)
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/close", $closePayload)
            ->assertOk()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.work_item.status', 'closed');
        $this->assertSame(1, $this->staffEventCount($workItem, 'closed'));

        $this->app['auth']->forgetGuards();
        $patientDetail = $this->withToken($fixture['patient_token'])
            ->getJson("/api/patient/v1/threads/{$fixture['thread']->thread_uuid}")
            ->assertOk()
            ->assertJsonPath('data.thread.status', 'closed')
            ->assertJsonPath('data.thread.ownership_state', 'closed')
            ->assertJsonPath('data.thread.close_reason', 'question_answered')
            ->assertJsonPath('data.thread.messages.1.sender_display_role', 'Care team')
            ->assertJsonPath('data.thread.messages.1.body', self::STAFF_REPLY);

        $this->assertStringNotContainsString('other', $patientDetail->getContent());
        $this->assertDatabaseHas('patient_experience.message_delivery_receipts', [
            'receipt_type' => 'team_responded',
            'patient_visible_state' => 'responded',
        ]);
        $this->assertDatabaseHas('patient_experience.message_routing_events', [
            'event_type' => 'closed',
            'patient_visible_state' => 'closed',
        ]);
    }

    public function test_close_requires_a_patient_visible_staff_response(): void
    {
        $fixture = $this->routedCommunication();
        $workItem = $fixture['work_item'];
        $staffToken = $this->staffToken($fixture['staff']);
        $claim = $this->claim($fixture, $staffToken);

        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/close", [
                'work_item_version' => $claim['work_item']['work_item_version'],
                'thread_version' => $claim['work_item']['thread_version'],
                'reason_code' => 'question_answered',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'response_required')
            ->assertJsonPath('error.message', 'Send a patient-visible response before closing this communication.');

        $this->assertSame('open', $workItem->fresh()->status);
        $this->assertSame(0, $this->staffEventCount($workItem, 'closed'));
    }

    public function test_idempotency_keys_and_client_message_uuids_cannot_be_rebound(): void
    {
        $fixture = $this->routedCommunication();
        $workItem = $fixture['work_item'];
        $staffToken = $this->staffToken($fixture['staff']);

        $claimKey = (string) Str::uuid7();
        $claimPayload = [
            'work_item_version' => $workItem->row_version,
            'thread_version' => $workItem->thread->version,
        ];
        $claim = $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', $claimKey)
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/claim", $claimPayload)
            ->assertOk()
            ->json('data');

        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', $claimKey)
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/claim", [
                ...$claimPayload,
                'thread_version' => $claimPayload['thread_version'] + 1,
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');
        $this->assertSame(1, $this->staffEventCount($workItem, 'claimed'));

        $replyKey = (string) Str::uuid7();
        $clientMessageUuid = (string) Str::uuid7();
        $replyPayload = [
            'work_item_version' => $claim['work_item']['work_item_version'],
            'thread_version' => $claim['work_item']['thread_version'],
            'message' => self::STAFF_REPLY,
            'client_message_uuid' => $clientMessageUuid,
        ];
        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', $replyKey)
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/reply", $replyPayload)
            ->assertOk();

        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', $replyKey)
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/reply", [
                ...$replyPayload,
                'message' => 'A different message must not reuse the operation key.',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');

        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/reply", [
                ...$replyPayload,
                'message' => 'A different message must not reuse the client UUID.',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');

        $this->assertSame(1, $this->staffEventCount($workItem, 'replied'));
        $this->assertSame(1, PatientMessage::query()->where('client_message_uuid', $clientMessageUuid)->count());
    }

    public function test_stale_versions_strict_types_and_unknown_properties_fail_without_mutation(): void
    {
        $fixture = $this->routedCommunication();
        $workItem = $fixture['work_item'];
        $staffToken = $this->staffToken($fixture['staff']);
        $claimUrl = "/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/claim";

        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson($claimUrl, [
                'work_item_version' => $workItem->row_version + 1,
                'thread_version' => $workItem->thread->version,
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'stale_version');

        foreach ([
            [
                'work_item_version' => (string) $workItem->row_version,
                'thread_version' => $workItem->thread->version,
            ],
            [
                'work_item_version' => $workItem->row_version,
                'thread_version' => (string) $workItem->thread->version,
            ],
        ] as $payload) {
            $this->withToken($staffToken)
                ->withHeader('Idempotency-Key', (string) Str::uuid7())
                ->postJson($claimUrl, $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(array_key_first(array_filter(
                    $payload,
                    static fn (mixed $value): bool => is_string($value),
                )));
        }

        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson($claimUrl, [
                'work_item_version' => $workItem->row_version,
                'thread_version' => $workItem->thread->version,
                'patient_name' => 'This unsupported property must be rejected.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request');

        $this->assertSame('pool_owned', $workItem->fresh()->ownership_state);
        $this->assertNull($workItem->fresh()->assigned_user_id);
        $this->assertSame(0, $this->staffEventCount($workItem, 'claimed'));
    }

    public function test_reply_and_close_require_exact_json_scalar_types_and_header_idempotency(): void
    {
        $fixture = $this->routedCommunication();
        $workItem = $fixture['work_item'];
        $staffToken = $this->staffToken($fixture['staff']);
        $claim = $this->claim($fixture, $staffToken);
        $replyUrl = "/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/reply";
        $replyBase = [
            'work_item_version' => $claim['work_item']['work_item_version'],
            'thread_version' => $claim['work_item']['thread_version'],
            'message' => self::STAFF_REPLY,
            'client_message_uuid' => (string) Str::uuid7(),
        ];

        $this->withToken($staffToken)
            ->withoutHeader('Idempotency-Key')
            ->postJson($replyUrl, $replyBase)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        foreach ([
            ['message' => ['not', 'a', 'string']],
            ['client_message_uuid' => 12345],
            ['work_item_version' => (string) $replyBase['work_item_version']],
        ] as $invalid) {
            $field = array_key_first($invalid);
            $this->withToken($staffToken)
                ->withHeader('Idempotency-Key', (string) Str::uuid7())
                ->postJson($replyUrl, [...$replyBase, ...$invalid])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson($replyUrl, [
                ...$replyBase,
                'routing_pool' => 'An internal routing hint is never accepted from the client.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request');

        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/close", [
                'work_item_version' => $replyBase['work_item_version'],
                'thread_version' => $replyBase['thread_version'],
                'reason_code' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason_code');

        $this->assertSame(0, $this->staffEventCount($workItem, 'replied'));
        $this->assertSame(1, PatientMessage::query()
            ->where('message_thread_id', $fixture['thread']->getKey())
            ->count());
    }

    public function test_effective_membership_revocation_takes_effect_on_the_next_request(): void
    {
        $fixture = $this->routedCommunication();
        $workItem = $fixture['work_item'];
        $staffToken = $this->staffToken($fixture['staff']);

        $this->withToken($staffToken)
            ->getJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}")
            ->assertOk();

        $fixture['membership']->forceFill([
            'availability_state' => 'ended',
            'effective_until' => now(),
        ])->save();

        $this->withToken($staffToken)
            ->getJson('/api/mobile/v1/patient-communications/inbox')
            ->assertOk()
            ->assertJsonPath('data.count', 0)
            ->assertJsonCount(0, 'data.items');

        $this->withToken($staffToken)
            ->getJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/claim", [
                'work_item_version' => $workItem->row_version,
                'thread_version' => $workItem->thread->version,
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->assertSame('pool_owned', $workItem->fresh()->ownership_state);
        $this->assertNull($workItem->fresh()->assigned_user_id);
    }

    public function test_route_candidates_are_capability_membership_and_policy_gated_without_message_content(): void
    {
        $fixture = $this->routedCommunication();
        $staffToken = $this->staffToken($fixture['staff']);
        $claim = $this->claim($fixture, $staffToken);

        $target = User::factory()->create(['role' => 'bedside_nurse', 'is_active' => true]);
        $targetMembership = $this->createMembership($fixture['pool'], $target, [
            'membership_role' => 'responder',
            'can_reroute' => false,
            'can_close' => false,
        ]);
        $enterprisePool = $this->createPool($fixture['unit'], [
            'scope_type' => 'enterprise',
            'unit_id' => null,
            'display_name' => 'Enterprise Care Team',
        ]);
        $enterpriseResponder = User::factory()->create([
            'role' => 'hospitalist',
            'is_active' => true,
        ]);
        $this->createMembership($enterprisePool, $enterpriseResponder, [
            'membership_role' => 'responder',
            'can_reroute' => false,
            'can_close' => false,
        ]);

        $workItemUuid = (string) $fixture['work_item']->work_item_uuid;
        $response = $this->withToken($staffToken)
            ->getJson("/api/mobile/v1/patient-communications/threads/{$workItemUuid}/route-candidates")
            ->assertOk()
            ->assertJsonPath('data.work_item_uuid', $workItemUuid)
            ->assertJsonPath('data.work_item_version', $claim['work_item']['work_item_version'])
            ->assertJsonPath('data.thread_version', $claim['work_item']['thread_version'])
            ->assertJsonPath('data.actions.can_release', true)
            ->assertJsonPath('data.actions.can_reassign', true)
            ->assertJsonPath('data.actions.can_reroute', true)
            ->assertJsonPath('data.reassign_candidates.0.membership_uuid', (string) $targetMembership->membership_uuid)
            ->assertJsonPath('data.reassign_candidates.0.label', (string) $target->name)
            ->assertJsonPath('data.reassign_candidates.0.membership_role', 'responder')
            ->assertJsonPath('data.reroute_candidates.0.pool_uuid', (string) $enterprisePool->pool_uuid)
            ->assertJsonPath('data.reroute_candidates.0.label', 'Enterprise Care Team')
            ->assertJsonPath('data.reroute_candidates.0.scope_type', 'enterprise')
            ->assertJsonPath('data.reroute_candidates.0.unit', null)
            ->assertJsonPath('meta.classification', 'patient_communication_restricted')
            ->assertJsonPath('meta.offline_writes_allowed', false);

        $this->assertSame(
            ['return_to_team', 'shift_handoff', 'responder_unavailable', 'incorrect_assignment'],
            $response->json('data.reason_options.release.*.code'),
        );
        $this->assertSame(
            ['supervisor_assignment', 'shift_handoff', 'coverage_change', 'workload_balance'],
            $response->json('data.reason_options.reassign.*.code'),
        );
        $this->assertSame(
            ['wrong_team', 'unit_transfer', 'service_change', 'specialty_needed'],
            $response->json('data.reason_options.reroute.*.code'),
        );
        $this->assertLessThanOrEqual(50, count($response->json('data.reassign_candidates')));
        $this->assertLessThanOrEqual(50, count($response->json('data.reroute_candidates')));
        foreach ([
            self::PATIENT_QUESTION,
            'messages',
            'body',
            'user_id',
            'staff_user_id',
            'responsibility_pool_id',
            'pool_key_digest',
            'responsibility_pool_ref_digest',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $response->getContent());
        }

        $nonMember = User::factory()->create(['role' => 'charge_nurse', 'is_active' => true]);
        $this->withToken($this->staffToken($nonMember))
            ->getJson("/api/mobile/v1/patient-communications/threads/{$workItemUuid}/route-candidates")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->assertDatabaseHas('audit.user_events', [
            'actor_user_id' => $fixture['staff']->getKey(),
            'action' => 'patient_communications.route_candidates_viewed',
            'category' => 'access',
            'outcome' => 'success',
        ]);
    }

    public function test_routing_fails_closed_when_work_item_and_thread_grants_diverge(): void
    {
        $fixture = $this->routedCommunication();
        $staffToken = $this->staffToken($fixture['staff']);
        $workItem = $fixture['work_item'];
        [$otherGrant] = $this->patientForEncounter($fixture['unit']);
        $targetPool = $this->createPool($fixture['unit'], [
            'scope_type' => 'enterprise',
            'unit_id' => null,
            'display_name' => 'Drift Guard Enterprise Team',
        ]);
        $this->createMembership(
            $targetPool,
            User::factory()->create(['role' => 'hospitalist', 'is_active' => true]),
            [
                'membership_role' => 'responder',
                'can_reroute' => false,
                'can_close' => false,
            ],
        );
        $originalPoolId = (int) $workItem->responsibility_pool_id;
        $originalWorkItemVersion = (int) $workItem->row_version;
        $originalThreadVersion = (int) $fixture['thread']->version;

        $workItem->forceFill(['access_grant_id' => $otherGrant->getKey()])->save();

        $this->withToken($staffToken)
            ->getJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/route-candidates")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/reroute", [
                'work_item_version' => $originalWorkItemVersion,
                'thread_version' => $originalThreadVersion,
                'target_pool_uuid' => (string) $targetPool->pool_uuid,
                'reason_code' => 'unit_transfer',
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->assertSame($originalPoolId, (int) $workItem->fresh()->responsibility_pool_id);
        $this->assertSame($originalWorkItemVersion, (int) $workItem->fresh()->row_version);
        $this->assertSame($originalThreadVersion, (int) $fixture['thread']->fresh()->version);
    }

    public function test_manual_reroute_destination_requires_a_reply_enabled_active_capable_responder(): void
    {
        $fixture = $this->routedCommunication();
        $staffToken = $this->staffToken($fixture['staff']);
        $workItem = $fixture['work_item'];
        $facilitySpace = FacilitySpace::query()->create([
            'space_code' => 'staff-eligibility-facility-'.Str::lower(Str::random(10)),
            'space_name' => 'Staff Eligibility Facility Unit Space',
            'space_category' => 'unit',
            'status' => 'active',
            'facility_key' => 'STAFF_ELIGIBILITY_FACILITY',
        ]);
        $fixture['unit']->forceFill(['facility_space_id' => $facilitySpace->getKey()])->save();
        $targetPool = $this->createPool($fixture['unit'], [
            'scope_type' => 'facility',
            'unit_id' => null,
            'facility_key' => 'STAFF_ELIGIBILITY_FACILITY',
            'display_name' => 'Eligibility-Governed Facility Team',
        ]);
        $targetUser = User::factory()->create(['role' => 'hospitalist', 'is_active' => true]);
        $targetMembership = $this->createMembership($targetPool, $targetUser, [
            'membership_role' => 'responder',
            'can_reply' => false,
            'can_reroute' => false,
            'can_close' => false,
        ]);
        $candidatesUrl = "/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/route-candidates";
        $rerouteUrl = "/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/reroute";
        $payload = [
            'work_item_version' => (int) $workItem->row_version,
            'thread_version' => (int) $workItem->thread->version,
            'target_pool_uuid' => (string) $targetPool->pool_uuid,
            'reason_code' => 'specialty_needed',
        ];

        $this->withToken($staffToken)
            ->getJson($candidatesUrl)
            ->assertOk()
            ->assertJsonPath('data.actions.can_reroute', false)
            ->assertJsonCount(0, 'data.reroute_candidates');
        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson($rerouteUrl, $payload)
            ->assertNotFound();

        $targetMembership->forceFill(['can_reply' => true])->save();
        $targetUser->forceFill(['is_active' => false])->save();
        $this->withToken($staffToken)
            ->getJson($candidatesUrl)
            ->assertOk()
            ->assertJsonCount(0, 'data.reroute_candidates');
        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson($rerouteUrl, $payload)
            ->assertNotFound();

        $targetUser->forceFill(['is_active' => true, 'role' => 'auditor'])->save();
        $this->withToken($staffToken)
            ->getJson($candidatesUrl)
            ->assertOk()
            ->assertJsonCount(0, 'data.reroute_candidates');
        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson($rerouteUrl, $payload)
            ->assertNotFound();

        $targetUser->forceFill(['role' => 'hospitalist'])->save();
        $this->withToken($staffToken)
            ->getJson($candidatesUrl)
            ->assertOk()
            ->assertJsonPath('data.actions.can_reroute', true)
            ->assertJsonPath('data.reroute_candidates.0.pool_uuid', (string) $targetPool->pool_uuid)
            ->assertJsonCount(1, 'data.reroute_candidates');

        // Destination eligibility must not redefine source authorization. An
        // effective source member with can_reroute and the actor capability may
        // move orphaned work even when that membership itself cannot reply.
        $fixture['membership']->forceFill(['can_reply' => false])->save();
        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson($rerouteUrl, $payload)
            ->assertOk()
            ->assertJsonPath('data.work_item.pool.pool_uuid', (string) $targetPool->pool_uuid);
        $this->assertSame(1, $this->staffEventCount($workItem, 'rerouted'));
    }

    public function test_release_requires_the_current_assignee_or_supervisor_and_appends_exact_facts(): void
    {
        $fixture = $this->routedCommunication();
        $staffToken = $this->staffToken($fixture['staff']);
        $claim = $this->claim($fixture, $staffToken);
        $workItem = $fixture['work_item'];
        $url = "/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/release";
        $payload = [
            'work_item_version' => $claim['work_item']['work_item_version'],
            'thread_version' => $claim['work_item']['thread_version'],
            'reason_code' => 'return_to_team',
        ];

        $otherResponder = User::factory()->create(['role' => 'bedside_nurse', 'is_active' => true]);
        $this->createMembership($fixture['pool'], $otherResponder, [
            'membership_role' => 'responder',
            'can_reroute' => false,
            'can_close' => false,
        ]);
        $this->withToken($this->staffToken($otherResponder))
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson($url, $payload)
            ->assertNotFound()
            ->assertJsonPath('error.message', 'The requested communication is not available.');
        $this->app['auth']->forgetGuards();

        $uppercaseKey = Str::upper((string) Str::uuid7());
        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', $uppercaseKey)
            ->postJson($url, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $key = (string) Str::uuid7();
        $eventUuid = $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', $key)
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.message', null)
            ->assertJsonPath('data.work_item.ownership_state', 'pool_owned')
            ->assertJsonPath('data.work_item.assigned_to_me', false)
            ->json('data.event_uuid');

        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', $key)
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.event_uuid', $eventUuid);

        $event = StaffActionEvent::query()
            ->where('thread_work_item_id', $workItem->getKey())
            ->where('event_type', 'released')
            ->sole();
        $this->assertSame($fixture['staff']->getKey(), $event->from_user_id);
        $this->assertNull($event->to_user_id);
        $this->assertSame($fixture['pool']->getKey(), $event->from_pool_id);
        $this->assertSame($fixture['pool']->getKey(), $event->to_pool_id);
        $this->assertSame('return_to_team', $event->reason_code);
        $this->assertSame(false, $event->metadata['content_included'] ?? null);
        $this->assertSame(1, $this->staffEventCount($workItem, 'released'));
        $this->assertDatabaseHas('patient_experience.message_routing_events', [
            'message_thread_id' => $fixture['thread']->getKey(),
            'event_type' => 'assigned',
            'reason_code' => 'return_to_team',
            'patient_visible_state' => 'assigned',
        ]);
        $this->assertDatabaseHas('patient_experience.message_delivery_receipts', [
            'receipt_type' => 'assigned',
            'patient_visible_state' => 'assigned',
        ]);
        $this->assertDatabaseHas('audit.user_events', [
            'actor_user_id' => $fixture['staff']->getKey(),
            'action' => 'patient_communications.thread_released',
            'outcome' => 'success',
        ]);
    }

    public function test_reassign_requires_a_live_supervisor_and_live_eligible_target_membership(): void
    {
        $fixture = $this->routedCommunication();
        $staffToken = $this->staffToken($fixture['staff']);
        $claim = $this->claim($fixture, $staffToken);
        $workItem = $fixture['work_item'];
        $target = User::factory()->create(['role' => 'bedside_nurse', 'is_active' => true]);
        $targetMembership = $this->createMembership($fixture['pool'], $target, [
            'membership_role' => 'responder',
            'can_reroute' => false,
            'can_close' => false,
        ]);
        $url = "/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/reassign";
        $payload = [
            'work_item_version' => $claim['work_item']['work_item_version'],
            'thread_version' => $claim['work_item']['thread_version'],
            'target_membership_uuid' => (string) $targetMembership->membership_uuid,
            'reason_code' => 'supervisor_assignment',
        ];

        $nonSupervisor = User::factory()->create(['role' => 'bedside_nurse', 'is_active' => true]);
        $this->createMembership($fixture['pool'], $nonSupervisor, [
            'membership_role' => 'responder',
            'can_reroute' => false,
            'can_close' => false,
        ]);
        $this->withToken($this->staffToken($nonSupervisor))
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson($url, $payload)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $this->app['auth']->forgetGuards();

        $targetMembership->forceFill([
            'availability_state' => 'ended',
            'effective_until' => now(),
        ])->save();
        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson($url, $payload)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $targetMembership->forceFill([
            'availability_state' => 'active',
            'effective_until' => null,
        ])->save();

        $key = (string) Str::uuid7();
        $eventUuid = $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', $key)
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.message', null)
            ->assertJsonPath('data.work_item.ownership_state', 'assigned')
            ->assertJsonPath('data.work_item.assigned_to_me', false)
            ->json('data.event_uuid');

        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', $key)
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.event_uuid', $eventUuid);

        $event = StaffActionEvent::query()
            ->where('thread_work_item_id', $workItem->getKey())
            ->where('event_type', 'reassigned')
            ->sole();
        $this->assertSame($fixture['staff']->getKey(), $event->from_user_id);
        $this->assertSame($target->getKey(), $event->to_user_id);
        $this->assertSame('supervisor_assignment', $event->reason_code);
        $this->assertSame($target->getKey(), $workItem->fresh()->assigned_user_id);
        $this->assertDatabaseHas('patient_experience.message_routing_events', [
            'message_thread_id' => $fixture['thread']->getKey(),
            'event_type' => 'assigned',
            'reason_code' => 'supervisor_assignment',
        ]);
        $this->assertDatabaseHas('audit.user_events', [
            'actor_user_id' => $fixture['staff']->getKey(),
            'action' => 'patient_communications.thread_reassigned',
            'outcome' => 'success',
        ]);

        $fixture['membership']->forceFill(['membership_role' => 'responder'])->save();
        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', $key)
            ->postJson($url, $payload)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $this->assertSame(1, $this->staffEventCount($workItem, 'reassigned'));
    }

    public function test_cross_pool_reroute_is_governed_and_exact_retry_reauthorizes_the_source_pool(): void
    {
        $fixture = $this->routedCommunication();
        $workItem = $fixture['work_item'];
        $staffToken = $this->staffToken($fixture['staff']);
        $facilitySpace = FacilitySpace::query()->create([
            'space_code' => 'staff-api-facility-'.Str::lower(Str::random(10)),
            'space_name' => 'Staff API Facility Unit Space',
            'space_category' => 'unit',
            'status' => 'active',
            'facility_key' => 'staff-api-facility',
        ]);
        $fixture['unit']->forceFill(['facility_space_id' => $facilitySpace->getKey()])->save();
        $targetPool = $this->createPool($fixture['unit'], [
            'scope_type' => 'enterprise',
            'unit_id' => null,
            'display_name' => 'Enterprise Routing Team',
        ]);
        $targetResponder = User::factory()->create(['role' => 'hospitalist', 'is_active' => true]);
        $this->createMembership($targetPool, $targetResponder, [
            'membership_role' => 'supervisor',
            'can_reroute' => true,
            'can_close' => false,
        ]);
        $thirdPool = $this->createPool($fixture['unit'], [
            'scope_type' => 'facility',
            'unit_id' => null,
            'facility_key' => 'staff-api-facility',
            'display_name' => 'Facility Routing Team',
        ]);
        $thirdResponder = User::factory()->create(['role' => 'bedside_nurse', 'is_active' => true]);
        $this->createMembership($thirdPool, $thirdResponder, [
            'membership_role' => 'responder',
            'can_reroute' => false,
            'can_close' => false,
        ]);

        $outsideUnit = Unit::query()->create([
            'name' => 'Outside Pilot Unit',
            'abbreviation' => 'OPU',
            'type' => 'med_surg',
            'staffed_bed_count' => 8,
            'ratio_floor' => 4,
            'access_standard_minutes' => 120,
            'is_deleted' => false,
        ]);
        $outsidePool = $this->createPool($outsideUnit, ['display_name' => 'Outside Unit Team']);
        $outsideResponder = User::factory()->create(['role' => 'hospitalist', 'is_active' => true]);
        $this->createMembership($outsidePool, $outsideResponder);

        $url = "/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/reroute";
        $base = [
            'work_item_version' => $workItem->row_version,
            'thread_version' => $workItem->thread->version,
            'reason_code' => 'specialty_needed',
        ];

        $governedDigest = (string) $fixture['thread']->responsibility_pool_ref_digest;
        $fixture['thread']->forceFill([
            'responsibility_pool_ref_digest' => hash('sha256', 'poisoned-thread-pool'),
        ])->save();
        $this->withToken($staffToken)
            ->getJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/route-candidates")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $fixture['thread']->forceFill([
            'responsibility_pool_ref_digest' => $governedDigest,
        ])->save();

        $fixture['pool']->forceFill(['routing_policy_version' => 'poisoned-source-policy'])->save();
        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson($url, [...$base, 'target_pool_uuid' => (string) $targetPool->pool_uuid])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $fixture['pool']->forceFill(['routing_policy_version' => self::POLICY_VERSION])->save();

        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson($url, [...$base, 'target_pool_uuid' => (string) $outsidePool->pool_uuid])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $payload = [...$base, 'target_pool_uuid' => (string) $targetPool->pool_uuid];
        $key = (string) Str::uuid7();
        $committedReroute = $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', $key)
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.message', null)
            ->assertJsonPath('data.work_item.pool.pool_uuid', (string) $targetPool->pool_uuid)
            ->assertJsonPath('data.work_item.ownership_state', 'rerouted')
            ->assertJsonPath('data.work_item.assigned_to_me', false);
        $eventUuid = $committedReroute->json('data.event_uuid');
        $patientContextRef = $committedReroute->json('data.work_item.patient_context_ref');
        $this->assertIsString($patientContextRef);
        $mappingBeforeReplay = $this->patientContextMapping($patientContextRef);
        $factsBeforeReplay = $this->communicationFactCounts($workItem, $fixture['thread']);
        $replayClock = now()->startOfSecond();
        Carbon::setTestNow($replayClock->copy()->addMinute());

        // The request digest and immutable event bind both opaque work and
        // target identities. Later mutable identity rotations must not turn an
        // exact source-authorized receipt replay into a projection read.
        $originalTargetPoolUuid = (string) $targetPool->pool_uuid;
        $originalWorkItemUuid = (string) $workItem->work_item_uuid;
        $targetPool->forceFill([
            'pool_uuid' => (string) Str::uuid7(),
            'display_name' => 'Renamed Destination After Commit',
            'status' => 'paused',
        ])->save();
        $workItem->forceFill(['work_item_uuid' => (string) Str::uuid7()])->save();

        // Simulate a lost response: the actor is not a target-pool member, but the
        // exact retry remains authorized against the still-live source membership.
        // It is an immutable receipt, not a refreshed destination projection.
        $lostResponseReplay = $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', $key)
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('data.replayed', true);
        $this->assertSame([
            'work_item' => null,
            'message' => null,
            'event_uuid' => $eventUuid,
            'replayed' => true,
        ], $lostResponseReplay->json('data'));
        $this->assertSame($mappingBeforeReplay, $this->patientContextMapping($patientContextRef));
        $this->assertSame($factsBeforeReplay, $this->communicationFactCounts($workItem, $fixture['thread']));

        $targetPool->forceFill([
            'pool_uuid' => $originalTargetPoolUuid,
            'display_name' => 'Enterprise Routing Team',
            'status' => 'active',
        ])->save();
        $workItem->forceFill(['work_item_uuid' => $originalWorkItemUuid])->save();

        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', $key)
            ->postJson($url, [...$payload, 'reason_code' => 'wrong_team'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');

        $this->withToken($staffToken)
            ->getJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $this->withToken($staffToken)
            ->getJson('/api/mobile/v1/patient-communications/inbox')
            ->assertOk()
            ->assertJsonPath('data.count', 0);

        $event = StaffActionEvent::query()
            ->where('thread_work_item_id', $workItem->getKey())
            ->where('event_type', 'rerouted')
            ->sole();
        $this->assertSame($fixture['pool']->getKey(), $event->from_pool_id);
        $this->assertSame($targetPool->getKey(), $event->to_pool_id);
        $this->assertNull($event->message_id);
        $this->assertSame(false, $event->metadata['content_included'] ?? null);
        $this->assertSame($targetPool->getKey(), $workItem->fresh()->responsibility_pool_id);
        $this->assertSame((string) $targetPool->pool_key_digest, (string) $fixture['thread']->fresh()->responsibility_pool_ref_digest);
        $routing = PatientMessageRoutingEvent::query()
            ->where('message_thread_id', $fixture['thread']->getKey())
            ->where('event_type', 'rerouted')
            ->sole();
        $this->assertSame('specialty_needed', $routing->reason_code);
        $this->assertSame('rerouted', $routing->patient_visible_state);
        $this->assertSame(false, $routing->metadata['content_included'] ?? null);
        $this->assertSame(1, PatientMessageDeliveryReceipt::query()
            ->where('message_id', PatientMessage::query()
                ->where('message_thread_id', $fixture['thread']->getKey())
                ->where('sender_type', 'patient')
                ->value('message_id'))
            ->where('receipt_type', 'assigned')
            ->where('actor_type', 'staff')
            ->count());
        $this->assertDatabaseHas('audit.user_events', [
            'actor_user_id' => $fixture['staff']->getKey(),
            'action' => 'patient_communications.thread_rerouted',
            'outcome' => 'success',
        ]);

        $fixture['membership']->forceFill([
            'availability_state' => 'ended',
            'effective_until' => now(),
        ])->save();
        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', $key)
            ->postJson($url, $payload)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonPath('error.message', 'The requested communication is not available.')
            ->assertJsonMissingPath('data');
        $this->assertSame(1, $this->staffEventCount($workItem, 'rerouted'));

        $fixture['membership']->forceFill([
            'availability_state' => 'active',
            'effective_until' => null,
        ])->save();

        // The destination actor can legitimately mutate versions and assignment
        // while the work remains in the destination pool. Those later facts must
        // not influence or appear in the source actor's immutable reroute receipt.
        Carbon::setTestNow($replayClock->copy()->addMinutes(2));
        $targetToken = $this->staffToken($targetResponder);
        $currentWorkItem = $workItem->fresh(['thread']);
        $this->app['auth']->forgetGuards();
        $targetClaim = $this->withToken($targetToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/claim", [
                'work_item_version' => $currentWorkItem->row_version,
                'thread_version' => $currentWorkItem->thread->version,
            ])
            ->assertOk()
            ->assertJsonPath('data.work_item.pool.pool_uuid', (string) $targetPool->pool_uuid)
            ->assertJsonPath('data.work_item.assigned_to_me', true);
        $this->assertSame($patientContextRef, $targetClaim->json('data.work_item.patient_context_ref'));
        $mappingAfterTargetClaim = $this->patientContextMapping($patientContextRef);
        $factsAfterTargetClaim = $this->communicationFactCounts($workItem, $fixture['thread']);

        Carbon::setTestNow($replayClock->copy()->addMinutes(3));
        $this->app['auth']->forgetGuards();
        $postClaimReplay = $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', $key)
            ->postJson($url, $payload)
            ->assertOk();
        $this->assertSame([
            'work_item' => null,
            'message' => null,
            'event_uuid' => $eventUuid,
            'replayed' => true,
        ], $postClaimReplay->json('data'));
        $this->assertStringNotContainsString((string) $targetResponder->name, $postClaimReplay->getContent());
        $this->assertSame($mappingAfterTargetClaim, $this->patientContextMapping($patientContextRef));
        $this->assertSame($factsAfterTargetClaim, $this->communicationFactCounts($workItem, $fixture['thread']));

        Carbon::setTestNow($replayClock->copy()->addMinutes(4));
        $currentWorkItem = $workItem->fresh(['thread']);
        $this->app['auth']->forgetGuards();
        $this->withToken($targetToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson($url, [
                'work_item_version' => $currentWorkItem->row_version,
                'thread_version' => $currentWorkItem->thread->version,
                'target_pool_uuid' => (string) $thirdPool->pool_uuid,
                'reason_code' => 'service_change',
            ])
            ->assertOk()
            ->assertJsonPath('data.work_item.pool.pool_uuid', (string) $thirdPool->pool_uuid)
            ->assertJsonPath('data.work_item.ownership_state', 'rerouted');

        $mappingAfterLaterMove = $this->patientContextMapping($patientContextRef);
        $factsAfterLaterMove = $this->communicationFactCounts($workItem, $fixture['thread']);
        Carbon::setTestNow($replayClock->copy()->addMinutes(5));
        $this->app['auth']->forgetGuards();
        $laterProjectionReplay = $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', $key)
            ->postJson($url, $payload)
            ->assertOk();
        $this->assertSame([
            'work_item' => null,
            'message' => null,
            'event_uuid' => $eventUuid,
            'replayed' => true,
        ], $laterProjectionReplay->json('data'));
        $this->assertStringNotContainsString((string) $thirdPool->pool_uuid, $laterProjectionReplay->getContent());
        $this->assertStringNotContainsString('Facility Routing Team', $laterProjectionReplay->getContent());
        $this->assertSame($mappingAfterLaterMove, $this->patientContextMapping($patientContextRef));
        $this->assertSame($factsAfterLaterMove, $this->communicationFactCounts($workItem, $fixture['thread']));
        $this->assertSame(2, $this->staffEventCount($workItem, 'rerouted'));
    }

    public function test_staff_action_and_user_audit_ledgers_are_append_only_and_content_free(): void
    {
        $fixture = $this->routedCommunication();
        $workItem = $fixture['work_item'];
        $staffToken = $this->staffToken($fixture['staff']);
        $claim = $this->claim($fixture, $staffToken);

        $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/reply", [
                'work_item_version' => $claim['work_item']['work_item_version'],
                'thread_version' => $claim['work_item']['thread_version'],
                'message' => self::STAFF_REPLY,
                'client_message_uuid' => (string) Str::uuid7(),
            ])
            ->assertOk();

        $event = StaffActionEvent::query()
            ->where('thread_work_item_id', $workItem->getKey())
            ->where('event_type', 'replied')
            ->firstOrFail();
        $this->assertSame(false, $event->metadata['content_included'] ?? null);
        $this->assertArrayNotHasKey('message', $event->metadata);
        $this->assertArrayNotHasKey('body', $event->metadata);

        try {
            $event->forceFill(['reason_code' => 'tampered'])->save();
            $this->fail('The append-only staff action model allowed an update.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        DB::beginTransaction();
        try {
            DB::table('patient_communications.staff_action_events')
                ->where('staff_action_event_id', $event->getKey())
                ->update(['reason_code' => 'tampered']);
            $this->fail('The append-only staff action trigger allowed an update.');
        } catch (QueryException) {
            DB::rollBack();
            $this->addToAssertionCount(1);
        }

        $audits = UserEvent::query()
            ->where('actor_user_id', $fixture['staff']->getKey())
            ->where('action', 'like', 'patient_communications.%')
            ->get();
        $this->assertTrue($audits->contains('action', 'patient_communications.thread_claimed'));
        $this->assertTrue($audits->contains('action', 'patient_communications.thread_replied'));
        $auditPayload = $audits->map(fn (UserEvent $audit): array => [
            'changes' => $audit->changes,
            'metadata' => $audit->metadata,
        ])->all();
        $encodedAuditPayload = json_encode($auditPayload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::PATIENT_QUESTION, $encodedAuditPayload);
        $this->assertStringNotContainsString(self::STAFF_REPLY, $encodedAuditPayload);
        $this->assertStringNotContainsString('encrypted_body', $encodedAuditPayload);

        $audit = $audits->firstWhere('action', 'patient_communications.thread_replied');
        $this->assertInstanceOf(UserEvent::class, $audit);
        DB::beginTransaction();
        try {
            DB::table('audit.user_events')
                ->where('event_cursor', $audit->getKey())
                ->delete();
            $this->fail('The append-only user audit trigger allowed a delete.');
        } catch (QueryException) {
            DB::rollBack();
            $this->addToAssertionCount(1);
        }
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
            'name' => 'Staff API Test Unit',
            'abbreviation' => 'SATU',
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
            $consumer->consumeBatch('staff-api-test-worker'),
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
            $consumer->consumeBatch('staff-api-test-worker'),
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
            'display_name' => 'Staff API Test Care Team',
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
            'patient_ref' => 'staff-api-test-'.Str::lower(Str::random(12)),
            'unit_id' => $unit->getKey(),
            'admitted_at' => now()->subDay(),
            'acuity_tier' => 2,
            'status' => 'active',
            'is_deleted' => false,
        ]);
        $principal = PatientPrincipal::query()->create([
            'principal_uuid' => (string) Str::uuid7(),
            'principal_type' => 'patient',
            'display_name' => 'Staff API Test Patient',
            'email' => 'staff-api+'.Str::lower(Str::random(10)).'@example.test',
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
            'grant_reason' => 'Automated staff communication API test.',
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

    /** @param list<string> $abilities */
    private function staffToken(User $staff, array $abilities = ['mobile:read', 'mobile:act']): string
    {
        $token = $staff->createToken('staff-patient-communications-test', $abilities)->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $token;
    }

    /** @param array<string, mixed> $fixture */
    private function claim(array $fixture, string $staffToken): array
    {
        /** @var ThreadWorkItem $workItem */
        $workItem = $fixture['work_item'];

        return $this->withToken($staffToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}/claim", [
                'work_item_version' => $workItem->row_version,
                'thread_version' => $workItem->thread->version,
            ])
            ->assertOk()
            ->json('data');
    }

    /** @return array<string, int> */
    private function communicationFactCounts(
        ThreadWorkItem $workItem,
        PatientMessageThread $thread,
    ): array {
        $messageIds = PatientMessage::query()
            ->where('message_thread_id', $thread->getKey())
            ->pluck('message_id');

        return [
            'staff_actions' => StaffActionEvent::query()
                ->where('thread_work_item_id', $workItem->getKey())
                ->count(),
            'patient_routing_events' => PatientMessageRoutingEvent::query()
                ->where('message_thread_id', $thread->getKey())
                ->count(),
            'patient_receipts' => PatientMessageDeliveryReceipt::query()
                ->whereIn('message_id', $messageIds)
                ->count(),
            'reroute_mutation_audits' => UserEvent::query()
                ->where('action', 'patient_communications.thread_rerouted')
                ->count(),
        ];
    }

    /** @return array<string, string> */
    private function patientContextMapping(string $patientContextRef): array
    {
        $mapping = DB::table('ops.patient_operational_context_cache')
            ->where('patient_context_ref', $patientContextRef)
            ->first([
                'patient_context_ref',
                'patient_ref',
                'generated_at',
                'expires_at',
                'updated_at',
                'created_at',
            ]);
        $this->assertNotNull($mapping);

        return collect((array) $mapping)
            ->map(static fn (mixed $value): string => (string) $value)
            ->all();
    }

    private function staffEventCount(ThreadWorkItem $workItem, string $eventType): int
    {
        return StaffActionEvent::query()
            ->where('thread_work_item_id', $workItem->getKey())
            ->where('event_type', $eventType)
            ->count();
    }
}
