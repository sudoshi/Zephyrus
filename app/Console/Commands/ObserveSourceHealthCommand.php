<?php

namespace App\Console\Commands;

use App\Integrations\Healthcare\Services\SourceObservabilityService;
use Illuminate\Console\Command;
use Throwable;

final class ObserveSourceHealthCommand extends Command
{
    protected $signature = 'integrations:observe-source-health
        {--source-id=* : Restrict collection to exact source IDs}
        {--limit=100 : Maximum sources to observe in this batch}';

    protected $description = 'Record a bounded, PHI-free healthcare source health observation batch';

    public function handle(SourceObservabilityService $observability): int
    {
        $sourceIds = array_values(array_map('intval', (array) $this->option('source-id')));
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 1000 || collect($sourceIds)->contains(fn (int $id): bool => $id < 1)) {
            $this->error('Source health collection options are invalid.');

            return self::INVALID;
        }

        try {
            $result = $observability->collect('scheduled', $sourceIds, $limit);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Source health collection failed; inspect PHI-safe structured diagnostics.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Recorded source health batch %s (%d recorded, %d failed).',
            $result['batchUuid'],
            $result['recorded'],
            $result['failed'],
        ));

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
