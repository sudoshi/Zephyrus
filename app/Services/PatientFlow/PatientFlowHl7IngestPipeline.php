<?php

namespace App\Services\PatientFlow;

use App\Exceptions\PatientFlowIngestException;
use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Integrations\Healthcare\Services\CanonicalEventWriter;
use App\Models\Integration\CanonicalEventRecord;
use App\Models\Integration\ProvenanceRecord;
use App\Models\Integration\Source;
use App\Models\Raw\DeadLetter;
use App\Models\Raw\InboundMessage;
use App\Models\Raw\IngestRun;
use App\Observability\MetricRecorder;
use App\Security\ClinicalPayloads\ClinicalPayloadStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Raw -> canonical -> Patient Flow projection pipeline for machine-delivered ADT. */
class PatientFlowHl7IngestPipeline
{
    private const CONNECTOR_KEY = 'patient-flow.hl7v2.adt';

    public function __construct(
        private readonly FlowEventNormalizer $normalizer,
        private readonly FlowEventRepository $events,
        private readonly CanonicalEventWriter $canonicalEvents,
        private readonly ClinicalPayloadStore $payloads,
        private readonly MetricRecorder $metrics,
    ) {}

    /**
     * @return array{accepted: true, duplicate: bool, status: string, run_id: string, message_id: string, canonical_event_id: string}
     */
    public function ingest(
        string $sourceKey,
        string $rawHl7,
        ?string $requestedIdempotencyKey = null,
        array $requestMetadata = [],
    ): array {
        $startedAt = hrtime(true);
        $attributes = ['zephyrus.connector.key' => self::CONNECTOR_KEY];
        $requestId = $requestMetadata['request_id'] ?? null;
        if (is_string($requestId) && Str::isUuid($requestId)) {
            $attributes['zephyrus.correlation.uuid'] = $requestId;
        }

        try {
            $result = $this->performIngest($sourceKey, $rawHl7, $requestedIdempotencyKey, $requestMetadata);
            $receipt = $result['receipt'];
            $this->metrics->span(
                'zephyrus.integration.hl7.receipt_to_projection',
                'ok',
                $this->durationMs($startedAt),
                [
                    ...$attributes,
                    'zephyrus.source.id' => $result['sourceId'],
                    'zephyrus.run.uuid' => $receipt['run_id'],
                    'zephyrus.message.uuid' => $receipt['message_id'],
                    'zephyrus.event.uuid' => $receipt['canonical_event_id'],
                    'zephyrus.outcome' => $receipt['duplicate'] ? 'duplicate' : 'projected',
                ],
            );

            return $receipt;
        } catch (Throwable $exception) {
            $this->metrics->span(
                'zephyrus.integration.hl7.receipt_to_projection',
                'error',
                $this->durationMs($startedAt),
                [
                    ...$attributes,
                    'error.type' => $exception instanceof PatientFlowIngestException
                        ? $exception->errorCode
                        : 'hl7_ingest_failed',
                ],
            );

            throw $exception;
        }
    }

