<?php

namespace App\Domain\Cockpit\Metrics;

use App\Domain\Cockpit\SnapshotContext;
use App\Support\Cockpit\MetricValue;

/**
 * Labor & financial stewardship (spec §2.9). ALL MOCK until productivity /
 * GL feeds exist (P7); identical contract, demo provenance (D5).
 */
class FinancialMetrics extends BaseMetrics
{
    private const KEYS = [
        'financial.worked_per_uos', 'financial.premium_pay',
        'financial.productivity', 'financial.cost_per_case',
        'financial.contract_labor', 'financial.overtime',
    ];

    public function domain(): string
    {
        return 'financial';
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
