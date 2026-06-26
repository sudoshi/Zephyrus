<?php

namespace App\Http\Controllers\Api\Ops;

use App\Http\Controllers\Controller;
use App\Models\Ops\SimulationScenario;
use App\Services\Ops\OperationsSimulationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SimulationController extends Controller
{
    public function __construct(private readonly OperationsSimulationService $simulations) {}

    public function promote(SimulationScenario $scenario, Request $request): JsonResponse
    {
        try {
            $payload = $this->simulations->promoteScenario($scenario, $request->user()?->id);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $payload]);
    }
}
