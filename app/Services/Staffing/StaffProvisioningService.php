<?php

namespace App\Services\Staffing;

use App\Models\Org\StaffMember;
use App\Models\Reference\StaffRole;
use App\Models\User;
use InvalidArgumentException;

/**
 * Phase 7: the ONLY class that writes prod.users, and only additively. It obeys
 * .claude/rules/auth-system.md — it can never touch the protected auth flow.
 *
 * Guardrails (locked by StaffingAuthInvariantsTest, written first):
 *   - Allow-list of settable columns = {workflow_preference, is_active}. ANY other
 *     key — password, must_change_password, email, username, role, name, ... — throws.
 *   - role is deliberately NOT auto-writable: a staffing sync must never escalate an
 *     account's auth role (a privilege-escalation vector if a source were compromised).
 *     The role recommendation (staff_roles.metadata.app_role) is *surfaced* for an
 *     explicit admin action, never applied here. This is stricter than the plan's
 *     literal allow-list, by design.
 *   - The admin@acumenus.net superuser is a hard no-op short-circuit.
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§6.1, §11)
 */
class StaffProvisioningService
{
    /** The only prod.users columns an automated staffing sync may write. */
    public const SETTABLE = ['workflow_preference', 'is_active'];

    public const PROTECTED_SUPERUSER_EMAIL = 'admin@acumenus.net';

    /**
     * Additively provision the linked account for a committed assignment's role:
     * set the operational workflow (from the role's default_workflow) and activate
     * operational access. Never writes role. No-op when no account is linked or the
     * account is the protected superuser.
     *
     * @return array<string, mixed>
     */
    public function provisionFromAssignment(StaffMember $member, StaffRole $role): array
    {
        if ($member->user_id === null) {
            return ['provisioned' => false, 'reason' => 'no_linked_account'];
        }

        $user = $member->user()->first();
        if ($user === null) {
            return ['provisioned' => false, 'reason' => 'no_linked_account'];
        }

        $recommendedRole = $role->metadata['app_role'] ?? null;

        if ($this->isProtectedSuperuser($user)) {
            return array_filter([
                'provisioned' => false,
                'reason' => 'protected_superuser',
                'recommended_app_role' => $recommendedRole,
            ], fn ($v): bool => $v !== null);
        }

        $attributes = ['is_active' => true];
        $workflow = $role->default_workflow;
        if ($workflow !== null && $workflow !== '' && $workflow !== 'none') {
            $attributes['workflow_preference'] = $workflow;
        }

        $applied = $this->applyOperationalAttributes($user, $attributes);

        return array_filter([
            'provisioned' => $applied !== [],
            'applied' => $applied,
            'recommended_app_role' => $recommendedRole,
        ], fn ($v): bool => $v !== null && $v !== []);
    }

    /**
     * The guarded low-level writer. Throws on any non-allow-listed column (before any
     * write, so a batch containing a forbidden key persists nothing). Skips the
     * protected superuser entirely. Returns the applied delta.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function applyOperationalAttributes(User $user, array $attributes): array
    {
        foreach (array_keys($attributes) as $key) {
            if (! in_array($key, self::SETTABLE, true)) {
                throw new InvalidArgumentException(
                    "StaffProvisioningService may not write prod.users.{$key}; allow-list is ["
                    .implode(', ', self::SETTABLE).'].'
                );
            }
        }

        if ($this->isProtectedSuperuser($user)) {
            return [];
        }

        $applied = [];
        foreach (self::SETTABLE as $key) {
            if (array_key_exists($key, $attributes)) {
                $user->{$key} = $attributes[$key];
                $applied[$key] = $attributes[$key];
            }
        }

        if ($applied !== []) {
            $user->save();
        }

        return $applied;
    }

    public function isProtectedSuperuser(User $user): bool
    {
        return strtolower((string) $user->email) === self::PROTECTED_SUPERUSER_EMAIL;
    }
}
