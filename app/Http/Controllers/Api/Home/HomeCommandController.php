<?php

namespace App\Http\Controllers\Api\Home;

use App\Http\Controllers\Controller;
use App\Services\Home\HomeCommandService;
use Illuminate\Http\JsonResponse;

/**
 * Live Virtual Ward Command payload — same builder as the Inertia page,
 * fetched over the authenticated API (invalidate-on-ping doctrine).
 */
class HomeCommandController extends Controller
{
    public function index(HomeCommandService $command): JsonResponse
    {
        return response()->json($command->build());
    }
}
