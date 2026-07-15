<?php

namespace App\Integrations\Healthcare\Ancillary;

use App\Integrations\Healthcare\Contracts\SourceMessageNormalizer;
use App\Integrations\Healthcare\DTO\NormalizedPayload;
use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use App\Services\PatientFlow\Hl7V2Message;
use Carbon\CarbonImmutable;

final class AncillaryHl7V2MessageNormalizer implements SourceMessageNormalizer
{
    private const FAMILIES = ['ORM', 'OMI', 'OML', 'ORU', 'SIU', 'RDE', 'RDS'];

    public function supports(SourceMessage $message): bool
    {
        return isset($message->payload['raw_hl7'])
            || str_starts_with(strtoupper($message->messageType), 'HL7V2');
    }

    public function normalize(SourceMessage $message): NormalizedPayload
    {
        $raw = $message->payload['raw_hl7'] ?? null;
        if (! is_string($raw)) {
            throw new AncillaryIngestException('invalid_hl7_structure', 'The HL7 v2 payload is missing its raw message envelope.');
        }

        $parsed = Hl7V2Message::parse($raw);
        if (! $parsed->isValid()) {
            throw new AncillaryIngestException(
                'invalid_hl7_structure',
                'The HL7 v2 message structure is invalid.',
                context: ['validation_code' => implode(',', $parsed->validationErrors())],
            );
        }

        $family = strtoupper($parsed->messageType());
        if (! in_array($family, self::FAMILIES, true)) {
            throw new AncillaryIngestException(
                'unsupported_message_family',
                'The HL7 v2 message family is unsupported for ancillary ingestion.',
                context: ['message_family' => substr($family, 0, 20)],
            );
        }

        $profile = AncillarySourceProfile::from($message);
        $milestoneCode = $profile->milestoneFor($family);
        $controlId = trim($parsed->field('MSH', 10));
        if ($controlId === '') {
            throw new AncillaryIngestException('missing_control_identity', 'The ancillary message is missing its message control identity.');
        }

        $orderKey = $this->firstFilled([
            $parsed->field('ORC', 2, 1),
            $parsed->field('OBR', 2, 1),
            $parsed->field('OBR', 3, 1),
            $parsed->field('RXO', 1, 1),
        ]);
        if ($orderKey === null) {
            throw new AncillaryIngestException('missing_order_identity', 'The ancillary message is missing its source order identity.');
        }

        $occurredAt = $this->occurredAt($parsed);
        $department = AncillaryEventVocabulary::departmentFor($milestoneCode);
        $patient = trim($parsed->field('PID', 3, 1));
        $encounter = trim($parsed->field('PV1', 19, 1));
        $payload = array_filter([
            'department' => $department,
            'milestone_code' => $milestoneCode,
            'work_item_type' => $this->workItemType($department),
            'source_order_key' => $orderKey,
            'reconciliation_key' => $this->firstFilled([$parsed->field('OBR', 3, 1), $orderKey]),
            'patient_ref' => $patient !== '' ? $this->pseudonym($profile->sourceKey, 'patient', $patient) : null,
            'encounter_ref' => $encounter !== '' ? $this->pseudonym($profile->sourceKey, 'encounter', $encounter) : null,
            'patient_class' => strtolower(trim($parsed->field('PV1', 2))) ?: null,
            'priority' => $this->priority($parsed),
            'ordered_at' => $occurredAt->toIso8601String(),
            'modality' => trim($parsed->field('OBR', 24)) ?: null,
            'test_code' => trim($parsed->field('OBR', 4, 1)) ?: null,
            'source_timestamp_valid' => true,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        return new NormalizedPayload(
            messageType: 'HL7V2_'.$family,
            eventType: AncillaryEventVocabulary::eventTypeFor($milestoneCode),
            payload: $payload,
            idempotencyKey: implode(':', ['ancillary', $profile->sourceKey, $controlId]),
            externalId: $controlId,
            occurredAt: $occurredAt->toIso8601String(),
            metadata: [
                'connector_key' => 'ancillary.healthcare',
                'source_protocol' => 'hl7v2',
                'message_family' => $family,
                'trigger_event' => substr($parsed->triggerEvent(), 0, 20),
            ],
        );
    }

    private function occurredAt(Hl7V2Message $message): CarbonImmutable
    {
        foreach ([['OBR', 22], ['OBR', 7], ['ORC', 9], ['RXA', 3], ['MSH', 7]] as [$segment, $field]) {
            $raw = trim($message->field($segment, $field));
            if ($raw === '') {
                continue;
            }

            $timestamp = $message->timestamp($segment, $field);
            if ($timestamp === null) {
                throw new AncillaryIngestException('malformed_timestamp', 'The ancillary message contains a malformed source timestamp.');
            }

            return $timestamp;
        }

        throw new AncillaryIngestException('missing_timestamp', 'The ancillary message is missing its source event timestamp.');
    }

    /** @param list<string> $values */
    private function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            if (trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function workItemType(string $department): string
    {
        return match ($department) {
            'rad' => 'imaging_order',
            'lab' => 'lab_order',
            'pathology' => 'ap_case',
            'blood_bank' => 'blood_bank_request',
            'rx' => 'medication_order',
            default => 'ancillary_order',
        };
    }

    private function priority(Hl7V2Message $message): string
    {
        $value = strtoupper($this->firstFilled([
            $message->field('ORC', 7, 6),
            $message->field('OBR', 27, 6),
        ]) ?? 'ROUTINE');

        return match ($value) {
            'S', 'STAT' => 'stat',
            'A', 'ASAP', 'URGENT' => 'urgent',
            default => 'routine',
        };
    }

    private function pseudonym(string $sourceKey, string $kind, string $value): string
    {
        return hash_hmac('sha256', implode('|', [$sourceKey, $kind, $value]), (string) config('app.key'));
    }
}
