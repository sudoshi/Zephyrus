<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\AccountSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class ChangePasswordController extends Controller
{
    public function __construct(private readonly AccountSessionService $sessions) {}

    /**
     * Display the change password page.
     */
    public function show(): Response
    {
        return Inertia::render('Auth/ChangePassword');
    }

    /**
     * Handle the password change request.
     */
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        // Verify current password
        if (! Hash::check($request->current_password, $user->password)) {
            return back()->withErrors([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        // Ensure new password is different from current
        if (Hash::check($request->new_password, $user->password)) {
            return back()->withErrors([
                'new_password' => 'The new password must be different from your current password.',
            ]);
        }

        $user->update([
            'password' => Hash::make($request->new_password),
            'must_change_password' => false,
        ]);
        $this->sessions->revoke($user, $request, 'password_changed', keepCurrentSession: true);

        return redirect()->route('dashboard')->with('status', 'Password changed successfully.');
    }
}
