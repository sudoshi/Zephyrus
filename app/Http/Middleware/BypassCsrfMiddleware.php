<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * DEPRECATED: This middleware is no longer needed as CSRF verification has been
 * completely replaced with session-based authentication.
 * 
 * This middleware is kept for compatibility but is not used in the application.
 */
class BypassCsrfMiddleware extends VerifyCsrfToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        // Simply pass the request to the next middleware without CSRF verification
        return $next($request);
    }
    
    /**
     * Add URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Exclude all routes from CSRF verification
        '*',
    ];
}
