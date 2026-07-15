<?php

namespace App\Integrations\Healthcare\Ancillary;

use App\Integrations\Healthcare\Contracts\SourceMessageNormalizer;
use App\Integrations\Healthcare\DTO\NormalizedPayload;
use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use App\Services\PatientFlow\Hl7V2Message;
use Carbon\CarbonImmutable;

/**
 * Normalizes governed Pharmacy HL7 v2 messages: RDE^O11 medication orders
 * (ORC/RXE/RXR/TQ1) and RDS^O13 dispenses (ORC/RXD).
 *
 * Contract notes:
 * - ORC-9 is treated as the order's ordering-time assertion on every RDE
 *   lifecycle message (NW and XO alike). An XO whose ORC-9 disagrees with the
 *   original surfaces through the shared RX_ORDERED disagreement flag instead
 *   of silently moving the ordered clock. Vendor feeds that put the change
 *   time in ORC-9 must remap at the adapter edge.
 * - DC maps to the terminal RX_DISCONTINUED milestone; CA maps to the same
 *   milestone with cancellation evidence on the satellite.
 * - RDS never asserts a clock class or ordering priority; a dispense-first
 *   order is created with fallback ordering context that a later RDE upgrades.
 */
final class PharmacyOrderHl7V2Normalizer implements SourceMessageNormalizer
{
    private const FAMILIES = ['RDE', 'RDS'];

    private const SYSTEM_CLASSES = ['pharmacy', 'pharmacy_system', 'ehr', 'ehr_cpoe'];

    private const ORDER_CONTROLS = ['NW', 'XO', 'DC', 'CA'];

    private const DISPENSE_CHANNELS = ['iv_room', 'central', 'robot', 'other'];

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

