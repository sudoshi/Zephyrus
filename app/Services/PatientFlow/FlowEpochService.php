<?php

namespace App\Services\PatientFlow;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dataset epoch for the Patient Flow surfaces — Codex HFE audit F-6 (part 2),
 * FLOW-4D-PATIENT-JOURNEY-AND-CONFORMANCE-PLAN §8 Phase A3 (DI-1).
 *
 * The 6h demo refresh rebases every operational timestamp in place
 * (DemoRefreshCoordinator), so a wall client that bootstrapped before the
 * refresh is rendering a dataset that no longer exists. The coordinator
 * already writes an `ops.demo_refresh_runs` ledger row per attempt; this
 * service exposes the newest TERMINAL row as the dataset epoch. A `failed`
 * run still advances the epoch on purpose — the rebase mutates data before
 * the invariant gate runs, so failure does not mean "nothing changed".
 *
 * On deployments without the ledger (or before the first refresh) the epoch
 * is null and every client-side epoch comparison is inert by design.
 */
class FlowEpochService
{
    /** @return array{epoch: string, refreshed_at: ?string, status: string}|null */
    public function current(): ?array
    {
        if (! Schema::hasTable('ops.demo_refresh_runs')) {
            return null;
        }

        $row = DB::table('ops.demo_refresh_runs')
            ->whereNotNull('completed_at')
            ->orderByDesc('completed_at')
            ->first(['refresh_id', 'completed_at', 'status']);

        if ($row === null) {
            return null;
        }

        return [
            'epoch' => (string) $row->refresh_id,
            'refreshed_at' => $row->completed_at
                ? CarbonImmutable::parse((string) $row->completed_at)->toJSON()
                : null,
            'status' => (string) $row->status,
        ];
    }
}
