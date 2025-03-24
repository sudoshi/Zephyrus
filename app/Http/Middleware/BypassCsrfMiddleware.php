<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * This middleware completely disables CSRF verification by skipping the parent
 * verification steps. Use this with caution as it lowers security by disabling
 * protection against cross-site request forgery attacks.
 * 
 * This is used when CSRF tokens are causing issues with the application flow.
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
