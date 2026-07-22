<?php

namespace App\Http\Middleware;

use App\Authorization\Capability;
use App\Services\Authorization\AdminScopeService;
use App\Services\Authorization\RoleCapabilityService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $authorization = app(RoleCapabilityService::class);
        $allows = fn (Capability $capability): bool => $user !== null
            && $authorization->allows($user, $capability);
        $usesAdminScope = $allows(Capability::ViewAdministration)
            || $allows(Capability::ViewIntegrations)
            || $allows(Capability::ViewEnterpriseSetup);

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? array_merge(
                    $request->user()->toArray(),
                    ['must_change_password' => (bool) $request->user()->must_change_password]
                ) : null,
                // Keep the Spatie-only field for compatibility while exposing
                // the reconciled roles/capabilities as the new contract.
                'roles' => $user ? $user->getRoleNames()->toArray() : [],
                'effective_roles' => $user ? $authorization->effectiveRoleIds($user) : [],
                'capabilities' => $user
                    ? array_map(fn (Capability $capability): string => $capability->value, $authorization->effectiveCapabilities($user))
                    : [],
                'is_admin' => $allows(Capability::ViewAdministration),
                'can' => [
                    'view_administration' => $allows(Capability::ViewAdministration),
                    'view_identity' => $allows(Capability::ViewIdentity),
                    'manage_identity' => $allows(Capability::ManageIdentity),
                    'manage_privileges' => $allows(Capability::ManagePrivileges),
                    'view_user_audit' => $allows(Capability::ViewAudit),
                    'view_access_reviews' => $allows(Capability::ViewAccessReviews),
                    'manage_access_reviews' => $allows(Capability::ManageAccessReviews),
                    'view_authorization' => $allows(Capability::ViewAuthorization),
                    'view_system_health' => $allows(Capability::ViewSystemHealth),
                    'run_diagnostics' => $allows(Capability::RunDiagnostics),
                    'view_enterprise_setup' => $allows(Capability::ViewEnterpriseSetup),
                    'manage_enterprise_setup' => $allows(Capability::ManageEnterpriseSetup),
                    'manage_facility_administration' => $allows(Capability::ManageFacilityAdministration),
                    'manage_staffing_alignment' => $allows(Capability::ManageEnterpriseSetup),
                    'view_integrations' => $allows(Capability::ViewIntegrations),
                    'manage_integrations' => $allows(Capability::ManageIntegrationConfiguration),
                    'operate_integrations' => $allows(Capability::OperateIntegrations),
                    'approve_integration_changes' => $allows(Capability::ApproveIntegrationChanges),
                    'manage_data_stewardship' => $allows(Capability::ManageDataStewardship),
                ],
            ],
            'adminScope' => $usesAdminScope
                ? app(AdminScopeService::class)->clientContract($request)
                : null,
            // Eddy ships disabled — the dock only mounts when this is true.
            'eddy' => [
                'enabled' => (bool) config('services.eddy.enabled'),
            ],
            // Part X (X4): the Arena's governed AI copilot ships disabled — the
            // copilot pane only mounts when this is true (independent of ARENA_ENABLED).
            'arena' => [
                'ai_enabled' => (bool) config('services.arena.ai_enabled'),
            ],
            // Feature flags the frontend reads to gate nav + surfaces. Virtual
            // Rounds ships disabled; the nav item stays hidden (never a dead
            // link) until VIRTUAL_ROUNDS_ENABLED is on — matching the server
            // route gate (EnsureRoundsEnabled) so nav and route never disagree.
            'features' => [
                // A read-only synthetic journey; independent from every real
                // catalog, assignment, patient, Hummingbird, and Eddy gate.
                'care_pathways_demo' => (bool) config('care-pathways.demo.enabled'),
                'virtual_rounds' => (bool) config('rounds.enabled'),
                // Home Hospital ships disabled; nav stays hidden (never a dead
                // link) until HOME_HOSPITAL_ENABLED is on — matching the server
                // route gate (EnsureHomeHospitalEnabled).
                'home_hospital' => (bool) config('home_hospital.enabled'),
            ],
            'workflow' => $request->session()->get('workflow'),
            'flash' => [
                'message' => fn () => $request->session()->get('message'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'app' => [
                'name' => config('app.name'),
                'env' => config('app.env'),
            ],
            'ziggy' => [
                'url' => $request->url(),
                'port' => $request->getPort(),
                'defaults' => [],
                'routes' => app('router')->getRoutes()->getRoutesByName() ?? [],
            ],
        ];
    }
}
