<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Ancillary\AncillaryCanonicalEventMapper;
use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use App\Models\Integration\CanonicalEventRecord;
use App\Models\Integration\Source;
use App\Models\Raw\DeadLetter;
use App\Models\Raw\InboundMessage;
use App\Models\Raw\IngestRun;
use App\Security\ClinicalPayloads\ClinicalPayloadStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class AncillaryMessageIngestPipeline
{
    public const CONNECTOR_KEY = 'ancillary.healthcare';

    public function __construct(
        private readonly AncillaryNormalizerRegistry $normalizers,
        private readonly AncillaryCanonicalEventMapper $mapper,
        private readonly CanonicalEventWriter $writer,
        private readonly ProjectionDispatcher $projector,
        private readonly ClinicalPayloadStore $payloads,
    ) {}

    /**
     * @return array{accepted: true, duplicate: bool, status: string, run_id: string, message_id: string, canonical_event_ids: list<string>}
     */
    public function ingest(
        string $sourceKey,
        SourceMessage $sourceMessage,
        string $runType = 'machine_ingress',
        ?string $cursorBefore = null,
    ): array {
        $source = $this->authorizedSource($sourceKey);
        $run = $this->startRun($source, $runType, $cursorBefore);
        $message = null;
        $messageCreated = false;

        try {
            $payloadJson = json_encode($sourceMessage->payload, JSON_THROW_ON_ERROR);
            $payloadHash = hash('sha256', $payloadJson);
            $idempotencyKey = $this->idempotencyKey($sourceMessage, $payloadHash);
            $message = $this->recordRawMessage($source, $run, $sourceMessage, $payloadHash, $idempotencyKey);
            $messageCreated = $message->wasRecentlyCreated;

            if (! hash_equals((string) $message->payload_hash, $payloadHash)) {
                throw new AncillaryIngestException('idempotency_key_conflict', 'The ancillary idempotency key was used with a different payload.');
            }

            $existing = CanonicalEventRecord::query()
                ->where('inbound_message_id', $message->inbound_message_id)
                ->orderBy('canonical_event_id')
                ->get();
            if (! $messageCreated && $existing->isNotEmpty() && $existing->every(fn (CanonicalEventRecord $record): bool => $record->projection_status === 'projected')) {
                $this->completeRun($run, received: 1, skipped: 1, cursorAfter: $cursorBefore);

                return $this->receipt($run, $message, $existing->all(), duplicate: true);
            }

            if (strlen($payloadJson) > max(1024, (int) config('integrations.ancillary.max_message_bytes', 262144))) {
                throw new AncillaryIngestException('message_too_large', 'The ancillary message exceeds the configured size limit.');
            }

            $enriched = new SourceMessage(
                messageType: $sourceMessage->messageType,
                payload: $sourceMessage->payload,
                externalId: $sourceMessage->externalId,
                receivedAt: $sourceMessage->receivedAt,
                metadata: [
                    ...$sourceMessage->metadata,
                    'source_id' => $source->source_id,
                    'source_key' => $source->source_key,
                    'system_class' => $source->system_class,
                    'ancillary_ingest' => $source->metadata['ancillary_ingest'] ?? [],
                ],
            );
            $normalized = $this->normalizers->normalize($enriched);
            $normalizedAttributes = [
                'message_type' => substr($normalized->messageType, 0, 120),
                'external_id' => $this->boundedNullable($normalized->externalId, 190),
                'parse_status' => 'normalized',
            ];
            if ($message->normalized_payload_object_id === null) {
                $stored = $this->payloads->storeJson(
                    (int) $source->source_id,
                    'normalized_message',
                    $normalized->toArray(),
                );
                try {
                    $message->update([
                        ...$normalizedAttributes,
                        'normalized_payload' => null,
                        'normalized_payload_object_id' => $stored->payloadObjectId,
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
                $message->update($normalizedAttributes);
            }
            $events = $this->mapper->map($normalized);
            if ($events === []) {
                throw new AncillaryIngestException('empty_canonical_mapping', 'The ancillary message did not produce a canonical event.', 'canonical_mapping');
            }

            $records = [];
            foreach ($events as $event) {
                $records[] = DB::transaction(function () use ($event, $source, $run, $message): CanonicalEventRecord {
                    $record = $this->writer->write($event, $source, $run, $message);
                    $projectedEvent = $event->withEventId($record->event_id);
                    $this->projector->project($projectedEvent);
                    $record->update(['projection_status' => 'projected', 'projected_at' => now()]);

                    return $record->fresh();
                });
            }

            $message->update(['parse_status' => 'projected']);
            $this->completeRun($run, received: 1, succeeded: 1, cursorAfter: $cursorBefore);

            return $this->receipt($run, $message, $records, duplicate: ! $messageCreated);
        } catch (AncillaryIngestException $exception) {
            $this->fail($run, $message, $messageCreated, $exception);

            throw $exception;
        } catch (Throwable $exception) {
            $safe = new AncillaryIngestException(
                'ancillary_pipeline_failed',
                'The ancillary message could not be accepted into the canonical pipeline.',
                previous: $exception,
            );
            $this->fail($run, $message, $messageCreated, $safe);

            throw $safe;
        }
    }

    private function recordRawMessage(
        Source $source,
        IngestRun $run,
        SourceMessage $sourceMessage,
        string $payloadHash,
        string $idempotencyKey,
    ): InboundMessage {
        $sourceId = (int) $source->source_id;
        $existing = InboundMessage::query()
            ->where('source_id', $sourceId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing !== null) {
            if ($existing->payload_object_id === null) {
                $stored = $this->payloads->storeJson($sourceId, 'raw_message', $sourceMessage->payload);
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

        $stored = $this->payloads->storeJson($sourceId, 'raw_message', $sourceMessage->payload);
        try {
            return InboundMessage::query()->create([
                'message_uuid' => (string) Str::uuid(),
                'source_id' => $sourceId,
                'ingest_run_id' => $run->ingest_run_id,
                'message_type' => substr($sourceMessage->messageType, 0, 120),
                'external_id' => $this->boundedNullable($sourceMessage->externalId, 190),
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'payload' => null,
                'payload_object_id' => $stored->payloadObjectId,
                'received_at' => $sourceMessage->receivedAt ?? now(),
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

            return $existing;
        } catch (Throwable $exception) {
            $this->discardPayload($stored->payloadObjectId, $sourceId, 'raw_message_insert_failed');

            throw $exception;
        }
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

    private function authorizedSource(string $sourceKey): Source
    {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{0,159}$/', $sourceKey)) {
            throw new AncillaryIngestException('integration_source_forbidden', 'The ancillary integration source is not authorized.', 'source_authorization');
        }

        $source = Source::query()->where('source_key', $sourceKey)->first();
        $configuration = $source?->metadata['ancillary_ingest'] ?? [];
        $valid = $source
            && $source->active_status === 'active'
            && $source->phi_allowed
            && ($configuration['enabled'] ?? false) === true
            && is_array($configuration['message_families'] ?? null)
            && ($configuration['message_families'] ?? []) !== [];
        if ($valid && app()->environment('production')) {
            $valid = $source->environment === 'production' && $source->go_live_status === 'live';
        }

        if (! $valid) {
            throw new AncillaryIngestException('integration_source_forbidden', 'The ancillary integration source is not authorized.', 'source_authorization');
        }

        return $source;
    }

    private function startRun(Source $source, string $runType, ?string $cursorBefore): IngestRun
    {
        return IngestRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'source_id' => $source->source_id,
            'connector_key' => self::CONNECTOR_KEY,
            'run_type' => substr($runType, 0, 80),
            'status' => 'running',
            'started_at' => now(),
            'cursor_before' => $cursorBefore,
            'metadata' => ['source_protocol' => 'governed_boundary'],
        ]);
    }

    private function completeRun(
        IngestRun $run,
        int $received,
        int $succeeded = 0,
        int $skipped = 0,
        ?string $cursorAfter = null,
    ): void {
        $run->update([
            'status' => 'completed',
            'completed_at' => now(),
            'messages_received' => $received,
            'messages_succeeded' => $succeeded,
            'messages_failed' => 0,
            'messages_skipped' => $skipped,
            'cursor_after' => $cursorAfter,
        ]);
    }

    private function fail(IngestRun $run, ?InboundMessage $message, bool $messageCreated, AncillaryIngestException $exception): void
    {
        if ($message !== null && $messageCreated) {
            $message->update(['parse_status' => 'failed']);
        }
        $run->update([
            'status' => 'failed',
            'completed_at' => now(),
            'messages_received' => $message !== null ? 1 : 0,
            'messages_succeeded' => 0,
            'messages_failed' => 1,
            'messages_skipped' => 0,
            'error_summary' => $exception->reasonCode,
        ]);

        DeadLetter::query()->create([
            'dead_letter_uuid' => (string) Str::uuid(),
            'source_id' => $run->source_id,
            'ingest_run_id' => $run->ingest_run_id,
            'inbound_message_id' => $message?->inbound_message_id,
            'failure_stage' => $exception->failureStage,
            'reason_code' => $exception->reasonCode,
            'message' => $exception->getMessage(),
            'exception_class' => $exception::class,
            'context' => [
                'connector_key' => self::CONNECTOR_KEY,
                ...$exception->context,
            ],
            'status' => 'open',
            'metadata' => [],
        ]);
    }

    private function idempotencyKey(SourceMessage $message, string $payloadHash): string
    {
        $requested = trim((string) ($message->metadata['idempotency_key'] ?? ''));
        if ($requested !== '') {
            if (strlen($requested) > 190 || ! preg_match('/^[A-Za-z0-9._:-]+$/', $requested)) {
                throw new AncillaryIngestException('invalid_idempotency_key', 'The ancillary idempotency key is invalid.');
            }

            return $requested;
        }

        return 'sha256:'.$payloadHash;
    }

    private function boundedNullable(?string $value, int $length): ?string
    {
        return $value !== null && $value !== '' ? substr($value, 0, $length) : null;
    }

    /**
     * @param  list<CanonicalEventRecord>  $records
     * @return array{accepted: true, duplicate: bool, status: string, run_id: string, message_id: string, canonical_event_ids: list<string>}
     */
    private function receipt(IngestRun $run, InboundMessage $message, array $records, bool $duplicate): array
    {
        return [
            'accepted' => true,
            'duplicate' => $duplicate,
            'status' => 'projected',
            'run_id' => (string) $run->run_uuid,
            'message_id' => (string) $message->message_uuid,
            'canonical_event_ids' => array_values(array_map(fn (CanonicalEventRecord $record): string => (string) $record->event_id, $records)),
        ];
    }
}
