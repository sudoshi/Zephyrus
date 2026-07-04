<?php

namespace App\Jobs;

use App\Services\Cockpit\SnapshotBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Recomputes the cockpit snapshot every minute (Zephyrus 2.0 P1) — the job
 * that turns /api/cockpit/snapshot into a pure cache lookup instead of a
 * 15+-query storm per /dashboard load. Scheduled everyMinute() with
 * withoutOverlapping in bootstrap/app.php.
 *
 * Single facility per decision D1 (HospitalManifest, no Facility model). The
 * build is guarded so a bad domain logs and skips rather than leaving the
 * previous snapshot torn — stale-but-whole beats fresh-but-blank, and the
 * frontend StaleDataBanner (P8) surfaces the age.
 */
class RefreshCockpitSnapshot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 110;

    public int $tries = 1;

    public function handle(SnapshotBuilder $builder): void
    {
        try {
            $builder->refresh();
        } catch (\Throwable $e) {
            Log::error('cockpit.snapshot.refresh_failed', ['error' => $e->getMessage()]);
        }
    }
}
