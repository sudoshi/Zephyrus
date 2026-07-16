<?php

namespace App\Services\PatientFlow;

use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Integrations\Healthcare\Services\CanonicalEventWriter;
use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Models\Integration\CanonicalEventRecord;
use App\Models\Integration\Source;
use App\Models\Raw\InboundMessage;
use App\Models\Raw\IngestRun;
use App\Security\ClinicalPayloads\ClinicalPayloadException;
use App\Security\ClinicalPayloads\ClinicalPayloadStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

class SyntheticFlowImporter
{
    public function __construct(
        private readonly FlowEventNormalizer $normalizer,
        private readonly FlowEventRepository $events,
        private readonly SourceRegistryService $sources,
        private readonly CanonicalEventWriter $canonicalEvents,
        private readonly ClinicalPayloadStore $payloads,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function import(string $path, array $options = []): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Synthetic flow file is not readable: {$path}");
        }

        $sourceKey = (string) ($options['source_key'] ?? 'synthetic-flow-ehr');
        $facilityCode = (string) ($options['facility_code'] ?? 'ZEPHYRUS-500');
        $fromNormalized = (bool) ($options['from_normalized'] ?? false);
        $dryRun = (bool) ($options['dry_run'] ?? false);

        $rows = $this->readNdjson($path);
        $summary = [
            'rows_read' => count($rows),
            'source_id' => null,
            'ingest_run_id' => null,
            'raw_messages_inserted' => 0,
            'raw_messages_skipped' => 0,
            'canonical_events_inserted' => 0,
            'canonical_events_skipped' => 0,
            'flow_events_inserted' => 0,
            'flow_events_skipped' => 0,
            'patients' => 0,
            'encounters' => 0,
            'mapped_locations' => 0,
            'unmapped_locations' => 0,
            'min_occurred_at' => null,
            'max_occurred_at' => null,
            'dry_run' => $dryRun,
        ];

        if ($dryRun) {
            return $summary;
        }

