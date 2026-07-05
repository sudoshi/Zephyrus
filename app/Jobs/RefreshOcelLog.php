<?php

namespace App\Jobs;

use App\Domain\Ocel\OcelProjector;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Zephyrus 2.0 — Part X (X0). Incrementally refreshes the OCEL log on the same
 * scheduler that drives the cockpit snapshot, so the object-centric log and the
 * cockpit are derived from one clock (Principle 7, §X.3.3). Projects a short
 * trailing window (idempotent upserts, so overlap with the previous run is
 * harmless and catches late-arriving rows). Guarded: a failure logs and leaves
 * the previous log whole rather than tearing it.
 *
 * Registered everyFifteenMinutes()->withoutOverlapping() in bootstrap/app.php,
 * beside RefreshCockpitSnapshot. Requires a running queue worker + schedule
 * runner in prod (same prerequisite the cockpit snapshot already carries).
 */
class RefreshOcelLog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 280;

    public int $tries = 1;

    /** Trailing window (days) each incremental refresh re-projects. */
    public function __construct(public int $windowDays = 2) {}

    public function handle(OcelProjector $projector): void
    {
        try {
            $result = $projector->project(Carbon::now()->subDays($this->windowDays), Carbon::now());
            Log::info('ocel.refresh.ok', [
                'events' => $result['events'],
                'objects' => $result['objects'],
                'window_days' => $this->windowDays,
            ]);
        } catch (\Throwable $e) {
            Log::error('ocel.refresh.failed', ['error' => $e->getMessage()]);
        }
    }
}
