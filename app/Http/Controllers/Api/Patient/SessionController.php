<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Concerns\RendersPatientEnvelope;
use App\Http\Controllers\Controller;
use App\Models\Patient\PatientPrincipal;
use App\Models\Patient\PatientSession;
use App\Services\Patient\PatientAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class SessionController extends Controller
{
    use RendersPatientEnvelope;

    public function __construct(private readonly PatientAuthService $auth) {}

    public function index(Request $request): JsonResponse
    {
        /** @var PatientPrincipal $principal */
        $principal = $request->user();
        /** @var PersonalAccessToken $token */
        $token = $principal->currentAccessToken();
        $result = $this->auth->activeSessions($principal, $token, $request);
        $currentSessionUuid = $result['current_session_uuid'];

        return $this->patientEnvelope([
            'sessions' => $result['sessions']
                ->map(fn (PatientSession $session): array => $this->serialize(
                    $session,
                    $currentSessionUuid !== null
                        && hash_equals($currentSessionUuid, (string) $session->session_uuid),
                ))
                ->values()
                ->all(),
        ]);
    }

    public function destroy(Request $request, string $sessionUuid): JsonResponse
    {
        /** @var PatientPrincipal $principal */
        $principal = $request->user();
        /** @var PersonalAccessToken $token */
        $token = $principal->currentAccessToken();
        $result = $this->auth->revokeOwnedSession($principal, $sessionUuid, $token, $request);

        if ($result === null) {
            return response()->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'The requested resource was not found.',
                ],
            ], 404);
        }

        return $this->patientEnvelope([
            'session_uuid' => (string) $result['session']->session_uuid,
            'revoked' => true,
            'already_revoked' => $result['already_revoked'],
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(PatientSession $session, bool $current): array
    {
        return [
            'session_uuid' => (string) $session->session_uuid,
            'current' => $current,
            'status' => (string) $session->status,
            'device' => [
                'uuid' => $session->device_uuid !== null ? (string) $session->device_uuid : null,
                'platform' => $session->platform,
                'name' => $session->device_name,
                'app_version' => $session->app_version,
                'os_version' => $session->os_version,
            ],
            'auth_method' => (string) $session->auth_method,
            'assurance_level' => $session->assurance_level,
            'last_seen_at' => $session->last_seen_at?->toISOString(),
            'expires_at' => $session->expires_at?->toISOString(),
            'created_at' => $session->created_at?->toISOString(),
        ];
    }
}
