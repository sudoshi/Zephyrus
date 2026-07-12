<?php

namespace App\Integrations\Healthcare\Ancillary;

use App\Integrations\Healthcare\Contracts\SourceMessageNormalizer;
use App\Integrations\Healthcare\DTO\NormalizedPayload;
use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use App\Services\PatientFlow\Hl7V2Message;
use Carbon\CarbonImmutable;

final class LabOrderHl7V2Normalizer implements SourceMessageNormalizer
{
    private const ORDER_FAMILIES = ['OML', 'ORM'];

    private const SYSTEM_CLASSES = ['lis', 'lab', 'lab_middleware', 'ehr', 'ehr_cpoe'];

    public function supports(SourceMessage $message): bool
    {
        $raw = $message->payload['raw_hl7'] ?? null;
        if (! is_string($raw)) {
            return false;
        }

        $systemClass = strtolower((string) ($message->metadata['system_class'] ?? ''));
        if (! in_array($systemClass, self::SYSTEM_CLASSES, true)) {
            return false;
        }

        $parsed = Hl7V2Message::parse($raw);
        $family = strtoupper($parsed->messageType());

        return in_array($family, self::ORDER_FAMILIES, true)
            || ($family === 'ORU' && $this->hasCollectionAssertion($parsed));
    }

    public function normalize(SourceMessage $message): NormalizedPayload
    {
        $parsed = Hl7V2Message::parse((string) $message->payload['raw_hl7']);
        if (! $parsed->isValid()) {
            throw new AncillaryIngestException('invalid_hl7_structure', 'The Laboratory HL7 v2 order message structure is invalid.');
        }

        $family = strtoupper($parsed->messageType());
        if (! in_array($family, [...self::ORDER_FAMILIES, 'ORU'], true)) {
            throw new AncillaryIngestException('unsupported_message_family', 'The Laboratory order normalizer does not support this HL7 v2 family.');
        }

        $profile = AncillarySourceProfile::from($message);
        $profile->assertFamily($family);
        if ($profile->departments !== [] && ! in_array('lab', $profile->departments, true)) {
            throw new AncillaryIngestException('source_message_mismatch', 'The Laboratory order message is outside the source department scope.');
        }

        $controlId = trim($parsed->field('MSH', 10));
        if ($controlId === '') {
            throw new AncillaryIngestException('missing_control_identity', 'The Laboratory order message is missing its message control identity.');
        }

        $blocks = $this->orderBlocks($parsed);
        if ($blocks === []) {
            throw new AncillaryIngestException('missing_order_group', 'The Laboratory order message contains no OBR order group.');
        }

        $groups = [];
        foreach ($blocks as $index => $block) {
            $context = $this->orderContext($parsed, $profile, $block, $index + 1);
            $specimens = $this->specimens($parsed, $profile, $block, $context, $index + 1);

            if ($family !== 'ORU') {
                $groups[] = [
                    ...$context,
                    'specimens' => array_map($this->pendingSpecimen(...), $specimens),
                ];
            }

            foreach ($specimens as $specimen) {
                if (! isset($specimen['collected_at'])) {
                    continue;
                }

                $groups[] = array_filter([
                    ...$context,
                    ...$specimen,
                    'milestone_code' => 'LAB_COLLECTED',
                    'occurred_at' => $specimen['collected_at'],
                    'specimen_status' => 'collected',
                    'collection_source' => $specimen['collection_source'] ?? null,
                ], fn (mixed $value): bool => $value !== null && $value !== '');
            }
        }

        if ($groups === []) {
            throw new AncillaryIngestException('missing_collection_assertion', 'The Laboratory ORU message contains no asserted specimen collection time.');
        }

        $first = $groups[0];

        return new NormalizedPayload(
            messageType: 'HL7V2_'.$family,
            eventType: AncillaryEventVocabulary::eventTypeFor((string) $first['milestone_code']),
            payload: [...$first, 'order_groups' => $groups],
            idempotencyKey: implode(':', ['ancillary', $profile->sourceKey, $controlId]),
            externalId: $controlId,
            occurredAt: (string) $first['occurred_at'],
            metadata: [
                'connector_key' => 'ancillary.healthcare',
                'source_protocol' => 'hl7v2',
                'message_family' => $family,
                'trigger_event' => substr($parsed->triggerEvent(), 0, 20),
                'group_count' => count($groups),
                'order_group_count' => count($blocks),
            ],
        );
    }

