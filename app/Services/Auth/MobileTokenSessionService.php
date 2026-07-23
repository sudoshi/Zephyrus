<?php

namespace App\Services\Auth;

use App\Models\Auth\MobileTokenSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class MobileTokenSessionService
{
    private const ACCESS_PREFIX = 'mobile-access:';

    private const REFRESH_PREFIX = 'mobile-refresh:';

    private const LEGACY_ACCESS_NAME = 'mobile-access';

    private const LEGACY_REFRESH_NAME = 'mobile-refresh';

    /**
     * Create one stable family and issue its first access/refresh generation.
     *
     * @return array<string, mixed>
     */
    public function issueNewFamily(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $session = MobileTokenSession::query()->create([
                'user_id' => $user->getKey(),
                'expires_at' => CarbonImmutable::now()->addDays($this->refreshTtlDays()),
            ]);

            return $this->issueForSession($user, $session);
        });
    }

    /**
     * Rotate the current refresh generation. A predecessor remains as a one-way
     * Sanctum hash. Presenting it again is family-reuse evidence and revokes all
     * access and refresh credentials in that family.
     *
     * @return array{
     *     pair: array<string, mixed>|null,
     *     reason: string|null,
     *     http_status: int
     * }
     */
    public function rotate(User $user, PersonalAccessToken $presented): array
    {
        if (! $this->isRefreshName((string) $presented->name)) {
            return $this->denied('invalid_refresh_token');
        }

        return DB::transaction(function () use ($user, $presented): array {
            /** @var PersonalAccessToken|null $token */
            $token = $user->tokens()
                ->whereKey($presented->getKey())
                ->lockForUpdate()
                ->first();

            if (! $token || ! $this->isRefreshName((string) $token->name)) {
                return $this->denied('invalid_refresh_token');
            }

            if ($token->name === self::LEGACY_REFRESH_NAME && ! $token->can('token:refresh')) {
                $token->delete();

                return $this->denied('invalid_refresh_token');
            }

            $session = $token->name === self::LEGACY_REFRESH_NAME
                ? $this->upgradeLegacyRefresh($user, $token)
                : $this->sessionForNamedToken($user, $token, forUpdate: true);

            if (! $user->is_active) {
                if ($session) {
                    $this->revokeFamily($user, $session, 'account_inactive');
                } else {
                    $token->delete();
                }

                return $this->denied('account_inactive', 403);
            }

            if (! $session) {
                $token->delete();

                return $this->denied('invalid_refresh_token');
            }

            if ($session->status !== 'active'
                || $session->revoked_at !== null
                || $session->expires_at === null
                || ! $session->expires_at->isFuture()) {
                $this->revokeFamily($user, $session, 'invalid_refresh_token');

                return $this->denied('invalid_refresh_token');
            }

            if ((int) $session->refresh_token_id !== (int) $token->getKey()) {
                $this->revokeFamily($user, $session, 'refresh_token_reuse_detected');

                return $this->denied('refresh_token_reuse_detected');
            }

            if (! $token->can('token:refresh')) {
                $this->revokeFamily($user, $session, 'invalid_refresh_token');

                return $this->denied('invalid_refresh_token');
            }

            // The predecessor must remain as a one-way hash so later presentation
            // can be recognized, but it loses every authorization ability before
            // the successor is issued. It is therefore a tombstone, not a usable
            // refresh credential.
            $token->forceFill(['abilities' => []])->save();
            $this->deleteFamilyAccessTokens($user, $session);

            return [
                'pair' => $this->issueForSession($user, $session),
                'reason' => null,
                'http_status' => 200,
            ];
        });
    }

    /**
     * Revoke the exact family represented by a mobile access/refresh token.
     * Non-family credentials (including password-change challenges) retain the
     * historical presented-token-only behavior.
     */
    public function revokeForToken(User $user, PersonalAccessToken $presented, string $reason): void
    {
        DB::transaction(function () use ($user, $presented, $reason): void {
            /** @var PersonalAccessToken|null $token */
            $token = $user->tokens()
                ->whereKey($presented->getKey())
                ->lockForUpdate()
                ->first();

            if (! $token) {
                return;
            }

            $session = $this->sessionForNamedToken($user, $token, forUpdate: true);
            if ($session) {
                $this->revokeFamily($user, $session, $reason);

                return;
            }

            if (in_array((string) $token->name, [self::LEGACY_ACCESS_NAME, self::LEGACY_REFRESH_NAME], true)) {
                $user->tokens()
                    ->whereIn('name', [self::LEGACY_ACCESS_NAME, self::LEGACY_REFRESH_NAME])
                    ->delete();

                return;
            }

            $token->delete();
        });
    }

    /**
     * Keep family lifecycle state reconciled with account-wide token revocation.
     */
    public function revokeAllForUser(User $user, string $reason): int
    {
        if (! Schema::hasTable('prod.mobile_token_sessions')) {
            return 0;
        }

        return MobileTokenSession::query()
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->update([
                'status' => 'revoked',
                'revoked_at' => now(),
                'revocation_reason' => $reason,
                'refresh_token_id' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Transactionally adopt a pre-family refresh token without exposing or
     * replacing its bearer value. Renaming the hashed token row makes a later
     * presentation of the same legacy bearer detectable as predecessor reuse.
     */
    private function upgradeLegacyRefresh(User $user, PersonalAccessToken $token): ?MobileTokenSession
    {
        $configuredExpiry = CarbonImmutable::now()->addDays($this->refreshTtlDays());
        $tokenExpiry = $token->expires_at !== null
            ? CarbonImmutable::instance($token->expires_at)
            : $configuredExpiry;
        $familyExpiry = $tokenExpiry->lessThan($configuredExpiry) ? $tokenExpiry : $configuredExpiry;

        if (! $familyExpiry->isFuture()) {
            $token->delete();

            return null;
        }

        $session = MobileTokenSession::query()->create([
            'user_id' => $user->getKey(),
            'expires_at' => $familyExpiry,
            'refresh_token_id' => $token->getKey(),
        ]);

        $token->forceFill(['name' => $this->refreshName($session)])->save();
        $user->tokens()->where('name', self::LEGACY_ACCESS_NAME)->delete();

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    private function issueForSession(User $user, MobileTokenSession $session): array
    {
        $now = CarbonImmutable::now();
        $familyExpiry = CarbonImmutable::instance($session->expires_at);
        $configuredAccessExpiry = $now->addMinutes($this->accessTtlMinutes());
        $accessExpiry = $configuredAccessExpiry->lessThan($familyExpiry)
            ? $configuredAccessExpiry
            : $familyExpiry;
        $expiresIn = max(1, (int) $now->diffInSeconds($accessExpiry, false));
        $abilities = $user->mobileTokenAbilities();

        $access = $user->createToken(
            $this->accessName($session),
            $abilities,
            $accessExpiry,
        );
        $refresh = $user->createToken(
            $this->refreshName($session),
            ['token:refresh'],
            $familyExpiry,
        );

        $session->forceFill([
            'status' => 'active',
            'refresh_token_id' => $refresh->accessToken->getKey(),
        ])->save();

        return [
            'token_type' => 'Bearer',
            'access_token' => $access->plainTextToken,
            'refresh_token' => $refresh->plainTextToken,
            'expires_in' => $expiresIn,
            'abilities' => $abilities,
        ];
    }

    private function sessionForNamedToken(
        User $user,
        PersonalAccessToken $token,
        bool $forUpdate = false,
    ): ?MobileTokenSession {
        $familyUuid = $this->familyUuidFromName((string) $token->name);
        if ($familyUuid === null) {
            return null;
        }

        $query = MobileTokenSession::query()
            ->where('token_family_uuid', $familyUuid)
            ->where('user_id', $user->getKey());

        return ($forUpdate ? $query->lockForUpdate() : $query)->first();
    }

    private function revokeFamily(User $user, MobileTokenSession $session, string $reason): void
    {
        $user->tokens()
            ->whereIn('name', [
                $this->accessName($session),
                $this->refreshName($session),
            ])
            ->delete();

        $session->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revocation_reason' => $reason,
            'refresh_token_id' => null,
        ])->save();
    }

    private function deleteFamilyAccessTokens(User $user, MobileTokenSession $session): void
    {
        $user->tokens()
            ->where('name', $this->accessName($session))
            ->delete();
    }

    private function accessName(MobileTokenSession $session): string
    {
        return self::ACCESS_PREFIX.$session->token_family_uuid;
    }

    private function refreshName(MobileTokenSession $session): string
    {
        return self::REFRESH_PREFIX.$session->token_family_uuid;
    }

    private function isRefreshName(string $name): bool
    {
        return $name === self::LEGACY_REFRESH_NAME
            || str_starts_with($name, self::REFRESH_PREFIX);
    }

    private function familyUuidFromName(string $name): ?string
    {
        foreach ([self::ACCESS_PREFIX, self::REFRESH_PREFIX] as $prefix) {
            if (! str_starts_with($name, $prefix)) {
                continue;
            }

            $familyUuid = substr($name, strlen($prefix));

            return Str::isUuid($familyUuid) ? $familyUuid : null;
        }

        return null;
    }

    /**
     * @return array{
     *     pair: null,
     *     reason: string,
     *     http_status: int
     * }
     */
    private function denied(string $reason, int $httpStatus = 401): array
    {
        return [
            'pair' => null,
            'reason' => $reason,
            'http_status' => $httpStatus,
        ];
    }

    private function accessTtlMinutes(): int
    {
        return max(1, (int) config('hummingbird.token.access_ttl_minutes', 30));
    }

    private function refreshTtlDays(): int
    {
        return max(1, (int) config('hummingbird.token.refresh_ttl_days', 30));
    }
}
