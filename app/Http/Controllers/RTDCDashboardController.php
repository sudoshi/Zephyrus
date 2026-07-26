<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesFlowLens;
use App\Services\Dashboard\RtdcDashboardService;
use App\Services\Rtdc\AncillaryServicesService;
use App\Services\Rtdc\BedTrackingService;
use App\Services\Rtdc\DemandForecastService;
use App\Services\Rtdc\DischargePrioritiesService;
use App\Services\Rtdc\PerformanceAnalyticsService;
use App\Services\Rtdc\ResourceAnalyticsService;
use App\Services\Rtdc\ResourcePlanningAnalyticsService;
use App\Services\Rtdc\RiskAssessmentService;
use App\Services\Rtdc\ServiceHuddleService;
use App\Services\Rtdc\TrendsAnalyticsService;
use App\Services\Rtdc\UtilizationAnalyticsService;
use App\Services\RtdcService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class RTDCDashboardController extends Controller
{
    use ResolvesFlowLens;

    public function __construct(
        private readonly RtdcService $rtdcService,
    ) {}

    /**
     * Display the RTDC dashboard.
     */
    public function index(Request $request, RtdcDashboardService $dashboard): InertiaResponse
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
    public function bedTracking(BedTrackingService $bedTracking): InertiaResponse
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
    public function ancillaryServices(AncillaryServicesService $service): InertiaResponse
    {
        return Inertia::render('RTDC/AncillaryServices', [
            'unitServices' => $service->build(),
        ]);
    }

    /**
     * Display the discharge priorities page (live, computed from prod.*).
     */
    public function dischargePriorities(DischargePrioritiesService $service): InertiaResponse
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
    public function serviceHuddle(Request $request, ServiceHuddleService $serviceHuddle): InertiaResponse
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
     * Display the Virtual Rounds board (route gated by EnsureRoundsEnabled).
     * The board fetches its data from /api/rounds/* — props stay minimal.
     */
    public function virtualRounds(Request $request): InertiaResponse
    {
        $this->rtdcService->activateWorkflow($request);

        return Inertia::render('RTDC/VirtualRounds', [
            'workflow' => 'rtdc',
        ]);
    }

    /**
     * Display the utilization page (live, computed from prod.*).
     */
    public function utilization(UtilizationAnalyticsService $utilization): InertiaResponse
    {
        return Inertia::render('RTDC/Analytics/Utilization', $utilization->build());
    }

    /**
     * Display the performance metrics page (live, computed from prod.*).
     */
    public function performance(PerformanceAnalyticsService $service): InertiaResponse
    {
        return Inertia::render('RTDC/Analytics/Performance', $service->build());
    }

    /**
     * Display the resources analytics page (live, computed from prod.*).
     */
    public function resources(ResourceAnalyticsService $service): InertiaResponse
    {
        return Inertia::render('RTDC/Analytics/Resources', $service->build());
    }

    /**
     * Display the trends page (live, computed from prod.*).
     */
    public function trends(TrendsAnalyticsService $trends): InertiaResponse
    {
        return Inertia::render('RTDC/Analytics/Trends', $trends->build());
    }

    /**
     * Display the demand forecast page (live, computed from prod.*).
     */
    public function demandForecast(DemandForecastService $service): InertiaResponse
    {
        return Inertia::render('RTDC/Predictions/DemandForecast', $service->build());
    }

    /**
     * Display the resource planning page (live, computed from prod.*).
     */
    public function resourcePlanning(ResourcePlanningAnalyticsService $service): InertiaResponse
    {
        return Inertia::render('RTDC/Predictions/ResourcePlanning', $service->build());
    }

    /**
     * Display the risk assessment page (live, computed from prod.*).
     */
    public function riskAssessment(RiskAssessmentService $service): InertiaResponse
    {
        return Inertia::render('RTDC/Predictions/RiskAssessment', $service->build());
    }
}
