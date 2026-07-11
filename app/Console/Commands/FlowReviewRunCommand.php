<?php

namespace App\Console\Commands;

use App\Domain\Arena\FlowReviewService;
use Illuminate\Console\Command;

/**
 * Zephyrus 2.0 — Part X / Flow Reconciliation. Builds the baseline 48-Hour Flow
 * Review artifact — the "scheduled" half of the trigger decision (the other half
 * is POST /api/arena/review/run at the huddle). No-ops when ARENA is off so the
 * scheduler can call it unconditionally, exactly like the Refresh*Arena jobs.
 */
class FlowReviewRunCommand extends Command
{
    protected $signature = 'arena:review:run
        {--window= : window end (ISO8601); defaults to now}';

    protected $description = 'Build & persist the 48-Hour Flow Review artifact (arena.reviews).';

    public function handle(FlowReviewService $service): int
    {
        if (! (bool) config('services.arena.enabled')) {
            $this->warn('ARENA_ENABLED is off — skipping Flow Review build.');

            return self::SUCCESS;
        }

        $window = $this->option('window') ?: null;
        $this->info('Building the 48-Hour Flow Review'.($window ? " for window ending {$window}" : '').' …');

        $result = $service->run($window);

        if (($result['available'] ?? false) !== true) {
            $this->error('Flow Review unavailable: '.($result['reason'] ?? 'unknown').'.');

            return self::FAILURE;
        }

        $stats = $result['stats'] ?? [];
        $this->table(['metric', 'count'], [
            ['open barriers', $stats['open_barriers'] ?? 0],
            ['new barriers', $stats['new_barriers'] ?? 0],
            ['worst hand-off', ($stats['worst_handoff']['label'] ?? '—').' · '.($stats['worst_handoff']['value_label'] ?? '—')],
            ['worst pathway', $stats['worst_pathway']['label'] ?? '—'],
        ]);

        $this->info('Flow Review built for '.($result['window']['label'] ?? 'window').'.');

        return self::SUCCESS;
    }
}
