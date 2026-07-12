<?php

namespace App\Integrations\Healthcare\Ancillary;

use App\Integrations\Healthcare\Contracts\CanonicalEventMapper;
use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Integrations\Healthcare\DTO\NormalizedPayload;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AncillaryCanonicalEventMapper implements CanonicalEventMapper
{
    public function map(NormalizedPayload $payload): array
    {
        $groups = $payload->payload['order_groups'] ?? null;
        if (! is_array($groups) || $groups === []) {
            $groups = [$payload->payload];
        }

        $events = [];
        foreach (array_values($groups) as $index => $data) {
            if (! is_array($data)) {
                throw new InvalidArgumentException('Ancillary normalized order group must be an object.');
            }
            $events[] = $this->mapOne($payload, $data, $index);
        }

        return $events;
    }

    /** @param array<string, mixed> $data */
    private function mapOne(NormalizedPayload $payload, array $data, int $index): CanonicalOperationalEvent
    {
        $milestoneCode = $this->required($data, 'milestone_code');
        $department = $this->required($data, 'department');
        $sourceOrderKey = $this->required($data, 'source_order_key');
        $eventType = AncillaryEventVocabulary::eventTypeFor($milestoneCode);
        if (AncillaryEventVocabulary::departmentFor($milestoneCode) !== $department) {
            throw new InvalidArgumentException('Ancillary normalized event type, milestone code, and department do not agree.');
        }

        $safe = Arr::only($data, [
            'department', 'milestone_code', 'work_item_type', 'source_order_key', 'reconciliation_key',
            'encounter_id', 'encounter_ref', 'patient_ref', 'patient_class', 'priority', 'ordered_at',
            'unit_id', 'modality', 'test_code', 'test_family', 'decision_class', 'route',
            'preparation_branch', 'discharge_blocking', 'correction', 'supersedes_assertion_key',
            'source_timestamp_valid', 'source_exam_key', 'procedure_code', 'procedure_label',
            'body_region', 'subspecialty_code', 'protocol', 'contrast_status', 'is_portable', 'is_ir',
            'scheduled_start_at', 'scheduled_end_at', 'exam_status', 'degraded_fields',
            'read_status', 'source_read_key', 'source_report_version', 'radiologist_ref',
            'subspecialty_code', 'is_teleradiology', 'preliminary_at', 'final_at', 'corrected_at',
            'scanner_key', 'source_sop_instance_uid_hash', 'started_at', 'completed_at', 'cancelled_at',
            'transport_request_ref', 'relay_signature', 'critical_status', 'source_result_key',
            'finding_class', 'identified_at', 'notified_at', 'acknowledged_at', 'recipient_role',
            'placer_order_key', 'filler_order_key', 'order_control', 'order_status', 'test_label',
            'loinc_code', 'add_on', 'source_specimen_key', 'source_specimen_business_key',
            'source_accession_key', 'specimen_type', 'container_type', 'collector_role',
            'collector_ref', 'collection_method', 'collected_at', 'specimen_status',
            'collection_source', 'specimens',
            'ordered_at_source',
        ]);

        return new CanonicalOperationalEvent(
            eventId: (string) Str::uuid(),
            eventType: $eventType,
            entityType: 'ancillary_order',
            entityRef: $sourceOrderKey,
            payload: $safe,
            occurredAt: CanonicalOperationalEvent::occurredAt($data['occurred_at'] ?? $payload->occurredAt),
            idempotencyKey: "{$payload->idempotencyKey}:group:{$index}:{$eventType}:{$milestoneCode}:{$sourceOrderKey}",
            correlationId: $payload->externalId,
            sequenceKey: $sourceOrderKey,
            metadata: [
                ...$payload->metadata,
                'source_message_type' => $payload->messageType,
            ],
        );
    }

    /** @param array<string, mixed> $payload */
    private function required(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new InvalidArgumentException("Missing required normalized ancillary field [{$key}].");
        }

        return trim((string) $value);
    }
}
