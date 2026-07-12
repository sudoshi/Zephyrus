<?php

namespace App\Integrations\Healthcare\Ancillary;

use App\Integrations\Healthcare\Contracts\SourceMessageNormalizer;
use App\Integrations\Healthcare\DTO\NormalizedPayload;
use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use App\Services\PatientFlow\Hl7V2Message;
use Carbon\CarbonImmutable;

final class LabResultHl7V2Normalizer implements SourceMessageNormalizer
{
    private const SYSTEM_CLASSES = ['lis', 'lab', 'lab_middleware'];

    public function supports(SourceMessage $message): bool
    {
        $raw = $message->payload['raw_hl7'] ?? null;
        if (! is_string($raw)) {
            return false;
        }

        $parsed = Hl7V2Message::parse($raw);

        return strtoupper($parsed->messageType()) === 'ORU'
            && in_array(strtolower((string) ($message->metadata['system_class'] ?? '')), self::SYSTEM_CLASSES, true)
            && $this->hasResultReceiptOrReworkAssertion($parsed);
    }

    public function normalize(SourceMessage $message): NormalizedPayload
    {
        $parsed = Hl7V2Message::parse((string) $message->payload['raw_hl7']);
        if (! $parsed->isValid()) {
            throw new AncillaryIngestException('invalid_hl7_structure', 'The Laboratory ORU message structure is invalid.');
        }

        $profile = AncillarySourceProfile::from($message);
        $profile->assertFamily('ORU');
        if ($profile->departments !== [] && ! in_array('lab', $profile->departments, true)) {
            throw new AncillaryIngestException('source_message_mismatch', 'The Laboratory result message is outside the source department scope.');
        }

        $controlId = trim($parsed->field('MSH', 10));
        if ($controlId === '') {
            throw new AncillaryIngestException('missing_control_identity', 'The Laboratory result message is missing its message control identity.');
        }

        $events = [];
        foreach ($parsed->groups('OBR') as $index => $segments) {
            $group = new Hl7V2Message(
                raw: '',
                segments: $segments,
                fieldSeparator: $parsed->fieldSeparator,
                componentSeparator: $parsed->componentSeparator,
                repetitionSeparator: $parsed->repetitionSeparator,
                escapeCharacter: $parsed->escapeCharacter,
                subcomponentSeparator: $parsed->subcomponentSeparator,
            );
            $events = [...$events, ...$this->groupEvents($parsed, $group, $profile, $controlId, $index + 1)];
        }
        if ($events === []) {
            throw new AncillaryIngestException('empty_result_assertion', 'The Laboratory ORU message has no supported collection, receipt, rework, or result assertion.');
        }

        $first = $events[0];

        return new NormalizedPayload(
            messageType: 'HL7V2_ORU',
            eventType: AncillaryEventVocabulary::eventTypeFor((string) $first['milestone_code']),
            payload: [...$first, 'order_groups' => $events],
            idempotencyKey: implode(':', ['ancillary', $profile->sourceKey, $controlId]),
            externalId: $controlId,
            occurredAt: (string) $first['occurred_at'],
            metadata: [
                'connector_key' => 'ancillary.healthcare',
                'source_protocol' => 'hl7v2',
                'message_family' => 'ORU',
                'trigger_event' => substr($parsed->triggerEvent(), 0, 20),
                'group_count' => count($events),
                'obr_group_count' => count($parsed->groups('OBR')),
            ],
        );
    }

