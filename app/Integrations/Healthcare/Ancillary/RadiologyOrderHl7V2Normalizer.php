<?php

namespace App\Integrations\Healthcare\Ancillary;

use App\Integrations\Healthcare\Contracts\SourceMessageNormalizer;
use App\Integrations\Healthcare\DTO\NormalizedPayload;
use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use App\Services\PatientFlow\Hl7V2Message;
use Carbon\CarbonImmutable;

final class RadiologyOrderHl7V2Normalizer implements SourceMessageNormalizer
{
    private const FAMILIES = ['ORM', 'OMI', 'SIU'];

    public function supports(SourceMessage $message): bool
    {
        if (! isset($message->payload['raw_hl7']) || ! is_string($message->payload['raw_hl7'])) {
            return false;
        }

        $systemClass = strtolower((string) ($message->metadata['system_class'] ?? ''));

        return in_array($systemClass, ['radiology', 'ris'], true)
            && in_array(strtoupper(Hl7V2Message::parse($message->payload['raw_hl7'])->messageType()), self::FAMILIES, true);
    }

    public function normalize(SourceMessage $message): NormalizedPayload
    {
        $parsed = Hl7V2Message::parse((string) $message->payload['raw_hl7']);
        if (! $parsed->isValid()) {
            throw new AncillaryIngestException('invalid_hl7_structure', 'The Radiology HL7 v2 message structure is invalid.');
        }

        $family = strtoupper($parsed->messageType());
        $profile = AncillarySourceProfile::from($message);
        $profile->assertFamily($family);
        if ($profile->departments !== [] && ! in_array('rad', $profile->departments, true)) {
            throw new AncillaryIngestException('source_message_mismatch', 'The Radiology order message is outside the source department scope.');
        }

        $controlId = trim($parsed->field('MSH', 10));
        if ($controlId === '') {
            throw new AncillaryIngestException('missing_control_identity', 'The Radiology order message is missing its message control identity.');
        }

        $obrCount = max(1, count($parsed->all('OBR')));
        $groups = [];
        for ($occurrence = 1; $occurrence <= $obrCount; $occurrence++) {
            $groups[] = $this->group($parsed, $profile, $family, $occurrence);
        }

        $first = $groups[0];

        return new NormalizedPayload(
            messageType: 'HL7V2_'.$family,
            eventType: AncillaryEventVocabulary::eventTypeFor($first['milestone_code']),
            payload: [...$first, 'order_groups' => $groups],
            idempotencyKey: implode(':', ['ancillary', $profile->sourceKey, $controlId]),
            externalId: $controlId,
            occurredAt: $first['occurred_at'],
            metadata: [
                'connector_key' => 'ancillary.healthcare',
                'source_protocol' => 'hl7v2',
                'message_family' => $family,
                'trigger_event' => substr($parsed->triggerEvent(), 0, 20),
                'group_count' => count($groups),
            ],
        );
    }

