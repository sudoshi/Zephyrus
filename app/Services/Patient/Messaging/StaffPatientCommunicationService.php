<?php

namespace App\Services\Patient\Messaging;

use App\Authorization\Capability;
use App\Models\Encounter;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientMessage;
use App\Models\Patient\PatientMessageDeliveryReceipt;
use App\Models\Patient\PatientMessageRoutingEvent;
use App\Models\Patient\PatientMessageThread;
use App\Models\PatientCommunication\PoolMembership;
use App\Models\PatientCommunication\ResponsibilityPool;
use App\Models\PatientCommunication\StaffActionEvent;
use App\Models\PatientCommunication\ThreadWorkItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\Audit\UserAuditRecorder;
use App\Services\Authorization\RoleCapabilityService;
use App\Services\Mobile\MobilePatientContextReferenceStore;
use App\Services\Patient\PatientHmac;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Capability- and pool-membership-gated staff workflow for patient messages.
 * Every mutation updates the staff projection and patient-visible ledgers in a
 * single transaction. Message content remains only in the encrypted canonical
 * patient message table.
 */
class StaffPatientCommunicationService
{
    private static ?bool $schemaReady = null;

    private const MAX_ROUTE_CANDIDATES = 50;

    private const REQUIRED_TABLES = [
        'patient_experience.encounter_access_grants',
        'patient_experience.message_threads',
        'patient_experience.messages',
        'patient_experience.message_delivery_receipts',
        'patient_experience.message_routing_events',
        'patient_communications.responsibility_pools',
        'patient_communications.pool_memberships',
        'patient_communications.thread_work_items',
        'patient_communications.staff_action_events',
    ];

    public function __construct(
        private readonly RoleCapabilityService $authorization,
        private readonly PatientCommunicationResponderEligibility $responders,
        private readonly PatientCommunicationPoolResolver $pools,
        private readonly PatientMessagingPolicyRegistry $policies,
        private readonly PatientMessageCipher $cipher,
        private readonly PatientHmac $hmac,
        private readonly MobilePatientContextReferenceStore $patientContexts,
        private readonly UserAuditRecorder $audit,
    ) {}

    /** @return array<string, mixed> */
    public function inbox(Request $request, User $user): array
    {
        $this->assertAvailable();
        $this->assertCapability($user, Capability::ViewPatientCommunications);
        $poolIds = $this->effectiveMemberships($user)->pluck('responsibility_pool_id');

        $items = $poolIds->isEmpty()
            ? collect()
            : ThreadWorkItem::query()
                ->with(['thread', 'accessGrant', 'pool', 'assignee'])
                ->whereIn('responsibility_pool_id', $poolIds)
                ->where('status', 'open')
                ->orderByRaw("CASE ownership_state WHEN 'escalated' THEN 0 WHEN 'pool_owned' THEN 1 ELSE 2 END")
                ->orderBy('escalate_at')
                ->orderByDesc('last_message_at')
                ->limit(200)
                ->get();

        $data = $items
            ->filter(fn (ThreadWorkItem $item): bool => $this->matchesCanonicalEncounterScope($item))
            ->map(fn (ThreadWorkItem $item): array => $this->serializeWorkItem($item, $user))
            ->values()
            ->all();

        $this->audit->record('patient_communications.inbox_viewed', 'access', 'success', [
            'request' => $request,
            'actor' => $user,
            'metadata' => ['eligible_count' => count($data)],
        ]);

        return ['items' => $data, 'count' => count($data)];
    }

    /** @return array<string, mixed> */
    public function show(Request $request, User $user, string $workItemUuid): array
    {
        $this->assertAvailable();
        $this->assertCapability($user, Capability::ViewPatientCommunications);
        $workItem = $this->authorizedWorkItem($user, $workItemUuid);
        $workItem->load([
            'thread.messages' => fn ($query) => $query
                ->with(['receipts', 'relatesTo'])
                ->orderByDesc('sent_at')
                ->orderByDesc('message_id')
                ->limit(250),
            'accessGrant',
            'pool',
            'assignee',
        ]);

        $data = $this->serializeWorkItem($workItem, $user, true);

        $this->audit->record('patient_communications.thread_viewed', 'access', 'success', [
            'request' => $request,
            'actor' => $user,
            'target_type' => 'patient_message_thread',
            'target_id' => (string) $workItem->work_item_uuid,
            'metadata' => ['work_item_uuid' => (string) $workItem->work_item_uuid],
        ]);

        return $data;
    }

    /** @return array<string, mixed> */
    public function routeCandidates(Request $request, User $user, string $workItemUuid): array
    {
        $this->assertAvailable();
        $this->assertCapability($user, Capability::RespondPatientCommunications);

        try {
            $workItem = $this->authorizedWorkItem($user, $workItemUuid);
            $thread = $workItem->thread;
            if (! $thread instanceof PatientMessageThread
                || $workItem->status !== 'open'
                || $thread->status !== 'open'
            ) {
                throw StaffPatientCommunicationFailure::threadClosed();
            }

            $membership = PoolMembership::query()
                ->effective()
                ->where('responsibility_pool_id', $workItem->responsibility_pool_id)
                ->where('staff_user_id', $user->getKey())
                ->first();
            if ($membership === null) {
                throw StaffPatientCommunicationFailure::notFound();
            }

            $policy = $this->approvedRoutingPolicy($thread);
            $scope = $this->governedRoutingScope($workItem->accessGrant);
            if (! $workItem->pool instanceof ResponsibilityPool
                || ! $this->poolMatchesGovernedRoute(
                    $workItem->pool,
                    $thread,
                    $policy,
                    $scope,
                )
            ) {
                throw StaffPatientCommunicationFailure::notFound();
            }
            $canSupervise = $membership->membership_role === 'supervisor';
            $hasAssignee = $workItem->assigned_user_id !== null;
            $canRelease = $hasAssignee
                && ($canSupervise || (int) $workItem->assigned_user_id === (int) $user->getKey());

            $reassignCandidates = $canSupervise && $hasAssignee
                ? $this->reassignCandidates($workItem)
                : [];
            $rerouteCandidates = $membership->can_reroute
                ? $this->rerouteCandidates($workItem, $thread, $policy, $scope)
                : [];

            $data = [
                'work_item_uuid' => (string) $workItem->work_item_uuid,
                'work_item_version' => (int) $workItem->row_version,
                'thread_version' => (int) $thread->version,
                'actions' => [
                    'can_release' => $canRelease,
                    'can_reassign' => $reassignCandidates !== [],
                    'can_reroute' => $rerouteCandidates !== [],
                ],
                'reason_options' => StaffPatientCommunicationRoutingPolicy::allReasonOptions(),
                'reassign_candidates' => $reassignCandidates,
                'reroute_candidates' => $rerouteCandidates,
            ];

            $this->audit->record('patient_communications.route_candidates_viewed', 'access', 'success', [
                'request' => $request,
                'actor' => $user,
                'target_type' => 'patient_message_thread',
                'target_id' => (string) $workItem->work_item_uuid,
                'metadata' => [
                    'eligible_count' => count($reassignCandidates) + count($rerouteCandidates),
                ],
            ]);

            return $data;
        } catch (StaffPatientCommunicationFailure $failure) {
            $this->recordDenialAudit($request, $user, $workItemUuid, 'route_candidates_view');

            throw $failure;
        }
    }

