<?php

namespace App\Services\PatientFlow;

use Carbon\CarbonImmutable;

class FlowEventNormalizer
{
    private const ADT_MOVEMENT_TYPES = [
        'A01' => 'admit',
        'A02' => 'transfer',
        'A03' => 'discharge',
        'A04' => 'register',
        'A05' => 'preadmit',
        'A06' => 'outpatient_to_inpatient',
        'A07' => 'inpatient_to_outpatient',
        'A08' => 'update',
        'A09' => 'departing_tracking',
        'A10' => 'arriving_tracking',
        'A11' => 'cancel_admit',
        'A12' => 'cancel_transfer',
        'A13' => 'cancel_discharge',
        'A40' => 'merge_patient',
    ];

    private const MESSAGE_CATEGORIES = [
        'ADT' => 'movement',
        'ORM' => 'order',
        'OML' => 'order',
        'ORU' => 'observation',
        'RDE' => 'medication',
        'RAS' => 'medication',
        'SIU' => 'schedule',
        'DFT' => 'financial',
        'MDM' => 'document',
    ];

    private const FHIR_ENCOUNTER_STATUS_BY_EVENT = [
        'admit' => 'in-progress',
        'register' => 'arrived',
        'preadmit' => 'planned',
        'transfer' => 'in-progress',
        'outpatient_to_inpatient' => 'in-progress',
        'inpatient_to_outpatient' => 'in-progress',
        'update' => 'in-progress',
        'departing_tracking' => 'in-progress',
        'arriving_tracking' => 'in-progress',
        'discharge' => 'finished',
        'cancel_admit' => 'cancelled',
        'cancel_transfer' => 'in-progress',
        'cancel_discharge' => 'in-progress',
    ];

    private const PATIENT_CLASS_TO_FHIR_CLASS = [
        'I' => 'inpatient',
        'E' => 'emergency',
        'O' => 'outpatient',
        'P' => 'preadmission',
        'R' => 'recurring',
        'B' => 'observation',
    ];

