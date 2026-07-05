<?php

namespace App\Console\Commands;

use App\Models\Org\StaffAssignment;
use App\Models\Org\StaffImportRun;
use App\Models\Org\StaffingSource;
use App\Models\Org\StaffMember;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7: reports source <-> Zephyrus divergence for a staffing source and sweeps
 * terminations after a grace period. Departed = an active staff_member last seen more
 * than --grace days ago. A partial-pull guard skips the sweep when the most recent
 * import staged implausibly few people vs the prior run (a source gap, not real
 * departures). Soft-deactivates only — never a hard delete.
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§6 task 7, §13)
 */
class DeploymentStaffingDriftCommand extends Command
{
    protected $signature = 'deployment:staffing-drift
        {source : staffing_sources.source_key}
        {--grace=14 : days a member may be unseen before termination sweep}
        {--sweep : soft-deactivate departed members + their assignments}';

    protected $description = 'Report staffing drift (departed members) and optionally sweep terminations after grace.';

    public function handle(): int
    {
        $source = StaffingSource::where('source_key', $this->argument('source'))->first();
        if ($source === null) {
            $this->error("Unknown staffing source '{$this->argument('source')}'.");

            return self::FAILURE;
        }

        $grace = max(0, (int) $this->option('grace'));
        $cutoff = Carbon::now()->subDays($grace);

        $departed = StaffMember::query()
            ->where('source_system', $source->source_key)
            ->where('is_active', true)
            ->where('last_seen_at', '<', $cutoff)
            ->get();

        $this->info(sprintf(
            'Source %s: %d active staff, %d unseen > %d days (candidates for termination).',
            $source->source_key,
            StaffMember::where('source_system', $source->source_key)->where('is_active', true)->count(),
            $departed->count(),
            $grace,
        ));

        if ($departed->isNotEmpty()) {
            $this->table(
                ['staff_key', 'name', 'last_seen'],
                $departed->take(25)->map(fn (StaffMember $m): array => [
                    $m->staff_key,
                    $m->display_name ?? '—',
                    optional($m->last_seen_at)->toDateString() ?? '—',
                ])->all(),
            );
        }

        if (! $this->option('sweep')) {
            $this->line('Report only — pass --sweep to soft-deactivate after grace.');

            return self::SUCCESS;
        }

        if ($this->partialPullSuspected($source)) {
            $this->warn('Partial-pull guard tripped: the latest import staged far fewer people than the prior run. Skipping sweep.');

            return self::SUCCESS;
        }

        $swept = 0;
        foreach ($departed as $member) {
            DB::transaction(function () use ($member, &$swept): void {
                StaffAssignment::where('staff_member_id', $member->staff_member_id)
                    ->update(['is_active' => false, 'primary_flag' => false]);
                $member->update(['is_active' => false, 'employment_status' => 'terminated']);
                $swept++;
            });
        }

        $this->info("Swept {$swept} departed staff (soft-deactivated members + assignments).");

        return self::SUCCESS;
    }

    /**
     * True when the most recent run staged < 50% of the prior run's total — a likely
     * source gap rather than mass departures.
     */
    private function partialPullSuspected(StaffingSource $source): bool
    {
        $totals = StaffImportRun::query()
            ->where('staffing_source_id', $source->staffing_source_id)
            ->whereIn('status', ['resolved', 'committed'])
            ->orderByDesc('staff_import_run_id')
            ->limit(2)
            ->get()
            ->map(fn (StaffImportRun $r): int => (int) ($r->counts['total'] ?? 0))
            ->all();

        if (count($totals) < 2 || $totals[1] === 0) {
            return false;
        }

        return $totals[0] < ($totals[1] * 0.5);
    }
}
