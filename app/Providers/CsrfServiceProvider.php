<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use App\Http\Middleware\DisableCsrfForAllRoutes;

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
        // Register the middleware globally
        $kernel->pushMiddleware(DisableCsrfForAllRoutes::class);
    }
}
