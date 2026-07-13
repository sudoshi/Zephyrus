<?php

namespace App\Console\Commands;

use App\Integrations\Healthcare\Services\SourceActivationWindowService;
use Illuminate\Console\Command;

final class ExecuteScheduledSourceActivations extends Command
{
    protected $signature = 'integrations:execute-scheduled-activations
        {--limit=25 : Maximum activation windows to claim}
        {--lease-seconds=120 : Lease duration for each claimed window}
        {--owner= : Stable scheduler instance identifier}';

    protected $description = 'Claim and execute due, independently approved integration-source activation windows';

    public function handle(SourceActivationWindowService $windows): int
    {
        $owner = filled($this->option('owner'))
            ? (string) $this->option('owner')
            : sprintf('scheduler:%s:%d', gethostname() ?: 'unknown', getmypid() ?: 0);
        $result = $windows->runDue(
            $owner,
            (int) $this->option('limit'),
            (int) $this->option('lease-seconds'),
        );

        $this->components->info(sprintf(
            'Activation windows: %d expired, %d claimed, %d activated, %d failed.',
            $result['expired'],
            $result['claimed'],
            $result['activated'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
