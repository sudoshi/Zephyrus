<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Zephyrus 2.0 P7 (WS-5) — refreshes the three MTD cockpit materialized views
 * hourly. Uses REFRESH MATERIALIZED VIEW CONCURRENTLY (each MV carries a
 * unique index) so the wall keeps serving the last-good rows while the refresh
 * runs — no read lock, no blank tiles. CONCURRENTLY cannot run inside a
 * transaction, so each statement runs on its own; a failure on one view logs
 * and moves on rather than tearing the whole set.
 *
 * Scheduled hourly()->withoutOverlapping() in bootstrap/app.php, mirroring the
 * RefreshCockpitSnapshot per-minute job.
 */
class RefreshCockpitMaterializedViews implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public const VIEWS = [
        'ops.mv_hai_ledger',
        'ops.mv_service_line_los',
        'ops.mv_cost_center_productivity',
    ];

    public function handle(): void
    {
        foreach (self::VIEWS as $view) {
            try {
                DB::statement("REFRESH MATERIALIZED VIEW CONCURRENTLY {$view}");
            } catch (\Throwable $e) {
                // A never-populated MV can't refresh CONCURRENTLY; fall back to
                // a plain (locking) refresh so it becomes eligible next hour.
                try {
                    DB::statement("REFRESH MATERIALIZED VIEW {$view}");
                } catch (\Throwable $inner) {
                    Log::warning('cockpit.mv.refresh_failed', ['view' => $view, 'error' => $inner->getMessage()]);
                }
            }
        }
    }
}
