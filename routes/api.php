<?php

use App\Http\Controllers\Api\Admin\EnterpriseConnectorController;
use App\Http\Controllers\Api\Admin\IntegrationHealthController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\BlockScheduleController;
use App\Http\Controllers\Api\Eddy\EddyActionController;
use App\Http\Controllers\Api\Eddy\EddyAdminController;
use App\Http\Controllers\Api\Eddy\EddyChatController;
use App\Http\Controllers\Api\Evs\EvsRequestController;
use App\Http\Controllers\Api\Facility\FacilityModelController;
use App\Http\Controllers\Api\Mobile\AuthController as MobileAuthController;
use App\Http\Controllers\Api\Mobile\DeviceController as MobileDeviceController;
use App\Http\Controllers\Api\Mobile\EddyController as MobileEddyController;
use App\Http\Controllers\Api\Mobile\ForYouController as MobileForYouController;
use App\Http\Controllers\Api\Mobile\MeController as MobileMeController;
use App\Http\Controllers\Api\Mobile\RealtimeConfigController as MobileRealtimeConfigController;
use App\Http\Controllers\Api\Mobile\RtdcController as MobileRtdcController;
use App\Http\Controllers\Api\Ops\AgentController;
use App\Http\Controllers\Api\Ops\OperationalActionController;
use App\Http\Controllers\Api\Ops\OperationsGraphController;
use App\Http\Controllers\Api\Ops\SimulationController;
use App\Http\Controllers\Api\ORCaseController;
use App\Http\Controllers\Api\PatientFlow\PatientFlowController;
use App\Http\Controllers\Api\PatientFlow\PatientFlowIngestController;
use App\Http\Controllers\Api\PatientFlow\PatientFlowStreamController;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\Rtdc\BarrierController;
use App\Http\Controllers\Api\Rtdc\BedRequestController;
use App\Http\Controllers\Api\Rtdc\CensusController;
use App\Http\Controllers\Api\Rtdc\HuddleController;
use App\Http\Controllers\Api\Rtdc\PredictionController;
use App\Http\Controllers\Api\Rtdc\ReconciliationController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\Staffing\StaffingController;
use App\Http\Controllers\Api\Transport\RegionalTransferController;
use App\Http\Controllers\Api\Transport\TransportRequestController;
use App\Http\Controllers\CommandCenterController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Health Check
Route::get('/health', function () {
    try {
        \DB::connection()->getPdo();

        return response()->json([
            'status' => 'healthy',
            'database' => 'connected',
            'timestamp' => now()->toISOString(),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'unhealthy',
            'database' => 'disconnected',
        ], 503);
    }
});

// Command Center drill-downs (web session auth)
Route::middleware(['web', 'auth', 'throttle:60,1'])->prefix('command-center')->group(function () {
    Route::get('/drilldown', [CommandCenterController::class, 'drilldown']);
});

// Facility blueprint/digital twin model (web session auth)
Route::middleware(['web', 'auth', 'throttle:60,1'])->prefix('facility')->group(function () {
    Route::get('/model/summary', [FacilityModelController::class, 'summary']);
});

// Patient Flow 4D navigator (web session auth)
Route::middleware(['web', 'auth', 'throttle:60,1'])->prefix('patient-flow')->group(function () {
    Route::get('/summary', [PatientFlowController::class, 'summary']);
    Route::get('/locations', [PatientFlowController::class, 'locations']);
    Route::get('/events', [PatientFlowController::class, 'events']);
    Route::get('/tracks', [PatientFlowController::class, 'tracks']);
    Route::get('/state', [PatientFlowController::class, 'state']);
    Route::get('/ambient', [PatientFlowController::class, 'ambient']);
    Route::get('/fhir/bundle', [PatientFlowController::class, 'fhirBundle']);
    Route::get('/stream/adt', PatientFlowStreamController::class);
    Route::post('/ingest/hl7v2', [PatientFlowIngestController::class, 'hl7v2']);
});

