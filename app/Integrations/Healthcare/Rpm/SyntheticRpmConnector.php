<?php

namespace App\Integrations\Healthcare\Rpm;

use App\Integrations\Healthcare\Contracts\HealthcareConnector;
use App\Integrations\Healthcare\DTO\BackfillRequest;
use App\Integrations\Healthcare\DTO\ConnectorCapabilities;
use App\Integrations\Healthcare\DTO\ConnectorHealth;
use App\Integrations\Healthcare\DTO\PollRequest;
use App\Integrations\Healthcare\DTO\ReplayRequest;
use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\DTO\WebhookEnvelope;
use App\Integrations\Healthcare\Services\CanonicalEventWriter;
use App\Integrations\Healthcare\Services\ProjectionDispatcher;
use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Models\Integration\CanonicalEventRecord;
use App\Models\Integration\ConnectorWatermark;
use App\Models\Integration\ProvenanceRecord;
use App\Models\Raw\DeadLetter;
use App\Models\Raw\InboundMessage;
use App\Models\Raw\IngestRun;
use App\Security\ClinicalPayloads\ClinicalPayloadException;
use App\Security\ClinicalPayloads\ClinicalPayloadStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Synthetic RPM device-feed connector (ACUM-PRD-HAH-001 §5.2) — the Phase 1
 * MVP rides this until a real vendor is chosen (build brief §13 Q4). A copy of
 * SyntheticHealthcareConnector's full lifecycle (webhook + poll + backfill +
 * replay + dead-letter) with an RPM vocabulary: ObservationRecorded and
 * DeviceStatusChanged, projected by RpmProjectionHandler into
 * prod.rpm_observations and device state. Wire payloads are pseudonymous —
 * patient_ref + LOINC + value + device serial, never MRN or address.
 */
class SyntheticRpmConnector implements HealthcareConnector
{
    private const CONNECTOR_KEY = 'synthetic.rpm';

    public function __construct(
        private readonly SourceRegistryService $sources,
        private readonly SyntheticRpmMessageNormalizer $normalizer,
        private readonly SyntheticRpmCanonicalEventMapper $mapper,
        private readonly CanonicalEventWriter $writer,
        private readonly ProjectionDispatcher $projector,
        private readonly ClinicalPayloadStore $payloads,
    ) {}

    public function sourceKey(): string
    {
        return 'synthetic.rpm';
    }

    public function capabilities(): ConnectorCapabilities
    {
        return new ConnectorCapabilities(
            connectorKey: self::CONNECTOR_KEY,
            eventTypes: RpmEventVocabulary::eventTypes(),
            metadata: ['purpose' => 'Home Hospital synthetic RPM device feed'],
        );
    }

    public function healthCheck(): ConnectorHealth
    {
        return new ConnectorHealth(
            status: 'healthy',
            message: 'Synthetic RPM connector is available for deterministic Home Hospital tests.',
            metrics: [
                'supported_event_types' => count($this->capabilities()->eventTypes),
            ],
        );
    }

    public function backfill(BackfillRequest $request): IngestRun
    {
        return $this->ingestMessages($request->messages, 'backfill', $request->cursor);
    }

    public function poll(PollRequest $request): IngestRun
    {
        return $this->ingestMessages($request->messages, 'poll', $request->cursor);
    }

    public function handleWebhook(WebhookEnvelope $webhook): IngestRun
    {
        $messages = $webhook->payload['messages'] ?? [$webhook->payload];

        return $this->ingestMessages($messages, 'webhook', null, [
            'headers' => array_keys($webhook->headers),
            'received_at' => $webhook->receivedAt,
        ]);
    }

