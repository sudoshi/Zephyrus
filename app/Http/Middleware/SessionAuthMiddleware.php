<?php

namespace App\Http\Middleware;

use App\Models\User;
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

        // The admin auto-login below is a LOCAL/DEMO convenience ONLY. In any other
        // environment (production, staging) this middleware must behave like real `auth`
        // — never mint an admin session for an anonymous visitor. See
        // .claude/rules/auth-system.md (the temp-password + must_change_password flow).
        if (! app()->environment(['local', 'testing'])) {
            return $request->expectsJson()
                ? abort(401, 'Unauthenticated.')
                : redirect()->guest(route('login'));
        }

        // Auto-login as admin user (local/demo only)
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

        // Log the user in
        Auth::login($user);

        // Set the workflow preference in session
        $request->session()->put('workflow', 'superuser');

        // Proceed with the request
        return $next($request);
    }
}
