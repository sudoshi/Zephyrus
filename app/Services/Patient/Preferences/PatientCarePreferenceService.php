<?php

namespace App\Services\Patient\Preferences;

use App\Models\Patient\PatientCarePreference;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientMessage;
use App\Models\Patient\PatientPrincipal;
use App\Services\Patient\Messaging\PatientMessagingFailure;
use App\Services\Patient\Messaging\PatientMessagingService;
use App\Services\Patient\PatientAccessAuditRecorder;
use App\Services\Patient\PatientHmac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Records a patient-authored care preference as an immutable, nonclinical
 * association to the encrypted message that carries its text. This service
 * deliberately has no pathway to create or amend a clinical care plan, order,
 * consent, clinician assessment, or source-system record.
 */
class PatientCarePreferenceService
{
    private const TOPIC_CODE = 'care_preference';

    public function __construct(
        private readonly PatientMessagingService $messaging,
        private readonly PatientAccessAuditRecorder $audit,
        private readonly PatientHmac $hmac,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{thread: array<string, mixed>, replayed: bool, status: int, policy_version: string}
     */
    public function submit(
        Request $request,
        PatientPrincipal $principal,
        string $encounterUuid,
        array $input,
    ): array {
        if (! (bool) config('hummingbird-patient.features.care_preferences')) {
            throw PatientMessagingFailure::notFound();
        }

        if (($input['topic_code'] ?? null) !== self::TOPIC_CODE) {
            throw PatientMessagingFailure::notFound();
        }

        try {
            return DB::transaction(function () use ($request, $principal, $encounterUuid, $input): array {
                $grant = $this->currentGrant($principal, $encounterUuid);
                $messageResult = $this->messaging->createThread(
                    $request,
                    $principal,
                    $encounterUuid,
                    $input,
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
                    'care-preference.idempotency',
                    (string) $principal->principal_uuid.'|'.(string) $input['idempotency_key'],
                );
                $payloadDigest = $this->hmac->digest(
                    'care-preference.payload',
                    implode('|', [
                        $encounterUuid,
                        $this->hmac->digest('care-preference.body', (string) $input['message']),
                        (string) $input['client_message_uuid'],
                        (string) $input['urgent_guidance_version'],
                    ]),
                );
                $existing = PatientCarePreference::query()
                    ->where('source_message_id', $message->getKey())
                    ->orWhere('idempotency_key_digest', $operationDigest)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof PatientCarePreference) {
                    if (! $this->isExactReplay(
                        $existing,
                        $principal,
                        $grant,
                        $message,
                        $operationDigest,
                        $payloadDigest,
                    )) {
                        throw PatientMessagingFailure::idempotencyConflict();
                    }

                    $replayed = true;
                    $preference = $existing;
                } else {
                    $preference = PatientCarePreference::query()->create([
                        'preference_uuid' => (string) Str::uuid7(),
                        'principal_id' => $principal->getKey(),
                        'access_grant_id' => $grant->getKey(),
                        'message_thread_id' => $message->message_thread_id,
                        'source_message_id' => $message->getKey(),
                        'policy_version' => (string) $messageResult['policy_version'],
                        'idempotency_key_digest' => $operationDigest,
                        'request_payload_digest' => $payloadDigest,
                        'submitted_at' => now(),
                    ]);
                    $replayed = false;
                }

                $this->audit->record(
                    $request,
                    $replayed
                        ? 'patient.care_preference.replayed'
                        : 'patient.care_preference.submitted',
                    'care_preference',
                    $replayed ? 'replay_preference' : 'submit_preference',
                    'succeeded',
                    $principal,
                    grant: $grant,
                    resourceType: 'patient_care_preference',
                    resourceUuid: (string) $preference->preference_uuid,
                    metadata: [
                        'message_replayed' => (bool) $messageResult['replayed'],
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
                    'patient.care_preference.denied',
                    'care_preference',
                    'submit_preference',
                    'denied',
                    $principal,
                    reasonCode: 'care_preference_not_available',
                    resourceType: 'patient_care_preference',
                );
            }

            throw $failure;
        }
    }

    private function currentGrant(
        PatientPrincipal $principal,
        string $encounterUuid,
    ): PatientEncounterAccessGrant {
        $grant = PatientEncounterAccessGrant::query()
            ->where('principal_id', $principal->getKey())
            ->where('encounter_uuid', $encounterUuid)
            ->lockForUpdate()
            ->first();

        if (! $grant instanceof PatientEncounterAccessGrant
            || ! Gate::forUser($principal)->allows('view', $grant)
            || ! $grant->permits('messaging:read')
            || ! $grant->permits('messaging:write')) {
            throw PatientMessagingFailure::notFound();
        }

        return $grant;
    }

    private function isExactReplay(
        PatientCarePreference $existing,
        PatientPrincipal $principal,
        PatientEncounterAccessGrant $grant,
        PatientMessage $message,
        string $operationDigest,
        string $payloadDigest,
    ): bool {
        return (int) $existing->principal_id === (int) $principal->getKey()
            && (int) $existing->access_grant_id === (int) $grant->getKey()
            && (int) $existing->source_message_id === (int) $message->getKey()
            && hash_equals((string) $existing->idempotency_key_digest, $operationDigest)
            && hash_equals((string) $existing->request_payload_digest, $payloadDigest);
    }
}