// RTDC — Real-Time Demand Capacity (web session auth)
// The `web` group provides StartSession (reads the session cookie) so the Inertia SPA
// authenticates via the web guard. Without it these routes 401 in the browser because
// the `api` middleware group has no session. CSRF is auto-skipped in the testing env;
// in the browser axios sends the X-XSRF-TOKEN header (bootstrap.js withXSRFToken=true).
Route::middleware(['web', 'auth', 'throttle:60,1'])->prefix('rtdc')->group(function () {
    Route::get('/units', [CensusController::class, 'units']);

    Route::get('/units/{unitId}/prediction', [PredictionController::class, 'show']);
    Route::post('/units/{unitId}/capacity', [PredictionController::class, 'capacity']);
    Route::post('/units/{unitId}/demand', [PredictionController::class, 'demand']);
    Route::post('/units/{unitId}/plan', [PredictionController::class, 'plan']);

    Route::post('/huddles', [HuddleController::class, 'open']);
    Route::post('/huddles/{huddleId}/close', [HuddleController::class, 'close']);
    Route::get('/bed-meeting', [HuddleController::class, 'bedMeeting']);

    Route::get('/barriers', [BarrierController::class, 'index']);
    Route::post('/barriers', [BarrierController::class, 'store']);
    Route::post('/barriers/{barrierId}/resolve', [BarrierController::class, 'resolve']);

    Route::get('/units/{unitId}/reliability', [ReconciliationController::class, 'latest']);

    Route::get('/bed-requests', [BedRequestController::class, 'index']);
    Route::post('/bed-requests', [BedRequestController::class, 'store']);
    Route::get('/bed-requests/{bedRequestId}/recommendations', [BedRequestController::class, 'recommendations']);
    Route::post('/bed-requests/{bedRequestId}/decision', [BedRequestController::class, 'decision']);
});

// Transport command center (web session auth)
Route::middleware(['web', 'auth', 'throttle:60,1'])->prefix('transport')->group(function () {
    Route::get('/overview', [TransportRequestController::class, 'overview']);
    Route::get('/regional-summary', [RegionalTransferController::class, 'summary']);
    Route::post('/regional-simulation', [RegionalTransferController::class, 'simulate']);
    Route::get('/requests', [TransportRequestController::class, 'index']);
    Route::post('/requests', [TransportRequestController::class, 'store']);
    Route::get('/requests/{transportRequestId}', [TransportRequestController::class, 'show']);
    Route::post('/requests/{transportRequestId}/regional-decision', [RegionalTransferController::class, 'decide']);
    Route::post('/requests/{transportRequestId}/regional-agent-draft', [RegionalTransferController::class, 'agentDraft']);
    Route::post('/requests/{transportRequestId}/assign', [TransportRequestController::class, 'assign']);
    Route::post('/requests/{transportRequestId}/status', [TransportRequestController::class, 'status']);
    Route::post('/requests/{transportRequestId}/cancel', [TransportRequestController::class, 'cancel']);
    Route::post('/requests/{transportRequestId}/handoff', [TransportRequestController::class, 'handoff']);
    Route::get('/resources', [TransportRequestController::class, 'resources']);
    Route::get('/vendors', [TransportRequestController::class, 'vendors']);
});

// EVS / environmental services workflow (web session auth)
Route::middleware(['web', 'auth', 'throttle:60,1'])->prefix('evs')->group(function () {
    Route::get('/overview', [EvsRequestController::class, 'overview']);
    Route::get('/requests', [EvsRequestController::class, 'index']);
    Route::post('/requests', [EvsRequestController::class, 'store']);
    Route::get('/requests/{evsRequestId}', [EvsRequestController::class, 'show']);
    Route::post('/requests/{evsRequestId}/assign', [EvsRequestController::class, 'assign']);
    Route::post('/requests/{evsRequestId}/status', [EvsRequestController::class, 'status']);
    Route::post('/requests/{evsRequestId}/cancel', [EvsRequestController::class, 'cancel']);
    Route::get('/resources', [EvsRequestController::class, 'resources']);
});

