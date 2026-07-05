<?php

namespace App\Http\Controllers\Api;

use App\Domain\Arena\ArenaService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Zephyrus 2.0 Part X (X1) Arena serving API. The browser calls these; Laravel
 * proxies to the OCPM sidecar and caches the discovered map in arena.maps. The
 * route group is gated by EnsureArenaEnabled (ARENA_ENABLED), so these endpoints
 * 404 while the feature is off.
 */
class ArenaController extends Controller
{
    public function __construct(private readonly ArenaService $arena) {}

    /** Sidecar liveness for the admin surface. */
    public function health(): JsonResponse
    {
        return response()->json($this->arena->health());
    }

    /** Object/event/activity counts of the current OCEL log. */
    public function summary(): JsonResponse
    {
        return response()->json($this->arena->summary());
    }

    /**
     * A discovered object-centric map. Query params:
     *   ?types=Encounter,Bed   restrict to these object types
     *   ?min_freq=5            drop activities below this occurrence count
     *   ?scope=house           cache scope label
     *   ?force=1               bypass the cache (re-mine)
     */
    public function map(Request $request): JsonResponse
    {
        $types = $request->filled('types')
            ? array_filter(array_map('trim', explode(',', (string) $request->query('types'))))
            : null;

        $minFreq = $request->filled('min_freq') ? max(0, (int) $request->query('min_freq')) : null;
        $scope = (string) ($request->query('scope', 'house'));
        $force = $request->boolean('force');

        $payload = $this->arena->map($types, $minFreq, $scope !== '' ? $scope : 'house', $force);

        $status = ($payload['available'] ?? true) === false ? 503 : 200;

        return response()->json($payload, $status);
    }
}
