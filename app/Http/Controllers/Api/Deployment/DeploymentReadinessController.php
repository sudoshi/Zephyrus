<?php

namespace App\Http\Controllers\Api\Deployment;

use App\Http\Controllers\Controller;
use App\Services\Deployment\DeploymentReadinessService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Serves the deployment-readiness scorecard (plan §16) for a facility as JSON — the same
 * structure the `deployment:readiness` command evaluates. Gated by viewDeploymentConsole.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 6, §6)
 */
class DeploymentReadinessController extends Controller
{
    public function __construct(private readonly DeploymentReadinessService $service) {}

    public function show(string $facilityKey): JsonResponse
    {
        try {
            $report = $this->service->evaluate($facilityKey);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return response()->json(['data' => $report]);
    }
}
