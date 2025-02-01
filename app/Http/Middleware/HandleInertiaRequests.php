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
                'user' => $request->user(),
            ],
            'csrf_token' => csrf_token(),
            'flash' => [
                'message' => fn () => $request->session()->get('message'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'app' => [
                'name' => config('app.name'),
                'env' => config('app.env'),
            ],
            'ziggy' => function () use ($request) {
                if (!$request->user() && !$request->routeIs('login')) {
                    return null;
                }
                return [
                    'url' => $request->url(),
                    'port' => $request->getPort(),
                    'defaults' => [],
                    'routes' => array_filter(
                        app('router')->getRoutes()->getRoutesByName() ?? [],
                        function ($route) use ($request) {
                            return $request->user() || in_array($route->uri(), ['login', 'password/reset']);
                        }
                    ),
                ];
            },
        ];
    }
}