    /** @param array<string, mixed> $input */
    public function claim(Request $request, User $user, string $workItemUuid, array $input): array
    {
        $this->assertAvailable();
        $this->assertCapability($user, Capability::RespondPatientCommunications);
        $operationDigest = $this->operationDigest($user, 'claim', (string) $input['idempotency_key']);
        $payloadDigest = $this->payloadDigest([
            'thread_version' => (int) $input['thread_version'],
            'work_item_uuid' => $workItemUuid,
            'work_item_version' => (int) $input['work_item_version'],
        ]);

        try {
            return DB::transaction(function () use (
                $request,
                $user,
                $workItemUuid,
                $input,
                $operationDigest,
                $payloadDigest,
            ): array {
                $this->acquireReplayLocks([$operationDigest]);
                $existing = $this->staffEventReplay(
                    $user,
                    $workItemUuid,
                    'claimed',
                    $operationDigest,
                    $payloadDigest,
                );
                if ($existing !== null) {
                    [$workItem, $event] = $existing;
                    $this->recordMutationAudit($request, $user, $workItem, 'thread_claimed', true);

                    return $this->mutationResult($workItem, $user, true, $event);
                }

                [$workItem, $thread, $membership] = $this->lockAuthorizedWorkItem($user, $workItemUuid);
                $this->assertOpenAndCurrent($workItem, $thread, $input);
                if (! $membership->can_claim) {
                    throw StaffPatientCommunicationFailure::notFound();
                }
                if ($workItem->assigned_user_id !== null) {
                    throw StaffPatientCommunicationFailure::alreadyAssigned();
                }

                $occurredAt = now();
                $workItem->forceFill([
                    'assigned_user_id' => $user->getKey(),
                    'ownership_state' => 'acknowledged',
                    'source_thread_version' => (int) $thread->version + 1,
                    'row_version' => (int) $workItem->row_version + 1,
                    'acknowledged_at' => $occurredAt,
                ])->save();
                $thread->forceFill([
                    'ownership_state' => 'acknowledged',
                    'version' => (int) $thread->version + 1,
                ])->save();

                $event = $this->createStaffEvent(
                    $workItem,
                    'claimed',
                    $user,
                    'staff_claimed',
                    'acknowledged',
                    $operationDigest,
                    $payloadDigest,
                    $occurredAt,
                    toUserId: $user->getKey(),
                );
                $this->createPatientRoutingEvent(
                    $thread,
                    'acknowledged',
                    'staff_claimed',
                    'acknowledged',
                    $operationDigest,
                    $payloadDigest,
                    $user,
                    $occurredAt,
                    toPoolDigest: (string) $thread->responsibility_pool_ref_digest,
                );
                $this->createLatestPatientReceipt(
                    $thread,
                    'team_acknowledged',
                    'acknowledged',
                    $operationDigest,
                    $user,
                    $occurredAt,
                );
                $this->recordMutationAudit($request, $user, $workItem, 'thread_claimed', false);

                return $this->mutationResult(
                    $workItem->fresh(['thread', 'accessGrant', 'pool', 'assignee']),
                    $user,
                    false,
                    $event,
                );
            }, 3);
        } catch (StaffPatientCommunicationFailure $failure) {
            $this->recordDenialAudit($request, $user, $workItemUuid, 'thread_claim');

            throw $failure;
        }
    }

    /** @param array<string, mixed> $input */
    public function release(Request $request, User $user, string $workItemUuid, array $input): array
    {
        $this->assertAvailable();
        $this->assertCapability($user, Capability::RespondPatientCommunications);
        $operationDigest = $this->operationDigest($user, 'release', (string) $input['idempotency_key']);
        $payloadDigest = $this->payloadDigest([
            'reason_code' => (string) $input['reason_code'],
            'thread_version' => (int) $input['thread_version'],
            'work_item_uuid' => $workItemUuid,
            'work_item_version' => (int) $input['work_item_version'],
        ]);

        try {
            return DB::transaction(function () use (
                $request,
                $user,
                $workItemUuid,
                $input,
                $operationDigest,
                $payloadDigest,
            ): array {
                $this->acquireReplayLocks([$operationDigest]);
                $existing = $this->staffEventReplay(
                    $user,
                    $workItemUuid,
                    'released',
                    $operationDigest,
                    $payloadDigest,
                );
                if ($existing !== null) {
                    [$workItem, $event] = $existing;
                    $membership = $this->lockEffectiveMembership(
                        $user,
                        (int) $event->from_pool_id,
                    );
                    if ((int) $event->from_user_id !== (int) $user->getKey()
                        && $membership->membership_role !== 'supervisor'
                    ) {
                        throw StaffPatientCommunicationFailure::notFound();
                    }
                    $this->recordMutationAudit($request, $user, $workItem, 'thread_released', true);

                    return $this->mutationResult($workItem, $user, true, $event);
                }

                [$workItem, $thread, $membership] = $this->lockAuthorizedWorkItem($user, $workItemUuid);
                $this->assertOpenAndCurrent($workItem, $thread, $input);
                $fromUserId = $workItem->assigned_user_id;
                if ($fromUserId === null
                    || ((int) $fromUserId !== (int) $user->getKey()
                        && $membership->membership_role !== 'supervisor')
                ) {
                    throw StaffPatientCommunicationFailure::notFound();
                }

                $occurredAt = now();
                $poolId = (int) $workItem->responsibility_pool_id;
                $poolDigest = (string) $thread->responsibility_pool_ref_digest;
                $thread->forceFill([
                    'ownership_state' => 'assigned',
                    'version' => (int) $thread->version + 1,
                ])->save();
                $workItem->forceFill([
                    'assigned_user_id' => null,
                    'ownership_state' => 'pool_owned',
                    'source_thread_version' => (int) $thread->version,
                    'row_version' => (int) $workItem->row_version + 1,
                    'acknowledged_at' => null,
                ])->save();

                $event = $this->createStaffEvent(
                    $workItem,
                    'released',
                    $user,
                    (string) $input['reason_code'],
                    'assigned',
                    $operationDigest,
                    $payloadDigest,
                    $occurredAt,
                    fromUserId: (int) $fromUserId,
                    fromPoolId: $poolId,
                    toPoolId: $poolId,
                );
                $this->createPatientRoutingEvent(
                    $thread,
                    'assigned',
                    (string) $input['reason_code'],
                    'assigned',
                    $operationDigest,
                    $payloadDigest,
                    $user,
                    $occurredAt,
                    fromPoolDigest: $poolDigest,
                    toPoolDigest: $poolDigest,
                );
                $this->createLatestPatientReceipt(
                    $thread,
                    'assigned',
                    'assigned',
                    $operationDigest,
                    $user,
                    $occurredAt,
                );
                $this->recordMutationAudit($request, $user, $workItem, 'thread_released', false);

                return $this->mutationResult(
                    $workItem->fresh(['thread', 'accessGrant', 'pool', 'assignee']),
                    $user,
                    false,
                    $event,
                );
            }, 3);
        } catch (StaffPatientCommunicationFailure $failure) {
            $this->recordDenialAudit($request, $user, $workItemUuid, 'thread_release');

            throw $failure;
        }
    }

