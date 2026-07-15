<?php

namespace App\Data\Ancillary;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AncillarySlaEvaluation
{
    public const STATES = ['not_started', 'running', 'warning', 'breached', 'complete', 'unknown'];

    public function __construct(
        public int $definitionId,
        public string $definitionUuid,
        public string $metricKey,
        public string $state,
        public ?float $elapsedMinutes,
        public ?int $startAssertionId,
        public ?int $stopAssertionId,
        public ?int $breachId,
        public bool $breachOpened,
        public bool $breachCleared,
        public bool $wasBreached,
        public ?DateTimeImmutable $warningAt,
        public ?DateTimeImmutable $breachedAt,
        public ?DateTimeImmutable $clearedAt,
        public FreshnessEnvelope $freshness,
        public ?string $reason = null,
    ) {
        if (! in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException("Unsupported ancillary SLA state: {$state}");
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'definitionId' => $this->definitionId,
            'definitionUuid' => $this->definitionUuid,
            'metricKey' => $this->metricKey,
            'state' => $this->state,
            'elapsedMinutes' => $this->elapsedMinutes,
            'startAssertionId' => $this->startAssertionId,
            'stopAssertionId' => $this->stopAssertionId,
            'breachId' => $this->breachId,
            'breachOpened' => $this->breachOpened,
            'breachCleared' => $this->breachCleared,
            'wasBreached' => $this->wasBreached,
            'warningAt' => $this->warningAt?->format(DATE_ATOM),
            'breachedAt' => $this->breachedAt?->format(DATE_ATOM),
            'clearedAt' => $this->clearedAt?->format(DATE_ATOM),
            'freshness' => $this->freshness->toArray(),
            'reason' => $this->reason,
        ];
    }
}
