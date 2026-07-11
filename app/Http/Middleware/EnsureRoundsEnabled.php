<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Feature gate for Virtual Rounds (VIRTUAL_ROUNDS_ENABLED).
 * A 404 (not 403) keeps the feature invisible when off; disabling the flag
 * stops new work without deleting existing audit records.
 */
class EnsureRoundsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('rounds.enabled'), 404);

        return $next($request);
    }
}
