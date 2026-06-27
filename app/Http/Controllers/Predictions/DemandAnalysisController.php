<?php

namespace App\Http\Controllers\Predictions;

use App\Http\Controllers\Controller;
use App\Services\Predictions\DemandAnalysisService;
use Inertia\Inertia;

class DemandAnalysisController extends Controller
{
    public function index(DemandAnalysisService $demand)
    {
        $forecast = $demand->build();

        return Inertia::render('Predictions/DemandAnalysis', [
            'metrics' => $forecast['metrics'],
            'series' => $forecast['series'],
            'byService' => $forecast['byService'],
            'hasData' => $forecast['hasData'],
            'projectionMethod' => $forecast['projectionMethod'],
        ]);
    }
}
