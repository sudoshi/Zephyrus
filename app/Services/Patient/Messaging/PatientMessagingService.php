<?php

namespace App\Services\Patient\Messaging;

use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientMessage;
use App\Models\Patient\PatientMessageDeliveryReceipt;
use App\Models\Patient\PatientMessageRoutingEvent;
use App\Models\Patient\PatientMessageThread;
use App\Models\Patient\PatientNotificationOutbox;
use App\Models\Patient\PatientPrincipal;
use App\Services\Patient\PatientAccessAuditRecorder;
use App\Services\Patient\PatientHmac;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

class PatientMessagingService
{
    public function __construct(
        private readonly PatientMessagingPolicyRegistry $policies,
        private readonly PatientCommunicationEncounterGuard $encounters,
        private readonly PatientAccessAuditRecorder $audit,
        private readonly PatientHmac $hmac,
        private readonly PatientMessageCipher $cipher,
    ) {}

    /** @return array<string, mixed> */
    public function topics(
        Request $request,
        PatientPrincipal $principal,
        string $encounterUuid,
    ): array {
        $policy = $this->policies->disclosurePolicy();

        try {
            return DB::transaction(function () use (
                $request,
                $principal,
                $encounterUuid,
                $policy,
            ): array {
                $grant = $this->lockGrantForEncounter(
                    $principal,
                    $encounterUuid,
                    ['messaging:read'],
                );
                $this->encounters->assertDisclosable($grant);
                $result = [
                    'topics' => collect($policy['topics'])
                        ->filter(fn (array $topic): bool => $topic['composition_mode'] === 'direct')
                        ->map(fn (array $topic, string $code): array => [
                            'code' => $code,
                            'label' => $topic['label'],
                            'description' => $topic['description'],
                            'expected_response_window' => $policy['default_response_window'],
                        ])
                        ->values()
                        ->all(),
                    'immediate_help' => $this->immediateHelp($policy),
                    'policy_version' => $policy['policy_version'],
                ];

                $this->audit->record(
                    $this->perRequestAuditRequest($request),
                    'patient.messaging.topics_viewed',
                    'messaging',
                    'list_topics',
                    'succeeded',
                    $principal,
                    grant: $grant,
                    resourceType: 'patient_encounter',
                    resourceUuid: $encounterUuid,
                    metadata: [
                        'topic_count' => collect($policy['topics'])
                            ->where('composition_mode', 'direct')
                            ->count(),
                    ],
                );

                return $result;
            }, 3);
        } catch (PatientMessagingFailure $failure) {
            if ($failure->errorCode === 'not_found') {
                $this->recordEncounterAccessDenial($request, $principal, $encounterUuid);
            }

            throw $failure;
        }
    }

