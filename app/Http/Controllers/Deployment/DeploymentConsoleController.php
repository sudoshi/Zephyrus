<?php

namespace App\Http\Controllers\Deployment;

use App\Http\Controllers\Admin\EnterpriseRegistryController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Renders Enterprise Setup: the administrative surface for IDN geography,
 * capability mapping, transfer relationships, facility spaces, readiness, and
 * (ENT-REG) the governed enterprise registry import/conflict-review surface.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase F1)
 */
class DeploymentConsoleController extends Controller
{
    public function index(Request $request, EnterpriseRegistryController $enterprise): InertiaResponse
    {
        return Inertia::render('Deployment/DeploymentConsole', [
            // ENT-REG governance overview (pending governed imports + change history).
            // Read-only; the import preview/commit/decide/apply are separate endpoints.
            'enterpriseGovernance' => $enterprise->overview($request),
        ]);
    }

    /**
     * Staffing Alignment remains backed by the existing deployment/staffing API,
     * but is presented under the Staffing workspace where operators expect it.
     */
    public function staffingWizard(): InertiaResponse
    {
        return Inertia::render('Deployment/StaffingWizard');
    }
}
