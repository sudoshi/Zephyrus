<?php

namespace App\Domain\Cockpit\Metrics;

use App\Domain\Cockpit\SnapshotContext;
use App\Models\Flow\DischargeLoungeStay;
use App\Models\Transport\TransportRequest;
use App\Services\Cockpit\MaterializedMetricsReader;
use App\Services\Cockpit\StatusEngine;
use App\Services\DashboardService;
use App\Services\Evs\EvsOperationsService;
use App\Services\Transport\TransportOperationsService;
use App\Support\Cockpit\MetricValue;
use App\Support\Hospital\HospitalManifest;
use App\Support\Operations\DurationFormatter;
use Illuminate\Support\Facades\DB;

/**
 * Patient flow & transport (spec §2.6). LIVE as of P7: DBN from the legacy
 * payload (prod.encounters); queue depth / end-to-end wait from
 * TransportOperationsService; dirty beds + bed turnaround (avg/p90) from
 * EvsOperationsService; discharge lounge census from
 * prod.discharge_lounge_stays (chair capacity from the hospital manifest).
 */
class FlowMetrics extends BaseMetrics
{
    public function __construct(
        StatusEngine $engine,
        private readonly TransportOperationsService $transport,
        private readonly EvsOperationsService $evs,
        private readonly DashboardService $dashboard,
        private readonly HospitalManifest $manifest,
        private readonly MaterializedMetricsReader $mv,
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
        $turnaround = $this->turnaround();
        $lounge = $this->count(fn (): int => DischargeLoungeStay::active()->count());
        $loungeChairs = $this->manifest->facility()['discharge_lounge_chairs'] ?? null;

        $bottlenecks = $this->bottleneckStats();
        // Part X (X2): the OPerA synchronization constraint — the worst object-side
        // wait at a shared hand-off, mined object-centrically and cached in
        // arena.performance_signals. Null (tile absent) whenever the Arena is off.
        $worstHandoff = $this->mv->value('flow.worst_handoff_wait');

        return $this->compact([
            $this->fromLegacy($ctx, 'flow.dc_before_noon', 'dbn'),
            $lounge === null ? null : $this->fromKey($ctx, 'flow.discharge_lounge', (float) $lounge, [
                'sub' => $loungeChairs !== null ? "of {$loungeChairs} lounge chairs" : null,
            ]),
            $transportQueue === null ? null : $this->fromKey($ctx, 'flow.transport_queue', (float) $transportQueue),
            $transportWait === null ? null : $this->fromKey($ctx, 'flow.transport_wait', $transportWait),
            $turnaround === null ? null : $this->fromKey($ctx, 'flow.bed_turnaround', $turnaround['avg'], [
                'sub' => $turnaround['sub'],
            ]),
            $dirtyBeds === null ? null : $this->fromKey($ctx, 'flow.dirty_beds', (float) $dirtyBeds),
            // P5: the PI crown jewel at A0 — the 5 live bottleneck signals
            // (long-stay, OR turnover, blocked beds, at-risk transports,
            // ED boarding) from prod.*, ranked by impact.
            $bottlenecks === null ? null : $this->fromKey(
                $ctx,
                'flow.bottlenecks_active',
                (float) $bottlenecks['active'],
                ['sub' => "{$bottlenecks['patientImpact']} patients affected"],
            ),
            $bottlenecks === null ? null : $this->fromKey(
                $ctx,
                'flow.bottleneck_patients',
                (float) $bottlenecks['patientImpact'],
            ),
            $worstHandoff === null ? null : $this->fromKey(
                $ctx,
                'flow.worst_handoff_wait',
                $worstHandoff,
                ['sub' => $this->handoffContext()],
            ),
        ]);
    }

    /**
     * Part X (X2): the human label for the winning hand-off ("Bed at discharge")
     * — a cheap point-read of the same cached row RefreshArenaPerformance wrote.
     * Guarded: any read failure leaves the tile without a sub, never blanks it.
     */
    private function handoffContext(): ?string
    {
        try {
            $row = DB::table('arena.performance_signals')
                ->where('metric_key', 'flow.worst_handoff_wait')
                ->value('context');
        } catch (\Throwable) {
            return null;
        }

        return $row !== null ? (string) $row : null;
    }

    /**
     * The live bottleneck roll-up (DashboardService::getBottleneckStats —
     * the Process-Improvement pipeline's only genuinely-live signal set).
     *
     * @return array{active:int,patientImpact:int}|null
     */
    private function bottleneckStats(): ?array
    {
        try {
            $stats = $this->dashboard->getBottleneckStats()['stats'] ?? null;
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($stats) || ! isset($stats['active'], $stats['patientImpact'])) {
            return null;
        }

        return ['active' => (int) $stats['active'], 'patientImpact' => (int) $stats['patientImpact']];
    }

    /**
     * Request → pickup: the precomputed end-to-end elapsed measure (P7) —
     * one average over real per-request waits, not a sum of stage averages.
     */
    private function transportWait(): ?float
    {
        try {
            $measures = collect($this->transport->measures())->keyBy('key');
        } catch (\Throwable) {
            return null;
        }

        $wait = $measures->get('request_to_pickup_min')['value'] ?? null;

        return $wait !== null ? (float) $wait : null;
    }

    /**
     * Today's completed bed-turn stats from the EVS service (P7): the tile
     * shows the average, the sub carries the p90 tail.
     *
     * @return array{avg: float, sub: string|null}|null
     */
    private function turnaround(): ?array
    {
        try {
            $stats = $this->evs->turnaroundStats();
        } catch (\Throwable) {
            return null;
        }

        if ($stats['avg_min'] === null) {
            return null;
        }

        return [
            'avg' => $stats['avg_min'],
            'sub' => $stats['p90_min'] !== null
                ? 'p90 '.DurationFormatter::minutes((float) $stats['p90_min'])." · {$stats['completed']} turns"
                : null,
        ];
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
