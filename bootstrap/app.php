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
        // Zephyrus 2.0 P7 (WS-5): the heavy MTD Quality/Service/Financial
        // materialized views refresh CONCURRENTLY hourly — off the per-minute
        // snapshot path so the wall never waits on a full aggregate.
        $schedule->job(new \App\Jobs\RefreshCockpitMaterializedViews)->hourly()->withoutOverlapping();
        // Retention for the snapshot scalars the refresh job writes — the
        // writer and the pruner ship together (P1 execution notes).
        $schedule->job(new \App\Jobs\PruneCockpitMetricValues)->dailyAt('03:40');
        // Zephyrus 2.0 Part X (X0): the object-centric event log (OCEL 2.0)
        // incremental projection — read-side, additive, PHI-safe. Runs on the
        // SAME clock as the cockpit snapshot so A0 (cockpit) and A3 (Arena)
        // never disagree (Principle 7). Idempotent upserts, so the 2-day
        // trailing window overlapping the prior run is harmless.
        $schedule->job(new \App\Jobs\RefreshOcelLog)->everyFifteenMinutes()->withoutOverlapping();
        // Nightly full reconcile: re-project the trailing window and diff
        // projected counts against prod.*/flow_core.* source counts (§X.3.3).
        $schedule->command('ocel:project --days=90 --reconcile')->dailyAt('02:30');
        // Zephyrus 2.0 Part X (X3): recompute care-pathway conformance on its own
        // cadence and cache the rate for the cockpit. ARENA_ENABLED-gated (no-op
        // when off). Off the per-minute snapshot path — the heavy sidecar call
        // never blocks the wall.
        $schedule->job(new \App\Jobs\RefreshArenaConformance)->everyThirtyMinutes()->withoutOverlapping();
        // Zephyrus 2.0 Part X (X2): recompute object-centric performance (OPerA)
        // and cache the worst hand-off synchronization wait as a flow-domain
        // cockpit tile. Same ARENA_ENABLED gate + off-snapshot cadence discipline.
        $schedule->job(new \App\Jobs\RefreshArenaPerformance)->everyThirtyMinutes()->withoutOverlapping();
    })
    ->create();
