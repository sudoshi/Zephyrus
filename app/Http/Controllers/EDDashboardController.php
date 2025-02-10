<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class EDDashboardController extends Controller
{
    /**
     * Display the ED dashboard.
     *
     * @return \Inertia\Response
     */
public function index(Request $request)
{
    $request->session()->put('workflow', 'emergency');
    return Inertia::render('Dashboard/ED', [
        'workflow' => 'emergency'
    ]);
}

    /**
     * Display the ED wait time analytics.
     *
     * @return \Inertia\Response
     */
    public function waitTime()
    {
        return Inertia::render('ED/Analytics/WaitTime');
    }

    /**
     * Display the ED patient flow analytics.
     *
     * @return \Inertia\Response
     */
    public function flow()
    {
        return Inertia::render('ED/Analytics/Flow');
    }

    /**
     * Display the ED resource utilization analytics.
     *
     * @return \Inertia\Response
     */
    public function resources()
    {
        return Inertia::render('ED/Analytics/Resources');
    }

    /**
     * Display the ED triage status board.
     *
     * @return \Inertia\Response
     */
    public function triage()
    {
        return Inertia::render('ED/Operations/Triage');
    }

    /**
     * Display the ED treatment tracking board.
     *
     * @return \Inertia\Response
     */
    public function treatment()
    {
        return Inertia::render('ED/Operations/Treatment');
    }

    /**
     * Display the ED resource management dashboard.
     *
     * @return \Inertia\Response
     */
    public function resourceManagement()
    {
        return Inertia::render('ED/Operations/Resources');
    }

    /**
     * Display the ED arrival forecast dashboard.
     *
     * @return \Inertia\Response
     */
    public function arrival()
    {
        return Inertia::render('ED/Predictions/Arrival');
    }

    /**
     * Display the ED acuity prediction dashboard.
     *
     * @return \Inertia\Response
     */
    public function acuity()
    {
        return Inertia::render('ED/Predictions/Acuity');
    }

    /**
     * Display the ED resource planning dashboard.
     *
     * @return \Inertia\Response
     */
    public function resourcePlanning()
    {
        return Inertia::render('ED/Predictions/Resources');
    }
}
