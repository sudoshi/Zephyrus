<?php

namespace App\Domain\Cockpit\Metrics;

use App\Domain\Cockpit\SnapshotContext;
use App\Services\Cockpit\StatusEngine;
use App\Services\Staffing\StaffingOperationsService;
use App\Support\Cockpit\MetricValue;

/**
 * Staffing & workforce (spec §2.5). Open shifts are LIVE via
 * StaffingOperationsService::overview(); OT / agency / callouts / sitters /
 * productivity are seeded demo values until time-and-attendance lands (P7).
 */
class StaffingMetrics extends BaseMetrics
{
    public function __construct(
        StatusEngine $engine,
        private readonly StaffingOperationsService $staffing,
    ) {
        parent::__construct($engine);
    }

    public function domain(): string
    {
        return 'staffing';
    }

    /** @return list<MetricValue> */
    public function metrics(SnapshotContext $ctx): array
    {
        $overview = $this->overview();
        $unfilled = $overview['metrics']['unfilled_requests'] ?? null;
        $coveragePct = $overview['coverage']['coverage_pct'] ?? null;

        return $this->compact([
            $unfilled === null ? null : $this->fromKey($ctx, 'staffing.open_shifts', (float) $unfilled, [
                'sub' => $coveragePct !== null ? "coverage {$coveragePct}%" : null,
            ]),
            $this->demo($ctx, 'staffing.overtime'),
            $this->demo($ctx, 'staffing.agency'),
            $this->demo($ctx, 'staffing.callouts'),
            $this->demo($ctx, 'staffing.sitters'),
            $this->demo($ctx, 'staffing.productivity'),
        ]);
    }

    /** @return array<string, mixed> */
    private function overview(): array
    {
        try {
            return $this->staffing->overview();
        } catch (\Throwable) {
            return [];
        }
    }
}
