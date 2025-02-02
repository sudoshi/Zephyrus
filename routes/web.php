<?php

use App\Http\Controllers\Analytics;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Operations;
use App\Http\Controllers\Predictions;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::middleware(['auth'])->group(function () {
    // Main Navigation Routes
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

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

    // Profile Routes
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