        return in_array(strtoupper(Hl7V2Message::parse($raw)->messageType()), self::FAMILIES, true);
    }

    public function normalize(SourceMessage $message): NormalizedPayload
    {
        $parsed = Hl7V2Message::parse((string) $message->payload['raw_hl7']);
        if (! $parsed->isValid()) {
            throw new AncillaryIngestException('invalid_hl7_structure', 'The Pharmacy HL7 v2 message structure is invalid.');
        }

        $family = strtoupper($parsed->messageType());
        if (! in_array($family, self::FAMILIES, true)) {
            throw new AncillaryIngestException('unsupported_message_family', 'The Pharmacy normalizer does not support this HL7 v2 family.');
        }

        $profile = AncillarySourceProfile::from($message);
        $profile->assertFamily($family);
        if ($profile->departments !== [] && ! in_array('rx', $profile->departments, true)) {
            throw new AncillaryIngestException('source_message_mismatch', 'The Pharmacy message is outside the source department scope.');
        }

        $controlId = trim($parsed->field('MSH', 10));
        if ($controlId === '') {
            throw new AncillaryIngestException('missing_control_identity', 'The Pharmacy message is missing its message control identity.');
        }

        $orc = $parsed->first('ORC');
        if ($orc === null) {
            throw new AncillaryIngestException('missing_order_group', 'The Pharmacy message contains no ORC order group.');
        }

        $placer = $this->firstFilled([$parsed->field('ORC', 2, 1)]);
        $filler = $this->firstFilled([$parsed->field('ORC', 3, 1)]);
        if ($placer === null && $filler === null) {
            throw new AncillaryIngestException('missing_order_identity', 'The Pharmacy message is missing both placer and filler order identities.');
        }

        $identity = $this->identityContext($parsed, $profile, $placer, $filler);
        $payload = $family === 'RDE'
            ? $this->medicationOrder($parsed, $identity)
            : $this->dispense($parsed, $profile, $identity);

        return new NormalizedPayload(
            messageType: 'HL7V2_'.$family,
            eventType: AncillaryEventVocabulary::eventTypeFor((string) $payload['milestone_code']),
            payload: $payload,
            idempotencyKey: implode(':', ['ancillary', $profile->sourceKey, $controlId]),
            externalId: $controlId,
            occurredAt: (string) $payload['occurred_at'],
            metadata: [
                'connector_key' => 'ancillary.healthcare',
                'source_protocol' => 'hl7v2',
                'message_family' => $family,
                'trigger_event' => substr($parsed->triggerEvent(), 0, 20),
            ],
        );
    }

    /** @return array<string, mixed> */
    private function identityContext(
        Hl7V2Message $parsed,
        AncillarySourceProfile $profile,
        ?string $placer,
        ?string $filler,
    ): array {
        $patient = trim($parsed->field('PID', 3, 1));
        $encounter = trim($parsed->field('PV1', 19, 1));

        return array_filter([
            'department' => 'rx',
            'work_item_type' => 'medication_order',
            'source_order_key' => $filler ?? $placer,
            'reconciliation_key' => $filler ?? $placer,
            'placer_order_key' => $placer,
            'filler_order_key' => $filler,
            'order_control' => strtoupper($parsed->field('ORC', 1)) ?: null,
            'order_status' => strtoupper($parsed->field('ORC', 5)) ?: null,
            'patient_ref' => $patient !== '' ? $this->pseudonym($profile->sourceKey, 'patient', $patient) : null,
            'encounter_ref' => $encounter !== '' ? $this->pseudonym($profile->sourceKey, 'encounter', $encounter) : null,
            'patient_class' => $this->patientClass($parsed),
            'source_timestamp_valid' => true,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @param array<string, mixed> $identity @return array<string, mixed> */
    private function medicationOrder(Hl7V2Message $parsed, array $identity): array
    {
        if ($parsed->first('RXE') === null) {
            throw new AncillaryIngestException('missing_medication_group', 'The Pharmacy RDE message contains no RXE medication group.');
        }

        $orderControl = (string) ($identity['order_control'] ?? '');
        if (! in_array($orderControl, self::ORDER_CONTROLS, true)) {
            throw new AncillaryIngestException('unsupported_order_control', 'The Pharmacy RDE order control code is unsupported.', context: ['order_control' => substr($orderControl, 0, 8)]);
        }

        $coding = $this->medicationCoding($parsed);
        $timing = $this->timing($parsed);
        $clockClass = $this->clockClass($parsed, $timing);
        $orderedAt = $this->requiredTimestamp($parsed, [['ORC', 9], ['MSH', 7]], 'order');
        $orderedAtSource = trim($parsed->field('ORC', 9)) !== '' ? 'ORC-9' : 'MSH-7';
        $transactionAt = $this->requiredTimestamp($parsed, [['MSH', 7], ['ORC', 9]], 'transaction');
        $terminal = in_array($orderControl, ['DC', 'CA'], true);
        $occurredAt = $terminal ? $transactionAt : $orderedAt;

        return array_filter([
            ...$identity,
            'milestone_code' => $terminal ? 'RX_DISCONTINUED' : 'RX_ORDERED',
            'priority' => $clockClass,
            'clock_class' => $clockClass,
            'hl7_priority' => $timing['priority_code'] ?: null,
            'due_at' => $timing['start_at']?->toIso8601String(),
            'ordered_at' => $orderedAt->toIso8601String(),
            'ordered_at_source' => $orderedAtSource,
            'occurred_at' => $occurredAt->toIso8601String(),
            ...$coding,
            'route' => $this->firstFilled([$parsed->field('RXR', 1, 1)]),
            'dosage_form' => $this->firstFilled([$parsed->field('RXE', 6, 1)]),
            'order_change' => $orderControl === 'XO' ?: null,
            'modified_at' => $orderControl === 'XO' ? $transactionAt->toIso8601String() : null,
            'discontinued_at' => $orderControl === 'DC' ? $transactionAt->toIso8601String() : null,
            'cancelled_at' => $orderControl === 'CA' ? $transactionAt->toIso8601String() : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @param array<string, mixed> $identity @return array<string, mixed> */
    private function dispense(Hl7V2Message $parsed, AncillarySourceProfile $profile, array $identity): array
    {
        if ($parsed->first('RXD') === null) {
            throw new AncillaryIngestException('missing_dispense_group', 'The Pharmacy RDS message contains no RXD dispense group.');
        }

        $dispensedAt = $parsed->timestamp('RXD', 3);
        if ($dispensedAt === null) {
            throw new AncillaryIngestException('missing_timestamp', 'The Pharmacy RDS message is missing its RXD-3 dispense timestamp.');
        }

        $dispenseKey = $this->firstFilled([$parsed->field('RXD', 7, 1)])
            ?? implode(':', [$identity['source_order_key'], $this->firstFilled([$parsed->field('RXD', 1, 1)]) ?? '1']);
        $coding = $this->dispenseCoding($parsed);

        return array_filter([
            ...$identity,
            'milestone_code' => 'RX_DISPENSED',
            'priority' => 'unknown',
            'ordered_at' => $dispensedAt->toIso8601String(),
            'ordered_at_source' => 'dispense_fallback',
            'occurred_at' => $dispensedAt->toIso8601String(),
            'source_dispense_key' => $dispenseKey,
            'dispense_channel' => $this->dispenseChannel($profile),
            'dispensed_at' => $dispensedAt->toIso8601String(),
            ...$coding,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @return array<string, string> */
    private function medicationCoding(Hl7V2Message $parsed): array
    {
        $coding = $this->cwe($parsed, 'RXE', 2);
        if (($coding['local_code'] ?? null) === null) {
            throw new AncillaryIngestException('missing_medication_identity', 'The Pharmacy RDE give code is missing its medication identity.');
        }

        return $coding;
    }

    /** @return array<string, string> */
    private function dispenseCoding(Hl7V2Message $parsed): array
    {
        return $this->cwe($parsed, 'RXD', 2);
    }

    /**
     * Splits a CWE coded field into local, RxNorm, and NDC mapping candidates
     * from the primary, alternate, and second-alternate coding triplets.
     *
     * @return array<string, string>
     */
    private function cwe(Hl7V2Message $parsed, string $segment, int $field): array
    {
        $local = null;
        $rxnorm = null;
        $ndc = null;
        foreach ([[1, 2, 3], [4, 5, 6], [10, 11, 12]] as [$codeAt, $labelAt, $systemAt]) {
            $code = trim($parsed->field($segment, $field, $codeAt));
            if ($code === '') {
                continue;
            }
            $candidate = ['code' => $code, 'label' => trim($parsed->field($segment, $field, $labelAt))];
            match (strtoupper(trim($parsed->field($segment, $field, $systemAt)))) {
                'RXNORM', 'RXN' => $rxnorm ??= $candidate,
                'NDC' => $ndc ??= $candidate,
                default => $local ??= $candidate,
            };
        }
        $label = $local['label'] ?? ($rxnorm['label'] ?? ($ndc['label'] ?? null));

        return array_filter([
            'local_code' => $local['code'] ?? ($rxnorm['code'] ?? ($ndc['code'] ?? null)),
            'medication_label' => $label,
            'rxnorm_cui' => $rxnorm['code'] ?? null,
            'ndc_code' => $ndc['code'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @return array{priority_code: string, priority_text: string, start_at: ?CarbonImmutable} */
    private function timing(Hl7V2Message $parsed): array
    {
        $priorityCode = strtoupper($this->firstFilled([
            $parsed->field('TQ1', 9, 1),
            $parsed->field('ORC', 7, 6),
        ]) ?? '');
        $priorityText = strtoupper(implode(' ', array_filter([
            trim($parsed->field('TQ1', 9, 2)),
            trim($parsed->field('TQ1', 11)),
        ])));
        $startRaw = trim($parsed->field('TQ1', 7));
        $startAt = null;
        if ($startRaw !== '') {
            $startAt = $parsed->timestamp('TQ1', 7);
            if ($startAt === null) {
                throw new AncillaryIngestException('malformed_timestamp', 'The Pharmacy TQ1 start timestamp is malformed.');
            }
        }

        return ['priority_code' => $priorityCode, 'priority_text' => $priorityText, 'start_at' => $startAt];
    }

    /**
     * Clock-class precedence: explicit order context beats generic priority.
     * Sepsis (ORC-16 reason) > discharge (ORC-29 outpatient order in a
     * non-outpatient class) > first dose (TQ1 FD / FIRST DOSE) > stat
     * (S/A/STAT/ASAP) > timed (TQ1 T with an explicit start) > routine.
     *
     * @param  array{priority_code: string, priority_text: string, start_at: ?CarbonImmutable}  $timing
     */
    private function clockClass(Hl7V2Message $parsed, array $timing): string
    {
        $reason = strtoupper(trim($parsed->field('ORC', 16, 1)).' '.trim($parsed->field('ORC', 16, 2)));
        if (str_contains($reason, 'SEPSIS')) {
            return 'sepsis';
        }
        if (strtoupper(trim($parsed->field('ORC', 29, 1))) === 'O' && $this->patientClass($parsed) !== 'outpatient') {
            return 'discharge';
        }
        if ($timing['priority_code'] === 'FD' || str_contains($timing['priority_text'], 'FIRST DOSE')) {
            return 'first_dose';
        }
        if (in_array($timing['priority_code'], ['S', 'STAT', 'A', 'ASAP'], true)) {
            return 'stat';
        }
        if (in_array($timing['priority_code'], ['T', 'TS'], true) && $timing['start_at'] !== null) {
            return 'timed';
        }

        return 'routine';
    }

    private function dispenseChannel(AncillarySourceProfile $profile): string
    {
        $configured = strtolower(trim((string) ($profile->dispenseChannel ?? '')));

        return in_array($configured, self::DISPENSE_CHANNELS, true) ? $configured : 'central';
    }

    /** @param list<array{0: string, 1: int}> $candidates */
    private function requiredTimestamp(Hl7V2Message $parsed, array $candidates, string $kind): CarbonImmutable
    {
        foreach ($candidates as [$segment, $field]) {
            if (trim($parsed->field($segment, $field)) === '') {
                continue;
            }
            $timestamp = $parsed->timestamp($segment, $field);
            if ($timestamp === null) {
                throw new AncillaryIngestException('malformed_timestamp', "The Pharmacy {$kind} timestamp is malformed.");
            }

            return $timestamp;
        }

        throw new AncillaryIngestException('missing_timestamp', "The Pharmacy message is missing its {$kind} timestamp.");
    }

    private function patientClass(Hl7V2Message $parsed): string
    {
        return match (strtoupper(trim($parsed->field('PV1', 2)))) {
            'E' => 'emergency',
            'I' => 'inpatient',
            'O' => 'outpatient',
            'P' => 'perioperative',
            default => 'unknown',
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
