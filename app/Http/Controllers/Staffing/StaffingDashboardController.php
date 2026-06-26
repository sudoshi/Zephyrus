<?php

namespace App\Http\Controllers\Staffing;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class StaffingDashboardController extends Controller
{
    public function index(): InertiaResponse
    {
        return Inertia::render('Staffing/StaffingOffice', ['workflow' => 'rtdc']);
    }
}
