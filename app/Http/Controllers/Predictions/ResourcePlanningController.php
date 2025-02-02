<?php

namespace App\Http\Controllers\Predictions;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class ResourcePlanningController extends Controller
{
    public function index()
    {
        return Inertia::render('Predictions/ResourcePlanning');
    }
}
