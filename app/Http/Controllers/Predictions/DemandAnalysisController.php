<?php

namespace App\Http\Controllers\Predictions;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class DemandAnalysisController extends Controller
{
    public function index()
    {
        return Inertia::render('Predictions/DemandAnalysis');
    }
}
