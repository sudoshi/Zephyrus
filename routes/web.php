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
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Process Analysis API Routes
Route::get('/improvement/api/nursing-operations', [ProcessAnalysisController::class, 'getNursingOperations']);

Route::get('/', function () {
    return redirect()->route('login');
});

Route::middleware(['auth'])->group(function () {
    // Dashboard Routes
    Route::get('/dashboard/rtdc', [RTDCDashboardController::class, 'index'])->name('dashboard.rtdc');
    Route::get('/dashboard/perioperative', [DashboardController::class, 'index'])->name('dashboard.perioperative');
    Route::get('/dashboard/emergency', [EDDashboardController::class, 'index'])->name('dashboard.emergency');
    Route::get('/dashboard/improvement', [DashboardController::class, 'improvement'])->name('dashboard.improvement');
    Route::get('/dashboard', function() {
        return redirect()->route('dashboard.perioperative');
    })->name('dashboard');

    // Improvement Routes
    Route::prefix('improvement')->name('improvement.')->group(function () {
        Route::get('/overview', [DashboardController::class, 'overview'])->name('overview');
        Route::get('/opportunities', [DashboardController::class, 'opportunities'])->name('opportunities');
        Route::get('/process', [ProcessAnalysisController::class, 'index'])->name('process');
        Route::post('/process/layout', [ProcessAnalysisController::class, 'saveLayout'])->name('process.saveLayout');
        Route::get('/process/layout', [ProcessAnalysisController::class, 'getLayout'])->name('process.getLayout');
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
            Route::get('/discharge', [RTDCDashboardController::class, 'dischargePredictions'])->name('discharge');
            Route::get('/risk', [RTDCDashboardController::class, 'riskAssessment'])->name('risk');
        });
    });

    // Analytics Routes
    Route::get('/analytics/service', [Analytics\ServiceAnalyticsController::class, 'index'])->name('analytics.service');
    Route::get('/analytics/provider', [Analytics\ProviderAnalyticsController::class, 'index'])->name('analytics.provider');
    Route::get('/analytics/trends', [Analytics\HistoricalTrendsController::class, 'index'])->name('analytics.trends');

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
    });

    // Profile Routes
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Workflow Change Route
    Route::post('/change-workflow', [DashboardController::class, 'changeWorkflow'])->name('change-workflow');
});

require __DIR__.'/auth.php';
