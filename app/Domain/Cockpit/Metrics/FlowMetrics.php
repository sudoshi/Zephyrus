<?php

namespace App\Domain\Cockpit\Metrics;

use App\Domain\Cockpit\SnapshotContext;
use App\Models\Evs\EvsRequest;
use App\Models\Transport\TransportRequest;
use App\Services\Cockpit\StatusEngine;
use App\Services\Evs\EvsOperationsService;
use App\Services\Transport\TransportOperationsService;
use App\Support\Cockpit\MetricValue;
use Illuminate\Support\Carbon;

/**
 * Patient flow & transport (spec §2.6). PARTIAL-LIVE per the plan:
 * DBN from the legacy payload; queue depth / waits from
 * TransportOperationsService; dirty beds from EvsOperationsService; bed
 * turnaround from today's completed EVS bed turns; discharge lounge is demo
 * until a lounge source exists (P7).
 */
class FlowMetrics extends BaseMetrics
{
    public function __construct(
        StatusEngine $engine,
        private readonly TransportOperationsService $transport,
        private readonly EvsOperationsService $evs,
    ) {
        parent::__construct($engine);
    }

    public function domain(): string
    {
        return 'flow';
    }

    /** @return list<MetricValue> */
    public function metrics(SnapshotContext $ctx): array
    {
        $transportQueue = $this->count(fn (): int => TransportRequest::active()->count());
        $transportWait = $this->transportWait();
        $dirtyBeds = $this->count(
            fn (): int => (int) ($this->evs->overview()['metrics']['dirty_bed_turnovers'] ?? 0)
        );

        return $this->compact([
            $this->fromLegacy($ctx, 'flow.dc_before_noon', 'dbn'),
            $this->demo($ctx, 'flow.discharge_lounge', ['sub' => 'of 10 lounge chairs']),
            $transportQueue === null ? null : $this->fromKey($ctx, 'flow.transport_queue', (float) $transportQueue),
            $transportWait === null ? null : $this->fromKey($ctx, 'flow.transport_wait', $transportWait),
            $this->fromKey($ctx, 'flow.bed_turnaround', $this->bedTurnaround()),
            $dirtyBeds === null ? null : $this->fromKey($ctx, 'flow.dirty_beds', (float) $dirtyBeds),
        ]);
    }

    /**
     * Request → pickup: the sum of the request-to-assign and
     * dispatch-to-pickup measure averages (spec §2.6 definition).
     */
    private function transportWait(): ?float
    {
        try {
            $measures = collect($this->transport->measures())->keyBy('key');
        } catch (\Throwable) {
            return null;
        }

        $toAssign = $measures->get('request_to_assign_min')['value'] ?? null;
        $toPickup = $measures->get('dispatch_to_pickup_min')['value'] ?? null;

        if ($toAssign === null && $toPickup === null) {
            return null;
        }

        return round((float) ($toAssign ?? 0) + (float) ($toPickup ?? 0), 1);
    }

    /** Average dirty→ready minutes over today's completed bed turns. */
    private function bedTurnaround(): ?float
    {
        $avg = EvsRequest::query()
            ->whereIn('request_type', ['bed_clean', 'discharge_turnover'])
            ->where('status', 'completed')
            ->whereDate('completed_at', Carbon::today())
            ->selectRaw('AVG(EXTRACT(EPOCH FROM completed_at - requested_at) / 60) AS avg_min')
            ->value('avg_min');

        return $avg !== null ? round((float) $avg) : null;
    }

    private function count(callable $fn): ?int
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return null;
        }
    }
}
