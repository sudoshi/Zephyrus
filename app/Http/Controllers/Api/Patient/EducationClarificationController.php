<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Concerns\RendersPatientEnvelope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\CreateEducationClarificationRequest;
use App\Models\Patient\PatientPrincipal;
use App\Services\Patient\Education\PatientEducationClarificationService;
use App\Services\Patient\Messaging\PatientMessagingFailure;
use Illuminate\Http\JsonResponse;

class EducationClarificationController extends Controller
{
    use RendersPatientEnvelope;

    public function __construct(private readonly PatientEducationClarificationService $clarifications) {}

    public function store(
        CreateEducationClarificationRequest $request,
        string $encounterUuid,
        string $educationItemUuid,
    ): JsonResponse {
        try {
            /** @var PatientPrincipal $principal */
            $principal = $request->user();
            $result = $this->clarifications->requestClarification(
                $request,
                $principal,
                $encounterUuid,
                $educationItemUuid,
                $request->validated(),
            );

            return $this->patientEnvelope(
                ['thread' => $result['thread']],
                [
                    'policy_version' => $result['policy_version'],
                    'idempotency_replayed' => $result['replayed'],
                    'source_freshness' => [
                        'status' => 'current',
                        'observed_at' => now()->toISOString(),
                    ],
                    'version' => (int) $result['thread']['version'],
                ],
                status: $result['status'],
            );
        } catch (PatientMessagingFailure $failure) {
            return response()->json([
                'error' => [
                    'code' => $failure->errorCode,
                    'message' => 'The education clarification request could not be completed.',
                ],
            ], $failure->httpStatus);
        }
    }
}
