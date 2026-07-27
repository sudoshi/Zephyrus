<?php

namespace App\Services\Patient\Messaging;

use App\Models\Patient\PatientMessage;
use App\Models\Patient\PatientMessageDeliveryReceipt;
use App\Models\Patient\PatientMessageRoutingEvent;
use App\Models\Patient\PatientMessageThread;
use App\Models\PatientCommunication\StaffActionEvent;
use App\Models\PatientCommunication\ThreadWorkItem;
use App\Services\Patient\PatientHmac;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Promotes unanswered work whose escalation target has elapsed. No message
 * content is read or copied; only immutable routing/receipt facts and the
 * versioned thread/work projections are changed.
 */
class PatientCommunicationEscalationService
{
    public function __construct(private readonly PatientHmac $hmac) {}

    /** @return array{selected: int, escalated: int, skipped: int, failed: int} */
    public function escalateDue(?int $limit = null): array
    {
        if (! (bool) config('hummingbird-patient.staff_messaging.enabled', false)
            || config('hummingbird-patient.staff_messaging.governance_status') !== 'approved'
        ) {
            throw StaffPatientCommunicationFailure::unavailable();
        }

        $limit = max(1, min(500, $limit ?? 100));
        $candidateIds = ThreadWorkItem::query()
            ->where('status', 'open')
            ->whereIn('ownership_state', ['pool_owned', 'assigned', 'acknowledged', 'rerouted'])
            ->where('escalate_at', '<=', now())
            ->orderBy('escalate_at')
            ->orderBy('thread_work_item_id')
            ->limit($limit)
            ->pluck('thread_work_item_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $escalated = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($candidateIds as $candidateId) {
            try {
                $outcome = DB::transaction(function () use ($candidateId): string {
                    $candidate = ThreadWorkItem::query()->find($candidateId);
                    if ($candidate === null) {
                        return 'skipped';
                    }

                    // Match the handoff and clinician mutation lock order.
                    $thread = PatientMessageThread::query()
                        ->whereKey($candidate->message_thread_id)
                        ->lockForUpdate()
                        ->first();
                    $workItem = ThreadWorkItem::query()->lockForUpdate()->find($candidateId);
                    if ($thread === null
                        || $workItem === null
                        || $thread->status !== 'open'
                        || $workItem->status !== 'open'
                        || ! in_array(
                            $workItem->ownership_state,
                            ['pool_owned', 'assigned', 'acknowledged', 'rerouted'],
                            true,
                        )
                        || $workItem->escalate_at === null
                        || $workItem->escalate_at->isFuture()
                    ) {
                        return 'skipped';
                    }

                    $occurredAt = now();
                    $operationDigest = $this->hmac->digest(
                        'messaging-staff-escalation',
                        (string) $workItem->work_item_uuid.'|'.
                        (string) $workItem->source_thread_version.'|'.
                        $workItem->escalate_at->toISOString(),
                    );
                    if (StaffActionEvent::query()
                        ->where('idempotency_key_digest', $operationDigest)
                        ->exists()
                    ) {
                        return 'skipped';
                    }

                    $payloadDigest = $this->hmac->digest(
                        'messaging-staff-payload',
                        'response-sla-exceeded|'.(string) $workItem->work_item_uuid,
                    );
                    $thread->forceFill([
                        'ownership_state' => 'escalated',
                        'version' => (int) $thread->version + 1,
                    ])->save();
                    $workItem->forceFill([
                        'ownership_state' => 'escalated',
                        'source_thread_version' => (int) $thread->version,
                        'row_version' => (int) $workItem->row_version + 1,
                    ])->save();

                    StaffActionEvent::query()->create([
                        'event_uuid' => (string) Str::uuid7(),
                        'thread_work_item_id' => $workItem->getKey(),
                        'event_type' => 'escalated',
                        'from_pool_id' => $workItem->responsibility_pool_id,
                        'to_pool_id' => $workItem->responsibility_pool_id,
                        'reason_code' => 'response_sla_exceeded',
                        'patient_visible_state' => 'escalated',
                        'idempotency_key_digest' => $operationDigest,
                        'request_payload_digest' => $payloadDigest,
                        'metadata' => [
                            'schema_version' => 1,
                            'content_included' => false,
                            'source' => 'response_sla_monitor',
                        ],
                        'occurred_at' => $occurredAt,
                    ]);
                    PatientMessageRoutingEvent::query()->create([
                        'routing_event_uuid' => (string) Str::uuid7(),
                        'message_thread_id' => $thread->getKey(),
                        'event_type' => 'escalated',
                        'from_pool_ref_digest' => (string) $thread->responsibility_pool_ref_digest,
                        'to_pool_ref_digest' => (string) $thread->responsibility_pool_ref_digest,
                        'actor_type' => 'system',
                        'actor_ref_digest' => $this->hmac->digest(
                            'messaging-actor-ref',
                            'response-sla-monitor',
                        ),
                        'reason_code' => 'response_sla_exceeded',
                        'patient_visible_state' => 'escalated',
                        'routing_policy_version' => (string) $thread->routing_policy_version,
                        'idempotency_key_digest' => $this->hmac->digest(
                            'messaging-routing',
                            $operationDigest,
                        ),
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
                        PatientMessageDeliveryReceipt::query()->create([
                            'receipt_uuid' => (string) Str::uuid7(),
                            'message_id' => $latestPatientMessage->getKey(),
                            'receipt_type' => 'escalated',
                            'actor_type' => 'system',
                            'actor_ref_digest' => $this->hmac->digest(
                                'messaging-actor-ref',
                                'response-sla-monitor',
                            ),
                            'patient_visible_state' => 'escalated',
                            'idempotency_key_digest' => $this->hmac->digest(
                                'messaging-receipt',
                                $operationDigest,
                            ),
                            'occurred_at' => $occurredAt,
                        ]);
                    }

                    return 'escalated';
                }, 3);
                if ($outcome === 'escalated') {
                    $escalated++;
                } else {
                    $skipped++;
                }
            } catch (Throwable) {
                $failed++;
            }
        }

        return [
            'selected' => count($candidateIds),
            'escalated' => $escalated,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }
}
