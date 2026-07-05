<?php

namespace App\Console\Commands;

use App\Domain\Ocel\OcelProjector;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Zephyrus 2.0 — Part X (X0). Projects the OCEL 2.0 log into ocel.* from the live
 * event sources on `main`. Idempotent: re-running a window converges. Run
 * `--reconcile` to diff projected event counts against the source-of-truth row
 * counts (§X.3.3).
 */
class OcelProjectCommand extends Command
{
    protected $signature = 'ocel:project
        {--since= : window start (ISO8601 or "2026-05-01"); defaults to now - --days}
        {--until= : window end (ISO8601); defaults to now}
        {--days=90 : trailing window length when --since is absent}
        {--reconcile : also print projected-vs-source reconciliation}';

    protected $description = 'Project the object-centric event log (OCEL 2.0) into ocel.* from flow_core/prod sources.';

    public function handle(OcelProjector $projector): int
    {
        $until = $this->option('until') ? Carbon::parse($this->option('until')) : Carbon::now();
        $since = $this->option('since')
            ? Carbon::parse($this->option('since'))
            : (clone $until)->subDays((int) $this->option('days'));

        $this->info("Projecting OCEL log for [{$since->toDateString()} … {$until->toDateString()}] …");

        $result = $projector->project($since, $until);

        $this->table(['metric', 'count'], [
            ['events', $result['events']],
            ['objects', $result['objects']],
            ['E2O relationships', $result['e2o']],
            ['O2O relationships', $result['o2o']],
            ['object changes', $result['object_changes']],
        ]);

        $this->line('Source rows consumed:');
        foreach ($result['source_rows'] as $src => $n) {
            $this->line("  {$src}: {$n}");
        }

        if ($this->option('reconcile')) {
            $this->newLine();
            $this->line('Reconciliation (source rows → projected events):');
            $rows = [];
            foreach ($projector->reconcile($since, $until) as $r) {
                $rows[] = [$r['source'], $r['source_rows'], $r['distinct_source_refs'], $r['projected_events']];
            }
            $this->table(['source', 'source rows (window)', 'distinct source refs', 'projected events'], $rows);
        }

        $this->info('OCEL projection complete.');

        return self::SUCCESS;
    }
}
