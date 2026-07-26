<?php

namespace App\Services\Lab;

use App\Data\Lab\LabAggregateSnapshot;

/**
 * Request-scoped laboratory aggregate snapshot (parity plan §3.2.9): the
 * Cockpit provider, health service, and readiness/drill consumers reuse one
 * governed cohort calculation per request or queued job instead of each
 * re-reading the cohort. Registered scoped() — the container flushes it per
 * FPM request and per queue job, so this never becomes a second cross-request
 * snapshot authority (that role belongs exclusively to SnapshotBuilder's
 * cache + persisted CockpitSnapshot row). Cohort selection and freshness stay
 * owned by LabFlowBoardService, gate derivation by LabDecisionPendingService,
 * status resolution by StatusEngine; this class memoizes and forwards only.
 * The user-filtered workspace build() paths are deliberately NOT memoized.
 */
final class LabAggregateSnapshotFactory
{
    private ?LabAggregateSnapshot $snapshot = null;

    public function __construct(
        private readonly LabFlowBoardService $flowBoard,
        private readonly LabDecisionPendingService $pending,
    ) {}

    public function snapshot(): LabAggregateSnapshot
    {
        return $this->snapshot ??= new LabAggregateSnapshot(
            operationsHealth: $this->flowBoard->cockpitHealth(),
            decisionReadiness: $this->pending->readinessSnapshot(),
        );
    }

    /** @return array<string, mixed> */
    public function operationsHealth(): array
    {
        return $this->snapshot()->operationsHealth;
    }

    /** @return array<string, mixed> */
    public function decisionReadiness(): array
    {
        return $this->snapshot()->decisionReadiness;
    }

    /** @return array<string, mixed> */
    public function decisionHealth(): array
    {
        return $this->pending->cockpitHealthFrom($this->snapshot()->decisionReadiness);
    }

    public function flush(): void
    {
        $this->snapshot = null;
    }
}
