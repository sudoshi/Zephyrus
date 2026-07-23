<?php

use App\Http\Controllers\Api\Admin\ClinicalPayloadGovernanceController;
use App\Http\Controllers\Api\Admin\CredentialNetworkGovernanceController;
use App\Http\Controllers\Api\Admin\EnterpriseConnectorController;
use App\Http\Controllers\Api\Admin\GovernedIntegrationChangeController;
use App\Http\Controllers\Api\Admin\IntegrationControlPlaneController;
use App\Http\Controllers\Api\Admin\IntegrationCredentialController;
use App\Http\Controllers\Api\Admin\IntegrationEndpointController;
use App\Http\Controllers\Api\Admin\IntegrationHealthController;
use App\Http\Controllers\Api\Admin\IntegrationSourceController;
use App\Http\Controllers\Api\Admin\SourceConfigurationVersionController;
use App\Http\Controllers\Api\Admin\SourceLifecycleController;
use App\Http\Controllers\Api\Admin\SourceObservabilityController;
use App\Http\Controllers\Api\Admin\SourceOnboardingController;
use App\Http\Controllers\Api\Admin\SourceStatusFacetController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\BlockScheduleController;
use App\Http\Controllers\Api\Deployment\CapabilityMatrixController;
use App\Http\Controllers\Api\Deployment\DeploymentReadinessController;
use App\Http\Controllers\Api\Deployment\FacilityController as DeploymentFacilityController;
use App\Http\Controllers\Api\Deployment\OrganizationController as DeploymentOrganizationController;
use App\Http\Controllers\Api\Deployment\ServiceLineCatalogController;
use App\Http\Controllers\Api\Deployment\Staffing\StaffCoverageController;
use App\Http\Controllers\Api\Deployment\Staffing\StaffImportController;
use App\Http\Controllers\Api\Deployment\Staffing\StaffingSourceController;
use App\Http\Controllers\Api\Deployment\Staffing\StaffMappingRuleController;
use App\Http\Controllers\Api\Deployment\Staffing\StaffReferenceController;
use App\Http\Controllers\Api\Deployment\TransferRelationshipController;
use App\Http\Controllers\Api\Eddy\EddyActionController;
use App\Http\Controllers\Api\Eddy\EddyAdminController;
use App\Http\Controllers\Api\Eddy\EddyChatController;
use App\Http\Controllers\Api\Evs\EvsRequestController;
use App\Http\Controllers\Api\Facility\FacilityModelController;
use App\Http\Controllers\Api\Lab\LabFlowBoardController;
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
use App\Http\Controllers\Api\Mobile\PatientCommunicationController as MobilePatientCommunicationController;
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
use App\Http\Controllers\Api\Pharmacy\PharmacyControlledController;
use App\Http\Controllers\Api\Pharmacy\PharmacyDischargeReadinessController;
use App\Http\Controllers\Api\Pharmacy\PharmacyDispenseController;
use App\Http\Controllers\Api\Pharmacy\PharmacyFlowBoardController;
use App\Http\Controllers\Api\Pharmacy\PharmacyIvRoomController;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\Radiology\RadiologyFlowBoardController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\Rtdc\BarrierController;
use App\Http\Controllers\Api\Rtdc\BedRequestController;
use App\Http\Controllers\Api\Rtdc\CensusController;
use App\Http\Controllers\Api\Rtdc\HuddleController;
use App\Http\Controllers\Api\Rtdc\PredictionController;
use App\Http\Controllers\Api\Rtdc\ReconciliationController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\Staffing\StaffingController;
use App\Http\Controllers\Api\Staffing\StaffingFulfillmentController;
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
        \Illuminate\Support\Facades\DB::connection()->getPdo();

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
})->middleware('throttle:public-health');

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
        ->where('contextRef', 'ptok_[a-f0-9]{24}');
    Route::get('/stream', \App\Http\Controllers\Api\CockpitStreamController::class);
    // HFE Phase 1 — alert acknowledgement (any authenticated operator; the
    // engine clears the ack on warn->crit escalation so worsening re-alarms).
    Route::post('/alerts/{alertId}/acknowledge', [\App\Http\Controllers\Api\CockpitController::class, 'acknowledgeAlert'])
        ->whereNumber('alertId');
    // P8 WS-6b — the admin threshold editor's endpoints. Both admin-gated: the
    // read backs the editor page, the write is the audited band-edge tune.
    Route::get('/kpi-definitions', [\App\Http\Controllers\Api\CockpitController::class, 'kpiDefinitions'])
        ->middleware(\App\Http\Middleware\AdminMiddleware::class);
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
        Route::get('/models', [\App\Http\Controllers\Api\OcelProcessLandscapeController::class, 'index']);
        Route::get('/models/{processId}', [\App\Http\Controllers\Api\OcelProcessLandscapeController::class, 'show'])
            ->where('processId', '[A-Ha-h](?:[1-9]|1[0-4])');
        Route::get('/map', [\App\Http\Controllers\Api\ArenaController::class, 'map']);
        Route::get('/performance', [\App\Http\Controllers\Api\ArenaController::class, 'performance']);
        Route::get('/conformance', [\App\Http\Controllers\Api\ArenaController::class, 'conformance']);
        Route::get('/petrinet', [\App\Http\Controllers\Api\ArenaController::class, 'petrinet']);
        Route::get('/capacity', [\App\Http\Controllers\Api\ArenaController::class, 'capacity']);

        // Flow Reconciliation — the 48-Hour Flow Review. GET reads the persisted
        // artifact (arena.reviews); POST /review/run rebuilds it (the Run-review
        // action). Not AI-gated: the review is observed signal + open barriers, no
        // model in the loop — corrective-action drafting rides the copilot below.
        Route::get('/review', [\App\Http\Controllers\Api\ArenaController::class, 'review']);
        Route::post('/review/run', [\App\Http\Controllers\Api\ArenaController::class, 'runReview']);

        // Part X (X4) — the governed AI copilot. Nested behind EnsureArenaAiEnabled
        // (ARENA_AI_ENABLED), so these 404 unless BOTH the Arena and its AI author
        // are on. Draft endpoints only ever land pending on the Eddy plane.
        Route::middleware(\App\Http\Middleware\EnsureArenaAiEnabled::class)->prefix('copilot')->group(function () {
            Route::get('/narrative', [\App\Http\Controllers\Api\ArenaCopilotController::class, 'narrative']);
            Route::post('/query', [\App\Http\Controllers\Api\ArenaCopilotController::class, 'query']);
            Route::post('/author-map', [\App\Http\Controllers\Api\ArenaCopilotController::class, 'authorMap']);
            Route::post('/draft-pdsa', [\App\Http\Controllers\Api\ArenaCopilotController::class, 'draftPdsa']);
            Route::post('/draft-correction', [\App\Http\Controllers\Api\ArenaCopilotController::class, 'draftCorrection']);
        });
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
    // Open operational barriers overlay — aggregate + patient-free, so it rides
    // with the other aggregate reads (un-blinds the navigator's 48h spine).
    Route::get('/barriers', [PatientFlowController::class, 'barriers']);
    Route::get('/demo-scenarios', [PatientFlowController::class, 'demoScenarios']);
    // Open operational barriers overlay — aggregate + patient-free, so it rides
    // with the other aggregate reads (un-blinds the navigator's 48h spine).
    Route::get('/barriers', [PatientFlowController::class, 'barriers']);

    // Patient-level reads — persona-lensed (FLOW-WINDOW-PLAN §6.4, closes G7):
    // requires a flow lens whose patient_dots policy is not `none`.
    Route::middleware(\App\Http\Middleware\EnforceFlowLens::class.':scoped-patients')->group(function () {
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
        ->middleware(\App\Http\Middleware\EnforceFlowLens::class.':scoped');
    Route::get('/occupancy/history', [PatientFlowController::class, 'occupancyHistory'])
        ->middleware(\App\Http\Middleware\EnforceFlowLens::class.':scoped');
    Route::get('/occupancy', [PatientFlowController::class, 'occupancy'])
        ->middleware(\App\Http\Middleware\EnforceFlowLens::class.':scoped');
});

