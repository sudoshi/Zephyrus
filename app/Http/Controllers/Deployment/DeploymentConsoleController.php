<?php

namespace App\Http\Controllers\Deployment;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Renders the Deployment Console (Phase F1): the read surface over the Phase 0–6
 * deployment API — IDN geography, capability matrix, transfer network, facility
 * spaces, and the per-facility readiness scorecard. All data is fetched client-side
 * from the /api/deployment/* endpoints (gated by viewDeploymentConsole); this route
 * carries the same gate so an unauthorized user never renders the page.
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
     * The Staffing Alignment Wizard (Phase F4): the admin write surface over the §8
     * staffing API. Data is fetched + mutated client-side against
     * /api/deployment/staffing/* (gated by manageDeploymentConfig); this route carries
     * the same ability so an unauthorized user never renders the page.
     */
    public function staffingWizard(): InertiaResponse
    {
        return Inertia::render('Deployment/StaffingWizard');
    }
}
