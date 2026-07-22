<?php

namespace App\Services\Patient\Messaging;

use App\Models\Encounter;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientMessage;
use App\Models\Patient\PatientMessageDeliveryReceipt;
use App\Models\Patient\PatientMessageRoutingEvent;
use App\Models\Patient\PatientMessageThread;
use App\Models\PatientCommunication\ResponsibilityPool;
use App\Models\PatientCommunication\StaffActionEvent;
use App\Models\PatientCommunication\ThreadWorkItem;
use App\Services\Patient\PatientHmac;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reconciles open patient-message work with canonical encounter lifecycle facts.
 *
 * The mutable thread/work rows remain the same identities. Every discharge,
 * scope handoff, and shift release appends content-free system facts in the
 * staff and patient ledgers in the same transaction as the projection change.
 */
class PatientCommunicationLifecycleReconciliationService
{
    private const REQUIRED_TABLES = [
        'prod.encounters',
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
        private readonly PatientHmac $hmac,
        private readonly PatientCommunicationPoolResolver $pools,
        private readonly PatientCommunicationResponderEligibility $responders,
    ) {}

    /**
     * @return array{selected: int, rerouted: int, released: int, closed: int, skipped: int, unresolved: int, failed: int}
     */
    public function reconcileOpen(?int $limit = null): array
    {
        $this->assertAvailable();

        $limit = max(1, min(500, $limit ?? 100));
        $candidateIds = ThreadWorkItem::query()
            ->where('status', 'open')
            ->orderBy('thread_work_item_id')
            ->limit($limit)
            ->pluck('thread_work_item_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $counts = [
            'rerouted' => 0,
            'released' => 0,
            'closed' => 0,
            'skipped' => 0,
            'unresolved' => 0,
            'failed' => 0,
        ];
        foreach ($candidateIds as $candidateId) {
            try {
                $outcome = $this->reconcileOne($candidateId);
                $counts[$outcome]++;
            } catch (Throwable) {
                $counts['failed']++;
            }
        }

        return ['selected' => count($candidateIds), ...$counts];
    }

    /** @return 'rerouted'|'released'|'closed'|'skipped'|'unresolved' */
    private function reconcileOne(int $candidateId): string
    {
        return DB::transaction(function () use ($candidateId): string {
            $candidate = ThreadWorkItem::query()->find($candidateId);
            if (! $candidate instanceof ThreadWorkItem) {
                return 'skipped';
            }

            // Match the staff mutation lock order: thread, work item, grant,
            // source pool, then canonical encounter/routing candidates.
            $thread = PatientMessageThread::query()
                ->whereKey($candidate->message_thread_id)
                ->lockForUpdate()
                ->first();
            $workItem = ThreadWorkItem::query()->lockForUpdate()->find($candidateId);
            if (! $thread instanceof PatientMessageThread || ! $workItem instanceof ThreadWorkItem) {
                return 'skipped';
            }
            if ($thread->status !== 'open' || $workItem->status !== 'open') {
                return $thread->status === $workItem->status ? 'skipped' : 'unresolved';
            }
            if ((int) $workItem->message_thread_id !== (int) $thread->getKey()
                || (int) $workItem->access_grant_id !== (int) $thread->access_grant_id
            ) {
                return 'unresolved';
            }

            $grant = PatientEncounterAccessGrant::query()
                ->lockForUpdate()
                ->find($workItem->access_grant_id);
            if (! $grant instanceof PatientEncounterAccessGrant
                || (int) $grant->getKey() !== (int) $thread->access_grant_id
                || $grant->source_encounter_id === null
            ) {
                return 'unresolved';
            }

            $sourcePool = ResponsibilityPool::query()
                ->lockForUpdate()
                ->find($workItem->responsibility_pool_id);
            $encounter = Encounter::query()
                ->whereKey((int) $grant->source_encounter_id)
                ->lockForUpdate()
                ->first();
            if (! $sourcePool instanceof ResponsibilityPool || ! $encounter instanceof Encounter) {
                return 'unresolved';
            }

            if ($this->isCanonicalDischarge($encounter)) {
                $this->closeForDischarge($thread, $workItem, $sourcePool, $encounter);

                return 'closed';
            }

            if (! $this->isCanonicalActive($encounter)) {
                return 'unresolved';
            }

            $scope = $this->pools->scopeForGrant($grant, true);
            if ($scope === null) {
                return 'unresolved';
            }

            $sourceMatchesScope = $this->pools->poolMatchesScope(
                $sourcePool,
                (string) $thread->routing_policy_version,
                (string) $thread->topic_code,
                (string) $thread->responsibility_pool_ref_digest,
                $scope,
                true,
            );

            if ($sourceMatchesScope) {
                $eligibleMemberships = $this->responders->eligibleMembershipsForPool(
                    (int) $sourcePool->getKey(),
                    lock: true,
                );
                if ($this->pendingAssigneeIsNoLongerEligible($workItem, $eligibleMemberships)) {
                    $this->releaseUnavailableAssignee($thread, $workItem, $sourcePool);

                    return 'released';
                }
                if ($eligibleMemberships->isNotEmpty()) {
                    return 'skipped';
                }
            }

            $targetPool = $this->pools->resolveForScope(
                (string) $thread->routing_policy_version,
                (string) $thread->topic_code,
                (string) $thread->responsibility_pool_ref_digest,
                $scope,
                true,
            );
            if (! $targetPool instanceof ResponsibilityPool
                || (int) $targetPool->getKey() === (int) $sourcePool->getKey()
            ) {
                return 'unresolved';
            }

            $reasonCode = $sourcePool->status !== 'active'
                ? 'responsibility_pool_unavailable'
                : ($sourceMatchesScope
                    ? 'responder_coverage_changed'
                    : 'encounter_unit_transferred');
            $this->reroute(
                $thread,
                $workItem,
                $sourcePool,
                $targetPool,
                $reasonCode,
            );

            return 'rerouted';
        }, 3);
    }

