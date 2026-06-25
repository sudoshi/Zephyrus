<?php

namespace App\Integrations\Healthcare\DTO;

final readonly class SourceMessage
{
    public function __construct(
        public string $messageType,
        public array $payload,
        public ?string $externalId = null,
        public ?string $receivedAt = null,
        public array $metadata = [],
    ) {}
}
