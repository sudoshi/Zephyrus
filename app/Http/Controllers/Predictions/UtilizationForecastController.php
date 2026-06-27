<?php

namespace App\Http\Controllers\Predictions;

use App\Http\Controllers\Controller;
use App\Services\Predictions\UtilizationForecastService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UtilizationForecastController extends Controller
{
    public function index(Request $request, UtilizationForecastService $forecast)
    {
        $timeframe = $request->string('timeframe', 'month')->toString();

        return Inertia::render('Predictions/UtilizationForecast', [
            'forecast' => $forecast->build($timeframe),
        ]);
    }
}
