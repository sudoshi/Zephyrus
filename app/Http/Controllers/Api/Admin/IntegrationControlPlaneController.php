<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Integrations\Healthcare\Services\IntegrationControlPlaneService;
use Illuminate\Http\JsonResponse;

class IntegrationControlPlaneController extends Controller
{
    public function __construct(private readonly IntegrationControlPlaneService $controlPlane) {}

    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => $this->controlPlane->snapshot()]);
    }
}
