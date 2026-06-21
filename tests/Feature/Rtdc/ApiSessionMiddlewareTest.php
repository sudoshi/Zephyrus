<?php

namespace Tests\Feature\Rtdc;

use Tests\TestCase;

class ApiSessionMiddlewareTest extends TestCase
{
    public function test_rtdc_api_routes_carry_session_middleware(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/rtdc/units');

        $this->assertNotNull($route, 'api/rtdc/units route should exist');

        // gatherMiddleware() returns the route's middleware list with group names
        // (e.g. 'web') unexpanded; resolveMiddleware() expands those groups into the
        // concrete middleware classes. We assert StartSession is present so the SPA's
        // session cookie is actually read (otherwise the route 401s in the browser).
        $resolved = app('router')->resolveMiddleware(
            $route->gatherMiddleware(),
            $route->excludedMiddleware(),
        );

        $this->assertContains(\Illuminate\Session\Middleware\StartSession::class, $resolved);
    }
}
