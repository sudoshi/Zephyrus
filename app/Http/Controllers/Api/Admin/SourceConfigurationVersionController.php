<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesIntegrationCorrelation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\IntegrationSourceRequest;
use App\Integrations\Healthcare\Services\SourceConfigurationVersionService;
use Illuminate\Http\JsonResponse;

final class SourceConfigurationVersionController extends Controller
{
    use ResolvesIntegrationCorrelation;

    public function __construct(private readonly SourceConfigurationVersionService $versions) {}

    public function index(int $source): JsonResponse
    {
        return response()->json(['data' => $this->versions->history($source)]);
    }

    public function store(IntegrationSourceRequest $request, int $source): JsonResponse
    {
        $validated = $request->validated();

        return response()->json(['data' => $this->versions->propose(
            $source,
            $validated,
            (int) $validated['expected_configuration_version_id'],
            $request->user()?->getAuthIdentifier(),
            (string) $validated['change_reason'],
            $this->correlationId($request),
        )], 201);
    }
}
