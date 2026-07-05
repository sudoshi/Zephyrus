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
use App\Http\Controllers\Api\Mobile\ActivityController as MobileActivityController;
use App\Http\Controllers\Api\Mobile\AltitudeController as MobileAltitudeController;
use App\Http\Controllers\Api\Mobile\AuthController as MobileAuthController;
use App\Http\Controllers\Api\Mobile\CommandController as MobileCommandController;
use App\Http\Controllers\Api\Mobile\DeviceController as MobileDeviceController;
use App\Http\Controllers\Api\Mobile\EddyContextController as MobileEddyContextController;
use App\Http\Controllers\Api\Mobile\EddyController as MobileEddyController;
use App\Http\Controllers\Api\Mobile\EvsController as MobileEvsController;
use App\Http\Controllers\Api\Mobile\FlowController as MobileFlowController;
use App\Http\Controllers\Api\Mobile\ForYouController as MobileForYouController;
use App\Http\Controllers\Api\Mobile\ImprovementController as MobileImprovementController;
use App\Http\Controllers\Api\Mobile\MeController as MobileMeController;
use App\Http\Controllers\Api\Mobile\OpsController as MobileOpsController;
use App\Http\Controllers\Api\Mobile\ORController as MobileORController;
use App\Http\Controllers\Api\Mobile\PatientContextController as MobilePatientContextController;
use App\Http\Controllers\Api\Mobile\RealtimeConfigController as MobileRealtimeConfigController;
use App\Http\Controllers\Api\Mobile\RtdcController as MobileRtdcController;
use App\Http\Controllers\Api\Mobile\StaffingController as MobileStaffingController;
use App\Http\Controllers\Api\Mobile\TransportController as MobileTransportController;
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

// Zephyrus 2.0 cockpit serving layer (web session auth). /snapshot is a
// cached read of the single replaced cockpit_snapshots row (ETag/304).
Route::middleware(['web', 'auth', 'throttle:60,1'])->prefix('cockpit')->group(function () {
    Route::get('/snapshot', [\App\Http\Controllers\Api\CockpitController::class, 'snapshot']);
    Route::get('/scopes', [\App\Http\Controllers\Api\CockpitController::class, 'scopes']);
    Route::get('/face', [\App\Http\Controllers\Api\CockpitController::class, 'face']);
    Route::get('/drill/{domain}', [\App\Http\Controllers\Api\CockpitController::class, 'drill'])
        ->where('domain', '[a-z]+');
    // P8 WS-3 — the A2P patient lens as an in-place cockpit drill. Persona-gated
    // by EnforceFlowLens:patients (patient_dots=none personas get 403); the ptok
    // constraint 404s any non-context ref before it reaches the service.
    Route::get('/patient/{contextRef}', [\App\Http\Controllers\PatientLensController::class, 'show'])
        ->middleware(\App\Http\Middleware\EnforceFlowLens::class.':patients')
        ->where('contextRef', 'ptok_[A-Za-z0-9]+');
    Route::get('/stream', \App\Http\Controllers\Api\CockpitStreamController::class);
    Route::get('/kpi-definitions', [\App\Http\Controllers\Api\CockpitController::class, 'kpiDefinitions']);
    Route::put('/kpi-definitions/{metricKey}', [\App\Http\Controllers\Api\CockpitController::class, 'updateKpiDefinition'])
        ->middleware(\App\Http\Middleware\AdminMiddleware::class);
});

// Zephyrus 2.0 Part X (X1) — Arena OCPM serving layer. Laravel proxies to the
// PHI-free OCPM sidecar and caches discovered maps in arena.maps. Gated by
// EnsureArenaEnabled (ARENA_ENABLED, default off) so the group 404s while off.
Route::middleware(['web', 'auth', 'throttle:30,1', \App\Http\Middleware\EnsureArenaEnabled::class])
    ->prefix('arena')->group(function () {
        Route::get('/health', [\App\Http\Controllers\Api\ArenaController::class, 'health']);
        Route::get('/summary', [\App\Http\Controllers\Api\ArenaController::class, 'summary']);
        Route::get('/map', [\App\Http\Controllers\Api\ArenaController::class, 'map']);
        Route::get('/performance', [\App\Http\Controllers\Api\ArenaController::class, 'performance']);
        Route::get('/conformance', [\App\Http\Controllers\Api\ArenaController::class, 'conformance']);
    });

// Facility blueprint/digital twin model (web session auth)
Route::middleware(['web', 'auth', 'throttle:60,1'])->prefix('facility')->group(function () {
    Route::get('/model/summary', [FacilityModelController::class, 'summary']);
});

