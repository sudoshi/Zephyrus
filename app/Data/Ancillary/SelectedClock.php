<?php

namespace App\Data\Ancillary;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SelectedClock
{
    public const STATES = ['not_started', 'running', 'warning', 'breached', 'complete', 'unknown'];

    public function __construct(
        public string $definitionUuid,
        public string $metricKey,
        public string $label,
        public string $startMilestoneCode,
        public string $stopMilestoneCode,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $stoppedAt,
        public ?float $elapsedMinutes,
        public ?float $remainingMinutes,
        public string $state,
        public string $definitionText,
        public FreshnessEnvelope $freshness,
    ) {
        if (! in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException("Unsupported clock state: {$state}");
        }

        if ($elapsedMinutes !== null && $elapsedMinutes < 0) {
            throw new InvalidArgumentException('Elapsed clock minutes cannot be negative.');
        }

        if ($stoppedAt !== null && $startedAt === null) {
            throw new InvalidArgumentException('A stopped clock must identify its start assertion.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'definitionUuid' => $this->definitionUuid,
            'metricKey' => $this->metricKey,
            'label' => $this->label,
            'startMilestoneCode' => $this->startMilestoneCode,
            'stopMilestoneCode' => $this->stopMilestoneCode,
            'startedAt' => $this->startedAt?->format(DATE_ATOM),
            'stoppedAt' => $this->stoppedAt?->format(DATE_ATOM),
            'elapsedMinutes' => $this->elapsedMinutes,
            'remainingMinutes' => $this->remainingMinutes,
            'state' => $this->state,
            'definitionText' => $this->definitionText,
            'freshness' => $this->freshness->toArray(),
        ];
    }
}
