<?php

namespace App\Http\Controllers\Admin;

use App\Authorization\Capability;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\UserAuditRecorder;
use App\Services\Authorization\AdminScopeService;
use App\Services\Authorization\RoleCapabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class AdminScopeController extends Controller
{
    public function __construct(
        private readonly AdminScopeService $scopes,
        private readonly RoleCapabilityService $authorization,
        private readonly UserAuditRecorder $audit,
    ) {}

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeSelection($request);
        $validated = $request->validate([
            'organization_id' => ['required', 'integer', 'min:1'],
            'facility_id' => ['nullable', 'integer', 'min:1'],
            'source_id' => ['nullable', 'integer', 'min:1'],
            'return_path' => ['nullable', 'string', 'max:1000'],
        ]);
        $scope = $this->scopes->select(
            $request,
            (int) $validated['organization_id'],
            isset($validated['facility_id']) ? (int) $validated['facility_id'] : null,
            isset($validated['source_id']) ? (int) $validated['source_id'] : null,
        );

        $this->audit->record('administration.scope.selected', 'administration', 'success', [
            'request' => $request,
            'target_type' => 'admin_scope',
            'target_id' => $scope->revision,
            'metadata' => [
                'organization_id' => $scope->organizationId,
                'facility_id' => $scope->facilityId,
                'source_id' => $scope->sourceId,
                'scope_revision' => $scope->revision,
            ],
        ]);

        return redirect()->to($this->returnPath($validated['return_path'] ?? null, $scope->query()))
            ->with('message', 'Active Admin scope updated.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->authorizeSelection($request);
        $validated = $request->validate([
            'return_path' => ['nullable', 'string', 'max:1000'],
        ]);
        $scope = $this->scopes->current($request);

        $this->audit->record('administration.scope.cleared', 'administration', 'success', [
            'request' => $request,
            'target_type' => 'admin_scope',
            'target_id' => $scope?->revision,
            'metadata' => array_filter([
                'organization_id' => $scope?->organizationId,
                'facility_id' => $scope?->facilityId,
                'source_id' => $scope?->sourceId,
                'scope_revision' => $scope?->revision,
            ], fn (mixed $value): bool => $value !== null),
        ]);
        $this->scopes->clear($request);

        return redirect()->to($this->returnPath($validated['return_path'] ?? null))
            ->with('message', 'Active Admin scope cleared.');
    }

    private function authorizeSelection(Request $request): void
    {
        $user = $request->user();
        abort_unless($user instanceof User && collect([
            Capability::ViewAdministration,
            Capability::ViewIntegrations,
            Capability::ViewEnterpriseSetup,
        ])->contains(fn (Capability $capability): bool => $this->authorization->allows($user, $capability)), 403);
    }

    /** @param array<string, int> $scopeQuery */
    private function returnPath(?string $candidate, array $scopeQuery = []): string
    {
        $candidate = trim((string) $candidate);
        if ($candidate === '' || ! str_starts_with($candidate, '/') || str_starts_with($candidate, '//')) {
            $candidate = route('admin.dashboard', absolute: false);
        }

        $parts = parse_url($candidate);
        if ($parts === false || isset($parts['host'], $parts['scheme']) || ($parts['path'] ?? '') === '') {
            $candidate = route('admin.dashboard', absolute: false);
            $parts = parse_url($candidate);
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        foreach (['organization_id', 'facility_id', 'source_id'] as $key) {
            unset($query[$key]);
        }
        $query = [...$query, ...$scopeQuery];
        $path = (string) ($parts['path'] ?? route('admin.dashboard', absolute: false));

        return $query === [] ? $path : $path.'?'.http_build_query($query);
    }
}
