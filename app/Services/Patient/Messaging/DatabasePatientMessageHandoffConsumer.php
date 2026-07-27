<?php

namespace App\Services\Patient\Messaging;

use App\Contracts\Patient\PatientMessageHandoffReadiness;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientMessage;
use App\Models\Patient\PatientMessageDeliveryReceipt;
use App\Models\Patient\PatientMessageRoutingEvent;
use App\Models\Patient\PatientMessageThread;
use App\Models\Patient\PatientNotificationDeliveryAttempt;
use App\Models\Patient\PatientNotificationOutbox;
use App\Models\PatientCommunication\ConsumerHeartbeat;
use App\Models\PatientCommunication\ResponsibilityPool;
use App\Models\PatientCommunication\StaffActionEvent;
use App\Models\PatientCommunication\ThreadWorkItem;
use App\Services\Patient\PatientHmac;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Durable, content-free outbox consumer for the accountable staff inbox.
 *
 * It never copies message bodies into an operational projection. Staff readers
 * decrypt the canonical append-only message ledger only after an independent
 * capability + effective pool-membership decision.
 */
class DatabasePatientMessageHandoffConsumer implements PatientMessageHandoffReadiness
{
    private const REQUIRED_TABLES = [
        'patient_communications.responsibility_pools',
        'patient_communications.pool_memberships',
        'patient_communications.thread_work_items',
        'patient_communications.staff_action_events',
        'patient_communications.consumer_heartbeats',
    ];

    public function __construct(
        private readonly PatientHmac $hmac,
        private readonly PatientCommunicationPoolResolver $pools,
    ) {}

    public function readyForPolicy(string $policyVersion): bool
    {
        try {
            if (! $this->configurationReady($policyVersion)) {
                return false;
            }

            $heartbeat = ConsumerHeartbeat::query()->find($this->consumerKey());
            if ($heartbeat === null
                || $heartbeat->status !== 'ready'
                || ! hash_equals((string) $heartbeat->routing_policy_version, $policyVersion)
                || $heartbeat->last_seen_at === null
                || $heartbeat->last_seen_at->lt(now()->subSeconds($this->heartbeatTtlSeconds()))
            ) {
                return false;
            }

            return ! $this->hasUnresolvedOutbox();
        } catch (Throwable) {
            return false;
        }
    }

    public function routableForGrant(
        string $policyVersion,
        string $topicCode,
        string $responsibilityPoolKey,
        PatientEncounterAccessGrant $grant,
    ): bool {
        if (! $this->readyForPolicy($policyVersion)) {
            return false;
        }

        $digest = $this->poolDigest($policyVersion, $responsibilityPoolKey);

        return $this->pools->resolveForGrant(
            $policyVersion,
            $topicCode,
            $digest,
            $grant,
        ) instanceof ResponsibilityPool;
    }

