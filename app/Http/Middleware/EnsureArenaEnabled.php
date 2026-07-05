<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the Part X Arena routes behind ARENA_ENABLED (default off), honouring the
 * EDDY_ENABLED precedent — the whole subsystem ships disabled. A 404 (not 403)
 * keeps the feature invisible when off.
 */
class EnsureArenaEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('services.arena.enabled'), 404);

        return $next($request);
    }
}
