<?php

use App\Http\Controllers\Api\CarePathways\CarePathwayDemoController;
use App\Http\Controllers\Api\CarePathways\CatalogGovernanceController;
use App\Http\Middleware\EnsureCarePathwayDemoEnabled;
use App\Http\Middleware\EnsureCarePathwayGovernanceEnabled;
use Illuminate\Support\Facades\Route;

Route::middleware([
    EnsureCarePathwayDemoEnabled::class,
    'web',
    'auth',
    'throttle:30,1',
])->get('/demo/scenario', CarePathwayDemoController::class)->name('demo.scenario');

Route::middleware([
    EnsureCarePathwayGovernanceEnabled::class,
    'web',
    'auth',
    'can:viewCarePathwayCatalog',
    'throttle:30,1',
])->group(function (): void {
    Route::get('/summary', [CatalogGovernanceController::class, 'summary'])->name('summary');
    Route::get('/pathways', [CatalogGovernanceController::class, 'pathways'])->name('pathways.index');
    Route::get('/versions/{versionUuid}', [CatalogGovernanceController::class, 'version'])
        ->whereUuid('versionUuid')
        ->name('versions.show');
    Route::get('/versions/{versionUuid}/claims', [CatalogGovernanceController::class, 'claims'])
        ->whereUuid('versionUuid')
        ->name('versions.claims');
    Route::get('/sources', [CatalogGovernanceController::class, 'sources'])->name('sources.index');
    Route::get('/sources/{sourceUuid}', [CatalogGovernanceController::class, 'source'])
        ->whereUuid('sourceUuid')
        ->name('sources.show');
    Route::get('/controls', [CatalogGovernanceController::class, 'controls'])->name('controls.index');
    Route::get('/reviews', [CatalogGovernanceController::class, 'reviews'])->name('reviews.index');
    Route::get('/approvals', [CatalogGovernanceController::class, 'approvals'])->name('approvals.index');
    Route::get('/events', [CatalogGovernanceController::class, 'events'])->name('events.index');
});
