<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesIntegrationCorrelation;
use App\Http\Controllers\Controller;
use App\Integrations\Healthcare\Services\EnterpriseConnectorControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EnterpriseConnectorController extends Controller
{
    use ResolvesIntegrationCorrelation;

    public function __construct(private readonly EnterpriseConnectorControlService $connectors) {}

    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->connectors->summary()]);
    }

    public function discoverFhir(Request $request): JsonResponse
    {
        $validated = $request->validate(['source_id' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $this->connectors->queueHealthCheck(
            (int) $validated['source_id'],
            $request->user()?->getAuthIdentifier(),
            $this->correlationId($request),
        )], 202);
    }

    public function healthCheck(Request $request, int $source): JsonResponse
    {
        return response()->json(['data' => $this->connectors->queueHealthCheck(
            $source,
            $request->user()?->getAuthIdentifier(),
            $this->correlationId($request),
        )], 202);
    }

    public function pollFhir(Request $request, int $source): JsonResponse
    {
        $validated = $request->validate([
            'resource_type' => ['required', Rule::in(['Encounter', 'Location'])],
        ]);

        return response()->json(['data' => $this->connectors->queueFhirPoll(
            $source,
            (string) $validated['resource_type'],
            $request->user()?->getAuthIdentifier(),
            $this->correlationId($request),
        )], 202);
    }

    public function previewReplay(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->connectors->previewReplay($this->replayScope($request))]);
    }

    public function queueReplay(Request $request): JsonResponse
    {
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 190 || preg_match('/^[A-Za-z0-9._:-]+$/', $idempotencyKey) !== 1) {
            return response()->json(['error' => [
                'code' => 'idempotency_key_required',
                'message' => 'Idempotency-Key must be 1-190 URL-safe characters.',
            ]], 422);
        }

        $result = $this->connectors->queueReplay(
            $this->replayScope($request),
            $idempotencyKey,
            $request->user()?->getAuthIdentifier(),
            $this->correlationId($request),
        );

        return response()->json(['data' => $result], $result['created'] ? 202 : 200);
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

    /** @return array<string, mixed> */
    private function replayScope(Request $request): array
    {
        return $request->validate([
            'source_id' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists(\App\Models\Integration\Source::class, 'source_id')],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
            'event_types' => ['sometimes', 'array', 'min:1', 'max:5'],
            'event_types.*' => ['string', Rule::in(['EncounterStarted', 'EncounterTransferred', 'EncounterDischarged', 'BedStatusChanged', 'AcuityChanged'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);
    }
}
