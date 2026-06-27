<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\User;
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
            return response()->json([
                'error' => ['code' => 'invalid_credentials', 'message' => 'These credentials do not match our records.'],
            ], 401);
        }

        if (! $user->is_active) {
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

            return response()->json([
                'password_change_required' => true,
                'change_token' => $changeToken->plainTextToken,
            ]);
        }

        return response()->json($this->issueTokenPair($user));
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
            return response()->json([
                'error' => ['code' => 'invalid_refresh_token', 'message' => 'A valid refresh token is required.'],
            ], 401);
        }

        // Rotate: revoke the presented refresh token, then issue a fresh pair.
        $current->delete();

        return response()->json($this->issueTokenPair($user));
    }

    /**
     * POST /api/auth/token/revoke — revoke the presented token (logout / wipe).
     */
    public function revoke(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['revoked' => true]);
    }

    /**
     * POST /api/auth/change-password — token-scoped forced/voluntary change.
     * Mirrors the web ChangePasswordController behavior; does not replace it.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        if (Hash::check($request->new_password, $user->password)) {
            throw ValidationException::withMessages([
                'new_password' => ['The new password must be different from your current password.'],
            ]);
        }

        $user->update([
            'password' => Hash::make($request->new_password),
            'must_change_password' => false,
        ]);

        // Consume the change token and hand back a full session.
        $request->user()->currentAccessToken()?->delete();

        return response()->json($this->issueTokenPair($user));
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
}
