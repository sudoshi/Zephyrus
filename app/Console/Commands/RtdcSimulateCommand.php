<?php

namespace App\Console\Commands;

use App\Rtdc\EventDispatcher;
use App\Rtdc\Simulator\SimulatorConfig;
use App\Rtdc\Simulator\SyntheticEventSource;
use Illuminate\Console\Command;

class RtdcSimulateCommand extends Command
{
    protected $signature = 'rtdc:simulate {--seed=0} {--ticks=24}
        {--trailing : anchor the tick stream to end at now (trailing window) instead of starting at 06:00 today}';

    protected $description = 'Drive the live census with a synthetic operational event stream.';

    public function handle(EventDispatcher $dispatcher): int
    {
        $ticks = (int) $this->option('ticks');
        $config = new SimulatorConfig(
            initialOccupancyPercent: 70,
            admitsPerTick: 3,
            dischargesPerTick: 2,
            ticks: $ticks,
        );

        // --trailing emits a plausible trailing window of prod.operational_events
        // ending at now (FLOW-WINDOW-PLAN §6.5 W5), so the Flow Window's review
        // half has a real story to replay against a freshly seeded demo DB.
        $startAt = $this->option('trailing')
            ? now()->subHours($ticks)
            : null;

        $source = new SyntheticEventSource($config, seed: (int) $this->option('seed'), startAt: $startAt);

        $n = 0;
        foreach ($source->pull() as $event) {
            $dispatcher->dispatch($event);
            $n++;
        }

        $this->info("Dispatched {$n} canonical events.");

        return self::SUCCESS;
    }
}
