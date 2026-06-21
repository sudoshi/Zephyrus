<?php

namespace App\Console\Commands;

use App\Rtdc\EventDispatcher;
use App\Rtdc\Simulator\SimulatorConfig;
use App\Rtdc\Simulator\SyntheticEventSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class RtdcDemoResetCommand extends Command
{
    protected $signature = 'rtdc:demo-reset {--seed=42}';

    protected $description = 'Reset to a deterministic RTDC demo state for E2E tests.';

    public function handle(EventDispatcher $dispatcher): int
    {
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\RtdcSeeder', '--force' => true]);

        $source = new SyntheticEventSource(SimulatorConfig::default(), seed: (int) $this->option('seed'));
        foreach ($source->pull() as $event) {
            $dispatcher->dispatch($event);
        }

        $this->info('RTDC demo state ready.');

        return self::SUCCESS;
    }
}
