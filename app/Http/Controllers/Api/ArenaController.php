<?php

namespace App\Http\Controllers\Api;

use App\Domain\Arena\ArenaService;
use App\Domain\Arena\FlowReviewService;
use App\Http\Controllers\Controller;
use App\Services\Eddy\EddyActionService;
use App\Services\PatientFlow\PathwayDeviationSceneService;
use App\Services\PatientFlow\PatientFlowEventAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Zephyrus 2.0 Part X (X1) Arena serving API. The browser calls these; Laravel
 * proxies to the OCPM sidecar and caches the discovered map in arena.maps. The
 * route group is gated by EnsureArenaEnabled (ARENA_ENABLED), so these endpoints
 * 404 while the feature is off.
 */
class ArenaController extends Controller
{
    public function __construct(
        private readonly ArenaService $arena,
        private readonly FlowReviewService $review,
    ) {}

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

    /**
     * Cached per-case conformance verdicts for ONE patient (FLOW-4D plan §8
     * A2). Requires scope=patient:{ptok_…} under EnforceFlowLens:scoped-patients
     * — the middleware's patientScope() runs the A2P authorization + disclosure
     * audit, and the resolved scope hands us the patient_ref for the
     * deterministic hash join. Reads the arena.case_conformance cache only.
     */
    public function caseConformance(Request $request): JsonResponse
    {
        $scope = $request->attributes->get('flow_scope');

        if (! is_array($scope) || ($scope['type'] ?? null) !== 'patient' || empty($scope['patient_ref'])) {
            return response()->json([
                'error' => [
                    'code' => 'case_conformance_requires_patient_scope',
                    'message' => 'This endpoint requires scope=patient:{ptok_…}.',
                ],
            ], 422);
        }

        $payload = $this->arena->caseConformance(
            $this->arena->caseOidsForPatient((string) $scope['patient_ref']),
        );
        $payload['patient_context_ref'] = $scope['patient_context_ref'];
        $payload['generated_at'] = now()->toJSON();

        return response()->json($payload)->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Bulk deviation flags for the scene's glyph layer + census scope (plan §8
     * C3). Under EnsureFlow4dConformanceEnabled + EnforceFlowLens:scoped-patients;
     * rows ride the same window filters and lens gate as the scene's own event
     * feed, keyed by opaque ptok refs. Cache-only, like caseConformance.
     */
    public function sceneConformance(
        Request $request,
        PatientFlowEventAccessService $access,
        PathwayDeviationSceneService $scene,
    ): JsonResponse {
        $payload = $scene->build($access->context($request), [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'limit' => $request->query('limit', 5000),
        ]);
        $payload['generated_at'] = now()->toJSON();

        return response()->json($payload)->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * "Open an exception note" (plan §7.2, CF-5 variance-to-review framing):
     * drafts a governed Eddy-plane proposal against a CACHED deviation verdict —
     * never hand-rolled governance records, never auto-approved. The note lands
     * as Recommendation(draft) → OperationalAction(draft) → Approval(pending)
     * through EddyActionService::propose(approve: false).
     */
    public function conformanceExceptionNote(Request $request, EddyActionService $eddy): JsonResponse
    {
        $scope = $request->attributes->get('flow_scope');

        if (! is_array($scope) || ($scope['type'] ?? null) !== 'patient' || empty($scope['patient_ref'])) {
            return response()->json([
                'error' => [
                    'code' => 'exception_note_requires_patient_scope',
                    'message' => 'This endpoint requires scope=patient:{ptok_…}.',
                ],
            ], 422);
        }

        $validated = $request->validate([
            'pathway' => ['required', 'string', 'max:60'],
            'note' => ['required', 'string', 'max:2000'],
            'deviations' => ['sometimes', 'array', 'max:20'],
            'deviations.*' => ['string', 'max:80'],
        ]);

        // The note must answer a REAL cached deviation — the same cache the
        // panel rendered from (honest provenance; no verdict, no note).
        $verdicts = $this->arena->caseConformance(
            $this->arena->caseOidsForPatient((string) $scope['patient_ref']),
        );
        $verdict = collect($verdicts['verdicts'] ?? [])->first(
            fn (array $candidate): bool => $candidate['pathway'] === $validated['pathway']
                && $candidate['conformant'] === false,
        );

        if ($verdict === null) {
            return response()->json([
                'error' => [
                    'code' => 'no_cached_deviation',
                    'message' => 'No cached non-conformant verdict for this pathway and patient.',
                ],
            ], 422);
        }

        $noted = array_values(array_intersect(
            array_filter((array) ($validated['deviations'] ?? []), 'is_string'),
            $verdict['deviations'],
        ));

        try {
            $result = $eddy->propose($request->user(), [
                'action_type' => 'flag_pathway_deviation',
                'title' => 'Pathway exception note — '.$validated['pathway'],
                'rationale' => $validated['note'],
                'surface' => 'patient_flow_4d',
                'params' => [
                    'exception_note' => true,
                    'pathway' => $validated['pathway'],
                    'pathway_version' => $verdict['pathway_version'],
                    'deviations' => $noted !== [] ? $noted : $verdict['deviations'],
                    'as_of_batch' => $verdict['computed_at'],
                    // Opaque HMAC context ref — the same non-identifying handle
                    // the authorized client already holds; resolvable only
                    // through lens-authorized surfaces. Never an identifier.
                    'patient_context_ref' => $scope['patient_context_ref'],
                ],
            ], approve: false);
        } catch (AuthorizationException $exception) {
            return response()->json([
                'error' => ['code' => 'eddy_role_required', 'message' => $exception->getMessage()],
            ], 403);
        }

        return response()->json($result + [
            'patient_context_ref' => $scope['patient_context_ref'],
        ], 201);
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
     * The 48-Hour Flow Review artifact — a pure read of the persisted review
     * (arena.reviews). Query param ?window=<ISO> selects a past window; default
     * is the latest. Returns {available:false, reason:'no_review'} (503) if none
     * has been built yet, so the movement invites a Run.
     */
    public function review(Request $request): JsonResponse
    {
        $window = $request->filled('window') ? (string) $request->query('window') : null;
        $payload = $this->review->get($window);
        $status = ($payload['available'] ?? true) === false ? 503 : 200;

        return response()->json($payload, $status);
    }

    /**
     * (Re)build the Flow Review for the current window and persist it — the
     * Run-review action. Rebuilds one OCPM pass + the open barriers into a fresh
     * ranked artifact. 503 if the sidecar is unreachable (the last-good review
     * stays readable via GET /review).
     */
    public function runReview(Request $request): JsonResponse
    {
        $window = $request->filled('window') ? (string) $request->input('window') : null;
        $payload = $this->review->run($window);
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

        Validator::make(
            ['filters' => $decoded],
            [
                'filters' => ['array', 'max:12'],
                'filters.*.kind' => ['required', 'string', 'in:object_type,event_type,time_frame,event_attribute'],
            ]
        )->validate();

        return $decoded;
    }
}
