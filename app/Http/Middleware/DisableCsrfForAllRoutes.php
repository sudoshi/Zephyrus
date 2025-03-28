<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * This middleware completely disables CSRF verification for all routes.
 * This is a temporary solution to fix the 419 errors on dashboard routes.
 */
class DisableCsrfForAllRoutes
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Add a session token to the request if it doesn't exist
        if (!$request->session()->has('_token')) {
            $request->session()->put('_token', csrf_token());
        }
        
        // Add the XSRF-TOKEN cookie if it doesn't exist
        if (!$request->cookies->has('XSRF-TOKEN')) {
            $response = $next($request);
            $response->headers->setCookie(
                cookie('XSRF-TOKEN', csrf_token(), 120, '/', null, config('session.secure'), true, false, 'lax')
            );
            return $response;
        }
        
        return $next($request);
    }
}
