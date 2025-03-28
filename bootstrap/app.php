<?php

use App\Http\Middleware\AddXsrfTokenMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(
            remove: [
                \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            ],
            append: [
                \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
                \App\Http\Middleware\HandleInertiaRequests::class,
                \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
                \App\Http\Middleware\SessionAuthMiddleware::class,
            ]
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
