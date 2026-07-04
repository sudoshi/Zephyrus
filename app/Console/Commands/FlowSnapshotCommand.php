<?php

namespace App\Console\Commands;

use App\Services\Flow\TimelineSnapshotService;
use Illuminate\Console\Command;

/**
 * Hourly Flow Window checkpoints (FLOW-WINDOW-PLAN §6.2 W2). Scheduled
 * hourly in bootstrap/app.php; run with --backfill=24h after seeding a
 * demo database so the review half of the window has history on day one.
 */
class FlowSnapshotCommand extends Command
{
    protected $signature = 'flow:snapshot {--backfill= : also rebuild trailing checkpoints from event replay, e.g. --backfill=24h}';

    protected $description = 'Write hourly per-unit census + per-space occupancy checkpoints for the Flow Window.';

    public function handle(TimelineSnapshotService $snapshots): int
    {
        if (($backfill = $this->option('backfill')) !== null) {
            $hours = (int) rtrim((string) $backfill, 'hH');
            if ($hours < 1 || $hours > 168) {
                $this->error('--backfill expects 1h…168h.');

                return self::INVALID;
            }

            $written = $snapshots->backfill($hours);
            $this->info("Backfilled {$written} unit-hour checkpoints over the trailing {$hours}h.");
        }

        $written = $snapshots->capture();
        $this->info("Captured {$written} unit checkpoints for ".now()->startOfHour()->toDateTimeString().'.');

        return self::SUCCESS;
    }
}
