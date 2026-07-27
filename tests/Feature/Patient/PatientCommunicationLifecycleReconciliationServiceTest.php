<?php

namespace Tests\Feature\Patient;

use App\Models\Encounter;
use App\Models\Facility\FacilitySpace;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientMessage;
use App\Models\Patient\PatientMessageDeliveryReceipt;
use App\Models\Patient\PatientMessageRoutingEvent;
use App\Models\Patient\PatientMessageThread;
use App\Models\Patient\PatientNotificationOutbox;
use App\Models\Patient\PatientPrincipal;
use App\Models\Patient\PatientSession;
use App\Models\PatientCommunication\PoolMembership;
use App\Models\PatientCommunication\ResponsibilityPool;
use App\Models\PatientCommunication\StaffActionEvent;
use App\Models\PatientCommunication\ThreadWorkItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\Patient\Messaging\PatientCommunicationLifecycleReconciliationService;
use App\Services\Patient\Messaging\PatientMessageCipher;
use App\Services\Patient\Messaging\StaffPatientCommunicationFailure;
use App\Services\Patient\PatientHmac;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class PatientCommunicationLifecycleReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    private const POLICY_VERSION = 'lifecycle-reconciliation-policy-v1';

    private const TOPIC_CODE = 'care_question';

    private const POOL_KEY = 'lifecycle.care-team';

    private const PATIENT_QUESTION = 'This patient content must never enter lifecycle routing facts.';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_unit_transfer_reroutes_in_place_and_appends_content_free_facts_once(): void
    {
        Carbon::setTestNow('2026-07-20 10:00:00 America/New_York');
        $sourceUnit = $this->createUnit('Lifecycle Source', 'LCS');
        $destinationUnit = $this->createUnit('Lifecycle Destination', 'LCD');
        $this->configure([$sourceUnit, $destinationUnit]);
        $fixture = $this->createOpenWork($sourceUnit);
        $destinationPool = $this->createPool($destinationUnit, [
            'display_name' => 'Destination Care Team',
            'response_target_minutes' => 20,
            'escalation_target_minutes' => 45,
        ]);
        $this->createMembership(
            $destinationPool,
            User::factory()->create(['role' => 'charge_nurse', 'is_active' => true]),
        );

        $thread = $fixture['thread'];
        $workItem = $fixture['work_item'];
        $grantId = $fixture['grant']->getKey();
        $threadId = $thread->getKey();
        $threadUuid = $thread->thread_uuid;
        $workItemId = $workItem->getKey();
        $workItemUuid = $workItem->work_item_uuid;
        $threadVersion = (int) $thread->version;
        $workVersion = (int) $workItem->row_version;
        $fixture['encounter']->forceFill(['unit_id' => $destinationUnit->getKey()])->save();

        $result = $this->reconciler()->reconcileOpen(25);
        $this->assertSame([
            'selected' => 1,
            'rerouted' => 1,
            'released' => 0,
            'closed' => 0,
            'skipped' => 0,
            'unresolved' => 0,
            'failed' => 0,
        ], $result);

        $thread = $thread->fresh();
        $workItem = $workItem->fresh();
        $this->assertSame($threadId, $thread->getKey());
        $this->assertSame($threadUuid, $thread->thread_uuid);
        $this->assertSame($workItemId, $workItem->getKey());
        $this->assertSame($workItemUuid, $workItem->work_item_uuid);
        $this->assertSame($grantId, $workItem->access_grant_id);
        $this->assertSame($destinationPool->getKey(), $workItem->responsibility_pool_id);
        $this->assertNull($workItem->assigned_user_id);
        $this->assertSame('open', $thread->status);
        $this->assertSame('rerouted', $thread->ownership_state);
        $this->assertSame('open', $workItem->status);
        $this->assertSame('rerouted', $workItem->ownership_state);
        $this->assertSame($threadVersion + 1, (int) $thread->version);
        $this->assertSame($workVersion + 1, (int) $workItem->row_version);
        $this->assertSame((int) $thread->version, (int) $workItem->source_thread_version);
        $this->assertTrue($workItem->due_at->equalTo(now()->addMinutes(20)));
        $this->assertTrue($workItem->escalate_at->equalTo(now()->addMinutes(45)));

        $this->assertLifecycleFacts(
            $fixture,
            'rerouted',
            'encounter_unit_transferred',
            'rerouted',
            'assigned',
        );
        $factCounts = $this->factCounts($fixture);

        $this->assertSame([
            'selected' => 1,
            'rerouted' => 0,
            'released' => 0,
            'closed' => 0,
            'skipped' => 1,
            'unresolved' => 0,
            'failed' => 0,
        ], $this->reconciler()->reconcileOpen(25));
        $this->assertSame($factCounts, $this->factCounts($fixture));

    }

    public function test_discharge_closes_both_projections_without_replacing_thread_work_or_grant_identity(): void
    {
        Carbon::setTestNow('2026-07-20 11:00:00 America/New_York');
        $unit = $this->createUnit('Discharge Unit', 'DCU');
        $this->configure([$unit]);
        $fixture = $this->createOpenWork($unit);
        $thread = $fixture['thread'];
        $workItem = $fixture['work_item'];
        $workItem->forceFill([
            'assigned_user_id' => $fixture['staff']->getKey(),
            'ownership_state' => 'acknowledged',
            'acknowledged_at' => now()->subMinutes(5),
        ])->save();
        $thread->forceFill(['ownership_state' => 'acknowledged'])->save();
        $threadVersion = (int) $thread->version;
        $workVersion = (int) $workItem->row_version;
        $fixture['encounter']->forceFill([
            'status' => 'discharged',
            'discharged_at' => now()->subMinute(),
        ])->save();

        $this->assertSame([
            'selected' => 1,
            'rerouted' => 0,
            'released' => 0,
            'closed' => 1,
            'skipped' => 0,
            'unresolved' => 0,
            'failed' => 0,
        ], $this->reconciler()->reconcileOpen());

        $thread = $thread->fresh();
        $workItem = $workItem->fresh();
        $this->assertSame($fixture['thread']->getKey(), $thread->getKey());
        $this->assertSame($fixture['work_item']->getKey(), $workItem->getKey());
        $this->assertSame($fixture['grant']->getKey(), $workItem->access_grant_id);
        $this->assertSame($fixture['pool']->getKey(), $workItem->responsibility_pool_id);
        $this->assertSame('closed', $thread->status);
        $this->assertSame('closed', $thread->ownership_state);
        $this->assertSame('no_longer_needed', $thread->close_reason_code);
        $this->assertSame('closed', $workItem->status);
        $this->assertSame('closed', $workItem->ownership_state);
        $this->assertSame('encounter_discharged', $workItem->close_reason_code);
        $this->assertNull($workItem->assigned_user_id);
        $this->assertSame($threadVersion + 1, (int) $thread->version);
        $this->assertSame($workVersion + 1, (int) $workItem->row_version);
        $this->assertSame((int) $thread->version, (int) $workItem->source_thread_version);
        $this->assertNotNull($thread->closed_at);
        $this->assertNotNull($workItem->closed_at);

        $this->assertLifecycleFacts(
            $fixture,
            'closed',
            'encounter_discharged',
            'closed',
            'closed',
        );
        $factCounts = $this->factCounts($fixture);
        $this->assertSame([
            'selected' => 0,
            'rerouted' => 0,
            'released' => 0,
            'closed' => 0,
            'skipped' => 0,
            'unresolved' => 0,
            'failed' => 0,
        ], $this->reconciler()->reconcileOpen());
        $this->assertSame($factCounts, $this->factCounts($fixture));

        // A corrected/reactivated canonical encounter may make the historical
        // closed thread readable again. Its patient close reason must remain
        // within the published contract and native-client enum.
        $fixture['encounter']->forceFill([
            'status' => 'active',
            'discharged_at' => null,
        ])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($fixture['patient_token'])
            ->getJson("/api/patient/v1/threads/{$thread->thread_uuid}")
            ->assertOk()
            ->assertJsonPath('data.thread.status', 'closed')
            ->assertJsonPath('data.thread.close_reason', 'no_longer_needed');
    }

    public function test_expired_pending_assignee_is_released_when_same_pool_has_an_eligible_backup(): void
    {
        Carbon::setTestNow('2026-07-20 12:00:00 America/New_York');
        $unit = $this->createUnit('Shift Coverage Unit', 'SCU');
        $this->configure([$unit]);
        $fixture = $this->createOpenWork($unit);
        $backup = User::factory()->create(['role' => 'bedside_nurse', 'is_active' => true]);
        $this->createMembership($fixture['pool'], $backup);

        $fixture['work_item']->forceFill([
            'assigned_user_id' => $fixture['staff']->getKey(),
            'ownership_state' => 'acknowledged',
            'acknowledged_at' => now()->subMinutes(5),
        ])->save();
        $fixture['thread']->forceFill(['ownership_state' => 'acknowledged'])->save();
        $fixture['membership']->forceFill([
            'availability_state' => 'ended',
            'effective_until' => now()->subSecond(),
        ])->save();
        $threadVersion = (int) $fixture['thread']->version;
        $workVersion = (int) $fixture['work_item']->row_version;
        // Capture the persisted (reloaded) due/escalate instants rather than the
        // in-memory model: under Carbon::setTestNow(...America/New_York) the in-memory
        // Carbon is tz-labeled NY, so comparing it via equalTo() against the DB-normalized
        // (UTC) reload after reconciliation would drift by the offset. Release must leave
        // these timestamps untouched, which is what this asserts.
        $persistedWorkItem = $fixture['work_item']->fresh();
        $dueAt = $persistedWorkItem->due_at;
        $escalateAt = $persistedWorkItem->escalate_at;

        $this->assertSame([
            'selected' => 1,
            'rerouted' => 0,
            'released' => 1,
            'closed' => 0,
            'skipped' => 0,
            'unresolved' => 0,
            'failed' => 0,
        ], $this->reconciler()->reconcileOpen());

        $thread = $fixture['thread']->fresh();
        $workItem = $fixture['work_item']->fresh();
        $this->assertSame('assigned', $thread->ownership_state);
        $this->assertSame('pool_owned', $workItem->ownership_state);
        $this->assertNull($workItem->assigned_user_id);
        $this->assertNull($workItem->acknowledged_at);
        $this->assertSame($threadVersion + 1, (int) $thread->version);
        $this->assertSame($workVersion + 1, (int) $workItem->row_version);
        $this->assertTrue($workItem->due_at->equalTo($dueAt));
        $this->assertTrue($workItem->escalate_at->equalTo($escalateAt));
        $this->assertLifecycleFacts(
            $fixture,
            'released',
            'assigned_responder_unavailable',
            'assigned',
            'assigned',
        );
    }

    public function test_paused_pool_reroutes_to_unambiguous_enterprise_fallback(): void
    {
        Carbon::setTestNow('2026-07-20 13:00:00 America/New_York');
        $unit = $this->createUnit('Downtime Unit', 'DTU');
        $this->configure([$unit]);
        $fixture = $this->createOpenWork($unit);
        $fallback = $this->createPool($unit, [
            'scope_type' => 'enterprise',
            'unit_id' => null,
            'display_name' => 'Enterprise Downtime Team',
        ]);
        $this->createMembership(
            $fallback,
            User::factory()->create(['role' => 'hospitalist', 'is_active' => true]),
        );
        $fixture['pool']->forceFill(['status' => 'paused'])->save();

        $this->assertSame([
            'selected' => 1,
            'rerouted' => 1,
            'released' => 0,
            'closed' => 0,
            'skipped' => 0,
            'unresolved' => 0,
            'failed' => 0,
        ], $this->reconciler()->reconcileOpen());
        $this->assertSame($fallback->getKey(), $fixture['work_item']->fresh()->responsibility_pool_id);
        $this->assertLifecycleFacts(
            $fixture,
            'rerouted',
            'responsibility_pool_unavailable',
            'rerouted',
            'assigned',
        );
    }

    public function test_shift_end_without_pool_backup_reroutes_to_enterprise_fallback(): void
    {
        Carbon::setTestNow('2026-07-20 16:00:00 America/New_York');
        $unit = $this->createUnit('Coverage Gap Unit', 'CGU');
        $this->configure([$unit]);
        $fixture = $this->createOpenWork($unit);
        $fallback = $this->createPool($unit, [
            'scope_type' => 'enterprise',
            'unit_id' => null,
            'display_name' => 'Enterprise Coverage Team',
            'response_target_minutes' => 15,
            'escalation_target_minutes' => 40,
        ]);
        $this->createMembership(
            $fallback,
            User::factory()->create(['role' => 'hospitalist', 'is_active' => true]),
        );

        // A responder held the thread, then their shift ended with no eligible
        // backup remaining in the unit pool. The pool stays active and still
        // matches the encounter's unit, so this is a workforce-coverage gap,
        // not encounter movement or pool downtime: accountability escalates to
        // the staffed enterprise fallback rather than silently stalling.
        $fixture['work_item']->forceFill([
            'assigned_user_id' => $fixture['staff']->getKey(),
            'ownership_state' => 'acknowledged',
            'acknowledged_at' => now()->subMinutes(5),
        ])->save();
        $fixture['thread']->forceFill(['ownership_state' => 'acknowledged'])->save();
        $fixture['membership']->forceFill([
            'availability_state' => 'ended',
            'effective_until' => now()->subSecond(),
        ])->save();
        $threadVersion = (int) $fixture['thread']->fresh()->version;
        $workVersion = (int) $fixture['work_item']->fresh()->row_version;

        $this->assertSame([
            'selected' => 1,
            'rerouted' => 1,
            'released' => 0,
            'closed' => 0,
            'skipped' => 0,
            'unresolved' => 0,
            'failed' => 0,
        ], $this->reconciler()->reconcileOpen());

        $thread = $fixture['thread']->fresh();
        $workItem = $fixture['work_item']->fresh();
        $this->assertSame('rerouted', $thread->ownership_state);
        $this->assertSame('rerouted', $workItem->ownership_state);
        $this->assertSame($fallback->getKey(), $workItem->responsibility_pool_id);
        $this->assertNull($workItem->assigned_user_id);
        $this->assertNull($workItem->acknowledged_at);
        $this->assertNull($workItem->responded_at);
        $this->assertSame($threadVersion + 1, (int) $thread->version);
        $this->assertSame($workVersion + 1, (int) $workItem->row_version);
        $this->assertTrue($workItem->due_at->equalTo(now()->addMinutes(15)));
        $this->assertTrue($workItem->escalate_at->equalTo(now()->addMinutes(40)));
        $this->assertLifecycleFacts(
            $fixture,
            'rerouted',
            'responder_coverage_changed',
            'rerouted',
            'assigned',
        );

        // Idempotent: the fallback now matches scope and is staffed, so a second
        // pass is a no-op with no additional facts.
        $factCounts = $this->factCounts($fixture);
        $this->assertSame([
            'selected' => 1,
            'rerouted' => 0,
            'released' => 0,
            'closed' => 0,
            'skipped' => 1,
            'unresolved' => 0,
            'failed' => 0,
        ], $this->reconciler()->reconcileOpen());
        $this->assertSame($factCounts, $this->factCounts($fixture));
    }

    public function test_shift_end_without_any_eligible_destination_stays_unresolved(): void
    {
        Carbon::setTestNow('2026-07-20 17:00:00 America/New_York');
        $unit = $this->createUnit('No Coverage Unit', 'NCU');
        $this->configure([$unit]);
        $fixture = $this->createOpenWork($unit);
        $versions = $this->versions($fixture);

        // The unit pool's only responder ends their shift and no facility or
        // enterprise fallback is configured. The reconciler must never silently
        // substitute another pool or close the thread; it degrades the item to
        // unresolved and touches no projection or content-free fact.
        $fixture['membership']->forceFill([
            'availability_state' => 'ended',
            'effective_until' => now()->subSecond(),
        ])->save();

        $this->assertSkippedWithoutMutation($fixture, $versions);
    }

    public function test_inconsistent_discharge_and_unresolved_facility_destination_fail_closed_without_mutation(): void
    {
        Carbon::setTestNow('2026-07-20 14:00:00 America/New_York');
        $sourceUnit = $this->createUnit('Fail Closed Source', 'FCS');
        $destinationFacility = FacilitySpace::query()->create([
            'space_code' => 'lifecycle-facility-'.Str::lower(Str::random(8)),
            'space_name' => 'Lifecycle Destination Facility',
            'space_category' => 'unit',
            'status' => 'active',
            'facility_key' => 'LIFECYCLE_DESTINATION',
        ]);
        $destinationUnit = $this->createUnit('Fail Closed Destination', 'FCD', $destinationFacility);
        $this->configure([$sourceUnit, $destinationUnit]);
        $fixture = $this->createOpenWork($sourceUnit);
        $wrongFacilityPool = $this->createPool($sourceUnit, [
            'scope_type' => 'facility',
            'unit_id' => null,
            'facility_key' => 'ANOTHER_FACILITY',
            'display_name' => 'Wrong Facility Team',
        ]);
        $this->createMembership(
            $wrongFacilityPool,
            User::factory()->create(['role' => 'hospitalist', 'is_active' => true]),
        );
        $versions = $this->versions($fixture);

        $fixture['encounter']->forceFill(['discharged_at' => now()])->save();
        $this->assertSkippedWithoutMutation($fixture, $versions);

        $fixture['encounter']->forceFill([
            'unit_id' => $destinationUnit->getKey(),
            'discharged_at' => null,
        ])->save();
        $this->assertSkippedWithoutMutation($fixture, $versions);
    }

    public function test_same_tier_destination_ambiguity_appends_no_facts_and_changes_no_projection(): void
    {
        Carbon::setTestNow('2026-07-20 15:00:00 America/New_York');
        $sourceUnit = $this->createUnit('Ambiguity Source', 'AMS');
        $destinationUnit = $this->createUnit('Ambiguity Destination', 'AMD');
        $this->configure([$sourceUnit, $destinationUnit]);
        $fixture = $this->createOpenWork($sourceUnit);
        $versions = $this->versions($fixture);

        DB::statement('DROP INDEX patient_communications.uq_patient_communications_pool_scope');
        $first = null;
        $second = null;
        try {
            $first = $this->createPool($destinationUnit, ['display_name' => 'Ambiguous Team One']);
            $second = $this->createPool($destinationUnit, ['display_name' => 'Ambiguous Team Two']);
            $this->createMembership(
                $first,
                User::factory()->create(['role' => 'charge_nurse', 'is_active' => true]),
            );
            $this->createMembership(
                $second,
                User::factory()->create(['role' => 'charge_nurse', 'is_active' => true]),
            );
            $fixture['encounter']->forceFill(['unit_id' => $destinationUnit->getKey()])->save();

            $this->assertSkippedWithoutMutation($fixture, $versions);
        } finally {
            $first?->memberships()->delete();
            $second?->memberships()->delete();
            $first?->delete();
            $second?->delete();
            DB::statement(<<<'SQL'
CREATE UNIQUE INDEX uq_patient_communications_pool_scope
    ON patient_communications.responsibility_pools (
        routing_policy_version,
        pool_key_digest,
        topic_code,
        scope_type,
        COALESCE(unit_id, 0),
        COALESCE(facility_key, '')
    )
SQL);
        }
    }

    public function test_disabled_or_unapproved_reconciliation_and_command_inputs_fail_closed(): void
    {
        $service = $this->reconciler();
        foreach ([
            ['enabled' => false, 'governance_status' => 'approved'],
            ['enabled' => true, 'governance_status' => 'draft_requires_approval'],
        ] as $staffMessaging) {
            config([
                'hummingbird-patient.enabled' => true,
                'hummingbird-patient.features.messaging' => true,
                'hummingbird-patient.staff_messaging' => $staffMessaging,
            ]);

            try {
                $service->reconcileOpen();
                $this->fail('Lifecycle reconciliation ran without approved feature gates.');
            } catch (StaffPatientCommunicationFailure $failure) {
                $this->assertSame('communications_unavailable', $failure->errorCode);
            }
        }

        $this->artisan('hummingbird:reconcile-patient-communications')
            ->expectsOutputToContain('Only bounded --once execution is supported; run it under the approved scheduler.')
            ->assertExitCode(Command::INVALID);
        foreach (['0', '501', 'not-an-integer'] as $invalidLimit) {
            $this->artisan('hummingbird:reconcile-patient-communications', [
                '--once' => true,
                '--limit' => $invalidLimit,
            ])
                ->expectsOutputToContain('The batch limit must be an integer from 1 through 500.')
                ->assertExitCode(Command::INVALID);
        }
    }

    public function test_command_emits_only_aggregate_success_or_stable_failure_output(): void
    {
        $this->mock(PatientCommunicationLifecycleReconciliationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reconcileOpen')->once()->with(25)->andReturn([
                'selected' => 7,
                'rerouted' => 2,
                'released' => 1,
                'closed' => 2,
                'skipped' => 2,
                'unresolved' => 0,
                'failed' => 0,
            ]);
        });
        $this->artisan('hummingbird:reconcile-patient-communications', [
            '--once' => true,
            '--limit' => '25',
        ])
            ->expectsOutputToContain(
                'Patient-communication reconciliation complete: selected=7 rerouted=2 released=1 closed=2 skipped=2 unresolved=0 failed=0.',
            )
            ->assertSuccessful();

        $secretFailure = 'patient content and database location must not reach command output';
        $this->mock(PatientCommunicationLifecycleReconciliationService::class, function (MockInterface $mock) use ($secretFailure): void {
            $mock->shouldReceive('reconcileOpen')->once()->andThrow(new RuntimeException($secretFailure));
        });
        $this->artisan('hummingbird:reconcile-patient-communications', [
            '--once' => true,
            '--limit' => '100',
        ])
            ->expectsOutputToContain('patient_communication_reconciliation_failed')
            ->doesntExpectOutputToContain($secretFailure)
            ->assertExitCode(Command::FAILURE);
    }

    /**
     * @return array{
     *   unit: Unit,
     *   encounter: Encounter,
     *   principal: PatientPrincipal,
     *   grant: PatientEncounterAccessGrant,
     *   thread: PatientMessageThread,
     *   message: PatientMessage,
     *   outbox: PatientNotificationOutbox,
     *   pool: ResponsibilityPool,
     *   staff: User,
     *   membership: PoolMembership,
     *   work_item: ThreadWorkItem,
     *   patient_token: string
     * }
     */
    private function createOpenWork(Unit $unit): array
    {
        $staff = User::factory()->create(['role' => 'charge_nurse', 'is_active' => true]);
        $pool = $this->createPool($unit);
        $membership = $this->createMembership($pool, $staff);
        $encounter = Encounter::query()->create([
            'patient_ref' => 'lifecycle-patient-'.Str::lower(Str::random(10)),
            'unit_id' => $unit->getKey(),
            'admitted_at' => now()->subDay(),
            'acuity_tier' => 2,
            'status' => 'active',
            'is_deleted' => false,
        ]);
        $principal = PatientPrincipal::query()->create([
            'principal_uuid' => (string) Str::uuid7(),
            'principal_type' => 'patient',
            'display_name' => 'Lifecycle Test Patient',
            'status' => 'active',
            'is_active' => true,
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
        $patientToken = $principal
            ->createToken('patient-access:'.$sessionUuid, ['patient:access'])
            ->plainTextToken;
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
            'grant_reason' => 'Lifecycle reconciliation test.',
            'version' => 1,
        ]);
        $thread = PatientMessageThread::query()->create([
            'thread_uuid' => (string) Str::uuid7(),
            'access_grant_id' => $grant->getKey(),
            'opened_by_principal_id' => $principal->getKey(),
            'topic_code' => self::TOPIC_CODE,
            'topic_label' => 'Question for my care team',
            'topic_description' => 'Ask about current hospital care.',
            'status' => 'open',
            'ownership_state' => 'assigned',
            'routing_policy_version' => self::POLICY_VERSION,
            'expected_response_window' => 'The care team usually responds within one hour.',
            'urgent_guidance_version' => 'lifecycle-guidance-v1',
            'responsibility_pool_ref_digest' => $this->poolDigest(),
            'creation_idempotency_key_digest' => hash('sha256', (string) Str::uuid7()),
            'creation_request_payload_digest' => hash('sha256', (string) Str::uuid7()),
            'version' => 2,
            'last_message_at' => now()->subMinutes(2),
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
                self::PATIENT_QUESTION,
                'test-lifecycle-message-key-v1',
                $cipher->contextFor((string) $thread->thread_uuid, $messageUuid),
            ),
            'encryption_key_version' => 'test-lifecycle-message-key-v1',
            'body_digest' => hash('sha256', self::PATIENT_QUESTION),
            'body_character_count' => mb_strlen(self::PATIENT_QUESTION),
            'client_message_uuid' => (string) Str::uuid7(),
            'idempotency_key_digest' => hash('sha256', (string) Str::uuid7()),
            'request_payload_digest' => hash('sha256', (string) Str::uuid7()),
            'delivery_state' => 'routed',
            'sent_at' => now()->subMinutes(2),
        ]);
        $outbox = PatientNotificationOutbox::query()->create([
            'outbox_uuid' => (string) Str::uuid7(),
            'principal_id' => $principal->getKey(),
            'access_grant_id' => $grant->getKey(),
            'aggregate_type' => 'patient_message_thread',
            'aggregate_uuid' => $thread->thread_uuid,
            'event_type' => 'patient.message.created',
            'destination' => 'staff_inbox',
            'payload_digest' => hash('sha256', (string) Str::uuid7()),
            'routing_metadata' => ['schema_version' => 1, 'content_included' => false],
            'idempotency_key_digest' => hash('sha256', (string) Str::uuid7()),
            'available_at' => now()->subMinutes(2),
            'expires_at' => now()->addDay(),
            'occurred_at' => now()->subMinutes(2),
        ]);
        $workItem = ThreadWorkItem::query()->create([
            'work_item_uuid' => (string) Str::uuid7(),
            'message_thread_id' => $thread->getKey(),
            'access_grant_id' => $grant->getKey(),
            'responsibility_pool_id' => $pool->getKey(),
            'status' => 'open',
            'ownership_state' => 'pool_owned',
            'source_thread_version' => $thread->version,
            'row_version' => 1,
            'last_outbox_id' => $outbox->getKey(),
            'first_routed_at' => now()->subMinute(),
            'due_at' => now()->addMinutes(30),
            'escalate_at' => now()->addMinutes(60),
            'last_message_at' => $thread->last_message_at,
        ]);

        return compact(
            'unit',
            'encounter',
            'principal',
            'grant',
            'thread',
            'message',
            'outbox',
            'pool',
            'staff',
            'membership',
            'workItem',
        ) + ['work_item' => $workItem, 'patient_token' => $patientToken];
    }

    /** @param list<Unit> $units */
    private function configure(array $units): void
    {
        config([
            'hummingbird-patient.enabled' => true,
            'hummingbird-patient.features.messaging' => true,
            'hummingbird-patient.messaging' => [
                'governance_status' => 'approved',
                'policy_version' => self::POLICY_VERSION,
                'urgent_guidance_version' => 'lifecycle-guidance-v1',
                'urgent_guidance_text' => 'Use the approved immediate-help route for urgent needs.',
                'default_response_window' => 'The care team usually responds within one hour.',
                'encryption_key_version' => 'test-lifecycle-message-key-v1',
                'topics' => [
                    self::TOPIC_CODE => [
                        'label' => 'Question for my care team',
                        'description' => 'Ask about current hospital care.',
                        'responsibility_pool_key' => self::POOL_KEY,
                    ],
                ],
            ],
            'hummingbird-patient.staff_messaging.enabled' => true,
            'hummingbird-patient.staff_messaging.governance_status' => 'approved',
            'hummingbird-patient.staff_messaging.pilot_unit_ids' => collect($units)
                ->map(static fn (Unit $unit): int => (int) $unit->getKey())
                ->all(),
        ]);
    }

    private function createUnit(
        string $name,
        string $abbreviation,
        ?FacilitySpace $facilitySpace = null,
    ): Unit {
        return Unit::query()->create([
            'name' => $name,
            'abbreviation' => $abbreviation,
            'type' => 'med_surg',
            'staffed_bed_count' => 12,
            'ratio_floor' => 4,
            'access_standard_minutes' => 120,
            'facility_space_id' => $facilitySpace?->getKey(),
            'is_deleted' => false,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createPool(Unit $unit, array $overrides = []): ResponsibilityPool
    {
        return ResponsibilityPool::query()->create([
            'pool_uuid' => (string) Str::uuid7(),
            'pool_key_digest' => $this->poolDigest(),
            'topic_code' => self::TOPIC_CODE,
            'display_name' => 'Lifecycle Test Care Team',
            'routing_policy_version' => self::POLICY_VERSION,
            'scope_type' => 'unit',
            'unit_id' => $unit->getKey(),
            'status' => 'active',
            'response_target_minutes' => 30,
            'escalation_target_minutes' => 60,
            ...$overrides,
        ]);
    }

    private function createMembership(ResponsibilityPool $pool, User $staff): PoolMembership
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
            'effective_from' => now()->subHour(),
        ]);
    }

    private function poolDigest(): string
    {
        return $this->app->make(PatientHmac::class)->digest(
            'messaging-pool-ref',
            self::POLICY_VERSION.'|'.self::POOL_KEY,
        );
    }

    private function reconciler(): PatientCommunicationLifecycleReconciliationService
    {
        return $this->app->make(PatientCommunicationLifecycleReconciliationService::class);
    }

    /** @param array<string, mixed> $fixture */
    private function assertLifecycleFacts(
        array $fixture,
        string $staffEventType,
        string $reasonCode,
        string $routingEventType,
        string $receiptState,
    ): void {
        $staffEvent = StaffActionEvent::query()
            ->where('thread_work_item_id', $fixture['work_item']->getKey())
            ->where('event_type', $staffEventType)
            ->sole();
        $routingEvent = PatientMessageRoutingEvent::query()
            ->where('message_thread_id', $fixture['thread']->getKey())
            ->where('event_type', $routingEventType)
            ->sole();
        $receipt = PatientMessageDeliveryReceipt::query()
            ->where('message_id', $fixture['message']->getKey())
            ->where('patient_visible_state', $receiptState)
            ->sole();

        $this->assertNull($staffEvent->actor_user_id);
        $this->assertSame($reasonCode, $staffEvent->reason_code);
        $this->assertEquals([
            'schema_version' => 1,
            'content_included' => false,
            'source' => 'encounter_lifecycle_reconciler',
        ], $staffEvent->metadata);
        $this->assertSame('system', $routingEvent->actor_type);
        $this->assertSame($reasonCode, $routingEvent->reason_code);
        $this->assertEquals(['schema_version' => 1, 'content_included' => false], $routingEvent->metadata);
        $this->assertSame('system', $receipt->actor_type);

        $encoded = json_encode([
            $staffEvent->getAttributes(),
            $routingEvent->getAttributes(),
            $receipt->getAttributes(),
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::PATIENT_QUESTION, $encoded);
        $this->assertStringNotContainsString('encrypted_body', $encoded);
        $this->assertStringNotContainsString('patient_ref', $encoded);
    }

    /** @param array<string, mixed> $fixture @return array{thread: int, work: int} */
    private function versions(array $fixture): array
    {
        return [
            'thread' => (int) $fixture['thread']->fresh()->version,
            'work' => (int) $fixture['work_item']->fresh()->row_version,
        ];
    }

    /** @param array<string, mixed> $fixture @param array{thread: int, work: int} $versions */
    private function assertSkippedWithoutMutation(array $fixture, array $versions): void
    {
        $factCounts = $this->factCounts($fixture);
        $this->assertSame([
            'selected' => 1,
            'rerouted' => 0,
            'released' => 0,
            'closed' => 0,
            'skipped' => 0,
            'unresolved' => 1,
            'failed' => 0,
        ], $this->reconciler()->reconcileOpen());
        $this->assertSame($versions, $this->versions($fixture));
        $this->assertSame($fixture['pool']->getKey(), $fixture['work_item']->fresh()->responsibility_pool_id);
        $this->assertSame($factCounts, $this->factCounts($fixture));
    }

    /** @param array<string, mixed> $fixture @return array{staff: int, routing: int, receipts: int} */
    private function factCounts(array $fixture): array
    {
        return [
            'staff' => StaffActionEvent::query()
                ->where('thread_work_item_id', $fixture['work_item']->getKey())
                ->count(),
            'routing' => PatientMessageRoutingEvent::query()
                ->where('message_thread_id', $fixture['thread']->getKey())
                ->count(),
            'receipts' => PatientMessageDeliveryReceipt::query()
                ->where('message_id', $fixture['message']->getKey())
                ->count(),
        ];
    }
}
