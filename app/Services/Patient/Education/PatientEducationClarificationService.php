<?php

namespace App\Services\Patient\Education;

use App\Models\Patient\PatientEducationClarificationRequest;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientEncounterProjection;
use App\Models\Patient\PatientMessage;
use App\Models\Patient\PatientPrincipal;
use App\Models\Patient\PatientReleasePolicyVersion;
use App\Services\Patient\Messaging\PatientMessagingFailure;
use App\Services\Patient\Messaging\PatientMessagingService;
use App\Services\Patient\PatientAccessAuditRecorder;
use App\Services\Patient\PatientHmac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Accept a patient-authored request to clarify released education. This is not
 * a teach-back assessment: no value here represents understanding, completion,
 * consent, diagnosis, order, or a clinician's conclusion.
 */
class PatientEducationClarificationService
{
    private const TOPIC_CODE = 'education_clarification';

    public function __construct(
        private readonly PatientMessagingService $messaging,
        private readonly PatientAccessAuditRecorder $audit,
        private readonly PatientHmac $hmac,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{thread: array<string, mixed>, replayed: bool, status: int, policy_version: string}
     */
    public function requestClarification(
        Request $request,
        PatientPrincipal $principal,
        string $encounterUuid,
        string $educationItemUuid,
        array $input,
    ): array {
        if (! (bool) config('hummingbird-patient.features.messaging')) {
            throw PatientMessagingFailure::notFound();
        }

        try {
            return DB::transaction(function () use (
                $request,
                $principal,
                $encounterUuid,
                $educationItemUuid,
                $input,
            ): array {
                [$grant, $projection] = $this->releasedEducationContext(
                    $principal,
                    $encounterUuid,
                    $educationItemUuid,
                );
                $messageInput = array_merge($input, ['topic_code' => self::TOPIC_CODE]);
                $messageResult = $this->messaging->createThreadForReleasedEducation(
                    $request,
                    $principal,
                    $encounterUuid,
                    $messageInput,
                );
                $message = PatientMessage::query()
                    ->where('client_message_uuid', (string) $input['client_message_uuid'])
                    ->where('message_thread_id', function ($query) use ($messageResult): void {
                        $query
                            ->select('message_thread_id')
                            ->from('patient_experience.message_threads')
                            ->where('thread_uuid', (string) $messageResult['thread']['thread_uuid']);
                    })
                    ->lockForUpdate()
                    ->first();

                if (! $message instanceof PatientMessage) {
                    throw PatientMessagingFailure::unavailable();
                }

                $operationDigest = $this->hmac->digest(
                    'education-clarification.idempotency',
                    (string) $principal->principal_uuid.'|'.(string) $input['idempotency_key'],
                );
                $payloadDigest = $this->hmac->digest(
                    'education-clarification.payload',
                    implode('|', [
                        $encounterUuid,
                        $educationItemUuid,
                        (string) $projection->projection_uuid,
                        $this->hmac->digest('education-clarification.body', (string) $input['message']),
                        (string) $input['client_message_uuid'],
                        (string) $input['urgent_guidance_version'],
                    ]),
                );
                $existing = PatientEducationClarificationRequest::query()
                    ->where('source_message_id', $message->getKey())
                    ->orWhere('idempotency_key_digest', $operationDigest)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof PatientEducationClarificationRequest) {
                    if (! $this->isExactReplay(
                        $existing,
                        $principal,
                        $grant,
                        $projection,
                        $educationItemUuid,
                        $message,
                        $operationDigest,
                        $payloadDigest,
                    )) {
                        throw PatientMessagingFailure::idempotencyConflict();
                    }

                    $replayed = true;
                } else {
                    PatientEducationClarificationRequest::query()->create([
                        'clarification_uuid' => (string) \Illuminate\Support\Str::uuid7(),
                        'principal_id' => $principal->getKey(),
                        'access_grant_id' => $grant->getKey(),
                        'pathway_projection_id' => $projection->getKey(),
                        'education_item_uuid' => $educationItemUuid,
                        'message_thread_id' => $message->message_thread_id,
                        'source_message_id' => $message->getKey(),
                        'policy_version' => (string) $messageResult['policy_version'],
                        'idempotency_key_digest' => $operationDigest,
                        'request_payload_digest' => $payloadDigest,
                        'requested_at' => now(),
                    ]);
                    $replayed = false;
                }

                // This durable audit has only opaque resource handles and
                // booleans. The actual question never enters an audit record.
                $this->audit->record(
                    $request,
                    $replayed
                        ? 'patient.education.clarification_replayed'
                        : 'patient.education.clarification_requested',
                    'education',
                    $replayed ? 'replay_clarification' : 'request_clarification',
                    'succeeded',
                    $principal,
                    grant: $grant,
                    resourceType: 'patient_education_item',
                    resourceUuid: $educationItemUuid,
                    metadata: [
                        'message_replayed' => (bool) $messageResult['replayed'],
                        'pathway_projection_sequence' => (int) $projection->projection_sequence,
                    ],
                );

                return [
                    'thread' => $messageResult['thread'],
                    'replayed' => $replayed,
                    'status' => $replayed ? 200 : 201,
                    'policy_version' => $messageResult['policy_version'],
                ];
            }, 3);
        } catch (PatientMessagingFailure $failure) {
            if ($failure->errorCode === 'not_found') {
                $this->audit->bestEffort(
                    $request,
                    'patient.education.clarification_denied',
                    'education',
                    'request_clarification',
                    'denied',
                    $principal,
                    reasonCode: 'released_education_not_available',
                    resourceType: 'patient_education_item',
                );
            }

            throw $failure;
        }
    }

