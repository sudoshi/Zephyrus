<?php

namespace App\Integrations\Healthcare\Ancillary;

use App\Integrations\Healthcare\Contracts\SourceMessageNormalizer;
use App\Integrations\Healthcare\DTO\NormalizedPayload;
use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use App\Services\PatientFlow\Hl7V2Message;
use Carbon\CarbonImmutable;

final class RadiologyResultHl7V2Normalizer implements SourceMessageNormalizer
{
    public function supports(SourceMessage $message): bool
    {
        if (! is_string($message->payload['raw_hl7'] ?? null)) {
            return false;
        }

        return strtoupper(Hl7V2Message::parse($message->payload['raw_hl7'])->messageType()) === 'ORU'
            && in_array(strtolower((string) ($message->metadata['system_class'] ?? '')), ['radiology', 'radiology_reporting', 'ris'], true);
    }

    public function normalize(SourceMessage $message): NormalizedPayload
    {
        $parsed = Hl7V2Message::parse((string) $message->payload['raw_hl7']);
        if (! $parsed->isValid()) {
            throw new AncillaryIngestException('invalid_hl7_structure', 'The Radiology ORU message structure is invalid.');
        }

        $profile = AncillarySourceProfile::from($message);
        $profile->assertFamily('ORU');
        if ($profile->departments !== [] && ! in_array('rad', $profile->departments, true)) {
            throw new AncillaryIngestException('source_message_mismatch', 'The Radiology report is outside the source department scope.');
        }

        $controlId = trim($parsed->field('MSH', 10));
        if ($controlId === '') {
            throw new AncillaryIngestException('missing_control_identity', 'The Radiology report is missing its message control identity.');
        }

        $groups = [];
        foreach ($parsed->groups('OBR') as $index => $segments) {
            $group = new Hl7V2Message(
                raw: '', segments: $segments,
                fieldSeparator: $parsed->fieldSeparator, componentSeparator: $parsed->componentSeparator,
                repetitionSeparator: $parsed->repetitionSeparator, escapeCharacter: $parsed->escapeCharacter,
                subcomponentSeparator: $parsed->subcomponentSeparator,
            );
            $groups[] = $this->group($parsed, $group, $profile, $controlId, $index);
        }
        if ($groups === []) {
            throw new AncillaryIngestException('missing_order_identity', 'The Radiology report has no OBR result group.');
        }

        $first = $groups[0];

        return new NormalizedPayload(
            messageType: 'HL7V2_ORU',
            eventType: AncillaryEventVocabulary::eventTypeFor($first['milestone_code']),
            payload: [...$first, 'order_groups' => $groups],
            idempotencyKey: implode(':', ['ancillary', $profile->sourceKey, $controlId]),
            externalId: $controlId,
            occurredAt: $first['occurred_at'],
            metadata: ['connector_key' => 'ancillary.healthcare', 'source_protocol' => 'hl7v2', 'message_family' => 'ORU', 'group_count' => count($groups)],
        );
    }

    /** @return array<string, mixed> */
    private function group(Hl7V2Message $message, Hl7V2Message $group, AncillarySourceProfile $profile, string $controlId, int $index): array
    {
        $orderKey = $this->firstFilled([$group->field('OBR', 2, 1), $group->field('OBR', 3, 1)]);
        $accession = $this->firstFilled([$group->field('OBR', 3, 1), $orderKey ?? '']);
        if ($orderKey === null || $accession === null) {
            throw new AncillaryIngestException('missing_order_identity', 'A Radiology report group is missing order/accession identity.', context: ['group' => $index + 1]);
        }

        $rawStatus = strtoupper($this->firstFilled([$group->field('OBR', 25), $group->field('OBX', 11)]) ?? '');
        [$status, $milestone] = match ($rawStatus) {
            'P', 'R' => ['preliminary', 'RAD_PRELIM'],
            'F' => ['final', 'RAD_FINAL'],
            'C' => ['corrected', 'RAD_FINAL'],
            'A' => ['addendum', 'RAD_FINAL'],
            default => throw new AncillaryIngestException('invalid_report_status', 'The Radiology report status is unsupported.', context: ['group' => $index + 1]),
        };
        $occurredAt = $this->reportTime($message, $group);
        $orderedAt = $group->timestamp('OBR', 7) ?? $occurredAt;
        $version = trim($group->field('OBR', 26, 1)) ?: '1';
        $sourceReadKey = hash('sha256', implode('|', [$accession, $status, $occurredAt->toIso8601String(), $version]));
        $patient = trim($message->field('PID', 3, 1));
        $encounter = trim($message->field('PV1', 19, 1));
        $radiologist = trim($group->field('OBR', 32, 1));

        return array_filter([
            'department' => 'rad', 'milestone_code' => $milestone, 'work_item_type' => 'imaging_order',
            'source_order_key' => $orderKey, 'source_exam_key' => $accession, 'reconciliation_key' => $accession,
            'patient_ref' => $patient !== '' ? $this->pseudonym($profile->sourceKey, 'patient', $patient) : null,
            'encounter_ref' => $encounter !== '' ? $this->pseudonym($profile->sourceKey, 'encounter', $encounter) : null,
            'patient_class' => $this->patientClass($message), 'priority' => 'routine',
            'ordered_at' => $orderedAt->toIso8601String(), 'occurred_at' => $occurredAt->toIso8601String(),
            'procedure_code' => trim($group->field('OBR', 4, 1)) ?: null,
            'procedure_label' => trim($group->field('OBR', 4, 2)) ?: null,
            'modality' => $this->modality($group->field('OBR', 24)),
            'read_status' => $status, 'source_read_key' => $sourceReadKey, 'source_report_version' => $version,
            'radiologist_ref' => $radiologist !== '' ? $this->pseudonym($profile->sourceKey, 'radiologist', $radiologist) : null,
            'preliminary_at' => $status === 'preliminary' ? $occurredAt->toIso8601String() : null,
            'final_at' => in_array($status, ['final', 'addendum'], true) ? $occurredAt->toIso8601String() : null,
            'corrected_at' => $status === 'corrected' ? $occurredAt->toIso8601String() : null,
            'correction' => in_array($status, ['corrected', 'addendum'], true) ? $status : null,
            'degraded_fields' => [], 'source_timestamp_valid' => true,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function reportTime(Hl7V2Message $message, Hl7V2Message $group): CarbonImmutable
    {
        foreach ([[$group, 'OBR', 22], [$group, 'OBX', 14], [$group, 'OBR', 7], [$message, 'MSH', 7]] as [$source, $segment, $field]) {
            if (trim($source->field($segment, $field)) === '') {
                continue;
            }
            $timestamp = $source->timestamp($segment, $field);
            if ($timestamp === null) {
                throw new AncillaryIngestException('malformed_timestamp', 'The Radiology report contains a malformed report timestamp.');
            }

            return $timestamp;
        }

        throw new AncillaryIngestException('missing_timestamp', 'The Radiology report is missing its operational report timestamp.');
    }

    private function patientClass(Hl7V2Message $message): string
    {
        return match (strtoupper(trim($message->field('PV1', 2)))) {
            'E' => 'emergency', 'I' => 'inpatient', 'O' => 'outpatient', 'P' => 'perioperative', default => 'unknown',
        };
    }

    private function modality(string $value): ?string
    {
        $value = strtoupper(trim($value));

        return in_array($value, ['XR', 'CT', 'MRI', 'US', 'NM', 'IR'], true) ? $value : null;
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
