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

    public function discoverFhir(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_key' => ['nullable', 'string', 'max:160'],
            'vendor' => ['nullable', 'string', 'max:120'],
            'base_url' => ['nullable', 'url'],
            'fhir_version' => ['nullable', 'string', 'max:40'],
            'client_id' => ['nullable', 'string', 'max:190'],
            'jwks_secret_ref' => ['nullable', 'string', 'max:255'],
            'token_url' => ['nullable', 'url'],
        ]);

        return response()->json(['data' => $this->connectors->discoverFhirCapabilities($validated)]);
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
