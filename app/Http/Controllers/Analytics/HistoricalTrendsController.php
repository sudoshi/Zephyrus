<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class HistoricalTrendsController extends Controller
{
    public function index()
    {
        return Inertia::render('Analytics/HistoricalTrends');
    }
}
