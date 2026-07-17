<?php

namespace App\Http\Controllers\Api\Home;

use App\Http\Controllers\Controller;
use App\Services\Home\HomeCensusService;
use Illuminate\Http\JsonResponse;

/**
 * Live census payload for the Virtual Bed Board. Same builder as the Inertia
 * page, fetched over the authenticated API (invalidate-on-ping doctrine —
 * public channels never carry patient-level data).
 */
class HomeCensusController extends Controller
{
    public function index(HomeCensusService $census): JsonResponse
    {
        return response()->json($census->build());
    }
}