// Patient Flow 4D navigator (web session auth)
Route::middleware(['web', 'auth', 'throttle:60,1'])->prefix('patient-flow')->group(function () {
    // Aggregate reads — any authenticated user.
    Route::get('/summary', [PatientFlowController::class, 'summary']);
    Route::get('/locations', [PatientFlowController::class, 'locations']);
    Route::get('/ambient', [PatientFlowController::class, 'ambient']);

    // Patient-level reads — persona-lensed (FLOW-WINDOW-PLAN §6.4, closes G7):
    // requires a flow lens whose patient_dots policy is not `none`.
    Route::middleware(\App\Http\Middleware\EnforceFlowLens::class.':patients')->group(function () {
        Route::get('/events', [PatientFlowController::class, 'events']);
        Route::get('/tracks', [PatientFlowController::class, 'tracks']);
        Route::get('/state', [PatientFlowController::class, 'state']);
        Route::get('/fhir/bundle', [PatientFlowController::class, 'fhirBundle']);
        Route::get('/stream/adt', PatientFlowStreamController::class);
    });

    // The +24h projection stream (Flow Window prediction half) — lensed,
    // aggregate-safe: items are persona-clamped and identity-redacted by
    // the same lens the mobile window uses.
    Route::get('/projections', [PatientFlowController::class, 'projections'])
        ->middleware(\App\Http\Middleware\EnforceFlowLens::class);

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

    Route::get('/altitude/home', [MobileAltitudeController::class, 'home']);
    Route::get('/altitude/workspace/{domain}', [MobileAltitudeController::class, 'workspace']);
    Route::get('/drills/{itemUuid}', [MobileAltitudeController::class, 'drill']);
    Route::get('/patients/{contextRef}/operational-context', [MobilePatientContextController::class, 'show']);
    Route::get('/activity', [MobileActivityController::class, 'index']);
    Route::post('/activity/{eventUuid}/ack', [MobileActivityController::class, 'ack'])
        ->middleware(CheckForAnyAbility::class.':mobile:act');

    Route::get('/rtdc/census', [MobileRtdcController::class, 'census']);
    Route::get('/rtdc/house', [MobileRtdcController::class, 'house']);
    Route::get('/rtdc/bed-requests', [MobileRtdcController::class, 'placements']);
    Route::get('/rtdc/bed-requests/{id}/recommendations', [MobileRtdcController::class, 'placementRecommendations']);
    Route::post('/rtdc/bed-requests/{id}/decision', [MobileRtdcController::class, 'placeBed'])
        ->middleware(CheckForAnyAbility::class.':mobile:act');
    Route::post('/rtdc/barriers/{id}/resolve', [MobileRtdcController::class, 'resolveBarrier'])
        ->middleware(CheckForAnyAbility::class.':mobile:act');

    Route::get('/for-you', [MobileForYouController::class, 'index']);

    // Flow Window (FLOW-WINDOW-PLAN §6.4) — the persona-lensed 48h
    // spatiotemporal surface. /floors is the versioned plate asset (ETag);
    // /window serves snapshots + events + projections clamped by the
    // caller's lens (config/hummingbird/flow_lens.php). Server-side RBAC —
    // patient identity never exceeds the caller's A2P matrix depth.
    Route::prefix('flow')->group(function () {
        Route::get('/floors', [MobileFlowController::class, 'floors']);
        Route::get('/window', [MobileFlowController::class, 'window']);
    });

    // Transport (P1) — the frontline claim-and-run queue. Reads PHI-minimized; the lifecycle
    // writes (status transition + structured handoff) additionally require mobile:act.
    Route::prefix('transport')->group(function () {
        Route::get('/queue', [MobileTransportController::class, 'queue']);
        Route::post('/requests/{id}/status', [MobileTransportController::class, 'status'])
            ->middleware(CheckForAnyAbility::class.':mobile:act');
        Route::post('/requests/{id}/handoff', [MobileTransportController::class, 'handoff'])
            ->middleware(CheckForAnyAbility::class.':mobile:act');
    });

    // EVS / bed-turns (P2) — the frontline "next dirty bed" queue. Read PHI-minimized; the
    // Claim → Start → Complete lifecycle write requires mobile:act.
    Route::prefix('evs')->group(function () {
        Route::get('/queue', [MobileEvsController::class, 'queue']);
        Route::post('/requests/{id}/status', [MobileEvsController::class, 'status'])
            ->middleware(CheckForAnyAbility::class.':mobile:act');
    });

    // Executive (P9) — house strain + hero KPIs (Command Center reshaped, PHI-free).
    Route::get('/command/house', [MobileCommandController::class, 'house']);

    // OR board (P4 OR nurse / P7 periop manager) — live room status (simulated-clock anchored).
    Route::get('/or/board', [MobileORController::class, 'board']);

    // Capacity lead (P6) — operational approvals inbox; the decision is a governed mobile:act write.
    Route::get('/ops/inbox', [MobileOpsController::class, 'inbox']);
    Route::post('/ops/approvals/{uuid}/decision', [MobileOpsController::class, 'decide'])
        ->middleware(CheckForAnyAbility::class.':mobile:act');

    // Staffing coordinator (P10) — gaps + open requests; the fill action is a mobile:act write.
    Route::get('/staffing/overview', [MobileStaffingController::class, 'overview']);
    Route::post('/staffing/requests/{id}/fill', [MobileStaffingController::class, 'fill'])
        ->middleware(CheckForAnyAbility::class.':mobile:act');

    // PI / quality lead (P8) — PDSA cycles + improvement opportunities (read-only).
    Route::get('/improvement/pdsa', [MobileImprovementController::class, 'pdsa']);
    Route::get('/improvement/opportunities', [MobileImprovementController::class, 'opportunities']);

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
        Route::get('/context/{scopeRef}', [MobileEddyContextController::class, 'show']);
    });
});