// Virtual Rounds — asynchronous/hybrid multidisciplinary rounds workflow
// (docs/superpowers/plans/2026-07-11-virtual-rounds-4d-eddy-implementation-plan.md §10.1).
// Gated by VIRTUAL_ROUNDS_ENABLED (404 when off). Mutations accept an
// Idempotency-Key header and expected versions; conflicts return 409 with the
// current projection. All patient authorization is server-side.
Route::middleware(['web', 'auth', 'throttle:60,1', \App\Http\Middleware\EnsureRoundsEnabled::class])
    ->prefix('rounds')->group(function () {
        Route::get('/templates', [\App\Http\Controllers\Api\Rounds\RoundTemplateController::class, 'index']);
        Route::get('/scopes', [\App\Http\Controllers\Api\Rounds\RoundScopeController::class, 'index']);

        Route::get('/runs', [\App\Http\Controllers\Api\Rounds\RoundRunController::class, 'index']);
        Route::post('/runs', [\App\Http\Controllers\Api\Rounds\RoundRunController::class, 'store']);
        Route::get('/runs/{runUuid}', [\App\Http\Controllers\Api\Rounds\RoundRunController::class, 'show']);
        Route::post('/runs/{runUuid}/start', [\App\Http\Controllers\Api\Rounds\RoundRunController::class, 'start']);
        Route::post('/runs/{runUuid}/pause', [\App\Http\Controllers\Api\Rounds\RoundRunController::class, 'pause']);
        Route::post('/runs/{runUuid}/resume', [\App\Http\Controllers\Api\Rounds\RoundRunController::class, 'resume']);
        Route::post('/runs/{runUuid}/complete', [\App\Http\Controllers\Api\Rounds\RoundRunController::class, 'complete']);
        Route::post('/runs/{runUuid}/cancel', [\App\Http\Controllers\Api\Rounds\RoundRunController::class, 'cancel']);
        Route::get('/runs/{runUuid}/board', [\App\Http\Controllers\Api\Rounds\RoundRunController::class, 'board']);
        Route::get('/runs/{runUuid}/scene', [\App\Http\Controllers\Api\Rounds\RoundRunController::class, 'scene']);
        Route::post('/runs/{runUuid}/cohort/reconcile', [\App\Http\Controllers\Api\Rounds\RoundRunController::class, 'reconcile']);
        Route::patch('/runs/{runUuid}/queue', [\App\Http\Controllers\Api\Rounds\RoundRunController::class, 'queue']);

        Route::get('/patients/{roundPatientUuid}', [\App\Http\Controllers\Api\Rounds\RoundPatientController::class, 'show']);
        Route::post('/patients/{roundPatientUuid}/mark-ready', [\App\Http\Controllers\Api\Rounds\RoundPatientController::class, 'markReady']);
        Route::post('/patients/{roundPatientUuid}/complete', [\App\Http\Controllers\Api\Rounds\RoundPatientController::class, 'complete']);
        Route::post('/patients/{roundPatientUuid}/reopen', [\App\Http\Controllers\Api\Rounds\RoundPatientController::class, 'reopen']);
        Route::post('/patients/{roundPatientUuid}/defer', [\App\Http\Controllers\Api\Rounds\RoundPatientController::class, 'defer']);
        Route::post('/patients/{roundPatientUuid}/skip', [\App\Http\Controllers\Api\Rounds\RoundPatientController::class, 'skip']);
        Route::post('/patients/{roundPatientUuid}/pin', [\App\Http\Controllers\Api\Rounds\RoundPatientController::class, 'pin']);
        Route::post('/patients/{roundPatientUuid}/contributions', [\App\Http\Controllers\Api\Rounds\RoundContributionController::class, 'store']);
        Route::post('/patients/{roundPatientUuid}/questions', [\App\Http\Controllers\Api\Rounds\RoundQuestionController::class, 'store']);
        Route::get('/patients/{roundPatientUuid}/patient-question-threads', [\App\Http\Controllers\Api\Rounds\RoundQuestionController::class, 'availablePatientQuestions'])
            ->middleware(\App\Http\Middleware\EnsurePatientRoundsQuestionBridgeEnabled::class)
            ->whereUuid('roundPatientUuid');
        Route::post('/patients/{roundPatientUuid}/patient-question-threads/{threadUuid}/promote', [\App\Http\Controllers\Api\Rounds\RoundQuestionController::class, 'promotePatientQuestion'])
            ->middleware(\App\Http\Middleware\EnsurePatientRoundsQuestionBridgeEnabled::class)
            ->whereUuid(['roundPatientUuid', 'threadUuid']);
        Route::post('/patients/{roundPatientUuid}/tasks', [\App\Http\Controllers\Api\Rounds\RoundTaskController::class, 'store']);

        Route::post('/contributions/{contributionUuid}/submit', [\App\Http\Controllers\Api\Rounds\RoundContributionController::class, 'submit']);
        Route::post('/contributions/{contributionUuid}/withdraw', [\App\Http\Controllers\Api\Rounds\RoundContributionController::class, 'withdraw']);
        Route::post('/questions/{questionUuid}/resolve', [\App\Http\Controllers\Api\Rounds\RoundQuestionController::class, 'resolve']);
        Route::post('/tasks/{taskUuid}/transition', [\App\Http\Controllers\Api\Rounds\RoundTaskController::class, 'transition']);
    });

