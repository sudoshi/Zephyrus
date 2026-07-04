<?php

namespace App\Domain\Cockpit\Metrics;

use App\Domain\Cockpit\SnapshotContext;
use App\Services\Cockpit\StatusEngine;
use App\Services\Dashboard\PerioperativeMetricsService;
use App\Support\Cockpit\MetricValue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perioperative / OR (spec §2.4). LIVE.
 *
 * FCOTS / turnover / block util / cancellations come from the legacy payload
 * (same OR-day window as /dashboard). Prime-time utilization wraps
 * PerioperativeMetricsService; cases-today is one count; PACU holds mirrors
 * InterventionAttributionService::pacuHoldsAt()'s convention (in PACU ≥75min
 * with no pacu_out_time — that helper is private, the semantics are shared).
 */
class PeriopMetrics extends BaseMetrics
{
    public function __construct(
        StatusEngine $engine,
        private readonly PerioperativeMetricsService $periop,
    ) {
        parent::__construct($engine);
    }

    public function domain(): string
    {
        return 'periop';
    }

    /** @return list<MetricValue> */
    public function metrics(SnapshotContext $ctx): array
    {
        $casesToday = (int) DB::table('prod.or_cases')
            ->whereDate('surgery_date', Carbon::today())
            ->where('is_deleted', false)
            ->count();

        return $this->compact([
            $this->fromKey($ctx, 'periop.prime_util', $this->primeTimeUtilization()),
            $this->fromLegacy($ctx, 'periop.first_case_ontime', 'fcots'),
            $this->fromLegacy($ctx, 'periop.turnover', 'turnover'),
            $this->fromKey($ctx, 'periop.cases', (float) $casesToday, [
                'sub' => 'on today\'s schedule',
            ]),
            $this->fromLegacy($ctx, 'periop.cancellations', 'cancellations'),
            $this->fromKey($ctx, 'periop.pacu_holds', (float) $this->pacuHolds()),
            $this->fromLegacy($ctx, 'periop.block_util', 'block_util'),
        ]);
    }

    private function primeTimeUtilization(): ?float
    {
        try {
            $staffed = $this->periop->build()['lastMonth']['primetimeUtilization']['staffed'] ?? null;

            return is_numeric($staffed) ? (float) $staffed : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function pacuHolds(): int
    {
        if (! Schema::hasTable('prod.or_logs')) {
            return 0;
        }

        return (int) DB::table('prod.or_logs as l')
            ->join('prod.or_cases as c', 'c.case_id', '=', 'l.case_id')
            ->where('l.is_deleted', false)
            ->where('c.is_deleted', false)
            ->whereNotNull('l.pacu_in_time')
            ->where('l.pacu_in_time', '<=', Carbon::now()->subMinutes(75))
            ->whereNull('l.pacu_out_time')
            ->count();
    }
}
