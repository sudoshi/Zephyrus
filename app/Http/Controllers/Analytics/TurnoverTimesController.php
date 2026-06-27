<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analytics\TurnoverTimesService;
use Inertia\Inertia;

class TurnoverTimesController extends Controller
{
    public function index(TurnoverTimesService $turnoverTimes)
    {
        return Inertia::render('Analytics/TurnoverTimes', [
            'turnoverData' => $turnoverTimes->build(),
        ]);
    }
}
