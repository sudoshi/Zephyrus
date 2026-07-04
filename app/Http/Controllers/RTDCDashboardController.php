<?php

namespace App\Http\Controllers;

use App\Services\RtdcService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class RTDCDashboardController extends Controller
{
    use \App\Http\Controllers\Concerns\ResolvesFlowLens;

    public function __construct(
        private readonly RtdcService $rtdcService,
    ) {}

    /**
     * Display the RTDC dashboard.
     */
    public function index(Request $request, \App\Services\Dashboard\RtdcDashboardService $dashboard): InertiaResponse
    {
        $this->rtdcService->activateWorkflow($request);

        return Inertia::render('Dashboard/RTDC', $dashboard->build());
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
    public function bedTracking(\App\Services\Rtdc\BedTrackingService $bedTracking): InertiaResponse
    {
        return Inertia::render('RTDC/BedTracking', $bedTracking->build());
    }

    /**
     * Display the 4D patient-flow navigator.
     */
    public function patientFlowNavigator(Request $request): InertiaResponse
    {
        $this->rtdcService->activateWorkflow($request);

        return Inertia::render('RTDC/PatientFlowNavigator', [
            'workflow' => 'rtdc',
            'facilityCode' => config('facility_models.zep_500.facility_code', 'ZEPHYRUS-500'),
            'flowLens' => $this->resolveFlowLens($request),
            'flowUnits' => $this->flowUnits(),
        ]);
    }

    /**
     * Display the ancillary services page.
     */
    public function ancillaryServices(\App\Services\Rtdc\AncillaryServicesService $service): InertiaResponse
    {
        return Inertia::render('RTDC/AncillaryServices', [
            'unitServices' => $service->build(),
        ]);
    }

    /**
     * Display the discharge priorities page (live, computed from prod.*).
     */
    public function dischargePriorities(\App\Services\Rtdc\DischargePrioritiesService $service): InertiaResponse
    {
        return Inertia::render('RTDC/DischargePriorities', $service->build());
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
    public function unitHuddle(Request $request): InertiaResponse
    {
        $this->rtdcService->activateWorkflow($request);

        return Inertia::render('RTDC/UnitHuddle');
    }

    /**
     * Display the service huddle page.
     */
    public function serviceHuddle(Request $request, \App\Services\Rtdc\ServiceHuddleService $serviceHuddle): InertiaResponse
    {
        $this->rtdcService->activateWorkflow($request);

        return Inertia::render('RTDC/ServiceHuddle', $serviceHuddle->build());
    }

    /**
     * Display the bed placement page.
     */
    public function bedPlacement(Request $request): InertiaResponse
    {
        $this->rtdcService->activateWorkflow($request);

        return Inertia::render('RTDC/BedPlacement');
    }

    /**
     * Display the utilization page (live, computed from prod.*).
     */
    public function utilization(\App\Services\Rtdc\UtilizationAnalyticsService $utilization): InertiaResponse
    {
        return Inertia::render('RTDC/Analytics/Utilization', $utilization->build());
    }

    /**
     * Display the performance metrics page (live, computed from prod.*).
     */
    public function performance(\App\Services\Rtdc\PerformanceAnalyticsService $service): InertiaResponse
    {
        return Inertia::render('RTDC/Analytics/Performance', $service->build());
    }

    /**
     * Display the resources analytics page (live, computed from prod.*).
     */
    public function resources(\App\Services\Rtdc\ResourceAnalyticsService $service): InertiaResponse
    {
        return Inertia::render('RTDC/Analytics/Resources', $service->build());
    }

    /**
     * Display the trends page (live, computed from prod.*).
     */
    public function trends(\App\Services\Rtdc\TrendsAnalyticsService $trends): InertiaResponse
    {
        return Inertia::render('RTDC/Analytics/Trends', $trends->build());
    }

    /**
     * Display the demand forecast page (live, computed from prod.*).
     */
    public function demandForecast(\App\Services\Rtdc\DemandForecastService $service): InertiaResponse
    {
        return Inertia::render('RTDC/Predictions/DemandForecast', $service->build());
    }

    /**
     * Display the resource planning page (live, computed from prod.*).
     */
    public function resourcePlanning(\App\Services\Rtdc\ResourcePlanningAnalyticsService $service): InertiaResponse
    {
        return Inertia::render('RTDC/Predictions/ResourcePlanning', $service->build());
    }

    /**
     * Display the risk assessment page (live, computed from prod.*).
     */
    public function riskAssessment(\App\Services\Rtdc\RiskAssessmentService $service): InertiaResponse
    {
        return Inertia::render('RTDC/Predictions/RiskAssessment', $service->build());
    }
}
