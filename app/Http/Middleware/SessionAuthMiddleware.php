<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SessionAuthMiddleware
{
    /**
     * Handle an incoming request.
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

        // Get the session ID
        $sessionId = $request->session()->getId();
        
        // Check if the session exists in the database
        $sessionExists = DB::table('sessions')
            ->where('id', $sessionId)
            ->exists();
            
        // If the session doesn't exist, redirect to login
        if (!$sessionExists) {
            return redirect()->route('login');
        }
        
        // Get user_id from the session data
        $session = DB::table('sessions')
            ->where('id', $sessionId)
            ->first();
            
        if ($session && $session->user_id) {
            // Log the user in
            Auth::loginUsingId($session->user_id);
            
            // Proceed with the request
            return $next($request);
        }
        
        // If no user_id in session, redirect to login
        return redirect()->route('login');
    }
}
