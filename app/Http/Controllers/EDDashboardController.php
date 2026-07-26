<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesFlowLens;
use App\Services\Dashboard\EdDashboardService;
use App\Services\Ed\AcuityPredictionService;
use App\Services\Ed\ArrivalPredictionService;
use App\Services\Ed\ResourceAnalyticsService;
use App\Services\Ed\ResourceManagementService;
use App\Services\Ed\ResourceOptimizationService;
use App\Services\Ed\TreatmentService;
use App\Services\Ed\TriageService;
use App\Services\Ed\WaitTimeService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EDDashboardController extends Controller
{
    use ResolvesFlowLens;

    /**
     * Display the ED dashboard.
     *
     * @return Response
     */
    public function index(Request $request, EdDashboardService $edDashboard)
    {
        $request->session()->put('workflow', 'emergency');

        return Inertia::render('Dashboard/ED', array_merge([
            'workflow' => 'emergency',
        ], $edDashboard->build()));
    }

    /**
     * Display the ED wait time analytics.
     *
     * @return Response
     */
    public function waitTime(WaitTimeService $waitTime)
    {
        return Inertia::render('ED/Analytics/WaitTime', $waitTime->build());
    }

    /**
     * Display the ED patient flow analytics.
     *
     * @return Response
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
     * @return Response
     */
    public function resources(ResourceAnalyticsService $resourceAnalytics)
    {
        return Inertia::render('ED/Analytics/Resources', $resourceAnalytics->build());
    }

    /**
     * Display the ED triage status board.
     *
     * @return Response
     */
    public function triage(TriageService $triage)
    {
        return Inertia::render('ED/Operations/Triage', $triage->build());
    }

    /**
     * Display the ED treatment tracking board.
     *
     * @return Response
     */
    public function treatment(TreatmentService $treatment)
    {
        return Inertia::render('ED/Operations/Treatment', $treatment->build());
    }

    /**
     * Display the ED resource management dashboard.
     *
     * @return Response
     */
    public function resourceManagement(ResourceManagementService $resourceManagement)
    {
        return Inertia::render('ED/Operations/Resources', $resourceManagement->build());
    }

    /**
     * Display the ED arrival forecast dashboard.
     *
     * @return Response
     */
    public function arrival(ArrivalPredictionService $arrivalPrediction)
    {
        return Inertia::render('ED/Predictions/Arrival', $arrivalPrediction->build());
    }

    /**
     * Display the ED acuity prediction dashboard.
     *
     * @return Response
     */
    public function acuity(AcuityPredictionService $acuityPrediction)
    {
        return Inertia::render('ED/Predictions/Acuity', $acuityPrediction->build());
    }

    /**
     * Display the ED resource planning dashboard.
     *
     * @return Response
     */
    public function resourcePlanning(ResourceOptimizationService $resourceOptimization)
    {
        return Inertia::render('ED/Predictions/Resources', $resourceOptimization->build());
    }
}
