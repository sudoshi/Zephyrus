<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Controllers\Controller;
use App\Models\Auth\MobileTokenSession;
use App\Models\User;
use App\Services\Audit\UserAuditRecorder;
use App\Services\Auth\MobileTokenSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class SessionController extends Controller
{
    use RendersMobileEnvelope;

    public function __construct(
        private readonly MobileTokenSessionService $sessions,
        private readonly UserAuditRecorder $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $currentToken = $user->currentAccessToken();
        if (! $currentToken instanceof PersonalAccessToken
            || ! $this->sessions->isActiveAccessFamilyToken($user, $currentToken)
        ) {
            return $this->durableMobileSessionRequired($request, $user, 'mobile.auth.sessions_viewed');
        }
        $result = $this->sessions->activeSessions($user, $currentToken);
        $currentSessionUuid = $result['current_session_uuid'];

        // Device inventory is security-sensitive metadata. If the audit ledger is
        // unavailable, fail closed before disclosing it.
        $this->audit->record('mobile.auth.sessions_viewed', 'access', 'success', [
            'request' => $request,
            'actor' => $user,
            'auth_method' => 'mobile_token',
            'source_surface' => 'hummingbird',
            'target_type' => 'user',
            'target_id' => $user->getKey(),
            'http_status' => 200,
            'metadata' => ['active_session_count' => $result['sessions']->count()],
        ]);

        return $this->envelope([
            'sessions' => $result['sessions']
                ->map(fn (MobileTokenSession $session): array => $this->serialize(
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
        /** @var User $user */
        $user = $request->user();
        $currentToken = $user->currentAccessToken();
        if (! $currentToken instanceof PersonalAccessToken
            || ! $this->sessions->isActiveAccessFamilyToken($user, $currentToken)
        ) {
            return $this->durableMobileSessionRequired(
                $request,
                $user,
                'mobile.auth.session_revoke_denied',
            );
        }
        $result = DB::transaction(function () use (
            $request,
            $user,
            $sessionUuid,
            $currentToken,
        ): ?array {
            $result = $this->sessions->revokeOwnedSession(
                $user,
                $sessionUuid,
                $currentToken,
            );

            if ($result === null) {
                return null;
            }

            $session = $result['session'];
            // Security mutation and evidence are one commit boundary. An audit
            // outage rolls the family revocation back.
            $this->audit->record('mobile.auth.session_revoked', 'authentication', 'success', [
                'request' => $request,
                'actor' => $user,
                'auth_method' => 'mobile_token',
                'source_surface' => 'hummingbird',
                'target_type' => 'mobile_token_session',
                'target_id' => (string) $session->session_uuid,
                'http_status' => 200,
                'metadata' => [
                    'already_revoked' => $result['already_revoked'],
                    'current_session' => $result['current'],
                ],
            ]);

            return $result;
        }, 3);

        if ($result === null) {
            $this->audit->bestEffort('mobile.auth.session_revoke_denied', 'authentication', 'denied', [
                'request' => $request,
                'actor' => $user,
                'reason' => 'not_found',
                'auth_method' => 'mobile_token',
                'source_surface' => 'hummingbird',
                'target_type' => 'mobile_token_session',
                'target_id' => $this->safeTargetId($sessionUuid),
                'http_status' => 404,
            ]);

            return response()->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'The requested resource was not found.',
                ],
            ], 404);
        }

        $session = $result['session'];

        return $this->envelope([
            'session_uuid' => (string) $session->session_uuid,
            'revoked' => true,
            'already_revoked' => $result['already_revoked'],
            'current' => $result['current'],
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(MobileTokenSession $session, bool $current): array
    {
        return [
            'session_uuid' => (string) $session->session_uuid,
            'current' => $current,
            'status' => (string) $session->status,
            'device' => [
                'platform' => $session->platform,
                'name' => $session->device_name,
                'app_version' => $session->app_version,
                'os_version' => $session->os_version,
            ],
            'environment' => $session->environment,
            'last_seen_at' => $session->last_seen_at?->toISOString(),
            'expires_at' => $session->expires_at?->toISOString(),
            'created_at' => $session->created_at?->toISOString(),
        ];
    }

    private function safeTargetId(string $candidate): ?string
    {
        return preg_match('/^[A-Za-z0-9_.:-]{1,190}$/', $candidate) === 1
            ? $candidate
            : null;
    }

    private function durableMobileSessionRequired(
        Request $request,
        User $user,
        string $action,
    ): JsonResponse {
        $this->audit->bestEffort($action, 'authentication', 'denied', [
            'request' => $request,
            'actor' => $user,
            'reason' => 'durable_mobile_session_required',
            'auth_method' => 'session',
            'source_surface' => 'hummingbird',
            'target_type' => 'user',
            'target_id' => $user->getKey(),
            'http_status' => 401,
        ]);

        return response()->json([
            'error' => [
                'code' => 'mobile_session_required',
                'message' => 'A valid mobile session is required.',
            ],
        ], 401);
    }
}
