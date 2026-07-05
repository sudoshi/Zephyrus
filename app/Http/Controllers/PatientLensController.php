<?php

namespace App\Http\Controllers;

use App\Services\Mobile\MobilePatientContextService;
use App\Services\Mobile\MobilePersonaCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Zephyrus 2.0 P8 WS-3 — the patient lens (A2P) as a web surface.
 *
 * Promotes the mobile A2P operational-context payload to the web cockpit: the
 * SAME persona-gated `MobilePatientContextService::build()` the Hummingbird app
 * renders, now reachable as an in-place cockpit drill (this JSON `show`). Reuse
 * is the point — there is zero new context logic here; the service stays the
 * single A2P authority for web and mobile alike, so the two surfaces can never
 * disagree about what a persona may see.
 *
 * Two gates, layered: `EnforceFlowLens:patients` (route middleware) answers
 * "may this persona see patient-level flow AT ALL?" — a lens whose
 * `patient_dots` is `none` (executive, PI, staffing, OR) never reaches the
 * controller. The service then answers "may this persona see THIS patient?"
 * (broad-access, ownership, or shared-unit) and throws `AuthorizationException`
 * — surfaced here as a clean 403 with an explicit `unauthorized_state`, never a
 * silently narrowed payload. The `flow_role_id` the middleware already resolved
 * is reused so the persona is resolved once per request.
 */
class PatientLensController extends Controller
{
    public function __construct(
        private readonly MobilePatientContextService $patients,
        private readonly MobilePersonaCatalog $personas,
    ) {}

    /**
     * In-place A2P drill fetch for the cockpit — the raw context payload,
     * parsed defensively client-side (mirrors /cockpit/face, /cockpit/drill).
     */
    public function show(Request $request, string $contextRef): JsonResponse
    {
        try {
            // EnforceFlowLens already resolved + validated the persona and
            // attached it; reuse it rather than re-resolving (and re-throwing).
            $roleId = $request->attributes->get('flow_role_id')
                ?? $this->personas->fromRequest($request);

            return response()->json(
                $this->patients->build($contextRef, $request->user(), $roleId),
            );
        } catch (AuthorizationException $exception) {
            return response()->json([
                'error' => [
                    'code' => 'patient_context_forbidden',
                    'message' => $exception->getMessage(),
                    'unauthorized_state' => true,
                ],
            ], 403);
        }
    }
}