    /** @param array<string, mixed> $input */
    public function reassign(Request $request, User $user, string $workItemUuid, array $input): array
    {
        $this->assertAvailable();
        $this->assertCapability($user, Capability::RespondPatientCommunications);
        $operationDigest = $this->operationDigest($user, 'reassign', (string) $input['idempotency_key']);
        $payloadDigest = $this->payloadDigest([
            'reason_code' => (string) $input['reason_code'],
            'target_membership_uuid' => (string) $input['target_membership_uuid'],
            'thread_version' => (int) $input['thread_version'],
            'work_item_uuid' => $workItemUuid,
            'work_item_version' => (int) $input['work_item_version'],
        ]);

        try {
            return DB::transaction(function () use (
                $request,
                $user,
                $workItemUuid,
                $input,
                $operationDigest,
                $payloadDigest,
            ): array {
                $this->acquireReplayLocks([$operationDigest]);
                $existing = $this->staffEventReplay(
                    $user,
                    $workItemUuid,
                    'reassigned',
                    $operationDigest,
                    $payloadDigest,
                );
                if ($existing !== null) {
                    [$workItem, $event] = $existing;
                    $membership = $this->lockEffectiveMembership(
                        $user,
                        (int) $event->from_pool_id,
                    );
                    if ($membership->membership_role !== 'supervisor') {
                        throw StaffPatientCommunicationFailure::notFound();
                    }
                    $targetMembership = $this->lockEligibleTargetMembership(
                        (string) $input['target_membership_uuid'],
                        (int) $event->to_pool_id,
                        (int) $event->from_user_id,
                    );
                    if ((int) $targetMembership->staff_user_id !== (int) $event->to_user_id) {
                        throw StaffPatientCommunicationFailure::idempotencyConflict();
                    }
                    $this->recordMutationAudit($request, $user, $workItem, 'thread_reassigned', true);

                    return $this->mutationResult($workItem, $user, true, $event);
                }

                [$workItem, $thread, $membership] = $this->lockAuthorizedWorkItem($user, $workItemUuid);
                $this->assertOpenAndCurrent($workItem, $thread, $input);
                if ($membership->membership_role !== 'supervisor'
                    || $workItem->assigned_user_id === null
                ) {
                    throw StaffPatientCommunicationFailure::notFound();
                }

                $targetMembership = $this->lockEligibleTargetMembership(
                    (string) $input['target_membership_uuid'],
                    (int) $workItem->responsibility_pool_id,
                    (int) $workItem->assigned_user_id,
                );
                $fromUserId = (int) $workItem->assigned_user_id;
                $toUserId = (int) $targetMembership->staff_user_id;
                $poolId = (int) $workItem->responsibility_pool_id;
                $poolDigest = (string) $thread->responsibility_pool_ref_digest;
                $occurredAt = now();

                $thread->forceFill([
                    'ownership_state' => 'assigned',
                    'version' => (int) $thread->version + 1,
                ])->save();
                $workItem->forceFill([
                    'assigned_user_id' => $toUserId,
                    'ownership_state' => 'assigned',
                    'source_thread_version' => (int) $thread->version,
                    'row_version' => (int) $workItem->row_version + 1,
                    'acknowledged_at' => null,
                ])->save();

                $event = $this->createStaffEvent(
                    $workItem,
                    'reassigned',
                    $user,
                    (string) $input['reason_code'],
                    'assigned',
                    $operationDigest,
                    $payloadDigest,
                    $occurredAt,
                    fromUserId: $fromUserId,
                    toUserId: $toUserId,
                    fromPoolId: $poolId,
                    toPoolId: $poolId,
                );
                $this->createPatientRoutingEvent(
                    $thread,
                    'assigned',
                    (string) $input['reason_code'],
                    'assigned',
                    $operationDigest,
                    $payloadDigest,
                    $user,
                    $occurredAt,
                    fromPoolDigest: $poolDigest,
                    toPoolDigest: $poolDigest,
                );
                $this->createLatestPatientReceipt(
                    $thread,
                    'assigned',
                    'assigned',
                    $operationDigest,
                    $user,
                    $occurredAt,
                );
                $this->recordMutationAudit($request, $user, $workItem, 'thread_reassigned', false);

                return $this->mutationResult(
                    $workItem->fresh(['thread', 'accessGrant', 'pool', 'assignee']),
                    $user,
                    false,
                    $event,
                );
            }, 3);
        } catch (StaffPatientCommunicationFailure $failure) {
            $this->recordDenialAudit($request, $user, $workItemUuid, 'thread_reassign');

            throw $failure;
        }
    }

    /** @param array<string, mixed> $input */
    public function reroute(Request $request, User $user, string $workItemUuid, array $input): array
    {
        $this->assertAvailable();
        $this->assertCapability($user, Capability::RespondPatientCommunications);
        $operationDigest = $this->operationDigest($user, 'reroute', (string) $input['idempotency_key']);
        $payloadDigest = $this->payloadDigest([
            'reason_code' => (string) $input['reason_code'],
            'target_pool_uuid' => (string) $input['target_pool_uuid'],
            'thread_version' => (int) $input['thread_version'],
            'work_item_uuid' => $workItemUuid,
            'work_item_version' => (int) $input['work_item_version'],
        ]);

        try {
            return DB::transaction(function () use (
                $request,
                $user,
                $workItemUuid,
                $input,
                $operationDigest,
                $payloadDigest,
            ): array {
                $this->acquireReplayLocks([$operationDigest]);
                $existing = $this->rerouteReplay(
                    $user,
                    $operationDigest,
                    $payloadDigest,
                );
                if ($existing !== null) {
                    // A reroute retry acknowledges only the immutable event that
                    // committed for this exact request. It must not project the
                    // destination's current state, refresh a patient-context
                    // handle, or append a second mutation/audit fact.
                    return [
                        'work_item' => null,
                        'message' => null,
                        'event_uuid' => (string) $existing->event_uuid,
                        'replayed' => true,
                    ];
                }

                [$workItem, $thread, $membership] = $this->lockAuthorizedWorkItem($user, $workItemUuid);
                $this->assertOpenAndCurrent($workItem, $thread, $input);
                if (! $membership->can_reroute) {
                    throw StaffPatientCommunicationFailure::notFound();
                }

                $policy = $this->approvedRoutingPolicy($thread);
                $scope = $this->lockedGovernedRoutingScope($workItem->accessGrant);
                $fromPool = $workItem->pool;
                if (! $fromPool instanceof ResponsibilityPool) {
                    throw StaffPatientCommunicationFailure::notFound();
                }
                if (! $this->poolMatchesGovernedRoute($fromPool, $thread, $policy, $scope)) {
                    throw StaffPatientCommunicationFailure::notFound();
                }
                $targetPool = ResponsibilityPool::query()
                    ->where('pool_uuid', (string) $input['target_pool_uuid'])
                    ->lockForUpdate()
                    ->first();
                if (! $targetPool instanceof ResponsibilityPool
                    || (int) $targetPool->getKey() === (int) $fromPool->getKey()
                    || ! $this->poolMatchesGovernedRoute($targetPool, $thread, $policy, $scope, true)
                ) {
                    throw StaffPatientCommunicationFailure::notFound();
                }

                $fromPoolId = (int) $fromPool->getKey();
                $toPoolId = (int) $targetPool->getKey();
                $fromPoolDigest = (string) $thread->responsibility_pool_ref_digest;
                $toPoolDigest = (string) $targetPool->pool_key_digest;
                $fromUserId = $workItem->assigned_user_id !== null
                    ? (int) $workItem->assigned_user_id
                    : null;
                $occurredAt = now();

                $thread->forceFill([
                    'responsibility_pool_ref_digest' => $toPoolDigest,
                    'ownership_state' => 'rerouted',
                    'version' => (int) $thread->version + 1,
                ])->save();
                $workItem->forceFill([
                    'responsibility_pool_id' => $toPoolId,
                    'assigned_user_id' => null,
                    'ownership_state' => 'rerouted',
                    'source_thread_version' => (int) $thread->version,
                    'row_version' => (int) $workItem->row_version + 1,
                    'due_at' => $occurredAt->copy()->addMinutes((int) $targetPool->response_target_minutes),
                    'escalate_at' => $occurredAt->copy()->addMinutes((int) $targetPool->escalation_target_minutes),
                    'acknowledged_at' => null,
                    'responded_at' => null,
                ])->save();

                $event = $this->createStaffEvent(
                    $workItem,
                    'rerouted',
                    $user,
                    (string) $input['reason_code'],
                    'rerouted',
                    $operationDigest,
                    $payloadDigest,
                    $occurredAt,
                    fromUserId: $fromUserId,
                    fromPoolId: $fromPoolId,
                    toPoolId: $toPoolId,
                );
                $this->createPatientRoutingEvent(
                    $thread,
                    'rerouted',
                    (string) $input['reason_code'],
                    'rerouted',
                    $operationDigest,
                    $payloadDigest,
                    $user,
                    $occurredAt,
                    fromPoolDigest: $fromPoolDigest,
                    toPoolDigest: $toPoolDigest,
                );
                $this->createLatestPatientReceipt(
                    $thread,
                    'assigned',
                    'assigned',
                    $operationDigest,
                    $user,
                    $occurredAt,
                );
                $this->recordMutationAudit($request, $user, $workItem, 'thread_rerouted', false);

                return $this->mutationResult(
                    $workItem->fresh(['thread', 'accessGrant', 'pool', 'assignee']),
                    $user,
                    false,
                    $event,
                );
            }, 3);
        } catch (StaffPatientCommunicationFailure $failure) {
            $this->recordDenialAudit($request, $user, $workItemUuid, 'thread_reroute');

            throw $failure;
        }
    }

