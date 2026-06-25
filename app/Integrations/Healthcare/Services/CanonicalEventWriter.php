<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Models\Integration\CanonicalEventRecord;
use App\Models\Integration\Source;
use App\Models\Raw\InboundMessage;
use App\Models\Raw\IngestRun;

class CanonicalEventWriter
{
    public function write(
        CanonicalOperationalEvent $event,
        ?Source $source = null,
        ?IngestRun $run = null,
        ?InboundMessage $message = null,
    ): CanonicalEventRecord {
        $payloadHash = hash('sha256', json_encode($event->payload, JSON_THROW_ON_ERROR));

        return CanonicalEventRecord::firstOrCreate(
            ['idempotency_key' => $event->idempotencyKey],
            [
                'event_id' => $event->eventId,
                'source_id' => $source?->source_id,
                'ingest_run_id' => $run?->ingest_run_id,
                'inbound_message_id' => $message?->inbound_message_id,
                'event_type' => $event->eventType,
                'entity_type' => $event->entityType,
                'entity_ref' => $event->entityRef,
                'occurred_at' => $event->occurredAt,
                'received_at' => now(),
                'payload' => $event->payload,
                'payload_hash' => $payloadHash,
                'correlation_id' => $event->correlationId,
                'causation_id' => $event->causationId,
                'sequence_key' => $event->sequenceKey,
                'projection_status' => 'pending',
                'metadata' => $event->metadata,
            ],
        );
    }
}
