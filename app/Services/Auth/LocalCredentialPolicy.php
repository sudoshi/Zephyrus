<?php

namespace App\Services\Auth;

use App\Models\User;

/**
 * SSO-only credential policy. When local password authentication is disabled
 * (`LOCAL_AUTH_ENABLED=false`, the same declaration surfaced on
 * /admin/auth-providers), administrators may not mint or rotate local
 * passwords for ordinary accounts: the IdP is the credential authority.
 * Break-glass (protected) accounts remain exempt so sealed emergency access
 * survives an IdP outage. The check fails closed in the service layer; UI
 * affordances are never the boundary.
 */
class LocalCredentialPolicy
{
    public function ssoOnly(): bool
    {
        return ! (bool) config('auth-drivers.local.enabled', true);
    }

    /**
     * @param  User|null  $target  null when the account does not exist yet
     *
     * @throws AccountLifecycleViolation
     */
    public function assertLocalPasswordAllowed(?User $target): void
    {
        if (! $this->ssoOnly()) {
            return;
        }

        if ($target !== null && (bool) $target->is_protected) {
            return;
        }

        throw new AccountLifecycleViolation(
            'sso_only_policy',
            'password',
            'Local passwords are disabled by the SSO-only authentication policy. '
            .'Provision this account through the identity provider; only sealed break-glass accounts may hold a local credential.',
        );
    }
}