    /** @return array<string, mixed> */
    private function group(Hl7V2Message $message, AncillarySourceProfile $profile, string $family, int $occurrence): array
    {
        $orderKey = $this->firstFilled([
            $message->fieldAt('OBR', $occurrence, 2, 1),
            $message->fieldAt('OBR', $occurrence, 3, 1),
            $message->field('ORC', 2, 1),
        ]);
        if ($orderKey === null) {
            throw new AncillaryIngestException('missing_order_identity', 'A Radiology order group is missing its source order identity.', context: ['group' => $occurrence]);
        }

        $control = strtoupper(trim($message->field('ORC', 1)));
        $status = strtoupper(trim($message->field('ORC', 5)));
        $milestone = match (true) {
            in_array($control, ['CA', 'DC'], true), in_array($status, ['CA', 'DC'], true) => 'RAD_CANCELLED',
            $family === 'SIU', in_array($status, ['SC', 'SCHEDULED'], true) => 'RAD_SCHEDULED',
            default => $profile->milestoneFor($family),
        };
        $occurredAt = $this->occurredAt($message, $occurrence);
        $patient = trim($message->field('PID', 3, 1));
        $encounter = trim($message->field('PV1', 19, 1));
        $modality = strtoupper(trim($message->fieldAt('OBR', $occurrence, 24)));
        if ($modality !== '' && ! in_array($modality, ['XR', 'CT', 'MRI', 'US', 'NM', 'IR'], true)) {
            $modality = '';
        }
        $scheduledAt = $this->optionalTimestamp($message, 'OBR', 36, $occurrence);
        $procedureCode = trim($message->fieldAt('OBR', $occurrence, 4, 1));
        $procedureLabel = trim($message->fieldAt('OBR', $occurrence, 4, 2));
        $protocol = trim($message->fieldAt('OBR', $occurrence, 44, 2));
        $sourceExamKey = $this->firstFilled([$message->fieldAt('OBR', $occurrence, 3, 1), $orderKey]);

        return array_filter([
            'department' => 'rad',
            'milestone_code' => $milestone,
            'work_item_type' => 'imaging_order',
            'source_order_key' => $orderKey,
            'source_exam_key' => $sourceExamKey,
            'reconciliation_key' => $sourceExamKey,
            'patient_ref' => $patient !== '' ? $this->pseudonym($profile->sourceKey, 'patient', $patient) : null,
            'encounter_ref' => $encounter !== '' ? $this->pseudonym($profile->sourceKey, 'encounter', $encounter) : null,
            'patient_class' => $this->patientClass($message),
            'priority' => $this->priority($message, $occurrence),
            'ordered_at' => $occurredAt->toIso8601String(),
            'occurred_at' => $occurredAt->toIso8601String(),
            'modality' => $modality ?: null,
            'procedure_code' => $procedureCode ?: null,
            'procedure_label' => $procedureLabel ?: null,
            'protocol' => $protocol ?: null,
            'is_portable' => str_contains(strtolower($procedureLabel), 'portable') ?: null,
            'scheduled_start_at' => $scheduledAt?->toIso8601String(),
            'exam_status' => match ($milestone) {
                'RAD_CANCELLED' => $control === 'DC' || $status === 'DC' ? 'discontinued' : 'cancelled',
                'RAD_SCHEDULED' => 'scheduled',
                default => 'ordered',
            },
            'correction' => in_array($control, ['XO', 'XX'], true) ? 'order_modified' : null,
            'degraded_fields' => array_values(array_filter([
                $modality === '' ? 'modality' : null,
                $procedureCode === '' ? 'procedure_code' : null,
                $protocol === '' ? 'protocol' : null,
                $scheduledAt === null ? 'scheduled_start_at' : null,
            ])),
            'source_timestamp_valid' => true,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function occurredAt(Hl7V2Message $message, int $occurrence): CarbonImmutable
    {
        foreach ([['OBR', 22], ['OBR', 7], ['ORC', 9], ['MSH', 7]] as [$segment, $field]) {
            $raw = trim($message->fieldAt($segment, $segment === 'OBR' ? $occurrence : 1, $field));
            if ($raw === '') {
                continue;
            }
            $timestamp = $message->timestamp($segment, $field, $segment === 'OBR' ? $occurrence : 1);
            if ($timestamp === null) {
                throw new AncillaryIngestException('malformed_timestamp', 'The Radiology order message contains a malformed source timestamp.', context: ['group' => $occurrence]);
            }

            return $timestamp;
        }

        throw new AncillaryIngestException('missing_timestamp', 'The Radiology order message is missing its source event timestamp.');
    }

    private function optionalTimestamp(Hl7V2Message $message, string $segment, int $field, int $occurrence): ?CarbonImmutable
    {
        $raw = trim($message->fieldAt($segment, $occurrence, $field));
        if ($raw === '') {
            return null;
        }
        $timestamp = $message->timestamp($segment, $field, $occurrence);
        if ($timestamp === null) {
            throw new AncillaryIngestException('malformed_timestamp', 'The Radiology order message contains a malformed scheduled timestamp.', context: ['group' => $occurrence]);
        }

        return $timestamp;
    }

    private function priority(Hl7V2Message $message, int $occurrence): string
    {
        $value = strtoupper($this->firstFilled([$message->field('ORC', 7, 6), $message->fieldAt('OBR', $occurrence, 27, 6)]) ?? 'ROUTINE');

        return match ($value) {
            'S', 'STAT' => 'stat',
            'A', 'ASAP', 'URGENT' => 'urgent',
            default => 'routine',
        };
    }

    private function patientClass(Hl7V2Message $message): string
    {
        return match (strtoupper(trim($message->field('PV1', 2)))) {
            'E' => 'emergency', 'I' => 'inpatient', 'O' => 'outpatient', 'P' => 'perioperative', default => 'unknown',
        };
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

    private function pseudonym(string $sourceKey, string $kind, string $value): string
    {
        return hash_hmac('sha256', implode('|', [$sourceKey, $kind, $value]), (string) config('app.key'));
    }
}
