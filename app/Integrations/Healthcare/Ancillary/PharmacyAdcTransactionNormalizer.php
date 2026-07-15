<?php

namespace App\Integrations\Healthcare\Ancillary;

use App\Integrations\Healthcare\Contracts\SourceMessageNormalizer;
use App\Integrations\Healthcare\DTO\NormalizedPayload;
use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Normalizes the versioned, vendor-neutral ADC transaction JSON envelope
 * (message family RX_ADC). Vendor-specific cabinet exports — Pyxis
 * transaction extracts (TransactionType/DeviceID/MedID/OrderID) and Omnicell
 * OmniCenter events (TxnType/OmniID/ItemID/OrderNumber) — are mapped into
 * this envelope at the adapter edge; no vendor field names cross this
 * boundary. Order-linked vends, returns, and wastes map to their catalog
 * milestones on the linked order; every supported transaction is persisted
 * as a station/unit-level `prod.adc_transactions` row; unlinked overrides
 * and discrepancies stay at station/unit operational level per §7.4/§13.
 *
 * Envelope schema, version 1:
 * - envelope_version  int, REQUIRED, must equal 1.
 * - transaction_id    string, REQUIRED. Vendor transaction identity; the
 *                     source-scoped natural key. A re-sent transaction with
 *                     the same transaction_id is a duplicate.
 * - transaction_type  REQUIRED: 'vend' | 'refill' | 'return' | 'waste' |
 *                     'override' | 'discrepancy_open' |
 *                     'discrepancy_resolved' | 'stockout'.
 * - occurred_at       ISO-8601 timestamp WITH explicit offset, REQUIRED.
 * - station           object, REQUIRED:
 *                     - station_key  string, REQUIRED. Vendor device
 *                       identity; the source-scoped station registry key.
 *                     - unit         string, REQUIRED. Hospital unit
 *                       abbreviation or name; resolved against prod.units.
 *                       An unresolvable unit dead-letters as
 *                       `unmapped_station_unit` — never silent coercion.
 *                     - label        string, optional (defaults to
 *                       station_key).
 *                     - station_type optional: 'general' | 'anesthesia' |
 *                       'procedural' | 'emergency' | 'other'.
 *                     - is_profiled / controlled_capable  booleans, optional.
 * - medication        object; REQUIRED for vend/refill/return/waste/override,
 *                     optional for discrepancy and stockout events:
 *                     - local_code  string, REQUIRED within the object.
 *                     - ndc_code / label  optional. Codes resolve against
 *                       the governed formulary; a missing mapping produces
 *                       the explicit `unmapped_local` flag, never a failure.
 * - order             object, OPTIONAL. An EXPLICIT source order link only —
 *                     the pipeline never guesses order attachment:
 *                     - source_order_key  string, REQUIRED within the object.
 *                     - placer_order_key  string, optional.
 * - quantity          numeric > 0, optional.
 * - is_controlled     boolean, optional; the governed formulary wins when
 *                     the medication code maps.
 * - discrepancy_key   string; REQUIRED for discrepancy_open and
 *                     discrepancy_resolved (pairs open/resolve rows).
 * - stockout_state    'open' | 'resolved'; stockout only, default 'open'.
 * - patient_id / encounter_id  raw source identifiers, optional; always
 *                     pseudonymized, never persisted raw.
 *
 * PROHIBITED: the envelope defines NO user, actor, staff, witness, badge, or
 * employee identity field and NO risk, score, rank, or label field. Vendor
 * user attribution must be dropped at the adapter edge; unknown keys never
 * cross this normalizer because the canonical payload is built exclusively
 * from the documented fields above (§13: station/unit aggregates only).
 */
final class PharmacyAdcTransactionNormalizer implements SourceMessageNormalizer
{
    public const ENVELOPE_VERSION = 1;

    public const STATION_EVENT_TYPE = 'ancillary.pharmacy.adc_transaction';

