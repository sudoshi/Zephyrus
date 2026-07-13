<?php

namespace App\Console\Commands;

use App\Services\Admin\SystemHealthService;
use Illuminate\Console\Command;
use Throwable;

final class ObserveSystemHealthCommand extends Command
{
    protected $signature = 'admin:observe-system-health';

    protected $description = 'Record a bounded, PHI-free system health observation batch';

    public function handle(SystemHealthService $health): int
    {
        try {
            $snapshot = $health->collect('scheduled');
        } catch (Throwable $exception) {
            report($exception);
            $this->error('System health observation failed; inspect structured application logs.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Recorded health batch %s (%s; %d required component(s) need attention).',
            $snapshot['batchUuid'],
            $snapshot['overallStatus'],
            $snapshot['counts']['requiredAttention'],
        ));

        return self::SUCCESS;
    }
}
