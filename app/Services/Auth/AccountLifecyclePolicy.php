<?php

namespace App\Services\Auth;

use App\Models\User;

class AccountLifecyclePolicy
{
    public function assertExternalIdentityMutationAllowed(User $actor, User $target): void
    {
        if ($target->identity_purged_at !== null) {
            $this->deny('identity_purged', 'identity', 'A purged identity cannot be linked to an external provider.');
        }
        if ($target->is_protected) {
            $this->deny('protected_account', 'identity', 'Protected-account identity links require the sealed break-glass procedure.');
        }
        if ($actor->is($target)) {
            $this->deny('self_identity_mutation', 'identity', 'A second identity administrator must change your external identity link.');
        }
    }

    public function assertIdentityPurgeAllowed(User $actor, User $target): void
    {
        if ($target->identity_purged_at !== null) {
            $this->deny('identity_already_purged', 'user', 'This account identity has already been purged.');
        }
        if ($target->is_protected) {
            $this->deny('protected_account', 'user', 'Protected accounts cannot use the ordinary exceptional-purge workflow.');
        }
        if ($actor->is($target)) {
            $this->deny('self_purge', 'user', 'You cannot request or execute your own identity purge.');
        }
        if ((bool) $target->is_active) {
            $this->deny('deactivation_required', 'user', 'Deactivate and revoke the account before requesting an identity purge.');
        }
    }

    public function assertUpdateAllowed(
        User $actor,
        User $target,
        string $newRole,
        bool $newActive,
        bool $credentialsChanging,
        bool $identityChanging,
    ): void {
        if ($target->identity_purged_at !== null) {
            $this->deny('identity_purged', 'user', 'A purged identity is immutable and cannot be reactivated or edited.');
        }

        $roleChanging = (string) $target->role !== $newRole;
        $activeChanging = (bool) $target->is_active !== $newActive;

        if ($target->is_protected
            && ($roleChanging || $activeChanging || $identityChanging || ($credentialsChanging && ! $actor->is($target)))) {
            $this->deny('protected_account', 'user', 'This protected account cannot be changed through routine administration.');
        }

        if ($actor->is($target) && ! $newActive) {
            $this->deny('self_deactivation', 'is_active', 'You cannot deactivate your own account.');
        }

        $currentlyPrivileged = (bool) $target->is_active && $target->isAdministrator();
        $willRemainPrivileged = $newActive && $this->willBeAdministrator($target, $newRole);

        if ($actor->is($target) && $currentlyPrivileged && ! $willRemainPrivileged) {
            $this->deny('self_demotion', 'role', 'You cannot remove your own administration access.');
        }

        if ($currentlyPrivileged && ! $willRemainPrivileged && ! $this->anotherActiveAdministratorExists($target)) {
            $this->deny('last_administrator', 'role', 'The final active administrator cannot be demoted or deactivated.');
        }
    }

    public function denyHardDelete(): never
    {
        $this->deny('hard_delete_disabled', 'user', 'Hard deletion is disabled. Deactivate the account to preserve accountability history.');
    }

    private function willBeAdministrator(User $target, string $newRole): bool
    {
        $canonical = str_replace([' ', '-'], '_', strtolower(trim($newRole)));

        return in_array($canonical, ['admin', 'super_admin'], true)
            || $target->hasRole(['admin', 'super-admin', 'super_admin']);
    }

    private function anotherActiveAdministratorExists(User $target): bool
    {
        return User::query()
            ->where($target->getKeyName(), '!=', $target->getKey())
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereRaw(
                    "replace(replace(lower(trim(coalesce(role, ''))), '-', '_'), ' ', '_') in (?, ?)",
                    ['admin', 'super_admin'],
                )->orWhereHas('roles', fn ($roles) => $roles->whereIn('name', [
                    'admin', 'super-admin', 'super_admin',
                ]));
            })
            ->lockForUpdate()
            ->get(['id'])
            ->isNotEmpty();
    }

    private function deny(string $reason, string $field, string $message): never
    {
        throw new AccountLifecycleViolation($reason, $field, $message);
    }
}