    /** @var list<string> */
    public const TRANSACTION_TYPES = [
        'vend', 'refill', 'return', 'waste', 'override',
        'discrepancy_open', 'discrepancy_resolved', 'stockout',
    ];

    /** Every key envelope v1 may carry — the safety test asserts no user/actor/risk field ever joins. */
    public const ENVELOPE_KEYS = [
        'envelope_version', 'transaction_id', 'transaction_type', 'occurred_at',
        'station', 'medication', 'order', 'quantity', 'is_controlled',
        'discrepancy_key', 'stockout_state', 'patient_id', 'encounter_id',
    ];

    private const FAMILY = 'RX_ADC';

    private const STATION_TYPES = ['general', 'anesthesia', 'procedural', 'emergency', 'other'];

    private const STOCKOUT_STATES = ['open', 'resolved'];

    private const LINKED_MILESTONES = ['vend' => 'RX_DISPENSED', 'return' => 'RX_RETURNED', 'waste' => 'RX_WASTED'];

    private const MEDICATION_REQUIRED = ['vend', 'refill', 'return', 'waste', 'override'];

    private const STATION_EVENT_CODES = [
        'override' => 'RX_OVERRIDE',
        'discrepancy_open' => 'RX_DISCREPANCY_OPEN',
        'discrepancy_resolved' => 'RX_DISCREPANCY_RESOLVED',
    ];

    public function supports(SourceMessage $message): bool
    {
        return $this->family($message) === self::FAMILY;
    }

