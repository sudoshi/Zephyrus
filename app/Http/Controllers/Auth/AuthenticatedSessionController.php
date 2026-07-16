<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Providers\RouteServiceProvider;
use App\Services\Auth\AccountSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response; // Import the RouteServiceProvider class

class AuthenticatedSessionController extends Controller
{
    public function __construct(private readonly AccountSessionService $sessions) {}

    /**
     * Display the login view.
     */
    public function create(): Response
    {
        $oidc = app(\App\Services\Auth\Oidc\OidcProviderConfig::class);

        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
            'localAuthEnabled' => (bool) config('auth-drivers.local.enabled', true),
            'registrationEnabled' => (bool) config('auth-drivers.local.registration_enabled', false),
            'oidcEnabled' => $oidc->isPubliclyAvailable(),
            'oidcLabel' => $oidc->displayName(),
            'showDemoCredentials' => ! app()->environment('production')
                && (bool) config('demo.auto_login_enabled', false)
                && (bool) config('demo.show_credentials', false),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        abort_unless(
            config('auth-drivers.local.enabled', true),
            403,
            'Local password authentication is disabled.',
        );

        $request->authenticate();

        $request->session()->regenerate();

        // Ensure user has default workflow preference
        if (auth()->user()->workflow_preference === null) {
            auth()->user()->update(['workflow_preference' => 'superuser']);
        }

        $this->sessions->establish($request, auth()->user());

        // Check if user must change their password
        if (auth()->user()->must_change_password) {
            return redirect()->route('password.change');
        }

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
