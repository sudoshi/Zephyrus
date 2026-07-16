<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Integrations\Healthcare\Services\IntegrationConfigurationService;
use App\Integrations\Healthcare\Services\SourceLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SourceLifecycleController extends Controller
{
    public function __construct(
        private readonly SourceLifecycleService $lifecycle,
        private readonly IntegrationConfigurationService $configuration,
    ) {}

    public function index(int $source): JsonResponse
    {
        return response()->json(['data' => $this->lifecycle->history($source)]);
    }

    public function transition(Request $request, int $source): JsonResponse
    {
        $validated = $request->validate([
            'to_state' => ['required', Rule::in(['draft', 'discovery', 'configured', 'validating', 'degraded', 'suspended'])],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $this->lifecycle->transition(
            $source,
            (string) $validated['to_state'],
            (string) $validated['reason'],
            $request->user()?->getAuthIdentifier(),
        );

        return response()->json(['data' => $this->configuration->source($source)]);
    }
}
