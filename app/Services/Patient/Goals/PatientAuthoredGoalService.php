<?php

namespace App\Services\Patient\Goals;

use App\Models\Patient\PatientAuthoredGoal;
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
 * Records a patient-authored personal goal only as an immutable, content-free
 * association to its encrypted accountable message. It deliberately cannot
 * create, amend, complete, or reconcile a clinician-authored care-plan goal.
 */
class PatientAuthoredGoalService
{
    private const TOPIC_CODE = 'patient_goal';

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
        if (! (bool) config('hummingbird-patient.features.patient_goals')
            || ($input['topic_code'] ?? null) !== self::TOPIC_CODE) {
            throw PatientMessagingFailure::notFound();
        }

        try {
            return DB::transaction(function () use ($request, $principal, $encounterUuid, $input): array {
                $grant = $this->currentGrant($principal, $encounterUuid);
                $messageResult = $this->messaging->createThread($request, $principal, $encounterUuid, $input);
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
                    'patient-goal.idempotency',
                    (string) $principal->principal_uuid.'|'.(string) $input['idempotency_key'],
                );
                $payloadDigest = $this->hmac->digest(
                    'patient-goal.payload',
                    implode('|', [
                        $encounterUuid,
                        $this->hmac->digest('patient-goal.body', (string) $input['message']),
                        (string) $input['client_message_uuid'],
                        (string) $input['urgent_guidance_version'],
                    ]),
                );
                $existing = PatientAuthoredGoal::query()
                    ->where('source_message_id', $message->getKey())
                    ->orWhere('idempotency_key_digest', $operationDigest)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof PatientAuthoredGoal) {
                    if (! $this->isExactReplay($existing, $principal, $grant, $message, $operationDigest, $payloadDigest)) {
                        throw PatientMessagingFailure::idempotencyConflict();
                    }
                    $goal = $existing;
                    $replayed = true;
                } else {
                    $goal = PatientAuthoredGoal::query()->create([
                        'goal_uuid' => (string) Str::uuid7(),
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
                    $replayed ? 'patient.goal.replayed' : 'patient.goal.submitted',
                    'patient_goal',
                    $replayed ? 'replay_goal' : 'submit_goal',
                    'succeeded',
                    $principal,
                    grant: $grant,
                    resourceType: 'patient_authored_goal',
                    resourceUuid: (string) $goal->goal_uuid,
                    metadata: ['message_replayed' => (bool) $messageResult['replayed']],
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
                    'patient.goal.denied',
                    'patient_goal',
                    'submit_goal',
                    'denied',
                    $principal,
                    reasonCode: 'patient_goal_not_available',
                    resourceType: 'patient_authored_goal',
                );
            }

            throw $failure;
        }
    }

    private function currentGrant(PatientPrincipal $principal, string $encounterUuid): PatientEncounterAccessGrant
    {
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
        PatientAuthoredGoal $existing,
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
