<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesIntegrationCorrelation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\IntegrationEndpointRequest;
use App\Integrations\Healthcare\Services\IntegrationConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IntegrationEndpointController extends Controller
{
    use ResolvesIntegrationCorrelation;

    public function __construct(private readonly IntegrationConfigurationService $configuration) {}

    public function index(int $source): JsonResponse
    {
        return response()->json(['data' => $this->configuration->endpoints($source)]);
    }

    public function store(IntegrationEndpointRequest $request, int $source): JsonResponse
    {
        return response()->json(['data' => $this->configuration->createEndpoint(
            $source,
            $request->validated(),
            $request->user()?->getAuthIdentifier(),
            $this->correlationId($request),
        )], 201);
    }

    public function update(IntegrationEndpointRequest $request, int $source, int $endpoint): JsonResponse
    {
        return response()->json(['data' => $this->configuration->updateEndpoint(
            $source,
            $endpoint,
            $request->validated(),
            $request->user()?->getAuthIdentifier(),
            $this->correlationId($request),
        )]);
    }

    public function destroy(Request $request, int $source, int $endpoint): Response
    {
        $this->configuration->deleteEndpoint(
            $source,
            $endpoint,
            $request->user()?->getAuthIdentifier(),
            $this->correlationId($request),
        );

        return response()->noContent();
    }
}
