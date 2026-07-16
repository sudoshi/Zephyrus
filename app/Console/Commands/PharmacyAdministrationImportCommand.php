<?php

namespace App\Console\Commands;

use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use App\Services\Pharmacy\PharmacyAdministrationImportService;
use Illuminate\Console\Command;
use JsonException;

class PharmacyAdministrationImportCommand extends Command
{
    protected $signature = 'ancillary:import-administrations
        {path : Path to a PharmacyAdministrationImport v1 JSON batch file}
        {--source= : Governed integration source key authorized for the RX_ADMIN_BATCH family}
        {--dry-run : Report the batch shape without ingesting anything}';

    protected $description = 'Ingest a warehouse/BCMA administration batch through the governed raw/canonical/projection pipeline with as-of cutoff stamping.';

    public function handle(PharmacyAdministrationImportService $importer): int
    {
        $sourceKey = trim((string) $this->option('source'));
        if ($sourceKey === '') {
            $this->components->error('A governed --source key is required.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('path');
        if (! is_file($path) || ! is_readable($path)) {
            $this->components->error("The administration batch file [{$path}] is not readable.");

            return self::FAILURE;
        }

        try {
            $batch = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->components->error("The administration batch file is not valid JSON: {$exception->getMessage()}");

            return self::FAILURE;
        }
        if (! is_array($batch)) {
            $this->components->error('The administration batch file must contain a JSON object.');

            return self::FAILURE;
        }

        $rows = is_array($batch['administrations'] ?? null) ? count($batch['administrations']) : 0;
        if ((bool) $this->option('dry-run')) {
            $this->components->info(sprintf(
                'Administration import dry run: extract [%s], cutoff [%s], %d row(s). Nothing was ingested.',
                (string) ($batch['extract_id'] ?? 'missing'),
                (string) ($batch['source_cutoff_at'] ?? 'missing'),
                $rows,
            ));

            return self::SUCCESS;
        }

        try {
            $summary = $importer->import($sourceKey, $batch);
        } catch (AncillaryIngestException $exception) {
            $this->components->error("Administration import refused: [{$exception->reasonCode}] {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Extract', (string) ($summary['extract_id'] ?? 'unknown'));
        $this->components->twoColumnDetail('Source cutoff', (string) ($summary['source_cutoff_at'] ?? 'unknown'));
        $this->components->twoColumnDetail('Rows', (string) $summary['rows']);
        $this->components->twoColumnDetail('Projected', (string) $summary['projected']);
        $this->components->twoColumnDetail('Duplicates (idempotent replays)', (string) $summary['duplicates']);
        $this->components->twoColumnDetail('Dead-lettered (reconciliation)', (string) $summary['dead_lettered']);
        foreach ($summary['reasons'] as $reason => $count) {
            $this->components->twoColumnDetail("  reason: {$reason}", (string) $count);
        }

        if ($summary['dead_lettered'] > 0) {
            $this->components->warn('Some rows entered the dead-letter reconciliation queue; review raw.dead_letters for the reason codes above.');

            return self::FAILURE;
        }

        $this->components->info('Administration batch ingested through raw/canonical/projection with provenance.');

        return self::SUCCESS;
    }
}
