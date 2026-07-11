<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesIntegrationCorrelation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\CredentialReferenceRequest;
use App\Integrations\Healthcare\Services\IntegrationConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IntegrationCredentialController extends Controller
{
    use ResolvesIntegrationCorrelation;

    public function __construct(private readonly IntegrationConfigurationService $configuration) {}

    public function index(int $source): JsonResponse
    {
        return response()->json(['data' => $this->configuration->credentials($source)]);
    }

    public function store(CredentialReferenceRequest $request, int $source): JsonResponse
    {
        return response()->json(['data' => $this->configuration->createCredential(
            $source,
            $request->validated(),
            $request->user()?->getAuthIdentifier(),
            $this->correlationId($request),
        )], 201);
    }

    public function update(CredentialReferenceRequest $request, int $source, int $credential): JsonResponse
    {
        return response()->json(['data' => $this->configuration->updateCredential(
            $source,
            $credential,
            $request->validated(),
            $request->user()?->getAuthIdentifier(),
            $this->correlationId($request),
        )]);
    }

    public function destroy(Request $request, int $source, int $credential): Response
    {
        $this->configuration->deleteCredential(
            $source,
            $credential,
            $request->user()?->getAuthIdentifier(),
            $this->correlationId($request),
        );

        return response()->noContent();
    }
}
