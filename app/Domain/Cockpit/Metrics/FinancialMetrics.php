<?php

namespace App\Domain\Cockpit\Metrics;

use App\Domain\Cockpit\SnapshotContext;
use App\Services\Cockpit\MaterializedMetricsReader;
use App\Services\Cockpit\StatusEngine;
use App\Services\Staffing\WorkforceActualsService;
use App\Support\Cockpit\MetricValue;

/**
 * Labor & financial stewardship (spec §2.9). LIVE as of P7. The MTD ratios
 * (worked/UOS, productivity, overtime%, cost/case) come from
 * ops.mv_cost_center_productivity; premium pay and contract-labor dollars are
 * TODAY figures from WorkforceActualsService — same workforce fact, two
 * altitudes, so the staffing wall and the financial ledger agree on hours.
 */
class FinancialMetrics extends BaseMetrics
{
    private const MV_KEYS = [
        'financial.worked_per_uos', 'financial.productivity',
        'financial.overtime', 'financial.cost_per_case',
    ];

    public function __construct(
        StatusEngine $engine,
        private readonly MaterializedMetricsReader $mv,
        private readonly WorkforceActualsService $workforce,
    ) {
        parent::__construct($engine);
    }

    public function domain(): string
    {
        return 'financial';
    }

    /** @return list<MetricValue> */
    public function metrics(SnapshotContext $ctx): array
    {
        $today = $this->today();

        $tiles = array_map(function (string $key) use ($ctx): ?MetricValue {
            $value = $this->mv->value($key);

            return $value === null ? null : $this->fromKey($ctx, $key, $value);
        }, self::MV_KEYS);

        // $k today, from the same workforce fact as the staffing tiles.
        $tiles[] = $today['premium_cost'] === null
            ? null
            : $this->fromKey($ctx, 'financial.premium_pay', round($today['premium_cost'] / 1000, 1));
        $tiles[] = $today['agency_cost'] === null
            ? null
            : $this->fromKey($ctx, 'financial.contract_labor', round($today['agency_cost'] / 1000, 1));

        return $this->compact($tiles);
    }

    /** @return array<string, mixed> */
    private function today(): array
    {
        try {
            return $this->workforce->todaySummary();
        } catch (\Throwable) {
            return ['premium_cost' => null, 'agency_cost' => null];
        }
    }
}
