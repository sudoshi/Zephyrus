<?php

namespace App\Http\Controllers\Api;

use App\Domain\Arena\ArenaCopilotService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Zephyrus 2.0 Part X (X4) — the governed AI copilot serving API. The route group
 * stacks EnsureArenaEnabled + EnsureArenaAiEnabled, so every endpoint 404s unless
 * BOTH the Arena and its AI author are switched on. The copilot only ever PROPOSES:
 * the draft endpoints create governance records that land pending for a human, and
 * the map endpoint withholds any picture that fails the conformance-fitness gate.
 */
class ArenaCopilotController extends Controller
{
    public function __construct(private readonly ArenaCopilotService $copilot) {}

    /** A provenance-pinned plain-language brief of the current process state. */
    public function narrative(): JsonResponse
    {
        $payload = $this->copilot->narrate();
        $status = ($payload['available'] ?? true) === false ? 503 : 200;

        return response()->json($payload, $status);
    }

    /** Answer a question by routing it onto an allow-listed, parameterized query. */
    public function query(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:400'],
        ]);

        $payload = $this->copilot->query($validated['question']);

        return response()->json($payload, ($payload['available'] ?? true) === false ? 503 : 200);
    }

    /** Propose an object-centric map, withheld below the conformance-fitness floor. */
    public function authorMap(Request $request): JsonResponse
    {
        $types = $request->filled('types')
            ? array_filter(array_map('trim', explode(',', (string) $request->input('types'))))
            : null;

        $payload = $this->copilot->authorMap($types);
        $status = ($payload['available'] ?? true) === false ? 503 : 200;

        return response()->json($payload, $status);
    }

    /** Draft a PDSA cycle for a bottleneck/pathway focus — lands pending on the Eddy plane. */
    public function draftPdsa(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'focus' => ['required', 'string', 'in:bottleneck,sepsis,surgical_safety'],
            'barrier_id' => ['nullable', 'integer'],
            'target_ref' => ['nullable', 'string', 'max:80'],
        ]);

        $payload = $this->copilot->draftPdsa(
            $request->user(),
            $validated['focus'],
            $validated['barrier_id'] ?? null,
            $validated['target_ref'] ?? null,
        );

        return response()->json($payload, ($payload['available'] ?? true) === false ? 503 : 200);
    }

    /** Draft a care-pathway correction for a conformance deviation — lands pending. */
    public function draftCorrection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pathway' => ['required', 'string', 'in:sepsis,surgical_safety'],
            'barrier_id' => ['nullable', 'integer'],
            'target_ref' => ['nullable', 'string', 'max:80'],
        ]);

        $payload = $this->copilot->draftCorrection(
            $request->user(),
            $validated['pathway'],
            $validated['barrier_id'] ?? null,
            $validated['target_ref'] ?? null,
        );

        return response()->json($payload, ($payload['available'] ?? true) === false ? 503 : 200);
    }
}
