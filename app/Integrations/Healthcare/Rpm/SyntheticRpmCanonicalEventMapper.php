<?php

namespace App\Integrations\Healthcare\Rpm;

use App\Integrations\Healthcare\Contracts\CanonicalEventMapper;
use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Integrations\Healthcare\DTO\NormalizedPayload;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Maps normalized RPM device messages onto canonical events. The wire payload
 * is pseudonymous (patient_ref + LOINC + value); device identity travels as a
 * serial number resolved to prod.rpm_devices at projection time.
 */
class SyntheticRpmCanonicalEventMapper implements CanonicalEventMapper
{
    public function map(NormalizedPayload $payload): array
    {
        $data = $payload->payload;
        $eventType = $payload->eventType;
        $occurredAt = CanonicalOperationalEvent::occurredAt($payload->occurredAt);
        $eventId = (string) ($data['event_id'] ?? Str::uuid());

        return match ($eventType) {
            RpmEventVocabulary::OBSERVATION_RECORDED => [
                new CanonicalOperationalEvent(
                    eventId: $eventId,
                    eventType: $eventType,
                    entityType: 'rpm_observation',
                    entityRef: $this->requiredString($data, 'patient_ref'),
                    payload: [
                        'patient_ref' => $this->requiredString($data, 'patient_ref'),
                        'loinc_code' => $this->requiredString($data, 'loinc_code'),
                        'display' => $data['display'] ?? null,
                        'value' => $this->requiredNumeric($data, 'value'),
                        'unit' => $data['unit'] ?? null,
                        'observed_at' => $occurredAt->toIso8601String(),
                        'transmission_id' => $data['transmission_id'] ?? $payload->externalId,
                        'device_serial' => $data['device_serial'] ?? null,
                        'quality_flag' => $data['quality_flag'] ?? 'ok',
                    ],
                    occurredAt: $occurredAt,
                    idempotencyKey: "{$payload->idempotencyKey}:{$eventType}",
                    correlationId: $data['correlation_id'] ?? null,
                    sequenceKey: $data['sequence_key'] ?? null,
                    metadata: ['source_message_type' => $payload->messageType],
                ),
            ],
            RpmEventVocabulary::DEVICE_STATUS_CHANGED => [
                new CanonicalOperationalEvent(
                    eventId: $eventId,
                    eventType: $eventType,
                    entityType: 'rpm_device',
                    entityRef: $this->requiredString($data, 'device_serial'),
                    payload: [
                        'device_serial' => $this->requiredString($data, 'device_serial'),
                        'status' => $data['status'] ?? null,
                        'battery_pct' => isset($data['battery_pct']) ? (int) $data['battery_pct'] : null,
                        'occurred_at' => $occurredAt->toIso8601String(),
                    ],
                    occurredAt: $occurredAt,
                    idempotencyKey: "{$payload->idempotencyKey}:{$eventType}",
                    correlationId: $data['correlation_id'] ?? null,
                    sequenceKey: $data['sequence_key'] ?? null,
                    metadata: ['source_message_type' => $payload->messageType],
                ),
            ],
            default => throw new InvalidArgumentException("Unsupported RPM event type [{$eventType}]."),
        };
    }

    private function requiredString(array $data, string $key): string
    {
        $value = trim((string) ($data[$key] ?? ''));
        if ($value === '') {
            throw new InvalidArgumentException("RPM payload is missing required field [{$key}].");
        }

        return $value;
    }

    private function requiredNumeric(array $data, string $key): float
    {
        if (! isset($data[$key]) || ! is_numeric($data[$key])) {
            throw new InvalidArgumentException("RPM payload is missing numeric field [{$key}].");
        }

        return (float) $data[$key];
    }
}