// Staffing operations / staffing office (web session auth)
Route::middleware(['web', 'auth', 'throttle:60,1'])->prefix('staffing')->group(function () {
    Route::get('/overview', [StaffingController::class, 'overview']);
    Route::get('/plans', [StaffingController::class, 'plans']);
    Route::get('/requests', [StaffingController::class, 'index']);
    Route::post('/requests', [StaffingController::class, 'store']);
    Route::get('/requests/{staffingRequestId}', [StaffingController::class, 'show']);
    Route::post('/requests/{staffingRequestId}/assign', [StaffingController::class, 'assign']);
    Route::post('/requests/{staffingRequestId}/status', [StaffingController::class, 'status']);
    Route::post('/requests/{staffingRequestId}/cancel', [StaffingController::class, 'cancel']);
    Route::get('/resources', [StaffingController::class, 'resources']);
});

// Operations graph foundation (web session auth)
Route::middleware(['web', 'auth', 'throttle:60,1'])->prefix('ops')->group(function () {
    Route::get('/graph/snapshot', [OperationsGraphController::class, 'snapshot']);
    Route::get('/graph/nodes/{node}', [OperationsGraphController::class, 'node']);
    Route::get('/recommendations', [OperationsGraphController::class, 'recommendations']);
    Route::get('/agent-inbox', [OperationsGraphController::class, 'agentInbox']);
    Route::get('/agents/definitions', [AgentController::class, 'definitions']);
    Route::post('/agents/capacity-commander/run', [AgentController::class, 'runCapacityCommander']);
    Route::post('/agents/data-quality/run', [AgentController::class, 'runDataQuality']);
    Route::post('/agents/executive-briefing/run', [AgentController::class, 'runExecutiveBriefing']);
    Route::get('/agents/runs/{run}', [AgentController::class, 'show']);
    Route::post('/approvals/{approval}/decision', [OperationalActionController::class, 'decideApproval']);
    Route::post('/actions/{action}/assign', [OperationalActionController::class, 'assign']);
    Route::post('/actions/{action}/start', [OperationalActionController::class, 'start']);
    Route::post('/actions/{action}/complete', [OperationalActionController::class, 'complete']);
    Route::post('/actions/{action}/override', [OperationalActionController::class, 'override']);
    Route::post('/actions/{action}/expire', [OperationalActionController::class, 'expire']);
    Route::post('/simulation-scenarios/{scenario}/promote', [SimulationController::class, 'promote']);
});

// Eddy — process-aware AI agent (web session auth). Read-only chat in Phase 1.
Route::middleware(['web', 'auth', 'throttle:60,1'])->prefix('eddy')->group(function () {
    Route::post('/chat', [EddyChatController::class, 'chat']);
    Route::post('/chat/stream', [EddyChatController::class, 'stream']);
    Route::get('/conversations', [EddyChatController::class, 'conversations']);
    Route::get('/conversations/{uuid}', [EddyChatController::class, 'conversation']);
    Route::delete('/conversations/{uuid}', [EddyChatController::class, 'destroy']);
    // Phase 3 — advice-not-autopilot action proposals (the dock human proposes/approves).
    Route::get('/actions/catalog', [EddyActionController::class, 'catalog']);
    Route::post('/actions/propose', [EddyActionController::class, 'propose']);
    Route::post('/agent/token', [EddyActionController::class, 'mintAgentToken']);

    // Phase 6 — super-admin: cost/redaction accounting, route simulator, knowledge review.
    Route::get('/admin/usage', [EddyAdminController::class, 'usage']);
    Route::post('/admin/route-simulate', [EddyAdminController::class, 'simulate']);
    Route::get('/admin/knowledge/proposed', [EddyAdminController::class, 'proposedKnowledge']);
    Route::post('/admin/knowledge/{uuid}/review', [EddyAdminController::class, 'reviewKnowledge']);
});

// Eddy agent callback (scoped Sanctum token: ops:read/ops:draft, NEVER ops:approve).
Route::middleware(['auth:sanctum', 'throttle:60,1'])->prefix('eddy/agent')->group(function () {
    Route::post('/actions/propose', [EddyActionController::class, 'propose']);
});

