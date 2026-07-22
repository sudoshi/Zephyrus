<?php

namespace App\Services\Rounds;

use App\Authorization\Capability;
use App\Exceptions\Rounds\RoundConflictException;
use App\Exceptions\Rounds\RoundPolicyException;
use App\Models\Encounter;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientMessage;
use App\Models\Patient\PatientMessageThread;
use App\Models\PatientCommunication\PoolMembership;
use App\Models\PatientCommunication\ResponsibilityPool;
use App\Models\PatientCommunication\RoundQuestionPromotion;
use App\Models\PatientCommunication\RoundQuestionPromotionOutcome;
use App\Models\PatientCommunication\ThreadWorkItem;
use App\Models\Rounds\RoundEvent;
use App\Models\Rounds\RoundPatient;
use App\Models\Rounds\RoundQuestion;
use App\Models\User;
use App\Services\Audit\UserAuditRecorder;
use App\Services\Authorization\RoleCapabilityService;
use App\Services\Patient\Messaging\PatientMessageCipher;
use App\Services\Patient\Messaging\PatientMessagingFailure;
use App\Services\Patient\Messaging\PatientMessagingPolicyRegistry;
use App\Services\Patient\PatientHmac;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Converts a patient-authored message only after a staff user explicitly
 * selects the matching Virtual Rounds patient. The promotion fact owns no
 * content: the source body and a patient-safe status remain in the dedicated
 * patient message ledger.
 */
class PatientRoundQuestionPromotionService
{
    private const PATIENT_STATUS_MESSAGE = 'Your question was shared with your care team for possible review. It may not be discussed in a particular round.';

    private const PATIENT_OUTCOME_STATUS_MESSAGE = 'Your care team has completed their review of the question you shared. If you still need help, please send a message to your care team.';

    public function __construct(
        private readonly RoleCapabilityService $capabilities,
        private readonly RoundAuthorizationService $roundsAuthorization,
        private readonly PatientMessageCipher $cipher,
        private readonly PatientMessagingPolicyRegistry $policies,
        private readonly PatientHmac $hmac,
        private readonly UserAuditRecorder $audit,
    ) {}

    /**
     * Return staff-visible patient questions that are eligible for an explicit
     * promotion into this particular Virtual Rounds patient. This is a
     * staff-only discovery projection: no patient API or patient contract is
     * expanded, and the returned text is decrypted only after the exact same
     * rounds, capability, encounter, pool, and policy checks as promotion.
     *
     * @return list<array{thread_uuid: string, thread_version: int, message_uuid: string, question_text: string, sent_at: string|null}>
     */
    public function available(User $actor, RoundPatient $patient): array
    {
        $this->assertEnabled();
        $this->roundsAuthorization->assertCanContribute($actor, $patient->run);

        try {
            return DB::transaction(function () use ($actor, $patient): array {
                $lockedActor = $this->lockedAuthorizedActor($actor, false);
                $roundPatient = $this->lockedRoundPatient($patient, false);
                if (! $roundPatient instanceof RoundPatient || $roundPatient->run === null || $roundPatient->run->isTerminal()) {
                    return [];
                }

                $grantIds = PatientEncounterAccessGrant::query()
                    ->where('source_encounter_id', $roundPatient->prod_encounter_id)
                    ->pluck('access_grant_id');
                if ($grantIds->isEmpty()) {
                    return [];
                }

                $threadsQuery = PatientMessageThread::query()
                    ->whereIn('access_grant_id', $grantIds)
                    ->where('topic_code', 'rounds_question')
                    ->where('status', 'open')
                    ->orderBy('last_message_at')
                    ->orderBy('message_thread_id');
                $this->applyLock($threadsQuery, false);

                /** @var Collection<int, PatientMessageThread> $threads */
                $threads = $threadsQuery->get();
                $available = [];

                foreach ($threads as $thread) {
                    $context = $this->promotionContext($lockedActor, $roundPatient, $thread, false);
                    if ($context === null
                        || RoundQuestionPromotion::query()
                            ->where('message_thread_id', $thread->getKey())
                            ->sharedLock()
                            ->exists()
                    ) {
                        continue;
                    }

                    $messagesQuery = PatientMessage::query()
                        ->where('message_thread_id', $thread->getKey())
                        ->where('sender_type', 'patient')
                        ->where('visibility', 'patient_visible')
                        ->whereIn('message_kind', ['message', 'correction'])
                        ->orderBy('sent_at')
                        ->orderBy('message_id');
                    $this->applyLock($messagesQuery, false);

                    /** @var Collection<int, PatientMessage> $messages */
                    $messages = $messagesQuery->get();
                    $supersededIds = PatientMessage::query()
                        ->where('message_thread_id', $thread->getKey())
                        ->whereIn('message_kind', ['correction', 'retraction'])
                        ->whereIn('relates_to_message_id', $messages->pluck('message_id'))
                        ->sharedLock()
                        ->pluck('relates_to_message_id')
                        ->all();

                    foreach ($messages as $message) {
                        if (in_array($message->getKey(), $supersededIds, true)
                            || ! is_string($message->encrypted_body)
                            || ! is_string($message->encryption_key_version)
                        ) {
                            continue;
                        }

                        try {
                            $questionText = $this->cipher->decrypt(
                                $message->encrypted_body,
                                $message->encryption_key_version,
                                $this->cipher->contextFor((string) $thread->thread_uuid, (string) $message->message_uuid),
                            );
                        } catch (RuntimeException) {
                            continue;
                        }

                        $available[] = [
                            'thread_uuid' => (string) $thread->thread_uuid,
                            'thread_version' => (int) $thread->version,
                            'message_uuid' => (string) $message->message_uuid,
                            'question_text' => $questionText,
                            'sent_at' => $message->sent_at?->toISOString(),
                        ];
                    }
                }

                return $available;
            });
        } catch (AuthorizationException) {
            throw new AuthorizationException('You are not authorized to view patient questions for this round.');
        }
    }

