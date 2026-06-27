<?php

namespace App\Http\Controllers\Predictions;

use App\Http\Controllers\Controller;
use App\Services\Predictions\ResourcePlanningService;
use Inertia\Inertia;

class ResourcePlanningController extends Controller
{
    public function index(ResourcePlanningService $resourcePlanning)
    {
        return Inertia::render('Predictions/ResourcePlanning', [
            'resourcePlan' => $resourcePlanning->build(),
        ]);
    }
}
