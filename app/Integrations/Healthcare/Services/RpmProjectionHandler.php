<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Contracts\ProjectionHandler;
use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;
use App\Integrations\Healthcare\Rpm\RpmEventVocabulary;
use App\Models\Home\RpmDevice;
use App\Models\Home\RpmEnrollment;
use App\Models\Home\RpmObservation;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * Projects canonical RPM events into the Home Hospital read models
 * (ACUM-PRD-HAH-001 §5.2): ObservationRecorded → prod.rpm_observations
 * (idempotent on observation_uuid, derived from the event idempotency key);
 * DeviceStatusChanged → device status/battery + kit last-seen. An observation
 * for a patient with no active enrollment is a mapping error — it dead-letters
 * upstream rather than silently attaching to the wrong episode.
 */
class RpmProjectionHandler implements ProjectionHandler
{
    public function __construct(
        private readonly \App\Services\Home\RpmAlertEvaluator $alerts,
        private readonly \App\Services\Home\RpmFhirObservationRecorder $fhir,
    ) {}

    public function key(): string
    {
        return 'home.rpm';
    }

    public function eventTypes(): array
    {
        return RpmEventVocabulary::eventTypes();
    }

    public function supports(CanonicalOperationalEvent $event): bool
    {
        return in_array($event->eventType, $this->eventTypes(), true);
    }

    public function project(CanonicalOperationalEvent $event): void
    {
        match ($event->eventType) {
            RpmEventVocabulary::OBSERVATION_RECORDED => $this->projectObservation($event),
            RpmEventVocabulary::DEVICE_STATUS_CHANGED => $this->projectDeviceStatus($event),
            default => throw new InvalidArgumentException("Unsupported RPM projection event [{$event->eventType}]."),
        };
    }

    private function projectObservation(CanonicalOperationalEvent $event): void
    {
        $payload = $event->payload;
        $patientRef = (string) ($payload['patient_ref'] ?? '');

        $enrollment = RpmEnrollment::query()
            ->where('patient_ref', $patientRef)
            ->where('status', 'active')
            ->where('is_deleted', false)
            ->orderByDesc('rpm_enrollment_id')
            ->first();

        if ($enrollment === null) {
            throw new InvalidArgumentException("No active RPM enrollment for patient_ref [{$patientRef}].");
        }

        $device = $this->deviceBySerial($payload['device_serial'] ?? null);

        $observation = RpmObservation::updateOrCreate(
            ['observation_uuid' => Uuid::uuid5(Uuid::NAMESPACE_DNS, 'zephyrus.rpm.observation.'.$event->idempotencyKey)->toString()],
            [
                'rpm_enrollment_id' => $enrollment->rpm_enrollment_id,
                'rpm_device_id' => $device?->rpm_device_id,
                'patient_ref' => $patientRef,
                'loinc_code' => (string) $payload['loinc_code'],
                'display' => $payload['display'] ?? null,
                'value' => (float) $payload['value'],
                'unit' => $payload['unit'] ?? null,
                'observed_at' => $payload['observed_at'] ?? $event->occurredAt,
                'received_at' => now(),
                'source_key' => 'synthetic.rpm',
                'transmission_id' => $payload['transmission_id'] ?? null,
                'quality_flag' => (string) ($payload['quality_flag'] ?? 'ok'),
                'metadata' => ['event_id' => $event->eventId],
            ]
        );

        if ($device !== null) {
            $device->update(['last_transmission_at' => $event->occurredAt]);
            $device->kit?->update(['last_seen_at' => $event->occurredAt]);
        }

        // Breach evaluation + FHIR persistence only on first projection — a
        // replay of the same transmission must not inflate breach counts or
        // mint duplicate resource versions (idempotent pipeline).
        if ($observation->wasRecentlyCreated) {
            $this->alerts->evaluate($observation, $enrollment);
            $this->fhir->recordForSourceKey($observation, (string) $observation->source_key);
        }
    }

    private function projectDeviceStatus(CanonicalOperationalEvent $event): void
    {
        $payload = $event->payload;
        $device = $this->deviceBySerial($payload['device_serial'] ?? null);

        if ($device === null) {
            throw new InvalidArgumentException('DeviceStatusChanged references an unknown device serial.');
        }

        $updates = ['last_transmission_at' => $event->occurredAt];
        if (isset($payload['status']) && $payload['status'] !== null) {
            $updates['status'] = (string) $payload['status'];
        }
        if (array_key_exists('battery_pct', $payload) && $payload['battery_pct'] !== null) {
            $updates['battery_pct'] = (int) $payload['battery_pct'];
        }

        $device->update($updates);
        $device->kit?->update(['last_seen_at' => $event->occurredAt]);
    }

    private function deviceBySerial(?string $serial): ?RpmDevice
    {
        if ($serial === null || trim($serial) === '') {
            return null;
        }

        return RpmDevice::query()
            ->where('serial_number', $serial)
            ->where('is_deleted', false)
            ->first();
    }
}