    /**
     * @param  array{message_uuid: string, thread_version: int, idempotency_key: string}  $input
     * @return array{promotion: RoundQuestionPromotion, replayed: bool}
     */
    public function promote(
        Request $request,
        User $actor,
        RoundPatient $patient,
        string $threadUuid,
        array $input,
    ): array {
        $this->assertEnabled();
        $this->roundsAuthorization->assertCanContribute($actor, $patient->run);

        try {
            return DB::transaction(function () use ($request, $actor, $patient, $threadUuid, $input): array {
                $lockedActor = $this->lockedAuthorizedActor($actor, true);

                $thread = PatientMessageThread::query()
                    ->where('thread_uuid', $threadUuid)
                    ->lockForUpdate()
                    ->first();
                if (! $thread instanceof PatientMessageThread) {
                    abort(404);
                }

                $context = $this->promotionContext($lockedActor, $patient, $thread, true);
                if ($context === null) {
                    abort(404);
                }
                $workItem = $context['work_item'];
                $grant = $context['grant'];
                $policy = $context['policy'];

                $message = PatientMessage::query()
                    ->where('message_thread_id', $thread->getKey())
                    ->where('message_uuid', $input['message_uuid'])
                    ->where('sender_type', 'patient')
                    ->where('visibility', 'patient_visible')
                    ->whereIn('message_kind', ['message', 'correction'])
                    ->lockForUpdate()
                    ->first();
                if (! $message instanceof PatientMessage
                    || ! is_string($message->encrypted_body)
                    || ! is_string($message->encryption_key_version)
                ) {
                    abort(404);
                }
                if (PatientMessage::query()
                    ->where('message_thread_id', $thread->getKey())
                    ->whereIn('message_kind', ['correction', 'retraction'])
                    ->where('relates_to_message_id', $message->getKey())
                    ->lockForUpdate()
                    ->exists()
                ) {
                    throw new RoundPolicyException('The selected patient question is no longer available for promotion.');
                }

                $operationDigest = $this->hmac->digest(
                    'rounds.patient-question-promotion.idempotency',
                    (string) $lockedActor->getKey().'|'.$input['idempotency_key'],
                );
                $payloadDigest = $this->hmac->digest(
                    'rounds.patient-question-promotion.payload',
                    implode('|', [
                        (string) $thread->thread_uuid,
                        (string) $message->message_uuid,
                        (string) $patient->round_patient_uuid,
                        (string) $input['thread_version'],
                    ]),
                );

                $replayed = RoundQuestionPromotion::query()
                    ->where('idempotency_key_digest', $operationDigest)
                    ->lockForUpdate()
                    ->first();
                if ($replayed instanceof RoundQuestionPromotion) {
                    if (! hash_equals((string) $replayed->request_payload_digest, $payloadDigest)) {
                        throw new RoundConflictException('This idempotency key was already used for a different promotion.');
                    }

                    return ['promotion' => $replayed, 'replayed' => true];
                }

                if ((int) $thread->version !== (int) $input['thread_version']) {
                    throw new RoundConflictException('The patient question changed before it could be promoted.');
                }

                $existing = RoundQuestionPromotion::query()
                    ->where('message_thread_id', $thread->getKey())
                    ->lockForUpdate()
                    ->first();
                if ($existing instanceof RoundQuestionPromotion) {
                    if ((int) $existing->round_patient_id !== (int) $patient->getKey()
                        || (int) $existing->source_message_id !== (int) $message->getKey()
                    ) {
                        throw new RoundConflictException('This patient question has already been promoted.');
                    }

                    return ['promotion' => $existing, 'replayed' => true];
                }

                try {
                    $questionText = $this->cipher->decrypt(
                        $message->encrypted_body,
                        $message->encryption_key_version,
                        $this->cipher->contextFor((string) $thread->thread_uuid, (string) $message->message_uuid),
                    );
                } catch (RuntimeException) {
                    throw new RoundPolicyException('The patient question cannot be safely promoted right now.');
                }

                $roundPatient = RoundPatient::query()->lockForUpdate()->find($patient->getKey());
                if (! $roundPatient instanceof RoundPatient
                    || (int) $roundPatient->prod_encounter_id !== (int) $grant->source_encounter_id
                    || $roundPatient->run === null
                    || $roundPatient->run->isTerminal()
                ) {
                    throw new RoundPolicyException('The selected round is not available for this patient question.');
                }

                $role = $this->roundsAuthorization->contributorRoleFor($lockedActor, $roundPatient->run);
                $roundQuestion = RoundQuestion::create([
                    'question_uuid' => (string) Str::uuid7(),
                    'round_patient_id' => $roundPatient->getKey(),
                    'raised_by' => $lockedActor->getKey(),
                    'raised_role' => $role,
                    'question_text' => $questionText,
                    'status' => 'open',
                    'provenance' => [
                        'source' => 'patient_question_bridge',
                        'promotion_ref' => $this->hmac->digest(
                            'rounds.patient-question-promotion.ref',
                            (string) $thread->thread_uuid,
                        ),
                    ],
                ]);

                $occurredAt = now();
                $statusMessageUuid = (string) Str::uuid7();
                $patientStatusMessage = PatientMessage::query()->create([
                    'message_uuid' => $statusMessageUuid,
                    'message_thread_id' => $thread->getKey(),
                    'sender_type' => 'system',
                    'sender_actor_ref_digest' => $this->hmac->digest(
                        'messaging-actor-ref',
                        'rounds-patient-question-promotion|'.(string) $roundQuestion->question_uuid,
                    ),
                    'visibility' => 'patient_visible',
                    'message_kind' => 'system_status',
                    'encrypted_body' => $this->cipher->encrypt(
                        self::PATIENT_STATUS_MESSAGE,
                        (string) $policy['encryption_key_version'],
                        $this->cipher->contextFor((string) $thread->thread_uuid, $statusMessageUuid),
                    ),
                    'encryption_key_version' => (string) $policy['encryption_key_version'],
                    'body_digest' => $this->hmac->digest('messaging-body', self::PATIENT_STATUS_MESSAGE),
                    'body_character_count' => mb_strlen(self::PATIENT_STATUS_MESSAGE),
                    'delivery_state' => 'accepted',
                    'sent_at' => $occurredAt,
                ]);

                $thread->forceFill([
                    'version' => (int) $thread->version + 1,
                    'last_message_at' => $occurredAt,
                ])->save();
                $workItem->forceFill([
                    'source_thread_version' => (int) $thread->version,
                    'row_version' => (int) $workItem->row_version + 1,
                    'last_message_at' => $occurredAt,
                ])->save();

                $promotion = RoundQuestionPromotion::create([
                    'promotion_uuid' => (string) Str::uuid7(),
                    'message_thread_id' => $thread->getKey(),
                    'source_message_id' => $message->getKey(),
                    'patient_status_message_id' => $patientStatusMessage->getKey(),
                    'round_patient_id' => $roundPatient->getKey(),
                    'round_question_id' => $roundQuestion->getKey(),
                    'promoted_by_user_id' => $lockedActor->getKey(),
                    'promotion_policy_version' => (string) $policy['policy_version'],
                    'idempotency_key_digest' => $operationDigest,
                    'request_payload_digest' => $payloadDigest,
                    'promoted_at' => $occurredAt,
                ]);

                RoundEvent::record(
                    'question',
                    (int) $roundQuestion->getKey(),
                    (string) $roundQuestion->question_uuid,
                    1,
                    (int) $lockedActor->getKey(),
                    'question.promoted_from_patient_message',
                    ['promotion_uuid' => (string) $promotion->promotion_uuid],
                    $input['idempotency_key'],
                );

                $this->audit->record('rounds.patient_question_promoted', 'activity', 'success', [
                    'request' => $request,
                    'actor' => $lockedActor,
                    'target_type' => 'patient_message_thread',
                    'target_id' => (string) $thread->thread_uuid,
                    'metadata' => ['event_type' => 'patient_question_promoted'],
                ]);

                return ['promotion' => $promotion, 'replayed' => false];
            });
        } catch (AuthorizationException) {
            $this->audit->record('rounds.patient_question_promotion_denied', 'authorization', 'denied', [
                'request' => $request,
                'actor' => $actor,
                'target_type' => 'patient_message_thread',
                'target_id' => $threadUuid,
            ]);

            throw new AuthorizationException('You are not authorized to promote a patient question.');
        }
    }

