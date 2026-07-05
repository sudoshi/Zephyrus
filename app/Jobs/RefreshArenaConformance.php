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
 * Zephyrus 2.0 — Part X (X3 last mile). Recomputes care-pathway conformance on
 * its own cadence and caches the per-pathway rate into arena.conformance_signals,
 * keyed by the cockpit metric it feeds. The per-minute snapshot then reads a
 * cheap cached scalar (via MaterializedMetricsReader) — the heavy sidecar call
 * never sits on the snapshot path.
 *
 * Gated by ARENA_ENABLED: if the Arena is off, the job no-ops and the signals
 * table stays empty, so the conformance tiles simply don't appear (no regression
 * to the existing cockpit). Guarded: a sidecar failure leaves the last-good rate.
 */
class RefreshArenaConformance implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 280;

    public int $tries = 1;

    /** pathway key (sidecar) → cockpit metric key it feeds. */
    private const METRIC_KEYS = [
        'sepsis' => 'quality.sepsis_conformance',
        'surgical_safety' => 'quality.surgical_safety_conformance',
    ];

    public function handle(ArenaService $arena): void
    {
        if (! config('services.arena.enabled')) {
            return;
        }

        try {
            $result = $arena->conformance();
            if (($result['available'] ?? false) !== true) {
                return; // sidecar unreachable — leave the last-good rate
            }

            $now = now();
            foreach ($result['pathways'] ?? [] as $pathway) {
                $key = self::METRIC_KEYS[$pathway['pathway']] ?? null;
                $rate = $pathway['conformance_rate'] ?? null;
                if ($key === null || $rate === null) {
                    continue;
                }

                DB::table('arena.conformance_signals')->upsert([[
                    'metric_key' => $key,
                    'pathway' => $pathway['pathway'],
                    'value' => round($rate * 100, 2),
                    'cases' => (int) ($pathway['cases'] ?? 0),
                    'deviant' => (int) ($pathway['deviant'] ?? 0),
                    'deviations' => json_encode($pathway['deviations'] ?? []),
                    'computed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]], ['metric_key'], ['pathway', 'value', 'cases', 'deviant', 'deviations', 'computed_at', 'updated_at']);
            }
        } catch (\Throwable $e) {
            Log::error('arena.conformance.refresh_failed', ['error' => $e->getMessage()]);
        }
    }
}
