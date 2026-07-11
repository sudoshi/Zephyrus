<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retention for the rolling demo (plan §10.2 / FEEDBACK Wave 5).
 *
 * The 15-minute refresh keeps the operational window current but time-series checkpoints
 * (census / occupancy snapshots) and the refresh ledger accumulate. This prunes them to a
 * bounded window so the demo database does not grow without limit under an unattended
 * schedule. SELECT + DELETE only on demo-owned time series; gated on demo mode.
 *
 *   php artisan zephyrus:demo-prune            # nightly retention
 *   php artisan zephyrus:demo-prune --dry-run  # report what would be deleted
 */
class DemoPruneCommand extends Command
{
    protected $signature = 'zephyrus:demo-prune
        {--occupancy-days=14 : Keep flow_core.occupancy_snapshots newer than this}
        {--census-days=30 : Keep prod.census_snapshots newer than this}
        {--keep-runs=300 : Keep this many most-recent demo_refresh_runs ledger rows}
        {--dry-run : Report counts without deleting}
        {--force : Permit outside demo mode}';

    protected $description = 'Prune old demo time-series snapshots and refresh-ledger rows to a bounded retention window.';

    public function handle(): int
    {
        if (! (bool) config('demo.enabled') && ! (bool) $this->option('force')) {
            $this->error('Refusing to prune: demo mode is off (set DEMO_MODE=true) and --force was not given.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $occupancyDays = max(2, (int) $this->option('occupancy-days'));
        $censusDays = max(2, (int) $this->option('census-days'));
        $keepRuns = max(20, (int) $this->option('keep-runs'));

        $plan = [
            ['flow_core.occupancy_snapshots', 'snapshot_at', "now() - interval '{$occupancyDays} days'"],
            ['prod.census_snapshots', 'captured_at', "now() - interval '{$censusDays} days'"],
        ];

        $total = 0;
        foreach ($plan as [$table, $col, $cutoff]) {
            $n = (int) (DB::selectOne("SELECT count(*) AS c FROM {$table} WHERE {$col} < {$cutoff}")->c ?? 0);
            $total += $n;
            if (! $dry && $n > 0) {
                DB::table($table)->whereRaw("{$col} < {$cutoff}")->delete();
            }
            $this->line(sprintf('  %s %-32s %d rows older than cutoff', $dry ? 'would prune' : 'pruned', $table, $n));
        }

        // Ledger: keep the most-recent N runs.
        $keepIds = DB::table('ops.demo_refresh_runs')->orderByDesc('started_at')->limit($keepRuns)->pluck('refresh_id');
        $ledgerN = DB::table('ops.demo_refresh_runs')->whereNotIn('refresh_id', $keepIds)->count();
        $total += $ledgerN;
        if (! $dry && $ledgerN > 0) {
            DB::table('ops.demo_refresh_runs')->whereNotIn('refresh_id', $keepIds)->delete();
        }
        $this->line(sprintf('  %s %-32s %d rows beyond the %d most recent', $dry ? 'would prune' : 'pruned', 'ops.demo_refresh_runs', $ledgerN, $keepRuns));

        $this->info(($dry ? 'Dry run — ' : '')."{$total} rows".($dry ? ' would be pruned.' : ' pruned.'));

        return self::SUCCESS;
    }
}
