<?php

namespace App\Http\Controllers\Api\Home;

use App\Http\Controllers\Controller;
use App\Services\Home\HomeLogisticsService;
use Illuminate\Http\JsonResponse;

/**
 * Field operations & logistics payload — the ONE surface where a patient's
 * physical address is permitted (ACUM-PRD-HAH-001 §4.2 / build brief §8.4).
 */
class HomeLogisticsController extends Controller
{
    public function index(HomeLogisticsService $logistics): JsonResponse
    {
        return response()->json($logistics->build());
    }
}
