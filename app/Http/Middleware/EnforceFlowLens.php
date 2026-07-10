<?php

namespace App\Http\Middleware;

use App\Services\Flow\FlowLensService;
use App\Services\Mobile\MobilePersonaCatalog;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Persona-lens RBAC for the web Patient Flow 4D API — FLOW-WINDOW-PLAN §6.4
 * (closing G7: "any authed user sees the patient inspector").
 *
 * Resolves the caller's Hummingbird persona (same three-way resolution the
 * mobile BFF uses: ?persona= → X-Hummingbird-Role header → user default;
 * broad-access admins may assume any persona) and requires a configured
 * flow lens. With the `patients` requirement, roles whose lens says
 * patient_dots = none (executive, pi_lead, staffing_coordinator, OR roles)
 * — and users with no Hummingbird persona at all — get an explicit 403,
 * exactly mirroring their A2P matrix.
 *
 * `scoped` and `scoped-patients` additionally resolve the canonical Flow
 * Window scope and attach the effective depth plus unit/task grants. This is
 * the web API equivalent of Mobile FlowController's server-side clamp.
 */
class EnforceFlowLens
{
    public function __construct(
        private readonly MobilePersonaCatalog $personas,
        private readonly FlowLensService $lens,
    ) {}

    public function handle(Request $request, Closure $next, string $requirement = 'any'): Response
    {
        try {
            $roleId = $this->personas->fromRequest($request);
            $lens = $this->lens->lensFor($roleId);
        } catch (AuthorizationException $exception) {
            return $this->forbidden($exception->getMessage());
        }

        if (in_array($requirement, ['patients', 'scoped-patients'], true) && $lens['patient_dots'] === 'none') {
            return $this->forbidden('This persona has no patient-level flow access.');
        }

        $request->attributes->set('flow_lens', $lens);
        $request->attributes->set('flow_role_id', $roleId);

        if (in_array($requirement, ['scoped', 'scoped-patients'], true)) {
            $requestedScope = $request->query('scope');
            if ($requestedScope !== null && ! is_string($requestedScope)) {
                return $this->forbidden('Flow scope must be a string.');
            }

            try {
                $scope = $this->lens->resolveScope($lens, $requestedScope, $request->user());
                $depth = $this->lens->effectivePatientDepth($lens, $scope, $request->user());
            } catch (AuthorizationException $exception) {
                return $this->forbidden($exception->getMessage());
            }

            if ($requirement === 'scoped-patients' && $depth === 'none') {
                return $this->forbidden('The resolved flow scope grants no patient-level access.');
            }

            $request->attributes->set('flow_scope', $scope);
            $request->attributes->set('flow_patient_depth', $depth);
            $request->attributes->set(
                'flow_task_patient_refs',
                $depth === 'task' ? $this->lens->taskPatientRefs($roleId) : [],
            );
            $request->attributes->set(
                'flow_visible_unit_ids',
                $depth === 'unit' ? $this->lens->visibleUnitIds($request->user()) : [],
            );
        }

        return $next($request);
    }

    private function forbidden(string $reason): Response
    {
        return response()->json([
            'error' => [
                'code' => 'flow_lens_forbidden',
                'message' => $reason,
                'unauthorized_state' => true,
            ],
        ], 403);
    }
}
