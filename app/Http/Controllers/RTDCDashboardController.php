<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class RTDCDashboardController extends Controller
{
    /**
     * Display the RTDC dashboard.
     *
     * @return \Inertia\Response
     */
public function index(Request $request)
{
    $request->session()->put('workflow', 'rtdc');
    return Inertia::render('Dashboard/RTDC');
}

    /**
     * Display the department census page.
     *
     * @return \Inertia\Response
     */
    public function departmentCensus()
    {
        return Inertia::render('RTDC/Analytics/DepartmentCensus');
    }

    /**
     * Display the bed tracking page.
     *
     * @return \Inertia\Response
     */
    public function bedTracking()
    {
        return Inertia::render('RTDC/BedTracking');
    }

    /**
     * Display the ancillary services page.
     *
     * @return \Inertia\Response
     */
    public function ancillaryServices()
    {
        return Inertia::render('RTDC/AncillaryServices');
    }

    /**
     * Display the discharge prediction page.
     *
     * @return \Inertia\Response
     */
    public function dischargePrediction()
    {
        return Inertia::render('RTDC/DischargePrediction');
    }

    /**
     * Display the global huddle page.
     *
     * @return \Inertia\Response
     */
    public function globalHuddle()
    {
        return Inertia::render('RTDC/GlobalHuddle');
    }

    /**
     * Display the unit huddle page.
     *
     * @return \Inertia\Response
     */
    public function unitHuddle()
    {
        return Inertia::render('RTDC/UnitHuddle');
    }

    /**
     * Display the service huddle page.
     *
     * @return \Inertia\Response
     */
    public function serviceHuddle(Request $request)
    {
        $request->session()->put('workflow', 'rtdc');
        return Inertia::render('RTDC/ServiceHuddle');
    }

    /**
     * Display the utilization page.
     *
     * @return \Inertia\Response
     */
    public function utilization()
    {
        return Inertia::render('RTDC/Analytics/Utilization');
    }

    /**
     * Display the performance metrics page.
     *
     * @return \Inertia\Response
     */
    public function performance()
    {
        return Inertia::render('RTDC/Analytics/Performance');
    }

    /**
     * Display the resources analytics page.
     *
     * @return \Inertia\Response
     */
    public function resources()
    {
        return Inertia::render('RTDC/Analytics/Resources');
    }

    /**
     * Display the trends page.
     *
     * @return \Inertia\Response
     */
    public function trends()
    {
        return Inertia::render('RTDC/Analytics/Trends');
    }

    /**
     * Display the demand forecast page.
     *
     * @return \Inertia\Response
     */
    public function demandForecast()
    {
        return Inertia::render('RTDC/Predictions/DemandForecast');
    }

    /**
     * Display the resource planning page.
     *
     * @return \Inertia\Response
     */
    public function resourcePlanning()
    {
        return Inertia::render('RTDC/Predictions/ResourcePlanning');
    }

    /**
     * Display the discharge predictions page.
     *
     * @return \Inertia\Response
     */
    public function dischargePredictions()
    {
        return Inertia::render('RTDC/Predictions/DischargePredictions');
    }

    /**
     * Display the risk assessment page.
     *
     * @return \Inertia\Response
     */
    public function riskAssessment()
    {
        return Inertia::render('RTDC/Predictions/RiskAssessment');
    }
}