    /**
     * @return array{receipt: array{accepted: true, duplicate: bool, status: string, run_id: string, message_id: string, canonical_event_id: string}, sourceId: int}
     */
    private function performIngest(
        string $sourceKey,
        string $rawHl7,
        ?string $requestedIdempotencyKey,
        array $requestMetadata,
    ): array {
        $source = $this->authorizedSource($sourceKey);
        $payloadHash = hash('sha256', $rawHl7);
        $idempotencyKey = $this->idempotencyKey($requestedIdempotencyKey, $payloadHash);
        $run = $this->startRun($source, $requestMetadata);
        $message = null;
        $messageCreated = false;

        try {
            $message = InboundMessage::query()
                ->where('source_id', $source->source_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($message === null) {
                $stored = $this->payloads->storeJson(
                    (int) $source->source_id,
                    'raw_message',
                    ['raw_hl7' => $rawHl7],
                );
                try {
                    $message = InboundMessage::create([
                        'message_uuid' => (string) Str::uuid(),
                        'source_id' => $source->source_id,
                        'ingest_run_id' => $run->ingest_run_id,
                        'message_type' => 'HL7V2_ADT',
                        'external_id' => null,
                        'idempotency_key' => $idempotencyKey,
                        'payload_hash' => $payloadHash,
                        'payload' => null,
                        'payload_object_id' => $stored->payloadObjectId,
                        'received_at' => now(),
                        'parse_status' => 'received',
                        'metadata' => ['connector_key' => self::CONNECTOR_KEY],
                    ]);
                    $messageCreated = true;
                } catch (QueryException $exception) {
                    $this->discardPayload($stored->payloadObjectId, (int) $source->source_id, 'raw_message_insert_race');
                    $message = InboundMessage::query()
                        ->where('source_id', $source->source_id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();
                    if ($message === null) {
                        throw $exception;
                    }
                } catch (Throwable $exception) {
                    $this->discardPayload($stored->payloadObjectId, (int) $source->source_id, 'raw_message_insert_failed');

                    throw $exception;
                }
            }

            if (! hash_equals((string) $message->payload_hash, $payloadHash)) {
                throw new PatientFlowIngestException(
                    'idempotency_key_conflict',
                    'The idempotency key was already used with a different payload.',
                    409,
                );
            }
            if ($message->payload_object_id === null) {
                $stored = $this->payloads->storeJson(
                    (int) $source->source_id,
                    'raw_message',
                    ['raw_hl7' => $rawHl7],
                );
                try {
                    $message->update([
                        'payload' => null,
                        'payload_object_id' => $stored->payloadObjectId,
                    ]);
                } catch (Throwable $exception) {
                    $this->discardPayload($stored->payloadObjectId, (int) $source->source_id, 'raw_message_link_failed');

                    throw $exception;
                }
            }

            $existingCanonical = CanonicalEventRecord::query()
                ->where('inbound_message_id', $message->inbound_message_id)
                ->first();
            if (! $messageCreated && $existingCanonical?->projection_status === 'projected') {
                $this->completeRun($run, skipped: true);

                return [
                    'receipt' => $this->receipt($run, $message, $existingCanonical, true),
                    'sourceId' => (int) $source->source_id,
                ];
            }

            $parsed = Hl7V2Message::parse($rawHl7);
            if ($parsed->messageType() !== 'ADT') {
                throw new PatientFlowIngestException(
                    'unsupported_hl7_message',
                    'This ingress accepts HL7 v2 ADT messages only.',
                    422,
                );
            }

            $required = [
                'MSH-10 message control id' => $parsed->field('MSH', 10),
                'MSH-9 trigger event' => $parsed->triggerEvent(),
                'PID-3 patient identifier' => $parsed->field('PID', 3, 1),
                'PV1-19 visit identifier' => $parsed->field('PV1', 19, 1),
            ];
            $missing = array_keys(array_filter($required, fn (?string $value): bool => trim((string) $value) === ''));
            if ($missing !== []) {
                throw new PatientFlowIngestException(
                    'invalid_hl7_adt',
                    'The ADT message is missing required flow fields: '.implode(', ', $missing).'.',
                    422,
                );
            }

            $normalized = $this->normalizer->normalize($rawHl7, 'hl7v2');

            if ($message->normalized_payload_object_id === null) {
                $stored = $this->payloads->storeJson(
                    (int) $source->source_id,
                    'normalized_message',
                    $normalized,
                );
                try {
                    $message->update([
                        'message_type' => 'ADT^'.($normalized['trigger_event'] ?? 'UNKNOWN'),
                        'external_id' => $normalized['message_control_id'] ?? null,
                        'normalized_payload' => null,
                        'normalized_payload_object_id' => $stored->payloadObjectId,
                        'parse_status' => 'normalized',
                    ]);
                } catch (Throwable $exception) {
                    $this->discardPayload($stored->payloadObjectId, (int) $source->source_id, 'normalized_message_link_failed');

                    throw $exception;
                }
            } else {
                $message->update([
                    'message_type' => 'ADT^'.($normalized['trigger_event'] ?? 'UNKNOWN'),
                    'external_id' => $normalized['message_control_id'] ?? null,
                    'parse_status' => 'normalized',
                ]);
            }

            [$record] = DB::transaction(function () use ($source, $run, $message, $normalized, $idempotencyKey): array {
                $canonical = new CanonicalOperationalEvent(
                    eventId: (string) Str::uuid(),
                    eventType: 'patient_flow.adt.'.(string) $normalized['event_type'],
                    entityType: 'patient',
                    entityRef: (string) $normalized['patient_id'],
                    payload: $normalized,
                    occurredAt: CarbonImmutable::parse((string) $normalized['occurred_at']),
                    idempotencyKey: self::CONNECTOR_KEY.':'.$source->source_id.':'.$idempotencyKey,
                    correlationId: isset($normalized['message_control_id']) ? (string) $normalized['message_control_id'] : null,
                    sequenceKey: isset($normalized['encounter_id']) ? (string) $normalized['encounter_id'] : null,
                    metadata: [
                        'connector_key' => self::CONNECTOR_KEY,
                        'source_protocol' => 'hl7v2',
                    ],
                );

                $record = $this->canonicalEvents->write($canonical, $source, $run, $message);
                $flowEvent = $this->events->upsertNormalizedEvent(
                    $normalized,
                    (int) $source->source_id,
                    (int) $message->inbound_message_id,
                    (int) $record->canonical_event_id,
                    (string) config('facility_models.zep_500.facility_code', 'ZEPHYRUS-500'),
                );

                ProvenanceRecord::firstOrCreate(
                    [
                        'source_id' => $source->source_id,
                        'inbound_message_id' => $message->inbound_message_id,
                        'canonical_event_id' => $record->canonical_event_id,
                        'target_schema' => 'flow_core',
                        'target_table' => 'flow_events',
                        'target_pk' => $flowEvent->flow_event_id,
                    ],
                    [
                        'lineage' => [
                            'raw_message' => "raw.inbound_messages:{$message->inbound_message_id}",
                            'canonical_event' => "integration.canonical_events:{$record->canonical_event_id}",
                            'projection' => "flow_core.flow_events:{$flowEvent->flow_event_id}",
                        ],
                    ],
                );

                $record->update([
                    'projection_status' => 'projected',
                    'projected_at' => now(),
                ]);
                $message->update(['parse_status' => 'projected']);

                return [$record->fresh(), $flowEvent];
            });

            $this->completeRun($run);

            return [
                'receipt' => $this->receipt($run, $message, $record, ! $messageCreated),
                'sourceId' => (int) $source->source_id,
            ];
        } catch (PatientFlowIngestException $exception) {
            $this->fail($run, $message, $messageCreated, $exception);

            throw $exception;
        } catch (Throwable $exception) {
            $this->fail($run, $message, $messageCreated, $exception);

            throw new PatientFlowIngestException(
                'hl7_ingest_failed',
                'The HL7 message could not be accepted into the canonical pipeline.',
                422,
            );
        }
    }

    private function authorizedSource(string $sourceKey): Source
    {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{0,159}$/', $sourceKey)) {
            throw new PatientFlowIngestException('integration_source_forbidden', 'The integration source is not authorized.', 403);
        }

        $source = Source::query()->where('source_key', $sourceKey)->first();
        $valid = $source
            && $source->interface_type === 'hl7v2'
            && $source->active_status === 'active'
            && $source->phi_allowed;

        if ($valid && app()->environment('production')) {
            $valid = $source->environment === 'production' && $source->go_live_status === 'live';
        }

        if (! $valid) {
            throw new PatientFlowIngestException('integration_source_forbidden', 'The integration source is not authorized.', 403);
        }

        return $source;
    }

    private function idempotencyKey(?string $requested, string $payloadHash): string
    {
        $requested = trim((string) $requested);
        if ($requested === '') {
            return 'sha256:'.$payloadHash;
        }

        if (strlen($requested) > 190 || ! preg_match('/^[A-Za-z0-9._:-]+$/', $requested)) {
            throw new PatientFlowIngestException(
                'invalid_idempotency_key',
                'Idempotency-Key must be 1-190 URL-safe characters.',
                422,
            );
        }

        return $requested;
    }

    private function startRun(Source $source, array $metadata): IngestRun
    {
        return IngestRun::create([
            'run_uuid' => (string) Str::uuid(),
            'source_id' => $source->source_id,
            'connector_key' => self::CONNECTOR_KEY,
            'run_type' => 'machine_ingress',
            'status' => 'running',
            'started_at' => now(),
            'metadata' => $metadata,
        ]);
    }

    private function completeRun(IngestRun $run, bool $skipped = false): void
    {
        $run->update([
            'status' => 'completed',
            'completed_at' => now(),
            'messages_received' => 1,
            'messages_succeeded' => $skipped ? 0 : 1,
            'messages_failed' => 0,
            'messages_skipped' => $skipped ? 1 : 0,
        ]);
    }

    private function fail(IngestRun $run, ?InboundMessage $message, bool $messageCreated, Throwable $exception): void
    {
        if ($message && $messageCreated) {
            $message->update(['parse_status' => 'failed']);
        }

        $run->update([
            'status' => 'failed',
            'completed_at' => now(),
            'messages_received' => $message ? 1 : 0,
            'messages_succeeded' => 0,
            'messages_failed' => 1,
            'error_summary' => $exception instanceof PatientFlowIngestException
                ? $exception->errorCode
                : 'pipeline_exception',
        ]);

        DeadLetter::create([
            'dead_letter_uuid' => (string) Str::uuid(),
            'source_id' => $run->source_id,
            'ingest_run_id' => $run->ingest_run_id,
            'inbound_message_id' => $message?->inbound_message_id,
            'failure_stage' => 'patient_flow_ingress',
            'reason_code' => $exception instanceof PatientFlowIngestException
                ? $exception->errorCode
                : 'pipeline_exception',
            'message' => $exception instanceof PatientFlowIngestException
                ? $exception->getMessage()
                : 'Patient Flow canonical ingestion failed.',
            'exception_class' => $exception::class,
            'context' => ['connector_key' => self::CONNECTOR_KEY],
            'status' => 'open',
            'metadata' => [],
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

    private function durationMs(int $startedAt): int
    {
        return max(0, min(86_400_000, (int) ((hrtime(true) - $startedAt) / 1_000_000)));
    }

    /**
     * @return array{accepted: true, duplicate: bool, status: string, run_id: string, message_id: string, canonical_event_id: string}
     */
    private function receipt(
        IngestRun $run,
        InboundMessage $message,
        CanonicalEventRecord $record,
        bool $duplicate,
    ): array {
        $receiptRun = $duplicate
            ? (IngestRun::query()->find($message->ingest_run_id) ?? $run)
            : $run;

        return [
            'accepted' => true,
            'duplicate' => $duplicate,
            'status' => 'projected',
            'run_id' => (string) $receiptRun->run_uuid,
            'message_id' => (string) $message->message_uuid,
            'canonical_event_id' => (string) $record->event_id,
        ];
    }
}
