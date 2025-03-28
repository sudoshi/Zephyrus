<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use App\Http\Middleware\AddXsrfTokenMiddleware;

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
        // Register the XSRF token middleware globally
        // This ensures all responses have the XSRF-TOKEN cookie set
        $kernel->pushMiddleware(AddXsrfTokenMiddleware::class);
    }
}
