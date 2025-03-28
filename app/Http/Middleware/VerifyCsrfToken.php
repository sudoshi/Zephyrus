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
        '/change-workflow', // Exempt the workflow change route
        '/login', // Exempt the login route
        '/logout', // Exempt the logout route
        'api/*' // Exempt API routes
    ];
}
