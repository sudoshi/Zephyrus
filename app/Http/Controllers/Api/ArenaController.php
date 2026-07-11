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
     * Object-centric performance (§X.6): slowest hand-offs + synchronization
     * waits. Query params: ?types=Encounter,Bed  ?top=25
     */
    public function performance(Request $request): JsonResponse
    {
        $types = $request->filled('types')
            ? array_filter(array_map('trim', explode(',', (string) $request->query('types'))))
            : null;
        $top = $request->filled('top') ? max(1, min(200, (int) $request->query('top'))) : 25;

        $payload = $this->arena->performance($types, $top, filters: $this->filtersFrom($request));
        $status = ($payload['available'] ?? true) === false ? 503 : 200;

        return response()->json($payload, $status);
    }

    /**
     * Patient-safety conformance of the OCEL log against the reference care
     * pathways (§X.7). Query param ?pathway=sepsis restricts to one pathway.
     */
    public function conformance(Request $request): JsonResponse
    {
        $pathway = $request->filled('pathway') ? (string) $request->query('pathway') : null;
        $payload = $this->arena->conformance($pathway, filters: $this->filtersFrom($request));
        $status = ($payload['available'] ?? true) === false ? 503 : 200;

        return response()->json($payload, $status);
    }

    public function petrinet(Request $request): JsonResponse
    {
        $payload = $this->arena->petrinet($this->filtersFrom($request));
        $status = ($payload['available'] ?? true) === false ? 503 : 200;

        return response()->json($payload, $status);
    }

    public function capacity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_type' => ['sometimes', 'string', 'max:60'],
            'threshold' => ['sometimes', 'integer', 'min:0'],
        ]);

        $payload = $this->arena->capacity(
            $validated['item_type'] ?? 'occupied_beds',
            isset($validated['threshold']) ? (int) $validated['threshold'] : null,
        );
        $status = ($payload['available'] ?? true) === false ? 503 : 200;

        return response()->json($payload, $status);
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

        $payload = $this->arena->map($types, $minFreq, $scope !== '' ? $scope : 'house', $force, filters: $this->filtersFrom($request));

        $status = ($payload['available'] ?? true) === false ? 503 : 200;

        return response()->json($payload, $status);
    }

    /**
     * Validate the optional filter pipeline from the request. Returns a plain
     * array the sidecar accepts, or null when absent.
     *
     * Accepts either a JSON-encoded string (GET query param from the frontend)
     * or a plain array (backward-compatible form/API usage).
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function filtersFrom(Request $request): ?array
    {
        $raw = $request->input('filters');
        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (! is_array($decoded) || $decoded === []) {
            return null;
        }

        \Illuminate\Support\Facades\Validator::make(
            ['filters' => $decoded],
            [
                'filters' => ['array', 'max:12'],
                'filters.*.kind' => ['required', 'string', 'in:object_type,event_type,time_frame,event_attribute'],
            ]
        )->validate();

        return $decoded;
    }
}
