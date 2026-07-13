<?php

namespace App\Integrations\Healthcare\Ancillary;

use App\Integrations\Healthcare\Contracts\SourceMessageNormalizer;
use App\Integrations\Healthcare\DTO\NormalizedPayload;
use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Normalizes the versioned, vendor-neutral PharmacyAdministrationImport batch
 * contract (message family RX_ADMIN_BATCH). BCMA/eMAR administration facts
 * usually arrive from a clinical warehouse on a NIGHTLY batch cadence, so
 * every row is stamped with the batch's as-of source cutoff and every
 * administration-dependent metric downstream must label that cutoff and
 * refuse real-time claims (§2, §8). Vendor extract layouts (Epic Clarity
 * MAR, Cerner CareAdmin warehouse views, generic eMAR CSV drops) are mapped
 * into this envelope at the adapter edge; no vendor field names cross this
 * boundary. The importer submits ONE message per batch row with the batch
 * header repeated, so raw/canonical/projection/provenance evidence exists
 * per administration fact.
 *
 * Per-message envelope schema, version 1:
 * - envelope_version  int, REQUIRED, must equal 1.
 * - extract_id        string, REQUIRED. Warehouse extract/batch identity;
 *                     persisted as `import_batch_key` on every projected row.
 * - source_cutoff_at  ISO-8601 timestamp WITH explicit offset, REQUIRED.
 *                     The warehouse as-of boundary for the whole batch; a row
 *                     administered after this cutoff fails closed.
 * - source_class      optional: 'bcma_warehouse' (DEFAULT — the baseline
 *                     assumption) | 'emar' | 'other' (batch classes) |
 *                     'bcma_realtime' | 'ras' (FUTURE real-time
 *                     administration source classes, supported by the
 *                     contract without ever becoming the baseline).
 * - administration    object, REQUIRED — one warehouse row:
 *                     - administration_id  string, REQUIRED. The source
 *                       row identity (`source_administration_key`).
 *                     - row_version  string, optional. Corrections re-send
 *                       the same administration_id with a new row_version;
 *                       each version is APPENDED, never overwritten.
 *                     - order  object, REQUIRED — order linkage keys only;
 *                       the projector attaches the milestone ONLY when the
 *                       keys match exactly one existing medication order:
 *                       - source_order_key  string, REQUIRED (filler key).
 *                       - placer_order_key  string, optional fallback key.
 *                     - administered_at  ISO-8601 with explicit offset,
 *                       REQUIRED, must be <= source_cutoff_at.
 *                     - status  optional: 'given' (default) | 'held' |
 *                       'refused' | 'missed'. Only 'given' asserts the
 *                       RX_ADMINISTERED milestone; the rest persist as
 *                       satellite facts without any milestone or SLA clock.
 *                     - medication  object, optional coding candidates:
 *                       local_code / ndc_code / rxnorm_cui / label. Carried
 *                       as administration-scoped evidence only — they never
 *                       rewrite the order's formulary-resolved terminology.
 *                     - route / dosage_form  strings, optional operational
 *                       context (minimum necessary).
 *                     - patient_id / encounter_id  raw source identifiers,
 *                       optional; always pseudonymized, never persisted raw.
 *
 * PROHIBITED: the envelope defines NO administering-user, nurse, witness,
 * badge, or employee identity field and NO clinical dose value (amount,
 * strength, rate) beyond the operational route/form context. Vendor
 * attribution or dose columns must be dropped at the adapter edge; unknown
 * keys never cross this normalizer because the canonical payload is built
 * exclusively from the documented fields above.
 */
final class PharmacyAdministrationImportNormalizer implements SourceMessageNormalizer
{
    public const ENVELOPE_VERSION = 1;

    public const FAMILY = 'RX_ADMIN_BATCH';

    public const RECORD_EVENT_TYPE = 'ancillary.pharmacy.administration_record';

    /** @var list<string> */
    public const SOURCE_CLASSES = ['bcma_warehouse', 'bcma_realtime', 'ras', 'emar', 'other'];