    /** @param array<string, mixed> $input */
    public function reply(Request $request, User $user, string $workItemUuid, array $input): array
    {
        $this->assertAvailable();
        $this->assertCapability($user, Capability::RespondPatientCommunications);
        $policy = $this->staffWritePolicy();
        $body = (string) $input['message'];
        $bodyDigest = $this->hmac->digest('messaging-body', $body);
        $actorDigest = $this->staffActorDigest($user);
        $operationDigest = $this->operationDigest($user, 'reply', (string) $input['idempotency_key']);
        $payloadDigest = $this->payloadDigest([
            'body_digest' => $bodyDigest,
            'client_message_uuid' => (string) $input['client_message_uuid'],
            'thread_version' => (int) $input['thread_version'],
            'work_item_uuid' => $workItemUuid,
            'work_item_version' => (int) $input['work_item_version'],
        ]);

        try {
            return DB::transaction(function () use (
                $request,
                $user,
                $workItemUuid,
                $input,
                $policy,
                $body,
                $bodyDigest,
                $actorDigest,
                $operationDigest,
                $payloadDigest,
            ): array {
                $this->acquireReplayLocks([
                    $operationDigest,
                    'staff-client-message|'.(string) $input['client_message_uuid'],
                ]);
                $operationEvent = StaffActionEvent::query()
                    ->where('idempotency_key_digest', $operationDigest)
                    ->first();
                $clientMessage = PatientMessage::query()
                    ->where('client_message_uuid', (string) $input['client_message_uuid'])
                    ->first();
                if ($operationEvent !== null || $clientMessage !== null) {
                    $event = $operationEvent ?? StaffActionEvent::query()
                        ->where('message_id', $clientMessage?->getKey())
                        ->first();
                    if ($event === null
                        || $clientMessage === null
                        || (int) $event->message_id !== (int) $clientMessage->getKey()
                        || ! hash_equals((string) $event->request_payload_digest, $payloadDigest)
                        || ! hash_equals((string) $clientMessage->request_payload_digest, $payloadDigest)
                        || ! hash_equals((string) $clientMessage->sender_actor_ref_digest, $actorDigest)
                    ) {
                        throw StaffPatientCommunicationFailure::idempotencyConflict();
                    }
                    $workItem = $this->lockedReplayWorkItem($user, $workItemUuid, $event);
                    $this->recordMutationAudit($request, $user, $workItem, 'thread_replied', true);

                    return $this->mutationResult($workItem, $user, true, $event, $clientMessage);
                }

                [$workItem, $thread, $membership] = $this->lockAuthorizedWorkItem($user, $workItemUuid);
                $this->assertOpenAndCurrent($workItem, $thread, $input);
                if (! $membership->can_reply || (int) $workItem->assigned_user_id !== (int) $user->getKey()) {
                    throw StaffPatientCommunicationFailure::notFound();
                }

                $occurredAt = now();
                $messageUuid = (string) Str::uuid7();
                $message = PatientMessage::query()->create([
                    'message_uuid' => $messageUuid,
                    'message_thread_id' => $thread->getKey(),
                    'sender_type' => 'staff',
                    'sender_actor_ref_digest' => $actorDigest,
                    'visibility' => 'patient_visible',
                    'message_kind' => 'message',
                    'encrypted_body' => $this->cipher->encrypt(
                        $body,
                        $policy['encryption_key_version'],
                        $this->cipher->contextFor((string) $thread->thread_uuid, $messageUuid),
                    ),
                    'encryption_key_version' => $policy['encryption_key_version'],
                    'body_digest' => $bodyDigest,
                    'body_character_count' => mb_strlen($body),
                    'client_message_uuid' => (string) $input['client_message_uuid'],
                    'idempotency_key_digest' => $operationDigest,
                    'request_payload_digest' => $payloadDigest,
                    'delivery_state' => 'delivered',
                    'sent_at' => $occurredAt,
                ]);
                PatientMessageDeliveryReceipt::query()->create([
                    'receipt_uuid' => (string) Str::uuid7(),
                    'message_id' => $message->getKey(),
                    'receipt_type' => 'team_responded',
                    'actor_type' => 'staff',
                    'actor_ref_digest' => $actorDigest,
                    'patient_visible_state' => 'responded',
                    'idempotency_key_digest' => $this->derivedDigest('messaging-receipt', $operationDigest),
                    'occurred_at' => $occurredAt,
                ]);

                $thread->forceFill([
                    'ownership_state' => 'responded',
                    'version' => (int) $thread->version + 1,
                    'last_message_at' => $occurredAt,
                ])->save();
                $workItem->forceFill([
                    'ownership_state' => 'responded',
                    'source_thread_version' => (int) $thread->version,
                    'row_version' => (int) $workItem->row_version + 1,
                    'last_message_at' => $occurredAt,
                    'responded_at' => $occurredAt,
                ])->save();

                $event = $this->createStaffEvent(
                    $workItem,
                    'replied',
                    $user,
                    'staff_replied',
                    'responded',
                    $operationDigest,
                    $payloadDigest,
                    $occurredAt,
                    messageId: $message->getKey(),
                    fromUserId: $user->getKey(),
                    toUserId: $user->getKey(),
                );
                $this->createPatientRoutingEvent(
                    $thread,
                    'responded',
                    'staff_replied',
                    'responded',
                    $operationDigest,
                    $payloadDigest,
                    $user,
                    $occurredAt,
                    toPoolDigest: (string) $thread->responsibility_pool_ref_digest,
                );
                $this->createLatestPatientReceipt(
                    $thread,
                    'team_responded',
                    'responded',
                    $operationDigest,
                    $user,
                    $occurredAt,
                    excludingMessageId: $message->getKey(),
                );
                $this->recordMutationAudit($request, $user, $workItem, 'thread_replied', false);

                return $this->mutationResult(
                    $workItem->fresh(['thread', 'accessGrant', 'pool', 'assignee']),
                    $user,
                    false,
                    $event,
                    $message,
                );
            }, 3);
        } catch (StaffPatientCommunicationFailure $failure) {
            $this->recordDenialAudit($request, $user, $workItemUuid, 'thread_reply');

            throw $failure;
        }
    }