// Admin integration health (web session auth)
Route::middleware(['web', 'auth', 'throttle:60,1'])->prefix('admin/integrations')->group(function () {
    Route::get('/health', IntegrationHealthController::class);
    Route::get('/enterprise', [EnterpriseConnectorController::class, 'summary']);
    Route::post('/enterprise/fhir/capability-discovery', [EnterpriseConnectorController::class, 'discoverFhir']);
    Route::post('/enterprise/writeback-drafts', [EnterpriseConnectorController::class, 'createWritebackDraft']);
});

// OR Cases
Route::prefix('cases')->middleware('throttle:60,1')->group(function () {
    Route::get('/', [ORCaseController::class, 'index']);
    Route::post('/', [ORCaseController::class, 'store']);
    Route::put('/{id}', [ORCaseController::class, 'update']);
    Route::get('/today', [ORCaseController::class, 'todaysCases']);
    Route::get('/metrics', [ORCaseController::class, 'metrics']);
    Route::get('/room-status', [ORCaseController::class, 'roomStatus']);
});

// Block Schedule
Route::prefix('blocks')->middleware('throttle:60,1')->group(function () {
    Route::get('/', [BlockScheduleController::class, 'index']);
    Route::post('/', [BlockScheduleController::class, 'store']);
    Route::get('/utilization', [BlockScheduleController::class, 'utilization']);
    Route::get('/service-utilization', [BlockScheduleController::class, 'serviceUtilization']);
    Route::get('/room-utilization', [BlockScheduleController::class, 'roomUtilization']);
});

// Analytics
Route::middleware(['web', 'auth', 'throttle:60,1'])->prefix('analytics')->group(function () {
    Route::get('/overview', [AnalyticsController::class, 'overview']);
    Route::get('/live', [AnalyticsController::class, 'live']);
    Route::get('/retrospective', [AnalyticsController::class, 'retrospective']);
    Route::get('/predictive', [AnalyticsController::class, 'predictive']);
    Route::get('/process-intelligence', [AnalyticsController::class, 'processIntelligence']);
    Route::get('/opportunities', [AnalyticsController::class, 'opportunities']);
    Route::get('/workbench', [AnalyticsController::class, 'workbench']);
    Route::get('/data-quality', [AnalyticsController::class, 'dataQuality']);
    Route::get('/metrics/{metricKey}/lineage', [AnalyticsController::class, 'metricLineage'])
        ->where('metricKey', '[A-Za-z0-9_\-]+');
});

Route::prefix('analytics')->middleware('throttle:60,1')->group(function () {
    Route::get('/service-performance', [AnalyticsController::class, 'servicePerformance']);
    Route::get('/provider-performance', [AnalyticsController::class, 'providerPerformance']);
    Route::get('/historical-trends', [AnalyticsController::class, 'historicalTrends']);
});

// Reference Data
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/services', [ServiceController::class, 'index']);
    Route::get('/rooms', [RoomController::class, 'index']);
    Route::get('/providers', [ProviderController::class, 'index']);
});

// Improvement Process Maps
Route::prefix('improvement')->middleware('throttle:60,1')->group(function () {
    Route::get('/api/nursing-operations', function () {
        $workflow = request('workflow', 'Bed Placement');

        // Map each process workflow to its OCEL process-map file. Honor the
        // requested workflow instead of always returning Bed Placement.
        $workflowFiles = [
            'Bed Placement' => 'bed_placement_process_map.json',
            'Admissions' => 'admissions_process_map.json',
            'Discharges' => 'discharges_process_map.json',
            'ED to Inpatient' => 'ed_to_inpatient_process_map.json',
        ];

        $file = $workflowFiles[$workflow] ?? $workflowFiles['Bed Placement'];
        $jsonPath = base_path('sample-pages/OCEL/'.$file);

        if (is_file($jsonPath)) {
            $data = json_decode((string) file_get_contents($jsonPath), true);

            if (json_last_error() === JSON_ERROR_NONE && isset($data['nodes'], $data['edges'])) {
                return response()->json($data);
            }
        }

        // Fallback structure when the requested workflow's file is missing/invalid.
        $fallbackData = [
            'nodes' => [
                [
                    'id' => 'node_0',
                    'label' => 'Emergency Department (ED)',
                    'type' => 'start',
                    'count' => 26,
                    'avgDuration' => 0.2,
                    'description' => 'Patient coming from emergency department',
                ],
                [
                    'id' => 'node_1',
                    'label' => 'Bed request initiated',
                    'type' => 'activity',
                    'count' => 50,
                    'avgDuration' => 11.0,
                    'description' => 'Request for inpatient bed is initiated in the system',
                ],
                [
                    'id' => 'node_2',
                    'label' => 'Patient arrived at bed',
                    'type' => 'end',
                    'count' => 50,
                    'avgDuration' => 10.7,
                    'description' => 'Patient has arrived at the assigned bed',
                ],
            ],
            'edges' => [
                [
                    'id' => 'edge_0',
                    'source' => 'node_0',
                    'target' => 'node_1',
                    'count' => 26,
                    'label' => '26 cases',
                ],
                [
                    'id' => 'edge_1',
                    'source' => 'node_1',
                    'target' => 'node_2',
                    'count' => 50,
                    'label' => '50 cases',
                ],
            ],
            'metrics' => [
                'totalCases' => 50,
                'avgDuration' => '21.9m',
                'bottleneckCount' => 1,
                'reworkPercentage' => '0%',
                'throughput' => '7/day',
                'complianceRate' => '100%',
                'variantCount' => 1,
            ],
        ];

        return response()->json($fallbackData);
    });
});

