<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\UserAuditRecorder;
use App\Services\Auth\AccountSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Token-based authentication for the Hummingbird mobile companion.
 *
 * This is an ADDITIVE, parallel auth path. It does NOT modify or bypass the
 * protected web flow (temp-password / must_change_password / Resend), and it
 * honors must_change_password exactly: a forced-change user receives only a
 * narrowly-scoped change token until they set a new password.
 *
 * @see \App\Http\Controllers\Auth\AuthenticatedSessionController  (web — untouched)
 * @see \App\Http\Controllers\Auth\ChangePasswordController        (web — untouched)
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly UserAuditRecorder $audit,
        private readonly AccountSessionService $sessions,
    ) {}

    /**
     * POST /api/auth/token — exchange username (or email) + password for tokens.
     */
    public function token(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device' => ['sometimes', 'array'],
        ]);

        $user = User::query()
            ->where('username', $validated['username'])
            ->orWhere('email', $validated['username'])
            ->first();

        // Generic failure — never reveal whether the account exists.
        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            $this->auditAuth($request, 'mobile.auth.token_exchange', 'failure', null, [
                'principal' => $validated['username'],
                'reason' => 'invalid_credentials',
                'auth_method' => 'password',
                'http_status' => 401,
            ]);

            return response()->json([
                'error' => ['code' => 'invalid_credentials', 'message' => 'These credentials do not match our records.'],
            ], 401);
        }

        if (! $user->is_active) {
            $this->auditAuth($request, 'mobile.auth.token_exchange', 'denied', $user, [
                'reason' => 'account_inactive',
                'auth_method' => 'password',
                'http_status' => 403,
            ]);

            return response()->json([
                'error' => ['code' => 'account_inactive', 'message' => 'This account is not active.'],
            ], 403);
        }

        // Forced password change: issue a single, narrowly-scoped token and stop.
        if ($user->must_change_password) {
            $changeToken = $user->createToken(
                'mobile-change-password',
                ['password:change'],
                now()->addMinutes((int) config('hummingbird.token.change_ttl_minutes', 15)),
            );

            $this->auditAuth($request, 'mobile.auth.token_exchange', 'challenge', $user, [
                'reason' => 'password_change_required',
                'auth_method' => 'password',
                'http_status' => 200,
                'metadata' => [
                    'challenge_required' => true,
                    'password_change_required' => true,
                    'token_kind' => 'password_change',
                ],
            ]);

            return response()->json([
                'password_change_required' => true,
                'change_token' => $changeToken->plainTextToken,
            ]);
        }

        $pair = $this->issueTokenPair($user);
        $this->auditAuth($request, 'mobile.auth.token_exchange', 'success', $user, [
            'auth_method' => 'password',
            'http_status' => 200,
            'metadata' => ['token_kind' => 'access_refresh_pair'],
        ]);

        return response()->json($pair);
    }

    /**
     * POST /api/auth/token/refresh — rotate a refresh token into a new pair.
     * Authenticated by the refresh token (ability: token:refresh).
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $user->currentAccessToken();

        if (! $current || ! $current->can('token:refresh')) {
            $this->auditAuth($request, 'mobile.auth.token_refresh', 'denied', $user, [
                'reason' => 'invalid_refresh_token',
                'auth_method' => 'mobile_token',
                'http_status' => 401,
            ]);

            return response()->json([
                'error' => ['code' => 'invalid_refresh_token', 'message' => 'A valid refresh token is required.'],
            ], 401);
        }

        if (! $user->is_active) {
            $current->delete();
            $this->auditAuth($request, 'mobile.auth.token_refresh', 'denied', $user, [
                'reason' => 'account_inactive',
                'auth_method' => 'mobile_token',
                'http_status' => 403,
            ]);

            return response()->json([
                'error' => ['code' => 'account_inactive', 'message' => 'This account is not active.'],
            ], 403);
        }

        // Rotate: revoke the presented refresh token, then issue a fresh pair.
        $current->delete();

        $pair = $this->issueTokenPair($user);
        $this->auditAuth($request, 'mobile.auth.token_refresh', 'success', $user, [
            'auth_method' => 'mobile_token',
            'http_status' => 200,
            'metadata' => ['token_kind' => 'access_refresh_pair'],
        ]);

        return response()->json($pair);
    }

    /**
     * POST /api/auth/token/revoke — revoke the presented token (logout / wipe).
     */
    public function revoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->currentAccessToken()?->delete();

        $this->auditAuth($request, 'mobile.auth.token_revoke', 'success', $user, [
            'auth_method' => 'mobile_token',
            'http_status' => 200,
            'metadata' => ['token_kind' => 'presented_token'],
        ]);

        return response()->json(['revoked' => true]);
    }

    /**
     * POST /api/auth/change-password — token-scoped forced/voluntary change.
     * Mirrors the web ChangePasswordController behavior; does not replace it.
     */
    public function changePassword(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'current_password' => ['required', 'string'],
                'new_password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);
        } catch (ValidationException $exception) {
            $this->auditAuth($request, 'mobile.auth.password_change', 'failure', $request->user(), [
                'reason' => 'validation_failed',
                'auth_method' => 'mobile_token',
                'http_status' => 422,
            ]);

            throw $exception;
        }

        $user = $request->user();

        if (! $user->is_active) {
            $this->auditAuth($request, 'mobile.auth.password_change', 'denied', $user, [
                'reason' => 'account_inactive',
                'auth_method' => 'mobile_token',
                'http_status' => 403,
            ]);

            return response()->json([
                'error' => ['code' => 'account_inactive', 'message' => 'This account is not active.'],
            ], 403);
        }

        if (! Hash::check($request->current_password, $user->password)) {
            $this->auditAuth($request, 'mobile.auth.password_change', 'failure', $user, [
                'reason' => 'current_password_invalid',
                'auth_method' => 'mobile_token',
                'http_status' => 422,
            ]);

            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        if (Hash::check($request->new_password, $user->password)) {
            $this->auditAuth($request, 'mobile.auth.password_change', 'failure', $user, [
                'reason' => 'password_reused',
                'auth_method' => 'mobile_token',
                'http_status' => 422,
            ]);

            throw ValidationException::withMessages([
                'new_password' => ['The new password must be different from your current password.'],
            ]);
        }

        $user->update([
            'password' => Hash::make($request->new_password),
            'must_change_password' => false,
        ]);

        // Revoke every pre-change web/mobile credential, then issue one new pair.
        $this->sessions->revoke($user, $request, 'password_changed');

        $pair = $this->issueTokenPair($user);
        $this->auditAuth($request, 'mobile.auth.password_change', 'success', $user, [
            'auth_method' => 'mobile_token',
            'http_status' => 200,
            'changes' => [
                'must_change_password' => ['from' => true, 'to' => false],
            ],
            'metadata' => [
                'changed_fields' => ['credentials', 'must_change_password'],
                'token_kind' => 'access_refresh_pair',
            ],
        ]);

        return response()->json($pair);
    }

    /**
     * Issue an access + refresh token pair with role-derived abilities.
     *
     * @return array<string, mixed>
     */
    private function issueTokenPair(User $user): array
    {
        $accessTtlMinutes = (int) config('hummingbird.token.access_ttl_minutes', 30);
        $refreshTtlDays = (int) config('hummingbird.token.refresh_ttl_days', 30);
        $abilities = $user->mobileTokenAbilities();

        $access = $user->createToken('mobile-access', $abilities, now()->addMinutes($accessTtlMinutes));
        $refresh = $user->createToken('mobile-refresh', ['token:refresh'], now()->addDays($refreshTtlDays));

        return [
            'token_type' => 'Bearer',
            'access_token' => $access->plainTextToken,
            'refresh_token' => $refresh->plainTextToken,
            'expires_in' => $accessTtlMinutes * 60,
            'abilities' => $abilities,
        ];
    }

    /** @param  array<string, mixed>  $context */
    private function auditAuth(
        Request $request,
        string $action,
        string $outcome,
        ?User $user,
        array $context = [],
    ): void {
        $this->audit->bestEffort($action, 'authentication', $outcome, [
            ...$context,
            'request' => $request,
            'actor' => $user,
            'source_surface' => 'hummingbird',
            'target_type' => $user !== null ? 'user' : null,
            'target_id' => $user?->getKey(),
        ]);
    }
}
