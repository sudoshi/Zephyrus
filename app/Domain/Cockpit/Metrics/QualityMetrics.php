<?php

namespace App\Domain\Cockpit\Metrics;

use App\Domain\Cockpit\SnapshotContext;
use App\Services\Cockpit\MaterializedMetricsReader;
use App\Services\Cockpit\StatusEngine;
use App\Support\Cockpit\MetricValue;

/**
 * Quality & safety (spec §2.7). LIVE as of P7: every tile is the MTD value
 * from ops.mv_hai_ledger (over prod.quality_events), read through
 * MaterializedMetricsReader. A metric with no MV row this month yields a null
 * tile (skipped) rather than a fabricated demo number.
 */
class QualityMetrics extends BaseMetrics
{
    private const KEYS = [
        'quality.sepsis_3hr', 'quality.sepsis_6hr', 'quality.hand_hygiene',
        'quality.falls_rate', 'quality.rapid_response', 'quality.med_rec',
        'quality.clabsi', 'quality.cauti', 'quality.cdiff', 'quality.ssi',
        'quality.mrsa', 'quality.vap', 'quality.hapi',
    ];

    public function __construct(
        StatusEngine $engine,
        private readonly MaterializedMetricsReader $mv,
    ) {
        parent::__construct($engine);
    }

    public function domain(): string
    {
        return 'quality';
    }

    /** @return list<MetricValue> */
    public function metrics(SnapshotContext $ctx): array
    {
        return $this->compact(array_map(function (string $key) use ($ctx): ?MetricValue {
            $value = $this->mv->value($key);

            return $value === null ? null : $this->fromKey($ctx, $key, $value);
        }, self::KEYS));
    }
}
