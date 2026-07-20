<?php

namespace App\Domain\Cockpit\Metrics;

use App\Domain\Cockpit\SnapshotContext;
use App\Enums\CockpitStatus;
use App\Services\Cockpit\StatusEngine;
use App\Services\Radiology\RadiologyCockpitHealthService;
use App\Support\Cockpit\MetricValue;

/**
 * Radiology domain provider (sector expansion 2026-07-19). The ancillary
 * imaging aggregates — formerly Flow sub-tiles — promoted to a first-class
 * cockpit sector. Emits the SAME governed `flow.ancillary_rad_*` keys (the
 * seeded catalog, admin-tuned edges, and accrued history are preserved); only
 * the domain section changes from `flow` to `radiology`.
 *
 * Aggregate-only: composes RadiologyCockpitHealthService verbatim, so the wall
 * and drill can never invent new math or expose a patient/study. Freshness
 * discipline (unchanged): a stale/degraded source demotes to a last-known
 * NORMAL tile so unavailable evidence can never earn green or an alert;
 * missing/error with no cutoff emits nothing, and an all-empty domain is
 * ABSENT from the snapshot.
 */
class RadiologyMetrics extends BaseMetrics
{
    public function __construct(
        StatusEngine $engine,
        private readonly RadiologyCockpitHealthService $radiology,
    ) {
        parent::__construct($engine);
    }

    public function domain(): string
    {
        return 'radiology';
    }

    /** @return list<MetricValue> */
    public function metrics(SnapshotContext $ctx): array
    {
        $radiology = $this->health();

        if ($radiology === null) {
            return [];
        }

        return $this->compact([
            $this->metric(
                $ctx,
                'flow.ancillary_rad_open_breaches',
                $radiology['openBreaches'],
                'Open imaging SLA breaches',
            ),
            $this->metric(
                $ctx,
                'flow.ancillary_rad_oldest_unread',
                $radiology['oldestUnread'],
                $this->unreadContext($radiology['oldestUnread']),
            ),
            $this->metric(
                $ctx,
                'flow.ancillary_rad_scanners_down',
                $radiology['scannersDown'],
                $radiology['scannersDown']['scannerTotal'].' active scanners',
            ),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function health(): ?array
    {
        try {
            return $this->radiology->build();
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
            'display' => $this->display($key, (float) $value),
            'sub' => $sub,
            'updatedAt' => $sourceCutoffAt ?? $ctx->nowIso,
            'metadata' => $metadata,
            ...($current ? [] : ['status' => CockpitStatus::NORMAL]),
        ]);
    }

    private function display(string $key, float $value): ?string
    {
        $count = (int) $value;

        return match ($key) {
            'flow.ancillary_rad_open_breaches' => $count.' '.($count === 1 ? 'order' : 'orders'),
            'flow.ancillary_rad_scanners_down' => $count.' '.($count === 1 ? 'scanner' : 'scanners'),
            default => null,
        };
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
}
