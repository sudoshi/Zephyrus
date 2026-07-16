<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesIntegrationCorrelation;
use App\Http\Controllers\Controller;
use App\Integrations\Healthcare\Services\IntegrationConfigurationAuditService;
use App\Integrations\Healthcare\Services\SourceObservabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SourceObservabilityController extends Controller
{
    use ResolvesIntegrationCorrelation;

    public function __construct(
        private readonly SourceObservabilityService $observability,
        private readonly IntegrationConfigurationAuditService $audit,
    ) {}

    public function show(int $source): JsonResponse
    {
        return response()->json(['data' => $this->observability->snapshot($source)]);
    }

    public function collect(Request $request, int $source): JsonResponse
    {
        $correlationUuid = $this->correlationId($request);
        $observation = $this->observability->observe(
            $source,
            CarbonImmutable::now(),
            'manual',
            $correlationUuid,
            $correlationUuid,
            $request->user()?->getAuthIdentifier(),
        );
        $this->audit->record(
            $request->user()?->getAuthIdentifier(),
            'observed',
            'source_health',
            (int) $observation['observationId'],
            'source:'.$source,
            [],
            collect($observation)->only([
                'observationId', 'observationUuid', 'batchUuid', 'correlationUuid',
                'sourceId', 'sloDefinitionId', 'sloDefinitionStatus', 'status',
                'protocolStatus', 'maintenanceActive', 'observedAtIso', 'freshUntilIso',
                'summary', 'evidenceSha256',
            ])->all(),
            $correlationUuid,
        );

        return response()->json(['data' => $observation], 201);
    }

    public function acknowledge(Request $request, int $source, string $breach): JsonResponse
    {
        $validated = $request->validate([
            'reason_code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,79}$/'],
        ]);
        $correlationUuid = $this->correlationId($request);
        $result = $this->observability->acknowledgeBreach(
            $source,
            $breach,
            (int) $request->user()?->getAuthIdentifier(),
            $validated['reason_code'],
        );

        return $this->auditedBreachAction($request, $source, $result, 'acknowledged', $correlationUuid);
    }

    public function escalate(Request $request, int $source, string $breach): JsonResponse
    {
        $validated = $request->validate([
            'reason_code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,79}$/'],
        ]);
        $correlationUuid = $this->correlationId($request);
        $result = $this->observability->escalateBreach(
            $source,
            $breach,
            (int) $request->user()?->getAuthIdentifier(),
            $validated['reason_code'],
        );

        return $this->auditedBreachAction($request, $source, $result, 'escalated', $correlationUuid);
    }

    public function linkIncident(Request $request, int $source, string $breach): JsonResponse
    {
        $validated = $request->validate([
            'incident_reference' => ['required', 'string', 'min:1', 'max:255'],
        ]);
        $correlationUuid = $this->correlationId($request);
        $result = $this->observability->linkBreachIncident(
            $source,
            $breach,
            (int) $request->user()?->getAuthIdentifier(),
            $validated['incident_reference'],
        );

        return $this->auditedBreachAction($request, $source, $result, 'incident_linked', $correlationUuid);
    }

    public function review(Request $request, int $source, string $breach): JsonResponse
    {
        $validated = $request->validate([
            'root_cause_code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,49}$/'],
            'corrective_action_code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,49}$/'],
            'recurrence_risk' => ['required', 'string', 'in:low,medium,high'],
        ]);
        $correlationUuid = $this->correlationId($request);
        $result = $this->observability->reviewBreach(
            $source,
            $breach,
            (int) $request->user()?->getAuthIdentifier(),
            $validated,
        );

        return $this->auditedBreachAction($request, $source, $result, 'reviewed', $correlationUuid);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function auditedBreachAction(Request $request, int $source, array $result, string $action, string $correlationUuid): JsonResponse
    {
        $this->audit->record(
            $request->user()?->getAuthIdentifier(),
            $action,
            'source_slo_breach',
            null,
            'source:'.$source,
            [],
            collect($result)->only([
                'breachUuid', 'sourceId', 'metricKey', 'eventType', 'statusAfter',
                'reasonCode', 'occurredAtIso', 'incidentLinked',
            ])->all(),
            $correlationUuid,
        );

        return response()->json(['data' => $result], 201);
    }
}
