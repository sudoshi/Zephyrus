<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analytics\OrUtilizationService;
use Inertia\Inertia;

class ORUtilizationController extends Controller
{
    public function index(OrUtilizationService $orUtilizationService)
    {
        return Inertia::render('Analytics/ORUtilization', [
            'orUtilizationData' => $orUtilizationService->build(),
        ]);
    }
}
