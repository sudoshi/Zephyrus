<?php

namespace App\Integrations\Healthcare\Synthetic;

use App\Integrations\Healthcare\Ancillary\AncillaryEventVocabulary;
use App\Integrations\Healthcare\Contracts\CanonicalEventMapper;
use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Integrations\Healthcare\DTO\NormalizedPayload;
use App\Rtdc\Events\CanonicalEvent as RtdcCanonicalEvent;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SyntheticCanonicalEventMapper implements CanonicalEventMapper
{
    public function map(NormalizedPayload $payload): array
    {
        $data = $payload->payload;
        $eventType = $payload->eventType;
        $occurredAt = CanonicalOperationalEvent::occurredAt($payload->occurredAt);
        $eventId = (string) ($data['event_id'] ?? Str::uuid());

        return match ($eventType) {
            RtdcCanonicalEvent::ENCOUNTER_STARTED => [
                new CanonicalOperationalEvent(
                    eventId: $eventId,
                    eventType: $eventType,
                    entityType: 'encounter',
                    entityRef: $this->requiredString($data, 'patient_ref'),
                    payload: [
                        'unit_id' => $this->requiredInt($data, 'unit_id'),
                        'bed_id' => $this->nullableInt($data, 'bed_id'),
                        'acuity_tier' => $this->requiredInt($data, 'acuity_tier'),
                    ],
                    occurredAt: $occurredAt,
                    idempotencyKey: "{$payload->idempotencyKey}:{$eventType}",
                    correlationId: $data['correlation_id'] ?? null,
                    sequenceKey: $data['sequence_key'] ?? null,
                    metadata: ['source_message_type' => $payload->messageType],
                ),
            ],
            RtdcCanonicalEvent::ENCOUNTER_TRANSFERRED => [
                new CanonicalOperationalEvent(
                    eventId: $eventId,
                    eventType: $eventType,
                    entityType: 'encounter',
                    entityRef: $this->requiredString($data, 'patient_ref'),
                    payload: [
                        'to_unit_id' => $this->requiredInt($data, 'to_unit_id'),
                        'to_bed_id' => $this->nullableInt($data, 'to_bed_id'),
                    ],
                    occurredAt: $occurredAt,
                    idempotencyKey: "{$payload->idempotencyKey}:{$eventType}",
                    correlationId: $data['correlation_id'] ?? null,
                    sequenceKey: $data['sequence_key'] ?? null,
                    metadata: ['source_message_type' => $payload->messageType],
                ),
            ],
            RtdcCanonicalEvent::ENCOUNTER_DISCHARGED => [
                new CanonicalOperationalEvent(
                    eventId: $eventId,
                    eventType: $eventType,
                    entityType: 'encounter',
                    entityRef: $this->requiredString($data, 'patient_ref'),
                    payload: [],
                    occurredAt: $occurredAt,
                    idempotencyKey: "{$payload->idempotencyKey}:{$eventType}",
                    correlationId: $data['correlation_id'] ?? null,
                    sequenceKey: $data['sequence_key'] ?? null,
                    metadata: ['source_message_type' => $payload->messageType],
                ),
            ],
            RtdcCanonicalEvent::BED_STATUS_CHANGED => [
                new CanonicalOperationalEvent(
                    eventId: $eventId,
                    eventType: $eventType,
                    entityType: 'bed',
                    entityRef: (string) $this->requiredInt($data, 'bed_id'),
                    payload: [
                        'bed_id' => $this->requiredInt($data, 'bed_id'),
                        'status' => $this->requiredString($data, 'status'),
                    ],
                    occurredAt: $occurredAt,
                    idempotencyKey: "{$payload->idempotencyKey}:{$eventType}",
                    correlationId: $data['correlation_id'] ?? null,
                    sequenceKey: $data['sequence_key'] ?? null,
                    metadata: ['source_message_type' => $payload->messageType],
                ),
            ],
            RtdcCanonicalEvent::ACUITY_CHANGED => [
                new CanonicalOperationalEvent(
                    eventId: $eventId,
                    eventType: $eventType,
                    entityType: 'encounter',
                    entityRef: $this->requiredString($data, 'patient_ref'),
                    payload: ['acuity_tier' => $this->requiredInt($data, 'acuity_tier')],
                    occurredAt: $occurredAt,
                    idempotencyKey: "{$payload->idempotencyKey}:{$eventType}",
                    correlationId: $data['correlation_id'] ?? null,
                    sequenceKey: $data['sequence_key'] ?? null,
                    metadata: ['source_message_type' => $payload->messageType],
                ),
            ],
            default => str_starts_with($eventType, 'ancillary.')
                ? $this->mapAncillary($payload, $eventId, $occurredAt)
                : throw new InvalidArgumentException("Unsupported synthetic event type [{$eventType}]."),
        };
    }

    /** @return list<CanonicalOperationalEvent> */
    private function mapAncillary(NormalizedPayload $payload, string $eventId, \Carbon\CarbonInterface $occurredAt): array
    {
        $data = $payload->payload;
        $milestoneCode = $this->requiredString($data, 'milestone_code');
        $department = $this->requiredString($data, 'department');
        if (AncillaryEventVocabulary::eventTypeFor($milestoneCode) !== $payload->eventType
            || AncillaryEventVocabulary::departmentFor($milestoneCode) !== $department) {
            throw new InvalidArgumentException('Ancillary event type, milestone code, and department do not agree.');
        }

        $safePayload = Arr::only($data, [
            'department', 'milestone_code', 'work_item_type', 'source_order_key',
            'reconciliation_key',
            'encounter_id', 'encounter_ref', 'patient_ref', 'patient_class', 'priority',
            'ordered_at', 'unit_id', 'demo_owner', 'modality', 'test_code',
            'test_family',
            'decision_class', 'route', 'preparation_branch', 'discharge_blocking',
            'correction', 'supersedes_assertion_key', 'source_timestamp_valid',
        ]);
        $safePayload['source_order_key'] = $this->requiredString($data, 'source_order_key');

        return [
            new CanonicalOperationalEvent(
                eventId: $eventId,
                eventType: $payload->eventType,
                entityType: 'ancillary_order',
                entityRef: $safePayload['source_order_key'],
                payload: $safePayload,
                occurredAt: $occurredAt,
                idempotencyKey: "{$payload->idempotencyKey}:{$payload->eventType}:{$milestoneCode}",
                correlationId: $data['correlation_id'] ?? null,
                sequenceKey: $safePayload['source_order_key'],
                metadata: ['source_message_type' => $payload->messageType],
            ),
        ];
    }

    private function requiredString(array $payload, string $key): string
    {
        $value = Arr::get($payload, $key);

        if ($value === null || $value === '') {
            throw new InvalidArgumentException("Missing required field [{$key}].");
        }

        return (string) $value;
    }

    private function requiredInt(array $payload, string $key): int
    {
        $value = Arr::get($payload, $key);

        if ($value === null || $value === '') {
            throw new InvalidArgumentException("Missing required field [{$key}].");
        }

        return (int) $value;
    }

    private function nullableInt(array $payload, string $key): ?int
    {
        $value = Arr::get($payload, $key);

        return $value === null || $value === '' ? null : (int) $value;
    }
}