    /**
     * @return array{selected: int, delivered: int, failed: int}
     */
    public function consumeBatch(string $workerRef, ?int $limit = null): array
    {
        $policyVersion = trim((string) config('hummingbird-patient.messaging.policy_version'));
        if (! $this->infrastructureReady()) {
            throw new RuntimeException('patient_message_handoff_not_ready');
        }

        $heartbeatPolicyVersion = $policyVersion !== '' ? $policyVersion : 'unconfigured-current-policy';
        $currentPolicyReady = $this->configurationReady($policyVersion);
        $workerDigest = $this->hmac->digest('messaging-handoff-worker', trim($workerRef));
        $this->writeHeartbeat(
            $heartbeatPolicyVersion,
            $workerDigest,
            $currentPolicyReady && ! $this->hasUnresolvedOutbox() ? 'ready' : 'degraded',
            0,
        );

        $limit = max(1, min(500, $limit ?? (int) config('hummingbird-patient.staff_messaging.batch_size', 100)));
        $outboxIds = PatientNotificationOutbox::query()
            ->where('destination', 'staff_inbox')
            ->where('available_at', '<=', now())
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->whereDoesntHave('deliveryAttempts', function (Builder $query): void {
                $query->whereIn('status', ['delivered', 'terminal_failure', 'expired']);
            })
            ->whereDoesntHave('deliveryAttempts', function (Builder $query): void {
                $query->where('status', 'retryable_failure')
                    ->where(function (Builder $schedule): void {
                        $schedule->whereNull('next_attempt_at')->orWhere('next_attempt_at', '>', now());
                    });
            })
            ->orderBy('notification_outbox_id')
            ->limit($limit)
            ->pluck('notification_outbox_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $delivered = 0;
        $failed = 0;
        foreach ($outboxIds as $outboxId) {
            try {
                if ($this->consumeOne($outboxId, $workerDigest)) {
                    $delivered++;
                }
            } catch (Throwable $exception) {
                $failed++;
                $this->recordFailure($outboxId, $workerDigest, $exception);
            }
        }

        $this->writeHeartbeat(
            $heartbeatPolicyVersion,
            $workerDigest,
            $currentPolicyReady && ! $this->hasUnresolvedOutbox() ? 'ready' : 'degraded',
            $failed,
        );

        return ['selected' => count($outboxIds), 'delivered' => $delivered, 'failed' => $failed];
    }

    private function consumeOne(int $outboxId, string $workerDigest): bool
    {
        return DB::transaction(function () use ($outboxId, $workerDigest): bool {
            $outbox = PatientNotificationOutbox::query()->lockForUpdate()->find($outboxId);
            if ($outbox === null || ! $this->outboxDueForAttempt($outbox)) {
                return false;
            }

            if ($outbox->destination !== 'staff_inbox'
                || $outbox->aggregate_type !== 'patient_message_thread'
                || $outbox->aggregate_uuid === null
            ) {
                throw new RuntimeException('handoff_outbox_contract_invalid');
            }

            $thread = PatientMessageThread::query()
                ->with('accessGrant')
                ->where('thread_uuid', (string) $outbox->aggregate_uuid)
                ->lockForUpdate()
                ->first();
            if ($thread === null || $thread->accessGrant === null) {
                throw new RuntimeException('handoff_thread_unavailable');
            }
            $lockedGrant = PatientEncounterAccessGrant::query()
                ->lockForUpdate()
                ->find($thread->access_grant_id);
            if ($lockedGrant === null) {
                throw new RuntimeException('handoff_grant_unavailable');
            }
            $thread->setRelation('accessGrant', $lockedGrant);

            $metadata = is_array($outbox->routing_metadata) ? $outbox->routing_metadata : [];
            if (($metadata['schema_version'] ?? null) !== 1
                || ($metadata['content_included'] ?? null) !== false
                || ! is_string($metadata['routing_policy_version'] ?? null)
                || ! hash_equals((string) $thread->routing_policy_version, $metadata['routing_policy_version'])
                || ! is_string($metadata['responsibility_pool_ref_digest'] ?? null)
                || ! hash_equals(
                    (string) $thread->responsibility_pool_ref_digest,
                    $metadata['responsibility_pool_ref_digest'],
                )
            ) {
                throw new RuntimeException('handoff_routing_metadata_invalid');
            }

            $workItem = ThreadWorkItem::query()
                ->where('message_thread_id', $thread->getKey())
                ->lockForUpdate()
                ->first();

            if ($workItem === null) {
                $pool = $this->pools->resolveForGrant(
                    (string) $thread->routing_policy_version,
                    (string) $thread->topic_code,
                    (string) $thread->responsibility_pool_ref_digest,
                    $lockedGrant,
                    true,
                );
            } else {
                $pool = ResponsibilityPool::query()
                    ->whereKey($workItem->responsibility_pool_id)
                    ->lockForUpdate()
                    ->first();
                $scope = $this->pools->scopeForGrant($lockedGrant, true);
                if ((int) $workItem->access_grant_id !== (int) $lockedGrant->getKey()
                    || ! ($pool instanceof ResponsibilityPool)
                    || $scope === null
                    || ! $this->pools->poolIsEligibleForScope(
                        $pool,
                        (string) $thread->routing_policy_version,
                        (string) $thread->topic_code,
                        (string) $thread->responsibility_pool_ref_digest,
                        $scope,
                        true,
                    )
                ) {
                    $pool = null;
                }
            }

            if (! ($pool instanceof ResponsibilityPool)) {
                throw new RuntimeException('handoff_responsibility_pool_unresolved');
            }

            $occurredAt = now();
            if ($thread->status === 'open') {
                $this->recordPatientRoutedState($thread, $outbox, $occurredAt);
            }

            if ($workItem === null) {
                $workItem = ThreadWorkItem::query()->create([
                    'work_item_uuid' => (string) Str::uuid7(),
                    'message_thread_id' => $thread->getKey(),
                    'access_grant_id' => $thread->access_grant_id,
                    'responsibility_pool_id' => $pool->getKey(),
                    'status' => $thread->status === 'closed' ? 'closed' : 'open',
                    'ownership_state' => $thread->status === 'closed' ? 'closed' : 'pool_owned',
                    'source_thread_version' => (int) $thread->version,
                    'row_version' => 1,
                    'last_outbox_id' => $outbox->getKey(),
                    'first_routed_at' => $occurredAt,
                    'due_at' => $occurredAt->copy()->addMinutes((int) $pool->response_target_minutes),
                    'escalate_at' => $occurredAt->copy()->addMinutes((int) $pool->escalation_target_minutes),
                    'last_message_at' => $thread->last_message_at,
                    'closed_at' => $thread->status === 'closed' ? ($thread->closed_at ?? $occurredAt) : null,
                    'close_reason_code' => $thread->status === 'closed'
                        ? ((string) $thread->close_reason_code ?: 'patient_closed')
                        : null,
                ]);
            } else {
                $closed = $thread->status === 'closed';
                $workItem->forceFill([
                    'responsibility_pool_id' => $pool->getKey(),
                    'status' => $closed ? 'closed' : 'open',
                    'ownership_state' => $closed
                        ? 'closed'
                        : ($workItem->assigned_user_id === null ? 'pool_owned' : 'assigned'),
                    'source_thread_version' => (int) $thread->version,
                    'row_version' => (int) $workItem->row_version + 1,
                    'last_outbox_id' => $outbox->getKey(),
                    'last_message_at' => $thread->last_message_at,
                    'due_at' => $closed
                        ? $workItem->due_at
                        : $occurredAt->copy()->addMinutes((int) $pool->response_target_minutes),
                    'escalate_at' => $closed
                        ? $workItem->escalate_at
                        : $occurredAt->copy()->addMinutes((int) $pool->escalation_target_minutes),
                    'responded_at' => $closed ? $workItem->responded_at : null,
                    'closed_at' => $closed ? ($thread->closed_at ?? $occurredAt) : null,
                    'close_reason_code' => $closed
                        ? ((string) $thread->close_reason_code ?: 'patient_closed')
                        : null,
                ])->save();
            }

            $patientVisibleState = $thread->status === 'closed' ? 'closed' : 'assigned';
            $eventType = $thread->status === 'closed'
                ? 'patient_closed'
                : ($workItem->wasRecentlyCreated ? 'pool_routed' : 'outbox_consumed');
            $eventDigest = $this->hmac->digest(
                'messaging-staff-event',
                (string) $outbox->outbox_uuid.'|'.$eventType,
            );

            StaffActionEvent::query()->firstOrCreate(
                ['idempotency_key_digest' => $eventDigest],
                [
                    'event_uuid' => (string) Str::uuid7(),
                    'thread_work_item_id' => $workItem->getKey(),
                    'event_type' => $eventType,
                    'to_pool_id' => $pool->getKey(),
                    'reason_code' => (string) $outbox->event_type,
                    'patient_visible_state' => $patientVisibleState,
                    'request_payload_digest' => $this->hmac->digest(
                        'messaging-staff-payload',
                        (string) $outbox->outbox_uuid.'|'.(string) $thread->version,
                    ),
                    'metadata' => [
                        'schema_version' => 1,
                        'content_included' => false,
                        'source' => 'patient_outbox',
                    ],
                    'occurred_at' => $occurredAt,
                ],
            );

            PatientNotificationDeliveryAttempt::query()->create([
                'delivery_attempt_uuid' => (string) Str::uuid7(),
                'notification_outbox_id' => $outbox->getKey(),
                'attempt_number' => $this->nextAttemptNumber($outbox),
                'status' => 'delivered',
                'worker_ref' => Str::limit($workerDigest, 190, ''),
                'provider_message_ref_digest' => $this->hmac->digest(
                    'messaging-staff-work-item',
                    (string) $workItem->work_item_uuid,
                ),
                'metadata' => [
                    'schema_version' => 1,
                    'content_included' => false,
                    'destination' => 'staff_inbox',
                ],
                'occurred_at' => $occurredAt,
            ]);

            return true;
        }, 3);
    }

    private function recordPatientRoutedState(
        PatientMessageThread $thread,
        PatientNotificationOutbox $outbox,
        mixed $occurredAt,
    ): void {
        $routeDigest = $this->hmac->digest('messaging-routing', 'staff-handoff|'.(string) $outbox->outbox_uuid);
        $payloadDigest = $this->hmac->digest('messaging-payload', 'staff-handoff|'.(string) $outbox->outbox_uuid);

        if (! PatientMessageRoutingEvent::query()->where('idempotency_key_digest', $routeDigest)->exists()) {
            $thread->forceFill([
                'ownership_state' => 'assigned',
                'version' => (int) $thread->version + 1,
            ])->save();

            PatientMessageRoutingEvent::query()->create([
                'routing_event_uuid' => (string) Str::uuid7(),
                'message_thread_id' => $thread->getKey(),
                'event_type' => 'assigned',
                'to_pool_ref_digest' => (string) $thread->responsibility_pool_ref_digest,
                'actor_type' => 'system',
                'actor_ref_digest' => $this->hmac->digest('messaging-actor-ref', 'staff-handoff-consumer'),
                'reason_code' => 'routed_to_accountable_pool',
                'patient_visible_state' => 'assigned',
                'routing_policy_version' => (string) $thread->routing_policy_version,
                'idempotency_key_digest' => $routeDigest,
                'request_payload_digest' => $payloadDigest,
                'metadata' => ['schema_version' => 1, 'content_included' => false],
                'occurred_at' => $occurredAt,
            ]);

            $latestPatientMessage = PatientMessage::query()
                ->where('message_thread_id', $thread->getKey())
                ->whereIn('sender_type', ['patient', 'representative'])
                ->orderByDesc('sent_at')
                ->orderByDesc('message_id')
                ->first();
            if ($latestPatientMessage !== null) {
                PatientMessageDeliveryReceipt::query()->firstOrCreate(
                    ['idempotency_key_digest' => $this->hmac->digest(
                        'messaging-receipt',
                        'staff-handoff|'.(string) $outbox->outbox_uuid,
                    )],
                    [
                        'receipt_uuid' => (string) Str::uuid7(),
                        'message_id' => $latestPatientMessage->getKey(),
                        'receipt_type' => 'assigned',
                        'actor_type' => 'system',
                        'actor_ref_digest' => $this->hmac->digest(
                            'messaging-actor-ref',
                            'staff-handoff-consumer',
                        ),
                        'patient_visible_state' => 'assigned',
                        'occurred_at' => $occurredAt,
                    ],
                );
            }
        }
    }

    private function configurationReady(string $policyVersion): bool
    {
        if (! $this->infrastructureReady()
            || $policyVersion === ''
            || config('hummingbird-patient.messaging.governance_status') !== 'approved'
            || $this->pilotUnitIds() === []
        ) {
            return false;
        }

        $topics = config('hummingbird-patient.messaging.topics');
        if (! is_array($topics) || $topics === []) {
            return false;
        }

        foreach ($this->pilotUnitIds() as $unitId) {
            foreach ($topics as $topicCode => $topic) {
                if (! is_string($topicCode)
                    || ! is_array($topic)
                    || ! is_string($topic['responsibility_pool_key'] ?? null)
                    || ! ($this->pools->resolveForUnit(
                        $policyVersion,
                        $topicCode,
                        $this->poolDigest($policyVersion, $topic['responsibility_pool_key']),
                        $unitId,
                    ) instanceof ResponsibilityPool)
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    private function infrastructureReady(): bool
    {
        return (bool) config('hummingbird-patient.staff_messaging.enabled', false)
            && config('hummingbird-patient.staff_messaging.governance_status') === 'approved'
            && ! collect(self::REQUIRED_TABLES)->contains(
                fn (string $table): bool => ! Schema::hasTable($table),
            );
    }

    private function hasUnresolvedOutbox(): bool
    {
        return PatientNotificationOutbox::query()
            ->where('destination', 'staff_inbox')
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->whereDoesntHave('deliveryAttempts', fn (Builder $query): Builder => $query
                ->whereIn('status', ['delivered', 'expired']))
            ->exists();
    }

    private function recordFailure(int $outboxId, string $workerDigest, Throwable $exception): void
    {
        DB::transaction(function () use ($outboxId, $workerDigest, $exception): void {
            $outbox = PatientNotificationOutbox::query()->lockForUpdate()->find($outboxId);
            if ($outbox === null || ! $this->outboxDueForAttempt($outbox)) {
                return;
            }

            $attemptNumber = $this->nextAttemptNumber($outbox);
            $terminal = $attemptNumber >= 10;
            PatientNotificationDeliveryAttempt::query()->create([
                'delivery_attempt_uuid' => (string) Str::uuid7(),
                'notification_outbox_id' => $outbox->getKey(),
                'attempt_number' => $attemptNumber,
                'status' => $terminal ? 'terminal_failure' : 'retryable_failure',
                'worker_ref' => Str::limit($workerDigest, 190, ''),
                'error_code' => $this->safeErrorCode($exception),
                'next_attempt_at' => $terminal ? null : now()->addMinutes(min(60, 2 ** min(5, $attemptNumber))),
                'metadata' => [
                    'schema_version' => 1,
                    'content_included' => false,
                    'destination' => 'staff_inbox',
                ],
                'occurred_at' => now(),
            ]);
        }, 3);
    }

    private function terminalAttemptExists(PatientNotificationOutbox $outbox): bool
    {
        return $outbox->deliveryAttempts()
            ->whereIn('status', ['delivered', 'terminal_failure', 'expired'])
            ->exists();
    }

    private function outboxDueForAttempt(PatientNotificationOutbox $outbox): bool
    {
        if ($outbox->destination !== 'staff_inbox'
            || $outbox->available_at === null
            || $outbox->available_at->isFuture()
            || ($outbox->expires_at !== null && ! $outbox->expires_at->isFuture())
            || $this->terminalAttemptExists($outbox)
        ) {
            return false;
        }

        $latestAttempt = $outbox->deliveryAttempts()
            ->orderByDesc('attempt_number')
            ->orderByDesc('notification_delivery_attempt_id')
            ->first();

        if ($latestAttempt === null) {
            return true;
        }

        return $latestAttempt->status === 'retryable_failure'
            && $latestAttempt->next_attempt_at !== null
            && ! $latestAttempt->next_attempt_at->isFuture();
    }

    private function nextAttemptNumber(PatientNotificationOutbox $outbox): int
    {
        return (int) $outbox->deliveryAttempts()->max('attempt_number') + 1;
    }

    private function writeHeartbeat(
        string $policyVersion,
        string $workerDigest,
        string $status,
        int $failureCount,
    ): void {
        ConsumerHeartbeat::query()->updateOrCreate(
            ['consumer_key' => $this->consumerKey()],
            [
                'routing_policy_version' => $policyVersion,
                'worker_ref_digest' => $workerDigest,
                'status' => $status,
                'last_seen_at' => now(),
                'metadata' => [
                    'schema_version' => 1,
                    'content_included' => false,
                    'last_batch_failure_count' => $failureCount,
                ],
            ],
        );
    }

    /** @return list<int> */
    private function pilotUnitIds(): array
    {
        return collect(config('hummingbird-patient.staff_messaging.pilot_unit_ids', []))
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function poolDigest(string $policyVersion, string $poolKey): string
    {
        return $this->hmac->digest('messaging-pool-ref', $policyVersion.'|'.$poolKey);
    }

    private function consumerKey(): string
    {
        return (string) config('hummingbird-patient.staff_messaging.consumer_key', 'patient-message-staff-inbox-v1');
    }

    private function heartbeatTtlSeconds(): int
    {
        return max(30, min(600, (int) config('hummingbird-patient.staff_messaging.heartbeat_ttl_seconds', 120)));
    }

    private function safeErrorCode(Throwable $exception): string
    {
        $message = $exception->getMessage();

        return preg_match('/^[a-z][a-z0-9_]{2,119}$/', $message) === 1
            ? $message
            : 'staff_handoff_failed';
    }
}
