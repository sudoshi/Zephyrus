<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * DEPRECATED: This middleware is no longer needed as CSRF verification has been
 * completely replaced with session-based authentication.
 * 
 * This middleware is kept for compatibility but is not used in the application.
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
        // Simply pass the request to the next middleware
        return $next($request);
    }
}
