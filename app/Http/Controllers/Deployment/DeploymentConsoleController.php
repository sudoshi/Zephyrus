<?php

namespace App\Http\Controllers\Deployment;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Renders Enterprise Setup: the administrative surface for IDN geography,
 * capability mapping, transfer relationships, facility spaces, and readiness.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase F1)
 */
class DeploymentConsoleController extends Controller
{
    public function index(): InertiaResponse
    {
        return Inertia::render('Deployment/DeploymentConsole');
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
