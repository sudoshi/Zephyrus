<?php

namespace App\Http\Controllers\Admin;

use App\Authorization\Capability;
use App\Http\Controllers\Controller;
use App\Models\Audit\UserEvent;
use App\Models\User;
use App\Services\Admin\AdminReadinessService;
use App\Services\Audit\UserAuditPresenter;
use App\Services\Authorization\AdminScopeService;
use App\Services\Authorization\RoleCapabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AdminDashboardController extends Controller
{
    public function __construct(
        private readonly UserAuditPresenter $presenter,
        private readonly RoleCapabilityService $authorization,
        private readonly AdminReadinessService $readiness,
        private readonly AdminScopeService $scopes,
    ) {}

    public function __invoke(Request $request): Response
    {
        $canViewIdentity = $this->authorization->allows($request->user(), Capability::ViewIdentity);
        $canViewAudit = $this->authorization->allows($request->user(), Capability::ViewAudit);
        $today = now()->startOfDay();
        $loginEvents = $canViewAudit
            ? UserEvent::query()
                ->whereIn('action', ['auth.login', 'mobile.auth.token_exchange'])
                ->where('occurred_at', '>=', $today)
            : null;

        $recent = $canViewAudit
            ? UserEvent::query()
                ->with('actor:id,name,username,email,role')
                ->orderByDesc('event_cursor')
                ->limit(8)
                ->get()
                ->map(fn (UserEvent $event): array => $this->presenter->present($event))
                ->all()
            : [];

        $metrics = [
            'totalUsers' => $canViewIdentity ? User::query()->count() : null,
            'activeUsers' => $canViewIdentity ? User::query()->where('is_active', true)->count() : null,
            'privilegedUsers' => $canViewIdentity ? $this->privilegedUserCount() : null,
            'mustChangePassword' => $canViewIdentity ? User::query()->where('must_change_password', true)->count() : null,
            'loginsToday' => $canViewAudit ? (clone $loginEvents)->where('outcome', 'success')->count() : null,
            'failedLoginsToday' => $canViewAudit ? (clone $loginEvents)->whereIn('outcome', ['failure', 'denied'])->count() : null,
            'activeUsers7d' => $canViewAudit
                ? UserEvent::query()
                    ->whereNotNull('actor_user_id')
                    ->where('outcome', 'success')
                    ->where('occurred_at', '>=', now()->subDays(7))
                    ->distinct()
                    ->count('actor_user_id')
                : null,
        ];
        $readiness = $this->readiness->for($request->user(), $metrics, $this->scopes->current($request));
        $healthState = $readiness['health']['visible'] && $readiness['health']['overallStatus'] === 'healthy' ? 'ready' : 'degraded';
        $integrationState = (($readiness['integration']['openDeadLetters'] ?? 0)
            + ($readiness['integration']['openProjectionErrors'] ?? 0)) > 0 ? 'degraded' : 'ready';

        return Inertia::render('Admin/Dashboard', [
            'metrics' => $metrics,
            'readiness' => $readiness,
            'recentEvents' => $recent,
            'canViewAuditActivity' => $canViewAudit,
            'sections' => [
                $this->section($request->user(), 'users', 'User Management', 'Manage account access, roles, and active status.', '/users', Capability::ViewIdentity, Route::has('users.index')),
                $this->section($request->user(), 'auth_providers', 'Authentication Providers', 'Review local access policy and configure enterprise OIDC.', '/admin/auth-providers', Capability::ViewIdentity, Route::has('admin.auth-providers.index'), 'implemented'),
                $this->section($request->user(), 'system_health', 'System Health', 'Review append-only platform observations, freshness, ownership, and bounded diagnostics.', '/admin/system-health', Capability::ViewSystemHealth, Route::has('admin.system-health.index'), $healthState, $readiness['health']['visible'] ? $readiness['health']['counts']['requiredAttention'].' required component(s) need attention.' : null),
                $this->section($request->user(), 'roles_capabilities', 'Roles / Capabilities', 'Inspect the canonical role-to-capability and scope policy without creating a second grant store.', '/admin/roles-capabilities', Capability::ViewAuthorization, Route::has('admin.roles-capabilities.index')),
                $this->section($request->user(), 'user_audit', 'User Audit', 'Review authentication, page access, and account changes.', '/admin/user-audit', Capability::ViewAudit, Route::has('admin.user-audit.index')),
                $this->section($request->user(), 'access_reviews', 'Access Reviews', 'Certify privileged access quarterly and export immutable evidence.', '/admin/access-reviews', Capability::ViewAccessReviews, Route::has('admin.access-reviews.index'), ($readiness['governance']['overdueAccessReviewCampaigns'] ?? 0) > 0 ? 'degraded' : 'ready'),
                $this->section($request->user(), 'audit_compliance', 'Audit / Compliance', 'Enter the immutable accountability trail and access-certification evidence.', '/admin/user-audit', Capability::ViewAudit, Route::has('admin.user-audit.index')),
                $this->section($request->user(), 'cockpit_thresholds', 'Cockpit Governance', 'Govern versioned KPI threshold policies with owner, scope, preview, independent approval, and rollback.', '/admin/cockpit/thresholds', Capability::ViewCockpitPolicy, Route::has('admin.cockpit.thresholds')),
                $this->section($request->user(), 'enterprise_setup', 'Enterprise Setup', 'Manage facility taxonomy and deployment readiness.', '/admin/enterprise-setup', Capability::ViewEnterpriseSetup, Route::has('admin.enterprise-setup')),
                $this->section($request->user(), 'staffing_administration', 'Staffing Administration', 'Configure governed staffing alignment.', '/staffing/administration', Capability::ManageEnterpriseSetup, Route::has('staffing.administration')),
                $this->section($request->user(), 'integrations', 'Integrations', 'Manage the separately governed healthcare interoperability control plane.', '/integrations?tab=sources', Capability::ViewIntegrations, Route::has('integrations'), $integrationState),
                $this->section($request->user(), 'data_protection', 'Data Protection', 'Review encrypted payload authority, migration coverage, quarantine, retention, integrity, and partition readiness.', '/admin/data-protection', Capability::ViewIntegrations, Route::has('admin.data-protection.index'), $integrationState),
                $this->section($request->user(), 'data_governance', 'Data Governance', 'Review terminology mappings, source provenance, and stewardship exceptions.', '/integrations?tab=mappings', Capability::ManageDataStewardship, Route::has('integrations'), $integrationState),
                $this->section($request->user(), 'eddy_governance', 'Eddy Governance', 'Govern Zephyrus/Eddy provider capability, fallback order, cost limits, PHI eligibility, region, and surface routing.', '/admin/ai-providers', Capability::ViewAiGovernance, Route::has('admin.ai-providers.index')),
            ],
        ]);
    }

    private function privilegedUserCount(): int
    {
        return User::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user): bool => $this->authorization->allows(
                $user,
                Capability::ViewAdministration,
            ))
            ->count();
    }

    /** @return array<string, mixed> */
    private function section(
        User $user,
        string $key,
        string $label,
        string $description,
        string $href,
        Capability $capability,
        bool $routeAvailable,
        string $readyState = 'ready',
        ?string $detail = null,
        ?string $remediation = null,
    ): array {
        $allowed = $this->authorization->allows($user, $capability);
        $state = match (true) {
            ! $routeAvailable => 'blocked',
            ! $allowed => 'restricted',
            default => $readyState,
        };

        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'href' => $href,
            'state' => $state,
            'requiredCapability' => $capability->value,
            'detail' => $detail,
            'remediation' => $remediation ?? match ($state) {
                'restricted' => 'Request the '.$capability->value.' capability through access governance.',
                'blocked' => 'Complete the implementation and release evidence before enabling this surface.',
                'degraded' => 'Open the section and resolve its current readiness items.',
                default => null,
            },
        ];
    }
}
