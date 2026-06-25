<?php

namespace App\Console\Commands;

use App\Services\Facility\ModelCatalogImporter;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

class FacilityImportCatalogCommand extends Command
{
    protected $signature = 'facility:import-catalog
        {path : Path to a hospital model_catalog.json file}
        {--facility-code=ZEPHYRUS-500 : Stable facility code used to prefix canonical space codes}
        {--facility-name=500-Bed Level I Trauma Academic Medical Center : Facility display name}
        {--source-name= : Optional source name; defaults to the file name}
        {--map-operational : Create or attach prod.units/prod.beds and operational mapping rows}';

    protected $description = 'Import a generated hospital model catalog into the facility blueprint data model.';

    public function handle(ModelCatalogImporter $importer): int
    {
        try {
            $summary = $importer->import((string) $this->argument('path'), [
                'facility_code' => (string) $this->option('facility-code'),
                'facility_name' => (string) $this->option('facility-name'),
                'source_name' => $this->option('source-name') ?: null,
                'map_operational' => (bool) $this->option('map-operational'),
            ]);
        } catch (JsonException $exception) {
            $this->error('Catalog JSON is invalid: '.$exception->getMessage());

            return self::FAILURE;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Facility catalog imported.');
        $this->line('Import ID: '.$summary['import_id']);
        $this->line('Checksum: '.$summary['checksum']);
        $this->line('Objects: '.$summary['objects']);
        $this->line('Facility spaces: '.$summary['spaces']);
        $this->line('Operational units created: '.$summary['units']);
        $this->line('Operational beds created: '.$summary['beds']);
        $this->line('Operational maps created: '.$summary['maps']);

        if ($summary['conflicts'] > 0) {
            $this->warn('Mapping conflicts skipped: '.$summary['conflicts']);
        }

        return self::SUCCESS;
    }
}
