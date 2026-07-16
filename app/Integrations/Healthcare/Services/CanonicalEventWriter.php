<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Models\Integration\CanonicalEventRecord;
use App\Models\Integration\Source;
use App\Models\Raw\InboundMessage;
use App\Models\Raw\IngestRun;
use App\Observability\MetricRecorder;
use App\Security\ClinicalPayloads\ClinicalPayloadException;
use App\Security\ClinicalPayloads\ClinicalPayloadStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

class CanonicalEventWriter
{
    public function __construct(
        private readonly ClinicalPayloadStore $payloads,
        private readonly MetricRecorder $metrics,
    ) {}

    /** @throws JsonException */
    public function write(
        CanonicalOperationalEvent $event,
        ?Source $source = null,
        ?IngestRun $run = null,
        ?InboundMessage $message = null,
    ): CanonicalEventRecord {
        $startedAt = hrtime(true);
        $attributes = $this->traceAttributes($event, $source, $run, $message);

        try {
            $record = $this->persist($event, $source, $run, $message);
            $this->metrics->span(
                'zephyrus.integration.canonical.write',
                'ok',
                $this->durationMs($startedAt),
                [...$attributes, 'zephyrus.outcome' => 'persisted'],
            );

            return $record;
        } catch (Throwable $exception) {
            $this->metrics->span(
                'zephyrus.integration.canonical.write',
                'error',
                $this->durationMs($startedAt),
                [...$attributes, 'error.type' => $exception instanceof ClinicalPayloadException
                    ? 'canonical_event_rejected'
                    : 'canonical_event_write_failed'],
            );

            throw $exception;
        }
    }

    /** @throws JsonException */
    private function persist(
        CanonicalOperationalEvent $event,
        ?Source $source,
        ?IngestRun $run,
        ?InboundMessage $message,
    ): CanonicalEventRecord {
        $payloadHash = hash('sha256', json_encode($event->payload, JSON_THROW_ON_ERROR));
        if ($source === null) {
            throw new ClinicalPayloadException('canonical_event_source_required');
        }

        $existing = $this->existing($event->idempotencyKey, (int) $source->source_id, $payloadHash);
        if ($existing !== null) {
            return $existing;
        }

        $stored = $this->payloads->storeJson(
            (int) $source->source_id,
            'canonical_event',
            $event->payload,
        );

        try {
            return DB::transaction(fn (): CanonicalEventRecord => CanonicalEventRecord::create([
                'event_id' => $event->eventId,
                'source_id' => $source->source_id,
                'ingest_run_id' => $run?->ingest_run_id,
                'inbound_message_id' => $message?->inbound_message_id,
                'event_type' => $event->eventType,
                'entity_type' => $event->entityType,
                'entity_ref' => $event->entityRef,
                'occurred_at' => $event->occurredAt,
                'received_at' => now(),
                'payload' => json_encode((object) [], JSON_THROW_ON_ERROR),
                'payload_object_id' => $stored->payloadObjectId,
                'payload_hash' => $payloadHash,
                'correlation_id' => $event->correlationId,
                'causation_id' => $event->causationId,
                'idempotency_key' => $event->idempotencyKey,
                'sequence_key' => $event->sequenceKey,
                'projection_status' => 'pending',
                'metadata' => $event->metadata,
            ]));
        } catch (QueryException $exception) {
            try {
                $existing = $this->existing($event->idempotencyKey, (int) $source->source_id, $payloadHash);
            } catch (Throwable $conflict) {
                $this->discard($stored->payloadObjectId, (int) $source->source_id);

                throw $conflict;
            }
            if ($existing === null) {
                $this->discard($stored->payloadObjectId, (int) $source->source_id);

                throw $exception;
            }
            $this->discard($stored->payloadObjectId, (int) $source->source_id);

            return $existing;
        } catch (Throwable $exception) {
            $this->discard($stored->payloadObjectId, (int) $source->source_id);

            throw $exception;
        }
    }

    private function existing(string $idempotencyKey, int $sourceId, string $payloadHash): ?CanonicalEventRecord
    {
        $existing = CanonicalEventRecord::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing === null) {
            return null;
        }
        if ((int) $existing->source_id !== $sourceId
            || ! hash_equals((string) $existing->payload_hash, $payloadHash)) {
            throw new ClinicalPayloadException('canonical_event_idempotency_conflict');
        }

        return $existing;
    }

    private function discard(int $payloadObjectId, int $sourceId): void
    {
        $this->payloads->discard(
            $payloadObjectId,
            $sourceId,
            'canonical_event_insert_aborted',
            'Encrypted canonical payload was discarded because its database insert did not become authoritative.',
        );
    }

    /** @return array<string, int|string> */
    private function traceAttributes(
        CanonicalOperationalEvent $event,
        ?Source $source,
        ?IngestRun $run,
        ?InboundMessage $message,
    ): array {
        $attributes = [];
        if ($source !== null) {
            $attributes['zephyrus.source.id'] = (int) $source->source_id;
        }
        if (Str::isUuid($event->eventId)) {
            $attributes['zephyrus.event.uuid'] = $event->eventId;
        }
        if ($run !== null && Str::isUuid((string) $run->run_uuid)) {
            $attributes['zephyrus.run.uuid'] = (string) $run->run_uuid;
        }
        if ($message !== null && Str::isUuid((string) $message->message_uuid)) {
            $attributes['zephyrus.message.uuid'] = (string) $message->message_uuid;
        }
        $requestId = is_array($run?->metadata) ? ($run->metadata['request_id'] ?? null) : null;
        if (is_string($requestId) && Str::isUuid($requestId)) {
            $attributes['zephyrus.correlation.uuid'] = $requestId;
        }

        return $attributes;
    }

    private function durationMs(int $startedAt): int
    {
        return max(0, min(86_400_000, (int) ((hrtime(true) - $startedAt) / 1_000_000)));
    }
}