    public function normalize(SourceMessage $message): NormalizedPayload
    {
        $profile = AncillarySourceProfile::from($message);
        $profile->assertFamily(self::FAMILY);
        if ($profile->departments !== [] && ! in_array('rx', $profile->departments, true)) {
            throw new AncillaryIngestException('source_message_mismatch', 'The ADC transaction event is outside the source department scope.');
        }

        $version = $message->payload['envelope_version'] ?? null;
        if (! is_int($version) || $version !== self::ENVELOPE_VERSION) {
            throw new AncillaryIngestException('unsupported_envelope_version', 'The ADC transaction envelope version is unsupported.');
        }

        $transactionId = $this->required($message->payload, 'transaction_id', 'missing_transaction_identity');
        $type = strtolower($this->required($message->payload, 'transaction_type', 'invalid_transaction_type'));
        if (! in_array($type, self::TRANSACTION_TYPES, true)) {
            throw new AncillaryIngestException('invalid_transaction_type', 'The ADC transaction type is unsupported.');
        }

        $occurredAt = $this->timestamp($message->payload['occurred_at'] ?? null, 'occurred_at');
        $station = $this->station($message->payload);
        $medication = $this->medication($message->payload, $type);
        $orderKey = $this->orderLink($message->payload);
        $quantity = $this->quantity($message->payload);
        $discrepancyKey = $this->discrepancyKey($message->payload, $type);
        $stockoutState = $this->stockoutState($message->payload, $type);

        $adc = [
            'department' => 'rx',
            'source_transaction_key' => $transactionId,
            'transaction_type' => $type,
            ...$station,
            ...$medication,
            'quantity' => $quantity,
            'is_controlled' => is_bool($message->payload['is_controlled'] ?? null)
                ? $message->payload['is_controlled']
                : null,
            'discrepancy_key' => $discrepancyKey,
            'stockout_state' => $stockoutState,
            'occurred_at' => $occurredAt->toIso8601String(),
            'source_timestamp_valid' => true,
        ];

        $milestone = self::LINKED_MILESTONES[$type] ?? null;
        $payload = $orderKey !== null && $milestone !== null
            ? $this->linkedOrderPayload($profile, $message->payload, $adc, $milestone, $orderKey, $transactionId, $occurredAt)
            : $this->stationScopePayload($adc, $type, $orderKey);

        return new NormalizedPayload(
            messageType: 'ANCILLARY_'.self::FAMILY,
            eventType: $milestone !== null && $orderKey !== null
                ? AncillaryEventVocabulary::eventTypeFor($milestone)
                : self::STATION_EVENT_TYPE,
            payload: array_filter($payload, fn (mixed $value): bool => $value !== null && $value !== ''),
            idempotencyKey: implode(':', ['ancillary', $profile->sourceKey, $transactionId]),
            externalId: $transactionId,
            occurredAt: $occurredAt->toIso8601String(),
            metadata: [
                'connector_key' => 'ancillary.healthcare',
                'source_protocol' => strtolower((string) ($message->metadata['source_protocol'] ?? 'forwarded_json')),
                'message_family' => self::FAMILY,
                'envelope_version' => self::ENVELOPE_VERSION,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<string, mixed>  $adc
     * @return array<string, mixed>
     */
    private function linkedOrderPayload(
        AncillarySourceProfile $profile,
        array $envelope,
        array $adc,
        string $milestone,
        string $orderKey,
        string $transactionId,
        CarbonImmutable $occurredAt,
    ): array {
        $order = is_array($envelope['order'] ?? null) ? $envelope['order'] : [];
        $placer = trim((string) ($order['placer_order_key'] ?? '')) ?: null;
        $patient = trim((string) ($envelope['patient_id'] ?? ''));
        $encounter = trim((string) ($envelope['encounter_id'] ?? ''));

        return [
            ...$adc,
            'milestone_code' => $milestone,
            'work_item_type' => 'medication_order',
            'source_order_key' => $orderKey,
            'reconciliation_key' => $orderKey,
            'placer_order_key' => $placer,
            'patient_ref' => $patient !== '' ? $this->pseudonym($profile->sourceKey, 'patient', $patient) : null,
            'encounter_ref' => $encounter !== '' ? $this->pseudonym($profile->sourceKey, 'encounter', $encounter) : null,
            'priority' => 'unknown',
            'ordered_at' => $occurredAt->toIso8601String(),
            'ordered_at_source' => 'adc_fallback',
            'source_dispense_key' => $milestone === 'RX_DISPENSED' ? $transactionId : null,
            'dispense_channel' => $milestone === 'RX_DISPENSED' ? 'adc' : null,
            'dispensed_at' => $milestone === 'RX_DISPENSED' ? $occurredAt->toIso8601String() : null,
        ];
    }

    /**
     * Station/unit operational scope: no milestone, no ancillary order, and
     * no order attachment beyond the explicit source link candidate.
     *
     * @param  array<string, mixed>  $adc
     * @return array<string, mixed>
     */
    private function stationScopePayload(array $adc, string $type, ?string $orderKey): array
    {
        return [
            ...$adc,
            'adc_station_scope' => true,
            'rx_event_code' => self::STATION_EVENT_CODES[$type] ?? null,
            'linked_order_key' => $orderKey,
        ];
    }

    /** @param array<string, mixed> $envelope @return array<string, mixed> */
    private function station(array $envelope): array
    {
        $station = $envelope['station'] ?? null;
        if (! is_array($station) || trim((string) ($station['station_key'] ?? '')) === '') {
            throw new AncillaryIngestException('missing_station_identity', 'The ADC transaction event is missing its station identity.');
        }
        $unit = trim((string) ($station['unit'] ?? ''));
        if ($unit === '') {
            throw new AncillaryIngestException('missing_station_unit', 'The ADC transaction station is missing its unit mapping.');
        }
        $stationType = strtolower(trim((string) ($station['station_type'] ?? ''))) ?: null;
        if ($stationType !== null && ! in_array($stationType, self::STATION_TYPES, true)) {
            throw new AncillaryIngestException('invalid_station_type', 'The ADC station type is unsupported.');
        }

        return [
            'station_key' => trim((string) $station['station_key']),
            'station_unit' => $unit,
            'station_label' => trim((string) ($station['label'] ?? '')) ?: null,
            'station_type' => $stationType,
            'station_is_profiled' => is_bool($station['is_profiled'] ?? null) ? $station['is_profiled'] : null,
            'station_controlled_capable' => is_bool($station['controlled_capable'] ?? null) ? $station['controlled_capable'] : null,
        ];
    }

    /** @param array<string, mixed> $envelope @return array<string, mixed> */
    private function medication(array $envelope, string $type): array
    {
        $medication = is_array($envelope['medication'] ?? null) ? $envelope['medication'] : null;
        $localCode = $medication !== null ? trim((string) ($medication['local_code'] ?? '')) : '';
        if ($localCode === '') {
            if (in_array($type, self::MEDICATION_REQUIRED, true)) {
                throw new AncillaryIngestException('missing_medication_identity', 'The ADC transaction event is missing its medication identity.');
            }

            return ['local_code' => null, 'ndc_code' => null, 'medication_label' => null];
        }

        return [
            'local_code' => $localCode,
            'ndc_code' => trim((string) ($medication['ndc_code'] ?? '')) ?: null,
            'medication_label' => trim((string) ($medication['label'] ?? '')) ?: null,
        ];
    }

    /** @param array<string, mixed> $envelope */
    private function orderLink(array $envelope): ?string
    {
        if (! array_key_exists('order', $envelope)) {
            return null;
        }
        $order = $envelope['order'];
        if (! is_array($order) || trim((string) ($order['source_order_key'] ?? '')) === '') {
            throw new AncillaryIngestException('missing_order_identity', 'The ADC transaction order link is missing its source order identity.');
        }

        return trim((string) $order['source_order_key']);
    }

    /** @param array<string, mixed> $envelope */
    private function quantity(array $envelope): ?float
    {
        if (! isset($envelope['quantity'])) {
            return null;
        }
        if (! is_numeric($envelope['quantity']) || (float) $envelope['quantity'] <= 0) {
            throw new AncillaryIngestException('invalid_quantity', 'The ADC transaction quantity must be a positive number.');
        }

        return (float) $envelope['quantity'];
    }

    /** @param array<string, mixed> $envelope */
    private function discrepancyKey(array $envelope, string $type): ?string
    {
        $key = trim((string) ($envelope['discrepancy_key'] ?? '')) ?: null;
        if ($key === null && in_array($type, ['discrepancy_open', 'discrepancy_resolved'], true)) {
            throw new AncillaryIngestException('missing_discrepancy_key', 'The ADC discrepancy event is missing its pairing key.');
        }

        return $key;
    }

    /** @param array<string, mixed> $envelope */
    private function stockoutState(array $envelope, string $type): ?string
    {
        if ($type !== 'stockout') {
            return null;
        }
        $state = strtolower(trim((string) ($envelope['stockout_state'] ?? 'open'))) ?: 'open';
        if (! in_array($state, self::STOCKOUT_STATES, true)) {
            throw new AncillaryIngestException('invalid_stockout_state', 'The ADC stockout state is unsupported.');
        }

        return $state;
    }

    private function family(SourceMessage $message): string
    {
        return strtoupper((string) preg_replace('/^(ANCILLARY[._-]|STRUCTURED[._-])/', '', strtoupper($message->messageType)));
    }

    /** @param array<string, mixed> $payload */
    private function required(array $payload, string $key, string $reasonCode): string
    {
        $value = $payload[$key] ?? null;
        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new AncillaryIngestException($reasonCode, "The ADC transaction event is missing its {$key}.");
        }

        return trim((string) $value);
    }

    private function timestamp(mixed $value, string $field): CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            throw new AncillaryIngestException('missing_timestamp', "The ADC transaction event is missing {$field}.");
        }
        if (! preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $value)) {
            throw new AncillaryIngestException('malformed_timestamp', "The ADC transaction {$field} must include an explicit offset.");
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable $exception) {
            throw new AncillaryIngestException('malformed_timestamp', "The ADC transaction {$field} is malformed.", previous: $exception);
        }
    }

    private function pseudonym(string $sourceKey, string $kind, string $value): string
    {
        return hash_hmac('sha256', implode('|', [$sourceKey, $kind, $value]), (string) config('app.key'));
    }
}
