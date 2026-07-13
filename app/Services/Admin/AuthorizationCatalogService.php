<?php

namespace App\Services\Admin;

use App\Authorization\Capability;
use App\Models\User;
use App\Services\Authorization\RoleCapabilityService;
use Illuminate\Support\Str;

final class AuthorizationCatalogService
{
    public function __construct(private readonly RoleCapabilityService $authorization) {}

    /** @return array<string, mixed> */
    public function catalogFor(User $user): array
    {
        /** @var array<string, list<string>> $profiles */
        $profiles = config('authorization.role_capabilities', []);
        /** @var array<string, list<string>> $domainGroups */
        $domainGroups = config('authorization.capability_domains', []);
        $globalOnly = config('authorization.global_only_capabilities', []);
        $workforceScoped = config('authorization.workforce_scoped_capabilities', []);
        $globalRoles = config('authorization.global_scope_roles', []);

        $roleRows = collect($profiles)
            ->map(function (array $capabilities, string $role) use ($globalRoles): array {
                $unique = collect($capabilities)->unique()->sort()->values()->all();

                return [
                    'role' => $role,
                    'label' => Str::headline($role),
                    'capabilities' => $unique,
                    'capabilityCount' => count($unique),
                    'globalScope' => in_array($role, $globalRoles, true),
                ];
            })
            ->sortBy('label')
            ->values();

        $capabilityRows = collect(Capability::cases())
            ->map(function (Capability $capability) use ($domainGroups, $globalOnly, $workforceScoped, $roleRows): array {
                $domain = collect($domainGroups)->search(
                    fn (array $members): bool => in_array($capability->value, $members, true),
                );

                $scopeMode = match (true) {
                    in_array($capability->value, $globalOnly, true) => 'global_only',
                    in_array($capability->value, $workforceScoped, true) => 'facility_or_workforce',
                    default => 'resource_scoped',
                };

                return [
                    'capability' => $capability->value,
                    'label' => Str::headline($capability->value),
                    'domain' => is_string($domain) ? $domain : 'Unclassified',
                    'scopeMode' => $scopeMode,
                    'assignedRoles' => $roleRows
                        ->filter(fn (array $role): bool => in_array($capability->value, $role['capabilities'], true))
                        ->pluck('role')
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy([['domain', 'asc'], ['label', 'asc']])
            ->values();

        $effectiveRoles = $this->authorization->effectiveRoleIds($user);
        $effectiveCapabilities = collect($this->authorization->effectiveCapabilities($user))
            ->map(fn (Capability $capability): string => $capability->value)
            ->values()
            ->all();

        return [
            'generatedAt' => now()->toIso8601String(),
            'sourceOfTruth' => 'config/authorization.php + App\\Authorization\\Capability',
            'roles' => $roleRows->all(),
            'capabilities' => $capabilityRows->all(),
            'aliases' => collect(config('authorization.role_aliases', []))
                ->map(fn (string $canonical, string $alias): array => compact('alias', 'canonical'))
                ->values()
                ->all(),
            'globalScopeRoles' => array_values($globalRoles),
            'currentPrincipal' => [
                'userId' => (int) $user->getKey(),
                'roles' => $effectiveRoles,
                'capabilities' => $effectiveCapabilities,
                'globalScope' => collect($effectiveRoles)->intersect($globalRoles)->isNotEmpty(),
            ],
            'counts' => [
                'roles' => $roleRows->count(),
                'capabilities' => $capabilityRows->count(),
                'globalScopeRoles' => count($globalRoles),
                'unclassifiedCapabilities' => $capabilityRows->where('domain', 'Unclassified')->count(),
            ],
        ];
    }
}
