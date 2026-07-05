<?php

namespace App\Console\Commands;

use App\Services\Deployment\DeploymentFacilityImporter;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

/**
 * Import an IDN facility roster (organization + markets + facilities) into hosp_org.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 1)
 */
class DeploymentImportFacilitiesCommand extends Command
{
    protected $signature = 'deployment:import-facilities {path : Path to a facility roster JSON file}';

    protected $description = 'Import organizations, markets, and facilities (Layer 1 IDN geography) into hosp_org.';

    public function handle(DeploymentFacilityImporter $importer): int
    {
        try {
            $summary = $importer->importFile((string) $this->argument('path'));
        } catch (JsonException $exception) {
            $this->error('Facility roster JSON is invalid: '.$exception->getMessage());

            return self::FAILURE;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Facility roster imported.');
        $this->line('Organizations: '.$summary['organizations']);
        $this->line('Markets: '.$summary['markets']);
        $this->line('Facilities: '.$summary['facilities']);

        return self::SUCCESS;
    }
}
