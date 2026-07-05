<?php

namespace App\Console\Commands;

use App\Services\Deployment\ServiceLineNormalizer;
use App\Services\Deployment\ServiceLineRegistrar;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Projects the config-authored service-line registry + taxonomy vocabulary into
 * hosp_ref.* (Layer 2). Idempotent — safe to run repeatedly.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 0)
 */
class DeploymentSeedRegistryCommand extends Command
{
    protected $signature = 'deployment:seed-registry';

    protected $description = 'Seed hosp_ref service lines, programs, capability tags, and taxonomy vocab from config/hospital/*.';

    public function handle(ServiceLineRegistrar $registrar): int
    {
        try {
            $counts = $registrar->seed();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Registry seed failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        // Refresh the normalizer cache so subsequent same-process consumers see the new registry.
        ServiceLineNormalizer::flush();

        $this->info('Service-line registry seeded.');
        $this->line('Vocab lookups:   '.$counts['vocab']);
        $this->line('Service lines:   '.$counts['service_lines']);
        $this->line('Programs:        '.$counts['programs']);
        $this->line('Capability tags: '.$counts['capability_tags']);

        return self::SUCCESS;
    }
}
