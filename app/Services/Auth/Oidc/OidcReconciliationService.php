<?php

namespace App\Services\Auth\Oidc;

use App\Models\Auth\OidcEmailAlias;
use App\Models\Auth\UserExternalIdentity;
use App\Models\User;
use App\Services\Auth\Oidc\Exceptions\OidcAccessDeniedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OidcReconciliationService
{
    private const PROVIDER = 'authentik';

    /**
     * @param  list<string>  $allowedGroups
     * @param  list<string>  $adminGroups
     */
    public function __construct(
        private readonly array $allowedGroups = ['Zephyrus Users'],
        private readonly array $adminGroups = ['Zephyrus Admins'],
        private readonly ?ExternalIdentityEventRecorder $events = null,
    ) {}

    /** @return array{user: User, reason: string} */
    public function reconcile(ValidatedClaims $claims): array
    {
        return DB::transaction(function () use ($claims): array {
            // Group membership is evaluated on every login, including already
            // linked subjects and aliases. Removing a user from all approved
            // IdP groups therefore removes access at the next authentication.
            if (! $this->inAnyGroup($claims->groups, [...$this->allowedGroups, ...$this->adminGroups])) {
                throw new OidcAccessDeniedException('not_in_allowed_group', 'User is not in an allowed Zephyrus group.');
            }

            $identity = UserExternalIdentity::query()
                ->where('provider', self::PROVIDER)
                ->where('provider_subject', $claims->sub)
                ->first();

            if ($identity !== null) {
                if (! $identity->is_active) {
                    throw new OidcAccessDeniedException(
                        'identity_unlinked',
                        'This external identity was administratively unlinked and requires an approved relink.',
                    );
                }
                $user = $identity->user;
                if ($user === null) {
                    throw new OidcAccessDeniedException('linked_user_missing', 'Linked Zephyrus user no longer exists.');
                }
                $this->assertActive($user);

                return ['user' => $user, 'reason' => 'linked_by_sub'];
            }

            $canonical = strtolower($claims->email);

            $user = User::query()->whereRaw('lower(email) = ?', [$canonical])->first();
            if ($user !== null) {
                $this->assertActive($user);
                $this->link($user->id, $claims);
                $this->markSynced($user);

                return ['user' => $user, 'reason' => 'linked_by_email'];
            }

            $aliased = OidcEmailAlias::canonicalFor($canonical);
            if ($aliased !== null) {
                $user = User::query()->whereRaw('lower(email) = ?', [strtolower($aliased)])->first();
                if ($user !== null) {
                    $this->assertActive($user);
                    $this->link($user->id, $claims);
                    $this->markSynced($user);

                    return ['user' => $user, 'reason' => 'linked_by_alias'];
                }
            }

            $role = $this->inAnyGroup($claims->groups, $this->adminGroups) ? 'admin' : 'user';

            $user = User::query()->create([
                'name' => $claims->name,
                'email' => $canonical,
                'username' => $this->uniqueUsername($canonical),
                'password' => bcrypt(Str::random(64)),
                'must_change_password' => false,
                'role' => $role,
                'is_active' => true,
                'provisioning_state' => 'jit',
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->link($user->id, $claims);

            return ['user' => $user, 'reason' => 'created_jit'];
        });
    }

    /** A pre-existing local/invited account is now bound to the IdP subject. */
    private function markSynced(User $user): void
    {
        if (in_array($user->provisioning_state, ['local', 'invited', null], true)) {
            $user->forceFill(['provisioning_state' => 'synced'])->save();
        }
    }

    private function assertActive(User $user): void
    {
        if (! $user->is_active) {
            throw new OidcAccessDeniedException('account_disabled', 'Linked Zephyrus user is disabled.');
        }
    }

    private function link(int $userId, ValidatedClaims $claims): void
    {
        $identity = UserExternalIdentity::query()->create([
            'user_id' => $userId,
            'provider' => self::PROVIDER,
            'provider_subject' => $claims->sub,
            'provider_email_at_link' => $claims->email,
            'linked_at' => now(),
            'is_active' => true,
        ]);

        ($this->events ?? app(ExternalIdentityEventRecorder::class))->record(
            $identity,
            'linked',
            null,
            'oidc_reconciliation',
            ['link_method' => 'validated_oidc_claims'],
        );
    }

    /** Mirror RegisteredUserController username derivation. */
    private function uniqueUsername(string $email): string
    {
        $base = preg_replace('/[^a-z0-9_-]/', '', strtolower(explode('@', $email)[0])) ?: 'user';
        $username = $base;
        $i = 1;
        while (User::query()->where('username', $username)->exists()) {
            $username = $base.$i;
            $i++;
        }

        return $username;
    }

    /**
     * @param  list<string>  $tokenGroups
     * @param  list<string>  $needles
     */
    private function inAnyGroup(array $tokenGroups, array $needles): bool
    {
        foreach ($needles as $g) {
            if (in_array($g, $tokenGroups, true)) {
                return true;
            }
        }

        return false;
    }
}
