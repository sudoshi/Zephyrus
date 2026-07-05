<?php

namespace App\Http\Controllers\Api\Deployment\Staffing;

use App\Http\Controllers\Controller;
use App\Services\Staffing\CoverageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase F4 (§8): post-commit coverage — staffed vs unstaffed units per service line
 * for a facility (feeds readiness criterion 12). Gated by manageDeploymentConfig.
 */
class StaffCoverageController extends Controller
{
    public function __construct(private readonly CoverageService $coverage) {}

    public function index(Request $request): JsonResponse
    {
        $facilityKey = $request->query('facility');

        if (! is_string($facilityKey) || $facilityKey === '') {
            return response()->json(['message' => 'A facility query parameter is required.'], 422);
        }

        return response()->json(['data' => $this->coverage->report($facilityKey)]);
    }
}
