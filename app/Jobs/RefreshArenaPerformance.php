<?php

namespace App\Jobs;

use App\Domain\Arena\ArenaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Zephyrus 2.0 — Part X (X2 cockpit promotion). Recomputes object-centric
 * performance (OPerA) on its own cadence and caches the flagship bottleneck
 * signal — the worst object-side wait at a shared hand-off, the synchronization
 * constraint — into arena.performance_signals, keyed by the cockpit metric it
 * feeds. The per-minute snapshot then reads a cheap cached scalar (via
 * MaterializedMetricsReader) — the heavy sidecar call never sits on the snapshot
 * path (the same discipline as RefreshArenaConformance).
 *
 * Gated by ARENA_ENABLED: if the Arena is off, the job no-ops and the signals
 * table stays empty, so the bottleneck tile simply doesn't appear (no regression
 * to the existing cockpit). Guarded: a sidecar failure leaves the last-good value.
 */
class RefreshArenaPerformance implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 280;

    public int $tries = 1;

    /** The cockpit metric this job feeds (the flow-domain bottleneck tile). */
    private const METRIC_KEY = 'flow.worst_handoff_wait';

    /**
     * Ignore synchronization rows thinner than this — a one- or two-case wait is
     * an anecdote, not a standing constraint. Keeps a single outlier from setting
     * the house bottleneck.
     */
    private const MIN_CASES = 3;

    public function handle(ArenaService $arena): void
    {
        if (! config('services.arena.enabled')) {
            return;
        }

        try {
            $result = $arena->performance();
            if (($result['available'] ?? false) !== true) {
                return; // sidecar unreachable — leave the last-good value
            }

            $sync = collect($result['synchronization'] ?? [])
                ->filter(fn (array $row): bool => (int) ($row['count'] ?? 0) >= self::MIN_CASES)
                ->sortByDesc(fn (array $row): float => (float) ($row['median_wait_sec'] ?? 0));

            $worst = $sync->first();
            if ($worst === null) {
                return; // nothing material to report — keep the last-good value
            }

            $now = now();
            $evidence = $sync->take(5)->map(fn (array $row): array => [
                'activity' => $row['activity'] ?? null,
                'object_type' => $row['object_type'] ?? null,
                'wait_min' => round(((float) ($row['median_wait_sec'] ?? 0)) / 60, 1),
                'count' => (int) ($row['count'] ?? 0),
            ])->values()->all();

            DB::table('arena.performance_signals')->upsert([[
                'metric_key' => self::METRIC_KEY,
                'value' => round(((float) $worst['median_wait_sec']) / 60, 2),
                'context' => trim(($worst['object_type'] ?? 'object').' at '.($worst['activity'] ?? 'hand-off')),
                'evidence' => json_encode($evidence),
                'computed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['metric_key'], ['value', 'context', 'evidence', 'computed_at', 'updated_at']);
        } catch (\Throwable $e) {
            Log::error('arena.performance.refresh_failed', ['error' => $e->getMessage()]);
        }
    }
}
