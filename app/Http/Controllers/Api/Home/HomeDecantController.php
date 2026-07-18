<?php

namespace App\Http\Controllers\Api\Home;

use App\Http\Controllers\Controller;
use App\Services\Home\HomeCapacityService;
use Illuminate\Http\JsonResponse;

/**
 * The decant line (ACUM-PRD-HAH-001 §6.2): home-eligible counts + free-slot
 * forecast for the RTDC global huddle. Also refreshes the home rows in
 * prod.rtdc_predictions so the forecast lives alongside physical capacity.
 */
class HomeDecantController extends Controller
{
    public function index(HomeCapacityService $capacity): JsonResponse
    {
        $capacity->writePredictions();

        return response()->json($capacity->decant());
    }
}
