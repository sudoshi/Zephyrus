<?php

namespace App\Http\Middleware;

use App\Models\Patient\PatientPrincipal;
use App\Models\Patient\PatientSession;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail-closed patient-realm boundary after Sanctum has resolved a bearer token.
 *
 * Sanctum can authenticate any HasApiTokens model. This additional check is
 * therefore mandatory: staff users and non-patient token abilities must never
 * be accepted by /api/patient/v1, even if a token was issued accidentally.
 */
class EnsurePatientRealm
{
    public function handle(Request $request, Closure $next): Response
    {
        $principal = $request->user();
        $token = $principal?->currentAccessToken();

        if (! $principal instanceof PatientPrincipal
            || ! $token instanceof PersonalAccessToken
            || ! $this->tokenMatchesRealm($token)) {
            return $this->denied();
        }

        if (! (bool) $principal->is_active
            || $principal->status !== 'active'
            || $principal->locked_at !== null) {
            $token->delete();

            return response()->json([
                'error' => [
                    'code' => 'account_inactive',
                    'message' => 'This patient account is not active.',
                ],
            ], 403);
        }

        $session = $this->activeSession($principal, $token);
        if (! $session) {
            $token->delete();

            return $this->denied();
        }

        if (str_starts_with((string) $token->name, 'patient-access:')
            && ($session->last_seen_at === null || $session->last_seen_at->lt(now()->subMinute()))) {
            $session->forceFill(['last_seen_at' => now()])->save();
        }

        return $next($request);
    }

    private function tokenMatchesRealm(PersonalAccessToken $token): bool
    {
        $abilities = array_values((array) $token->abilities);
        $name = (string) $token->name;

        if (str_starts_with($name, 'patient-access:')) {
            return $abilities === ['patient:access'];
        }

        if (str_starts_with($name, 'patient-refresh:')) {
            return $abilities === ['patient:refresh'];
        }

        return false;
    }

    private function activeSession(
        PatientPrincipal $principal,
        PersonalAccessToken $token,
    ): ?PatientSession {
        $name = (string) $token->name;
        $separator = strpos($name, ':');
        $sessionUuid = $separator === false ? '' : substr($name, $separator + 1);

        if (! Str::isUuid($sessionUuid)) {
            return null;
        }

        return PatientSession::query()
            ->where('principal_id', $principal->getKey())
            ->where('session_uuid', $sessionUuid)
            ->active()
            ->first();
    }

    private function denied(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'patient_realm_required',
                'message' => 'A valid patient credential is required.',
            ],
        ], 403);
    }
}
