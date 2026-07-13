<?php

namespace App\Services\Identity;

use App\Models\Audit\UserEvent;
use App\Models\Auth\UserExternalIdentity;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The one read model for account lifecycle evidence: identity source, external
 * subject, group-reconciliation state, IdP MFA assurance, last login, last
 * meaningful activity, and live session/token counts. The access-review
 * entitlement snapshot and the Users surfaces both read the same facts here
 * instead of duplicating audit-ledger queries.
 */
class UserLifecycleReadService
{
    private const LOGIN_ACTIONS = ['auth.login', 'mobile.auth.token_exchange'];

    /**
     * @param  Collection<int, User>  $users
     * @return array<int, array<string, mixed>> keyed by user id
     */
    public function summaries(Collection $users): array
    {
        $ids = $users->map(fn (User $user): int => (int) $user->getKey())->values()->all();
        if ($ids === []) {
            return [];
        }

        $lastLogins = $this->latestByActor(
            UserEvent::query()->whereIn('action', self::LOGIN_ACTIONS)->where('outcome', 'success'),
            $ids,
        );
        $lastOidcLogins = $this->latestByActor(
            UserEvent::query()
                ->whereIn('action', self::LOGIN_ACTIONS)
                ->where('outcome', 'success')
                ->where('auth_method', 'oidc'),
            $ids,
        );
        $lastActivity = $this->latestByActor(
            UserEvent::query()->where('outcome', 'success')->where('category', '!=', 'authentication'),
            $ids,
        );
        $lastIdpMfa = $this->latestByActor(
            UserEvent::query()
                ->where('action', 'security.step_up.completed')
                ->where('outcome', 'success')
                ->whereRaw("metadata->>'step_up_method' = 'oidc_mfa'"),
            $ids,
        );

        $sessionCounts = [];
        if (Schema::hasTable('prod.sessions')) {
            $sessionCounts = DB::table('prod.sessions')
                ->whereIn('user_id', $ids)
                ->groupBy('user_id')
                ->selectRaw('user_id, count(*) as session_count')
                ->pluck('session_count', 'user_id')
                ->map(fn (mixed $count): int => (int) $count)
                ->all();
        }

        $tokenCounts = PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $ids)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->groupBy('tokenable_id')
            ->selectRaw('tokenable_id, count(*) as token_count')
            ->pluck('token_count', 'tokenable_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $identities = UserExternalIdentity::query()
            ->whereIn('user_id', $ids)
            ->orderBy('provider')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id');

        $summaries = [];
        foreach ($users as $user) {
            $id = (int) $user->getKey();
            $userIdentities = $identities->get($id, collect());
            $activeIdentity = $userIdentities->firstWhere('is_active', true);

            $summaries[$id] = [
                'identity_source' => $activeIdentity?->provider ?? 'local',
                'provisioning_state' => (string) ($user->provisioning_state ?? 'local'),
                'external_subjects' => $userIdentities->map(fn (UserExternalIdentity $identity): array => [
                    'id' => (int) $identity->getKey(),
                    'provider' => $identity->provider,
                    'subject_fingerprint' => $this->subjectFingerprint($identity),
                    'is_active' => (bool) $identity->is_active,
                    'linked_at' => $identity->linked_at?->toIso8601String(),
                ])->values()->all(),
                'group_reconciliation_state' => $this->reconciliationState(
                    $userIdentities,
                    $lastOidcLogins[$id] ?? null,
                ),
                'mfa_assurance' => isset($lastIdpMfa[$id])
                    ? ['method' => 'idp_mfa', 'verified_at' => $lastIdpMfa[$id]->toIso8601String()]
                    : null,
                'last_login_at' => ($lastLogins[$id] ?? null)?->toIso8601String(),
                'last_meaningful_activity_at' => ($lastActivity[$id] ?? null)?->toIso8601String(),
                'active_session_count' => $sessionCounts[$id] ?? 0,
                'active_token_count' => $tokenCounts[$id] ?? 0,
            ];
        }

        return $summaries;
    }

    /** @return array<string, mixed> */
    public function summary(User $user): array
    {
        return $this->summaries(collect([$user]))[(int) $user->getKey()];
    }

    /** Same fingerprint the Users/Edit surface has always shown. */
    public function subjectFingerprint(UserExternalIdentity $identity): string
    {
        return substr(hash('sha256', $identity->provider.':'.$identity->provider_subject), 0, 16);
    }

    /**
     * Group membership is re-evaluated by the IdP reconciliation on every
     * login, so an active link is `reconciled` once a validated OIDC login
     * has happened at or after the (re)link moment.
     *
     * @param  Collection<int, UserExternalIdentity>  $identities
     */
    private function reconciliationState(Collection $identities, ?\Carbon\CarbonImmutable $lastOidcLoginAt): string
    {
        if ($identities->isEmpty()) {
            return 'not_applicable';
        }

        $active = $identities->filter(fn (UserExternalIdentity $identity): bool => (bool) $identity->is_active);
        if ($active->isEmpty()) {
            return 'unlinked';
        }

        if ($lastOidcLoginAt === null) {
            return 'awaiting_login';
        }

        $linkedAt = $active
            ->map(fn (UserExternalIdentity $identity) => $identity->relinked_at ?? $identity->linked_at)
            ->filter()
            ->max();

        return $linkedAt === null || $lastOidcLoginAt->greaterThanOrEqualTo($linkedAt)
            ? 'reconciled'
            : 'awaiting_login';
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<UserEvent>  $query
     * @param  list<int>  $ids
     * @return array<int, \Carbon\CarbonImmutable> latest occurred_at keyed by actor id
     */
    private function latestByActor($query, array $ids): array
    {
        return $query
            ->whereIn('actor_user_id', $ids)
            ->groupBy('actor_user_id')
            ->selectRaw('actor_user_id, max(occurred_at) as latest_at')
            ->pluck('latest_at', 'actor_user_id')
            ->map(fn (mixed $value): \Carbon\CarbonImmutable => \Carbon\CarbonImmutable::parse((string) $value))
            ->all();
    }
}