// Home Hospital — virtual-ward live data (ACUM-PRD-HAH-001; docs/home-hospital/).
// Gated by HOME_HOSPITAL_ENABLED (404 when off). Patient-level payloads travel
// only on this authenticated API — public Reverb channels carry aggregate
// pings, never vitals (PHI-free-wire rule).
Route::middleware(['web', 'auth', 'throttle:60,1', \App\Http\Middleware\EnsureHomeHospitalEnabled::class])
    ->prefix('home')->group(function () {
        Route::get('/census', [\App\Http\Controllers\Api\Home\HomeCensusController::class, 'index']);
        Route::get('/command', [\App\Http\Controllers\Api\Home\HomeCommandController::class, 'index']);
        // Patient-alert acknowledgement workflow — human actions, recorded.
        Route::get('/alerts', [\App\Http\Controllers\Api\Home\RpmAlertController::class, 'index']);
        Route::post('/alerts/{alertUuid}/acknowledge', [\App\Http\Controllers\Api\Home\RpmAlertController::class, 'acknowledge']);
        Route::post('/alerts/{alertUuid}/resolve', [\App\Http\Controllers\Api\Home\RpmAlertController::class, 'resolve']);
        // Escalation workflow — full response timing chain vs the 30-min floor.
        Route::get('/escalations', [\App\Http\Controllers\Api\Home\HomeEscalationController::class, 'index']);
        Route::post('/escalations', [\App\Http\Controllers\Api\Home\HomeEscalationController::class, 'store']);
        Route::post('/escalations/{escalationUuid}/dispatch', [\App\Http\Controllers\Api\Home\HomeEscalationController::class, 'dispatchResponse']);
        Route::post('/escalations/{escalationUuid}/arrive', [\App\Http\Controllers\Api\Home\HomeEscalationController::class, 'arrive']);
        Route::post('/escalations/{escalationUuid}/resolve', [\App\Http\Controllers\Api\Home\HomeEscalationController::class, 'resolve']);
        // Referral funnel + eligibility worklists (declines always coded).
        Route::get('/referrals', [\App\Http\Controllers\Api\Home\HomeReferralController::class, 'index']);
        Route::post('/referrals', [\App\Http\Controllers\Api\Home\HomeReferralController::class, 'store']);
        Route::post('/referrals/{referralUuid}/advance', [\App\Http\Controllers\Api\Home\HomeReferralController::class, 'advance']);
        Route::post('/referrals/{referralUuid}/decline', [\App\Http\Controllers\Api\Home\HomeReferralController::class, 'decline']);
        // Transitions: inbound checklists, outbound governed handoffs, discharge.
        Route::get('/transitions', [\App\Http\Controllers\Api\Home\HomeTransitionController::class, 'index']);
        Route::post('/transitions/{transitionUuid}/checklist', [\App\Http\Controllers\Api\Home\HomeTransitionController::class, 'completeChecklistItem']);
        Route::post('/episodes/{episodeUuid}/handoff', [\App\Http\Controllers\Api\Home\HomeTransitionController::class, 'startOutbound']);
        Route::post('/episodes/{episodeUuid}/discharge', [\App\Http\Controllers\Api\Home\HomeTransitionController::class, 'discharge']);
        // Logistics — the ONE address-permitted surface.
        Route::get('/logistics', [\App\Http\Controllers\Api\Home\HomeLogisticsController::class, 'index']);
        // The decant line: home-eligible counts + free-slot forecast (huddle).
        Route::get('/decant', [\App\Http\Controllers\Api\Home\HomeDecantController::class, 'index']);
    });

// Machine-to-machine ingress only. This route intentionally lives outside the
// browser-session Patient Flow group and writes through raw -> canonical ->
// projection/provenance before acknowledging an ADT message.
Route::post('/integrations/v1/patient-flow/hl7v2', [PatientFlowIngestController::class, 'hl7v2'])
    ->middleware([
        'auth:sanctum',
        \App\Http\Middleware\RequireMachineToken::class.':integration:patient-flow:ingest',
        'throttle:machine-ingest',
    ]);

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

Route::middleware(['web', 'auth', 'throttle:60,1'])->prefix('radiology')->name('api.radiology.')->group(function () {
    Route::get('/flow-board', [RadiologyFlowBoardController::class, 'show'])->name('flow-board');
    Route::get('/worklist', [RadiologyFlowBoardController::class, 'worklist'])->name('worklist');
    Route::get('/modality', [RadiologyFlowBoardController::class, 'modality'])->name('modality');
    Route::get('/reads', [RadiologyFlowBoardController::class, 'reads'])->name('reads');
    Route::get('/tat', [RadiologyFlowBoardController::class, 'tat'])->name('tat');
    Route::get('/ir-utilization', [RadiologyFlowBoardController::class, 'irSuite'])->name('ir-utilization');
    Route::post('/barriers', [RadiologyFlowBoardController::class, 'storeBarrier'])->name('barriers.store');
});

