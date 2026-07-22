<?php

use App\Http\Controllers\Api\Patient\AuthController;
use App\Http\Controllers\Api\Patient\EducationClarificationController;
use App\Http\Controllers\Api\Patient\EncounterController;
use App\Http\Controllers\Api\Patient\EncounterProjectionController;
use App\Http\Controllers\Api\Patient\MeController;
use App\Http\Controllers\Api\Patient\MessagingController;
use App\Http\Controllers\Api\Patient\NotificationDeviceController;
use App\Http\Controllers\Api\Patient\SessionController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

/*
|--------------------------------------------------------------------------
| Hummingbird Patient API v1
|--------------------------------------------------------------------------
|
| This route file is intentionally separate from both routes/api.php and the
| staff /api/mobile/v1 BFF. The product-wide and per-feature middleware gates
| all fail closed; the authenticated surface additionally enforces a patient
| model realm after Sanctum resolves the bearer token.
|
*/

Route::middleware(['patient.response', 'patient.enabled'])->group(function () {
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('/enroll/challenge/verify', [AuthController::class, 'enroll'])
            ->middleware(['patient.feature:enrollment', 'throttle:patient-enrollment'])
            ->name('enroll');

        Route::post('/token', [AuthController::class, 'token'])
            ->middleware(['patient.feature:token_exchange', 'throttle:patient-credential-exchange'])
            ->name('token');

        Route::middleware([
            'patient.feature:token_exchange',
            'auth:sanctum',
            'patient.realm',
            'throttle:patient-authenticated',
        ])->group(function () {
            Route::post('/token/refresh', [AuthController::class, 'refresh'])
                ->middleware(CheckForAnyAbility::class.':patient:refresh')
                ->name('refresh');
            Route::post('/token/revoke', [AuthController::class, 'revoke'])
                ->middleware(CheckForAnyAbility::class.':patient:access,patient:refresh')
                ->name('revoke');
        });
    });

    $patientAccessMiddleware = [
        'auth:sanctum',
        'patient.realm',
        CheckForAnyAbility::class.':patient:access',
        'throttle:patient-api',
    ];

    Route::middleware('patient.feature:profile')->group(function () use ($patientAccessMiddleware) {
        Route::middleware($patientAccessMiddleware)->group(function () {
            Route::get('/me', [MeController::class, 'show'])->name('me.show');
            Route::put('/me/preferences', [MeController::class, 'updatePreferences'])->name('me.preferences.update');
        });
    });

    Route::middleware('patient.feature:session_management')->group(function () use ($patientAccessMiddleware) {
        Route::middleware($patientAccessMiddleware)->group(function () {
            Route::get('/me/sessions', [SessionController::class, 'index'])->name('me.sessions.index');
            Route::delete('/me/sessions/{sessionUuid}', [SessionController::class, 'destroy'])
                ->whereUuid('sessionUuid')
                ->name('me.sessions.destroy');
        });
    });

    Route::middleware('patient.feature:notification_devices')->group(function () use ($patientAccessMiddleware) {
        Route::middleware($patientAccessMiddleware)->group(function () {
            Route::put('/me/notification-devices/{deviceUuid}', [NotificationDeviceController::class, 'store'])
                ->whereUuid('deviceUuid')
                ->name('me.notification-devices.store');
            Route::delete('/me/notification-devices/{deviceUuid}', [NotificationDeviceController::class, 'destroy'])
                ->whereUuid('deviceUuid')
                ->name('me.notification-devices.destroy');
        });
    });

    Route::middleware('patient.feature:encounters')->group(function () use ($patientAccessMiddleware) {
        Route::get('/encounters', [EncounterController::class, 'index'])
            ->middleware($patientAccessMiddleware)
            ->name('encounters.index');
    });

    Route::get('/encounters/{encounterUuid}/today', [EncounterProjectionController::class, 'today'])
        ->whereUuid('encounterUuid')
        ->middleware(array_merge(['patient.feature:today'], $patientAccessMiddleware))
        ->name('encounters.today');

    Route::get('/encounters/{encounterUuid}/pathway', [EncounterProjectionController::class, 'pathway'])
        ->whereUuid('encounterUuid')
        ->middleware(array_merge(['patient.feature:pathway'], $patientAccessMiddleware))
        ->name('encounters.pathway');

    Route::get('/encounters/{encounterUuid}/pathway/events', [EncounterProjectionController::class, 'pathwayEvents'])
        ->whereUuid('encounterUuid')
        ->middleware(array_merge(['patient.feature:pathway'], $patientAccessMiddleware))
        ->name('encounters.pathway-events');

    Route::get('/encounters/{encounterUuid}/discharge-readiness', [EncounterProjectionController::class, 'dischargeReadiness'])
        ->whereUuid('encounterUuid')
        ->middleware(array_merge(['patient.feature:pathway'], $patientAccessMiddleware))
        ->name('encounters.discharge-readiness');

    Route::get('/encounters/{encounterUuid}/rounds/summary', [EncounterProjectionController::class, 'roundsSummary'])
        ->whereUuid('encounterUuid')
        ->middleware(array_merge(['patient.feature:rounds_summary'], $patientAccessMiddleware))
        ->name('encounters.rounds-summary');

    Route::get('/encounters/{encounterUuid}/care-team', [EncounterProjectionController::class, 'careTeam'])
        ->whereUuid('encounterUuid')
        ->middleware(array_merge(['patient.feature:care_team'], $patientAccessMiddleware))
        ->name('encounters.care-team');

    Route::post('/encounters/{encounterUuid}/education/{educationItemUuid}/clarifications', [EducationClarificationController::class, 'store'])
        ->whereUuid(['encounterUuid', 'educationItemUuid'])
        ->middleware(array_merge(['patient.feature:teach_back'], $patientAccessMiddleware))
        ->name('encounters.education.clarifications.store');

    Route::middleware('patient.feature:messaging')->group(function () use ($patientAccessMiddleware) {
        Route::get('/encounters/{encounterUuid}/message-topics', [MessagingController::class, 'topics'])
            ->whereUuid('encounterUuid')
            ->middleware($patientAccessMiddleware)
            ->name('encounters.message-topics');
        Route::get('/encounters/{encounterUuid}/threads', [MessagingController::class, 'index'])
            ->whereUuid('encounterUuid')
            ->middleware($patientAccessMiddleware)
            ->name('encounters.threads.index');
        Route::post('/encounters/{encounterUuid}/threads', [MessagingController::class, 'store'])
            ->whereUuid('encounterUuid')
            ->middleware($patientAccessMiddleware)
            ->name('encounters.threads.store');
        Route::get('/threads/{threadUuid}', [MessagingController::class, 'show'])
            ->whereUuid('threadUuid')
            ->middleware($patientAccessMiddleware)
            ->name('threads.show');
        Route::post('/threads/{threadUuid}/messages', [MessagingController::class, 'send'])
            ->whereUuid('threadUuid')
            ->middleware($patientAccessMiddleware)
            ->name('threads.messages.store');
        Route::post('/threads/{threadUuid}/messages/{messageUuid}/amend', [MessagingController::class, 'amend'])
            ->whereUuid(['threadUuid', 'messageUuid'])
            ->middleware($patientAccessMiddleware)
            ->name('threads.messages.amend');
        Route::post('/threads/{threadUuid}/close', [MessagingController::class, 'close'])
            ->whereUuid('threadUuid')
            ->middleware($patientAccessMiddleware)
            ->name('threads.close');
    });
});