    private function closeForDischarge(
        PatientMessageThread $thread,
        ThreadWorkItem $workItem,
        ResponsibilityPool $sourcePool,
        Encounter $encounter,
    ): void {
        $occurredAt = now();
        $reasonCode = 'encounter_discharged';
        $operationDigest = $this->operationDigest($workItem, $reasonCode, [
            (string) $encounter->discharged_at?->toISOString(),
        ]);
        $payloadDigest = $this->payloadDigest($workItem, $reasonCode, [
            (string) $thread->version,
            (string) $workItem->row_version,
        ]);
        $fromUserId = $workItem->assigned_user_id !== null
            ? (int) $workItem->assigned_user_id
            : null;

        $thread->forceFill([
            'status' => 'closed',
            'ownership_state' => 'closed',
            'version' => (int) $thread->version + 1,
            'closed_at' => $occurredAt,
            'close_reason_code' => 'no_longer_needed',
        ])->save();
        $workItem->forceFill([
            'assigned_user_id' => null,
            'status' => 'closed',
            'ownership_state' => 'closed',
            'source_thread_version' => (int) $thread->version,
            'row_version' => (int) $workItem->row_version + 1,
            'closed_at' => $occurredAt,
            'close_reason_code' => $reasonCode,
        ])->save();

        $this->createStaffEvent(
            $workItem,
            'closed',
            $reasonCode,
            'closed',
            $operationDigest,
            $payloadDigest,
            $occurredAt,
            (int) $sourcePool->getKey(),
            (int) $sourcePool->getKey(),
            $fromUserId,
        );
        $this->createPatientRoutingEvent(
            $thread,
            'closed',
            $reasonCode,
            'closed',
            $operationDigest,
            $payloadDigest,
            $occurredAt,
            (string) $thread->responsibility_pool_ref_digest,
            null,
        );
        $this->createLatestPatientReceipt(
            $thread,
            'closed',
            'closed',
            $operationDigest,
            $occurredAt,
        );
    }

