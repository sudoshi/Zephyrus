<?php

namespace App\Data\Ancillary;

use DateTimeImmutable;

final readonly class MilestoneAssertion
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $milestoneUuid,
        public string $code,
        public string $label,
        public DateTimeImmutable $occurredAt,
        public DateTimeImmutable $receivedAt,
        public string $sourceKey,
        public int $sourceRank,
        public bool $selected,
        public int $assertionCount = 1,
        public ?int $disagreementSeconds = null,
        public array $metadata = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'milestoneUuid' => $this->milestoneUuid,
            'code' => $this->code,
            'label' => $this->label,
            'occurredAt' => $this->occurredAt->format(DATE_ATOM),
            'receivedAt' => $this->receivedAt->format(DATE_ATOM),
            'sourceKey' => $this->sourceKey,
            'sourceRank' => $this->sourceRank,
            'selected' => $this->selected,
            'assertionCount' => $this->assertionCount,
            'disagreementSeconds' => $this->disagreementSeconds,
            'metadata' => $this->metadata,
        ];
    }
}