Route::middleware(['web', 'auth', 'throttle:60,1'])->prefix('lab')->name('api.lab.')->group(function () {
    Route::get('/flow-board', [LabFlowBoardController::class, 'show'])->name('flow-board');
    Route::get('/specimens', [LabFlowBoardController::class, 'specimens'])->name('specimens');
    Route::get('/pending-decisions', [LabFlowBoardController::class, 'pendingDecisions'])->name('pending-decisions');
    Route::get('/blood-bank', [LabFlowBoardController::class, 'bloodBank'])->name('blood-bank');
    Route::get('/anatomic-path', [LabFlowBoardController::class, 'anatomicPathology'])->name('anatomic-path');
    Route::get('/tat', [LabFlowBoardController::class, 'tat'])->name('tat');
    Route::post('/barriers', [LabFlowBoardController::class, 'storeBarrier'])->name('barriers.store');
});

Route::middleware(['web', 'auth', 'throttle:60,1'])->prefix('pharmacy')->name('api.pharmacy.')->group(function () {
    Route::get('/flow-board', [PharmacyFlowBoardController::class, 'show'])->name('flow-board');
    Route::get('/discharge-readiness', [PharmacyDischargeReadinessController::class, 'show'])->name('discharge-readiness');
    Route::get('/iv-room', [PharmacyIvRoomController::class, 'show'])->name('iv-room');
    Route::get('/dispense', [PharmacyDispenseController::class, 'show'])->name('dispense');
    Route::get('/controlled', [PharmacyControlledController::class, 'show'])->name('controlled');
    Route::get('/tat', [PharmacyFlowBoardController::class, 'tat'])->name('tat');
    Route::post('/barriers', [PharmacyFlowBoardController::class, 'storeBarrier'])->name('barriers.store');
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
    Route::get('/workforce', [StaffingController::class, 'workforce']);
    Route::get('/requests', [StaffingController::class, 'index']);
    Route::post('/requests', [StaffingController::class, 'store'])->middleware('can:manageStaffingOperations');
    Route::get('/requests/{staffingRequestId}/candidates', [StaffingFulfillmentController::class, 'candidates']);
    Route::get('/requests/{staffingRequestId}/fulfillments', [StaffingFulfillmentController::class, 'index']);
    Route::post('/requests/{staffingRequestId}/fulfillments', [StaffingFulfillmentController::class, 'store'])
        ->middleware('can:manageStaffingOperations');
    Route::post('/fulfillments/{fulfillmentUuid}/transition', [StaffingFulfillmentController::class, 'transition'])
        ->middleware('can:manageStaffingOperations')
        ->whereUuid('fulfillmentUuid');
    Route::get('/requests/{staffingRequestId}', [StaffingController::class, 'show']);
    Route::post('/requests/{staffingRequestId}/assign', [StaffingController::class, 'assign'])->middleware('can:manageStaffingOperations');
    Route::post('/requests/{staffingRequestId}/status', [StaffingController::class, 'status'])->middleware('can:manageStaffingOperations');
    Route::post('/requests/{staffingRequestId}/cancel', [StaffingController::class, 'cancel'])->middleware('can:manageStaffingOperations');
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

// Deployment taxonomy — IDN geography, capability matrix, transfer graph, readiness.
// Read-gated by the viewDeploymentConsole ability (superuser/ops-leader/admin roles).
Route::middleware(['web', 'auth', 'throttle:60,1', 'can:viewDeploymentConsole'])->prefix('deployment')->group(function () {
    Route::get('/service-lines', [ServiceLineCatalogController::class, 'index']);
    Route::get('/organizations', [DeploymentOrganizationController::class, 'index']);
    Route::get('/organizations/{key}', [DeploymentOrganizationController::class, 'show']);
    Route::get('/facilities', [DeploymentFacilityController::class, 'index']);
    Route::get('/facilities/{facilityKey}', [DeploymentFacilityController::class, 'show']);
    Route::get('/facilities/{facilityKey}/spaces', [DeploymentFacilityController::class, 'spaces']);
    Route::get('/capability-matrix', [CapabilityMatrixController::class, 'index']);
    Route::get('/transfers', [TransferRelationshipController::class, 'index']);
    Route::get('/readiness/{facilityKey}', [DeploymentReadinessController::class, 'show']);
});

// Staffing Alignment Wizard — the write API (Phase 7 / §8). Gated by the narrower
// manageDeploymentConfig ability (superuser/ops-leader — NOT plain admin). Connector
// secrets are never accepted or returned; the shipped file/FHIR path uploads content
// per request and stores none. All staff_assignments writes route through
// StaffImportOrchestrator::commit; prod.users is only ever touched additively by
// StaffProvisioningService.
Route::middleware(['web', 'auth', 'throttle:60,1', 'can:manageDeploymentConfig'])->prefix('deployment/staffing')->group(function () {
    Route::get('/sources', [StaffingSourceController::class, 'index']);
    Route::post('/sources', [StaffingSourceController::class, 'store']);
    Route::post('/sources/{source}/test', [StaffingSourceController::class, 'test']);
    Route::post('/sources/{source}/discover', [StaffingSourceController::class, 'discover']);
    Route::post('/sources/{source}/schedule', [StaffingSourceController::class, 'schedule']);

    Route::post('/imports', [StaffImportController::class, 'store']);
    Route::get('/imports/{run}', [StaffImportController::class, 'show']);
    Route::post('/imports/{run}/resolve', [StaffImportController::class, 'resolve']);
    Route::patch('/imports/{run}/reviews/{staffMember}', [StaffImportController::class, 'review']);
    Route::post('/imports/{run}/commit', [StaffImportController::class, 'commit']);

    Route::get('/rules', [StaffMappingRuleController::class, 'index']);
    Route::post('/rules', [StaffMappingRuleController::class, 'store']);

    Route::get('/reference', [StaffReferenceController::class, 'index']);
    Route::get('/coverage', [StaffCoverageController::class, 'index']);
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
    Route::post('/actions/propose', [EddyActionController::class, 'propose'])
        ->middleware('can:useEddyActions');
    Route::post('/agent/token', [EddyActionController::class, 'mintAgentToken'])
        ->middleware('can:useEddyActions');

    // Phase 6 — super-admin: cost/redaction accounting, route simulator, knowledge review.
    Route::get('/admin/usage', [EddyAdminController::class, 'usage']);
    Route::post('/admin/route-simulate', [EddyAdminController::class, 'simulate']);
    Route::get('/admin/knowledge/proposed', [EddyAdminController::class, 'proposedKnowledge']);
    Route::post('/admin/knowledge/{uuid}/review', [EddyAdminController::class, 'reviewKnowledge']);
});

// Eddy agent callback (scoped Sanctum token: ops:read/ops:draft, NEVER ops:approve).
Route::middleware([
    'auth:sanctum',
    \App\Http\Middleware\RequireMachineToken::class.':ops:draft',
    'throttle:machine-agent',
])->prefix('eddy/agent')->group(function () {
    Route::post('/actions/propose', [EddyActionController::class, 'propose']);
});

// Integration administration is a distinct, strict superuser boundary. General
// enterprise-setup and operations-leader access does not imply connector access.
Route::middleware(['web', 'auth', 'throttle:60,1', 'can:viewIntegrations'])->prefix('admin/integrations')->group(function () {
    Route::get('/control-plane', IntegrationControlPlaneController::class);
    Route::get('/health', IntegrationHealthController::class);
    Route::get('/enterprise', [EnterpriseConnectorController::class, 'summary']);
    Route::get('/sources', [IntegrationSourceController::class, 'index']);
    Route::get('/sources/{source}', [IntegrationSourceController::class, 'show'])->whereNumber('source');
    Route::get('/sources/{source}/configuration-versions', [SourceConfigurationVersionController::class, 'index'])
        ->middleware('admin.scope:source')->whereNumber('source');
    Route::get('/sources/{source}/lifecycle-events', [SourceLifecycleController::class, 'index'])
        ->middleware('admin.scope:source')->whereNumber('source');
    Route::get('/sources/{source}/onboarding', [SourceOnboardingController::class, 'show'])
        ->middleware('admin.scope:source')->whereNumber('source');
    Route::get('/sources/{source}/observability', [SourceObservabilityController::class, 'show'])
        ->middleware('admin.scope:source')->whereNumber('source');
    Route::get('/sources/{source}/status-facets', [SourceStatusFacetController::class, 'show'])
        ->middleware('admin.scope:source')->whereNumber('source');
    Route::get('/sources/{source}/fhir/conformance', [EnterpriseConnectorController::class, 'fhirConformance'])
        ->middleware('admin.scope:source')->whereNumber('source');
    Route::get('/sources/{source}/endpoints', [IntegrationEndpointController::class, 'index'])->whereNumber('source');
    Route::get('/sources/{source}/credentials', [IntegrationCredentialController::class, 'index'])->whereNumber('source');
    Route::get('/secret-providers', [CredentialNetworkGovernanceController::class, 'providers']);
    Route::get('/sources/{source}/credentials/{credential}/versions', [CredentialNetworkGovernanceController::class, 'credentialVersions'])
        ->middleware('admin.scope:source')->whereNumber(['source', 'credential']);
    Route::get('/sources/{source}/network-routes', [CredentialNetworkGovernanceController::class, 'networkRoutes'])
        ->middleware('admin.scope:source')->whereNumber('source');
    Route::get('/sources/{source}/peer-pin-policies', [CredentialNetworkGovernanceController::class, 'peerPinPolicies'])
        ->middleware('admin.scope:source')->whereNumber('source');
    Route::post('/enterprise/replays/preview', [EnterpriseConnectorController::class, 'previewReplay'])
        ->middleware('admin.scope:source');
});

Route::middleware(['web', 'auth', 'throttle:60,1', 'can:manageIntegrations'])->prefix('admin/integrations')->group(function () {
    Route::post('/sources', [IntegrationSourceController::class, 'store'])->middleware('admin.scope:facility');
    Route::patch('/sources/{source}', [IntegrationSourceController::class, 'update'])->middleware('admin.scope:source')->whereNumber('source');
    Route::post('/sources/{source}/configuration-versions', [SourceConfigurationVersionController::class, 'store'])
        ->middleware('admin.scope:source')->whereNumber('source');
    Route::post('/sources/{source}/lifecycle-transitions', [SourceLifecycleController::class, 'transition'])
        ->middleware('admin.scope:source')->whereNumber('source');
    Route::post('/sources/{source}/onboarding-versions', [SourceOnboardingController::class, 'storeVersion'])
        ->middleware('admin.scope:source')->whereNumber('source');
    Route::post('/sources/{source}/evidence', [SourceOnboardingController::class, 'storeEvidence'])
        ->middleware('admin.scope:source')->whereNumber('source');
    Route::post('/sources/{source}/readiness-assessments', [SourceOnboardingController::class, 'assess'])
        ->middleware('admin.scope:source')->whereNumber('source');
    Route::post('/sources/{source}/activation-windows/{windowUuid}/cancel', [SourceOnboardingController::class, 'cancelWindow'])
        ->middleware('admin.scope:source')->whereNumber('source')->whereUuid('windowUuid');
    Route::delete('/sources/{source}', [IntegrationSourceController::class, 'destroy'])->middleware('admin.scope:source')->whereNumber('source');
    Route::post('/sources/{source}/endpoints', [IntegrationEndpointController::class, 'store'])->middleware('admin.scope:source')->whereNumber('source');
    Route::patch('/sources/{source}/endpoints/{endpoint}', [IntegrationEndpointController::class, 'update'])->middleware('admin.scope:source')->whereNumber(['source', 'endpoint']);
    Route::delete('/sources/{source}/endpoints/{endpoint}', [IntegrationEndpointController::class, 'destroy'])->middleware('admin.scope:source')->whereNumber(['source', 'endpoint']);
    Route::post('/sources/{source}/credentials', [IntegrationCredentialController::class, 'store'])->middleware('admin.scope:source')->whereNumber('source');
    Route::patch('/sources/{source}/credentials/{credential}', [IntegrationCredentialController::class, 'update'])->middleware('admin.scope:source')->whereNumber(['source', 'credential']);
    Route::delete('/sources/{source}/credentials/{credential}', [IntegrationCredentialController::class, 'destroy'])->middleware('admin.scope:source')->whereNumber(['source', 'credential']);
    Route::post('/sources/{source}/credentials/{credential}/validations', [CredentialNetworkGovernanceController::class, 'validateCredential'])
        ->middleware('admin.scope:source')->whereNumber(['source', 'credential']);
    Route::post('/sources/{source}/network-routes', [CredentialNetworkGovernanceController::class, 'createNetworkRoute'])
        ->middleware('admin.scope:source')->whereNumber('source');
    Route::patch('/sources/{source}/network-routes/{route}', [CredentialNetworkGovernanceController::class, 'updateNetworkRoute'])
        ->middleware('admin.scope:source')->whereNumber(['source', 'route']);
    Route::post('/sources/{source}/network-routes/{route}/validations', [CredentialNetworkGovernanceController::class, 'validateNetworkRoute'])
        ->middleware('admin.scope:source')->whereNumber(['source', 'route']);
    Route::delete('/sources/{source}/network-routes/{route}', [CredentialNetworkGovernanceController::class, 'retireNetworkRoute'])
        ->middleware('admin.scope:source')->whereNumber(['source', 'route']);
    Route::post('/sources/{source}/network-routes/{route}/peer-pin-policies', [CredentialNetworkGovernanceController::class, 'upsertPeerPinPolicy'])
        ->middleware('admin.scope:source')->whereNumber(['source', 'route']);
    Route::delete('/sources/{source}/peer-pin-policies/{policy}', [CredentialNetworkGovernanceController::class, 'retirePeerPinPolicy'])
        ->middleware('admin.scope:source')->whereNumber(['source', 'policy']);
    Route::put('/sources/{source}/fhir/resource-profiles/{resourceType}', [EnterpriseConnectorController::class, 'configureFhirResourceProfile'])
        ->middleware('admin.scope:source')->whereNumber('source')->where('resourceType', '[A-Z][A-Za-z]{1,79}');
    Route::delete('/sources/{source}/fhir/resource-profiles/{profile}', [EnterpriseConnectorController::class, 'retireFhirResourceProfile'])
        ->middleware('admin.scope:source')->whereNumber(['source', 'profile']);
    Route::post('/sources/{source}/activation-requests', [GovernedIntegrationChangeController::class, 'requestSourceActivation'])
        ->middleware('admin.scope:source')->whereNumber('source');
    Route::post('/sources/{source}/activation-window-requests', [GovernedIntegrationChangeController::class, 'requestScheduledSourceActivation'])
        ->middleware('admin.scope:source')->whereNumber('source');
    Route::post('/sources/{source}/configuration-versions/{version}/application-requests', [GovernedIntegrationChangeController::class, 'requestSourceConfigurationApplication'])
        ->middleware('admin.scope:source')->whereNumber(['source', 'version']);
    Route::post('/sources/{source}/credentials/{credential}/rotation-requests', [GovernedIntegrationChangeController::class, 'requestCredentialRotation'])
        ->middleware('admin.scope:source')->whereNumber(['source', 'credential']);
    Route::post('/governed-changes/{changeRequestUuid}/execute-source-activation', [GovernedIntegrationChangeController::class, 'executeSourceActivation'])
        ->middleware('admin.scope:governed_change')->whereUuid('changeRequestUuid');
    Route::post('/governed-changes/{changeRequestUuid}/execute-source-activation-schedule', [GovernedIntegrationChangeController::class, 'executeScheduledSourceActivation'])
        ->middleware('admin.scope:governed_change')->whereUuid('changeRequestUuid');
    Route::post('/governed-changes/{changeRequestUuid}/execute-source-configuration', [GovernedIntegrationChangeController::class, 'executeSourceConfigurationApplication'])
        ->middleware('admin.scope:governed_change')->whereUuid('changeRequestUuid');
    Route::post('/governed-changes/{changeRequestUuid}/sources/{source}/credentials/{credential}/execute-rotation', [GovernedIntegrationChangeController::class, 'executeCredentialRotation'])
        ->middleware(['admin.scope:governed_change', 'admin.scope:source'])
        ->whereUuid('changeRequestUuid')->whereNumber(['source', 'credential']);
});

Route::middleware(['web', 'auth', 'throttle:60,1', 'can:operateIntegrations'])
    ->prefix('admin/integrations')->group(function () {
        Route::post('/sources/{source}/observations', [SourceObservabilityController::class, 'collect'])
            ->middleware('admin.scope:source')->whereNumber('source');
        Route::post('/sources/{source}/slo-breaches/{breach}/acknowledge', [SourceObservabilityController::class, 'acknowledge'])
            ->middleware('admin.scope:source')->whereNumber('source')->whereUuid('breach');
        Route::post('/sources/{source}/slo-breaches/{breach}/escalate', [SourceObservabilityController::class, 'escalate'])
            ->middleware('admin.scope:source')->whereNumber('source')->whereUuid('breach');
        Route::post('/sources/{source}/slo-breaches/{breach}/incident-link', [SourceObservabilityController::class, 'linkIncident'])
            ->middleware('admin.scope:source')->whereNumber('source')->whereUuid('breach');
        Route::post('/sources/{source}/slo-breaches/{breach}/review', [SourceObservabilityController::class, 'review'])
            ->middleware('admin.scope:source')->whereNumber('source')->whereUuid('breach');
        Route::post('/sources/{source}/status-facets/conformance', [SourceStatusFacetController::class, 'recordConformance'])
            ->middleware('admin.scope:source')->whereNumber('source');
        Route::post('/sources/{source}/status-facets/contract', [SourceStatusFacetController::class, 'recordContract'])
            ->middleware('admin.scope:source')->whereNumber('source');
        Route::post('/sources/{source}/status-facets/incident', [SourceStatusFacetController::class, 'recordIncident'])
            ->middleware('admin.scope:source')->whereNumber('source');
        Route::post('/sources/{source}/health-check', [EnterpriseConnectorController::class, 'healthCheck'])->middleware('admin.scope:source')->whereNumber('source');
        Route::post('/sources/{source}/fhir/poll', [EnterpriseConnectorController::class, 'pollFhir'])->middleware('admin.scope:source')->whereNumber('source');
        Route::post('/enterprise/fhir/capability-discovery', [EnterpriseConnectorController::class, 'discoverFhir'])->middleware('admin.scope:source');
        Route::post('/enterprise/writeback-drafts', [EnterpriseConnectorController::class, 'createWritebackDraft'])->middleware('admin.scope:source');
        Route::post('/sources/{source}/payload-quarantines/{quarantine}/release-requests', [ClinicalPayloadGovernanceController::class, 'requestQuarantineRelease'])
            ->middleware('admin.scope:source')->whereNumber(['source', 'quarantine']);
        Route::post('/governed-changes/{changeRequestUuid}/sources/{source}/payload-quarantines/{quarantine}/execute-release', [ClinicalPayloadGovernanceController::class, 'executeQuarantineRelease'])
            ->middleware(['admin.scope:governed_change', 'admin.scope:source'])
            ->whereUuid('changeRequestUuid')->whereNumber(['source', 'quarantine']);
    });

Route::middleware(['web', 'auth', 'throttle:30,1', 'can:executeDestructiveReplay'])
    ->prefix('admin/integrations')->group(function () {
        Route::post('/enterprise/replays/requests', [EnterpriseConnectorController::class, 'requestReplay'])->middleware('admin.scope:source');
        Route::post('/enterprise/replays', [EnterpriseConnectorController::class, 'queueReplay'])->middleware(['admin.scope:source', 'admin.scope:governed_change']);
    });

Route::middleware(['web', 'auth', 'throttle:30,1', 'can:manageDataStewardship'])
    ->prefix('admin/integrations')->group(function () {
        Route::post('/sources/{source}/payload-objects/{object}/hold-requests', [ClinicalPayloadGovernanceController::class, 'requestHold'])
            ->middleware('admin.scope:source')->whereNumber(['source', 'object']);
        Route::post('/governed-changes/{changeRequestUuid}/sources/{source}/payload-objects/{object}/execute-hold', [ClinicalPayloadGovernanceController::class, 'executeHold'])
            ->middleware(['admin.scope:governed_change', 'admin.scope:source'])
            ->whereUuid('changeRequestUuid')->whereNumber(['source', 'object']);
        Route::post('/sources/{source}/payload-objects/{object}/purge-requests', [ClinicalPayloadGovernanceController::class, 'requestObjectPurge'])
            ->middleware('admin.scope:source')->whereNumber(['source', 'object']);
        Route::post('/governed-changes/{changeRequestUuid}/sources/{source}/payload-objects/{object}/execute-purge', [ClinicalPayloadGovernanceController::class, 'executeObjectPurge'])
            ->middleware(['admin.scope:governed_change', 'admin.scope:source'])
            ->whereUuid('changeRequestUuid')->whereNumber(['source', 'object']);
        Route::post('/sources/{source}/payload-objects/{object}/integrity-recovery-requests', [ClinicalPayloadGovernanceController::class, 'requestIntegrityRecovery'])
            ->middleware('admin.scope:source')->whereNumber(['source', 'object']);
        Route::post('/governed-changes/{changeRequestUuid}/sources/{source}/payload-objects/{object}/execute-integrity-recovery', [ClinicalPayloadGovernanceController::class, 'executeIntegrityRecovery'])
            ->middleware(['admin.scope:governed_change', 'admin.scope:source'])
            ->whereUuid('changeRequestUuid')->whereNumber(['source', 'object']);
        Route::post('/sources/{source}/payload-quarantines/{quarantine}/purge-requests', [ClinicalPayloadGovernanceController::class, 'requestQuarantinePurge'])
            ->middleware('admin.scope:source')->whereNumber(['source', 'quarantine']);
        Route::post('/governed-changes/{changeRequestUuid}/sources/{source}/payload-quarantines/{quarantine}/execute-purge', [ClinicalPayloadGovernanceController::class, 'executeQuarantinePurge'])
            ->middleware(['admin.scope:governed_change', 'admin.scope:source'])
            ->whereUuid('changeRequestUuid')->whereNumber(['source', 'quarantine']);
    });

Route::middleware(['web', 'auth', 'throttle:30,1', 'can:approveIntegrationChanges'])
    ->prefix('admin/integrations')->group(function () {
        Route::post('/governed-changes/{changeRequestUuid}/decision', [GovernedIntegrationChangeController::class, 'decide'])
            ->middleware('admin.scope:governed_change')->whereUuid('changeRequestUuid');
    });

// OR Cases
Route::prefix('cases')->middleware(['web', 'auth', 'throttle:web-api'])->group(function () {
    Route::get('/', [ORCaseController::class, 'index']);
    Route::get('/today', [ORCaseController::class, 'todaysCases']);
    Route::get('/metrics', [ORCaseController::class, 'metrics']);
    Route::get('/room-status', [ORCaseController::class, 'roomStatus']);
});
Route::prefix('cases')->middleware(['web', 'auth', 'throttle:60,1', 'can:writeOrCases'])->group(function () {
    Route::post('/', [ORCaseController::class, 'store']);
    Route::put('/{id}', [ORCaseController::class, 'update']);
});

// Block Schedule
Route::prefix('blocks')->middleware(['web', 'auth', 'throttle:web-api'])->group(function () {
    Route::get('/', [BlockScheduleController::class, 'index']);
    Route::get('/utilization', [BlockScheduleController::class, 'utilization']);
    Route::get('/service-utilization', [BlockScheduleController::class, 'serviceUtilization']);
    Route::get('/room-utilization', [BlockScheduleController::class, 'roomUtilization']);
});
Route::prefix('blocks')->middleware(['web', 'auth', 'throttle:sensitive-web-api', 'can:writeOrCases'])->group(function () {
    Route::post('/', [BlockScheduleController::class, 'store']);
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

Route::prefix('analytics')->middleware(['web', 'auth', 'throttle:web-api'])->group(function () {
    Route::get('/service-performance', [AnalyticsController::class, 'servicePerformance']);
    Route::get('/provider-performance', [AnalyticsController::class, 'providerPerformance']);
    Route::get('/historical-trends', [AnalyticsController::class, 'historicalTrends']);
});

// Reference Data
Route::middleware(['web', 'auth', 'throttle:web-api'])->group(function () {
    Route::get('/services', [ServiceController::class, 'index']);
    Route::get('/rooms', [RoomController::class, 'index']);
    Route::get('/providers', [ProviderController::class, 'index']);
});

// Improvement Process Maps
Route::prefix('improvement')->middleware(['web', 'auth', 'throttle:web-api'])->group(function () {
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
    Route::post('/token', [MobileAuthController::class, 'token'])->middleware('throttle:credential-exchange');

    Route::middleware(['auth:sanctum', 'staff.realm', 'throttle:mobile-authenticated'])->group(function () {
        Route::post('/token/refresh', [MobileAuthController::class, 'refresh']);
        Route::post('/token/revoke', [MobileAuthController::class, 'revoke']);
        Route::post('/change-password', [MobileAuthController::class, 'changePassword'])
            ->middleware(CheckForAnyAbility::class.':password:change,mobile:read,token:refresh');
    });
});

// The mobile BFF — one token-gated, role-scoped, PHI-minimized surface.
// `CheckForAnyAbility:mobile:read` rejects narrowly-scoped tokens (e.g. the
// must_change_password challenge token) while admin `*` tokens pass. Write-vs-read
// ability splitting (mobile:act) + per-resource Policies land with the P1 writes.
Route::middleware(['auth:sanctum', 'staff.realm', CheckForAnyAbility::class.':mobile:read', 'throttle:mobile-api'])->prefix('mobile/v1')->group(function () {
    Route::get('/me', [MobileMeController::class, 'show']);
    Route::put('/me/preferences', [MobileMeController::class, 'updatePreferences']);

    Route::post('/devices', [MobileDeviceController::class, 'store']);
    Route::delete('/devices/{device}', [MobileDeviceController::class, 'destroy']);

    Route::get('/realtime/config', [MobileRealtimeConfigController::class, 'show']);

    Route::get('/altitude/home', [MobileAltitudeController::class, 'home']);
    Route::get('/altitude/workspace/{domain}', [MobileAltitudeController::class, 'workspace']);
    Route::get('/drills/{itemUuid}', [MobileAltitudeController::class, 'drill']);
    Route::get('/patients/{contextRef}/operational-context', [MobilePatientContextController::class, 'show'])
        ->where('contextRef', 'ptok_[a-f0-9]{24}');
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

    // Patient communications — the accountable care-team side of the separate
    // patient messaging realm. Capability checks are necessary but never
    // sufficient: the service also requires current responsibility-pool
    // membership for every read and mutation.
    Route::middleware([
        \App\Http\Middleware\ProtectPatientCommunicationResponse::class,
        'patient.staff-messaging',
        'can:viewPatientCommunications',
    ])
        ->prefix('patient-communications')
        ->group(function () {
            Route::get('/inbox', [MobilePatientCommunicationController::class, 'inbox']);
            Route::get('/threads/{workItemUuid}', [MobilePatientCommunicationController::class, 'show'])
                ->whereUuid('workItemUuid');

            Route::middleware([
                CheckForAnyAbility::class.':mobile:act',
                'can:respondPatientCommunications',
            ])->group(function () {
                Route::get('/threads/{workItemUuid}/route-candidates', [MobilePatientCommunicationController::class, 'routeCandidates'])
                    ->whereUuid('workItemUuid');
                Route::post('/threads/{workItemUuid}/claim', [MobilePatientCommunicationController::class, 'claim'])
                    ->whereUuid('workItemUuid');
                Route::post('/threads/{workItemUuid}/reply', [MobilePatientCommunicationController::class, 'reply'])
                    ->whereUuid('workItemUuid');
                Route::post('/threads/{workItemUuid}/close', [MobilePatientCommunicationController::class, 'close'])
                    ->whereUuid('workItemUuid');
                Route::post('/threads/{workItemUuid}/release', [MobilePatientCommunicationController::class, 'release'])
                    ->whereUuid('workItemUuid');
                Route::post('/threads/{workItemUuid}/reassign', [MobilePatientCommunicationController::class, 'reassign'])
                    ->whereUuid('workItemUuid');
                Route::post('/threads/{workItemUuid}/reroute', [MobilePatientCommunicationController::class, 'reroute'])
                    ->whereUuid('workItemUuid');
            });
        });

    // Flow Window (FLOW-WINDOW-PLAN §6.4) — the persona-lensed 48h
    // spatiotemporal surface. /floors is the versioned plate asset (ETag);
    // /window serves snapshots + events + projections clamped by the
    // caller's lens (config/hummingbird/flow_lens.php). Server-side RBAC —
    // patient identity never exceeds the caller's A2P matrix depth.
    Route::prefix('flow')->group(function () {
        Route::get('/floors', [MobileFlowController::class, 'floors']);
        Route::get('/spaces3d', [MobileFlowController::class, 'spaces3d']);
        Route::get('/demo-scenarios', [MobileFlowController::class, 'demoScenarios']);
        Route::get('/occupancy/history', [MobileFlowController::class, 'occupancyHistory']);
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
    Route::get('/staffing/requests/{id}/candidates', [MobileStaffingController::class, 'candidates']);
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
