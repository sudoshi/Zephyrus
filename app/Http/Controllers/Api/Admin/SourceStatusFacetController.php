<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesIntegrationCorrelation;
use App\Http\Controllers\Controller;
use App\Integrations\Healthcare\Services\IntegrationConfigurationAuditService;
use App\Integrations\Healthcare\Services\SourceStatusFacetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * INT-LIFECYCLE — the six-facet source status surface.
 *
 * Reads require viewIntegrations; conformance/contract/incident mutations
 * require operateIntegrations + admin.scope:source and are audited. Every
 * mutation appends an immutable event; the projection advances atomically.
 */
final class SourceStatusFacetController extends Controller
{
    use ResolvesIntegrationCorrelation;

    public function __construct(
        private readonly SourceStatusFacetService $facets,
        private readonly IntegrationConfigurationAuditService $audit,
    ) {}

    public function show(int $source): JsonResponse
    {
        return response()->json([
            'data' => [
                ...$this->facets->snapshot($source),
                'history' => [
                    'conformance' => $this->facets->conformanceHistory($source),
                    'contract' => $this->facets->contractHistory($source),
                    'incident' => $this->facets->incidentHistory($source),
                ],
            ],
        ]);
    }

    public function recordConformance(Request $request, int $source): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(SourceStatusFacetService::CONFORMANCE_STATUSES)],
            'profile_key' => ['nullable', 'string', 'max:120'],
            'profile_version' => ['nullable', 'string', 'max:60'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $result = $this->facets->recordConformance(
            $source,
            (string) $validated['status'],
            $validated['profile_key'] ?? null,
            $validated['profile_version'] ?? null,
            (string) $validated['reason'],
            $request->user()?->getAuthIdentifier(),
        );

        return $this->audited($request, $source, 'conformance_recorded', 'source_conformance', $result);
    }

    public function recordContract(Request $request, int $source): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(SourceStatusFacetService::CONTRACT_STATUSES)],
            'evidence_record_id' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $result = $this->facets->recordContract(
            $source,
            (string) $validated['status'],
            isset($validated['evidence_record_id']) ? (int) $validated['evidence_record_id'] : null,
            (string) $validated['reason'],
            $request->user()?->getAuthIdentifier(),
        );

        return $this->audited($request, $source, 'contract_recorded', 'source_contract', $result);
    }

    public function recordIncident(Request $request, int $source): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(SourceStatusFacetService::INCIDENT_STATUSES)],
            'breach_uuid' => ['nullable', 'uuid'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $result = $this->facets->recordIncident(
            $source,
            (string) $validated['status'],
            $validated['breach_uuid'] ?? null,
            (string) $validated['reason'],
            $request->user()?->getAuthIdentifier(),
        );

        return $this->audited($request, $source, 'incident_recorded', 'source_incident', $result);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function audited(Request $request, int $source, string $action, string $entityType, array $result): JsonResponse
    {
        $correlationUuid = $this->correlationId($request);
        $this->audit->record(
            $request->user()?->getAuthIdentifier(),
            $action,
            $entityType,
            (int) $result['eventId'],
            'source:'.$source,
            [],
            collect($result)->only(['facet', 'status', 'eventId', 'occurredAtIso'])->all(),
            $correlationUuid,
        );

        return response()->json(['data' => $result], 201);
    }
}
