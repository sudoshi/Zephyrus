<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Only exclude endpoints that truly need CSRF protection disabled
        // For SPA using Inertia, most routes should have CSRF protection
        '/api/*',          // API routes typically use token auth instead
        '/webhook/*',      // External webhook endpoints
    ];
}
