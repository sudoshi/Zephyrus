<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\UserAuditController;
use App\Http\Controllers\Analytics;
use App\Http\Controllers\CommandCenterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Deployment\DeploymentConsoleController;
use App\Http\Controllers\DesignController;
use App\Http\Controllers\EDDashboardController;
use App\Http\Controllers\Integrations\IntegrationConsoleController;
use App\Http\Controllers\Operations;
use App\Http\Controllers\Ops\OpsConsoleController;
use App\Http\Controllers\Predictions;
use App\Http\Controllers\ProcessAnalysisController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RTDCController;
use App\Http\Controllers\RTDCDashboardController;
use App\Http\Controllers\Staffing\StaffingDashboardController;
use App\Http\Controllers\Transport\TransportDashboardController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Process Analysis API Routes
Route::get('/improvement/api/nursing-operations', [ProcessAnalysisController::class, 'getNursingOperations']);

// Root route - redirect to login or dashboard based on auth state
Route::get('/', function (Request $request) {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return redirect()->route('login');
});

// Authenticated demo routes.
//
// SessionAuthMiddleware is the existing local/demo auto-login gate. Keeping it
// Direct viewer URLs create a valid session here, while API routes retain their
// normal web-session authentication.
Route::middleware([\App\Http\Middleware\SessionAuthMiddleware::class])
    ->group(function () {
        // RTDC Routes
        Route::prefix('rtdc')->group(function () {
            Route::get('/global-huddle', [RTDCController::class, 'globalHuddle'])->name('rtdc.global-huddle');
            Route::post('/update-red-stretch-plan', [RTDCController::class, 'updateRedStretchPlan'])->name('rtdc.update-red-stretch-plan');
        });

        // Dashboard Routes — Zephyrus 2.0 P4a (D4, permanent): the five legacy
        // overviews redirect into the cockpit drill layer so every old bookmark
        // opens the matching DrillModal over /dashboard. Route names survive
        // because /improvement/overview and setPreference() resolve them. The
        // config flag is the rollback lever: COCKPIT_OVERVIEW_REDIRECTS=false
        // re-serves the original overview pages without a code revert.
        $legacyOverviews = [
            'dashboard.rtdc' => ['/dashboard/rtdc', 'rtdc', [RTDCDashboardController::class, 'index']],
            'dashboard.perioperative' => ['/dashboard/perioperative', 'periop', [DashboardController::class, 'index']],
            'dashboard.emergency' => ['/dashboard/emergency', 'ed', [EDDashboardController::class, 'index']],
            'dashboard.improvement' => ['/dashboard/improvement', 'quality', [DashboardController::class, 'improvement']],
            'dashboard.transport' => ['/dashboard/transport', 'flow', [TransportDashboardController::class, 'dashboard']],
        ];
        foreach ($legacyOverviews as $name => [$uri, $drillDomain, $legacyAction]) {
            if (config('cockpit.overview_redirects_enabled')) {
                Route::get($uri, fn () => redirect("/dashboard?drill={$drillDomain}"))->name($name);
            } else {
                Route::get($uri, $legacyAction)->name($name);
            }
        }
        Route::get('/dashboard', [CommandCenterController::class, 'index'])->name('dashboard');

        // Improvement Routes
        Route::prefix('improvement')->name('improvement.')->group(function () {
            Route::get('/overview', [DashboardController::class, 'overview'])->name('overview');
            Route::get('/bottlenecks', [DashboardController::class, 'bottlenecks'])->name('bottlenecks');
            Route::get('/process', [ProcessAnalysisController::class, 'index'])->name('process');
            Route::get('/root-cause', [DashboardController::class, 'rootCause'])->name('root-cause');
            Route::post('/process/layout', [ProcessAnalysisController::class, 'saveLayout'])->name('process.saveLayout');
            Route::get('/process/layout', [ProcessAnalysisController::class, 'getLayout'])->name('process.getLayout');
            Route::post('/process/viewport', [ProcessAnalysisController::class, 'saveViewport'])->name('process.saveViewport');
            Route::get('/library', [DashboardController::class, 'library'])->name('library');
            Route::get('/active', [DashboardController::class, 'active'])->name('active');
            Route::get('/opportunities', [DashboardController::class, 'opportunities'])->name('opportunities');

            // PDSA Routes
            Route::prefix('pdsa')->name('pdsa.')->group(function () {
                Route::get('/', [DashboardController::class, 'pdsaIndex'])->name('index');
                Route::post('/', [DashboardController::class, 'pdsaStore'])->name('store');
                // /create must precede /{id} so it isn't captured as a show param.
                Route::get('/create', function () {
                    return Inertia::render('Improvement/PDSA/Create');
                })->name('create');
                Route::get('/{id}', [DashboardController::class, 'pdsaShow'])->name('show');
            });
        });

        // RTDC Routes
        Route::prefix('rtdc')->name('rtdc.')->group(function () {
            // Analytics Routes
            Route::prefix('analytics')->name('analytics.')->group(function () {
                Route::get('/utilization', [RTDCDashboardController::class, 'utilization'])->name('utilization');
                Route::get('/performance', [RTDCDashboardController::class, 'performance'])->name('performance');
                Route::get('/resources', [RTDCDashboardController::class, 'resources'])->name('resources');
                Route::get('/trends', [RTDCDashboardController::class, 'trends'])->name('trends');
            });

            // Operations Routes
            Route::get('/bed-tracking', [RTDCDashboardController::class, 'bedTracking'])->name('bed-tracking');
            Route::get('/patient-flow-navigator', [RTDCDashboardController::class, 'patientFlowNavigator'])->name('patient-flow-navigator');
            Route::get('/ancillary-services', [RTDCDashboardController::class, 'ancillaryServices'])->name('ancillary-services');
            Route::get('/global-huddle', [RTDCDashboardController::class, 'globalHuddle'])->name('global-huddle');
            Route::get('/unit-huddle', [RTDCDashboardController::class, 'unitHuddle'])->name('unit-huddle');
            Route::get('/service-huddle', [RTDCDashboardController::class, 'serviceHuddle'])->name('service-huddle');
            Route::get('/bed-placement', [RTDCDashboardController::class, 'bedPlacement'])->name('bed-placement');

            // Predictions Routes
            Route::prefix('predictions')->name('predictions.')->group(function () {
                Route::get('/demand', [RTDCDashboardController::class, 'demandForecast'])->name('demand');
                Route::get('/resources', [RTDCDashboardController::class, 'resourcePlanning'])->name('resources');
                Route::get('/discharge', [RTDCDashboardController::class, 'dischargePriorities'])->name('discharge');
                Route::get('/risk', [RTDCDashboardController::class, 'riskAssessment'])->name('risk');
            });
        });

        // Analytics Routes
        Route::get('/analytics', [Analytics\AnalyticsController::class, 'index'])->name('analytics');
        Route::get('/analytics/live', [Analytics\AnalyticsController::class, 'live'])->name('analytics.live');
        Route::get('/analytics/retrospective', [Analytics\AnalyticsController::class, 'retrospective'])->name('analytics.retrospective');
        Route::get('/analytics/predictive', [Analytics\AnalyticsController::class, 'predictive'])->name('analytics.predictive');
        Route::get('/analytics/process-intelligence', [Analytics\AnalyticsController::class, 'processIntelligence'])->name('analytics.process-intelligence');
        Route::get('/analytics/opportunities', [Analytics\AnalyticsController::class, 'opportunities'])->name('analytics.opportunities');
        Route::get('/analytics/workbench', [Analytics\AnalyticsController::class, 'workbench'])->name('analytics.workbench');
        Route::get('/analytics/data-quality', [Analytics\AnalyticsController::class, 'dataQuality'])->name('analytics.data-quality');
        // Part X (X1) — Patient-Flow Arena Study surface. Gated by ARENA_ENABLED.
        Route::get('/analytics/arena', [Analytics\AnalyticsController::class, 'arena'])->name('analytics.arena')
            ->middleware(\App\Http\Middleware\EnsureArenaEnabled::class);
        Route::get('/analytics/block-utilization', [Analytics\BlockUtilizationController::class, 'index'])->name('analytics.block-utilization');
        Route::get('/analytics/or-utilization', [Analytics\ORUtilizationController::class, 'index'])->name('analytics.or-utilization');
        Route::get('/analytics/primetime-utilization', [Analytics\PrimetimeUtilizationController::class, 'index'])->name('analytics.primetime-utilization');
        Route::get('/analytics/room-running', [Analytics\RoomRunningController::class, 'index'])->name('analytics.room-running');
        Route::get('/analytics/turnover-times', [Analytics\TurnoverTimesController::class, 'index'])->name('analytics.turnover-times');

        // Operations Routes
        Route::get('/operations/room-status', [Operations\RoomStatusController::class, 'index'])->name('operations.room-status');
        Route::get('/operations/block-schedule', [Operations\BlockScheduleController::class, 'index'])->name('operations.block-schedule');
        Route::get('/operations/cases', [Operations\CaseManagementController::class, 'index'])->name('operations.cases');

        // Predictions Routes
        Route::get('/predictions/forecast', [Predictions\UtilizationForecastController::class, 'index'])->name('predictions.forecast');
        Route::get('/predictions/demand', [Predictions\DemandAnalysisController::class, 'index'])->name('predictions.demand');
        Route::get('/predictions/resources', [Predictions\ResourcePlanningController::class, 'index'])->name('predictions.resources');

        // ED Routes
        Route::prefix('ed')->name('ed.')->group(function () {
            // Analytics Routes
            Route::prefix('analytics')->name('analytics.')->group(function () {
                Route::get('/wait-time', [EDDashboardController::class, 'waitTime'])->name('wait-time');
                Route::get('/flow', [EDDashboardController::class, 'flow'])->name('flow');
                Route::get('/resources', [EDDashboardController::class, 'resources'])->name('resources');
            });

            // Operations Routes
            Route::prefix('operations')->name('operations.')->group(function () {
                Route::get('/triage', [EDDashboardController::class, 'triage'])->name('triage');
                Route::get('/treatment', [EDDashboardController::class, 'treatment'])->name('treatment');
                Route::get('/resources', [EDDashboardController::class, 'resourceManagement'])->name('resources');
            });

            // Predictions Routes
            Route::prefix('predictions')->name('predictions.')->group(function () {
                Route::get('/arrival', [EDDashboardController::class, 'arrival'])->name('arrival');
                Route::get('/acuity', [EDDashboardController::class, 'acuity'])->name('acuity');
                Route::get('/resources', [EDDashboardController::class, 'resourcePlanning'])->name('resources');
            });
        });

        // Staffing Office
        Route::get('/staffing', [StaffingDashboardController::class, 'index'])->name('staffing');

        // Enterprise setup retains facility taxonomy/readiness; integrations have
        // their own strictly gated control plane below.
        Route::get('/admin/enterprise-setup', [DeploymentConsoleController::class, 'index'])
            ->middleware('can:viewDeploymentConsole')
            ->name('admin.enterprise-setup');

        Route::get('/staffing/administration', [DeploymentConsoleController::class, 'staffingWizard'])
            ->middleware('can:manageDeploymentConfig')
            ->name('staffing.administration');

        Route::get('/integrations', [IntegrationConsoleController::class, 'index'])
            ->middleware('can:viewIntegrations')
            ->name('integrations');

        // Authorized legacy bookmarks preserve their original functional target.
        Route::get('/deployment', fn () => redirect()->route('admin.enterprise-setup'))
            ->middleware('can:viewDeploymentConsole')
            ->name('deployment');
        Route::get('/deployment/staffing', fn () => redirect()->route('staffing.administration'))
            ->middleware('can:manageDeploymentConfig')
            ->name('deployment.staffing');

        // Operations Console — agent governance + executive brief
        Route::get('/ops/agent-inbox', [OpsConsoleController::class, 'agentInbox'])->name('ops.agent-inbox');
        Route::get('/ops/executive-brief', [OpsConsoleController::class, 'executiveBrief'])->name('ops.executive-brief');

        // Transport Routes
        Route::prefix('transport')->name('transport.')->group(function () {
            Route::get('/requests', [TransportDashboardController::class, 'requests'])->name('requests');
            Route::get('/dispatch', [TransportDashboardController::class, 'dispatch'])->name('dispatch');
            Route::get('/inpatient', [TransportDashboardController::class, 'inpatient'])->name('inpatient');
            Route::get('/transfers', [TransportDashboardController::class, 'transfers'])->name('transfers');
            Route::get('/discharge', [TransportDashboardController::class, 'discharge'])->name('discharge');
            Route::get('/ems', [TransportDashboardController::class, 'ems'])->name('ems');
            Route::get('/care-transitions', [TransportDashboardController::class, 'careTransitions'])->name('care-transitions');
            Route::get('/resources', [TransportDashboardController::class, 'resources'])->name('resources');
            Route::get('/analytics', [TransportDashboardController::class, 'analytics'])->name('analytics');
            Route::get('/settings/integrations', fn () => redirect()->route('integrations'))
                ->middleware('can:viewIntegrations')
                ->name('settings.integrations');
        });

        // Design Routes
        Route::prefix('design')->name('design.')->group(function () {
            Route::get('/components', [DesignController::class, 'components'])->name('components');
            Route::get('/cards', [DesignController::class, 'cards'])->name('cards');
            Route::get('/ui-components', function () {
                return Inertia::render('Examples/ComponentsDemo');
            })->name('ui-components');

            Route::get('/simple-test', function () {
                return Inertia::render('Examples/SimpleTest');
            })->name('simple-test');
        });

        // Profile Routes
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

        // Zephyrus-native administration. Integration and operational ledgers
        // remain on their existing, separately gated surfaces.
        Route::middleware('can:viewAdministration')->group(function () {
            Route::get('/admin', AdminDashboardController::class)->name('admin.dashboard');
            Route::resource('users', \App\Http\Controllers\UserController::class)->except('show');

            Route::get('/admin/user-audit', [UserAuditController::class, 'index'])
                ->middleware('can:viewUserAudit')
                ->name('admin.user-audit.index');

            // P8 WS-6b — the cockpit threshold editor (band-edge tuning without a
            // deploy). The page self-fetches GET/PUT /api/cockpit/kpi-definitions,
            // both AdminMiddleware-gated + audited.
            Route::get('/admin/cockpit/thresholds', fn () => Inertia::render('Admin/CockpitThresholds'))
                ->name('admin.cockpit.thresholds');
        });

        // User Preferences Route - Using GET with URL parameters
        Route::get('/set-preference/{workflow}', [DashboardController::class, 'setPreference'])
            ->name('user.set-preference')
            ->where('workflow', 'superuser|rtdc|perioperative|emergency|improvement|transport');
    });

require __DIR__.'/auth.php';
