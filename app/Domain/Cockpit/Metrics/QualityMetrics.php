<?php

namespace App\Domain\Cockpit\Metrics;

use App\Domain\Cockpit\SnapshotContext;
use App\Support\Cockpit\MetricValue;

/**
 * Quality & safety (spec §2.7). ALL MOCK until the HAI/sepsis fact tables
 * land (P7) — every tile carries metadata.provenance='demo' behind the
 * identical MetricValue contract, so the P7 swap changes no shapes (D5).
 */
class QualityMetrics extends BaseMetrics
{
    private const KEYS = [
        'quality.sepsis_3hr', 'quality.sepsis_6hr', 'quality.hand_hygiene',
        'quality.falls_rate', 'quality.rapid_response', 'quality.med_rec',
        'quality.clabsi', 'quality.cauti', 'quality.cdiff', 'quality.ssi',
        'quality.mrsa', 'quality.vap', 'quality.hapi',
    ];

    public function domain(): string
    {
        return 'quality';
    }

    /** @return list<MetricValue> */
    public function metrics(SnapshotContext $ctx): array
    {
        return $this->compact(array_map(
            fn (string $key): ?MetricValue => $this->demo($ctx, $key),
            self::KEYS,
        ));
    }
}
