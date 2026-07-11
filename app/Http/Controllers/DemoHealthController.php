<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rolling-demo health endpoint — GET /up/demo (plan §10.3 / FEEDBACK Wave 5).
 *
 * Reports the last refresh (from the ops.demo_refresh_runs ledger), current source freshness,
 * and whether the scheduler is alive — so an uptime monitor can alert on a stalled scheduler,
 * a failed refresh, or stale decision-critical data. Returns HTTP 200 when healthy, 503 when
 * not (no PHI; safe to expose to a monitor). Reads the ledger's cached invariant summary rather
 * than re-running the invariants, so the check is cheap.
 */
class DemoHealthController extends Controller
{
    /** Two missed 15-minute cycles → the scheduler is considered stalled. */
    private const SCHEDULER_STALE_SECONDS = 1800;

    private const DECISION_CRITICAL = ['ed_flow', 'encounters', 'capacity_census', 'bed_placement', 'rtdc_predictions'];

    public function __invoke(): JsonResponse
    {
        $run = DB::table('ops.demo_refresh_runs')->orderByDesc('started_at')->first();
        $sources = DB::table('ops.source_freshness')->orderBy('source_key')->get();

        $staleDecision = $sources
            ->whereIn('source_key', self::DECISION_CRITICAL)
            ->where('status', 'critical')
            ->pluck('source_key')->values();

        $inv = $run && $run->invariant_results ? (array) json_decode((string) $run->invariant_results, true) : [];
        $criticalFailed = (int) ($inv['criticalFailed'] ?? 0);

        // Clamp to >= 0: a refresh timestamp slightly in the "future" (clock skew between the app
        // timezone and the DB session) reads as age 0 (fresh), never a negative age.
        $refreshAge = $run && $run->completed_at
            ? max(0, now()->getTimestamp() - Carbon::parse($run->completed_at)->getTimestamp())
            : null;
        $schedulerStale = $refreshAge === null || $refreshAge > self::SCHEDULER_STALE_SECONDS;

        $ok = $run !== null
            && $run->status === 'passed'
            && $criticalFailed === 0
            && $staleDecision->isEmpty()
            && ! $schedulerStale;

        return response()->json([
            'ok' => $ok,
            'scenario' => config('demo.scenario'),
            'demoMode' => (bool) config('demo.enabled'),
            'schedulerStale' => $schedulerStale,
            'lastRefresh' => $run ? [
                'refreshId' => $run->refresh_id,
                'status' => $run->status,
                'anchorAt' => $run->anchor_at,
                'completedAt' => $run->completed_at,
                'ageSeconds' => $refreshAge,
                'criticalFailed' => $criticalFailed,
                'warningFailed' => (int) ($inv['warningFailed'] ?? 0),
            ] : null,
            'staleDecisionSources' => $staleDecision,
            'freshness' => $sources->map(fn ($s): array => [
                'sourceKey' => $s->source_key,
                'status' => $s->status,
                'latestObservedAt' => $s->latest_observed_at,
            ])->values(),
        ], $ok ? 200 : 503);
    }
}
