<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Integrations\Healthcare\Services\IntegrationControlPlaneService;
use Illuminate\Http\JsonResponse;

class IntegrationHealthController extends Controller
{
    public function __construct(private readonly IntegrationControlPlaneService $controlPlane) {}

    public function __invoke(): JsonResponse
    {
        $snapshot = $this->controlPlane->snapshot();

        return response()->json(['data' => [
            'generatedAtIso' => $snapshot['generatedAtIso'],
            'status' => $snapshot['status'],
            'freshnessPolicy' => $snapshot['freshnessPolicy'],
            'sources' => $snapshot['sources'],
            'counts' => [
                'sources' => $snapshot['counts']['sources'],
                'activeSources' => $snapshot['counts']['activeSources'],
                'healthySources' => $snapshot['counts']['healthySources'],
                'degradedSources' => $snapshot['counts']['degradedSources'],
                'staleSources' => $snapshot['counts']['staleSources'],
                'failedSources' => $snapshot['counts']['failedSources'],
                'protocolHealthySources' => $snapshot['counts']['protocolHealthySources'],
                'protocolDegradedSources' => $snapshot['counts']['protocolDegradedSources'],
                'protocolFailedSources' => $snapshot['counts']['protocolFailedSources'],
                'openDeadLetters' => $snapshot['counts']['openDeadLetters'],
                'pendingProjectionEvents' => $snapshot['counts']['pendingProjectionEvents'],
                'queuedJobs' => $snapshot['counts']['queuedJobs'],
                'failedQueueJobs' => $snapshot['counts']['failedQueueJobs'],
            ],
        ]]);
    }
}