    /**
     * Atomically append one deliberately generic patient-facing outcome only
     * for a previously promoted question. This is called from the staff
     * resolution transaction; it never opens the staff rounds API to the
     * patient realm and never copies a staff response or resolution label into
     * the patient message ledger.
     *
     * A disabled bridge, revoked relationship, ended encounter, or policy
     * drift suppresses the patient disclosure while preserving the ordinary
     * staff resolution. Once an enabled bridge can safely publish, the unique
     * outcome fact makes repeated resolution requests exact no-ops for the
     * patient history.
     */
    public function recordResolutionOutcome(
        Request $request,
        User $actor,
        RoundQuestion $roundQuestion,
        string $resolutionStatus,
    ): ?RoundQuestionPromotionOutcome {
        if (! $this->isEnabled() || ! in_array($resolutionStatus, ['answered', 'dismissed'], true)) {
            return null;
        }

        try {
            $policy = $this->policies->contentWritePolicy();
        } catch (PatientMessagingFailure) {
            return null;
        }

        $promotion = RoundQuestionPromotion::query()
            ->where('round_question_id', $roundQuestion->getKey())
            ->lockForUpdate()
            ->first();
        if (! $promotion instanceof RoundQuestionPromotion) {
            return null;
        }

        $existing = RoundQuestionPromotionOutcome::query()
            ->where('round_question_promotion_id', $promotion->getKey())
            ->lockForUpdate()
            ->first();
        if ($existing instanceof RoundQuestionPromotionOutcome) {
            return $existing;
        }

        $thread = PatientMessageThread::query()
            ->whereKey($promotion->message_thread_id)
            ->lockForUpdate()
            ->first();
        $grant = $thread instanceof PatientMessageThread
            ? PatientEncounterAccessGrant::query()->whereKey($thread->access_grant_id)->lockForUpdate()->first()
            : null;
        $encounter = $grant instanceof PatientEncounterAccessGrant
            ? Encounter::query()->whereKey($grant->source_encounter_id)->lockForUpdate()->first()
            : null;
        $workItem = $thread instanceof PatientMessageThread
            ? ThreadWorkItem::query()->where('message_thread_id', $thread->getKey())->lockForUpdate()->first()
            : null;

        if (! $thread instanceof PatientMessageThread
            || ! $grant instanceof PatientEncounterAccessGrant
            || ! $encounter instanceof Encounter
            || ! $workItem instanceof ThreadWorkItem
            || (int) $workItem->access_grant_id !== (int) $grant->getKey()
            || $thread->topic_code !== 'rounds_question'
            || $grant->status !== 'active'
            || $grant->valid_from === null
            || $grant->valid_from->isFuture()
            || ($grant->expires_at !== null && ! $grant->expires_at->isFuture())
            || ! $grant->permits('messaging:read')
            || $encounter->status !== 'active'
            || $encounter->discharged_at !== null
            || $encounter->is_deleted
            || ! hash_equals((string) $promotion->promotion_policy_version, (string) $policy['policy_version'])
            || ! hash_equals((string) $thread->routing_policy_version, (string) $policy['policy_version'])
        ) {
            return null;
        }
        if (PatientMessage::query()
            ->where('message_thread_id', $thread->getKey())
            ->where('message_kind', 'retraction')
            ->where('relates_to_message_id', $promotion->source_message_id)
            ->sharedLock()
            ->exists()
        ) {
            return null;
        }

        $occurredAt = now();
        $statusMessageUuid = (string) Str::uuid7();
        $patientStatusMessage = PatientMessage::query()->create([
            'message_uuid' => $statusMessageUuid,
            'message_thread_id' => $thread->getKey(),
            'sender_type' => 'system',
            'sender_actor_ref_digest' => $this->hmac->digest(
                'messaging-actor-ref',
                'rounds-patient-question-outcome|'.(string) $promotion->promotion_uuid,
            ),
            'visibility' => 'patient_visible',
            'message_kind' => 'system_status',
            'encrypted_body' => $this->cipher->encrypt(
                self::PATIENT_OUTCOME_STATUS_MESSAGE,
                (string) $policy['encryption_key_version'],
                $this->cipher->contextFor((string) $thread->thread_uuid, $statusMessageUuid),
            ),
            'encryption_key_version' => (string) $policy['encryption_key_version'],
            'body_digest' => $this->hmac->digest('messaging-body', self::PATIENT_OUTCOME_STATUS_MESSAGE),
            'body_character_count' => mb_strlen(self::PATIENT_OUTCOME_STATUS_MESSAGE),
            'delivery_state' => 'accepted',
            'sent_at' => $occurredAt,
        ]);

        $thread->forceFill([
            'version' => (int) $thread->version + 1,
            'last_message_at' => $occurredAt,
        ])->save();
        $workItem->forceFill([
            'source_thread_version' => (int) $thread->version,
            'row_version' => (int) $workItem->row_version + 1,
            'last_message_at' => $occurredAt,
        ])->save();

        $outcome = RoundQuestionPromotionOutcome::query()->create([
            'outcome_uuid' => (string) Str::uuid7(),
            'round_question_promotion_id' => $promotion->getKey(),
            'patient_status_message_id' => $patientStatusMessage->getKey(),
            'resolved_by_user_id' => $actor->getKey(),
            'resolved_status' => $resolutionStatus,
            'outcome_policy_version' => (string) $policy['policy_version'],
            'resolved_at' => $occurredAt,
        ]);

        RoundEvent::record(
            'question',
            (int) $roundQuestion->getKey(),
            (string) $roundQuestion->question_uuid,
            1,
            (int) $actor->getKey(),
            'question.patient_outcome_published',
            ['event_type' => 'patient_question_outcome_published'],
        );
        $this->audit->record('rounds.patient_question_outcome_published', 'activity', 'success', [
            'request' => $request,
            'actor' => $actor,
            'target_type' => 'patient_message_thread',
            'target_id' => (string) $thread->thread_uuid,
            'metadata' => ['event_type' => 'patient_question_outcome_published'],
        ]);

        return $outcome;
    }

