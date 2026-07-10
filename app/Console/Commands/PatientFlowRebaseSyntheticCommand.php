<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Shift the fixed synthetic flow_core window so it ends at wall-clock now —
 * FLOW-WINDOW-PLAN §6.5 (W5, demo-data honesty / G1).
 *
 * The synthetic HL7 fixture spans a frozen window (2026-06-25 → 2026-06-28)
 * that drifts further from "now" every day. This command computes one
 * interval (anchor − max(occurred_at)) and applies it uniformly to every
 * flow_core timestamp family, so the web 4D twin and the mobile Flow Window
 * agree on what "the last 24 hours" means during a demo. Relative spacing
 * between events is untouched — the story replays identically, just ending
 * at the anchor.
 *
 * Idempotent: re-running with the same anchor shifts by ~0 (whatever time
 * elapsed since the previous run).
 */
class PatientFlowRebaseSyntheticCommand extends Command
{
    protected $signature = 'patient-flow:rebase-synthetic
        {--anchor=now : Timestamp the LAST synthetic event should land on (default: now)}
        {--dry-run : Report the shift without writing}';

    protected $description = 'Rebase the synthetic flow_core event window to end at the given anchor (default: now).';

    public function handle(): int
    {
        $maxOccurredAt = DB::table('flow_core.flow_events')->max('occurred_at');
        if ($maxOccurredAt === null) {
            $this->warn('flow_core.flow_events is empty — nothing to rebase. Run patient-flow:import-synthetic first.');

            return self::SUCCESS;
        }

        try {
            $anchor = CarbonImmutable::parse((string) $this->option('anchor'));
        } catch (\Throwable) {
            $this->error('Could not parse --anchor. Use ISO-8601 or "now".');

            return self::INVALID;
        }

        $latest = CarbonImmutable::parse($maxOccurredAt);
        $shiftSeconds = $latest->diffInSeconds($anchor, false);

        $days = intdiv(abs((int) $shiftSeconds), 86400);
        $this->info(sprintf(
            'Window currently ends %s; shifting %s%dd %s to end at %s.',
            $latest->toIso8601String(),
            $shiftSeconds < 0 ? '-' : '+',
            $days,
            gmdate('H:i:s', abs((int) $shiftSeconds) % 86400),
            $anchor->toIso8601String(),
        ));

        if ((int) $shiftSeconds === 0) {
            $this->info('Already anchored — no shift needed.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line('Dry run: no rows written.');

            return self::SUCCESS;
        }

        $interval = "{$shiftSeconds} seconds";

        DB::transaction(function () use ($interval): void {
            // Every timestamp family that participates in the replay story.
            $updated = DB::update('UPDATE flow_core.flow_events
                SET occurred_at = occurred_at + ?::interval,
                    recorded_at = recorded_at + ?::interval', [$interval, $interval]);
            $this->line("  flow_core.flow_events: {$updated} rows");

            $updated = DB::update('UPDATE flow_core.encounters
                SET started_at = started_at + ?::interval,
                    ended_at = ended_at + ?::interval', [$interval, $interval]);
            $this->line("  flow_core.encounters: {$updated} rows");

            $updated = DB::update('UPDATE flow_core.occupancy_snapshots
                SET snapshot_at = snapshot_at + ?::interval', [$interval]);
            $this->line("  flow_core.occupancy_snapshots: {$updated} rows");
        });

        $this->info('Rebase complete — the synthetic window now ends at the anchor.');

        return self::SUCCESS;
    }
}
