<?php

namespace App\Console\Commands;

use App\Services\Ops\OperationsGraphProjector;
use Illuminate\Console\Command;
use Throwable;

/**
 * Rebuild the ops.* operational graph from the prod tables plus the hosp_org IDN
 * geography + transfer graph (Phase 4). Idempotent full rebuild.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 4)
 */
class OpsGraphRebuildCommand extends Command
{
    protected $signature = 'ops:graph-rebuild';

    protected $description = 'Rebuild the ops operational graph (nodes + edges) from prod and hosp_org.';

    public function handle(OperationsGraphProjector $projector): int
    {
        try {
            $snapshot = $projector->rebuild();
        } catch (Throwable $exception) {
            $this->error('Graph rebuild failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Operations graph rebuilt.');
        $this->line('Nodes: '.$snapshot->node_count);
        $this->line('Edges: '.$snapshot->edge_count);

        return self::SUCCESS;
    }
}
