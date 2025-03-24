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
        $response = $next($request);
        
        // Regenerate the token if it doesn't exist
        if (!$request->session()->has('_token')) {
            $request->session()->regenerateToken();
        }
        
        // Add the XSRF-TOKEN cookie to the response
        $response->headers->setCookie(
            cookie('XSRF-TOKEN', $request->session()->token(), null, '/', null, false, false, false, 'none')
        );
        
        return $response;
    }
}
