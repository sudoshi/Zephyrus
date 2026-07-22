<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Concerns\RendersPatientEnvelope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\TokenRequest;
use App\Http\Requests\Patient\VerifyEnrollmentChallengeRequest;
use App\Models\Patient\PatientPrincipal;
use App\Services\Patient\PatientAuthFailure;
use App\Services\Patient\PatientAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    use RendersPatientEnvelope;

    public function __construct(private readonly PatientAuthService $auth) {}

    public function enroll(VerifyEnrollmentChallengeRequest $request): JsonResponse
    {
        try {
            $pair = $this->auth->enroll($request->validated(), $request);
        } catch (PatientAuthFailure $failure) {
            return $this->failure($failure);
        }

        return $this->patientEnvelope($pair, status: 201);
    }

    public function token(TokenRequest $request): JsonResponse
    {
        try {
            $pair = $this->auth->authenticate(
                (string) $request->validated('email'),
                (string) $request->validated('password'),
                (array) $request->validated('device', []),
                $request,
            );
        } catch (PatientAuthFailure $failure) {
            return $this->failure($failure);
        }

        return $this->patientEnvelope($pair);
    }

    public function refresh(Request $request): JsonResponse
    {
        /** @var PatientPrincipal $principal */
        $principal = $request->user();
        $token = $principal->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return $this->failure(new PatientAuthFailure(
                'invalid_refresh_token',
                'A valid patient refresh token is required.',
                401,
            ));
        }

        try {
            $pair = $this->auth->refresh($principal, $token, $request);
        } catch (PatientAuthFailure $failure) {
            return $this->failure($failure);
        }

        return $this->patientEnvelope($pair);
    }

    public function revoke(Request $request): JsonResponse
    {
        /** @var PatientPrincipal $principal */
        $principal = $request->user();
        $token = $principal->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $this->auth->revoke($principal, $token, $request);
        }

        return $this->patientEnvelope(['revoked' => true]);
    }

    private function failure(PatientAuthFailure $failure): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $failure->errorCode,
                'message' => $failure->getMessage(),
            ],
        ], $failure->httpStatus);
    }
}
