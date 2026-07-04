<?php

namespace App\Jobs;

use App\Services\Cockpit\MetricValueWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Daily retention prune for the cockpit's per-snapshot scalars — ships in
 * the same commit as the writer (plan P1 execution notes). Only
 * grain='snapshot' rows past the configured retention are deleted.
 */
class PruneCockpitMetricValues implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function handle(MetricValueWriter $writer): void
    {
        try {
            $deleted = $writer->prune();

            if ($deleted > 0) {
                Log::info('cockpit.metric_values.pruned', ['deleted' => $deleted]);
            }
        } catch (\Throwable $e) {
            Log::error('cockpit.metric_values.prune_failed', ['error' => $e->getMessage()]);
        }
    }
}
