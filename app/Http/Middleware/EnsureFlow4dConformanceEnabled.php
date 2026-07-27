<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FLOW-4D adherence surface gate (plan §8 C5). Applied INSIDE the Arena route
 * group, so the effective gate composes: ARENA_ENABLED ∧ FLOW4D_CONFORMANCE_ENABLED
 * ∧ (per-route) a patient-dots flow lens. 404 — not 403 — while off: the
 * surface does not exist, mirroring EnsureArenaEnabled.
 */
class EnsureFlow4dConformanceEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('services.flow4d.conformance'), 404);

        return $next($request);
    }
}
