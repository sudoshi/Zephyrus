<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class TurnoverTimesController extends Controller
{
    public function index()
    {
        return Inertia::render('Analytics/TurnoverTimes');
    }
}
