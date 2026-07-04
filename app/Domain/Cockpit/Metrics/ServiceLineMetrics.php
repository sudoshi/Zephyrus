<?php

namespace App\Domain\Cockpit\Metrics;

use App\Domain\Cockpit\SnapshotContext;
use App\Services\Cockpit\MaterializedMetricsReader;
use App\Services\Cockpit\StatusEngine;
use App\Support\Cockpit\MetricValue;

/**
 * Service lines / throughput (spec §2.8). LIVE as of P7: O:E LOS, 30-day
 * readmission and avoidable days are the legacy outcomes-band values
 * (identical to /dashboard); CMI, observation rate and discharges-MTD come
 * from ops.mv_service_line_los (over prod.encounters DRG/observation columns).
 */
class ServiceLineMetrics extends BaseMetrics
{
    public function __construct(
        StatusEngine $engine,
        private readonly MaterializedMetricsReader $mv,
    ) {
        parent::__construct($engine);
    }

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
            $this->fromMv($ctx, 'service.cmi'),
            $this->fromMv($ctx, 'service.observation_rate'),
            $this->fromMv($ctx, 'service.discharges_mtd'),
        ]);
    }

    private function fromMv(SnapshotContext $ctx, string $key): ?MetricValue
    {
        $value = $this->mv->value($key);

        return $value === null ? null : $this->fromKey($ctx, $key, $value);
    }
}
