<?php

namespace App\Http\Controllers\Api\CarePathways;

use App\Http\Controllers\Controller;
use App\Services\CarePathways\CarePathwayDemoScenarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CarePathwayDemoController extends Controller
{
    public function __invoke(Request $request, CarePathwayDemoScenarioService $scenario): JsonResponse
    {
        $validated = $request->validate([
            'step' => ['nullable', 'integer', 'min:0', 'max:'.CarePathwayDemoScenarioService::MAX_STEP],
        ]);

        return response()->json([
            'data' => $scenario->scenario((int) ($validated['step'] ?? 0)),
        ]);
    }
}
