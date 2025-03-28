<?php

use App\Http\Controllers\Analytics;
use App\Http\Controllers\DesignController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EDDashboardController;
use App\Http\Controllers\Operations;
use App\Http\Controllers\Predictions;
use App\Http\Controllers\ProcessAnalysisController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RTDCDashboardController;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Http\Controllers\RTDCController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;

// Process Analysis API Routes
Route::get('/improvement/api/nursing-operations', [ProcessAnalysisController::class, 'getNursingOperations']);

// Temporary debug route for checking CSRF token issues
Route::get('/debug-session', function (Request $request) {
    return response()->json([
        'csrf_token' => csrf_token(),
        'session_id' => session()->getId(),
        'session_status' => session()->isStarted(),
        'cookies' => $request->cookies->all(),
        'session_domain' => config('session.domain'),
        'session_secure' => config('session.secure'),
        'app_url' => config('app.url'),
        'stateful_domains' => config('sanctum.stateful'),
        'request_url' => $request->url(),
        'request_host' => $request->getHost(),
        'xsrf_token_cookie' => $request->cookie('XSRF-TOKEN')
    ]);
});

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('home');
    }
    return Inertia::render('Auth/Login', [
        'canResetPassword' => Route::has('password.request'),
        'status' => session('status'),
    ]);
});

Route::middleware(['auth'])->group(function () {
    // Home Route
    Route::get('/home', function() {
        return Inertia::render('Home/Home', [
            'workflow' => 'home'
        ]);
    })->name('home');

    // RTDC Routes
    Route::prefix('rtdc')->group(function () {
        Route::get('/global-huddle', [RTDCController::class, 'globalHuddle'])->name('rtdc.global-huddle');
        Route::post('/update-red-stretch-plan', [RTDCController::class, 'updateRedStretchPlan'])->name('rtdc.update-red-stretch-plan');
    });

    // Dashboard Routes
    Route::get('/dashboard/rtdc', [RTDCDashboardController::class, 'index'])->name('dashboard.rtdc');
    Route::get('/dashboard/perioperative', [DashboardController::class, 'index'])->name('dashboard.perioperative');
    Route::get('/dashboard/emergency', [EDDashboardController::class, 'index'])->name('dashboard.emergency');
    Route::get('/dashboard/improvement', [DashboardController::class, 'improvement'])->name('dashboard.improvement');
    Route::get('/dashboard', function(Request $request) {
        $request->session()->put('workflow', 'superuser');
        return Inertia::render('Home/Home', [
            'workflow' => 'superuser'
        ]);
    })->name('dashboard');

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
        
        
        // PDSA Routes
        Route::prefix('pdsa')->name('pdsa.')->group(function () {
            Route::get('/', [DashboardController::class, 'pdsaIndex'])->name('index');
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
        Route::get('/ancillary-services', [RTDCDashboardController::class, 'ancillaryServices'])->name('ancillary-services');
        Route::get('/global-huddle', [RTDCDashboardController::class, 'globalHuddle'])->name('global-huddle');
        Route::get('/service-huddle', [RTDCDashboardController::class, 'serviceHuddle'])->name('service-huddle');

        // Predictions Routes
        Route::prefix('predictions')->name('predictions.')->group(function () {
            Route::get('/demand', [RTDCDashboardController::class, 'demandForecast'])->name('demand');
            Route::get('/resources', [RTDCDashboardController::class, 'resourcePlanning'])->name('resources');
            Route::get('/discharge', function() {
                return Inertia::render('RTDC/DischargePriorities');
            })->name('discharge');
            Route::get('/risk', [RTDCDashboardController::class, 'riskAssessment'])->name('risk');
        });
    });

    // Analytics Routes
    Route::get('/analytics', [Analytics\AnalyticsController::class, 'index'])->name('analytics');
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

    // Design Routes
    Route::prefix('design')->name('design.')->group(function () {
        Route::get('/components', [DesignController::class, 'components'])->name('components');
        Route::get('/cards', [DesignController::class, 'cards'])->name('cards');
        Route::get('/ui-components', function() {
            return Inertia::render('Examples/ComponentsDemo');
        })->name('ui-components');
        
        Route::get('/simple-test', function() {
            return Inertia::render('Examples/SimpleTest');
        })->name('simple-test');
    });

    // Profile Routes
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // User Management Routes - Only accessible to admins
    Route::middleware([\App\Http\Middleware\AdminMiddleware::class])->group(function () {
        Route::resource('users', \App\Http\Controllers\UserController::class);
    });

    // User Preferences Route
    Route::patch('/user/preferences', [DashboardController::class, 'updatePreferences'])
        ->name('user.preferences.update');
});

require __DIR__.'/auth.php';
