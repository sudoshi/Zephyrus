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
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
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
                'workflow_preference' => 'superuser'
            ]
        );
        
        // Log the user in
        Auth::login($user);
        
        // Set the workflow preference in session
        $request->session()->put('workflow', 'superuser');
        
        // Proceed with the request
        return $next($request);
    }
}
