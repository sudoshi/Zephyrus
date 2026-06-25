<?php

namespace App\Integrations\Healthcare\DTO;

final readonly class WebhookEnvelope
{
    public function __construct(
        public array $payload,
        public array $headers = [],
        public ?string $receivedAt = null,
    ) {}
}
