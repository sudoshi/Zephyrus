<?php

namespace App\Services\Authorization;

use App\Authorization\AdminScope;
use App\Models\Auth\UserAccessScope;
use App\Models\Governance\GovernedChangeRequest;
use App\Models\Integration\Source;
use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Owns the explicit Admin enterprise-selection contract. Session state is only
 * a hint: every read re-resolves the hierarchy and current effective grant.
 */
final class AdminScopeService
{
    public const SESSION_KEY = 'admin.active_scope.v1';

    public const REQUEST_ATTRIBUTE = '_zephyrus_admin_scope';

    public function __construct(private readonly RoleCapabilityService $authorization) {}

    /** @return array<string, mixed> */
    public function clientContract(Request $request): array
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->emptyContract();
        }

        $catalog = $this->catalog($user);
        $current = $this->current($request);

        return [
            ...$catalog,
            'current' => $current?->toClient(),
            'query' => $current?->query() ?? [],
            'updateUrl' => route('admin.active-scope.update'),
            'clearUrl' => route('admin.active-scope.destroy'),
        ];
    }

    /** @return array{organizations: list<array<string, mixed>>, facilities: list<array<string, mixed>>, sources: list<array<string, mixed>>} */
    public function catalog(User $user): array
    {
        try {
            [$organizationIds, $facilityIds] = $this->accessibleBoundaryIds($user);
            $global = $this->hasGlobalScope($user);

            $organizations = Organization::query()
                ->when(! $global, fn (Builder $query) => $query->whereIn('organization_id', $organizationIds))
                ->orderBy('name')
                ->get(['organization_id', 'organization_key', 'name']);

            $facilities = Facility::query()
                ->where('is_active', true)
                ->when(! $global, function (Builder $query) use ($organizationIds, $facilityIds): void {
                    $query->where(function (Builder $query) use ($organizationIds, $facilityIds): void {
                        if ($organizationIds->isNotEmpty()) {
                            $query->whereIn('organization_id', $organizationIds);
                        } else {
                            $query->whereRaw('false');
                        }
                        if ($facilityIds->isNotEmpty()) {
                            $query->orWhereIn('facility_id', $facilityIds);
                        }
                    });
                })
                ->orderBy('facility_name')
                ->get(['facility_id', 'organization_id', 'facility_key', 'facility_name']);

            $accessibleOrganizationIds = $organizations->pluck('organization_id');
            $accessibleFacilityIds = $facilities->pluck('facility_id');
            $sources = Source::query()
                ->whereNotNull('organization_id')
                ->whereNotNull('facility_id')
                ->whereIn('organization_id', $accessibleOrganizationIds)
                ->whereIn('facility_id', $accessibleFacilityIds)
                ->orderBy('source_name')
                ->get(['source_id', 'organization_id', 'facility_id', 'source_key', 'source_name']);

            return [
                'organizations' => $organizations->map(fn (Organization $organization): array => [
                    'id' => (int) $organization->organization_id,
                    'key' => (string) $organization->organization_key,
                    'name' => (string) $organization->name,
                ])->values()->all(),
                'facilities' => $facilities->map(fn (Facility $facility): array => [
                    'id' => (int) $facility->facility_id,
                    'organizationId' => (int) $facility->organization_id,
                    'key' => (string) $facility->facility_key,
                    'name' => (string) $facility->facility_name,
                ])->values()->all(),
                'sources' => $sources->map(fn (Source $source): array => [
                    'id' => (int) $source->source_id,
                    'organizationId' => (int) $source->organization_id,
                    'facilityId' => (int) $source->facility_id,
                    'key' => (string) $source->source_key,
                    'name' => (string) $source->source_name,
                ])->values()->all(),
            ];
        } catch (Throwable) {
            return [
                'organizations' => [],
                'facilities' => [],
                'sources' => [],
            ];
        }
    }

    public function select(
        Request $request,
        int $organizationId,
        ?int $facilityId = null,
        ?int $sourceId = null,
    ): AdminScope {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AdminScopeViolation('admin_scope_actor_missing', 'An authenticated user is required to select an Admin scope.');
        }

        $scope = $this->resolve(
            $user,
            $organizationId,
            $facilityId,
            $sourceId,
            (string) Str::uuid7(),
            CarbonImmutable::now(),
        );
        $request->session()->put(self::SESSION_KEY, $scope->toSession());
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $scope);

        return $scope;
    }

    public function current(Request $request): ?AdminScope
    {
        $cached = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if ($cached instanceof AdminScope) {
            return $cached;
        }

        $payload = $request->session()->get(self::SESSION_KEY);
        $user = $request->user();
        if (! is_array($payload) || ! $user instanceof User || (int) ($payload['user_id'] ?? 0) !== (int) $user->getKey()) {
            $this->clear($request);

            return null;
        }

        try {
            $scope = $this->resolve(
                $user,
                (int) ($payload['organization_id'] ?? 0),
                isset($payload['facility_id']) ? (int) $payload['facility_id'] : null,
                isset($payload['source_id']) ? (int) $payload['source_id'] : null,
                (string) ($payload['revision'] ?? ''),
                CarbonImmutable::parse((string) ($payload['selected_at'] ?? '')),
            );
        } catch (Throwable) {
            $this->clear($request);

            return null;
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $scope);

        return $scope;
    }

    public function clear(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
        $request->attributes->remove(self::REQUEST_ATTRIBUTE);
    }

    public function requireOrganization(Request $request): AdminScope
    {
        return $this->current($request) ?? throw new AdminScopeViolation(
            'admin_scope_required',
            'Select an organization before performing this action.',
        );
    }

    public function requireFacility(Request $request): AdminScope
    {
        $scope = $this->requireOrganization($request);
        if ($scope->facilityId === null) {
            throw new AdminScopeViolation('admin_facility_scope_required', 'Select a facility before performing this action.');
        }

        return $scope;
    }

    public function requireSource(Request $request, ?int $expectedSourceId = null): AdminScope
    {
        $scope = $this->requireFacility($request);
        if ($scope->sourceId === null) {
            throw new AdminScopeViolation('admin_source_scope_required', 'Select an integration source before performing this action.');
        }
        if ($expectedSourceId !== null && $scope->sourceId !== $expectedSourceId) {
            throw new AdminScopeViolation('admin_source_scope_mismatch', 'The selected source does not match the requested integration source.');
        }

        return $scope;
    }

    public function requireGovernedChange(Request $request, string $changeRequestUuid): AdminScope
    {
        if (! Str::isUuid($changeRequestUuid)) {
            throw new AdminScopeViolation('admin_governed_change_required', 'A valid governed change request is required for this action.');
        }

        $change = GovernedChangeRequest::query()->whereKey($changeRequestUuid)->firstOrFail();
        $scope = $change->facility_id !== null
            ? $this->requireFacility($request)
            : $this->requireOrganization($request);

        if ($change->facility_id !== null && $scope->facilityId !== (int) $change->facility_id) {
            throw new AdminScopeViolation('admin_governed_scope_mismatch', 'The selected facility does not match the governed request.');
        }
        if ($change->organization_id !== null && $scope->organizationId !== (int) $change->organization_id) {
            throw new AdminScopeViolation('admin_governed_scope_mismatch', 'The selected organization does not match the governed request.');
        }
        if ($change->facility_id === null && $change->organization_id === null) {
            throw new AdminScopeViolation('admin_governed_scope_missing', 'The governed integration request has no canonical enterprise scope.');
        }

        $sourceId = $this->governedSourceId($change);
        if ($sourceId !== null) {
            return $this->requireSource($request, $sourceId);
        }

        return $scope;
    }

    private function resolve(
        User $user,
        int $organizationId,
        ?int $facilityId,
        ?int $sourceId,
        string $revision,
        CarbonImmutable $selectedAt,
    ): AdminScope {
        if ($organizationId < 1 || $revision === '') {
            throw new AdminScopeViolation('admin_scope_invalid', 'The selected Admin scope is invalid.');
        }
        if ($sourceId !== null && $facilityId === null) {
            throw new AdminScopeViolation('admin_scope_invalid', 'An integration source scope requires a facility.');
        }

        $organization = Organization::query()->find($organizationId);
        if (! $organization instanceof Organization) {
            throw new AdminScopeViolation('admin_organization_not_found', 'The selected organization no longer exists.');
        }

        $facility = null;
        if ($facilityId !== null) {
            $facility = Facility::query()->where('is_active', true)->find($facilityId);
            if (! $facility instanceof Facility || (int) $facility->organization_id !== $organizationId) {
                throw new AdminScopeViolation('admin_facility_scope_mismatch', 'The selected facility is inactive or does not belong to the organization.');
            }
        }

        if (! $this->userMayAccess($user, $organizationId, $facilityId)) {
            throw new AdminScopeViolation('admin_scope_access_revoked', 'The selected Admin scope is not currently granted to this user.');
        }

        $source = null;
        if ($sourceId !== null) {
            $source = Source::query()->find($sourceId);
            if (! $source instanceof Source
                || (int) $source->organization_id !== $organizationId
                || (int) $source->facility_id !== $facilityId) {
                throw new AdminScopeViolation('admin_source_scope_mismatch', 'The selected source does not belong to the organization and facility.');
            }
        }

        return new AdminScope(
            userId: (int) $user->getKey(),
            organizationId: $organizationId,
            organizationKey: (string) $organization->organization_key,
            organizationName: (string) $organization->name,
            facilityId: $facilityId,
            facilityKey: $facility?->facility_key,
            facilityName: $facility?->facility_name,
            sourceId: $sourceId,
            sourceKey: $source?->source_key,
            sourceName: $source?->source_name,
            revision: $revision,
            selectedAt: $selectedAt,
        );
    }

    /** @return array{Collection<int, int>, Collection<int, int>} */
    private function accessibleBoundaryIds(User $user): array
    {
        if ($this->hasGlobalScope($user)) {
            return [collect(), collect()];
        }

        $scopes = UserAccessScope::query()
            ->effective()
            ->where('user_id', $user->getKey())
            ->get(['organization_id', 'facility_id']);
        $facilityIds = $scopes->pluck('facility_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values();
        $organizationIds = $scopes->pluck('organization_id')->filter()->map(fn (mixed $id): int => (int) $id);
        if ($facilityIds->isNotEmpty()) {
            $organizationIds = $organizationIds->merge(
                Facility::query()->whereIn('facility_id', $facilityIds)->pluck('organization_id')->map(fn (mixed $id): int => (int) $id),
            );
        }

        return [$organizationIds->unique()->values(), $facilityIds];
    }

    private function userMayAccess(User $user, int $organizationId, ?int $facilityId): bool
    {
        if ($this->hasGlobalScope($user)) {
            return true;
        }

        return UserAccessScope::query()
            ->effective()
            ->where('user_id', $user->getKey())
            ->where(function (Builder $query) use ($organizationId, $facilityId): void {
                $query->where(function (Builder $query) use ($organizationId): void {
                    $query->where('organization_id', $organizationId)->whereNull('facility_id');
                });
                if ($facilityId !== null) {
                    $query->orWhere('facility_id', $facilityId);
                } else {
                    $query->orWhereHas('facility', fn (Builder $query) => $query->where('organization_id', $organizationId));
                }
            })
            ->exists();
    }

    private function hasGlobalScope(User $user): bool
    {
        return collect(config('authorization.global_scope_roles', []))
            ->intersect($this->authorization->effectiveRoleIds($user))
            ->isNotEmpty();
    }

    private function governedSourceId(GovernedChangeRequest $change): ?int
    {
        if ($change->subject_type === 'integration_source' && ctype_digit((string) $change->subject_id)) {
            return (int) $change->subject_id;
        }
        if (in_array($change->subject_type, ['integration_credential', 'integration_source_configuration'], true)) {
            $sourceId = explode(':', (string) $change->subject_id, 2)[0];

            return ctype_digit($sourceId) ? (int) $sourceId : null;
        }
        if ($change->subject_type === 'source_activation_window') {
            $sourceId = DB::table('integration.source_activation_windows')
                ->where('activation_window_uuid', (string) $change->subject_id)
                ->value('source_id');

            return $sourceId !== null ? (int) $sourceId : null;
        }
        if ($change->subject_type === 'payload_quarantine') {
            $sourceId = DB::table('raw.payload_quarantines')
                ->where('quarantine_uuid', (string) $change->subject_id)
                ->value('source_id');

            return $sourceId !== null ? (int) $sourceId : null;
        }
        if ($change->subject_type === 'clinical_payload') {
            $sourceId = DB::table('raw.payload_objects')
                ->where('payload_uuid', (string) $change->subject_id)
                ->value('source_id');

            return $sourceId !== null ? (int) $sourceId : null;
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function emptyContract(): array
    {
        return [
            'organizations' => [],
            'facilities' => [],
            'sources' => [],
            'current' => null,
            'query' => [],
            'updateUrl' => null,
            'clearUrl' => null,
        ];
    }
}
