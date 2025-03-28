<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use App\Http\Middleware\AddXsrfTokenMiddleware;

/**
 * DEPRECATED: This service provider is no longer needed as CSRF verification has been
 * completely replaced with session-based authentication.
 * 
 * This provider is kept for compatibility but is not used in the application.
 */
class CsrfServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(Kernel $kernel): void
    {
        // CSRF token middleware is no longer needed
        // $kernel->pushMiddleware(AddXsrfTokenMiddleware::class);
    }
}
