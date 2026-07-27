<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Concerns\RendersPatientEnvelope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\AmendPatientMessageRequest;
use App\Http\Requests\Patient\CloseMessageThreadRequest;
use App\Http\Requests\Patient\CreateMessageThreadRequest;
use App\Http\Requests\Patient\SendPatientMessageRequest;
use App\Models\Patient\PatientPrincipal;
use App\Services\Patient\Goals\PatientAuthoredGoalService;
use App\Services\Patient\Messaging\PatientMessagingFailure;
use App\Services\Patient\Messaging\PatientMessagingService;
use App\Services\Patient\Preferences\PatientCarePreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessagingController extends Controller
{
    use RendersPatientEnvelope;

    public function __construct(
        private readonly PatientMessagingService $messaging,
        private readonly PatientCarePreferenceService $carePreferences,
        private readonly PatientAuthoredGoalService $patientGoals,
    ) {}

    public function topics(Request $request, string $encounterUuid): JsonResponse
    {
        return $this->attempt(function () use ($request, $encounterUuid): JsonResponse {
            /** @var PatientPrincipal $principal */
            $principal = $request->user();
            $result = $this->messaging->topics($request, $principal, $encounterUuid);

            return $this->patientEnvelope(
                $this->withoutControlFields($result),
                $this->meta((string) $result['policy_version']),
            );
        });
    }

    public function index(Request $request, string $encounterUuid): JsonResponse
    {
        return $this->attempt(function () use ($request, $encounterUuid): JsonResponse {
            /** @var PatientPrincipal $principal */
            $principal = $request->user();
            $result = $this->messaging->listThreads($request, $principal, $encounterUuid);

            return $this->patientEnvelope(
                $this->withoutControlFields($result),
                $this->meta((string) $result['policy_version']),
            );
        });
    }

    public function store(CreateMessageThreadRequest $request, string $encounterUuid): JsonResponse
    {
        return $this->attempt(function () use ($request, $encounterUuid): JsonResponse {
            /** @var PatientPrincipal $principal */
            $principal = $request->user();
            $input = $request->validated();
            $result = match ($input['topic_code'] ?? null) {
                'care_preference' => (bool) config('hummingbird-patient.features.care_preferences')
                    ? $this->carePreferences->submit($request, $principal, $encounterUuid, $input)
                    : $this->messaging->createThread($request, $principal, $encounterUuid, $input),
                'patient_goal' => (bool) config('hummingbird-patient.features.patient_goals')
                    ? $this->patientGoals->submit($request, $principal, $encounterUuid, $input)
                    : $this->messaging->createThread($request, $principal, $encounterUuid, $input),
                default => $this->messaging->createThread($request, $principal, $encounterUuid, $input),
            };

            return $this->patientEnvelope(
                ['thread' => $result['thread']],
                $this->meta(
                    $result['policy_version'],
                    $result['replayed'],
                    (int) $result['thread']['version'],
                ),
                status: $result['status'],
            );
        });
    }

    public function show(Request $request, string $threadUuid): JsonResponse
    {
        return $this->attempt(function () use ($request, $threadUuid): JsonResponse {
            /** @var PatientPrincipal $principal */
            $principal = $request->user();
            $result = $this->messaging->showThread($request, $principal, $threadUuid);

            return $this->patientEnvelope(
                $this->withoutControlFields($result),
                $this->meta(
                    (string) $result['policy_version'],
                    version: (int) $result['thread']['version'],
                ),
            );
        });
    }

    public function send(SendPatientMessageRequest $request, string $threadUuid): JsonResponse
    {
        return $this->attempt(function () use ($request, $threadUuid): JsonResponse {
            /** @var PatientPrincipal $principal */
            $principal = $request->user();
            $result = $this->messaging->sendMessage(
                $request,
                $principal,
                $threadUuid,
                $request->validated(),
            );

            return $this->patientEnvelope(
                [
                    'thread' => $result['thread'],
                    'message' => $result['message'],
                ],
                $this->meta(
                    $result['policy_version'],
                    $result['replayed'],
                    (int) $result['thread']['version'],
                ),
                status: $result['status'],
            );
        });
    }

    public function close(CloseMessageThreadRequest $request, string $threadUuid): JsonResponse
    {
        return $this->attempt(function () use ($request, $threadUuid): JsonResponse {
            /** @var PatientPrincipal $principal */
            $principal = $request->user();
            $result = $this->messaging->closeThread(
                $request,
                $principal,
                $threadUuid,
                $request->validated(),
            );

            return $this->patientEnvelope(
                ['thread' => $result['thread']],
                $this->meta(
                    $result['policy_version'],
                    $result['replayed'],
                    (int) $result['thread']['version'],
                ),
                status: $result['status'],
            );
        });
    }

    public function amend(
        AmendPatientMessageRequest $request,
        string $threadUuid,
        string $messageUuid,
    ): JsonResponse {
        return $this->attempt(function () use ($request, $threadUuid, $messageUuid): JsonResponse {
            /** @var PatientPrincipal $principal */
            $principal = $request->user();
            $result = $this->messaging->amendMessage(
                $request,
                $principal,
                $threadUuid,
                $messageUuid,
                $request->validated(),
            );

            return $this->patientEnvelope(
                [
                    'thread' => $result['thread'],
                    'message' => $result['message'],
                ],
                $this->meta(
                    $result['policy_version'],
                    $result['replayed'],
                    (int) $result['thread']['version'],
                ),
                status: $result['status'],
            );
        });
    }

    private function attempt(callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (PatientMessagingFailure $failure) {
            return response()->json([
                'error' => [
                    'code' => $failure->errorCode,
                    'message' => 'The patient messaging request could not be completed.',
                ],
            ], $failure->httpStatus);
        }
    }

    /** @return array<string, mixed> */
    private function meta(
        string $policyVersion,
        ?bool $replayed = null,
        ?int $version = null,
    ): array {
        $meta = [
            'policy_version' => $policyVersion,
            'source_freshness' => [
                'status' => 'current',
                'observed_at' => now()->toISOString(),
            ],
            'version' => $version,
        ];

        if ($replayed !== null) {
            $meta['idempotency_replayed'] = $replayed;
        }

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function withoutControlFields(array $result): array
    {
        unset($result['policy_version']);

        return $result;
    }
}
