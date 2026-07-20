<?php

namespace App\Domain\Cockpit\Metrics;

use App\Domain\Cockpit\SnapshotContext;
use App\Enums\CockpitStatus;
use App\Services\Cockpit\StatusEngine;
use App\Services\Lab\LabCockpitHealthService;
use App\Support\Cockpit\MetricValue;

/**
 * Laboratory domain provider (sector expansion 2026-07-19). The ancillary lab
 * aggregates — formerly Flow sub-tiles — promoted to a first-class cockpit
 * sector. Emits the SAME governed `flow.ancillary_lab_*` keys (seeded catalog,
 * admin-tuned edges, and accrued history preserved); only the domain section
 * changes from `flow` to `lab`.
 *
 * Aggregate-only: composes LabCockpitHealthService verbatim, so the house wall
 * can never create a second cohort or expose a result. Freshness discipline:
 * stale/degraded sources demote to last-known NORMAL; missing/error with no
 * cutoff emits nothing; an all-empty domain is ABSENT.
 */
class LabMetrics extends BaseMetrics
{
    public function __construct(
        StatusEngine $engine,
        private readonly LabCockpitHealthService $laboratory,
    ) {
        parent::__construct($engine);
    }

    public function domain(): string
    {
        return 'lab';
    }

    /** @return list<MetricValue> */
    public function metrics(SnapshotContext $ctx): array
    {
        $laboratory = $this->health();

        if ($laboratory === null) {
            return [];
        }

        return $this->compact([
            $this->metric(
                $ctx,
                'flow.ancillary_lab_stat_compliance',
                $laboratory['statCompliance'],
                (int) $laboratory['statCompliance']['statCompliant'].' of '.(int) $laboratory['statCompliance']['statOrders'].' STAT within governed SLA',
            ),
            $this->metric(
                $ctx,
                'flow.ancillary_lab_oldest_decision_pending',
                $laboratory['oldestDecisionPending'],
                $this->decisionPendingContext($laboratory['oldestDecisionPending']),
            ),
            $this->metric(
                $ctx,
                'flow.ancillary_lab_critical_callbacks',
                $laboratory['criticalCallbacks'],
                $this->criticalCallbackContext($laboratory['criticalCallbacks']),
            ),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function health(): ?array
    {
        try {
            return $this->laboratory->build();
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
            'sourceLabel' => (string) ($fact['sourceLabel'] ?? 'Laboratory operational feeds'),
            'workspaceHref' => $this->workspaceHref($key),
        ];
        foreach (['statOrders', 'statCompliant', 'pendingCount', 'byDecisionClass', 'atRiskCount', 'oldestOpenAgeMinutes', 'byState', 'coverageState'] as $aggregate) {
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
        if ($key !== 'flow.ancillary_lab_critical_callbacks') {
            return null;
        }
        $count = (int) $value;

        return $count.' '.($count === 1 ? 'callback' : 'callbacks');
    }

    private function workspaceHref(string $key): string
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
}
