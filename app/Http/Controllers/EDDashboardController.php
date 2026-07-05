<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class EDDashboardController extends Controller
{
    use \App\Http\Controllers\Concerns\ResolvesFlowLens;

    /**
     * Display the ED dashboard.
     *
     * @return \Inertia\Response
     */
    public function index(Request $request, \App\Services\Dashboard\EdDashboardService $edDashboard)
    {
        $request->session()->put('workflow', 'emergency');

        return Inertia::render('Dashboard/ED', array_merge([
            'workflow' => 'emergency',
        ], $edDashboard->build()));
    }

    /**
     * Display the ED wait time analytics.
     *
     * @return \Inertia\Response
     */
    public function waitTime(\App\Services\Ed\WaitTimeService $waitTime)
    {
        return Inertia::render('ED/Analytics/WaitTime', $waitTime->build());
    }

    /**
     * Display the ED patient flow analytics.
     *
     * @return \Inertia\Response
     */
    public function flow(Request $request)
    {
        return Inertia::render('ED/Analytics/Flow', [
            'flowLens' => $this->resolveFlowLens($request),
            'flowUnits' => $this->flowUnits(),
        ]);
    }

    /**
     * Display the ED resource utilization analytics.
     *
     * @return \Inertia\Response
     */
    public function resources(\App\Services\Ed\ResourceAnalyticsService $resourceAnalytics)
    {
        return Inertia::render('ED/Analytics/Resources', $resourceAnalytics->build());
    }

    /**
     * Display the ED triage status board.
     *
     * @return \Inertia\Response
     */
    public function triage(\App\Services\Ed\TriageService $triage)
    {
        return Inertia::render('ED/Operations/Triage', $triage->build());
    }

    /**
     * Display the ED treatment tracking board.
     *
     * @return \Inertia\Response
     */
    public function treatment(\App\Services\Ed\TreatmentService $treatment)
    {
        return Inertia::render('ED/Operations/Treatment', $treatment->build());
    }

    /**
     * Display the ED resource management dashboard.
     *
     * @return \Inertia\Response
     */
    public function resourceManagement(\App\Services\Ed\ResourceManagementService $resourceManagement)
    {
        return Inertia::render('ED/Operations/Resources', $resourceManagement->build());
    }

    /**
     * Display the ED arrival forecast dashboard.
     *
     * @return \Inertia\Response
     */
    public function arrival(\App\Services\Ed\ArrivalPredictionService $arrivalPrediction)
    {
        return Inertia::render('ED/Predictions/Arrival', $arrivalPrediction->build());
    }

    /**
     * Display the ED acuity prediction dashboard.
     *
     * @return \Inertia\Response
     */
    public function acuity(\App\Services\Ed\AcuityPredictionService $acuityPrediction)
    {
        return Inertia::render('ED/Predictions/Acuity', $acuityPrediction->build());
    }

    /**
     * Display the ED resource planning dashboard.
     *
     * @return \Inertia\Response
     */
    public function resourcePlanning(\App\Services\Ed\ResourceOptimizationService $resourceOptimization)
    {
        return Inertia::render('ED/Predictions/Resources', $resourceOptimization->build());
    }
}