    /** @return array<string, mixed> */
    public function listThreads(
        Request $request,
        PatientPrincipal $principal,
        string $encounterUuid,
    ): array {
        $policy = $this->policies->disclosurePolicy();

        try {
            return DB::transaction(function () use (
                $request,
                $principal,
                $encounterUuid,
                $policy,
            ): array {
                $grant = $this->lockGrantForEncounter(
                    $principal,
                    $encounterUuid,
                    ['messaging:read'],
                );
                $this->encounters->assertDisclosable($grant);
                $threads = PatientMessageThread::query()
                    ->where('access_grant_id', $grant->getKey())
                    ->orderByDesc('last_message_at')
                    ->orderByDesc('message_thread_id')
                    ->limit(50)
                    ->get();
                $result = [
                    'threads' => $threads
                        ->map(fn (PatientMessageThread $thread): array => $this->serializeThread($thread))
                        ->values()
                        ->all(),
                    'immediate_help' => $this->immediateHelp($policy),
                    'policy_version' => $policy['policy_version'],
                ];

                $this->audit->record(
                    $this->perRequestAuditRequest($request),
                    'patient.messaging.threads_viewed',
                    'messaging',
                    'list_threads',
                    'succeeded',
                    $principal,
                    grant: $grant,
                    resourceType: 'patient_encounter',
                    resourceUuid: $encounterUuid,
                    metadata: ['thread_count' => $threads->count()],
                );

                return $result;
            }, 3);
        } catch (PatientMessagingFailure $failure) {
            if ($failure->errorCode === 'not_found') {
                $this->recordEncounterAccessDenial($request, $principal, $encounterUuid);
            }

            throw $failure;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{thread: array<string, mixed>, replayed: bool, status: int, policy_version: string}
     */
    public function createThread(
        Request $request,
        PatientPrincipal $principal,
        string $encounterUuid,
        array $input,
    ): array {
        return $this->createThreadForCompositionMode(
            $request,
            $principal,
            $encounterUuid,
            $input,
            'direct',
        );
    }

    /**
     * Create a thread only after a caller has verified that its education item
     * is present in a currently released patient pathway projection. Keeping
     * this separate from the public direct-composition path prevents a caller
     * from manufacturing an education association with an arbitrary UUID.
     *
     * @param  array<string, mixed>  $input
     * @return array{thread: array<string, mixed>, replayed: bool, status: int, policy_version: string}
     */
    public function createThreadForReleasedEducation(
        Request $request,
        PatientPrincipal $principal,
        string $encounterUuid,
        array $input,
    ): array {
        return $this->createThreadForCompositionMode(
            $request,
            $principal,
            $encounterUuid,
            $input,
            'released_education_only',
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{thread: array<string, mixed>, replayed: bool, status: int, policy_version: string}
     */
    private function createThreadForCompositionMode(
        Request $request,
        PatientPrincipal $principal,
        string $encounterUuid,
        array $input,
        string $expectedCompositionMode,
    ): array {
        $disclosurePolicy = $this->policies->disclosurePolicy();
        $declaredTopic = $disclosurePolicy['topics'][(string) $input['topic_code']] ?? null;
        if (! is_array($declaredTopic)
            || $declaredTopic['composition_mode'] !== $expectedCompositionMode) {
            throw PatientMessagingFailure::notFound();
        }
        $grant = $this->grant(
            $request,
            $principal,
            $encounterUuid,
            ['messaging:read', 'messaging:write'],
        );
        $messageBody = (string) $input['message'];
        $bodyDigest = $this->hmac->digest('messaging-body', $messageBody);
        $operationDigest = $this->operationDigest(
            $principal,
            'thread-create',
            (string) $input['idempotency_key'],
        );
        $payloadDigest = $this->payloadDigest([
            'client_message_uuid' => (string) $input['client_message_uuid'],
            'encounter_uuid' => $encounterUuid,
            'message_body_digest' => $bodyDigest,
            'topic_code' => (string) $input['topic_code'],
            'urgent_guidance_version' => (string) $input['urgent_guidance_version'],
        ]);

        try {
            return DB::transaction(function () use (
                $request,
                $principal,
                $grant,
                $input,
                $disclosurePolicy,
                $messageBody,
                $bodyDigest,
                $operationDigest,
                $payloadDigest,
                $expectedCompositionMode,
            ): array {
                $this->acquireReplayLocks([
                    'operation|'.$operationDigest,
                    'client-message|'.(string) $input['client_message_uuid'],
                ]);

                $operationThread = PatientMessageThread::query()
                    ->where('creation_idempotency_key_digest', $operationDigest)
                    ->first();
                $existingMessage = PatientMessage::query()
                    ->where('client_message_uuid', (string) $input['client_message_uuid'])
                    ->first();
                $operationThreadId = $operationThread?->getKey();
                $clientThreadId = $existingMessage?->message_thread_id;

                if ($operationThreadId !== null
                    && $clientThreadId !== null
                    && (int) $operationThreadId !== (int) $clientThreadId
                ) {
                    $conflictingThread = $this->lockThreadById(
                        $principal,
                        (int) $operationThreadId,
                        'send',
                    );
                    $this->encounters->assertDisclosable($conflictingThread->accessGrant);

                    throw PatientMessagingFailure::idempotencyConflict();
                }

                $existingThreadId = $operationThreadId ?? $clientThreadId;
                if ($existingThreadId !== null) {
                    $existingThread = $this->lockThreadById(
                        $principal,
                        (int) $existingThreadId,
                        'send',
                    );
                    $this->encounters->assertDisclosable($existingThread->accessGrant);
                    $this->assertThreadReplay(
                        $existingThread,
                        $principal,
                        $payloadDigest,
                        (int) $grant->getKey(),
                    );
                    $result = $this->threadResult($existingThread, true, 200, $disclosurePolicy);

                    $this->audit->record(
                        $this->perRequestAuditRequest($request),
                        'patient.messaging.thread_creation_replayed',
                        'messaging',
                        'replay_create_thread',
                        'succeeded',
                        $principal,
                        grant: $existingThread->accessGrant,
                        resourceType: 'patient_message_thread',
                        resourceUuid: (string) $existingThread->thread_uuid,
                        metadata: [
                            'replay_source' => $operationThreadId !== null && $clientThreadId !== null
                                ? 'operation_and_client_message'
                                : ($operationThreadId !== null ? 'operation' : 'client_message'),
                            'thread_version' => (int) $existingThread->version,
                        ],
                    );

                    return $result;
                }

                $policy = $this->policies->mutationPolicy();
                $this->assertGuidanceVersion($input, $policy);
                $topic = $policy['topics'][(string) $input['topic_code']] ?? null;
                if (! is_array($topic)) {
                    throw PatientMessagingFailure::notFound();
                }

                if ($topic['composition_mode'] !== $expectedCompositionMode) {
                    throw PatientMessagingFailure::notFound();
                }

                $lockedGrant = $this->lockGrant($grant, $principal, ['messaging:read', 'messaging:write']);
                $this->encounters->assertFreshMutationRoutable($lockedGrant);
                $this->policies->assertMutationRoutable(
                    $policy,
                    (string) $input['topic_code'],
                    $topic,
                    $lockedGrant,
                );
                $occurredAt = now();
                $poolDigest = $this->hmac->digest(
                    'messaging-pool-ref',
                    $policy['policy_version'].'|'.$topic['responsibility_pool_key'],
                );

                $thread = PatientMessageThread::query()->create([
                    'thread_uuid' => (string) Str::uuid7(),
                    'access_grant_id' => $lockedGrant->getKey(),
                    'opened_by_principal_id' => $principal->getKey(),
                    'topic_code' => (string) $input['topic_code'],
                    'topic_label' => $topic['label'],
                    'topic_description' => $topic['description'],
                    'status' => 'open',
                    'ownership_state' => 'awaiting_team',
                    'routing_policy_version' => $policy['policy_version'],
                    'expected_response_window' => $policy['default_response_window'],
                    'urgent_guidance_version' => $policy['urgent_guidance_version'],
                    'responsibility_pool_ref_digest' => $poolDigest,
                    'creation_idempotency_key_digest' => $operationDigest,
                    'creation_request_payload_digest' => $payloadDigest,
                    'version' => 1,
                    'last_message_at' => $occurredAt,
                ]);

                $message = $this->createPatientMessage(
                    $thread,
                    $principal,
                    $messageBody,
                    $bodyDigest,
                    (string) $input['client_message_uuid'],
                    $operationDigest,
                    $payloadDigest,
                    $policy['encryption_key_version'],
                    $occurredAt,
                );

                $this->createReceipt($message, $principal, 'server_accepted', 'sent', $operationDigest, $occurredAt);
                $this->createRoutingEvent(
                    $thread,
                    $principal,
                    'thread_opened',
                    'new_patient_thread',
                    'awaiting_team',
                    $operationDigest,
                    $payloadDigest,
                    $policy['policy_version'],
                    $occurredAt,
                    toPoolDigest: $poolDigest,
                );
                $this->createStaffInboxOutbox(
                    $thread,
                    $principal,
                    $lockedGrant,
                    'patient.messaging.thread_opened',
                    $operationDigest,
                    $occurredAt,
                );
                $result = $this->threadResult($thread, false, 201, $policy);
                $this->audit->record(
                    $request,
                    'patient.messaging.thread_created',
                    'messaging',
                    'create_thread',
                    'succeeded',
                    $principal,
                    grant: $lockedGrant,
                    resourceType: 'patient_message_thread',
                    resourceUuid: (string) $thread->thread_uuid,
                    metadata: ['thread_version' => 1],
                );

                return $result;
            }, 3);
        } catch (PatientMessagingFailure $failure) {
            if ($failure->errorCode === 'not_found') {
                $this->audit->bestEffort(
                    $this->perRequestAuditRequest($request),
                    'patient.messaging.access_denied',
                    'messaging',
                    'create_thread',
                    'denied',
                    $principal,
                    grant: $grant,
                    reasonCode: 'resource_unavailable',
                    resourceType: 'patient_encounter',
                    resourceUuid: $encounterUuid,
                );
            }

            throw $failure;
        }
    }

    /** @return array<string, mixed> */
    public function showThread(
        Request $request,
        PatientPrincipal $principal,
        string $threadUuid,
    ): array {
        $policy = $this->policies->disclosurePolicy();

        try {
            return DB::transaction(function () use (
                $request,
                $principal,
                $threadUuid,
                $policy,
            ): array {
                $thread = $this->lockThread($principal, $threadUuid, 'view');
                $this->encounters->assertDisclosable($thread->accessGrant);
                $thread->load([
                    'messages' => fn ($query) => $query
                        ->where('visibility', 'patient_visible')
                        ->with(['receipts', 'relatesTo'])
                        ->orderByDesc('sent_at')
                        ->orderByDesc('message_id')
                        ->limit(1001),
                ]);
                $historyTruncated = $thread->messages->count() > 1000;
                $messages = $thread->messages
                    ->take(1000)
                    ->sortBy([
                        ['sent_at', 'asc'],
                        ['message_id', 'asc'],
                    ])
                    ->values();
                $thread->setRelation('messages', $messages);
                $result = [
                    'thread' => $this->serializeThread($thread, true, $historyTruncated),
                    'immediate_help' => $this->immediateHelp($policy),
                    'policy_version' => $policy['policy_version'],
                ];

                // Serialization decrypts every returned body. Record success
                // only after that work completes so failed disclosure cannot
                // leave a false success event.
                $this->audit->record(
                    $this->perRequestAuditRequest($request),
                    'patient.messaging.thread_viewed',
                    'messaging',
                    'view_thread',
                    'succeeded',
                    $principal,
                    grant: $thread->accessGrant,
                    resourceType: 'patient_message_thread',
                    resourceUuid: $threadUuid,
                    metadata: [
                        'history_truncated' => $historyTruncated,
                        'message_count' => $thread->messages->count(),
                    ],
                );

                return $result;
            }, 3);
        } catch (PatientMessagingFailure $failure) {
            if ($failure->errorCode === 'not_found') {
                $this->recordThreadAccessDenial($request, $principal, $threadUuid, 'view_thread');
            }

            throw $failure;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{thread: array<string, mixed>, message: array<string, mixed>, replayed: bool, status: int, policy_version: string}
     */
    public function sendMessage(
        Request $request,
        PatientPrincipal $principal,
        string $threadUuid,
        array $input,
    ): array {
        $disclosurePolicy = $this->policies->disclosurePolicy();
        $messageBody = (string) $input['message'];
        $bodyDigest = $this->hmac->digest('messaging-body', $messageBody);
        $operationDigest = $this->operationDigest(
            $principal,
            'message-send',
            (string) $input['idempotency_key'],
        );
        $payloadDigest = $this->payloadDigest([
            'client_message_uuid' => (string) $input['client_message_uuid'],
            'message_body_digest' => $bodyDigest,
            'thread_uuid' => $threadUuid,
            'thread_version' => (int) $input['thread_version'],
            'urgent_guidance_version' => (string) $input['urgent_guidance_version'],
        ]);

        // Persist an ordinary ownership denial before entering the mutation
        // transaction. A second check still runs under the row lock.
        $this->thread($request, $principal, $threadUuid, 'send');

        try {
            return DB::transaction(function () use (
                $request,
                $principal,
                $threadUuid,
                $input,
                $disclosurePolicy,
                $messageBody,
                $bodyDigest,
                $operationDigest,
                $payloadDigest,
            ): array {
                $this->acquireReplayLocks([
                    'operation|'.$operationDigest,
                    'client-message|'.(string) $input['client_message_uuid'],
                ]);

                $thread = $this->lockThread($principal, $threadUuid, 'send');
                $operationMessage = PatientMessage::query()
                    ->where('idempotency_key_digest', $operationDigest)
                    ->first();
                $clientMessage = PatientMessage::query()
                    ->where('client_message_uuid', (string) $input['client_message_uuid'])
                    ->first();

                if ($operationMessage !== null || $clientMessage !== null) {
                    $this->encounters->assertDisclosable($thread->accessGrant);
                }

                if ($operationMessage !== null
                    && $clientMessage !== null
                    && (int) $operationMessage->getKey() !== (int) $clientMessage->getKey()
                ) {
                    throw PatientMessagingFailure::idempotencyConflict();
                }

                $existing = $operationMessage ?? $clientMessage;
                if ($existing !== null) {
                    $existing->setRelation('thread', $thread);
                    $existing->load(['receipts', 'relatesTo']);
                    $this->assertMessageReplay($existing, $principal, $threadUuid, $payloadDigest);
                    $result = $this->messageResult($thread, $existing, true, 200, $disclosurePolicy);

                    $this->audit->record(
                        $this->perRequestAuditRequest($request),
                        'patient.messaging.message_send_replayed',
                        'messaging',
                        'replay_send_message',
                        'succeeded',
                        $principal,
                        grant: $thread->accessGrant,
                        resourceType: 'patient_message',
                        resourceUuid: (string) $existing->message_uuid,
                        metadata: [
                            'replay_source' => $operationMessage !== null && $clientMessage !== null
                                ? 'operation_and_client_message'
                                : ($operationMessage !== null ? 'operation' : 'client_message'),
                            'thread_version' => (int) $thread->version,
                        ],
                    );

                    return $result;
                }

                $policy = $this->policies->mutationPolicy();
                $this->assertGuidanceVersion($input, $policy);
                $topic = $policy['topics'][(string) $thread->topic_code] ?? null;
                if (! is_array($topic)) {
                    throw PatientMessagingFailure::unavailable();
                }

                $this->encounters->assertFreshThreadMutationRoutable(
                    $thread->accessGrant,
                    $thread,
                );
                $this->policies->assertMutationRoutable(
                    $policy,
                    (string) $thread->topic_code,
                    $topic,
                    $thread->accessGrant,
                );

                if ($thread->status !== 'open') {
                    throw PatientMessagingFailure::threadClosed();
                }
                if ((int) $thread->version !== (int) $input['thread_version']) {
                    throw PatientMessagingFailure::staleVersion();
                }
                if ($thread->messages()->count() >= 1000) {
                    throw PatientMessagingFailure::threadMessageLimitReached();
                }

                $occurredAt = now();
                $message = $this->createPatientMessage(
                    $thread,
                    $principal,
                    $messageBody,
                    $bodyDigest,
                    (string) $input['client_message_uuid'],
                    $operationDigest,
                    $payloadDigest,
                    $policy['encryption_key_version'],
                    $occurredAt,
                );

                $thread->forceFill([
                    'ownership_state' => 'awaiting_team',
                    'version' => (int) $thread->version + 1,
                    'last_message_at' => $occurredAt,
                ])->save();

                $this->createReceipt($message, $principal, 'server_accepted', 'sent', $operationDigest, $occurredAt);
                $this->createRoutingEvent(
                    $thread,
                    $principal,
                    'message_submitted',
                    'patient_follow_up',
                    'awaiting_team',
                    $operationDigest,
                    $payloadDigest,
                    $policy['policy_version'],
                    $occurredAt,
                    toPoolDigest: (string) $thread->responsibility_pool_ref_digest,
                );
                $this->createStaffInboxOutbox(
                    $thread,
                    $principal,
                    $thread->accessGrant,
                    'patient.messaging.message_submitted',
                    $operationDigest,
                    $occurredAt,
                );
                $result = $this->messageResult($thread, $message, false, 201, $policy);
                $this->audit->record(
                    $request,
                    'patient.messaging.message_sent',
                    'messaging',
                    'send_message',
                    'succeeded',
                    $principal,
                    grant: $thread->accessGrant,
                    resourceType: 'patient_message',
                    resourceUuid: (string) $message->message_uuid,
                    metadata: ['thread_version' => (int) $thread->version],
                );

                return $result;
            }, 3);
        } catch (PatientMessagingFailure $failure) {
            if ($failure->errorCode === 'not_found') {
                $this->recordMutationAccessDenial($request, $principal, $threadUuid, 'send_thread');
            }

            throw $failure;
        }
    }

    /**
     * Append one patient-authored correction or retraction to an eligible
     * patient message. The source message is never mutated or deleted. A
     * correction/retraction is routed back to the accountable team as a new,
     * content-free outbox fact so it cannot silently rewrite staff work that
     * may already have been reviewed.
     *
     * @param  array{action: string, message?: string, client_message_uuid: string, thread_version: int, urgent_guidance_version: string, idempotency_key: string}  $input
     * @return array{thread: array<string, mixed>, message: array<string, mixed>, replayed: bool, status: int, policy_version: string}
     */
    public function amendMessage(
        Request $request,
        PatientPrincipal $principal,
        string $threadUuid,
        string $messageUuid,
        array $input,
    ): array {
        $disclosurePolicy = $this->policies->disclosurePolicy();
        $action = (string) $input['action'];
        $body = $action === 'correction' ? (string) $input['message'] : null;
        $bodyDigest = $body !== null ? $this->hmac->digest('messaging-body', $body) : null;
        $operationDigest = $this->operationDigest(
            $principal,
            'message-amend',
            (string) $input['idempotency_key'],
        );
        $payloadDigest = $this->payloadDigest([
            'action' => $action,
            'client_message_uuid' => (string) $input['client_message_uuid'],
            'message_body_digest' => $bodyDigest,
            'message_uuid' => $messageUuid,
            'thread_uuid' => $threadUuid,
            'thread_version' => (int) $input['thread_version'],
            'urgent_guidance_version' => (string) $input['urgent_guidance_version'],
        ]);

        // Persist an ordinary ownership denial before entering the mutation
        // transaction. A second check still runs under the row lock.
        $this->thread($request, $principal, $threadUuid, 'amend');

        try {
            return DB::transaction(function () use (
                $request,
                $principal,
                $threadUuid,
                $messageUuid,
                $input,
                $action,
                $body,
                $bodyDigest,
                $disclosurePolicy,
                $operationDigest,
                $payloadDigest,
            ): array {
                $this->acquireReplayLocks([
                    'operation|'.$operationDigest,
                    'client-message|'.(string) $input['client_message_uuid'],
                ]);

                $thread = $this->lockThread($principal, $threadUuid, 'amend');
                $operationMessage = PatientMessage::query()
                    ->where('idempotency_key_digest', $operationDigest)
                    ->first();
                $clientMessage = PatientMessage::query()
                    ->where('client_message_uuid', (string) $input['client_message_uuid'])
                    ->first();

                if ($operationMessage !== null || $clientMessage !== null) {
                    $this->encounters->assertDisclosable($thread->accessGrant);
                }
                if ($operationMessage !== null
                    && $clientMessage !== null
                    && (int) $operationMessage->getKey() !== (int) $clientMessage->getKey()
                ) {
                    throw PatientMessagingFailure::idempotencyConflict();
                }

                $existing = $operationMessage ?? $clientMessage;
                if ($existing !== null) {
                    $existing->setRelation('thread', $thread);
                    $existing->load(['receipts', 'relatesTo']);
                    $this->assertAmendReplay(
                        $existing,
                        $principal,
                        $threadUuid,
                        $messageUuid,
                        $action,
                        $payloadDigest,
                    );
                    $result = $this->messageResult($thread, $existing, true, 200, $disclosurePolicy);
                    $this->audit->record(
                        $this->perRequestAuditRequest($request),
                        'patient.messaging.message_amend_replayed',
                        'messaging',
                        'replay_amend_message',
                        'succeeded',
                        $principal,
                        grant: $thread->accessGrant,
                        resourceType: 'patient_message',
                        resourceUuid: (string) $existing->message_uuid,
                        metadata: [
                            'replay_source' => $operationMessage !== null && $clientMessage !== null
                                ? 'operation_and_client_message'
                                : ($operationMessage !== null ? 'operation' : 'client_message'),
                            'thread_version' => (int) $thread->version,
                        ],
                    );

                    return $result;
                }

                $policy = $this->policies->mutationPolicy();
                $this->assertGuidanceVersion($input, $policy);
                $topic = $policy['topics'][(string) $thread->topic_code] ?? null;
                if (! is_array($topic)) {
                    throw PatientMessagingFailure::unavailable();
                }

                $this->encounters->assertFreshThreadMutationRoutable(
                    $thread->accessGrant,
                    $thread,
                );
                $this->policies->assertMutationRoutable(
                    $policy,
                    (string) $thread->topic_code,
                    $topic,
                    $thread->accessGrant,
                );

                if ($thread->status !== 'open') {
                    throw PatientMessagingFailure::threadClosed();
                }
                if ((int) $thread->version !== (int) $input['thread_version']) {
                    throw PatientMessagingFailure::staleVersion();
                }
                if ($thread->messages()->count() >= 1000) {
                    throw PatientMessagingFailure::threadMessageLimitReached();
                }

                $source = PatientMessage::query()
                    ->where('message_thread_id', $thread->getKey())
                    ->where('message_uuid', $messageUuid)
                    ->lockForUpdate()
                    ->first();
                if (! $source instanceof PatientMessage
                    || (int) $source->sender_principal_id !== (int) $principal->getKey()
                    || ! in_array($source->sender_type, ['patient', 'representative'], true)
                    || $source->visibility !== 'patient_visible'
                    || $source->message_kind !== 'message'
                ) {
                    throw PatientMessagingFailure::notFound();
                }
                if (PatientMessage::query()
                    ->where('relates_to_message_id', $source->getKey())
                    ->whereIn('message_kind', ['correction', 'retraction'])
                    ->sharedLock()
                    ->exists()
                ) {
                    throw PatientMessagingFailure::messageNotAmendable();
                }

                $occurredAt = now();
                $amendment = $action === 'correction'
                    ? $this->createPatientMessage(
                        $thread,
                        $principal,
                        (string) $body,
                        (string) $bodyDigest,
                        (string) $input['client_message_uuid'],
                        $operationDigest,
                        $payloadDigest,
                        (string) $policy['encryption_key_version'],
                        $occurredAt,
                        messageKind: 'correction',
                        relatesToMessageId: (int) $source->getKey(),
                    )
                    : $this->createPatientRetraction(
                        $thread,
                        $principal,
                        (string) $input['client_message_uuid'],
                        $operationDigest,
                        $payloadDigest,
                        (int) $source->getKey(),
                        $occurredAt,
                    );

                $thread->forceFill([
                    'ownership_state' => 'awaiting_team',
                    'version' => (int) $thread->version + 1,
                    'last_message_at' => $occurredAt,
                ])->save();

                $eventSuffix = $action === 'correction' ? 'corrected' : 'retracted';
                $this->createReceipt($amendment, $principal, 'server_accepted', 'sent', $operationDigest, $occurredAt);
                $this->createRoutingEvent(
                    $thread,
                    $principal,
                    'message_'.$eventSuffix,
                    'patient_message_'.$eventSuffix,
                    'awaiting_team',
                    $operationDigest,
                    $payloadDigest,
                    $policy['policy_version'],
                    $occurredAt,
                    toPoolDigest: (string) $thread->responsibility_pool_ref_digest,
                );
                $this->createStaffInboxOutbox(
                    $thread,
                    $principal,
                    $thread->accessGrant,
                    'patient.messaging.message_'.$eventSuffix,
                    $operationDigest,
                    $occurredAt,
                );
                $result = $this->messageResult($thread, $amendment, false, 201, $policy);
                $this->audit->record(
                    $request,
                    'patient.messaging.message_'.$eventSuffix,
                    'messaging',
                    'amend_message',
                    'succeeded',
                    $principal,
                    grant: $thread->accessGrant,
                    resourceType: 'patient_message',
                    resourceUuid: (string) $amendment->message_uuid,
                    metadata: ['thread_version' => (int) $thread->version],
                );

                return $result;
            }, 3);
        } catch (PatientMessagingFailure $failure) {
            if ($failure->errorCode === 'not_found') {
                $this->recordMutationAccessDenial($request, $principal, $threadUuid, 'amend_message');
            }

            throw $failure;
        } catch (QueryException $exception) {
            // The pre-insert relationship check gives an ordinary caller a
            // stable domain result. The unique index is the final authority
            // when two devices race to amend the same immutable source.
            if ($this->isMessageAmendmentUniquenessViolation($exception)) {
                throw PatientMessagingFailure::messageNotAmendable();
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{thread: array<string, mixed>, replayed: bool, status: int, policy_version: string}
     */
    public function closeThread(
        Request $request,
        PatientPrincipal $principal,
        string $threadUuid,
        array $input,
    ): array {
        $disclosurePolicy = $this->policies->disclosurePolicy();
        $operationDigest = $this->operationDigest(
            $principal,
            'thread-close',
            (string) $input['idempotency_key'],
        );
        $payloadDigest = $this->payloadDigest([
            'close_reason' => (string) $input['close_reason'],
            'thread_uuid' => $threadUuid,
            'thread_version' => (int) $input['thread_version'],
        ]);
        $routingDigest = $this->derivedDigest('messaging-routing', $operationDigest);

        // Persist an ordinary ownership denial before entering the mutation
        // transaction. A second check still runs under the row lock.
        $this->thread($request, $principal, $threadUuid, 'close');

        try {
            return DB::transaction(function () use (
                $request,
                $principal,
                $threadUuid,
                $input,
                $disclosurePolicy,
                $operationDigest,
                $payloadDigest,
                $routingDigest,
            ): array {
                $this->acquireReplayLocks(['operation|'.$operationDigest]);

                $thread = $this->lockThread($principal, $threadUuid, 'close');
                $existing = PatientMessageRoutingEvent::query()
                    ->where('idempotency_key_digest', $routingDigest)
                    ->first();
                if ($existing !== null) {
                    $this->encounters->assertDisclosable($thread->accessGrant);
                    if (! hash_equals((string) $existing->request_payload_digest, $payloadDigest)
                        || (int) $existing->message_thread_id !== (int) $thread->getKey()
                    ) {
                        throw PatientMessagingFailure::idempotencyConflict();
                    }

                    $result = $this->threadResult($thread, true, 200, $disclosurePolicy);
                    $this->audit->record(
                        $this->perRequestAuditRequest($request),
                        'patient.messaging.thread_close_replayed',
                        'messaging',
                        'replay_close_thread',
                        'succeeded',
                        $principal,
                        grant: $thread->accessGrant,
                        resourceType: 'patient_message_thread',
                        resourceUuid: $threadUuid,
                        metadata: [
                            'replay_source' => 'operation',
                            'thread_version' => (int) $thread->version,
                        ],
                    );

                    return $result;
                }

                $policy = $this->policies->mutationPolicy();
                $topic = $policy['topics'][(string) $thread->topic_code] ?? null;
                if (! is_array($topic)) {
                    throw PatientMessagingFailure::unavailable();
                }

                $this->encounters->assertFreshMutationRoutable($thread->accessGrant);
                $this->policies->assertMutationRoutable(
                    $policy,
                    (string) $thread->topic_code,
                    $topic,
                    $thread->accessGrant,
                );

                if ($thread->status === 'closed') {
                    throw PatientMessagingFailure::threadClosed();
                }
                if ((int) $thread->version !== (int) $input['thread_version']) {
                    throw PatientMessagingFailure::staleVersion();
                }

                $occurredAt = now();
                $thread->forceFill([
                    'status' => 'closed',
                    'ownership_state' => 'closed',
                    'version' => (int) $thread->version + 1,
                    'closed_at' => $occurredAt,
                    'close_reason_code' => (string) $input['close_reason'],
                ])->save();

                $this->createRoutingEvent(
                    $thread,
                    $principal,
                    'closed',
                    (string) $input['close_reason'],
                    'closed',
                    $operationDigest,
                    $payloadDigest,
                    $policy['policy_version'],
                    $occurredAt,
                );
                $this->createStaffInboxOutbox(
                    $thread,
                    $principal,
                    $thread->accessGrant,
                    'patient.messaging.thread_closed',
                    $operationDigest,
                    $occurredAt,
                );
                $result = $this->threadResult($thread, false, 200, $policy);
                $this->audit->record(
                    $request,
                    'patient.messaging.thread_closed',
                    'messaging',
                    'close_thread',
                    'succeeded',
                    $principal,
                    grant: $thread->accessGrant,
                    reasonCode: (string) $input['close_reason'],
                    resourceType: 'patient_message_thread',
                    resourceUuid: $threadUuid,
                    metadata: ['thread_version' => (int) $thread->version],
                );

                return $result;
            }, 3);
        } catch (PatientMessagingFailure $failure) {
            if ($failure->errorCode === 'not_found') {
                $this->recordMutationAccessDenial($request, $principal, $threadUuid, 'close_thread');
            }

            throw $failure;
        }
    }

    /**
     * @param  list<string>  $requiredScopes
     */
    private function grant(
        Request $request,
        PatientPrincipal $principal,
        string $encounterUuid,
        array $requiredScopes,
    ): PatientEncounterAccessGrant {
        $grant = PatientEncounterAccessGrant::query()
            ->where('principal_id', $principal->getKey())
            ->where('encounter_uuid', $encounterUuid)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where('valid_from', '<=', now())
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($grant === null
            || ! $principal->can('view', $grant)
            || collect($requiredScopes)->contains(fn (string $scope): bool => ! $grant->permits($scope))
        ) {
            $this->audit->bestEffort(
                $this->perRequestAuditRequest($request),
                'patient.messaging.access_denied',
                'messaging',
                'resolve_encounter',
                'denied',
                $principal,
                grant: $grant,
                reasonCode: 'resource_unavailable',
                resourceType: 'patient_encounter',
                resourceUuid: $encounterUuid,
            );

            throw PatientMessagingFailure::notFound();
        }

        return $grant;
    }

    /**
     * @param  list<string>  $requiredScopes
     */
    private function lockGrant(
        PatientEncounterAccessGrant $grant,
        PatientPrincipal $principal,
        array $requiredScopes,
    ): PatientEncounterAccessGrant {
        $locked = PatientEncounterAccessGrant::query()->lockForUpdate()->find($grant->getKey());

        if (! $this->grantIsAuthorized($locked, $principal, $requiredScopes)) {
            throw PatientMessagingFailure::notFound();
        }

        return $locked;
    }

    /**
     * Resolve, lock, and revalidate an encounter grant inside the caller's
     * transaction. Filtering revoked rows out of the query would make it
     * impossible to distinguish a stale pre-lock read from a current grant.
     *
     * @param  list<string>  $requiredScopes
     */
    private function lockGrantForEncounter(
        PatientPrincipal $principal,
        string $encounterUuid,
        array $requiredScopes,
    ): PatientEncounterAccessGrant {
        $grant = PatientEncounterAccessGrant::query()
            ->where('principal_id', $principal->getKey())
            ->where('encounter_uuid', $encounterUuid)
            ->orderByDesc('access_grant_id')
            ->lockForUpdate()
            ->first();

        if (! $this->grantIsAuthorized($grant, $principal, $requiredScopes)) {
            throw PatientMessagingFailure::notFound();
        }

        return $grant;
    }

    /**
     * @param  list<string>  $requiredScopes
     */
    private function grantIsAuthorized(
        ?PatientEncounterAccessGrant $grant,
        PatientPrincipal $principal,
        array $requiredScopes,
    ): bool {
        return $grant instanceof PatientEncounterAccessGrant
            && $principal->can('view', $grant)
            && ! collect($requiredScopes)
                ->contains(fn (string $scope): bool => ! $grant->permits($scope));
    }

    private function thread(
        Request $request,
        PatientPrincipal $principal,
        string $threadUuid,
        string $ability,
    ): PatientMessageThread {
        $thread = PatientMessageThread::query()
            ->with('accessGrant')
            ->where('thread_uuid', $threadUuid)
            ->first();

        if ($thread === null || ! $principal->can($ability, $thread)) {
            $this->audit->bestEffort(
                $this->perRequestAuditRequest($request),
                'patient.messaging.access_denied',
                'messaging',
                $ability.'_thread',
                'denied',
                $principal,
                reasonCode: 'resource_unavailable',
                resourceType: 'patient_message_thread',
                resourceUuid: $threadUuid,
            );

            throw PatientMessagingFailure::notFound();
        }

        return $thread;
    }

    private function lockThread(
        PatientPrincipal $principal,
        string $threadUuid,
        string $ability,
    ): PatientMessageThread {
        $thread = PatientMessageThread::query()
            ->where('thread_uuid', $threadUuid)
            ->lockForUpdate()
            ->first();

        return $this->revalidateLockedThread($thread, $principal, $ability);
    }

    private function lockThreadById(
        PatientPrincipal $principal,
        int $threadId,
        string $ability,
    ): PatientMessageThread {
        $thread = PatientMessageThread::query()
            ->whereKey($threadId)
            ->lockForUpdate()
            ->first();

        return $this->revalidateLockedThread($thread, $principal, $ability);
    }

    private function revalidateLockedThread(
        ?PatientMessageThread $thread,
        PatientPrincipal $principal,
        string $ability,
    ): PatientMessageThread {
        if ($thread === null) {
            throw PatientMessagingFailure::notFound();
        }

        $lockedGrant = PatientEncounterAccessGrant::query()
            ->lockForUpdate()
            ->find($thread->access_grant_id);
        $thread->setRelation('accessGrant', $lockedGrant);

        if (! ($thread->accessGrant instanceof PatientEncounterAccessGrant)
            || ! $principal->can($ability, $thread)
        ) {
            throw PatientMessagingFailure::notFound();
        }

        return $thread;
    }

    private function recordMutationAccessDenial(
        Request $request,
        PatientPrincipal $principal,
        string $threadUuid,
        string $action,
    ): void {
        $this->audit->bestEffort(
            $this->perRequestAuditRequest($request),
            'patient.messaging.access_denied',
            'messaging',
            $action,
            'denied',
            $principal,
            reasonCode: 'resource_unavailable',
            resourceType: 'patient_message_thread',
            resourceUuid: $threadUuid,
        );
    }

    private function recordEncounterAccessDenial(
        Request $request,
        PatientPrincipal $principal,
        string $encounterUuid,
    ): void {
        $this->audit->bestEffort(
            $this->perRequestAuditRequest($request),
            'patient.messaging.access_denied',
            'messaging',
            'resolve_encounter',
            'denied',
            $principal,
            reasonCode: 'resource_unavailable',
            resourceType: 'patient_encounter',
            resourceUuid: $encounterUuid,
        );
    }

    private function recordThreadAccessDenial(
        Request $request,
        PatientPrincipal $principal,
        string $threadUuid,
        string $action,
    ): void {
        $this->audit->bestEffort(
            $this->perRequestAuditRequest($request),
            'patient.messaging.access_denied',
            'messaging',
            $action,
            'denied',
            $principal,
            reasonCode: 'resource_unavailable',
            resourceType: 'patient_message_thread',
            resourceUuid: $threadUuid,
        );
    }

    /**
     * Access/disclosure audit is one fact per HTTP request, including replay
     * requests. Operation-level idempotency remains on the mutation audit, but
     * must not collapse later access facts through the audit table's global
     * unique idempotency digest.
     */
    private function perRequestAuditRequest(Request $request): Request
    {
        $auditRequest = clone $request;
        $auditRequest->headers = clone $request->headers;
        $auditRequest->headers->remove('Idempotency-Key');

        return $auditRequest;
    }

    private function createPatientMessage(
        PatientMessageThread $thread,
        PatientPrincipal $principal,
        string $body,
        string $bodyDigest,
        string $clientMessageUuid,
        string $operationDigest,
        string $payloadDigest,
        string $encryptionKeyVersion,
        mixed $occurredAt,
        string $messageKind = 'message',
        ?int $relatesToMessageId = null,
    ): PatientMessage {
        $messageUuid = (string) Str::uuid7();

        return PatientMessage::query()->create([
            'message_uuid' => $messageUuid,
            'message_thread_id' => $thread->getKey(),
            'sender_type' => (string) $principal->principal_type,
            'sender_principal_id' => $principal->getKey(),
            'visibility' => 'patient_visible',
            'message_kind' => $messageKind,
            'relates_to_message_id' => $relatesToMessageId,
            'encrypted_body' => $this->cipher->encrypt(
                $body,
                $encryptionKeyVersion,
                $this->cipher->contextFor((string) $thread->thread_uuid, $messageUuid),
            ),
            'encryption_key_version' => $encryptionKeyVersion,
            'body_digest' => $bodyDigest,
            'body_character_count' => mb_strlen($body),
            'client_message_uuid' => $clientMessageUuid,
            'idempotency_key_digest' => $operationDigest,
            'request_payload_digest' => $payloadDigest,
            'delivery_state' => 'accepted',
            'sent_at' => $occurredAt,
        ]);
    }

    private function createPatientRetraction(
        PatientMessageThread $thread,
        PatientPrincipal $principal,
        string $clientMessageUuid,
        string $operationDigest,
        string $payloadDigest,
        int $relatesToMessageId,
        mixed $occurredAt,
    ): PatientMessage {
        return PatientMessage::query()->create([
            'message_uuid' => (string) Str::uuid7(),
            'message_thread_id' => $thread->getKey(),
            'sender_type' => (string) $principal->principal_type,
            'sender_principal_id' => $principal->getKey(),
            'visibility' => 'patient_visible',
            'message_kind' => 'retraction',
            'relates_to_message_id' => $relatesToMessageId,
            'body_character_count' => 0,
            'client_message_uuid' => $clientMessageUuid,
            'idempotency_key_digest' => $operationDigest,
            'request_payload_digest' => $payloadDigest,
            'delivery_state' => 'accepted',
            'sent_at' => $occurredAt,
        ]);
    }

    private function createReceipt(
        PatientMessage $message,
        PatientPrincipal $principal,
        string $receiptType,
        string $patientVisibleState,
        string $operationDigest,
        mixed $occurredAt,
    ): PatientMessageDeliveryReceipt {
        return PatientMessageDeliveryReceipt::query()->create([
            'receipt_uuid' => (string) Str::uuid7(),
            'message_id' => $message->getKey(),
            'receipt_type' => $receiptType,
            'actor_type' => 'system',
            'actor_ref_digest' => $this->derivedDigest('messaging-actor-ref', 'patient-api'),
            'patient_visible_state' => $patientVisibleState,
            'idempotency_key_digest' => $this->derivedDigest('messaging-receipt', $operationDigest),
            'occurred_at' => $occurredAt,
        ]);
    }

    private function createRoutingEvent(
        PatientMessageThread $thread,
        PatientPrincipal $principal,
        string $eventType,
        string $reasonCode,
        string $patientVisibleState,
        string $operationDigest,
        string $payloadDigest,
        string $policyVersion,
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
            'actor_type' => (string) $principal->principal_type,
            'actor_ref_digest' => $this->actorDigest($principal),
            'reason_code' => $reasonCode,
            'patient_visible_state' => $patientVisibleState,
            'routing_policy_version' => $policyVersion,
            'idempotency_key_digest' => $this->derivedDigest('messaging-routing', $operationDigest),
            'request_payload_digest' => $payloadDigest,
            'metadata' => ['schema_version' => 1, 'content_included' => false],
            'occurred_at' => $occurredAt,
        ]);
    }

    private function createStaffInboxOutbox(
        PatientMessageThread $thread,
        PatientPrincipal $principal,
        PatientEncounterAccessGrant $grant,
        string $eventType,
        string $operationDigest,
        mixed $occurredAt,
    ): PatientNotificationOutbox {
        return PatientNotificationOutbox::query()->create([
            'outbox_uuid' => (string) Str::uuid7(),
            'principal_id' => $principal->getKey(),
            'access_grant_id' => $grant->getKey(),
            'aggregate_type' => 'patient_message_thread',
            'aggregate_uuid' => (string) $thread->thread_uuid,
            'event_type' => $eventType,
            'destination' => 'staff_inbox',
            'encrypted_payload' => null,
            'encryption_key_version' => null,
            'payload_digest' => null,
            'routing_metadata' => [
                'schema_version' => 1,
                'content_included' => false,
                'routing_policy_version' => (string) $thread->routing_policy_version,
                'responsibility_pool_ref_digest' => (string) $thread->responsibility_pool_ref_digest,
            ],
            'idempotency_key_digest' => $this->derivedDigest('messaging-outbox', $operationDigest),
            'available_at' => $occurredAt,
            'occurred_at' => $occurredAt,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $policy
     */
    private function assertGuidanceVersion(array $input, array $policy): void
    {
        if (! hash_equals(
            $policy['urgent_guidance_version'],
            (string) ($input['urgent_guidance_version'] ?? ''),
        )) {
            throw PatientMessagingFailure::guidanceChanged();
        }
    }

    private function assertThreadReplay(
        PatientMessageThread $thread,
        PatientPrincipal $principal,
        string $payloadDigest,
        int $accessGrantId,
    ): void {
        if ((int) $thread->opened_by_principal_id !== (int) $principal->getKey()
            || (int) $thread->access_grant_id !== $accessGrantId
            || ! hash_equals((string) $thread->creation_request_payload_digest, $payloadDigest)
        ) {
            throw PatientMessagingFailure::idempotencyConflict();
        }
    }

    private function assertMessageReplay(
        PatientMessage $message,
        PatientPrincipal $principal,
        string $threadUuid,
        string $payloadDigest,
    ): void {
        if ((int) $message->sender_principal_id !== (int) $principal->getKey()
            || (int) $message->message_thread_id !== (int) $message->thread->getKey()
            || (string) $message->thread->thread_uuid !== $threadUuid
            || ! hash_equals((string) $message->request_payload_digest, $payloadDigest)
            || ! $principal->can('view', $message->thread)
        ) {
            throw PatientMessagingFailure::idempotencyConflict();
        }
    }

    private function assertAmendReplay(
        PatientMessage $message,
        PatientPrincipal $principal,
        string $threadUuid,
        string $sourceMessageUuid,
        string $action,
        string $payloadDigest,
    ): void {
        if ((int) $message->sender_principal_id !== (int) $principal->getKey()
            || (int) $message->message_thread_id !== (int) $message->thread->getKey()
            || (string) $message->thread->thread_uuid !== $threadUuid
            || (string) $message->message_kind !== $action
            || (string) ($message->relatesTo?->message_uuid ?? '') !== $sourceMessageUuid
            || ! hash_equals((string) $message->request_payload_digest, $payloadDigest)
            || ! $principal->can('view', $message->thread)
        ) {
            throw PatientMessagingFailure::idempotencyConflict();
        }
    }

    private function isMessageAmendmentUniquenessViolation(QueryException $exception): bool
    {
        return (string) $exception->getCode() === '23505'
            && str_contains(
                $exception->getMessage(),
                'uq_patient_messages_one_amendment_per_source',
            );
    }

    /** @param  array<string, mixed>  $policy */
    private function immediateHelp(array $policy): array
    {
        return [
            'version' => $policy['urgent_guidance_version'],
            'text' => $policy['urgent_guidance_text'],
        ];
    }

    /** @return array<string, mixed> */
    private function serializeThread(
        PatientMessageThread $thread,
        bool $includeMessages = false,
        bool $historyTruncated = false,
    ): array {
        $serialized = [
            'thread_uuid' => (string) $thread->thread_uuid,
            'topic' => [
                'code' => (string) $thread->topic_code,
                'label' => (string) $thread->topic_label,
                'description' => (string) $thread->topic_description,
            ],
            'status' => (string) $thread->status,
            'ownership_state' => (string) $thread->ownership_state,
            'expected_response_window' => (string) $thread->expected_response_window,
            'version' => (int) $thread->version,
            'last_message_at' => $thread->last_message_at?->toISOString(),
            'created_at' => $thread->created_at?->toISOString(),
            'closed_at' => $thread->closed_at?->toISOString(),
            'close_reason' => $thread->close_reason_code,
        ];

        if ($includeMessages) {
            /** @var Collection<int, PatientMessage> $messages */
            $messages = $thread->messages;
            $serialized['messages'] = $messages
                ->map(fn (PatientMessage $message): array => $this->serializeMessage(
                    $message,
                    (string) $thread->thread_uuid,
                ))
                ->values()
                ->all();
            $serialized['history_truncated'] = $historyTruncated;
        }

        return $serialized;
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
            'sender_display_role' => in_array($message->sender_type, ['patient', 'representative'], true)
                ? 'You'
                : 'Care team',
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
            'relates_to_message_uuid' => $message->relatesTo?->message_uuid !== null
                ? (string) $message->relatesTo->message_uuid
                : null,
            'delivery_state' => $latestReceipt?->patient_visible_state ?? 'sent',
            'sent_at' => $message->sent_at?->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array{thread: array<string, mixed>, replayed: bool, status: int, policy_version: string}
     */
    private function threadResult(
        PatientMessageThread $thread,
        bool $replayed,
        int $status,
        array $policy,
    ): array {
        return [
            'thread' => $this->serializeThread($thread),
            'replayed' => $replayed,
            'status' => $status,
            'policy_version' => $policy['policy_version'],
        ];
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array{thread: array<string, mixed>, message: array<string, mixed>, replayed: bool, status: int, policy_version: string}
     */
    private function messageResult(
        PatientMessageThread $thread,
        PatientMessage $message,
        bool $replayed,
        int $status,
        array $policy,
    ): array {
        if (! $message->relationLoaded('receipts')) {
            $message->load(['receipts', 'relatesTo']);
        }

        return [
            'thread' => $this->serializeThread($thread),
            'message' => $this->serializeMessage($message, (string) $thread->thread_uuid),
            'replayed' => $replayed,
            'status' => $status,
            'policy_version' => $policy['policy_version'],
        ];
    }

    private function operationDigest(
        PatientPrincipal $principal,
        string $operation,
        string $idempotencyKey,
    ): string {
        return $this->hmac->digest(
            'messaging-idempotency',
            (string) $principal->principal_uuid.'|'.$operation.'|'.$idempotencyKey,
        );
    }

    /**
     * Serialize concurrent retries before checking the durable idempotency
     * records. A transaction-scoped PostgreSQL advisory lock avoids the race
     * where two identical requests both observe no row and one later fails a
     * unique constraint instead of returning the first committed result.
     *
     * @param  list<string>  $keys
     */
    private function acquireReplayLocks(array $keys): void
    {
        sort($keys, SORT_STRING);

        foreach (array_unique($keys) as $key) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
        }
    }

    private function actorDigest(PatientPrincipal $principal): string
    {
        return $this->hmac->digest('messaging-actor-ref', (string) $principal->principal_uuid);
    }

    private function derivedDigest(string $purpose, string $operationDigest): string
    {
        return $this->hmac->digest($purpose, $operationDigest);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    private function payloadDigest(array $payload): string
    {
        return $this->hmac->digest(
            'messaging-payload',
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

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
