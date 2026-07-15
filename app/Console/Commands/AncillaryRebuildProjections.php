<?php

namespace App\Console\Commands;

use App\Models\Ancillary\AncillaryOrder;
use App\Services\Ancillary\AncillaryProjectionRebuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class AncillaryRebuildProjections extends Command
{
    protected $signature = 'ancillary:rebuild-projections
        {--order-id=* : One or more ancillary_order_id values}
        {--from= : Restrict to orders at or after this ordered_at timestamp}
        {--to= : Restrict to orders before this ordered_at timestamp}
        {--dry-run : Report the bounded scope without mutating projections}';

    protected $description = 'Rebuild ancillary current-order projections from the append-only selected-assertion ledger.';

    public function handle(AncillaryProjectionRebuilder $rebuilder): int
    {
        $query = AncillaryOrder::query()->orderBy('ancillary_order_id');
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->option('order-id')))));
        if ($ids !== []) {
            $query->whereIn('ancillary_order_id', $ids);
        }
        if (filled($this->option('from'))) {
            $query->where('ordered_at', '>=', CarbonImmutable::parse((string) $this->option('from')));
        }
        if (filled($this->option('to'))) {
            $query->where('ordered_at', '<', CarbonImmutable::parse((string) $this->option('to')));
        }

        $count = (clone $query)->count();
        if ((bool) $this->option('dry-run')) {
            $this->components->info("Ancillary projection rebuild dry run: {$count} order(s) selected.");

            return self::SUCCESS;
        }

        $rebuilt = 0;
        $query->chunkById(250, function ($orders) use ($rebuilder, &$rebuilt): void {
            foreach ($orders as $order) {
                $rebuilder->rebuild($order);
                $rebuilt++;
            }
        }, 'ancillary_order_id');

        $this->components->info("Ancillary projection rebuild complete: {$rebuilt} order(s) rebuilt.");

        return self::SUCCESS;
    }
}
