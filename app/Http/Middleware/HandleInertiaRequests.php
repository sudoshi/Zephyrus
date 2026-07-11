<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? array_merge(
                    $request->user()->toArray(),
                    ['must_change_password' => (bool) $request->user()->must_change_password]
                ) : null,
                'roles' => $request->user() ? $request->user()->getRoleNames()->toArray() : [],
                'is_admin' => $request->user()?->isAdministrator() ?? false,
                'can' => [
                    'view_administration' => $request->user()
                        ? Gate::forUser($request->user())->allows('viewAdministration')
                        : false,
                    'view_user_audit' => $request->user()
                        ? Gate::forUser($request->user())->allows('viewUserAudit')
                        : false,
                    'view_enterprise_setup' => $request->user()
                        ? Gate::forUser($request->user())->allows('viewDeploymentConsole')
                        : false,
                    'manage_staffing_alignment' => $request->user()
                        ? Gate::forUser($request->user())->allows('manageDeploymentConfig')
                        : false,
                    'view_integrations' => $request->user()
                        ? Gate::forUser($request->user())->allows('viewIntegrations')
                        : false,
                    'manage_integrations' => $request->user()
                        ? Gate::forUser($request->user())->allows('manageIntegrations')
                        : false,
                ],
            ],
            // Eddy ships disabled — the dock only mounts when this is true.
            'eddy' => [
                'enabled' => (bool) config('services.eddy.enabled'),
            ],
            // Part X (X4): the Arena's governed AI copilot ships disabled — the
            // copilot pane only mounts when this is true (independent of ARENA_ENABLED).
            'arena' => [
                'ai_enabled' => (bool) config('services.arena.ai_enabled'),
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