    /** @var list<string> */
    public const STATUSES = ['given', 'held', 'refused', 'missed'];

    /** Every top-level key envelope v1 may carry — asserted free of user/actor/dose fields. */
    public const ENVELOPE_KEYS = ['envelope_version', 'extract_id', 'source_cutoff_at', 'source_class', 'administration'];

    /** Every administration-row key envelope v1 may carry. */
    public const ROW_KEYS = [
        'administration_id', 'row_version', 'order', 'administered_at', 'status',
        'medication', 'route', 'dosage_form', 'patient_id', 'encounter_id',
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
            throw new AncillaryIngestException('source_message_mismatch', 'The administration import is outside the source department scope.');
        }

        $envelope = $message->payload;
        $version = $envelope['envelope_version'] ?? null;
        if (! is_int($version) || $version !== self::ENVELOPE_VERSION) {
            throw new AncillaryIngestException('unsupported_envelope_version', 'The administration import envelope version is unsupported.');
        }

        $extractId = $this->required($envelope, 'extract_id', 'missing_extract_identity');
        if (! array_key_exists('source_cutoff_at', $envelope) || trim((string) ($envelope['source_cutoff_at'] ?? '')) === '') {
            throw new AncillaryIngestException('missing_source_cutoff', 'The administration import batch is missing its warehouse source cutoff.');
        }
        $cutoffAt = $this->timestamp($envelope['source_cutoff_at'], 'source_cutoff_at');
        $sourceClass = $this->sourceClass($envelope);

        $row = $envelope['administration'] ?? null;
        if (! is_array($row)) {
            throw new AncillaryIngestException('missing_administration_row', 'The administration import message is missing its administration row.');
        }
        $administrationId = $this->required($row, 'administration_id', 'missing_administration_identity');
        $rowVersion = trim((string) ($row['row_version'] ?? '')) ?: null;
        $orderKeys = $this->orderKeys($row);
        if (! array_key_exists('administered_at', $row) || trim((string) ($row['administered_at'] ?? '')) === '') {
            throw new AncillaryIngestException('missing_administered_at', 'The administration row is missing its administration timestamp.');
        }
        $administeredAt = $this->timestamp($row['administered_at'], 'administered_at');
        if ($administeredAt->greaterThan($cutoffAt)) {
            throw new AncillaryIngestException('administration_after_cutoff', 'The administration timestamp is after the batch source cutoff.');
        }
        $status = $this->status($row);

        $medication = is_array($row['medication'] ?? null) ? $row['medication'] : [];
        $patient = trim((string) ($row['patient_id'] ?? ''));
        $encounter = trim((string) ($row['encounter_id'] ?? ''));
        $base = [
            'department' => 'rx',
            'require_existing_order' => true,
            'source_order_key' => $orderKeys['source_order_key'],
            'reconciliation_key' => $orderKeys['source_order_key'],
            'placer_order_key' => $orderKeys['placer_order_key'],
            'source_administration_key' => $administrationId,
            'source_row_version' => $rowVersion,
            'import_batch_key' => $extractId,
            'administration_source_class' => $sourceClass,
            'administration_status' => $status,
            'administration_route' => trim((string) ($row['route'] ?? '')) ?: null,
            'dosage_form' => trim((string) ($row['dosage_form'] ?? '')) ?: null,
            'administration_local_code' => trim((string) ($medication['local_code'] ?? '')) ?: null,
            'administration_ndc_code' => trim((string) ($medication['ndc_code'] ?? '')) ?: null,
            'administration_rxnorm_cui' => trim((string) ($medication['rxnorm_cui'] ?? '')) ?: null,
            'administration_medication_label' => trim((string) ($medication['label'] ?? '')) ?: null,
            'administered_at' => $administeredAt->toIso8601String(),
            'source_cutoff_at' => $cutoffAt->toIso8601String(),
            'patient_ref' => $patient !== '' ? $this->pseudonym($profile->sourceKey, 'patient', $patient) : null,
            'encounter_ref' => $encounter !== '' ? $this->pseudonym($profile->sourceKey, 'encounter', $encounter) : null,
            'occurred_at' => $administeredAt->toIso8601String(),
            'source_timestamp_valid' => true,
        ];

