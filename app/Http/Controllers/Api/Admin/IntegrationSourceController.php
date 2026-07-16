<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesIntegrationCorrelation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\IntegrationSourceRequest;
use App\Integrations\Healthcare\Services\IntegrationConfigurationService;
use App\Services\Authorization\AdminScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationSourceController extends Controller
{
    use ResolvesIntegrationCorrelation;

    public function __construct(
        private readonly IntegrationConfigurationService $configuration,
        private readonly AdminScopeService $scopes,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->configuration->sources()]);
    }

    public function store(IntegrationSourceRequest $request): JsonResponse
    {
        $scope = $this->scopes->requireFacility($request);

        return response()->json(['data' => $this->configuration->createSource(
            [
                ...$request->validated(),
                'organization_id' => $scope->organizationId,
                'facility_id' => $scope->facilityId,
                'tenant_key' => $scope->organizationKey,
                'facility_key' => $scope->facilityKey,
            ],
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
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        return response()->json(['data' => $this->configuration->retireSource(
            $source,
            $request->user()?->getAuthIdentifier(),
            $this->correlationId($request),
            (string) $validated['reason'],
        )]);
    }
}
