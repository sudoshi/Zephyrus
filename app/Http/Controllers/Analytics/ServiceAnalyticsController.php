<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class ServiceAnalyticsController extends Controller
{
    public function index()
    {
        return Inertia::render('Analytics/ServiceAnalytics');
    }
}
