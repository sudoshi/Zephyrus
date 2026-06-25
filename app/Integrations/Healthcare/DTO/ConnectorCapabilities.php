<?php

namespace App\Integrations\Healthcare\DTO;

final readonly class ConnectorCapabilities
{
    /** @param list<string> $eventTypes */
    public function __construct(
        public string $connectorKey,
        public array $eventTypes,
        public bool $supportsBackfill = true,
        public bool $supportsPolling = true,
        public bool $supportsWebhooks = true,
        public bool $supportsReplay = true,
        public array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'connector_key' => $this->connectorKey,
            'event_types' => $this->eventTypes,
            'supports_backfill' => $this->supportsBackfill,
            'supports_polling' => $this->supportsPolling,
            'supports_webhooks' => $this->supportsWebhooks,
            'supports_replay' => $this->supportsReplay,
            'metadata' => $this->metadata,
        ];
    }
}