    public function replay(ReplayRequest $request): IngestRun
    {
        $source = $this->source();
        $run = $this->startRun($source, 'replay', metadata: ['scope' => $request->scope]);
        $query = CanonicalEventRecord::query()->where('source_id', $source->source_id);

        if ($request->canonicalEventIds !== []) {
            $query->whereIn('canonical_event_id', $request->canonicalEventIds);
        }

        $events = $query->orderBy('canonical_event_id')->get();
        $succeeded = 0;
        $failed = 0;

        foreach ($events as $record) {
            try {
                $this->projectRecord($record, force: true);
                $succeeded++;
            } catch (Throwable $exception) {
                $failed++;
                $this->deadLetter(
                    sourceId: $source->source_id,
                    runId: $run->ingest_run_id,
                    inboundMessageId: $record->inbound_message_id,
                    failureStage: 'replay',
                    reasonCode: 'projection_failed',
                    message: 'The encrypted canonical RPM event could not be projected during replay.',
                    exceptionClass: $exception::class,
                    context: ['canonical_event_id' => $record->canonical_event_id],
                );
            }
        }

        $run->update([
            'status' => $failed > 0 ? 'completed_with_errors' : 'completed',
            'completed_at' => now(),
            'messages_received' => $events->count(),
            'messages_succeeded' => $succeeded,
            'messages_failed' => $failed,
        ]);

        return $run->fresh();
    }

    private function ingestMessages(array $messages, string $runType, ?string $cursorBefore = null, array $metadata = []): IngestRun
    {
        $source = $this->source();
        $run = $this->startRun($source, $runType, $cursorBefore, $metadata);
        $received = 0;
        $succeeded = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($messages as $index => $rawPayload) {
            $received++;
            $message = $this->recordRawMessage($source->source_id, $run->ingest_run_id, $rawPayload, $index);

            if (! $message->wasRecentlyCreated && $message->parse_status === 'projected') {
                $skipped++;

                continue;
            }

            try {
                $sourceMessage = new SourceMessage(
                    messageType: (string) ($rawPayload['message_type'] ?? 'rpm.'.($rawPayload['event_type'] ?? 'unknown')),
                    payload: $rawPayload,
                    externalId: $rawPayload['external_id'] ?? ($rawPayload['transmission_id'] ?? null),
                    receivedAt: $rawPayload['received_at'] ?? null,
                    metadata: ['source_key' => $source->source_key],
                );

                if (! $this->normalizer->supports($sourceMessage)) {
                    throw new \InvalidArgumentException("Unsupported RPM source message type [{$sourceMessage->messageType}].");
                }

                $normalized = $this->normalizer->normalize($sourceMessage);
                if ($message->normalized_payload_object_id === null) {
                    $stored = $this->payloads->storeJson(
                        (int) $source->source_id,
                        'normalized_message',
                        $normalized->toArray(),
                    );
                    try {
                        $message->update([
                            'normalized_payload' => null,
                            'normalized_payload_object_id' => $stored->payloadObjectId,
                            'parse_status' => 'normalized',
                        ]);
                    } catch (Throwable $exception) {
                        $this->discardPayload(
                            $stored->payloadObjectId,
                            (int) $source->source_id,
                            'normalized_message_link_failed',
                        );

                        throw $exception;
                    }
                } else {
                    $message->update(['parse_status' => 'normalized']);
                }

                $events = $this->mapper->map($normalized);

                foreach ($events as $event) {
                    $record = $this->writer->write($event, $source, $run, $message);
                    $event = $event->withEventId($record->event_id);
                    $this->projectRecord($record, event: $event);
                    $this->writeProvenance($source->source_id, $message->inbound_message_id, $record);
                }

                $message->update(['parse_status' => 'projected']);
                $succeeded++;
            } catch (Throwable $exception) {
                $message->update(['parse_status' => 'failed']);
                $failed++;
                $this->deadLetter(
                    sourceId: $source->source_id,
                    runId: $run->ingest_run_id,
                    inboundMessageId: $message->inbound_message_id,
                    failureStage: 'mapping',
                    reasonCode: 'message_mapping_failed',
                    message: 'The encrypted RPM source message could not be normalized and mapped.',
                    exceptionClass: $exception::class,
                    context: [
                        'message_type' => $message->message_type,
                    ],
                );
            }
        }

        $status = $failed > 0
            ? ($succeeded > 0 || $skipped > 0 ? 'completed_with_errors' : 'failed')
            : 'completed';

        $run->update([
            'status' => $status,
            'completed_at' => now(),
            'messages_received' => $received,
            'messages_succeeded' => $succeeded,
            'messages_failed' => $failed,
            'messages_skipped' => $skipped,
            'cursor_after' => $received > 0 ? (string) now()->getTimestamp() : $cursorBefore,
        ]);

        if ($status !== 'failed') {
            ConnectorWatermark::updateOrCreate(
                [
                    'source_id' => $source->source_id,
                    'connector_key' => self::CONNECTOR_KEY,
                    'scope_type' => $runType,
                    'scope_key' => 'default',
                    'watermark_kind' => 'rpm_cursor',
                ],
                [
                    'watermark_value' => $run->cursor_after,
                    'last_success_at' => now(),
                    'metadata' => ['run_uuid' => $run->run_uuid],
                ],
            );
        }

        return $run->fresh();
    }

