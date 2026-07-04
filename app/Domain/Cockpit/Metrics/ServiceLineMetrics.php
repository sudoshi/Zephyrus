<?php

namespace App\Domain\Cockpit\Metrics;

use App\Domain\Cockpit\SnapshotContext;
use App\Support\Cockpit\MetricValue;

/**
 * Service lines / throughput (spec §2.8). PARTIAL-LIVE: O:E LOS, 30-day
 * readmission and avoidable days are the legacy outcomes-band values
 * (identical to /dashboard); CMI, observation rate and discharges-MTD are
 * demo until the DRG/financial feeds land (P7).
 */
class ServiceLineMetrics extends BaseMetrics
{
    public function domain(): string
    {
        return 'service';
    }

    /** @return list<MetricValue> */
    public function metrics(SnapshotContext $ctx): array
    {
        return $this->compact([
            $this->fromLegacy($ctx, 'service.oe_los', 'los_gmlos'),
            $this->fromLegacy($ctx, 'service.readmit_30d', 'readmission'),
            $this->fromLegacy($ctx, 'service.avoidable_days', 'excess_days'),
            $this->demo($ctx, 'service.cmi'),
            $this->demo($ctx, 'service.observation_rate'),
            $this->demo($ctx, 'service.discharges_mtd'),
        ]);
    }
}
