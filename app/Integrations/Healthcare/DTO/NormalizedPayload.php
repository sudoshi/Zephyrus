<?php

namespace App\Integrations\Healthcare\DTO;

final readonly class NormalizedPayload
{
    public function __construct(
        public string $messageType,
        public string $eventType,
        public array $payload,
        public string $idempotencyKey,
        public ?string $externalId = null,
        public ?string $occurredAt = null,
        public array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'message_type' => $this->messageType,
            'event_type' => $this->eventType,
            'payload' => $this->payload,
            'idempotency_key' => $this->idempotencyKey,
            'external_id' => $this->externalId,
            'occurred_at' => $this->occurredAt,
            'metadata' => $this->metadata,
        ];
    }
}
