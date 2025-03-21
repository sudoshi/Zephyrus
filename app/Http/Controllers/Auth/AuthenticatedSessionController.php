<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use App\Providers\RouteServiceProvider; // Import the RouteServiceProvider class

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // Store the selected workflow in the session
        $request->session()->put('workflow', $request->workflow);

        // Store the username in the session
        $request->session()->put('username', $request->username);

        // Redirect to the appropriate dashboard based on workflow
        $dashboardRoute = match($request->workflow) {
            'rtdc' => 'dashboard.rtdc',
            'perioperative' => 'dashboard.perioperative',
            'emergency' => 'dashboard.emergency',
            'improvement' => 'dashboard.improvement',
            'superuser' => 'dashboard',
            default => 'dashboard'
        };

        return redirect()->intended(route($dashboardRoute));
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
