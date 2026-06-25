<?php

namespace App\Integrations\Healthcare\DTO;

final readonly class ConnectorHealth
{
    public function __construct(
        public string $status,
        public string $message,
        public array $metrics = [],
        public array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
            'metrics' => $this->metrics,
            'metadata' => $this->metadata,
        ];
    }
}
