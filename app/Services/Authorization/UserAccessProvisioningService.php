<?php

namespace App\Services\Authorization;

use App\Authorization\Capability;
use App\Models\Auth\UserAccessScope;
use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\User;
use App\Services\Audit\UserAuditRecorder;
use App\Services\Auth\AccountLifecycleViolation;
use App\Services\Auth\AccountSessionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * Grant/revoke authority for the two canonical assignment stores the
 * RoleCapabilityService already consumes: effective-dated organization/
 * facility access scopes and direct `capability:*` permission grants.
 * Protected, purged, and self-targeted mutations fail closed; every change
 * is recorded in the append-only user audit ledger inside the same
 * transaction as the mutation.
 */
class UserAccessProvisioningService
{
    public function __construct(
        private readonly AccountSessionService $sessions,
        private readonly UserAuditRecorder $audit,
    ) {}

    public function grantScope(
        User $actor,
        User $target,
        ?int $organizationId,
        ?int $facilityId,
        string $reason,
        ?CarbonImmutable $validFrom,
        ?CarbonImmutable $validUntil,
        Request $request,
    ): UserAccessScope {
        $this->assertAssignable($actor, $target);

        if (($organizationId === null) === ($facilityId === null)) {
            $this->deny('scope_boundary_invalid', 'scope', 'Grant exactly one boundary: an organization or a facility.');
        }
        if ($organizationId !== null && ! Organization::query()->whereKey($organizationId)->exists()) {
            $this->deny('organization_not_found', 'organization_id', 'The selected organization does not exist.');
        }
        if ($facilityId !== null
            && ! Facility::query()->whereKey($facilityId)->where('is_active', true)->exists()) {
            $this->deny('facility_not_found', 'facility_id', 'The selected facility does not exist or is inactive.');
        }

        $duplicate = UserAccessScope::query()
            ->effective()
            ->where('user_id', $target->getKey())
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->when($facilityId !== null, fn ($query) => $query->where('facility_id', $facilityId))
            ->exists();
        if ($duplicate) {
            $this->deny('scope_already_granted', 'scope', 'An equivalent effective scope already exists for this account.');
        }

        return DB::transaction(function () use (
            $actor,
            $target,
            $organizationId,
            $facilityId,
            $reason,
            $validFrom,
            $validUntil,
            $request,
        ): UserAccessScope {
            $scope = UserAccessScope::query()->create([
                'user_id' => $target->getKey(),
                'organization_id' => $organizationId,
                'facility_id' => $facilityId,
                'granted_by_user_id' => $actor->getKey(),
                'grant_reason' => $reason,
                'valid_from' => $validFrom ?? now(),
                'valid_until' => $validUntil,
            ]);

            $this->audit->record('administration.user.access_scope_granted', 'administration', 'success', [
                'request' => $request,
                'actor' => $actor,
                'reason' => 'access_scope_granted',
                'target_type' => 'user',
                'target_id' => $target->getKey(),
                'metadata' => [
                    'scope_id' => (int) $scope->getKey(),
                    'organization_id' => $organizationId,
                    'facility_id' => $facilityId,
                    'valid_from' => $scope->valid_from?->toIso8601String(),
                    'valid_until' => $scope->valid_until?->toIso8601String(),
                ],
            ]);

            return $scope;
        });
    }

    public function revokeScope(
        User $actor,
        User $target,
        UserAccessScope $scope,
        string $reason,
        Request $request,
    ): void {
        $this->assertAssignable($actor, $target);

        if ((int) $scope->user_id !== (int) $target->getKey()) {
            $this->deny('scope_mismatch', 'scope', 'This access scope does not belong to the selected account.');
        }
        if ($scope->revoked_at !== null) {
            $this->deny('scope_already_revoked', 'scope', 'This access scope is already revoked.');
        }

        DB::transaction(function () use ($actor, $target, $scope, $reason, $request): void {
            $scope->forceFill([
                'revoked_at' => now(),
                'revoked_by_user_id' => $actor->getKey(),
                'revocation_reason' => $reason,
            ])->save();

            // Scoped decisions consult the scope table live on every request,
            // so no session teardown is required for the boundary to close.
            $this->audit->record('administration.user.access_scope_revoked', 'administration', 'success', [
                'request' => $request,
                'actor' => $actor,
                'reason' => 'access_scope_revoked',
                'target_type' => 'user',
                'target_id' => $target->getKey(),
                'metadata' => [
                    'scope_id' => (int) $scope->getKey(),
                    'organization_id' => $scope->organization_id,
                    'facility_id' => $scope->facility_id,
                ],
            ]);
        });
    }

    public function grantCapability(
        User $actor,
        User $target,
        Capability $capability,
        Request $request,
    ): void {
        $this->assertAssignable($actor, $target);

        $permission = 'capability:'.$capability->value;
        if ($target->permissions()->where('name', $permission)->exists()) {
            $this->deny('capability_already_granted', 'capability', 'This capability is already directly granted.');
        }

        DB::transaction(function () use ($actor, $target, $capability, $permission, $request): void {
            Permission::findOrCreate($permission, 'web');
            $target->givePermissionTo($permission);

            $this->audit->record('administration.user.capability_granted', 'administration', 'success', [
                'request' => $request,
                'actor' => $actor,
                'reason' => 'capability_granted',
                'target_type' => 'user',
                'target_id' => $target->getKey(),
                'metadata' => ['capability' => $capability->value],
            ]);
        });
    }

    public function revokeCapability(
        User $actor,
        User $target,
        Capability $capability,
        Request $request,
    ): void {
        $this->assertAssignable($actor, $target);

        $permission = 'capability:'.$capability->value;
        if (! $target->permissions()->where('name', $permission)->exists()) {
            $this->deny(
                'capability_not_directly_granted',
                'capability',
                'This capability is not a direct grant; role-profile capabilities are removed by changing the role.',
            );
        }

        DB::transaction(function () use ($actor, $target, $capability, $permission, $request): void {
            $target->revokePermissionTo($permission);

            // Mobile/API tokens carry abilities minted at issuance, so an
            // access reduction must also retire previously issued credentials.
            $this->sessions->revoke($target, $request, 'capability_revoked');

            $this->audit->record('administration.user.capability_revoked', 'administration', 'success', [
                'request' => $request,
                'actor' => $actor,
                'reason' => 'capability_revoked',
                'target_type' => 'user',
                'target_id' => $target->getKey(),
                'metadata' => ['capability' => $capability->value],
            ]);
        });
    }

    private function assertAssignable(User $actor, User $target): void
    {
        if ($target->identity_purged_at !== null) {
            $this->deny('identity_purged', 'user', 'A purged identity cannot receive or lose assignments.');
        }
        if ((bool) $target->is_protected) {
            $this->deny('protected_account', 'user', 'Protected-account assignments require the sealed break-glass procedure.');
        }
        if ($actor->is($target)) {
            $this->deny('self_privilege_mutation', 'user', 'A second administrator must change your own access assignments.');
        }
    }

    private function deny(string $reason, string $field, string $message): never
    {
        throw new AccountLifecycleViolation($reason, $field, $message);
    }
}
