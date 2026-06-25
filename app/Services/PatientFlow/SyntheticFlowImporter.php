<?php

namespace App\Services\PatientFlow;

use App\Models\Integration\CanonicalEventRecord;
use App\Models\Integration\Source;
use App\Models\Raw\InboundMessage;
use App\Models\Raw\IngestRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

class SyntheticFlowImporter
{
    public function __construct(
        private readonly FlowEventNormalizer $normalizer,
        private readonly FlowEventRepository $events,
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

        return DB::transaction(function () use ($rows, $sourceKey, $facilityCode, $fromNormalized, $summary): array {
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
        });
    }

    private function upsertSource(string $sourceKey, string $facilityCode): Source
    {
        return Source::query()->updateOrCreate(
            ['source_key' => $sourceKey],
            [
                'source_uuid' => (string) Str::uuid(),
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
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $normalized
     */
    private function upsertInboundMessage(Source $source, IngestRun $run, array $row, array $normalized, string $idempotency, bool $fromNormalized): InboundMessage
    {
        $payload = $fromNormalized ? $normalized : ['raw_hl7' => $row['raw_hl7'] ?? null];
        $payloadHash = FlowEventNormalizer::stableHash(json_encode($payload, JSON_THROW_ON_ERROR), 64);

        return InboundMessage::query()->updateOrCreate(
            ['source_id' => $source->source_id, 'idempotency_key' => $idempotency],
            [
                'message_uuid' => (string) Str::uuid(),
                'ingest_run_id' => $run->ingest_run_id,
                'message_type' => (string) ($normalized['message_type'] ?? 'UNKNOWN'),
                'external_id' => (string) ($normalized['message_control_id'] ?? $normalized['event_id']),
                'payload_hash' => $payloadHash,
                'payload' => $payload,
                'normalized_payload' => $normalized,
                'received_at' => now(),
                'parse_status' => 'parsed',
                'metadata' => [
                    'trigger_event' => $normalized['trigger_event'] ?? null,
                    'synthetic' => true,
                ],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function upsertCanonicalEvent(Source $source, IngestRun $run, InboundMessage $inbound, array $normalized): CanonicalEventRecord
    {
        $idempotency = 'flow:'.$normalized['event_id'];
        $payloadHash = FlowEventNormalizer::stableHash(json_encode($normalized, JSON_THROW_ON_ERROR), 64);

        return CanonicalEventRecord::query()->updateOrCreate(
            ['idempotency_key' => $idempotency],
            [
                'event_id' => (string) Str::uuid(),
                'source_id' => $source->source_id,
                'ingest_run_id' => $run->ingest_run_id,
                'inbound_message_id' => $inbound->inbound_message_id,
                'event_type' => 'patient_flow.'.$normalized['event_type'],
                'entity_type' => 'encounter',
                'entity_ref' => $normalized['encounter_id'],
                'occurred_at' => $normalized['occurred_at'],
                'received_at' => now(),
                'payload' => $normalized,
                'payload_hash' => $payloadHash,
                'correlation_id' => $normalized['encounter_id'],
                'causation_id' => $normalized['message_control_id'] ?? null,
                'sequence_key' => $normalized['patient_id'],
                'projection_status' => 'projected',
                'projected_at' => now(),
                'metadata' => ['source_protocol' => $normalized['source_protocol'] ?? 'hl7v2'],
            ],
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
