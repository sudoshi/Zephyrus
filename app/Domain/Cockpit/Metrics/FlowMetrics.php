<?php

namespace App\Domain\Cockpit\Metrics;

use App\Domain\Cockpit\SnapshotContext;
use App\Enums\CockpitStatus;
use App\Models\Flow\DischargeLoungeStay;
use App\Models\Transport\TransportRequest;
use App\Services\Cockpit\MaterializedMetricsReader;
use App\Services\Cockpit\StatusEngine;
use App\Services\DashboardService;
use App\Services\Evs\EvsOperationsService;
use App\Services\Lab\LabCockpitHealthService;
use App\Services\Pharmacy\PharmacyCockpitHealthService;
use App\Services\Radiology\RadiologyCockpitHealthService;
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
        private readonly RadiologyCockpitHealthService $radiology,
        private readonly LabCockpitHealthService $laboratory,
        private readonly PharmacyCockpitHealthService $pharmacy,
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
        $radiology = $this->radiologyHealth();
        $laboratory = $this->laboratoryHealth();
        $pharmacy = $this->pharmacyHealth();

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
            $radiology === null ? null : $this->radiologyMetric(
                $ctx,
                'flow.ancillary_rad_open_breaches',
                $radiology['openBreaches'],
                'Open imaging SLA breaches',
            ),
            $radiology === null ? null : $this->radiologyMetric(
                $ctx,
                'flow.ancillary_rad_oldest_unread',
                $radiology['oldestUnread'],
                $this->unreadContext($radiology['oldestUnread']),
            ),
            $radiology === null ? null : $this->radiologyMetric(
                $ctx,
                'flow.ancillary_rad_scanners_down',
                $radiology['scannersDown'],
                $radiology['scannersDown']['scannerTotal'].' active scanners',
            ),
            $laboratory === null ? null : $this->laboratoryMetric(
                $ctx,
                'flow.ancillary_lab_stat_compliance',
                $laboratory['statCompliance'],
                (int) $laboratory['statCompliance']['statCompliant'].' of '.(int) $laboratory['statCompliance']['statOrders'].' STAT within governed SLA',
            ),
            $laboratory === null ? null : $this->laboratoryMetric(
                $ctx,
                'flow.ancillary_lab_oldest_decision_pending',
                $laboratory['oldestDecisionPending'],
                $this->decisionPendingContext($laboratory['oldestDecisionPending']),
            ),
            $laboratory === null ? null : $this->laboratoryMetric(
                $ctx,
                'flow.ancillary_lab_critical_callbacks',
                $laboratory['criticalCallbacks'],
                $this->criticalCallbackContext($laboratory['criticalCallbacks']),
            ),
            $pharmacy === null ? null : $this->pharmacyMetric(
                $ctx,
                'flow.ancillary_rx_verification_queue',
                $pharmacy['verificationQueue'],
                $this->verificationQueueContext($pharmacy['verificationQueue']),
            ),
            $pharmacy === null ? null : $this->pharmacyMetric(
                $ctx,
                'flow.ancillary_rx_oldest_stat',
                $pharmacy['oldestStat'],
                'Oldest open STAT medication',
            ),
            $pharmacy === null ? null : $this->pharmacyMetric(
                $ctx,
                'flow.ancillary_rx_sepsis_at_risk',
                $pharmacy['sepsisAtRisk'],
                $this->sepsisAtRiskContext($pharmacy['sepsisAtRisk']),
            ),
            $pharmacy === null ? null : $this->pharmacyMetric(
                $ctx,
                'flow.ancillary_rx_shortage_stockouts',
                $pharmacy['shortageStockouts'],
                $this->shortageStockoutContext($pharmacy['shortageStockouts']),
            ),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function radiologyHealth(): ?array
    {
        try {
            return $this->radiology->build();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function laboratoryHealth(): ?array
    {
        try {
            return $this->laboratory->build();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function pharmacyHealth(): ?array
    {
        try {
            return $this->pharmacy->build();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Emit one Pharmacy Flow-domain metric from the aggregate health contract.
     * A value that is null (e.g. sepsis-at-risk with a stale administration
     * tail) is skipped rather than rendered as a fabricated zero; when the
     * governing source is not fresh the tile is demoted to a last-known,
     * NORMAL, degraded/unknown state so unavailable evidence can never earn
     * green or an alert.
     *
     * @param  array<string, mixed>  $fact
     */
    private function pharmacyMetric(SnapshotContext $ctx, string $key, array $fact, string $context): ?MetricValue
    {
        $value = $fact['value'] ?? null;
        $sourceState = (string) ($fact['sourceState'] ?? 'missing');
        $sourceCutoffAt = $fact['sourceCutoffAt'] ?? null;
        if (! is_numeric($value) || ($sourceCutoffAt === null && in_array($sourceState, ['missing', 'error'], true))) {
            return null;
        }

        $current = $sourceState === 'fresh';
        $metadata = [
            'provenance' => 'live',
            'dataState' => $current ? 'current' : ($sourceState === 'missing' ? 'unknown' : 'degraded'),
            'sourceState' => $sourceState,
            'sourceCutoffAt' => $sourceCutoffAt,
            'sourceLabel' => (string) ($fact['sourceLabel'] ?? 'Pharmacy operational feeds'),
            'workspaceHref' => $this->pharmacyWorkspaceHref($key),
        ];
        foreach (['hourNormDepth', 'oldestAgeMinutes', 'openBreaches', 'openWarnings', 'administrationState', 'administrationCutoffAt', 'shortageOrders', 'coverageState'] as $aggregate) {
            if (array_key_exists($aggregate, $fact)) {
                $metadata[$aggregate] = $fact[$aggregate];
            }
        }

        return $this->fromKey($ctx, $key, (float) $value, [
            'display' => $this->pharmacyDisplay($key, (float) $value),
            'sub' => $current ? $context : 'Last known · '.str_replace('_', ' ', $sourceState).' · '.$context,
            'updatedAt' => $sourceCutoffAt ?? $ctx->nowIso,
            'metadata' => $metadata,
            ...($current ? [] : ['status' => CockpitStatus::NORMAL]),
        ]);
    }

    private function pharmacyDisplay(string $key, float $value): ?string
    {
        $count = (int) $value;

        return match ($key) {
            'flow.ancillary_rx_verification_queue' => $count.' '.($count === 1 ? 'order' : 'orders'),
            'flow.ancillary_rx_sepsis_at_risk' => $count.' '.($count === 1 ? 'order' : 'orders'),
            'flow.ancillary_rx_shortage_stockouts' => $count.' '.($count === 1 ? 'station' : 'stations'),
            default => null,
        };
    }

    private function pharmacyWorkspaceHref(string $key): string
    {
        return match ($key) {
            'flow.ancillary_rx_oldest_stat' => '/pharmacy?lens=stat&source=cockpit',
            'flow.ancillary_rx_sepsis_at_risk' => '/pharmacy?lens=sepsis&source=cockpit',
            'flow.ancillary_rx_shortage_stockouts' => '/pharmacy?lens=shortage&source=cockpit',
            default => '/pharmacy?source=cockpit',
        };
    }

    /** @param array<string, mixed> $fact */
    private function verificationQueueContext(array $fact): string
    {
        $context = (int) ($fact['value'] ?? 0).' awaiting verification';
        $norm = $fact['hourNormDepth'] ?? null;
        $oldest = $fact['oldestAgeMinutes'] ?? null;
        $context .= $norm === null ? '' : ' · hour norm '.(int) $norm;

        return $oldest === null ? $context : $context.' · oldest '.(int) $oldest.' min';
    }

    /** @param array<string, mixed> $fact */
    private function sepsisAtRiskContext(array $fact): string
    {
        $breaches = (int) ($fact['openBreaches'] ?? 0);
        $warnings = $fact['openWarnings'] ?? null;
        $context = $breaches.' breached';

        return $warnings === null ? $context : $context.' · '.(int) $warnings.' warning';
    }

    /** @param array<string, mixed> $fact */
    private function shortageStockoutContext(array $fact): string
    {
        $shortageOrders = (int) ($fact['shortageOrders'] ?? 0);

        return $shortageOrders.' shortage '.($shortageOrders === 1 ? 'order' : 'orders').' affected';
    }

    /** @param array<string, mixed> $fact */
    private function radiologyMetric(SnapshotContext $ctx, string $key, array $fact, string $context): ?MetricValue
    {
        $value = $fact['value'] ?? null;
        $sourceState = (string) ($fact['sourceState'] ?? 'missing');
        $sourceCutoffAt = $fact['sourceCutoffAt'] ?? null;

        if (! is_numeric($value) || ($sourceCutoffAt === null && in_array($sourceState, ['missing', 'error'], true))) {
            return null;
        }

        $current = $sourceState === 'fresh';
        $sub = $current ? $context : 'Last known · '.str_replace('_', ' ', $sourceState).' · '.$context;
        $metadata = [
            'provenance' => 'live',
            'dataState' => $current ? 'current' : ($sourceState === 'missing' ? 'unknown' : 'degraded'),
            'sourceState' => $sourceState,
            'sourceCutoffAt' => $sourceCutoffAt,
            'sourceLabel' => (string) ($fact['sourceLabel'] ?? 'Radiology operational feeds'),
            'workspaceHref' => '/radiology',
        ];
        if (isset($fact['byPriority']) && is_array($fact['byPriority'])) {
            $metadata['unreadByPriority'] = $fact['byPriority'];
        }

        return $this->fromKey($ctx, $key, (float) $value, [
            'display' => $this->radiologyDisplay($key, (float) $value),
            'sub' => $sub,
            'updatedAt' => $sourceCutoffAt ?? $ctx->nowIso,
            'metadata' => $metadata,
            ...($current ? [] : ['status' => CockpitStatus::NORMAL]),
        ]);
    }

    private function radiologyDisplay(string $key, float $value): ?string
    {
        $count = (int) $value;

        return match ($key) {
            'flow.ancillary_rad_open_breaches' => $count.' '.($count === 1 ? 'order' : 'orders'),
            'flow.ancillary_rad_scanners_down' => $count.' '.($count === 1 ? 'scanner' : 'scanners'),
            default => null,
        };
    }

    /** @param array<string, mixed> $fact */
    private function laboratoryMetric(SnapshotContext $ctx, string $key, array $fact, string $context): ?MetricValue
    {
        $value = $fact['value'] ?? null;
        $sourceState = (string) ($fact['sourceState'] ?? 'missing');
        $sourceCutoffAt = $fact['sourceCutoffAt'] ?? null;
        if (! is_numeric($value) || ($sourceCutoffAt === null && in_array($sourceState, ['missing', 'error'], true))) {
            return null;
        }

        $current = $sourceState === 'fresh';
        $metadata = [
            'provenance' => 'live',
            'dataState' => $current ? 'current' : ($sourceState === 'missing' ? 'unknown' : 'degraded'),
            'sourceState' => $sourceState,
            'sourceCutoffAt' => $sourceCutoffAt,
            'sourceLabel' => (string) ($fact['sourceLabel'] ?? 'Laboratory operational feeds'),
            'workspaceHref' => $this->laboratoryWorkspaceHref($key),
        ];
        foreach (['statOrders', 'statCompliant', 'pendingCount', 'byDecisionClass', 'atRiskCount', 'oldestOpenAgeMinutes', 'byState', 'coverageState'] as $aggregate) {
            if (array_key_exists($aggregate, $fact)) {
                $metadata[$aggregate] = $fact[$aggregate];
            }
        }

        return $this->fromKey($ctx, $key, (float) $value, [
            'display' => $this->laboratoryDisplay($key, (float) $value),
            'sub' => $current ? $context : 'Last known · '.str_replace('_', ' ', $sourceState).' · '.$context,
            'updatedAt' => $sourceCutoffAt ?? $ctx->nowIso,
            'metadata' => $metadata,
            ...($current ? [] : ['status' => CockpitStatus::NORMAL]),
        ]);
    }

    private function laboratoryDisplay(string $key, float $value): ?string
    {
        if ($key !== 'flow.ancillary_lab_critical_callbacks') {
            return null;
        }
        $count = (int) $value;

        return $count.' '.($count === 1 ? 'callback' : 'callbacks');
    }

    private function laboratoryWorkspaceHref(string $key): string
    {
        return match ($key) {
            'flow.ancillary_lab_stat_compliance' => '/lab?priority=stat&source=cockpit',
            'flow.ancillary_lab_oldest_decision_pending' => '/lab/pending-decisions?source=cockpit',
            'flow.ancillary_lab_critical_callbacks' => '/lab?lens=critical_callbacks&source=cockpit',
            default => '/lab?source=cockpit',
        };
    }

    /** @param array<string, mixed> $fact */
    private function decisionPendingContext(array $fact): string
    {
        $classes = collect($fact['byDecisionClass'] ?? [])->map(
            fn (array $row): string => str_replace('_', ' ', (string) $row['decisionClass']).' '.(int) $row['count'],
        )->implode(' · ');
        $context = (int) ($fact['pendingCount'] ?? 0).' decision gates';

        return $classes === '' ? $context : $context.' · '.$classes;
    }

    /** @param array<string, mixed> $fact */
    private function criticalCallbackContext(array $fact): string
    {
        $context = (int) ($fact['atRiskCount'] ?? 0).' at risk';
        $oldest = $fact['oldestOpenAgeMinutes'] ?? null;

        return $oldest === null ? $context : $context.' · oldest '.(int) $oldest.' min';
    }

    /** @param array<string, mixed> $fact */
    private function unreadContext(array $fact): string
    {
        $byPriority = collect($fact['byPriority'] ?? [])->map(
            fn (array $row): string => strtoupper((string) $row['priority']).' '.(int) $row['count'],
        )->implode(' · ');
        $context = (int) ($fact['unreadCount'] ?? 0).' unread';

        return $byPriority === '' ? $context : $context.' · '.$byPriority;
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
