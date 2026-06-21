<?php

namespace App\Console\Commands;

use App\Rtdc\EventDispatcher;
use App\Rtdc\Simulator\SimulatorConfig;
use App\Rtdc\Simulator\SyntheticEventSource;
use Illuminate\Console\Command;

class RtdcSimulateCommand extends Command
{
    protected $signature = 'rtdc:simulate {--seed=0} {--ticks=24}';

    protected $description = 'Drive the live census with a synthetic operational event stream.';

    public function handle(EventDispatcher $dispatcher): int
    {
        $config = new SimulatorConfig(
            initialOccupancyPercent: 70,
            admitsPerTick: 3,
            dischargesPerTick: 2,
            ticks: (int) $this->option('ticks'),
        );
        $source = new SyntheticEventSource($config, seed: (int) $this->option('seed'));

        $n = 0;
        foreach ($source->pull() as $event) {
            $dispatcher->dispatch($event);
            $n++;
        }

        $this->info("Dispatched {$n} canonical events.");

        return self::SUCCESS;
    }
}
