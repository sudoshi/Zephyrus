<?php

namespace App\Jobs;

use App\Domain\Arena\ArenaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
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
            // per_case: the same run that feeds the cockpit rate also lands
            // every case verdict in arena.case_conformance — the 4D adherence
            // surface's cache (FLOW-4D plan §8 A2). One sidecar call, two sinks.
            $result = $arena->conformance(perCase: true);
            if (($result['available'] ?? false) !== true) {
                return; // sidecar unreachable — leave the last-good rate
            }

            $now = now();
            foreach ($result['pathways'] ?? [] as $pathway) {
                $this->storeCaseVerdicts($pathway, $now);

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

    /**
     * Land one pathway's per-case verdicts and prune rows for cases that left
     * the log window (a batch is authoritative for its pathway: anything this
     * run didn't re-assert is stale by definition).
     *
     * @param  array<string, mixed>  $pathway
     */
    private function storeCaseVerdicts(array $pathway, Carbon $now): void
    {
        $key = (string) ($pathway['pathway'] ?? '');
        $caseResults = $pathway['case_results'] ?? [];
        if ($key === '' || ! is_array($caseResults)) {
            return;
        }

        $batchOids = array_values(array_filter(array_map(
            fn (array $case): string => (string) ($case['case_id'] ?? ''),
            $caseResults,
        )));

        foreach (array_chunk($caseResults, 200) as $chunk) {
            DB::table('arena.case_conformance')->upsert(
                array_map(fn (array $case): array => [
                    'case_oid' => (string) $case['case_id'],
                    'pathway' => $key,
                    'pathway_version' => (int) ($pathway['version'] ?? 1),
                    'conformant' => (bool) ($case['conformant'] ?? false),
                    'deviations' => json_encode($case['deviations'] ?? []),
                    'activity_timeline' => json_encode($case['activity_timeline'] ?? (object) []),
                    'computed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk),
                ['case_oid', 'pathway'],
                ['pathway_version', 'conformant', 'deviations', 'activity_timeline', 'computed_at', 'updated_at'],
            );
        }

        // Batch authority: anything this run didn't re-assert left the log
        // window and is stale by definition. (Not a timestamp comparison —
        // timestampTz(0) columns tie within a second.)
        DB::table('arena.case_conformance')
            ->where('pathway', $key)
            ->whereNotIn('case_oid', $batchOids)
            ->delete();
    }
}
