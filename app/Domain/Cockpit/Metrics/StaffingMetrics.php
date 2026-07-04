<?php

namespace App\Domain\Cockpit\Metrics;

use App\Domain\Cockpit\SnapshotContext;
use App\Services\Cockpit\StatusEngine;
use App\Services\Staffing\StaffingOperationsService;
use App\Services\Staffing\WorkforceActualsService;
use App\Support\Cockpit\MetricValue;

/**
 * Staffing & workforce (spec §2.5). LIVE as of P7: open shifts from
 * StaffingOperationsService::overview(); OT% / agency RNs / callouts /
 * sitters / productivity from today's prod.workforce_actuals rows via
 * WorkforceActualsService. A cost center that reported no hours today yields
 * a null tile (skipped) rather than a fabricated number.
 */
class StaffingMetrics extends BaseMetrics
{
    public function __construct(
        StatusEngine $engine,
        private readonly StaffingOperationsService $staffing,
        private readonly WorkforceActualsService $workforce,
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

        $workforce = $this->workforce();

        return $this->compact([
            $unfilled === null ? null : $this->fromKey($ctx, 'staffing.open_shifts', (float) $unfilled, [
                'sub' => $coveragePct !== null ? "coverage {$coveragePct}%" : null,
            ]),
            $workforce['overtime_pct'] === null ? null : $this->fromKey($ctx, 'staffing.overtime', $workforce['overtime_pct']),
            $workforce['agency_rns'] === null ? null : $this->fromKey($ctx, 'staffing.agency', (float) $workforce['agency_rns']),
            $workforce['callouts'] === null ? null : $this->fromKey($ctx, 'staffing.callouts', (float) $workforce['callouts']),
            $workforce['sitters'] === null ? null : $this->fromKey($ctx, 'staffing.sitters', (float) $workforce['sitters']),
            $workforce['productivity_pct'] === null ? null : $this->fromKey($ctx, 'staffing.productivity', $workforce['productivity_pct']),
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

    /** @return array<string, mixed> */
    private function workforce(): array
    {
        try {
            return $this->workforce->todaySummary();
        } catch (\Throwable) {
            return [
                'overtime_pct' => null, 'agency_rns' => null, 'callouts' => null,
                'sitters' => null, 'productivity_pct' => null,
                'premium_cost' => null, 'agency_cost' => null,
            ];
        }
    }
}