    /**
     * @return array<string, mixed>
     */
    public function normalize(string $raw, string $sourceProtocol = 'hl7v2'): array
    {
        $message = Hl7V2Message::parse($raw);
        $messageType = $message->messageType() ?: 'UNKNOWN';
        $trigger = $message->triggerEvent();
        $sourceSystem = $message->field('MSH', 3) ?: 'UNKNOWN';
        $messageControlId = $message->field('MSH', 10) ?: self::stableHash($raw, 10);
        $occurredAt = $this->parseHl7Timestamp($message->field('EVN', 2) ?: $message->field('MSH', 7));
        $recordedAt = CarbonImmutable::now('UTC')->toJSON();
        $patientIdentifier = $message->field('PID', 3, 1) ?: 'UNKNOWN-'.self::stableHash($raw, 8);
        $visitIdentifier = $message->field('PV1', 19, 1) ?: 'ENC-'.self::stableHash($patientIdentifier.$occurredAt, 10);
        $assigned = Hl7LocationData::parse($message->field('PV1', 3));
        $prior = Hl7LocationData::parse($message->field('PV1', 6));
        $patientClass = $message->field('PV1', 2) ?: null;
        $category = self::MESSAGE_CATEGORIES[$messageType] ?? 'clinical_context';
        $eventType = self::ADT_MOVEMENT_TYPES[$trigger] ?? ($trigger ? strtolower($trigger) : strtolower($messageType));

        if ($messageType !== 'ADT' && $category !== 'movement') {
            $eventType = $category;
        }

        $diagnosisCodes = [];
        foreach ($message->all('DG1') as $segment) {
            if (! empty($segment[3])) {
                $diagnosisCodes[] = explode('^', $segment[3])[0];
            }
        }

        $orderCodes = [];
        $observationCodes = [];
        $medicationCodes = [];

        foreach ($message->all('OBR') as $segment) {
            if (! empty($segment[4])) {
                $orderCodes[] = explode('^', $segment[4])[0];
            }
        }

        foreach ($message->all('OBX') as $segment) {
            if (! empty($segment[3])) {
                $observationCodes[] = explode('^', $segment[3])[0];
            }
        }

        foreach ($message->all('RXE') as $segment) {
            if (! empty($segment[2])) {
                $medicationCodes[] = explode('^', $segment[2])[0];
            }
        }

        foreach ($message->all('ORC') as $segment) {
            if (! empty($segment[3])) {
                $orderCodes[] = explode('^', $segment[3])[0];
            }
        }

        $eventId = "{$sourceSystem}-{$messageControlId}-".self::stableHash($raw, 8);
        $cancellation = null;
        if (str_starts_with($eventType, 'cancel_')) {
            $cancellation = self::stableHash("{$patientIdentifier}:{$visitIdentifier}:{$trigger}:{$assigned->locationCode()}:{$occurredAt}", 16);
        }

        return [
            'event_id' => $eventId,
            'event_category' => $category,
            'event_type' => $eventType,
            'message_type' => $messageType,
            'trigger_event' => $trigger,
            'patient_id' => self::stableHash($patientIdentifier),
            'patient_display_id' => 'PT-'.strtoupper(self::stableHash($patientIdentifier, 6)),
            'encounter_id' => self::stableHash($visitIdentifier),
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
            'from_location' => $prior->locationCode() !== 'UNKNOWN' ? $prior->locationCode() : null,
            'to_location' => $assigned->locationCode() !== 'UNKNOWN' ? $assigned->locationCode() : null,
            'point_of_care' => $assigned->pointOfCare ?: null,
            'room' => $assigned->room ?: null,
            'bed' => $assigned->bed ?: null,
            'patient_class' => $patientClass,
            'fhir_encounter_status' => self::FHIR_ENCOUNTER_STATUS_BY_EVENT[$eventType] ?? 'in-progress',
            'fhir_encounter_class' => self::PATIENT_CLASS_TO_FHIR_CLASS[$patientClass ?? ''] ?? 'unknown',
            'source_system' => $sourceSystem,
            'message_control_id' => $messageControlId,
            'attending_provider' => $message->field('PV1', 7, 1) ?: null,
            'service_line' => $message->field('PV1', 10) ?: null,
            'priority' => $message->field('PV2', 25) ?: null,
            'diagnosis_codes' => array_values(array_unique($diagnosisCodes)),
            'order_codes' => array_values(array_unique($orderCodes)),
            'observation_codes' => array_values(array_unique($observationCodes)),
            'medication_codes' => array_values(array_unique($medicationCodes)),
            'cancellation_of_event_id' => $cancellation,
            'raw_message_hash' => self::stableHash($raw, 32),
            'source_protocol' => $sourceProtocol,
            'deidentified' => true,
            'metadata' => [
                'sending_facility' => $message->field('MSH', 4),
                'receiving_application' => $message->field('MSH', 5),
                'hl7_version' => $message->field('MSH', 12),
                'pv1_assigned_patient_location' => $assigned->toHl7(),
                'pv1_prior_patient_location' => $prior->toHl7(),
            ],
        ];
    }

    public static function stableHash(string $value, int $length = 16): string
    {
        return substr(hash('sha256', $value), 0, $length);
    }

    public function parseHl7Timestamp(?string $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '' || ! preg_match('/^(\d{4})(\d{2})?(\d{2})?(\d{2})?(\d{2})?(\d{2})?/', $raw, $matches)) {
            return CarbonImmutable::now('UTC')->toJSON();
        }

        $year = (int) $matches[1];
        $month = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 1;
        $day = isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : 1;
        $hour = isset($matches[4]) && $matches[4] !== '' ? (int) $matches[4] : 0;
        $minute = isset($matches[5]) && $matches[5] !== '' ? (int) $matches[5] : 0;
        $second = isset($matches[6]) && $matches[6] !== '' ? (int) $matches[6] : 0;

        return CarbonImmutable::create($year, $month, $day, $hour, $minute, $second, 'UTC')->toJSON();
    }
}
