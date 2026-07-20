<?php

namespace App\Domain\Cockpit\Metrics;

use App\Domain\Cockpit\SnapshotContext;
use App\Enums\CockpitStatus;
use App\Services\Cockpit\StatusEngine;
use App\Services\Pharmacy\PharmacyCockpitHealthService;
use App\Support\Cockpit\MetricValue;

/**
 * Pharmacy domain provider (sector expansion 2026-07-19). The ancillary
 * medication aggregates — formerly Flow sub-tiles — promoted to a first-class
 * cockpit sector. Emits the SAME governed `flow.ancillary_rx_*` keys (seeded
 * catalog, admin-tuned edges, and accrued history preserved); only the domain
 * section changes from `flow` to `pharmacy`.
 *
 * Aggregate-only: composes PharmacyCockpitHealthService verbatim — no
 * pharmacist/verifier dimension exists anywhere in the contract (§13). The
 * sepsis-at-risk signal is administration-warehouse qualified: a stale or
 * absent tail renders it unknown (tile skipped), never a false success or
 * failure. Freshness discipline: stale/degraded sources demote to last-known
 * NORMAL; missing/error with no cutoff emits nothing; an all-empty domain is
 * ABSENT.
 */
class PharmacyMetrics extends BaseMetrics
{
    public function __construct(
        StatusEngine $engine,
        private readonly PharmacyCockpitHealthService $pharmacy,
    ) {
        parent::__construct($engine);
    }

    public function domain(): string
    {
        return 'pharmacy';
    }

    /** @return list<MetricValue> */
    public function metrics(SnapshotContext $ctx): array
    {
        $pharmacy = $this->health();

        if ($pharmacy === null) {
            return [];
        }

        return $this->compact([
            $this->metric(
                $ctx,
                'flow.ancillary_rx_verification_queue',
                $pharmacy['verificationQueue'],
                $this->verificationQueueContext($pharmacy['verificationQueue']),
            ),
            $this->metric(
                $ctx,
                'flow.ancillary_rx_oldest_stat',
                $pharmacy['oldestStat'],
                'Oldest open STAT medication',
            ),
            $this->metric(
                $ctx,
                'flow.ancillary_rx_sepsis_at_risk',
                $pharmacy['sepsisAtRisk'],
                $this->sepsisAtRiskContext($pharmacy['sepsisAtRisk']),
            ),
            $this->metric(
                $ctx,
                'flow.ancillary_rx_shortage_stockouts',
                $pharmacy['shortageStockouts'],
                $this->shortageStockoutContext($pharmacy['shortageStockouts']),
            ),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function health(): ?array
    {
        try {
            return $this->pharmacy->build();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $fact */
    private function metric(SnapshotContext $ctx, string $key, array $fact, string $context): ?MetricValue
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
            'workspaceHref' => $this->workspaceHref($key),
        ];
        foreach (['hourNormDepth', 'oldestAgeMinutes', 'openBreaches', 'openWarnings', 'administrationState', 'administrationCutoffAt', 'shortageOrders', 'coverageState'] as $aggregate) {
            if (array_key_exists($aggregate, $fact)) {
                $metadata[$aggregate] = $fact[$aggregate];
            }
        }

        return $this->fromKey($ctx, $key, (float) $value, [
            'display' => $this->display($key, (float) $value),
            'sub' => $current ? $context : 'Last known · '.str_replace('_', ' ', $sourceState).' · '.$context,
            'updatedAt' => $sourceCutoffAt ?? $ctx->nowIso,
            'metadata' => $metadata,
            ...($current ? [] : ['status' => CockpitStatus::NORMAL]),
        ]);
    }

    private function display(string $key, float $value): ?string
    {
        $count = (int) $value;

        return match ($key) {
            'flow.ancillary_rx_verification_queue' => $count.' '.($count === 1 ? 'order' : 'orders'),
            'flow.ancillary_rx_sepsis_at_risk' => $count.' '.($count === 1 ? 'order' : 'orders'),
            'flow.ancillary_rx_shortage_stockouts' => $count.' '.($count === 1 ? 'station' : 'stations'),
            default => null,
        };
    }

    private function workspaceHref(string $key): string
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
}