    private function lockedAuthorizedActor(User $actor, bool $forUpdate): User
    {
        $query = User::query()->whereKey($actor->getKey());
        $this->applyLock($query, $forUpdate);
        $lockedActor = $query->first();

        if (! $lockedActor instanceof User
            || ! $lockedActor->is_active
            || ! $this->capabilities->allows($lockedActor, Capability::RespondPatientCommunications)
        ) {
            throw new AuthorizationException('You are not authorized to promote a patient question.');
        }

        return $lockedActor;
    }

    private function lockedRoundPatient(RoundPatient $patient, bool $forUpdate): ?RoundPatient
    {
        $query = RoundPatient::query()->whereKey($patient->getKey());
        $this->applyLock($query, $forUpdate);

        return $query->first();
    }

    /**
     * Validate the staff-message ownership chain for a thread. Returning null
     * keeps stale, revoked, rerouted, or out-of-scope patient content absent
     * from the staff discovery projection and indistinguishable from an
     * unavailable source to the promotion route.
     *
     * @return array{work_item: ThreadWorkItem, grant: PatientEncounterAccessGrant, policy: array<string, mixed>}|null
     */
    private function promotionContext(
        User $actor,
        RoundPatient $patient,
        PatientMessageThread $thread,
        bool $forUpdate,
    ): ?array {
        $workItemQuery = ThreadWorkItem::query()->where('message_thread_id', $thread->getKey());
        $this->applyLock($workItemQuery, $forUpdate);
        $workItem = $workItemQuery->first();

        $grantQuery = PatientEncounterAccessGrant::query()->whereKey($thread->access_grant_id);
        $this->applyLock($grantQuery, $forUpdate);
        $grant = $grantQuery->first();

        if (! $workItem instanceof ThreadWorkItem
            || ! $grant instanceof PatientEncounterAccessGrant
            || (int) $workItem->access_grant_id !== (int) $grant->getKey()
            || $grant->status !== 'active'
            || $grant->valid_from === null
            || $grant->valid_from->isFuture()
            || ($grant->expires_at !== null && ! $grant->expires_at->isFuture())
            || ! $grant->permits('messaging:read')
            || ! $grant->permits('messaging:write')
            || $thread->topic_code !== 'rounds_question'
            || $thread->status !== 'open'
        ) {
            return null;
        }

        $encounterQuery = Encounter::query()
            ->whereKey((int) $grant->source_encounter_id)
            ->where('status', 'active')
            ->whereNull('discharged_at')
            ->where('is_deleted', false);
        $this->applyLock($encounterQuery, $forUpdate);
        $encounter = $encounterQuery->first();

        $poolQuery = ResponsibilityPool::query()->whereKey($workItem->responsibility_pool_id);
        $this->applyLock($poolQuery, $forUpdate);
        $pool = $poolQuery->first();

        if (! $encounter instanceof Encounter
            || ! $pool instanceof ResponsibilityPool
            || $pool->status !== 'active'
            || $pool->scope_type !== 'unit'
            || $encounter->unit_id === null
            || (int) $pool->unit_id !== (int) $encounter->unit_id
            || (int) $encounter->getKey() !== (int) $patient->prod_encounter_id
        ) {
            return null;
        }

        try {
            $policy = $this->policies->contentWritePolicy();
        } catch (PatientMessagingFailure) {
            return null;
        }

        if (! hash_equals((string) $policy['policy_version'], (string) $thread->routing_policy_version)
            || ! isset($policy['topics']['rounds_question'])
            || $pool->topic_code !== 'rounds_question'
            || ! hash_equals((string) $pool->routing_policy_version, (string) $thread->routing_policy_version)
            || ! hash_equals((string) $pool->pool_key_digest, (string) $thread->responsibility_pool_ref_digest)
        ) {
            return null;
        }

        $membershipQuery = PoolMembership::query()
            ->effective()
            ->where('responsibility_pool_id', $workItem->responsibility_pool_id)
            ->where('staff_user_id', $actor->getKey());
        $this->applyLock($membershipQuery, $forUpdate);
        $membership = $membershipQuery->first();

        if (! $membership instanceof PoolMembership
            || ! ($membership->can_claim || $membership->can_reply || $membership->membership_role === 'supervisor')
        ) {
            return null;
        }

        return [
            'work_item' => $workItem,
            'grant' => $grant,
            'policy' => $policy,
        ];
    }

    /** @param Builder<\Illuminate\Database\Eloquent\Model> $query */
    private function applyLock(Builder $query, bool $forUpdate): void
    {
        if ($forUpdate) {
            $query->lockForUpdate();

            return;
        }

        $query->sharedLock();
    }

    private function assertEnabled(): void
    {
        abort_unless($this->isEnabled(), 404);
    }

    private function isEnabled(): bool
    {
        return (bool) config('rounds.patient_question_bridge_enabled')
            && (bool) config('hummingbird-patient.enabled')
            && (bool) config('hummingbird-patient.features.messaging')
            && (bool) config('hummingbird-patient.features.rounds_questions')
            && (bool) config('hummingbird-patient.staff_messaging.enabled')
            && config('hummingbird-patient.staff_messaging.governance_status') === 'approved';
    }
}
