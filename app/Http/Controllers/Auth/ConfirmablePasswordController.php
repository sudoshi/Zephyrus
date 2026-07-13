<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\Oidc\OidcProviderConfig;
use App\Services\Auth\StepUpAuthenticationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ConfirmablePasswordController extends Controller
{
    /**
     * Show the confirm password view.
     */
    public function show(OidcProviderConfig $oidc): Response
    {
        return Inertia::render('Auth/ConfirmPassword', [
            'oidcAvailable' => $oidc->isPubliclyAvailable(),
        ]);
    }

    /**
     * Confirm the user's password.
     */
    public function store(Request $request, StepUpAuthenticationService $stepUp): RedirectResponse
    {
        if (! Auth::guard('web')->validate([
            'email' => $request->user()->email,
            'password' => $request->password,
        ])) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        $stepUp->markPasswordConfirmed($request);

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