    private function source(): \App\Models\Integration\Source
    {
        $scope = $this->enterpriseScope();

        return $this->sources->ensureSource([
            'source_key' => $this->sourceKey(),
            'source_name' => 'Synthetic RPM Device Feed',
            'vendor' => 'Zephyrus',
            'system_class' => 'rpm',
            'interface_type' => 'webhook',
            'environment' => app()->environment('production') ? 'production' : 'sandbox',
            'active_status' => $scope === [] ? 'testing' : 'active',
            'metadata' => ['connector_key' => self::CONNECTOR_KEY],
            ...$scope,
        ]);
    }

    /** @return array<string, int|string> */
    private function enterpriseScope(): array
    {
        $facilityKey = trim((string) config('integrations.synthetic.facility_key'));
        if ($facilityKey === '') {
            return [];
        }

        $facility = \App\Models\Org\Facility::query()
            ->with('organization:organization_id,organization_key')
            ->where('facility_key', $facilityKey)
            ->where('is_active', true)
            ->first();
        if ($facility === null || $facility->organization === null) {
            return [];
        }

        return [
            'organization_id' => (int) $facility->organization_id,
            'facility_id' => (int) $facility->facility_id,
            'tenant_key' => (string) $facility->organization->organization_key,
            'facility_key' => (string) $facility->facility_key,
        ];
    }

    private function startRun(
        \App\Models\Integration\Source $source,
        string $runType,
        ?string $cursorBefore = null,
        array $metadata = [],
    ): IngestRun {
        return IngestRun::create([
            'run_uuid' => (string) Str::uuid(),
            'source_id' => $source->source_id,
            'connector_key' => self::CONNECTOR_KEY,
            'run_type' => $runType,
            'status' => 'running',
            'started_at' => now(),
            'cursor_before' => $cursorBefore,
            'metadata' => $metadata,
        ]);
    }

    private function recordRawMessage(int $sourceId, int $runId, array $payload, int $index): InboundMessage
    {
        $messageType = (string) ($payload['message_type'] ?? 'rpm.'.($payload['event_type'] ?? 'unknown'));
        $externalId = $payload['external_id'] ?? ($payload['transmission_id'] ?? null);
        $idempotencyKey = (string) ($payload['idempotency_key'] ?? implode(':', array_filter([
            $this->sourceKey(),
            $messageType,
            $externalId,
            $externalId ? null : (string) $index,
        ])));
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $existing = InboundMessage::query()
            ->where('source_id', $sourceId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing !== null) {
            if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                throw new ClinicalPayloadException('raw_message_idempotency_conflict');
            }
            if ($existing->payload_object_id === null) {
                $stored = $this->payloads->storeJson($sourceId, 'raw_message', $payload);
                try {
                    $existing->update([
                        'payload' => null,
                        'payload_object_id' => $stored->payloadObjectId,
                    ]);
                } catch (Throwable $exception) {
                    $this->discardPayload($stored->payloadObjectId, $sourceId, 'raw_message_link_failed');

                    throw $exception;
                }
            }

            return $existing;
        }