    private function releaseUnavailableAssignee(
        PatientMessageThread $thread,
        ThreadWorkItem $workItem,
        ResponsibilityPool $pool,
    ): void {
        $occurredAt = now();
        $reasonCode = 'assigned_responder_unavailable';
        $operationDigest = $this->operationDigest($workItem, $reasonCode, [
            (string) $workItem->assigned_user_id,
        ]);
        $payloadDigest = $this->payloadDigest($workItem, $reasonCode, [
            (string) $thread->version,
            (string) $workItem->row_version,
        ]);
        $fromUserId = (int) $workItem->assigned_user_id;
        $poolId = (int) $pool->getKey();
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

        $this->createStaffEvent(
            $workItem,
            'released',
            $reasonCode,
            'assigned',
            $operationDigest,
            $payloadDigest,
            $occurredAt,
            $poolId,
            $poolId,
            $fromUserId,
        );
        $this->createPatientRoutingEvent(
            $thread,
            'assigned',
            $reasonCode,
            'assigned',
            $operationDigest,
            $payloadDigest,
            $occurredAt,
            $poolDigest,
            $poolDigest,
        );
        $this->createLatestPatientReceipt(
            $thread,
            'assigned',
            'assigned',
            $operationDigest,
            $occurredAt,
        );
    }

    private function reroute(
        PatientMessageThread $thread,
        ThreadWorkItem $workItem,
        ResponsibilityPool $sourcePool,
        ResponsibilityPool $targetPool,
        string $reasonCode,
    ): void {
        $occurredAt = now();
        $operationDigest = $this->operationDigest($workItem, $reasonCode, [
            (string) $sourcePool->pool_uuid,
            (string) $targetPool->pool_uuid,
        ]);
        $payloadDigest = $this->payloadDigest($workItem, $reasonCode, [
            (string) $thread->version,
            (string) $workItem->row_version,
            (string) $sourcePool->getKey(),
            (string) $targetPool->getKey(),
        ]);
        $fromUserId = $workItem->assigned_user_id !== null
            ? (int) $workItem->assigned_user_id
            : null;
        $fromPoolDigest = (string) $thread->responsibility_pool_ref_digest;
        $toPoolDigest = (string) $targetPool->pool_key_digest;

        $thread->forceFill([
            'responsibility_pool_ref_digest' => $toPoolDigest,
            'ownership_state' => 'rerouted',
            'version' => (int) $thread->version + 1,
        ])->save();
        $workItem->forceFill([
            'responsibility_pool_id' => $targetPool->getKey(),
            'assigned_user_id' => null,
            'ownership_state' => 'rerouted',
            'source_thread_version' => (int) $thread->version,
            'row_version' => (int) $workItem->row_version + 1,
            'due_at' => $occurredAt->copy()->addMinutes((int) $targetPool->response_target_minutes),
            'escalate_at' => $occurredAt->copy()->addMinutes((int) $targetPool->escalation_target_minutes),
            'acknowledged_at' => null,
            'responded_at' => null,
        ])->save();

        $this->createStaffEvent(
            $workItem,
            'rerouted',
            $reasonCode,
            'rerouted',
            $operationDigest,
            $payloadDigest,
            $occurredAt,
            (int) $sourcePool->getKey(),
            (int) $targetPool->getKey(),
            $fromUserId,
        );
        $this->createPatientRoutingEvent(
            $thread,
            'rerouted',
            $reasonCode,
            'rerouted',
            $operationDigest,
            $payloadDigest,
            $occurredAt,
            $fromPoolDigest,
            $toPoolDigest,
        );
        $this->createLatestPatientReceipt(
            $thread,
            'assigned',
            'assigned',
            $operationDigest,
            $occurredAt,
        );
    }

