<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

/**
 * DEPRECATED: This middleware is no longer needed as CSRF verification has been
 * completely replaced with session-based authentication.
 * 
 * This middleware is kept for compatibility but is not used in the application.
 */
class AddXsrfTokenMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Simply pass the request to the next middleware
        return $next($request);
    }
}
