<?php

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class TransportDashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboardService) {}

    public function dashboard(): InertiaResponse
    {
        $this->dashboardService->updateWorkflowPreference(auth()->user(), 'transport');

        return Inertia::render('Transport/Dashboard', ['workflow' => 'transport']);
    }

    public function requests(): InertiaResponse
    {
        return Inertia::render('Transport/Requests', ['workflow' => 'transport']);
    }

    public function dispatch(): InertiaResponse
    {
        return Inertia::render('Transport/Dispatch', ['workflow' => 'transport']);
    }

    public function inpatient(): InertiaResponse
    {
        return Inertia::render('Transport/Inpatient', ['workflow' => 'transport']);
    }

    public function transfers(): InertiaResponse
    {
        return Inertia::render('Transport/Transfers', ['workflow' => 'transport']);
    }

    public function discharge(): InertiaResponse
    {
        return Inertia::render('Transport/Discharge', ['workflow' => 'transport']);
    }

    public function ems(): InertiaResponse
    {
        return Inertia::render('Transport/Ems', ['workflow' => 'transport']);
    }

    public function careTransitions(): InertiaResponse
    {
        return Inertia::render('Transport/CareTransitions', ['workflow' => 'transport']);
    }

    public function resources(): InertiaResponse
    {
        return Inertia::render('Transport/Resources', ['workflow' => 'transport']);
    }

    public function analytics(): InertiaResponse
    {
        return Inertia::render('Transport/Analytics', ['workflow' => 'transport']);
    }

    public function settings(): InertiaResponse
    {
        return Inertia::render('Transport/IntegrationSettings', ['workflow' => 'transport']);
    }
}
