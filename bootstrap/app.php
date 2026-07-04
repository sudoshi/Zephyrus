<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$builder = Application::configure(basePath: dirname(__DIR__));

if (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null) === 'testing') {
    $builder->create()->dontMergeFrameworkConfiguration();
}

return $builder
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        $middleware->web(
            append: [
                \App\Http\Middleware\HandleInertiaRequests::class,
                \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            ]
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->job(new \App\Jobs\ReconcileRtdcPredictions)->dailyAt('02:00');
        // Flow Window hourly checkpoints (census + per-space occupancy) — W2.
        $schedule->command('flow:snapshot')->hourly();
        // Zephyrus 2.0 P1: the ONE server-computed cockpit snapshot —
        // /api/cockpit/snapshot becomes a pure cache lookup. Requires a
        // running queue worker + schedule runner in prod.
        $schedule->job(new \App\Jobs\RefreshCockpitSnapshot)->everyMinute()->withoutOverlapping();
        // Retention for the snapshot scalars the refresh job writes — the
        // writer and the pruner ship together (P1 execution notes).
        $schedule->job(new \App\Jobs\PruneCockpitMetricValues)->dailyAt('03:40');
    })
    ->create();