        $source = $this->upsertSource($sourceKey, $facilityCode);
        $run = IngestRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'source_id' => $source->source_id,
            'connector_key' => 'patient-flow-synthetic-import',
            'run_type' => $fromNormalized ? 'normalized_ndjson' : 'hl7v2_ndjson',
            'status' => 'running',
            'started_at' => now(),
            'metadata' => ['facility_code' => $facilityCode],
        ]);

        $summary['source_id'] = (int) $source->source_id;
        $summary['ingest_run_id'] = (int) $run->ingest_run_id;

        try {
            foreach ($rows as $row) {
                $normalized = $fromNormalized ? $row : $this->normalizer->normalize((string) ($row['raw_hl7'] ?? ''));
                $idempotency = $fromNormalized
                    ? 'flow-event:'.($normalized['event_id'] ?? FlowEventNormalizer::stableHash(json_encode($normalized, JSON_THROW_ON_ERROR)))
                    : 'hl7v2:'.($normalized['message_control_id'] ?? FlowEventNormalizer::stableHash((string) ($row['raw_hl7'] ?? '')));

                $inbound = $this->upsertInboundMessage($source, $run, $row, $normalized, $idempotency, $fromNormalized);
                $summary[$inbound->wasRecentlyCreated ? 'raw_messages_inserted' : 'raw_messages_skipped']++;

                $canonical = $this->upsertCanonicalEvent($source, $run, $inbound, $normalized);
                $summary[$canonical->wasRecentlyCreated ? 'canonical_events_inserted' : 'canonical_events_skipped']++;

                $existed = DB::table('flow_core.flow_events')->where('flow_event_id', $normalized['event_id'])->exists();
                $flowEvent = $this->events->upsertNormalizedEvent(
                    $normalized,
                    (int) $source->source_id,
                    (int) $inbound->inbound_message_id,
                    (int) $canonical->canonical_event_id,
                    $facilityCode,
                );
                $summary[$existed ? 'flow_events_skipped' : 'flow_events_inserted']++;

                if ($flowEvent->to_facility_space_id) {
                    $summary['mapped_locations']++;
                } else {
                    $summary['unmapped_locations']++;
                }

                $summary['min_occurred_at'] = $this->minTimestamp($summary['min_occurred_at'], $normalized['occurred_at']);
                $summary['max_occurred_at'] = $this->maxTimestamp($summary['max_occurred_at'], $normalized['occurred_at']);
            }

            $run->update([
                'status' => 'completed',
                'completed_at' => now(),
                'messages_received' => count($rows),
                'messages_succeeded' => $summary['flow_events_inserted'] + $summary['flow_events_skipped'],
                'messages_skipped' => $summary['flow_events_skipped'],
                'messages_failed' => 0,
                'metadata' => ['facility_code' => $facilityCode, 'summary' => $summary],
            ]);

            $summary['patients'] = (int) DB::table('flow_core.patient_identities')->count();
            $summary['encounters'] = (int) DB::table('flow_core.encounters')->count();

            return $summary;
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'completed_at' => now(),
                'messages_failed' => 1,
                'error_summary' => 'synthetic_import_failed',
            ]);

            throw $exception;
        }
    }

    private function upsertSource(string $sourceKey, string $facilityCode): Source
    {
        return $this->sources->ensureSource([
            'source_key' => $sourceKey,
            'tenant_key' => 'default',
            'facility_key' => $facilityCode,
            'source_name' => 'Synthetic Flow EHR',
            'vendor' => 'synthetic',
            'system_class' => 'ehr',
            'environment' => 'sandbox',
            'interface_type' => 'hl7v2_file',
            'active_status' => 'active',
            'contract_status' => 'not_required',
            'baa_status' => 'not_required',
            'phi_allowed' => false,
            'go_live_status' => 'demo',
            'metadata' => ['purpose' => 'patient-flow-4d-navigator-demo'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $normalized
     */
    private function upsertInboundMessage(Source $source, IngestRun $run, array $row, array $normalized, string $idempotency, bool $fromNormalized): InboundMessage
    {
        $payload = $fromNormalized ? $normalized : ['raw_hl7' => $row['raw_hl7'] ?? null];
        $payloadHash = FlowEventNormalizer::stableHash(json_encode($payload, JSON_THROW_ON_ERROR), 64);

        $existing = InboundMessage::query()
            ->where('source_id', $source->source_id)
            ->where('idempotency_key', $idempotency)
            ->first();
        if ($existing !== null) {
            if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                throw new ClinicalPayloadException('raw_message_idempotency_conflict');
            }
            $this->protectExistingInbound($existing, $payload, $normalized);

            return $existing;
        }

        $rawObject = $this->payloads->storeJson((int) $source->source_id, 'raw_message', $payload);
        try {
            $normalizedObject = $this->payloads->storeJson(
                (int) $source->source_id,
                'normalized_message',
                $normalized,
            );
        } catch (Throwable $exception) {
            $this->discardPayload($rawObject->payloadObjectId, (int) $source->source_id, 'synthetic_import_pair_failed');

            throw $exception;
        }

        try {
            return InboundMessage::query()->create([
                'message_uuid' => (string) Str::uuid(),
                'source_id' => $source->source_id,
                'ingest_run_id' => $run->ingest_run_id,
                'message_type' => (string) ($normalized['message_type'] ?? 'UNKNOWN'),
                'external_id' => (string) ($normalized['message_control_id'] ?? $normalized['event_id']),
                'idempotency_key' => $idempotency,
                'payload_hash' => $payloadHash,
                'payload' => null,
                'payload_object_id' => $rawObject->payloadObjectId,
                'normalized_payload' => null,
                'normalized_payload_object_id' => $normalizedObject->payloadObjectId,
                'received_at' => now(),
                'parse_status' => 'parsed',
                'metadata' => [
                    'trigger_event' => $normalized['trigger_event'] ?? null,
                    'synthetic' => true,
                ],
            ]);
        } catch (QueryException $exception) {
            $this->discardPayload($rawObject->payloadObjectId, (int) $source->source_id, 'synthetic_import_insert_race');
            $this->discardPayload($normalizedObject->payloadObjectId, (int) $source->source_id, 'synthetic_import_insert_race');
            $existing = InboundMessage::query()
                ->where('source_id', $source->source_id)
                ->where('idempotency_key', $idempotency)
                ->first();
            if ($existing === null) {
                throw $exception;
            }
            if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                throw new ClinicalPayloadException('raw_message_idempotency_conflict');
            }

            return $existing;
        } catch (Throwable $exception) {
            $this->discardPayload($rawObject->payloadObjectId, (int) $source->source_id, 'synthetic_import_insert_failed');
            $this->discardPayload($normalizedObject->payloadObjectId, (int) $source->source_id, 'synthetic_import_insert_failed');

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function upsertCanonicalEvent(Source $source, IngestRun $run, InboundMessage $inbound, array $normalized): CanonicalEventRecord
    {
        $idempotency = 'flow:'.$normalized['event_id'];
        $record = $this->canonicalEvents->write(
            new CanonicalOperationalEvent(
                eventId: (string) Str::uuid(),
                eventType: 'patient_flow.'.$normalized['event_type'],
                entityType: 'encounter',
                entityRef: (string) $normalized['encounter_id'],
                payload: $normalized,
                occurredAt: CarbonImmutable::parse((string) $normalized['occurred_at']),
                idempotencyKey: $idempotency,
                correlationId: (string) $normalized['encounter_id'],
                causationId: isset($normalized['message_control_id']) ? (string) $normalized['message_control_id'] : null,
                sequenceKey: (string) $normalized['patient_id'],
                metadata: ['source_protocol' => $normalized['source_protocol'] ?? 'hl7v2'],
            ),
            $source,
            $run,
            $inbound,
        );
        $record->update(['projection_status' => 'projected', 'projected_at' => now()]);

        return $record;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $rawPayload
     * @param  array<string, mixed>|list<mixed>  $normalizedPayload
     */
    private function protectExistingInbound(InboundMessage $message, array $rawPayload, array $normalizedPayload): void
    {
        $changes = [];
        $createdObjectIds = [];

        try {
            if ($message->payload_object_id === null) {
                $stored = $this->payloads->storeJson((int) $message->source_id, 'raw_message', $rawPayload);
                $createdObjectIds[] = $stored->payloadObjectId;
                $changes['payload'] = null;
                $changes['payload_object_id'] = $stored->payloadObjectId;
            }
            if ($message->normalized_payload_object_id === null) {
                $stored = $this->payloads->storeJson(
                    (int) $message->source_id,
                    'normalized_message',
                    $normalizedPayload,
                );
                $createdObjectIds[] = $stored->payloadObjectId;
                $changes['normalized_payload'] = null;
                $changes['normalized_payload_object_id'] = $stored->payloadObjectId;
            }
            if ($changes !== []) {
                $message->update($changes);
            }
        } catch (Throwable $exception) {
            foreach ($createdObjectIds as $payloadObjectId) {
                $this->discardPayload($payloadObjectId, (int) $message->source_id, 'synthetic_import_link_failed');
            }

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

    /**
     * @return list<array<string, mixed>>
     *
     * @throws JsonException
     */
    private function readNdjson(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        if (! $handle) {
            throw new RuntimeException("Unable to open synthetic flow file: {$path}");
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        fclose($handle);

        return $rows;
    }

    private function minTimestamp(?string $current, string $candidate): string
    {
        if ($current === null) {
            return $candidate;
        }

        return CarbonImmutable::parse($candidate)->lessThan(CarbonImmutable::parse($current)) ? $candidate : $current;
    }

    private function maxTimestamp(?string $current, string $candidate): string
    {
        if ($current === null) {
            return $candidate;
        }

        return CarbonImmutable::parse($candidate)->greaterThan(CarbonImmutable::parse($current)) ? $candidate : $current;
    }
}