    /** @param array<string, mixed> $input */
    public function close(Request $request, User $user, string $workItemUuid, array $input): array
    {
        $this->assertAvailable();
        $this->assertCapability($user, Capability::RespondPatientCommunications);
        $operationDigest = $this->operationDigest($user, 'close', (string) $input['idempotency_key']);
        $payloadDigest = $this->payloadDigest([
            'reason_code' => (string) $input['reason_code'],
            'thread_version' => (int) $input['thread_version'],
            'work_item_uuid' => $workItemUuid,
            'work_item_version' => (int) $input['work_item_version'],
        ]);

        try {
            return DB::transaction(function () use (
                $request,
                $user,
                $workItemUuid,
                $input,
                $operationDigest,
                $payloadDigest,
            ): array {
                $this->acquireReplayLocks([$operationDigest]);
                $existing = $this->staffEventReplay(
                    $user,
                    $workItemUuid,
                    'closed',
                    $operationDigest,
                    $payloadDigest,
                );
                if ($existing !== null) {
                    [$workItem, $event] = $existing;
                    $this->recordMutationAudit($request, $user, $workItem, 'thread_closed', true);

                    return $this->mutationResult($workItem, $user, true, $event);
                }

                [$workItem, $thread, $membership] = $this->lockAuthorizedWorkItem($user, $workItemUuid);
                $this->assertOpenAndCurrent($workItem, $thread, $input);
                if (! $membership->can_close || (int) $workItem->assigned_user_id !== (int) $user->getKey()) {
                    throw StaffPatientCommunicationFailure::notFound();
                }
                $latestPatientMessageId = PatientMessage::query()
                    ->where('message_thread_id', $thread->getKey())
                    ->whereIn('sender_type', ['patient', 'representative'])
                    ->max('message_id');
                $latestResponseExists = $latestPatientMessageId !== null
                    && PatientMessage::query()
                        ->where('message_thread_id', $thread->getKey())
                        ->where('sender_type', 'staff')
                        ->where('visibility', 'patient_visible')
                        ->where('message_id', '>', (int) $latestPatientMessageId)
                        ->exists();
                if (! $latestResponseExists) {
                    throw StaffPatientCommunicationFailure::responseRequired();
                }

                $occurredAt = now();
                $thread->forceFill([
                    'status' => 'closed',
                    'ownership_state' => 'closed',
                    'version' => (int) $thread->version + 1,
                    'closed_at' => $occurredAt,
                    'close_reason_code' => 'question_answered',
                ])->save();
                $workItem->forceFill([
                    'status' => 'closed',
                    'ownership_state' => 'closed',
                    'source_thread_version' => (int) $thread->version,
                    'row_version' => (int) $workItem->row_version + 1,
                    'closed_at' => $occurredAt,
                    'close_reason_code' => (string) $input['reason_code'],
                ])->save();

                $event = $this->createStaffEvent(
                    $workItem,
                    'closed',
                    $user,
                    (string) $input['reason_code'],
                    'closed',
                    $operationDigest,
                    $payloadDigest,
                    $occurredAt,
                    fromUserId: $user->getKey(),
                );
                $this->createPatientRoutingEvent(
                    $thread,
                    'closed',
                    (string) $input['reason_code'],
                    'closed',
                    $operationDigest,
                    $payloadDigest,
                    $user,
                    $occurredAt,
                    fromPoolDigest: (string) $thread->responsibility_pool_ref_digest,
                );
                $this->createLatestPatientReceipt(
                    $thread,
                    'closed',
                    'closed',
                    $operationDigest,
                    $user,
                    $occurredAt,
                );
                $this->recordMutationAudit($request, $user, $workItem, 'thread_closed', false);

                return $this->mutationResult(
                    $workItem->fresh(['thread', 'accessGrant', 'pool', 'assignee']),
                    $user,
                    false,
                    $event,
                );
            }, 3);
        } catch (StaffPatientCommunicationFailure $failure) {
            $this->recordDenialAudit($request, $user, $workItemUuid, 'thread_close');

            throw $failure;
        }
    }

    private function assertAvailable(): void
    {
        if (! (bool) config('hummingbird-patient.enabled', false)
            || ! (bool) config('hummingbird-patient.features.messaging', false)
            || ! (bool) config('hummingbird-patient.staff_messaging.enabled', false)
            || config('hummingbird-patient.staff_messaging.governance_status') !== 'approved'
            || ! $this->schemaReady()
        ) {
            throw StaffPatientCommunicationFailure::unavailable();
        }
    }

    private function schemaReady(): bool
    {
        return self::$schemaReady ??= ! collect(self::REQUIRED_TABLES)->contains(
            fn (string $table): bool => ! Schema::hasTable($table),
        );
    }

    /** @return array<string, mixed> */
    private function staffWritePolicy(): array
    {
        try {
            return $this->policies->contentWritePolicy();
        } catch (PatientMessagingFailure) {
            throw StaffPatientCommunicationFailure::unavailable();
        }
    }

    private function assertCapability(User $user, Capability $capability): void
    {
        if (! $this->authorization->allows($user, $capability)) {
            throw StaffPatientCommunicationFailure::notFound();
        }
    }

    private function effectiveMemberships(User $user): Collection
    {
        return PoolMembership::query()
            ->effective()
            ->where('staff_user_id', $user->getKey())
            ->whereHas('pool', fn (Builder $pool): Builder => $pool->where('status', 'active'))
            ->get();
    }

    /** @return array<string, mixed> */
    private function approvedRoutingPolicy(PatientMessageThread $thread): array
    {
        try {
            $policy = $this->policies->disclosurePolicy();
        } catch (PatientMessagingFailure) {
            throw StaffPatientCommunicationFailure::unavailable();
        }

        $policyVersion = $policy['policy_version'] ?? null;
        $topics = $policy['topics'] ?? null;
        $topic = is_array($topics) ? ($topics[(string) $thread->topic_code] ?? null) : null;
        if (! is_string($policyVersion)
            || ! hash_equals($policyVersion, (string) $thread->routing_policy_version)
            || ! is_array($topic)
            || ! is_string($topic['responsibility_pool_key'] ?? null)
        ) {
            throw StaffPatientCommunicationFailure::notFound();
        }

        return $policy;
    }

    /** @return array{unit_id: int, facility_key: string|null} */
    private function governedRoutingScope(?PatientEncounterAccessGrant $grant): array
    {
        if (! $grant instanceof PatientEncounterAccessGrant) {
            throw StaffPatientCommunicationFailure::notFound();
        }

        $scope = $this->pools->scopeForGrant($grant);
        if ($scope === null) {
            throw StaffPatientCommunicationFailure::notFound();
        }

        return $scope;
    }

    /** @return array{unit_id: int, facility_key: string|null} */
    private function lockedGovernedRoutingScope(?PatientEncounterAccessGrant $grant): array
    {
        if (! $grant instanceof PatientEncounterAccessGrant) {
            throw StaffPatientCommunicationFailure::notFound();
        }

        $scope = $this->pools->scopeForGrant($grant, true);
        if ($scope === null) {
            throw StaffPatientCommunicationFailure::notFound();
        }

        return $scope;
    }

