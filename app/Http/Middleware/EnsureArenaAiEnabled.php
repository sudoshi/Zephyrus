<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Part X (X4) — gates the governed AI copilot routes behind ARENA_AI_ENABLED
 * (default off), a flag INDEPENDENT of ARENA_ENABLED so the deterministic Arena
 * (discovery/performance/conformance) can run with the AI author switched entirely
 * off. Stack this AFTER EnsureArenaEnabled: the copilot needs the Arena, so both
 * must be on. A 404 (not 403) keeps the copilot invisible when off — the same
 * "ships disabled" discipline as EnsureArenaEnabled.
 */
class EnsureArenaAiEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('services.arena.ai_enabled'), 404);

        return $next($request);
    }
}