        // Only a 'given' administration asserts the RX_ADMINISTERED milestone;
        // held/refused/missed rows persist as satellite facts without ever
        // touching a milestone or SLA clock.
        $payload = $status === 'given'
            ? [...$base, 'milestone_code' => 'RX_ADMINISTERED', 'work_item_type' => 'medication_order', 'priority' => 'unknown']
            : [...$base, 'rx_administration_scope' => true];

        return new NormalizedPayload(
            messageType: 'ANCILLARY_'.self::FAMILY,
            eventType: $status === 'given'
                ? AncillaryEventVocabulary::eventTypeFor('RX_ADMINISTERED')
                : self::RECORD_EVENT_TYPE,
            payload: array_filter($payload, fn (mixed $value): bool => $value !== null && $value !== ''),
            idempotencyKey: implode(':', ['ancillary', $profile->sourceKey, 'rxadmin', $extractId, $administrationId, $rowVersion ?? '0']),
            externalId: $administrationId,
            occurredAt: $administeredAt->toIso8601String(),
            metadata: [
                'connector_key' => 'ancillary.healthcare',
                'source_protocol' => strtolower((string) ($message->metadata['source_protocol'] ?? 'warehouse_batch')),
                'message_family' => self::FAMILY,
                'envelope_version' => self::ENVELOPE_VERSION,
            ],
        );
    }

    /** @param array<string, mixed> $row @return array{source_order_key: string, placer_order_key: ?string} */
    private function orderKeys(array $row): array
    {
        $order = $row['order'] ?? null;
        if (! is_array($order) || trim((string) ($order['source_order_key'] ?? '')) === '') {
            throw new AncillaryIngestException('missing_order_identity', 'The administration row is missing its order linkage identity.');
        }

        return [
            'source_order_key' => trim((string) $order['source_order_key']),
            'placer_order_key' => trim((string) ($order['placer_order_key'] ?? '')) ?: null,
        ];
    }

    /** @param array<string, mixed> $envelope */
    private function sourceClass(array $envelope): string
    {
        $class = strtolower(trim((string) ($envelope['source_class'] ?? ''))) ?: 'bcma_warehouse';
        if (! in_array($class, self::SOURCE_CLASSES, true)) {
            throw new AncillaryIngestException('invalid_source_class', 'The administration source class is unsupported.');
        }

        return $class;
    }

    /** @param array<string, mixed> $row */
    private function status(array $row): string
    {
        $status = strtolower(trim((string) ($row['status'] ?? ''))) ?: 'given';
        if (! in_array($status, self::STATUSES, true)) {
            throw new AncillaryIngestException('invalid_administration_status', 'The administration status is unsupported.');
        }

        return $status;
    }

    /** @param array<string, mixed> $payload */
    private function required(array $payload, string $key, string $reasonCode): string
    {
        $value = $payload[$key] ?? null;
        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new AncillaryIngestException($reasonCode, "The administration import is missing its {$key}.");
        }

        return trim((string) $value);
    }

    private function timestamp(mixed $value, string $field): CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            throw new AncillaryIngestException('malformed_timestamp', "The administration import {$field} is missing or not a string.");
        }
        if (! preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $value)) {
            throw new AncillaryIngestException('malformed_timestamp', "The administration import {$field} must include an explicit offset.");
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable $exception) {
            throw new AncillaryIngestException('malformed_timestamp', "The administration import {$field} is malformed.", previous: $exception);
        }
    }

    private function family(SourceMessage $message): string
    {
        return strtoupper((string) preg_replace('/^(ANCILLARY[._-]|STRUCTURED[._-])/', '', strtoupper($message->messageType)));
    }

    private function pseudonym(string $sourceKey, string $kind, string $value): string
    {
        return hash_hmac('sha256', implode('|', [$sourceKey, $kind, $value]), (string) config('app.key'));
    }
}
