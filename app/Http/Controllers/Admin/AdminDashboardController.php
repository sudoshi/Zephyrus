<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Audit\UserEvent;
use App\Models\User;
use App\Services\Audit\UserAuditPresenter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AdminDashboardController extends Controller
{
    public function __construct(private readonly UserAuditPresenter $presenter) {}

    public function __invoke(): Response
    {
        $today = now()->startOfDay();
        $loginEvents = UserEvent::query()
            ->whereIn('action', ['auth.login', 'mobile.auth.token_exchange'])
            ->where('occurred_at', '>=', $today);

        $recent = UserEvent::query()
            ->with('actor:id,name,username,email,role')
            ->orderByDesc('event_cursor')
            ->limit(8)
            ->get()
            ->map(fn (UserEvent $event): array => $this->presenter->present($event))
            ->all();

        return Inertia::render('Admin/Dashboard', [
            'metrics' => [
                'totalUsers' => User::query()->count(),
                'activeUsers' => User::query()->where('is_active', true)->count(),
                'privilegedUsers' => $this->privilegedUserCount(),
                'mustChangePassword' => User::query()->where('must_change_password', true)->count(),
                'loginsToday' => (clone $loginEvents)->where('outcome', 'success')->count(),
                'failedLoginsToday' => (clone $loginEvents)->whereIn('outcome', ['failure', 'denied'])->count(),
                'activeUsers7d' => UserEvent::query()
                    ->whereNotNull('actor_user_id')
                    ->where('outcome', 'success')
                    ->where('occurred_at', '>=', now()->subDays(7))
                    ->distinct()
                    ->count('actor_user_id'),
            ],
            'recentEvents' => $recent,
            'sections' => [
                $this->section('users', 'User Management', 'Manage account access, roles, and active status.', '/users', Route::has('users.index')),
                $this->section('user_audit', 'User Audit', 'Review authentication, page access, and account changes.', '/admin/user-audit', Gate::allows('viewUserAudit') && Route::has('admin.user-audit.index')),
                $this->section('cockpit_thresholds', 'Cockpit Thresholds', 'Govern operational KPI status bands and refresh timing.', '/admin/cockpit/thresholds', Route::has('admin.cockpit.thresholds')),
                $this->section('enterprise_setup', 'Enterprise Setup', 'Manage facility taxonomy and deployment readiness.', '/admin/enterprise-setup', Gate::allows('viewDeploymentConsole') && Route::has('admin.enterprise-setup')),
                $this->section('staffing_administration', 'Staffing Administration', 'Configure governed staffing alignment.', '/staffing/administration', Gate::allows('manageDeploymentConfig') && Route::has('staffing.administration')),
                $this->section('integrations', 'Integrations', 'Manage the separately governed integration control plane.', '/integrations', Gate::allows('viewIntegrations') && Route::has('integrations')),
            ],
        ]);
    }

    private function privilegedUserCount(): int
    {
        return User::query()
            ->where(function ($query): void {
                $query->whereRaw(
                    "replace(replace(lower(trim(coalesce(role, ''))), '-', '_'), ' ', '_') in (?, ?)",
                    ['admin', 'super_admin'],
                )->orWhereHas('roles', fn ($roles) => $roles->whereIn('name', [
                    'admin', 'super-admin', 'super_admin',
                ]));
            })
            ->count();
    }

    /** @return array{key: string, label: string, description: string, href: string, available: bool} */
    private function section(
        string $key,
        string $label,
        string $description,
        string $href,
        bool $available,
    ): array {
        return compact('key', 'label', 'description', 'href', 'available');
    }
}
