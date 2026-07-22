<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCarePathwayDemoEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('care-pathways.demo.enabled', false), 404);

        return $next($request);
    }
}
