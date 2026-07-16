<?php

namespace App\Services\Radiology\BreachRisk;

use DateTimeInterface;

/**
 * The available-at-prediction inputs for one open imaging order.
 *
 * This object deliberately carries no outcome-bearing field: no stop milestone,
 * no final-report time, no breach state, and no clinical/demographic attribute
 * beyond the operational `patientClass`. The leakage guard test asserts this
 * shape stays operational-only, and the feature extractor consumes nothing else.
 */
final readonly class BreachRiskObservation
{
    public function __construct(
        public int $orderId,
        public string $orderUuid,
        public DateTimeInterface $orderedAt,
        public ?DateTimeInterface $stageStartedAt,
        public ?string $modality,
        public string $priority,
        public string $patientClass,
        public ?int $queueDepth,
        public ?bool $scannerDown,
        public ?DateTimeInterface $signalCutoffAt,
    ) {}
}
