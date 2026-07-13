<?php

namespace App\Services\Authorization;

use App\Authorization\AuthorizationDecision;
use App\Authorization\AuthorizationScope;
use App\Authorization\Capability;
use App\Models\Auth\UserAccessScope;
use App\Models\Org\Facility;
use App\Models\Org\StaffAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * The single role-to-capability and scope decision point for web, API, mobile,
 * workforce, and externally provisioned identities.
 *
 * Fail-closed rules:
 * - inactive users have no capability;
 * - unknown roles and permission names grant nothing;
 * - a scoped decision requires a real organization/facility;
 * - workforce membership can scope operational work only, never administration;
 * - database/configuration errors never become an allow decision.
 */
final class RoleCapabilityService
{
    /** @return list<string> */
    public function effectiveRoleIds(User $user): array
    {
        $roles = collect([$this->canonicalRole($user->role)]);

        try {
            $roles = $roles->merge($user->roles()->pluck('name')->map($this->canonicalRole(...)));
        } catch (QueryException) {
            // A missing/unavailable role store is not permission to proceed.
        }

        try {
            $workforceRoles = StaffAssignment::query()
                ->where('is_active', true)
                ->whereHas('staffMember', fn (Builder $query) => $query
                    ->where('user_id', $user->getKey())
                    ->where('is_active', true))
                ->where(function (Builder $query): void {
                    $query->whereNull('effective_start')->orWhere('effective_start', '<=', today());
                })
                ->where(function (Builder $query): void {
                    $query->whereNull('effective_end')->orWhere('effective_end', '>=', today());
                })
                ->pluck('role_code')
                ->map($this->canonicalRole(...));
            $roles = $roles->merge($workforceRoles);
        } catch (QueryException) {
            // Workforce data is additive; its absence may not elevate access.
        }

        return $roles
            ->filter(fn (?string $role): bool => $role !== null && $role !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @return list<Capability> */
    public function effectiveCapabilities(User $user): array
    {
        if (! (bool) $user->is_active) {
            return [];
        }

        /** @var array<string, list<string>> $profiles */
        $profiles = config('authorization.role_capabilities', []);
        $capabilityNames = collect($this->effectiveRoleIds($user))
            ->flatMap(fn (string $role): array => $profiles[$role] ?? []);

        try {
            $permissionCapabilities = $user->getAllPermissions()
                ->pluck('name')
                ->map(fn (string $permission): ?string => $this->permissionCapability($permission))
                ->filter();
            $capabilityNames = $capabilityNames->merge($permissionCapabilities);
        } catch (QueryException) {
            // Direct/role permission storage unavailable means no permissions.
        }

        return $capabilityNames
            ->unique()
            ->map(fn (string $capability): Capability => Capability::from($capability))
            ->sortBy(fn (Capability $capability): string => $capability->value)
            ->values()
            ->all();
    }

    public function allows(
        User $user,
        Capability|string $capability,
        ?AuthorizationScope $scope = null,
    ): bool {
        return $this->decide($user, $capability, $scope)->allowed;
    }

    public function decide(
        User $user,
        Capability|string $capability,
        ?AuthorizationScope $scope = null,
    ): AuthorizationDecision {
        $capability = Capability::fromName($capability);
        $roles = $this->effectiveRoleIds($user);

        if (! (bool) $user->is_active) {
            return new AuthorizationDecision(false, 'account_inactive', $capability, $scope, $roles);
        }

        if (! $this->capabilityCollection($user)->contains($capability)) {
            return new AuthorizationDecision(false, 'capability_missing', $capability, $scope, $roles);
        }

        if ($scope === null) {
            return new AuthorizationDecision(true, 'capability_granted', $capability, null, $roles);
        }

        $facility = $this->resolveFacility($scope);
        if (($scope->facilityId !== null || $scope->facilityKey !== null) && $facility === null) {
            return new AuthorizationDecision(false, 'facility_not_found', $capability, $scope, $roles);
        }

        $organizationId = $scope->organizationId ?? $facility?->organization_id;
        if ($organizationId === null) {
            return new AuthorizationDecision(false, 'organization_not_found', $capability, $scope, $roles);
        }

        if ($facility !== null && $scope->organizationId !== null
            && (int) $facility->organization_id !== $scope->organizationId) {
            return new AuthorizationDecision(false, 'scope_mismatch', $capability, $scope, $roles);
        }

        if ($this->hasGlobalScope($roles)) {
            return new AuthorizationDecision(true, 'global_scope_role', $capability, $scope, $roles);
        }

        if ($this->hasExplicitScope($user, $organizationId, $facility?->facility_id)) {
            return new AuthorizationDecision(true, 'explicit_access_scope', $capability, $scope, $roles);
        }

        if ($facility !== null && $this->workforceMayScope($capability)
            && $this->hasWorkforceFacilityScope($user, (string) $facility->facility_key)) {
            return new AuthorizationDecision(true, 'active_workforce_assignment', $capability, $scope, $roles);
        }

        return new AuthorizationDecision(false, 'scope_missing', $capability, $scope, $roles);
    }

    /** @return list<string> */
    public function mobileTokenAbilities(User $user): array
    {
        if (! (bool) $user->is_active) {
            return [];
        }

        $roles = $this->effectiveRoleIds($user);
        if (in_array('super_admin', $roles, true)) {
            return ['*'];
        }

        /** @var array<string, string> $abilityMap */
        $abilityMap = config('authorization.mobile_ability_map', []);
        $capabilities = $this->capabilityCollection($user)->map->value;
        $abilities = collect($abilityMap)
            ->filter(fn (string $ability, string $capability): bool => $capabilities->contains($capability))
            ->values();

        if ($user->workflow_preference) {
            $abilities->push('workflow:'.$user->workflow_preference);
        }

        return $abilities->unique()->values()->all();
    }

    public function canonicalRole(?string $role): ?string
    {
        $role = trim(strtolower((string) $role));

        if ($role === '') {
            return null;
        }

        $canonical = str_replace([' ', '-'], '_', $role);

        return config('authorization.role_aliases.'.$canonical, $canonical);
    }

    /** @return Collection<int, Capability> */
    private function capabilityCollection(User $user): Collection
    {
        return collect($this->effectiveCapabilities($user));
    }

    private function permissionCapability(string $permission): ?string
    {
        $permission = str_starts_with($permission, 'capability:')
            ? substr($permission, strlen('capability:'))
            : $permission;

        return collect(Capability::cases())->first(
            fn (Capability $capability): bool => $capability->value === $permission
        )?->value;
    }

    private function resolveFacility(AuthorizationScope $scope): ?Facility
    {
        try {
            return Facility::query()
                ->when($scope->facilityId !== null, fn (Builder $query) => $query->whereKey($scope->facilityId))
                ->when($scope->facilityKey !== null, fn (Builder $query) => $query->where('facility_key', $scope->facilityKey))
                ->first();
        } catch (QueryException) {
            return null;
        }
    }

    /** @param list<string> $roles */
    private function hasGlobalScope(array $roles): bool
    {
        return collect(config('authorization.global_scope_roles', []))->intersect($roles)->isNotEmpty();
    }

    private function hasExplicitScope(User $user, int $organizationId, ?int $facilityId): bool
    {
        try {
            return UserAccessScope::query()
                ->effective()
                ->where('user_id', $user->getKey())
                ->where(function (Builder $query) use ($organizationId, $facilityId): void {
                    $query->where(function (Builder $query) use ($organizationId): void {
                        $query->where('organization_id', $organizationId)->whereNull('facility_id');
                    });

                    if ($facilityId !== null) {
                        $query->orWhere('facility_id', $facilityId);
                    }
                })
                ->exists();
        } catch (QueryException) {
            return false;
        }
    }

    private function workforceMayScope(Capability $capability): bool
    {
        return in_array($capability->value, config('authorization.workforce_scoped_capabilities', []), true);
    }

    private function hasWorkforceFacilityScope(User $user, string $facilityKey): bool
    {
        try {
            return StaffAssignment::query()
                ->where('facility_key', $facilityKey)
                ->where('is_active', true)
                ->whereHas('staffMember', fn (Builder $query) => $query
                    ->where('user_id', $user->getKey())
                    ->where('is_active', true))
                ->where(function (Builder $query): void {
                    $query->whereNull('effective_start')->orWhere('effective_start', '<=', today());
                })
                ->where(function (Builder $query): void {
                    $query->whereNull('effective_end')->orWhere('effective_end', '>=', today());
                })
                ->exists();
        } catch (QueryException) {
            return false;
        }
    }
}
