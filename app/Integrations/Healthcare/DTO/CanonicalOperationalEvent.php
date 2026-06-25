<?php

namespace App\Integrations\Healthcare\DTO;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final readonly class CanonicalOperationalEvent
{
    public function __construct(
        public string $eventId,
        public string $eventType,
        public ?string $entityType,
        public ?string $entityRef,
        public array $payload,
        public CarbonInterface $occurredAt,
        public string $idempotencyKey,
        public ?string $correlationId = null,
        public ?string $causationId = null,
        public ?string $sequenceKey = null,
        public array $metadata = [],
    ) {}

    public function withEventId(string $eventId): self
    {
        return new self(
            eventId: $eventId,
            eventType: $this->eventType,
            entityType: $this->entityType,
            entityRef: $this->entityRef,
            payload: $this->payload,
            occurredAt: $this->occurredAt,
            idempotencyKey: $this->idempotencyKey,
            correlationId: $this->correlationId,
            causationId: $this->causationId,
            sequenceKey: $this->sequenceKey,
            metadata: $this->metadata,
        );
    }

    public static function occurredAt(?string $value): CarbonInterface
    {
        return $value ? CarbonImmutable::parse($value) : CarbonImmutable::now();
    }
}
