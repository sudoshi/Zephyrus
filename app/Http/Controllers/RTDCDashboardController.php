<?php

namespace App\Http\Controllers;

use App\Services\RTDCService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class RTDCDashboardController extends Controller
{
    public function __construct(
        private readonly RTDCService $rtdcService,
    ) {}

    /**
     * Display the RTDC dashboard.
     */
    public function index(Request $request): InertiaResponse
    {
        $this->rtdcService->activateWorkflow($request);

        return Inertia::render('Dashboard/RTDC');
    }

    /**
     * Display the department census page.
     */
    public function departmentCensus(): InertiaResponse
    {
        return Inertia::render('RTDC/Analytics/DepartmentCensus');
    }

    /**
     * Display the bed tracking page.
     */
    public function bedTracking(): InertiaResponse
    {
        return Inertia::render('RTDC/BedTracking');
    }

    /**
     * Display the ancillary services page.
     */
    public function ancillaryServices(): InertiaResponse
    {
        return Inertia::render('RTDC/AncillaryServices');
    }

    /**
     * Display the discharge prediction page.
     */
    public function dischargePrediction(): InertiaResponse
    {
        return Inertia::render('RTDC/DischargePrediction');
    }

    /**
     * Display the global huddle page.
     */
    public function globalHuddle(): InertiaResponse
    {
        return Inertia::render('RTDC/GlobalHuddle');
    }

    /**
     * Display the unit huddle page.
     */
    public function unitHuddle(): InertiaResponse
    {
        return Inertia::render('RTDC/UnitHuddle');
    }

    /**
     * Display the service huddle page.
     */
    public function serviceHuddle(Request $request): InertiaResponse
    {
        $this->rtdcService->activateWorkflow($request);

        return Inertia::render('RTDC/ServiceHuddle');
    }

    /**
     * Display the utilization page.
     */
    public function utilization(): InertiaResponse
    {
        return Inertia::render('RTDC/Analytics/Utilization');
    }

    /**
     * Display the performance metrics page.
     */
    public function performance(): InertiaResponse
    {
        return Inertia::render('RTDC/Analytics/Performance');
    }

    /**
     * Display the resources analytics page.
     */
    public function resources(): InertiaResponse
    {
        return Inertia::render('RTDC/Analytics/Resources');
    }

    /**
     * Display the trends page.
     */
    public function trends(): InertiaResponse
    {
        return Inertia::render('RTDC/Analytics/Trends');
    }

    /**
     * Display the demand forecast page.
     */
    public function demandForecast(): InertiaResponse
    {
        return Inertia::render('RTDC/Predictions/DemandForecast');
    }

    /**
     * Display the resource planning page.
     */
    public function resourcePlanning(): InertiaResponse
    {
        return Inertia::render('RTDC/Predictions/ResourcePlanning');
    }

    /**
     * Display the discharge predictions page.
     */
    public function dischargePredictions(): InertiaResponse
    {
        return Inertia::render('RTDC/Predictions/DischargePredictions');
    }

    /**
     * Display the risk assessment page.
     */
    public function riskAssessment(): InertiaResponse
    {
        return Inertia::render('RTDC/Predictions/RiskAssessment');
    }
}