    /** @return list<array<string, mixed>> */
    private function groupEvents(
        Hl7V2Message $message,
        Hl7V2Message $group,
        AncillarySourceProfile $profile,
        string $controlId,
        int $groupNumber,
    ): array {
        $placer = $this->firstFilled([$group->field('OBR', 2, 1)]);
        $filler = $this->firstFilled([$group->field('OBR', 3, 1), $placer ?? '']);
        if ($filler === null) {
            throw new AncillaryIngestException('missing_order_identity', 'A Laboratory ORU group is missing its order/accession identity.', context: ['group' => $groupNumber]);
        }

        $test = $this->testIdentity($group, 'OBR', 1);
        $fallbackTime = $this->timestampFrom(
            [[$group, 'OBR', 22, 1], [$message, 'MSH', 7, 1]],
            'result',
            $groupNumber,
        );
        $base = array_filter([
            'department' => 'lab',
            'work_item_type' => 'lab_order',
            'source_order_key' => $filler,
            'reconciliation_key' => $filler,
            'placer_order_key' => $placer,
            'filler_order_key' => $filler,
            'patient_ref' => $this->pseudonymIfPresent($profile, 'patient', $message->field('PID', 3, 1)),
            'encounter_ref' => $this->pseudonymIfPresent($profile, 'encounter', $message->field('PV1', 19, 1)),
            'patient_class' => $this->patientClass($message),
            'priority' => $this->priority($group),
            'ordered_at' => $fallbackTime->toIso8601String(),
            'ordered_at_source' => 'result_fallback',
            ...$test,
            'source_timestamp_valid' => true,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $specimens = $this->specimens($group);
        $primarySpecimen = $this->primarySpecimen($specimens);
        $events = [];
        foreach ($specimens as $specimen) {
            if (isset($specimen['collected_at'])) {
                $events[] = [...$base, ...$specimen, 'milestone_code' => 'LAB_COLLECTED', 'occurred_at' => $specimen['collected_at'], 'specimen_status' => 'collected'];
            }
        }

        $receivedAt = $this->optionalTimestamp($group, 'OBR', 14, 1, 'receipt', $groupNumber);
        if ($receivedAt !== null && $primarySpecimen !== null) {
            $events[] = [
                ...$base,
                ...$primarySpecimen,
                'milestone_code' => 'LAB_RECEIVED',
                'occurred_at' => $receivedAt->toIso8601String(),
                'received_at' => $receivedAt->toIso8601String(),
                'specimen_status' => 'received',
            ];
        }

        foreach ($specimens as $specimen) {
            if (! isset($specimen['rejection_reason_code'])) {
                continue;
            }
            $rejectedAt = $receivedAt ?? $fallbackTime;
            $events[] = [
                ...$base,
                ...$specimen,
                'milestone_code' => 'LAB_REJECTED',
                'occurred_at' => $rejectedAt->toIso8601String(),
                'rejected_at' => $rejectedAt->toIso8601String(),
                'specimen_status' => 'rejected',
            ];
        }

        foreach ($specimens as $specimen) {
            if (! isset($specimen['parent_source_specimen_key'])) {
                continue;
            }
            $events[] = [
                ...$base,
                ...$specimen,
                'milestone_code' => 'LAB_RECOLLECT_ORDERED',
                'occurred_at' => $fallbackTime->toIso8601String(),
                'recollect_ordered_at' => $fallbackTime->toIso8601String(),
                'specimen_status' => 'collection_pending',
            ];
        }

        $obxCount = count($group->all('OBX'));
        for ($obx = 1; $obx <= $obxCount; $obx++) {
            $events = [
                ...$events,
                ...$this->resultEvents($message, $group, $profile, $controlId, $base, $primarySpecimen, $obx, $groupNumber),
            ];
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>|null  $specimen
     * @return list<array<string, mixed>>
     */
    private function resultEvents(
        Hl7V2Message $message,
        Hl7V2Message $group,
        AncillarySourceProfile $profile,
        string $controlId,
        array $base,
        ?array $specimen,
        int $obx,
        int $groupNumber,
    ): array {
        $test = $this->testIdentity($group, 'OBX', $obx);
        if (! isset($test['test_code']) && ! isset($test['loinc_code'])) {
            throw new AncillaryIngestException('missing_result_identity', 'A Laboratory OBX result is missing its local/LOINC identity.', context: ['group' => $groupNumber, 'obx' => $obx]);
        }

        $rawStatus = strtoupper(trim($group->fieldAt('OBX', $obx, 11)) ?: trim($group->field('OBR', 25)));
        [$status, $milestones, $verified] = match ($rawStatus) {
            'P', 'I', 'S' => ['preliminary', ['LAB_PRELIM'], false],
            'R' => ['final', ['LAB_RESULTED'], false],
            'F' => ['final', ['LAB_RESULTED', 'LAB_VERIFIED'], true],
            'C' => ['corrected', ['LAB_CORRECTED'], true],
            'D', 'X' => ['cancelled', ['LAB_CANCELLED'], false],
            default => throw new AncillaryIngestException('invalid_result_status', 'A Laboratory OBX result has an unsupported status.', context: ['group' => $groupNumber, 'obx' => $obx]),
        };

        $resultedAt = $this->timestampFrom(
            [[$group, 'OBX', 19, $obx], [$group, 'OBR', 22, 1], [$message, 'MSH', 7, 1]],
            'result',
            $groupNumber,
        );
        $observedAt = $this->optionalTimestamp($group, 'OBX', 14, $obx, 'observation', $groupNumber)
            ?? $this->optionalTimestamp($group, 'OBR', 7, 1, 'collection', $groupNumber);
        $sourceResultKey = trim($group->fieldAt('OBX', $obx, 21, 1));
        if ($sourceResultKey === '') {
            $sourceResultKey = implode(':', [
                $base['source_order_key'],
                $test['test_code'] ?? $test['loinc_code'],
                trim($group->fieldAt('OBX', $obx, 4)) ?: 'root',
            ]);
        }
        $version = trim($group->fieldAt('OBX', $obx, 21, 2));
        if ($version === '') {
            $version = $resultedAt->utc()->format('YmdHisv');
        }
        $abnormal = $this->abnormalFlag($group->fieldAt('OBX', $obx, 8));
        $autoVerified = strtoupper(trim($group->fieldAt('OBX', $obx, 16, 1))) === 'AUTO_VERIFY';
        $analyzer = $this->firstFilled([
            $group->fieldAt('OBX', $obx, 18, 1),
            $group->fieldAt('OBX', $obx, 15, 1),
        ]);
        $stage = $this->resultStage($group->fieldAt('OBX', $obx, 4), $status);
        $common = array_filter([
            ...$base,
            ...($specimen ?? []),
            ...$test,
            'source_result_key' => $sourceResultKey,
            'source_result_version' => $version,
            'result_status' => $status,
            'result_stage' => $stage,
            'abnormal_flag' => $abnormal,
            'is_critical' => $abnormal === 'critical',
            'auto_verified' => $autoVerified,
            'analyzer_ref' => $analyzer,
            'middleware_ref' => trim($group->fieldAt('OBX', $obx, 15, 1)) ?: null,
            'observed_at' => $observedAt?->toIso8601String(),
            'resulted_at' => $resultedAt->toIso8601String(),
            'corrected_at' => $status === 'corrected' ? $resultedAt->toIso8601String() : null,
            'cancelled_at' => $status === 'cancelled' ? $resultedAt->toIso8601String() : null,
            'critical_identified_at' => $abnormal === 'critical' ? $resultedAt->toIso8601String() : null,
            'result_time_source' => trim($group->fieldAt('OBX', $obx, 19)) !== '' ? 'OBX-19' : (trim($group->field('OBR', 22)) !== '' ? 'OBR-22' : 'MSH-7'),
            'obx_set_id' => trim($group->fieldAt('OBX', $obx, 1)) ?: (string) $obx,
            'source_timestamp_valid' => true,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $events = [];
        foreach ($milestones as $milestone) {
            $events[] = [
                ...$common,
                'milestone_code' => $milestone,
                'occurred_at' => $resultedAt->toIso8601String(),
                'verified_at' => $milestone === 'LAB_VERIFIED' || $status === 'corrected' && $verified
                    ? $resultedAt->toIso8601String()
                    : null,
                'auto_verified' => $milestone === 'LAB_VERIFIED' ? $autoVerified : false,
            ];
        }

        return $events;
    }

    /** @return list<array<string, mixed>> */
    private function specimens(Hl7V2Message $group): array
    {
        $specimens = [];
        foreach ($group->all('SPM') as $index => $segment) {
            $spm = new Hl7V2Message(
                raw: '',
                segments: [$segment],
                fieldSeparator: $group->fieldSeparator,
                componentSeparator: $group->componentSeparator,
                repetitionSeparator: $group->repetitionSeparator,
                escapeCharacter: $group->escapeCharacter,
                subcomponentSeparator: $group->subcomponentSeparator,
            );
            $specimenKey = $this->firstFilled([$spm->field('SPM', 2, 1), $spm->field('SPM', 2, 2)]);
            if ($specimenKey === null) {
                throw new AncillaryIngestException('missing_specimen_identity', 'A Laboratory ORU specimen is missing its source identity.', context: ['specimen' => $index + 1]);
            }
            $parent = $this->firstFilled([$spm->field('SPM', 3, 1), $spm->field('SPM', 3, 2)]);
            $collection = $this->optionalTimestamp($spm, 'SPM', 17, 1, 'collection', $index + 1)
                ?? ($parent === null ? $this->optionalTimestamp($group, 'OBR', 7, 1, 'collection', $index + 1) : null);
            $rejection = strtoupper(trim($spm->field('SPM', 21, 1)));
            $specimens[] = array_filter([
                'source_specimen_key' => $specimenKey,
                'source_specimen_business_key' => $specimenKey,
                'source_accession_key' => trim($group->field('OBR', 3, 1)) ?: null,
                'parent_source_specimen_key' => $parent,
                'specimen_type' => trim($spm->field('SPM', 4, 1)) ?: 'unknown',
                'container_type' => trim($spm->field('SPM', 27, 1)) ?: null,
                'collection_method' => trim($spm->field('SPM', 7, 1)) ?: null,
                'collected_at' => $collection?->toIso8601String(),
                'collection_source' => $collection !== null ? (trim($spm->field('SPM', 17, 1)) !== '' ? 'SPM-17' : 'OBR-7') : null,
                'rejection_reason_code' => $rejection !== '' ? strtolower($rejection) : null,
            ], fn (mixed $value): bool => $value !== null && $value !== '');
        }

        if ($specimens === [] && trim($group->field('OBR', 3, 1)) !== '') {
            $collection = $this->optionalTimestamp($group, 'OBR', 7, 1, 'collection', 1);
            $specimens[] = array_filter([
                'source_specimen_key' => trim($group->field('OBR', 3, 1)),
                'source_accession_key' => trim($group->field('OBR', 3, 1)),
                'specimen_type' => trim($group->field('OBR', 15, 1)) ?: 'unknown',
                'collected_at' => $collection?->toIso8601String(),
                'collection_source' => $collection !== null ? 'OBR-7' : null,
            ], fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return $specimens;
    }

    /** @param list<array<string, mixed>> $specimens @return array<string, mixed>|null */
    private function primarySpecimen(array $specimens): ?array
    {
        foreach ($specimens as $specimen) {
            if (! isset($specimen['parent_source_specimen_key'])) {
                return $specimen;
            }
        }

        return $specimens[0] ?? null;
    }

    /** @return array<string, string> */
    private function testIdentity(Hl7V2Message $message, string $segment, int $occurrence): array
    {
        $primary = [
            'code' => trim($message->fieldAt($segment, $occurrence, $segment === 'OBX' ? 3 : 4, 1)),
            'label' => trim($message->fieldAt($segment, $occurrence, $segment === 'OBX' ? 3 : 4, 2)),
            'system' => strtoupper(trim($message->fieldAt($segment, $occurrence, $segment === 'OBX' ? 3 : 4, 3))),
        ];
        $alternate = [
            'code' => trim($message->fieldAt($segment, $occurrence, $segment === 'OBX' ? 3 : 4, 4)),
            'label' => trim($message->fieldAt($segment, $occurrence, $segment === 'OBX' ? 3 : 4, 5)),
            'system' => strtoupper(trim($message->fieldAt($segment, $occurrence, $segment === 'OBX' ? 3 : 4, 6))),
        ];
        $isLoinc = fn (array $coding): bool => in_array($coding['system'], ['LN', 'LOINC', 'HTTP://LOINC.ORG'], true);
        $local = $isLoinc($primary) ? $alternate : $primary;
        $loinc = $isLoinc($primary) ? $primary : ($isLoinc($alternate) ? $alternate : ['code' => '', 'label' => '']);

        return array_filter([
            'test_code' => $local['code'] ?: ($primary['code'] ?: null),
            'test_label' => $local['label'] ?: ($primary['label'] ?: null),
            'loinc_code' => $loinc['code'] ?: null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @param list<array{0:Hl7V2Message,1:string,2:int,3:int}> $candidates */
    private function timestampFrom(array $candidates, string $kind, int $group): CarbonImmutable
    {
        foreach ($candidates as [$message, $segment, $field, $occurrence]) {
            $timestamp = $this->optionalTimestamp($message, $segment, $field, $occurrence, $kind, $group);
            if ($timestamp !== null) {
                return $timestamp;
            }
        }

        throw new AncillaryIngestException('missing_timestamp', "A Laboratory ORU group is missing its {$kind} timestamp.", context: ['group' => $group]);
    }

    private function optionalTimestamp(
        Hl7V2Message $message,
        string $segment,
        int $field,
        int $occurrence,
        string $kind,
        int $group,
    ): ?CarbonImmutable {
        $raw = trim($message->fieldAt($segment, $occurrence, $field, 1));
        if ($raw === '') {
            return null;
        }
        $timestamp = $message->timestamp($segment, $field, $occurrence);
        if ($timestamp === null) {
            throw new AncillaryIngestException('malformed_timestamp', "A Laboratory ORU {$kind} timestamp is malformed.", context: ['group' => $group]);
        }

        return $timestamp;
    }

    private function abnormalFlag(string $raw): string
    {
        $flags = array_map('strtoupper', explode('~', str_replace('\\', '~', trim($raw))));
        if (array_intersect($flags, ['HH', 'LL', 'AA', 'CRIT', 'CRITICAL']) !== []) {
            return 'critical';
        }
        if (array_intersect($flags, ['H', 'L', 'A', 'ABN', 'ABNORMAL']) !== []) {
            return 'abnormal';
        }
        if (array_intersect($flags, ['N', 'NORMAL']) !== []) {
            return 'normal';
        }

        return 'unknown';
    }

    private function resultStage(string $subId, string $status): string
    {
        if ($status === 'corrected') {
            return 'corrected';
        }
        if ($status === 'cancelled') {
            return 'cancelled';
        }

        return match (strtoupper(trim($subId))) {
            'ORG', 'ORGANISM', 'ORGANISM_IDENTIFICATION' => 'organism_identification',
            'SUSC', 'SUSCEPTIBILITY' => 'susceptibility',
            'PRELIM', 'PRELIMINARY' => 'preliminary',
            default => $status === 'final' ? 'final' : 'preliminary',
        };
    }

    private function priority(Hl7V2Message $group): string
    {
        return match (strtoupper(trim($group->field('OBR', 27, 6)))) {
            'S', 'STAT' => 'stat',
            'A', 'ASAP', 'URGENT' => 'urgent',
            default => 'routine',
        };
    }

    private function patientClass(Hl7V2Message $message): string
    {
        return match (strtoupper(trim($message->field('PV1', 2)))) {
            'E' => 'emergency',
            'I' => 'inpatient',
            'O' => 'outpatient',
            'P' => 'perioperative',
            default => 'unknown',
        };
    }

    private function hasResultReceiptOrReworkAssertion(Hl7V2Message $message): bool
    {
        foreach ($message->all('OBX') as $index => $_segment) {
            if (trim($message->fieldAt('OBX', $index + 1, 11)) !== '') {
                return true;
            }
        }
        foreach ($message->all('OBR') as $index => $_segment) {
            if (trim($message->fieldAt('OBR', $index + 1, 14)) !== '' || trim($message->fieldAt('OBR', $index + 1, 25)) !== '') {
                return true;
            }
        }
        foreach ($message->all('SPM') as $index => $_segment) {
            if (trim($message->fieldAt('SPM', $index + 1, 3)) !== '' || trim($message->fieldAt('SPM', $index + 1, 21)) !== '') {
                return true;
            }
        }

        return false;
    }

    private function pseudonymIfPresent(AncillarySourceProfile $profile, string $kind, string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : hash_hmac('sha256', implode('|', [$profile->sourceKey, $kind, $value]), (string) config('app.key'));
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
}