/*
|--------------------------------------------------------------------------
| Hummingbird mobile companion — token auth (ADDITIVE)
|--------------------------------------------------------------------------
| Bearer-token (Sanctum) API for the native mobile apps. This is a NEW,
| parallel surface: it uses the `sanctum` guard (not the web session), does
| not apply CSRF, and does NOT alter the locked web auth flow. See
| docs/hummingbird/ for the full plan and api-contract.
*/

// Public token exchange (tightly rate-limited). Issuance honors must_change_password.
Route::prefix('auth')->group(function () {
    Route::post('/token', [MobileAuthController::class, 'token'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/token/refresh', [MobileAuthController::class, 'refresh']);
        Route::post('/token/revoke', [MobileAuthController::class, 'revoke']);
        Route::post('/change-password', [MobileAuthController::class, 'changePassword']);
    });
});

// The mobile BFF — one token-gated, role-scoped, PHI-minimized surface.
// `CheckForAnyAbility:mobile:read` rejects narrowly-scoped tokens (e.g. the
// must_change_password challenge token) while admin `*` tokens pass. Write-vs-read
// ability splitting (mobile:act) + per-resource Policies land with the P1 writes.
Route::middleware(['auth:sanctum', CheckForAnyAbility::class.':mobile:read', 'throttle:120,1'])->prefix('mobile/v1')->group(function () {
    Route::get('/me', [MobileMeController::class, 'show']);
    Route::put('/me/preferences', [MobileMeController::class, 'updatePreferences']);

    Route::post('/devices', [MobileDeviceController::class, 'store']);
    Route::delete('/devices/{device}', [MobileDeviceController::class, 'destroy']);

    Route::get('/realtime/config', [MobileRealtimeConfigController::class, 'show']);

    Route::get('/rtdc/census', [MobileRtdcController::class, 'census']);

    Route::get('/for-you', [MobileForYouController::class, 'index']);

    // Eddy — process-aware AI agent on mobile. Chat + conversations + the approval
    // inbox are reads (mobile:read). The approval DECISION is a human write and
    // additionally requires mobile:act — Eddy's scoped token never reaches here.
    Route::prefix('eddy')->group(function () {
        Route::post('/chat', [MobileEddyController::class, 'chat']);
        Route::post('/chat/stream', [MobileEddyController::class, 'stream']);
        Route::get('/conversations', [MobileEddyController::class, 'conversations']);
        Route::get('/conversations/{uuid}', [MobileEddyController::class, 'conversation']);
        Route::get('/approvals', [MobileEddyController::class, 'approvals']);
        Route::get('/approvals/{uuid}', [MobileEddyController::class, 'approval']);
        Route::post('/approvals/{uuid}/decision', [MobileEddyController::class, 'decide'])
            ->middleware(CheckForAnyAbility::class.':mobile:act');
    });
});