    /**
     * @return array{0: PatientEncounterAccessGrant, 1: PatientEncounterProjection}
     */
    private function releasedEducationContext(
        PatientPrincipal $principal,
        string $encounterUuid,
        string $educationItemUuid,
    ): array {
        $grant = PatientEncounterAccessGrant::query()
            ->where('principal_id', $principal->getKey())
            ->where('encounter_uuid', $encounterUuid)
            ->lockForUpdate()
            ->first();

        if (! $grant instanceof PatientEncounterAccessGrant
            || ! Gate::forUser($principal)->allows('view', $grant)
            || ! $grant->permits('pathway:read')
            || ! $grant->permits('messaging:read')
            || ! $grant->permits('messaging:write')) {
            throw PatientMessagingFailure::notFound();
        }

        $policy = PatientReleasePolicyVersion::query()
            ->effective()
            ->where('version', (string) config('hummingbird-patient.policy_version'))
            ->first();

        if (! $policy instanceof PatientReleasePolicyVersion) {
            throw PatientMessagingFailure::notFound();
        }

        $projection = PatientEncounterProjection::query()
            ->where('access_grant_id', $grant->getKey())
            ->where('projection_kind', 'pathway')
            ->where('required_scope', 'pathway:read')
            ->where('release_policy_version_id', $policy->getKey())
            ->where('release_state', 'released')
            ->where('released_at', '<=', now())
            ->orderByDesc('released_at')
            ->orderByDesc('projection_sequence')
            ->lockForUpdate()
            ->first();

        if (! $projection instanceof PatientEncounterProjection
            || ! Gate::forUser($principal)->allows('view', $projection)
            || ! $this->includesEducationItem($projection, $educationItemUuid)) {
            throw PatientMessagingFailure::notFound();
        }

        return [$grant, $projection];
    }

    private function includesEducationItem(
        PatientEncounterProjection $projection,
        string $educationItemUuid,
    ): bool {
        foreach ((array) ($projection->content['education'] ?? []) as $item) {
            if (is_array($item)
                && is_string($item['item_uuid'] ?? null)
                && hash_equals(strtolower((string) $item['item_uuid']), strtolower($educationItemUuid))) {
                return true;
            }
        }

        return false;
    }

    private function isExactReplay(
        PatientEducationClarificationRequest $existing,
        PatientPrincipal $principal,
        PatientEncounterAccessGrant $grant,
        PatientEncounterProjection $projection,
        string $educationItemUuid,
        PatientMessage $message,
        string $operationDigest,
        string $payloadDigest,
    ): bool {
        return (int) $existing->principal_id === (int) $principal->getKey()
            && (int) $existing->access_grant_id === (int) $grant->getKey()
            && (int) $existing->pathway_projection_id === (int) $projection->getKey()
            && hash_equals((string) $existing->education_item_uuid, $educationItemUuid)
            && (int) $existing->source_message_id === (int) $message->getKey()
            && hash_equals((string) $existing->idempotency_key_digest, $operationDigest)
            && hash_equals((string) $existing->request_payload_digest, $payloadDigest);
    }
}
