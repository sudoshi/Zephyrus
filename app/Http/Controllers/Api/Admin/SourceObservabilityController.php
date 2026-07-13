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
}