    /**
     * @param  array{orc:?array<int,string>,obr:array<int,string>,segments:list<array<int,string>>}  $block
     * @return array<string, mixed>
     */
    private function orderContext(
        Hl7V2Message $message,
        AncillarySourceProfile $profile,
        array $block,
        int $group,
    ): array {
        $placer = $this->firstFilled([
            $this->field($message, $block['obr'], 2, 1),
            $this->field($message, $block['orc'], 2, 1),
        ]);
        $filler = $this->firstFilled([
            $this->field($message, $block['obr'], 3, 1),
            $this->field($message, $block['orc'], 3, 1),
        ]);
        if ($placer === null && $filler === null) {
            throw new AncillaryIngestException('missing_order_identity', 'A Laboratory order group is missing both placer and filler identities.', context: ['group' => $group]);
        }

        $test = $this->testIdentity($message, $block['obr']);
        $sourceOrderKey = $filler ?? implode(':', array_filter([$placer, $test['test_code'] ?? null]));
        $orderControl = strtoupper($this->field($message, $block['orc'], 1));
        $orderStatus = strtoupper($this->field($message, $block['orc'], 5));
        $cancelled = in_array($orderControl, ['CA', 'DC'], true) || in_array($orderStatus, ['CA', 'DC'], true);
        $orderedAt = $this->orderTimestamp($message, $block, $group);
        $patient = trim($message->field('PID', 3, 1));
        $encounter = trim($message->field('PV1', 19, 1));

        return array_filter([
            'department' => 'lab',
            'milestone_code' => $cancelled ? 'LAB_CANCELLED' : 'LAB_ORDERED',
            'work_item_type' => 'lab_order',
            'source_order_key' => $sourceOrderKey,
            'reconciliation_key' => $filler ?? implode(':', array_filter([$placer, $test['test_code'] ?? null])),
            'placer_order_key' => $placer,
            'filler_order_key' => $filler,
            'order_control' => $orderControl ?: null,
            'order_status' => $orderStatus ?: null,
            'patient_ref' => $patient !== '' ? $this->pseudonym($profile->sourceKey, 'patient', $patient) : null,
            'encounter_ref' => $encounter !== '' ? $this->pseudonym($profile->sourceKey, 'encounter', $encounter) : null,
            'patient_class' => $this->patientClass($message),
            'priority' => $this->priority($message, $block),
            'ordered_at' => $orderedAt->toIso8601String(),
            'ordered_at_source' => $this->field($message, $block['orc'], 9) !== '' ? 'ORC-9' : 'MSH-7',
            'occurred_at' => $orderedAt->toIso8601String(),
            ...$test,
            'add_on' => strtoupper($this->field($message, $block['obr'], 11)) === 'A' ?: null,
            'cancelled_at' => $cancelled ? $orderedAt->toIso8601String() : null,
            'source_timestamp_valid' => true,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array{orc:?array<int,string>,obr:array<int,string>,segments:list<array<int,string>>}  $block
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    private function specimens(
        Hl7V2Message $message,
        AncillarySourceProfile $profile,
        array $block,
        array $context,
        int $group,
    ): array {
        $segments = array_values(array_filter(
            $block['segments'],
            fn (array $segment): bool => ($segment[0] ?? null) === 'SPM',
        ));
        if ($segments === [] && $this->field($message, $block['obr'], 7) !== '') {
            $segments = [['SPM', '1', (string) ($context['filler_order_key'] ?? $context['source_order_key'])]];
        }

        $specimens = [];
        foreach ($segments as $position => $spm) {
            $specimenKey = $this->firstFilled([
                $this->field($message, $spm, 2, 1),
                $this->field($message, $spm, 2, 2),
            ]);
            if ($specimenKey === null) {
                throw new AncillaryIngestException('missing_specimen_identity', 'A Laboratory specimen group is missing its source specimen identity.', context: ['group' => $group, 'specimen' => $position + 1]);
            }

            $spmCollection = $this->field($message, $spm, 17, 1);
            $obrCollection = $this->field($message, $block['obr'], 7);
            $collection = $this->optionalTimestamp($spmCollection !== '' ? $spmCollection : $obrCollection, $group, 'collection');
            $collector = $this->field($message, $block['obr'], 10, 1);
            $role = strtoupper($this->field($message, $spm, 15, 1));

            $specimens[] = array_filter([
                'source_specimen_key' => $specimenKey,
                'source_specimen_business_key' => $specimenKey,
                'source_accession_key' => $context['filler_order_key'] ?? null,
                'specimen_type' => $this->field($message, $spm, 4, 1) ?: ($this->field($message, $block['obr'], 15, 1) ?: 'unknown'),
                'container_type' => $this->field($message, $spm, 27, 1) ?: null,
                'collector_role' => match ($role) {
                    'NURSE_COLLECT' => 'nurse_collect',
                    'LAB_COLLECT' => 'lab_collect',
                    default => null,
                },
                'collector_ref' => $collector !== '' ? $this->pseudonym($profile->sourceKey, 'collector', $collector) : null,
                'collection_method' => $this->field($message, $spm, 7, 1) ?: null,
                'collected_at' => $collection?->toIso8601String(),
                'collection_source' => $collection !== null ? ($spmCollection !== '' ? 'SPM-17' : 'OBR-7') : null,
            ], fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return $specimens;
    }

    /** @param array<string, mixed> $specimen @return array<string, mixed> */
    private function pendingSpecimen(array $specimen): array
    {
        unset($specimen['collected_at'], $specimen['collection_source']);

        return [...$specimen, 'specimen_status' => 'collection_pending'];
    }

    /**
     * @return list<array{orc:?array<int,string>,obr:array<int,string>,segments:list<array<int,string>>}>
     */
    private function orderBlocks(Hl7V2Message $message): array
    {
        $blocks = [];
        $currentOrc = null;
        $current = null;

        foreach ($message->segments as $segment) {
            $name = $segment[0] ?? null;
            if ($name === 'ORC') {
                if ($current !== null) {
                    $blocks[] = $current;
                    $current = null;
                }
                $currentOrc = $segment;

                continue;
            }
            if ($name === 'OBR') {
                if ($current !== null) {
                    $blocks[] = $current;
                }
                $current = ['orc' => $currentOrc, 'obr' => $segment, 'segments' => []];

                continue;
            }
            if ($current !== null) {
                $current['segments'][] = $segment;
            }
        }

        if ($current !== null) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /** @param array<int, string>|null $segment */
    private function field(Hl7V2Message $message, ?array $segment, int $field, ?int $component = null): string
    {
        if ($segment === null) {
            return '';
        }
        $value = $segment[$field] ?? '';
        $value = explode($message->repetitionSeparator, $value)[0] ?? '';
        if ($component !== null) {
            $value = explode($message->componentSeparator, $value)[$component - 1] ?? '';
        }

        return trim($message->decode($value));
    }

    /** @param array<int, string> $obr @return array<string, string> */
    private function testIdentity(Hl7V2Message $message, array $obr): array
    {
        $primary = [
            'code' => $this->field($message, $obr, 4, 1),
            'label' => $this->field($message, $obr, 4, 2),
            'system' => strtoupper($this->field($message, $obr, 4, 3)),
        ];
        $alternate = [
            'code' => $this->field($message, $obr, 4, 4),
            'label' => $this->field($message, $obr, 4, 5),
            'system' => strtoupper($this->field($message, $obr, 4, 6)),
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

    /** @param array{orc:?array<int,string>,obr:array<int,string>,segments:list<array<int,string>>} $block */
    private function orderTimestamp(Hl7V2Message $message, array $block, int $group): CarbonImmutable
    {
        foreach ([$this->field($message, $block['orc'], 9), $message->field('MSH', 7)] as $value) {
            $timestamp = $this->optionalTimestamp($value, $group, 'order');
            if ($timestamp !== null) {
                return $timestamp;
            }
        }

        throw new AncillaryIngestException('missing_timestamp', 'A Laboratory order group is missing its order timestamp.', context: ['group' => $group]);
    }

    private function optionalTimestamp(string $value, int $group, string $kind): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^(\d{4})(\d{2})?(\d{2})?(\d{2})?(\d{2})?(\d{2})?(?:\.(\d{1,6}))?([+-]\d{4})?$/', $value, $parts)) {
            throw new AncillaryIngestException('malformed_timestamp', "The Laboratory {$kind} timestamp is malformed.", context: ['group' => $group]);
        }

        $normalized = ($parts[1] ?? '0000').($parts[2] ?? '01').($parts[3] ?? '01').($parts[4] ?? '00').($parts[5] ?? '00').($parts[6] ?? '00');
        $fraction = isset($parts[7]) && $parts[7] !== '' ? '.'.str_pad($parts[7], 6, '0') : '.000000';
        $offset = isset($parts[8]) && $parts[8] !== '' ? $parts[8] : '+0000';
        $parsed = CarbonImmutable::createFromFormat('!YmdHis.uO', $normalized.$fraction.$offset);
        if ($parsed === false) {
            throw new AncillaryIngestException('malformed_timestamp', "The Laboratory {$kind} timestamp is malformed.", context: ['group' => $group]);
        }

        return $parsed;
    }

    /** @param array{orc:?array<int,string>,obr:array<int,string>,segments:list<array<int,string>>} $block */
    private function priority(Hl7V2Message $message, array $block): string
    {
        $value = strtoupper($this->firstFilled([
            $this->field($message, $block['orc'], 7, 6),
            $this->field($message, $block['obr'], 27, 6),
        ]) ?? 'ROUTINE');

        return match ($value) {
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

    private function hasCollectionAssertion(Hl7V2Message $message): bool
    {
        foreach ($message->all('OBR') as $obr) {
            if ($this->field($message, $obr, 7) !== '') {
                return true;
            }
        }
        foreach ($message->all('SPM') as $spm) {
            if ($this->field($message, $spm, 17, 1) !== '') {
                return true;
            }
        }

        return false;
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
