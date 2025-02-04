<?php

use App\Http\Controllers\Analytics;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EDDashboardController;
use App\Http\Controllers\Operations;
use App\Http\Controllers\Predictions;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RTDCDashboardController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::middleware(['auth'])->group(function () {
    // Dashboard Routes
    Route::get('/dashboard/rtdc', [RTDCDashboardController::class, 'index'])->name('dashboard.rtdc');
    Route::get('/dashboard/or', [DashboardController::class, 'index'])->name('dashboard.or');
    Route::get('/dashboard/ed', [EDDashboardController::class, 'index'])->name('dashboard.ed');
    Route::get('/dashboard', function() { return redirect()->route('dashboard.or'); })->name('dashboard');

    // RTDC Routes
    Route::prefix('rtdc')->name('rtdc.')->group(function () {
        // Core RTDC Pages
        Route::get('/bed-tracking', [RTDCDashboardController::class, 'bedTracking'])->name('bed-tracking');
        Route::get('/ancillary-services', [RTDCDashboardController::class, 'ancillaryServices'])->name('ancillary-services');
        Route::get('/discharge-prediction', [RTDCDashboardController::class, 'dischargePrediction'])->name('discharge-prediction');
        Route::get('/global-huddle', [RTDCDashboardController::class, 'globalHuddle'])->name('global-huddle');
        Route::get('/unit-huddle', [RTDCDashboardController::class, 'unitHuddle'])->name('unit-huddle');
        Route::get('/services-huddle', [RTDCDashboardController::class, 'servicesHuddle'])->name('services-huddle');
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

    // Profile Routes
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
