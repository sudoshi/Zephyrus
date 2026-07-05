<?php

namespace App\Console\Commands;

use App\Services\Deployment\ServiceLineBackfiller;
use Illuminate\Console\Command;
use Throwable;

/**
 * Layer 4 backfill: derive facility_spaces.facility_key from the space_code prefix
 * (via hosp_org.facilities.cad_facility_code) and seed one primary
 * facility_space_service_lines bridge row per space that has a service line.
 *
 * Run after deployment:normalize-service-lines and before the FK-validate migration.
 * Idempotent — re-running inserts nothing new.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (§5, Phase 2)
 */
class DeploymentBackfillSpaceServiceLinesCommand extends Command
{
    protected $signature = 'deployment:backfill-space-service-lines';

    protected $description = 'Backfill facility_spaces.facility_key and one primary facility_space_service_lines bridge row per space.';

    public function handle(ServiceLineBackfiller $backfiller): int
    {
        try {
            $counts = $backfiller->backfill();
        } catch (Throwable $exception) {
            $this->error('Backfill failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Facility spaces backfilled.');
        $this->line('facility_key set:   '.$counts['facility_keys']);
        $this->line('bridge rows seeded: '.$counts['bridge_rows']);

        return self::SUCCESS;
    }
}
