<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Audit\UserAuditRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SessionAuthMiddleware
{
    /**
     * Auto-login middleware that ensures a user is always authenticated.
     * Replaces traditional authentication with an automatic login as admin user.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next): Response
    {
        // If the user is already authenticated, proceed
        if (Auth::check()) {
            return $next($request);
        }

        // Auto-login as admin user
        $user = User::firstOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'Administrator',
                'email' => 'admin@example.com',
                'password' => bcrypt('password'),
                'workflow_preference' => 'superuser',
                'must_change_password' => false,
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        if ($user->must_change_password || ! in_array($user->role, ['admin', 'bed_manager'], true)) {
            $user->forceFill([
                'workflow_preference' => $user->workflow_preference ?: 'superuser',
                'must_change_password' => false,
                'role' => 'admin',
                'is_active' => true,
            ])->save();
        }

        $request->attributes->set(UserAuditRecorder::AUTH_METHOD_ATTRIBUTE, 'demo');
        $request->attributes->set(UserAuditRecorder::SOURCE_SURFACE_ATTRIBUTE, 'web');

        // Log the user in
        Auth::login($user);

        // Set the workflow preference in session
        $request->session()->put('workflow', 'superuser');

        // Proceed with the request
        return $next($request);
    }
}