    /** @return list<array{membership_uuid: string, label: string, membership_role: string}> */
    private function reassignCandidates(ThreadWorkItem $workItem): array
    {
        return $this->responders->eligibleMembershipsForPool(
            (int) $workItem->responsibility_pool_id,
            $workItem->assigned_user_id !== null ? (int) $workItem->assigned_user_id : null,
        )
            ->map(fn (PoolMembership $membership): array => [
                'membership_uuid' => (string) $membership->membership_uuid,
                'label' => $this->staffCandidateLabel($membership->user),
                'membership_role' => (string) $membership->membership_role,
            ])
            ->sortBy([['label', 'asc'], ['membership_uuid', 'asc']])
            ->take(self::MAX_ROUTE_CANDIDATES)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $policy
     * @param  array{unit_id: int, facility_key: string|null}  $scope
     * @return list<array{pool_uuid: string, label: string, scope_type: string, unit: array{id: int, label: string}|null}>
     */
    private function rerouteCandidates(
        ThreadWorkItem $workItem,
        PatientMessageThread $thread,
        array $policy,
        array $scope,
    ): array {
        $poolKey = $policy['topics'][(string) $thread->topic_code]['responsibility_pool_key'];
        $expectedPoolDigest = $this->hmac->digest(
            'messaging-pool-ref',
            (string) $thread->routing_policy_version.'|'.(string) $poolKey,
        );

        return $this->pools->eligibleCandidatesForScope(
            (string) $thread->routing_policy_version,
            (string) $thread->topic_code,
            $expectedPoolDigest,
            $scope,
            (int) $workItem->responsibility_pool_id,
        )
            ->map(fn (ResponsibilityPool $pool): array => [
                'pool_uuid' => (string) $pool->pool_uuid,
                'label' => Str::limit(trim((string) $pool->display_name), 120, ''),
                'scope_type' => (string) $pool->scope_type,
                'unit' => $pool->scope_type === 'unit' && $pool->unit instanceof Unit
                    ? [
                        'id' => (int) $pool->unit->getKey(),
                        'label' => (string) $pool->unit->name,
                    ]
                    : null,
            ])
            ->take(self::MAX_ROUTE_CANDIDATES)
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $policy */
    private function poolMatchesGovernedRoute(
        ResponsibilityPool $pool,
        PatientMessageThread $thread,
        array $policy,
        array $scope,
        bool $requireEligibleResponder = false,
    ): bool {
        $topic = $policy['topics'][(string) $thread->topic_code] ?? null;
        $poolKey = is_array($topic) ? ($topic['responsibility_pool_key'] ?? null) : null;
        if (! is_string($poolKey)) {
            return false;
        }
        $expectedPoolDigest = $this->hmac->digest(
            'messaging-pool-ref',
            (string) $thread->routing_policy_version.'|'.$poolKey,
        );
        if (! hash_equals($expectedPoolDigest, (string) $thread->responsibility_pool_ref_digest)) {
            return false;
        }

        return $requireEligibleResponder
            ? $this->pools->poolIsEligibleForScope(
                $pool,
                (string) $thread->routing_policy_version,
                (string) $thread->topic_code,
                $expectedPoolDigest,
                $scope,
                true,
            )
            : $this->pools->poolMatchesScope(
                $pool,
                (string) $thread->routing_policy_version,
                (string) $thread->topic_code,
                $expectedPoolDigest,
                $scope,
            );
    }

    private function staffCandidateLabel(User $user): string
    {
        foreach ([$user->name, $user->username] as $candidate) {
            $label = trim((string) $candidate);
            if ($label !== '') {
                return Str::limit($label, 120, '');
            }
        }

        return 'Eligible care team member';
    }

    private function authorizedWorkItem(User $user, string $workItemUuid): ThreadWorkItem
    {
        $poolIds = $this->effectiveMemberships($user)->pluck('responsibility_pool_id');
        $workItem = ThreadWorkItem::query()
            ->with(['thread', 'accessGrant', 'pool', 'assignee'])
            ->where('work_item_uuid', $workItemUuid)
            ->whereIn('responsibility_pool_id', $poolIds)
            ->first();

        if ($workItem === null
            || ! $workItem->thread instanceof PatientMessageThread
            || ! $workItem->accessGrant instanceof PatientEncounterAccessGrant
            || (int) $workItem->thread->access_grant_id !== (int) $workItem->access_grant_id
            || ! $this->matchesCanonicalEncounterScope($workItem)
        ) {
            throw StaffPatientCommunicationFailure::notFound();
        }

        return $workItem;
    }

    /**
     * @return array{0: ThreadWorkItem, 1: PatientMessageThread, 2: PoolMembership}
     */
    private function lockAuthorizedWorkItem(User $user, string $workItemUuid): array
    {
        // Resolve immutable IDs without a lock, then acquire the canonical
        // thread-before-work-item order also used by the handoff consumer.
        // This avoids the thread/work projection deadlock cycle while every
        // mutable authorization fact is still re-read under lock below.
        $candidate = ThreadWorkItem::query()
            ->where('work_item_uuid', $workItemUuid)
            ->first();
        if ($candidate === null) {
            throw StaffPatientCommunicationFailure::notFound();
        }

        $thread = PatientMessageThread::query()->lockForUpdate()->find($candidate->message_thread_id);
        $workItem = ThreadWorkItem::query()->lockForUpdate()->find($candidate->getKey());
        if ($workItem === null
            || $thread === null
            || (int) $workItem->message_thread_id !== (int) $thread->getKey()
            || (int) $workItem->access_grant_id !== (int) $thread->access_grant_id
        ) {
            throw StaffPatientCommunicationFailure::notFound();
        }

        $grant = PatientEncounterAccessGrant::query()->lockForUpdate()->find($workItem->access_grant_id);
        $pool = ResponsibilityPool::query()->lockForUpdate()->find($workItem->responsibility_pool_id);
        $membership = PoolMembership::query()
            ->effective()
            ->where('responsibility_pool_id', $workItem->responsibility_pool_id)
            ->where('staff_user_id', $user->getKey())
            ->lockForUpdate()
            ->first();

        if ($grant === null || $pool === null || $membership === null
            || $pool->status !== 'active'
        ) {
            throw StaffPatientCommunicationFailure::notFound();
        }

        $this->lockActorCapability($user, Capability::RespondPatientCommunications);

        $workItem->setRelation('thread', $thread);
        $workItem->setRelation('accessGrant', $grant);
        $workItem->setRelation('pool', $pool);

        if (! $this->matchesCanonicalEncounterScope($workItem, true)) {
            throw StaffPatientCommunicationFailure::notFound();
        }

        return [$workItem, $thread, $membership];
    }

    /**
     * Scheduler lag must never extend the old care team's disclosure window.
     * The current encounter, exact grant identity, and governed pool scope are
     * re-evaluated synchronously before any staff read or mutation.
     */
    private function matchesCanonicalEncounterScope(ThreadWorkItem $workItem, bool $lock = false): bool
    {
        $thread = $workItem->thread;
        $grant = $workItem->accessGrant;
        $pool = $workItem->pool;
        if (! $thread instanceof PatientMessageThread
            || ! $grant instanceof PatientEncounterAccessGrant
            || ! $pool instanceof ResponsibilityPool
            || (int) $thread->access_grant_id !== (int) $workItem->access_grant_id
            || (int) $grant->getKey() !== (int) $workItem->access_grant_id
        ) {
            return false;
        }

        try {
            $scope = $this->pools->scopeForGrant($grant, $lock);

            return $scope !== null && $this->pools->poolMatchesScope(
                $pool,
                (string) $thread->routing_policy_version,
                (string) $thread->topic_code,
                (string) $thread->responsibility_pool_ref_digest,
                $scope,
                $lock,
            );
        } catch (Throwable) {
            return false;
        }
    }

    private function lockActorCapability(User $user, Capability $capability): User
    {
        $lockedUser = User::query()->lockForUpdate()->find($user->getKey());
        if (! $lockedUser instanceof User
            || ! $lockedUser->is_active
            || ! $this->authorization->allows($lockedUser, $capability)
        ) {
            throw StaffPatientCommunicationFailure::notFound();
        }

        return $lockedUser;
    }

    private function lockEffectiveMembership(User $user, int $poolId): PoolMembership
    {
        $membership = PoolMembership::query()
            ->effective()
            ->where('responsibility_pool_id', $poolId)
            ->where('staff_user_id', $user->getKey())
            ->lockForUpdate()
            ->first();
        if (! $membership instanceof PoolMembership) {
            throw StaffPatientCommunicationFailure::notFound();
        }

        $this->lockActorCapability($user, Capability::RespondPatientCommunications);

        return $membership;
    }

    private function lockEligibleTargetMembership(
        string $membershipUuid,
        int $poolId,
        ?int $excludedUserId,
    ): PoolMembership {
        $membership = $this->responders->eligibleMembershipByUuid(
            $membershipUuid,
            $poolId,
            $excludedUserId,
            true,
        );
        if (! $membership instanceof PoolMembership) {
            throw StaffPatientCommunicationFailure::notFound();
        }

        return $membership;
    }

    /**
     * Resolve an exact committed reroute to its immutable event receipt.
     *
     * The exact request payload digest already binds the submitted work-item
     * UUID, versions, target, and reason. The only live dependency is therefore
     * the actor's effective source-pool membership and can_reroute authority.
     * No mutable work/destination projection, patient thread, grant, assignee,
     * message, policy, or version is read.
     */
    private function rerouteReplay(
        User $user,
        string $operationDigest,
        string $payloadDigest,
    ): ?StaffActionEvent {
        $event = StaffActionEvent::query()
            ->where('idempotency_key_digest', $operationDigest)
            ->lockForUpdate()
            ->first();
        if ($event === null) {
            return null;
        }
        if ($event->event_type !== 'rerouted'
            || (int) $event->actor_user_id !== (int) $user->getKey()
            || $event->from_pool_id === null
            || $event->to_pool_id === null
            || ! hash_equals((string) $event->request_payload_digest, $payloadDigest)
        ) {
            throw StaffPatientCommunicationFailure::idempotencyConflict();
        }

        $membership = $this->lockEffectiveMembership($user, (int) $event->from_pool_id);
        if (! $membership->can_reroute) {
            throw StaffPatientCommunicationFailure::notFound();
        }

        return $event;
    }

    /** @param array<string, mixed> $input */
    private function assertOpenAndCurrent(
        ThreadWorkItem $workItem,
        PatientMessageThread $thread,
        array $input,
    ): void {
        if ($workItem->status !== 'open' || $thread->status !== 'open') {
            throw StaffPatientCommunicationFailure::threadClosed();
        }
        if ((int) $workItem->row_version !== (int) $input['work_item_version']
            || (int) $thread->version !== (int) $input['thread_version']
        ) {
            throw StaffPatientCommunicationFailure::staleVersion();
        }
    }

    /** @return array{0: ThreadWorkItem, 1: StaffActionEvent}|null */
    private function staffEventReplay(
        User $user,
        string $workItemUuid,
        string $eventType,
        string $operationDigest,
        string $payloadDigest,
    ): ?array {
        $event = StaffActionEvent::query()
            ->where('idempotency_key_digest', $operationDigest)
            ->lockForUpdate()
            ->first();
        if ($event === null) {
            return null;
        }
        if ($event->event_type !== $eventType
            || (int) $event->actor_user_id !== (int) $user->getKey()
            || ! hash_equals((string) $event->request_payload_digest, $payloadDigest)
        ) {
            throw StaffPatientCommunicationFailure::idempotencyConflict();
        }

        return [$this->lockedReplayWorkItem($user, $workItemUuid, $event), $event];
    }

    private function lockedReplayWorkItem(
        User $user,
        string $workItemUuid,
        StaffActionEvent $event,
    ): ThreadWorkItem {
        [$workItem] = $this->lockAuthorizedWorkItem($user, $workItemUuid);
        if ((int) $event->thread_work_item_id !== (int) $workItem->getKey()) {
            throw StaffPatientCommunicationFailure::idempotencyConflict();
        }

        return $workItem->load(['thread', 'accessGrant', 'pool', 'assignee']);
    }

    private function createStaffEvent(
        ThreadWorkItem $workItem,
        string $eventType,
        User $user,
        string $reasonCode,
        string $patientVisibleState,
        string $operationDigest,
        string $payloadDigest,
        mixed $occurredAt,
        ?int $messageId = null,
        ?int $fromUserId = null,
        ?int $toUserId = null,
        ?int $fromPoolId = null,
        ?int $toPoolId = null,
    ): StaffActionEvent {
        return StaffActionEvent::query()->create([
            'event_uuid' => (string) Str::uuid7(),
            'thread_work_item_id' => $workItem->getKey(),
            'event_type' => $eventType,
            'actor_user_id' => $user->getKey(),
            'from_pool_id' => $fromPoolId ?? $workItem->responsibility_pool_id,
            'to_pool_id' => $toPoolId ?? $workItem->responsibility_pool_id,
            'from_user_id' => $fromUserId,
            'to_user_id' => $toUserId,
            'message_id' => $messageId,
            'reason_code' => $reasonCode,
            'patient_visible_state' => $patientVisibleState,
            'idempotency_key_digest' => $operationDigest,
            'request_payload_digest' => $payloadDigest,
            'metadata' => ['schema_version' => 1, 'content_included' => false],
            'occurred_at' => $occurredAt,
        ]);
    }

    private function createPatientRoutingEvent(
        PatientMessageThread $thread,
        string $eventType,
        string $reasonCode,
        string $patientVisibleState,
        string $operationDigest,
        string $payloadDigest,
        User $user,
        mixed $occurredAt,
        ?string $fromPoolDigest = null,
        ?string $toPoolDigest = null,
    ): PatientMessageRoutingEvent {
        return PatientMessageRoutingEvent::query()->create([
            'routing_event_uuid' => (string) Str::uuid7(),
            'message_thread_id' => $thread->getKey(),
            'event_type' => $eventType,
            'from_pool_ref_digest' => $fromPoolDigest,
            'to_pool_ref_digest' => $toPoolDigest,
            'actor_type' => 'staff',
            'actor_ref_digest' => $this->staffActorDigest($user),
            'reason_code' => $reasonCode,
            'patient_visible_state' => $patientVisibleState,
            'routing_policy_version' => (string) $thread->routing_policy_version,
            'idempotency_key_digest' => $this->derivedDigest('messaging-routing', $operationDigest),
            'request_payload_digest' => $payloadDigest,
            'metadata' => ['schema_version' => 1, 'content_included' => false],
            'occurred_at' => $occurredAt,
        ]);
    }

    private function createLatestPatientReceipt(
        PatientMessageThread $thread,
        string $receiptType,
        string $patientVisibleState,
        string $operationDigest,
        User $user,
        mixed $occurredAt,
        ?int $excludingMessageId = null,
    ): ?PatientMessageDeliveryReceipt {
        $message = PatientMessage::query()
            ->where('message_thread_id', $thread->getKey())
            ->whereIn('sender_type', ['patient', 'representative'])
            ->when($excludingMessageId !== null, fn (Builder $query): Builder => $query->whereKeyNot($excludingMessageId))
            ->orderByDesc('sent_at')
            ->orderByDesc('message_id')
            ->first();
        if ($message === null) {
            return null;
        }

        return PatientMessageDeliveryReceipt::query()->create([
            'receipt_uuid' => (string) Str::uuid7(),
            'message_id' => $message->getKey(),
            'receipt_type' => $receiptType,
            'actor_type' => 'staff',
            'actor_ref_digest' => $this->staffActorDigest($user),
            'patient_visible_state' => $patientVisibleState,
            'idempotency_key_digest' => $this->derivedDigest(
                'messaging-patient-receipt',
                $operationDigest,
            ),
            'occurred_at' => $occurredAt,
        ]);
    }

    /** @return array<string, mixed> */
    private function mutationResult(
        ThreadWorkItem $workItem,
        User $viewer,
        bool $replayed,
        ?StaffActionEvent $event = null,
        ?PatientMessage $message = null,
    ): array {
        $workItem->loadMissing(['thread', 'accessGrant', 'pool', 'assignee']);

        return [
            'work_item' => $this->serializeWorkItem($workItem, $viewer),
            'message' => $message !== null
                ? $this->serializeMessage(
                    $message->loadMissing(['receipts', 'relatesTo']),
                    (string) $workItem->thread->thread_uuid,
                )
                : null,
            'event_uuid' => $event?->event_uuid,
            'replayed' => $replayed,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeWorkItem(ThreadWorkItem $workItem, User $viewer, bool $includeMessages = false): array
    {
        $thread = $workItem->thread;
        $grant = $workItem->accessGrant;
        $pool = $workItem->pool;
        $encounter = $grant?->source_encounter_id !== null
            ? Encounter::query()->with('unit')->find((int) $grant->source_encounter_id)
            : null;

        $patientContextRef = null;
        if ($encounter !== null && trim((string) $encounter->patient_ref) !== '') {
            try {
                $patientContextRef = $this->patientContexts->issue((string) $encounter->patient_ref);
            } catch (Throwable) {
                $patientContextRef = null;
            }
        }

        $data = [
            'work_item_uuid' => (string) $workItem->work_item_uuid,
            'thread_uuid' => (string) $thread?->thread_uuid,
            'patient_context_ref' => $patientContextRef,
            'topic' => [
                'code' => (string) $thread?->topic_code,
                'label' => (string) $thread?->topic_label,
            ],
            'unit' => $encounter?->unit !== null ? [
                'id' => (int) $encounter->unit->getKey(),
                'label' => (string) $encounter->unit->name,
            ] : null,
            'pool' => [
                'pool_uuid' => (string) $pool?->pool_uuid,
                'label' => (string) $pool?->display_name,
            ],
            'status' => (string) $workItem->status,
            'ownership_state' => (string) $workItem->ownership_state,
            'assigned_to_me' => $workItem->assigned_user_id !== null
                && (int) $workItem->assigned_user_id === (int) $viewer->getKey(),
            'work_item_version' => (int) $workItem->row_version,
            'thread_version' => (int) $thread?->version,
            'last_message_at' => $workItem->last_message_at?->toISOString(),
            'due_at' => $workItem->due_at?->toISOString(),
            'escalate_at' => $workItem->escalate_at?->toISOString(),
            'is_response_due' => $this->responsePending($workItem)
                && ($workItem->due_at?->isPast() ?? false),
            'is_escalation_due' => $this->responsePending($workItem)
                && ($workItem->escalate_at?->isPast() ?? false),
            'closed_at' => $workItem->closed_at?->toISOString(),
        ];

        if ($includeMessages) {
            /** @var Collection<int, PatientMessage> $messages */
            $messages = $thread?->messages ?? collect();
            $data['messages'] = $messages
                ->sortBy([['sent_at', 'asc'], ['message_id', 'asc']])
                ->map(fn (PatientMessage $message): array => $this->serializeMessage(
                    $message,
                    (string) $thread->thread_uuid,
                ))
                ->values()
                ->all();
            $data['has_earlier_messages'] = PatientMessage::query()
                ->where('message_thread_id', $thread?->getKey())
                ->count() > $messages->count();
        }

        return $data;
    }

    private function responsePending(ThreadWorkItem $workItem): bool
    {
        return $workItem->status === 'open'
            && in_array(
                $workItem->ownership_state,
                ['pool_owned', 'assigned', 'acknowledged', 'rerouted', 'escalated'],
                true,
            );
    }

    /** @return array<string, mixed> */
    private function serializeMessage(PatientMessage $message, string $threadUuid): array
    {
        $latestReceipt = $message->relationLoaded('receipts')
            ? $message->receipts->sortBy([
                ['occurred_at', 'desc'],
                ['message_delivery_receipt_id', 'desc'],
            ])->first()
            : null;

        return [
            'message_uuid' => (string) $message->message_uuid,
            'sender_display_role' => match ($message->sender_type) {
                'patient' => 'Patient',
                'representative' => 'Representative',
                'staff' => $message->visibility === 'staff_internal' ? 'Care team — internal' : 'Care team',
                default => 'System',
            },
            'visibility' => (string) $message->visibility,
            'message_kind' => (string) $message->message_kind,
            'body' => $message->encrypted_body !== null && $message->encryption_key_version !== null
                ? $this->cipher->decrypt(
                    (string) $message->encrypted_body,
                    (string) $message->encryption_key_version,
                    $this->cipher->contextFor(
                        $threadUuid,
                        (string) $message->message_uuid,
                    ),
                )
                : null,
            'delivery_state' => $latestReceipt?->patient_visible_state ?? (string) $message->delivery_state,
            'sent_at' => $message->sent_at?->toISOString(),
        ];
    }

    private function recordMutationAudit(
        Request $request,
        User $user,
        ThreadWorkItem $workItem,
        string $eventType,
        bool $replayed,
    ): void {
        $this->audit->record('patient_communications.'.$eventType, 'activity', 'success', [
            'request' => $request,
            'actor' => $user,
            'target_type' => 'patient_message_thread',
            'target_id' => (string) $workItem->work_item_uuid,
            'metadata' => [
                'work_item_uuid' => (string) $workItem->work_item_uuid,
                'event_type' => $eventType,
                'replayed' => $replayed,
            ],
        ]);
    }

    private function recordDenialAudit(Request $request, User $user, string $workItemUuid, string $eventType): void
    {
        $this->audit->bestEffort('patient_communications.'.$eventType, 'authorization', 'denied', [
            'request' => $request,
            'actor' => $user,
            'target_type' => 'patient_message_thread',
            'target_id' => $workItemUuid,
            'reason' => 'resource_unavailable',
            'metadata' => ['event_type' => $eventType],
        ]);
    }

    private function operationDigest(User $user, string $operation, string $key): string
    {
        return $this->hmac->digest(
            'messaging-staff-idempotency',
            (string) $user->getKey().'|'.$operation.'|'.$key,
        );
    }

    private function staffActorDigest(User $user): string
    {
        return $this->hmac->digest('messaging-actor-ref', 'staff-user|'.(string) $user->getKey());
    }

    private function derivedDigest(string $purpose, string $operationDigest): string
    {
        return $this->hmac->digest($purpose, $operationDigest);
    }

    /** @param list<string> $keys */
    private function acquireReplayLocks(array $keys): void
    {
        sort($keys, SORT_STRING);
        foreach (array_unique($keys) as $key) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
        }
    }

    /** @param array<string, mixed> $payload */
    private function payloadDigest(array $payload): string
    {
        return $this->hmac->digest(
            'messaging-staff-payload',
            json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn (mixed $nested): mixed => $this->canonicalize($nested), $value);
    }
}
