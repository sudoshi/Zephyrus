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
    public function index()
    {
        return Inertia::render('Dashboard/RTDC');
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
     * Display the services huddle page.
     *
     * @return \Inertia\Response
     */
    public function servicesHuddle()
    {
        return Inertia::render('RTDC/ServicesHuddle');
    }
}