    private function createStaffEvent(
        ThreadWorkItem $workItem,
        string $eventType,
        string $reasonCode,
        string $patientVisibleState,
        string $operationDigest,
        string $payloadDigest,
        mixed $occurredAt,
        int $fromPoolId,
        int $toPoolId,
        ?int $fromUserId,
    ): void {
        StaffActionEvent::query()->create([
            'event_uuid' => (string) Str::uuid7(),
            'thread_work_item_id' => $workItem->getKey(),
            'event_type' => $eventType,
            'from_pool_id' => $fromPoolId,
            'to_pool_id' => $toPoolId,
            'from_user_id' => $fromUserId,
            'reason_code' => $reasonCode,
            'patient_visible_state' => $patientVisibleState,
            'idempotency_key_digest' => $operationDigest,
            'request_payload_digest' => $payloadDigest,
            'metadata' => [
                'schema_version' => 1,
                'content_included' => false,
                'source' => 'encounter_lifecycle_reconciler',
            ],
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
        mixed $occurredAt,
        ?string $fromPoolDigest,
        ?string $toPoolDigest,
    ): void {
        PatientMessageRoutingEvent::query()->create([
            'routing_event_uuid' => (string) Str::uuid7(),
            'message_thread_id' => $thread->getKey(),
            'event_type' => $eventType,
            'from_pool_ref_digest' => $fromPoolDigest,
            'to_pool_ref_digest' => $toPoolDigest,
            'actor_type' => 'system',
            'actor_ref_digest' => $this->systemActorDigest(),
            'reason_code' => $reasonCode,
            'patient_visible_state' => $patientVisibleState,
            'routing_policy_version' => (string) $thread->routing_policy_version,
            'idempotency_key_digest' => $this->hmac->digest(
                'messaging-lifecycle-routing',
                $operationDigest,
            ),
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
        mixed $occurredAt,
    ): void {
        $message = PatientMessage::query()
            ->where('message_thread_id', $thread->getKey())
            ->whereIn('sender_type', ['patient', 'representative'])
            ->orderByDesc('sent_at')
            ->orderByDesc('message_id')
            ->first();
        if (! $message instanceof PatientMessage) {
            return;
        }

        PatientMessageDeliveryReceipt::query()->create([
            'receipt_uuid' => (string) Str::uuid7(),
            'message_id' => $message->getKey(),
            'receipt_type' => $receiptType,
            'actor_type' => 'system',
            'actor_ref_digest' => $this->systemActorDigest(),
            'patient_visible_state' => $patientVisibleState,
            'idempotency_key_digest' => $this->hmac->digest(
                'messaging-lifecycle-receipt',
                $operationDigest,
            ),
            'occurred_at' => $occurredAt,
        ]);
    }

    /** @param Collection<int, \App\Models\PatientCommunication\PoolMembership> $eligibleMemberships */
    private function pendingAssigneeIsNoLongerEligible(
        ThreadWorkItem $workItem,
        Collection $eligibleMemberships,
    ): bool {
        // A responded thread has no pending patient request. Its assignee is
        // historical projection state and is deliberately not reopened or
        // reassigned by shift reconciliation. A later patient follow-up is a
        // distinct handoff transition and must establish fresh accountability.
        return $workItem->assigned_user_id !== null
            && in_array($workItem->ownership_state, ['assigned', 'acknowledged', 'escalated'], true)
            && ! $eligibleMemberships->contains(
                fn (mixed $membership): bool => (int) $membership->staff_user_id
                    === (int) $workItem->assigned_user_id,
            )
            && $eligibleMemberships->isNotEmpty();
    }

    private function isCanonicalActive(Encounter $encounter): bool
    {
        return ! $encounter->is_deleted
            && $encounter->status === 'active'
            && $encounter->discharged_at === null;
    }

    private function isCanonicalDischarge(Encounter $encounter): bool
    {
        return ! $encounter->is_deleted
            && $encounter->status === 'discharged'
            && $encounter->discharged_at !== null;
    }

    /** @param list<string> $parts */
    private function operationDigest(ThreadWorkItem $workItem, string $reasonCode, array $parts): string
    {
        return $this->hmac->digest(
            'messaging-lifecycle-operation',
            implode('|', [
                (string) $workItem->work_item_uuid,
                $reasonCode,
                (string) $workItem->source_thread_version,
                (string) $workItem->row_version,
                ...$parts,
            ]),
        );
    }

    /** @param list<string> $parts */
    private function payloadDigest(ThreadWorkItem $workItem, string $reasonCode, array $parts): string
    {
        return $this->hmac->digest(
            'messaging-lifecycle-payload',
            implode('|', [
                (string) $workItem->work_item_uuid,
                $reasonCode,
                ...$parts,
            ]),
        );
    }

    private function systemActorDigest(): string
    {
        return $this->hmac->digest(
            'messaging-actor-ref',
            'encounter-lifecycle-reconciler',
        );
    }

    private function assertAvailable(): void
    {
        if (! (bool) config('hummingbird-patient.enabled', false)
            || ! (bool) config('hummingbird-patient.features.messaging', false)
            || ! (bool) config('hummingbird-patient.staff_messaging.enabled', false)
            || config('hummingbird-patient.staff_messaging.governance_status') !== 'approved'
            || collect(self::REQUIRED_TABLES)->contains(
                fn (string $table): bool => ! Schema::hasTable($table),
            )
        ) {
            throw StaffPatientCommunicationFailure::unavailable();
        }
    }
}
