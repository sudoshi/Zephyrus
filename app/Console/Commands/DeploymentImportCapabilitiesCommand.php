<?php

namespace App\Console\Commands;

use App\Services\Deployment\DeploymentCapabilityImporter;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

/**
 * Import a facility capability matrix + interfacility transfer relationships into
 * hosp_org. Service-line codes are normalized to canonical before write.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 1)
 */
class DeploymentImportCapabilitiesCommand extends Command
{
    protected $signature = 'deployment:import-capabilities {path : Path to a capability + transfers JSON file}';

    protected $description = 'Import facility_service_capabilities and transfer_relationships (Layer 3 + transfer graph) into hosp_org.';

    public function handle(DeploymentCapabilityImporter $importer): int
    {
        try {
            $summary = $importer->importFile((string) $this->argument('path'));
        } catch (JsonException $exception) {
            $this->error('Capability JSON is invalid: '.$exception->getMessage());

            return self::FAILURE;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Capability matrix imported.');
        $this->line('Capabilities: '.$summary['capabilities']);
        $this->line('Transfer relationships: '.$summary['transfers']);

        if ($summary['skipped'] !== []) {
            $this->warn('Skipped '.count($summary['skipped']).' row(s):');
            foreach ($summary['skipped'] as $reason) {
                $this->line('  - '.$reason);
            }
        }

        return self::SUCCESS;
    }
}
