<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesIntegrationCorrelation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\IntegrationSourceRequest;
use App\Integrations\Healthcare\Services\IntegrationConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationSourceController extends Controller
{
    use ResolvesIntegrationCorrelation;

    public function __construct(private readonly IntegrationConfigurationService $configuration) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->configuration->sources()]);
    }

    public function store(IntegrationSourceRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->configuration->createSource(
            $request->validated(),
            $request->user()?->getAuthIdentifier(),
            $this->correlationId($request),
        )], 201);
    }

    public function show(int $source): JsonResponse
    {
        return response()->json(['data' => $this->configuration->source($source)]);
    }

    public function update(IntegrationSourceRequest $request, int $source): JsonResponse
    {
        return response()->json(['data' => $this->configuration->updateSource(
            $source,
            $request->validated(),
            $request->user()?->getAuthIdentifier(),
            $this->correlationId($request),
        )]);
    }

    public function destroy(Request $request, int $source): JsonResponse
    {
        return response()->json(['data' => $this->configuration->retireSource(
            $source,
            $request->user()?->getAuthIdentifier(),
            $this->correlationId($request),
        )]);
    }
}
