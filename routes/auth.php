<?php

use App\Http\Controllers\Admin\AuthProviderController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\OidcController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::post('register', [RegisteredUserController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');

});

// OIDC state/nonce/PKCE protects the callback. These routes intentionally sit
// outside `guest` so an authenticated administrator can perform upstream MFA
// step-up without being redirected away by RedirectIfAuthenticated.
Route::get('auth/oidc/redirect', [OidcController::class, 'redirect'])
    ->middleware('throttle:12,1')
    ->name('auth.oidc.redirect');
Route::get('auth/oidc/callback', [OidcController::class, 'callback'])
    ->middleware('throttle:12,1')
    ->name('auth.oidc.callback');
Route::get('auth/oidc/step-up', [OidcController::class, 'redirect'])
    ->middleware(['auth', 'throttle:6,1'])
    ->name('auth.oidc.step-up');

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('change-password', [ChangePasswordController::class, 'show'])
        ->name('password.change');

    Route::post('change-password', [ChangePasswordController::class, 'update'])
        ->name('password.change.update');

    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');

    Route::get('admin/auth-providers/{type}', [AuthProviderController::class, 'show'])
        ->middleware('can:viewIdentity')
        ->name('admin.auth-providers.show');
    Route::put('admin/auth-providers/{type}', [AuthProviderController::class, 'update'])
        ->middleware('can:manageIdentity')
        ->name('admin.auth-providers.update');
    Route::post('admin/auth-providers/{type}/diagnostics', [AuthProviderController::class, 'diagnose'])
        ->middleware(['can:viewIdentity', 'throttle:6,1'])
        ->name('admin.auth-providers.diagnostics');
});
