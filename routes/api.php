<?php

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\BlockScheduleController;
use App\Http\Controllers\Api\ORCaseController;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\ServiceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// OR Cases
Route::prefix('cases')->group(function () {
    Route::get('/', [ORCaseController::class, 'index']);
    Route::post('/', [ORCaseController::class, 'store']);
    Route::put('/{id}', [ORCaseController::class, 'update']);
    Route::get('/today', [ORCaseController::class, 'todaysCases']);
    Route::get('/metrics', [ORCaseController::class, 'metrics']);
    Route::get('/room-status', [ORCaseController::class, 'roomStatus']);
});

// Block Schedule
Route::prefix('blocks')->group(function () {
    Route::get('/', [BlockScheduleController::class, 'index']);
    Route::post('/', [BlockScheduleController::class, 'store']);
    Route::get('/utilization', [BlockScheduleController::class, 'utilization']);
    Route::get('/service-utilization', [BlockScheduleController::class, 'serviceUtilization']);
    Route::get('/room-utilization', [BlockScheduleController::class, 'roomUtilization']);
});

// Analytics
Route::prefix('analytics')->group(function () {
    Route::get('/service-performance', [AnalyticsController::class, 'servicePerformance']);
    Route::get('/provider-performance', [AnalyticsController::class, 'providerPerformance']);
    Route::get('/historical-trends', [AnalyticsController::class, 'historicalTrends']);
});

// Reference Data
Route::get('/services', [ServiceController::class, 'index']);
Route::get('/rooms', [RoomController::class, 'index']);
Route::get('/providers', [ProviderController::class, 'index']);
