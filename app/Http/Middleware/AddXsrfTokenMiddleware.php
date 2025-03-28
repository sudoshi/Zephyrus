<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class AddXsrfTokenMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Process the request first
        $response = $next($request);
        
        // Ensure session has a CSRF token
        if (!$request->session()->has('_token')) {
            $request->session()->regenerateToken();
        }
        
        // Add the XSRF-TOKEN cookie to the response with the proper SameSite attribute
        // This cookie is read by frontend JavaScript and added to request headers
        $response->headers->setCookie(
            cookie(
                'XSRF-TOKEN',                      // name
                $request->session()->token(),      // value
                null,                              // expire (null = session cookie)
                '/',                               // path
                null,                              // domain
                config('session.secure'),          // secure (HTTPS only)
                false,                             // httpOnly (false allows JavaScript to read it)
                true,                              // raw
                config('session.same_site', 'lax') // SameSite
            )
        );
        
        return $response;
    }
}