        $stored = $this->payloads->storeJson($sourceId, 'raw_message', $payload);
        try {
            return InboundMessage::create([
                'message_uuid' => (string) Str::uuid(),
                'source_id' => $sourceId,
                'ingest_run_id' => $runId,
                'message_type' => $messageType,
                'external_id' => $externalId,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'payload' => null,
                'payload_object_id' => $stored->payloadObjectId,
                'received_at' => $payload['received_at'] ?? now(),
                'parse_status' => 'received',
                'metadata' => ['connector_key' => self::CONNECTOR_KEY],
            ]);
        } catch (QueryException $exception) {
            $this->discardPayload($stored->payloadObjectId, $sourceId, 'raw_message_insert_race');
            $existing = InboundMessage::query()
                ->where('source_id', $sourceId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing === null) {
                throw $exception;
            }
            if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                throw new ClinicalPayloadException('raw_message_idempotency_conflict');
            }

            return $existing;
        } catch (Throwable $exception) {
            $this->discardPayload($stored->payloadObjectId, $sourceId, 'raw_message_insert_failed');

            throw $exception;
        }
    }

    private function projectRecord(
        CanonicalEventRecord $record,
        ?\App\Integrations\Healthcare\DTO\CanonicalOperationalEvent $event = null,
        bool $force = false,
    ): void {
        if ($record->projection_status === 'projected' && ! $force) {
            return;
        }

        $event ??= new \App\Integrations\Healthcare\DTO\CanonicalOperationalEvent(
            eventId: $record->event_id,
            eventType: $record->event_type,
            entityType: $record->entity_type,
            entityRef: $record->entity_ref,
            payload: $record->payload ?? [],
            occurredAt: $record->occurred_at,
            idempotencyKey: $record->idempotency_key,
            correlationId: $record->correlation_id,
            causationId: $record->causation_id,
            sequenceKey: $record->sequence_key,
            metadata: $record->metadata ?? [],
        );

        DB::transaction(function () use ($record, $event): void {
            $this->projector->project($event);
            $record->update([
                'projection_status' => 'projected',
                'projected_at' => now(),
            ]);
        });
    }

    private function writeProvenance(int $sourceId, int $messageId, CanonicalEventRecord $record): void
    {
        ProvenanceRecord::create([
            'source_id' => $sourceId,
            'inbound_message_id' => $messageId,
            'canonical_event_id' => $record->canonical_event_id,
            'target_schema' => 'prod',
            'target_table' => 'rpm_observations',
            'target_pk' => $record->event_id,
            'lineage' => [
                'raw_message' => "raw.inbound_messages:{$messageId}",
                'canonical_event' => "integration.canonical_events:{$record->canonical_event_id}",
                'projection' => 'prod.rpm_observations',
            ],
        ]);
    }

    private function deadLetter(
        ?int $sourceId,
        ?int $runId,
        ?int $inboundMessageId,
        string $failureStage,
        string $reasonCode,
        string $message,
        ?string $exceptionClass = null,
        array $context = [],
    ): DeadLetter {
        return DeadLetter::create([
            'dead_letter_uuid' => (string) Str::uuid(),
            'source_id' => $sourceId,
            'ingest_run_id' => $runId,
            'inbound_message_id' => $inboundMessageId,
            'failure_stage' => $failureStage,
            'reason_code' => $reasonCode,
            'message' => $message,
            'exception_class' => $exceptionClass,
            'context' => $context,
            'status' => 'open',
            'metadata' => ['connector_key' => self::CONNECTOR_KEY],
        ]);
    }

    private function discardPayload(int $payloadObjectId, int $sourceId, string $reasonCode): void
    {
        $this->payloads->discard(
            $payloadObjectId,
            $sourceId,
            $reasonCode,
            'Encrypted clinical payload was discarded because the intended database link did not become authoritative.',
        );
    }
}
