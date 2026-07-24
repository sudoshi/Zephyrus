<?php

namespace App\Services\Auth;

use App\Models\Auth\MobileTokenSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
     * @param  array{
     *     installation_uuid?: string,
     *     platform?: string,
     *     name?: string|null,
     *     app_version?: string|null,
     *     os_version?: string|null
     * }  $device
     * @return array<string, mixed>
     */
    public function issueNewFamily(User $user, array $device = []): array
    {
        return DB::transaction(function () use ($user, $device): array {
            $session = MobileTokenSession::query()->create([
                'user_id' => $user->getKey(),
                'expires_at' => CarbonImmutable::now()->addDays($this->refreshTtlDays()),
                ...$this->deviceAttributes($device),
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
                'access_token_id' => null,
                'refresh_token_id' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Session self-service is available only from the active access credential
     * of a server-issued mobile family. A transient Sanctum principal, browser
     * cookie, refresh token, legacy token, or unrelated personal token cannot
     * enumerate or revoke Hummingbird families.
     */
    public function isActiveAccessFamilyToken(
        User $user,
        PersonalAccessToken $currentToken,
    ): bool {
        if (! str_starts_with((string) $currentToken->name, self::ACCESS_PREFIX)) {
            return false;
        }

        $session = $this->sessionForNamedToken($user, $currentToken);

        return $session !== null
            && $session->status === 'active'
            && $session->revoked_at === null
            && $session->expires_at?->isFuture() === true;
    }

    /**
     * Return only usable token families owned by this user. Token row IDs,
     * hashes, installation identifiers, network metadata, and bearer material
     * never cross this boundary.
     *
     * @return array{
     *     current_session_uuid: string|null,
     *     sessions: EloquentCollection<int, MobileTokenSession>
     * }
     */
    public function activeSessions(User $user, PersonalAccessToken $currentToken): array
    {
        $currentSession = $this->sessionForNamedToken($user, $currentToken);
        $sessions = MobileTokenSession::query()
            ->where('user_id', $user->getKey())
            ->active()
            ->orderByDesc('last_seen_at')
            ->orderByDesc('created_at')
            ->orderByDesc('mobile_token_session_id')
            ->limit(100)
            ->get();

        if ($currentSession !== null
            && $currentSession->status === 'active'
            && $currentSession->expires_at?->isFuture()
            && ! $sessions->contains(
                fn (MobileTokenSession $session): bool => $session->is($currentSession),
            )
        ) {
            $sessions = new EloquentCollection(
                collect([$currentSession])
                    ->concat($sessions)
                    ->take(100)
                    ->values()
                    ->all(),
            );
        }

        return [
            'current_session_uuid' => $currentSession?->session_uuid,
            'sessions' => $sessions,
        ];
    }

    /**
     * Revoke one session owned by the authenticated staff user. An unknown UUID
     * and another user's UUID are deliberately indistinguishable.
     *
     * @return array{
     *     session: MobileTokenSession,
     *     already_revoked: bool,
     *     current: bool
     * }|null
     */
    public function revokeOwnedSession(
        User $user,
        string $sessionUuid,
        PersonalAccessToken $currentToken,
    ): ?array {
        if (preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
            $sessionUuid,
        ) !== 1) {
            return null;
        }

        $canonicalUuid = $sessionUuid;

        return DB::transaction(function () use ($user, $canonicalUuid, $currentToken): ?array {
            // Resolve the current refresh-row pointer without locking, then acquire
            // token -> family locks in the same order used by rotation, logout, and
            // account-wide revocation. A concurrent rotation may advance the
            // pointer before we lock the family; the final family-wide delete still
            // removes that successor after the family row is locked.
            $sessionHint = MobileTokenSession::query()
                ->where('user_id', $user->getKey())
                ->where('session_uuid', $canonicalUuid)
                ->first(['refresh_token_id']);

            if ($sessionHint === null) {
                return null;
            }

            if ($sessionHint->refresh_token_id !== null) {
                $user->tokens()
                    ->whereKey($sessionHint->refresh_token_id)
                    ->lockForUpdate()
                    ->first();
            }

            $session = MobileTokenSession::query()
                ->where('user_id', $user->getKey())
                ->where('session_uuid', $canonicalUuid)
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                return null;
            }

            $currentFamilyUuid = $this->familyUuidFromName((string) $currentToken->name);
            $current = $currentFamilyUuid !== null
                && hash_equals($currentFamilyUuid, (string) $session->token_family_uuid);
            $alreadyRevoked = $session->status === 'revoked';

            if (! $alreadyRevoked) {
                $this->revokeFamily($user, $session, 'user_session_revoked');
            }

            return [
                'session' => $session->refresh(),
                'already_revoked' => $alreadyRevoked,
                'current' => $current,
            ];
        }, 3);
    }

    /**
     * Rate-limit last-seen writes while still deriving the family exclusively
     * from the authenticated Sanctum row. This is observation metadata, never a
     * replacement for authorization or role/facility evaluation.
     */
    public function touchForToken(User $user, PersonalAccessToken $currentToken): void
    {
        $familyUuid = $this->familyUuidFromName((string) $currentToken->name);
        if ($familyUuid === null) {
            return;
        }

        $now = now();
        MobileTokenSession::query()
            ->where('user_id', $user->getKey())
            ->where('token_family_uuid', $familyUuid)
            ->where('access_token_id', $currentToken->getKey())
            ->where('status', 'active')
            ->where('expires_at', '>', $now)
            ->where(function ($stale) use ($now): void {
                $stale->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<=', $now->copy()->subMinutes(5));
            })
            ->update([
                'last_seen_at' => $now,
                'updated_at' => $now,
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
            'environment' => $this->serverEnvironment(),
            'last_seen_at' => now(),
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
            'access_token_id' => $access->accessToken->getKey(),
            'refresh_token_id' => $refresh->accessToken->getKey(),
            'last_seen_at' => $now,
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

        // Access-family authorization is pointer-bound to the exact current
        // generation. A different personal token cannot become a Hummingbird
        // credential merely by copying the predictable family-name format.
        if (str_starts_with((string) $token->name, self::ACCESS_PREFIX)) {
            $query->where('access_token_id', $token->getKey());
        }

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
            'access_token_id' => null,
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

    /**
     * @param  array{
     *     installation_uuid?: string,
     *     platform?: string,
     *     name?: string|null,
     *     app_version?: string|null,
     *     os_version?: string|null
     * }  $device
     * @return array<string, mixed>
     */
    private function deviceAttributes(array $device): array
    {
        return [
            'installation_uuid' => $device['installation_uuid'] ?? null,
            'platform' => $device['platform'] ?? null,
            'device_name' => $this->nullableTrimmed($device['name'] ?? null),
            'app_version' => $this->nullableTrimmed($device['app_version'] ?? null),
            'os_version' => $this->nullableTrimmed($device['os_version'] ?? null),
            'environment' => $this->serverEnvironment(),
            'last_seen_at' => now(),
        ];
    }

    private function serverEnvironment(): string
    {
        $environment = trim(Str::limit((string) app()->environment(), 40, ''));

        return $environment !== '' ? $environment : 'unknown';
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
