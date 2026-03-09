<?php

namespace App\Http\Middleware;

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
        return [
            ...parent::share($request),
'auth' => [
    'user' => $request->user() ? array_merge(
        $request->user()->toArray(),
        ['must_change_password' => (bool) $request->user()->must_change_password]
    ) : null,
    'roles' => $request->user() ? $request->user()->getRoleNames()->toArray() : [],
    'is_admin' => $request->user() ? $request->user()->hasRole(['super-admin', 'admin']) : false,
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
