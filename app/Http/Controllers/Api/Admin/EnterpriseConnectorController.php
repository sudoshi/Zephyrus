<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Integrations\Healthcare\Services\EnterpriseConnectorControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EnterpriseConnectorController extends Controller
{
    public function __construct(private readonly EnterpriseConnectorControlService $connectors) {}

    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->connectors->summary()]);
    }

    public function discoverFhir(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'fhir_discovery_not_configured',
                'message' => 'FHIR discovery requires a configured protocol checker.',
            ],
        ], 501);
    }

    public function createWritebackDraft(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_key' => ['nullable', 'string', 'max:160'],
            'vendor' => ['nullable', 'string', 'max:120'],
            'target_system' => ['nullable', 'string', 'max:120'],
            'resource_type' => ['required', Rule::in(['Task', 'ServiceRequest', 'TransportRequest', 'EvsRequest', 'SecureMessage'])],
            'draft_type' => ['nullable', 'string', 'max:80'],
            'resource_payload' => ['nullable', 'array'],
        ]);

        return response()->json([
            'data' => $this->connectors->createWritebackDraft($validated, $request->user()?->id),
        ]);
    }
}
