<?php

namespace App\Data\Lab;

/**
 * One coherent pair of laboratory aggregate facts for a single request or
 * queued-job scope. Both members are captured together so the verified-empty
 * reconciliation in LabCockpitHealthService always sees a consistent read —
 * lazily memoizing them independently could pair legs that span a mid-request
 * data change. Holds pre-projection aggregates only (counts, cutoffs, states);
 * patient/result projection stays with the owning services per caller.
 */
final readonly class LabAggregateSnapshot
{
    /**
     * @param  array<string, mixed>  $operationsHealth
     * @param  array<string, mixed>  $decisionReadiness
     */
    public function __construct(
        public array $operationsHealth,
        public array $decisionReadiness,
    ) {}
}
