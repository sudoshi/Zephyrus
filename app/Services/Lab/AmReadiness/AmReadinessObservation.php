<?php

namespace App\Services\Lab\AmReadiness;

use DateTimeInterface;

/**
 * The available-at-prediction inputs for one open decision-class Laboratory order.
 *
 * This object deliberately carries no outcome-bearing field: no verification
 * time, no result value, no "verified before cutoff" flag, and no
 * clinical/demographic attribute beyond the operational `patientClass`. The
 * current processing `stage` is the last observed milestone on the
 * collect→receive→result→verify path (never LAB_VERIFIED, because a verified
 * order has left the decision-pending cohort). The leakage guard test asserts
 * this shape stays operational-only, and the feature extractor consumes nothing
 * else.
 */
final readonly class AmReadinessObservation
{
    public function __construct(
        public int $orderId,
        public string $orderUuid,
        public string $stage,
        public string $testFamily,
        public string $priority,
        public string $patientClass,
        public bool $isAmDraw,
        public ?int $queueDepth,
        public ?bool $analyzerDowntime,
        public DateTimeInterface $roundsCutoffAt,
        public ?DateTimeInterface $signalCutoffAt,
    ) {}
}
