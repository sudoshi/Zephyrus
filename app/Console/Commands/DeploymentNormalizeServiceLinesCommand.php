<?php

namespace App\Console\Commands;

use App\Services\Deployment\ServiceLineBackfiller;
use Illuminate\Console\Command;
use Throwable;

/**
 * One-time (idempotent) backfill that folds legacy service-line codes to canonical
 * across the three free-text stores, so the facility_spaces service-line FK can be
 * validated with zero violations. Only synthetic Summit data is affected.
 *
 * Run before 2026_07_04_000160 (FK validate). Pair with deployment:backfill-space-service-lines.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (§5, Phase 2)
 */
class DeploymentNormalizeServiceLinesCommand extends Command
{
    protected $signature = 'deployment:normalize-service-lines';

    protected $description = 'Fold legacy service-line codes to canonical across facility_spaces, flow_events, and occupancy_snapshots.';

    public function handle(ServiceLineBackfiller $backfiller): int
    {
        try {
            $counts = $backfiller->normalize();
        } catch (Throwable $exception) {
            $this->error('Normalization failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Service-line codes normalized to canonical.');
        $this->line('facility_spaces rows:      '.$counts['facility_spaces']);
        $this->line('flow_events rows:          '.$counts['flow_events']);
        $this->line('occupancy_snapshots rows:  '.$counts['occupancy_snapshots']);

        return self::SUCCESS;
    }
}
