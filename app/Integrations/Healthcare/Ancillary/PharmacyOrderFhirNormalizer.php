<?php

namespace App\Integrations\Healthcare\Ancillary;

use App\Integrations\Healthcare\Contracts\SourceMessageNormalizer;
use App\Integrations\Healthcare\DTO\NormalizedPayload;
use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use App\Integrations\Healthcare\Services\FhirResourcePolicy;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Normalizes governed FHIR MedicationRequest and MedicationDispense backfill
 * into the same pharmacy order/dispense identities the HL7 v2 path uses.
 *
 * A MedicationRequest reconciles through "MedicationRequest/{id}" exactly the
 * way the Lab FHIR normalizer reconciles ServiceRequest identities, and a
 * MedicationDispense joins the same order through its authorizingPrescription
 * reference. FHIR dispense data NEVER substitutes for administration
 * evidence: this normalizer cannot emit RX_ADMINISTERED, and a completed
 * MedicationDispense maps only to RX_DISPENSED.
 */
final class PharmacyOrderFhirNormalizer implements SourceMessageNormalizer
{
    private const SYSTEM_CLASSES = ['pharmacy', 'pharmacy_system', 'ehr', 'ehr_cpoe'];

    private const CLOCK_CLASSES = ['stat', 'first_dose', 'sepsis', 'routine', 'timed', 'discharge'];

    public function __construct(private readonly FhirResourcePolicy $policy) {}

    public function supports(SourceMessage $message): bool
    {
        $resource = $this->resource($message);

        return in_array($resource['resourceType'] ?? null, ['MedicationRequest', 'MedicationDispense'], true)
            && in_array(strtolower((string) ($message->metadata['system_class'] ?? '')), self::SYSTEM_CLASSES, true);
    }

    public function normalize(SourceMessage $message): NormalizedPayload
    {
        $resource = $this->resource($message);
        $resourceType = (string) ($resource['resourceType'] ?? '');
        try {
            $this->policy->assertResourceAllowed($resourceType);
        } catch (Throwable $exception) {
            throw new AncillaryIngestException('fhir_resource_not_allowed', 'The Pharmacy FHIR resource type is not enabled for ancillary ingestion.', previous: $exception);
        }

        $profile = AncillarySourceProfile::from($message);
        $profile->assertFamily('FHIR');
        if ($profile->departments !== [] && ! in_array('rx', $profile->departments, true)) {
            throw new AncillaryIngestException('source_message_mismatch', 'The Pharmacy FHIR resource is outside the source department scope.');
        }

        return $resourceType === 'MedicationRequest'
            ? $this->medicationRequest($resource, $profile)
            : $this->medicationDispense($resource, $profile);
    }

    /** @param array<string, mixed> $resource */
    private function medicationRequest(array $resource, AncillarySourceProfile $profile): NormalizedPayload
    {
        $id = $this->required($resource, 'id');
        $version = trim((string) ($resource['meta']['versionId'] ?? '1')) ?: '1';
        $status = strtolower((string) ($resource['status'] ?? ''));
        $stopped = $status === 'stopped';
        $cancelled = in_array($status, ['cancelled', 'entered-in-error', 'revoked'], true);
        $orderedAt = $this->timestamp($resource['authoredOn'] ?? $resource['meta']['lastUpdated'] ?? null, 'MedicationRequest');
        $terminalAt = $stopped || $cancelled
            ? $this->timestamp($resource['meta']['lastUpdated'] ?? $resource['authoredOn'] ?? null, 'MedicationRequest')
            : null;
        $subject = trim((string) ($resource['subject']['reference'] ?? ''));
        $encounter = trim((string) ($resource['encounter']['reference'] ?? ''));
        $coding = $this->medicationCoding(is_array($resource['medicationCodeableConcept'] ?? null) ? $resource['medicationCodeableConcept'] : []);
        $clockClass = $this->clockClass($resource);
        $dosage = is_array($resource['dosageInstruction'][0] ?? null) ? $resource['dosageInstruction'][0] : [];
        $dueAt = $this->optionalTimestamp($dosage['timing']['event'][0] ?? null, 'MedicationRequest');

        $payload = array_filter([
            'department' => 'rx',
            'milestone_code' => $stopped || $cancelled ? 'RX_DISCONTINUED' : 'RX_ORDERED',
            'work_item_type' => 'medication_order',
            'source_order_key' => $this->identifier($resource) ?? $id,
            'reconciliation_key' => "MedicationRequest/{$id}",
            'order_status' => $status ?: null,
            'patient_ref' => $subject !== '' ? $this->pseudonym($profile->sourceKey, 'patient', $subject) : null,
            'encounter_ref' => $encounter !== '' ? $this->pseudonym($profile->sourceKey, 'encounter', $encounter) : null,
            'patient_class' => $this->extensionCode($resource, 'patient-class', ['emergency', 'inpatient', 'outpatient', 'perioperative']) ?? 'unknown',
            'priority' => $clockClass,
            'clock_class' => $clockClass,
            'due_at' => $dueAt?->toIso8601String(),
            'ordered_at' => $orderedAt->toIso8601String(),
            'ordered_at_source' => isset($resource['authoredOn']) ? 'MedicationRequest.authoredOn' : 'MedicationRequest.meta.lastUpdated',
            'occurred_at' => ($terminalAt ?? $orderedAt)->toIso8601String(),
            ...$coding,
            'route' => $this->codingCode(is_array($dosage['route'] ?? null) ? $dosage['route'] : []),
            'discontinued_at' => $stopped ? $terminalAt?->toIso8601String() : null,
            'cancelled_at' => $cancelled ? $terminalAt?->toIso8601String() : null,
            'source_timestamp_valid' => true,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        return new NormalizedPayload(
            messageType: 'FHIR_MedicationRequest',
            eventType: AncillaryEventVocabulary::eventTypeFor((string) $payload['milestone_code']),
            payload: $payload,
            idempotencyKey: implode(':', ['ancillary', $profile->sourceKey, 'MedicationRequest', $id, $version]),
            externalId: "MedicationRequest/{$id}/_history/{$version}",
            occurredAt: (string) $payload['occurred_at'],
            metadata: [
                'connector_key' => 'ancillary.healthcare',
                'source_protocol' => 'fhir',
                'message_family' => 'FHIR',
                'resource_type' => 'MedicationRequest',
            ],
        );
    }

    /** @param array<string, mixed> $resource */
    private function medicationDispense(array $resource, AncillarySourceProfile $profile): NormalizedPayload
    {
        $id = $this->required($resource, 'id');
        $version = trim((string) ($resource['meta']['versionId'] ?? '1')) ?: '1';
        $requestId = $this->authorizingRequestId($resource);
        if ($requestId === null) {
            throw new AncillaryIngestException('missing_order_identity', 'The Pharmacy FHIR MedicationDispense is missing its MedicationRequest identity.');
        }

        $dispensedAt = $this->optionalTimestamp($resource['whenHandedOver'] ?? null, 'MedicationDispense')
            ?? $this->optionalTimestamp($resource['whenPrepared'] ?? null, 'MedicationDispense');
        if ($dispensedAt === null) {
            throw new AncillaryIngestException('missing_dispense_assertion', 'The Pharmacy FHIR MedicationDispense does not assert a preparation or hand-over timestamp.');
        }

        $subject = trim((string) ($resource['subject']['reference'] ?? ''));
        $coding = $this->medicationCoding(is_array($resource['medicationCodeableConcept'] ?? null) ? $resource['medicationCodeableConcept'] : []);

        $payload = array_filter([
            'department' => 'rx',
            'milestone_code' => 'RX_DISPENSED',
            'work_item_type' => 'medication_order',
            'source_order_key' => $requestId,
            'reconciliation_key' => "MedicationRequest/{$requestId}",
            'patient_ref' => $subject !== '' ? $this->pseudonym($profile->sourceKey, 'patient', $subject) : null,
            'priority' => 'unknown',
            'ordered_at' => $dispensedAt->toIso8601String(),
            'ordered_at_source' => 'dispense_fallback',
            'occurred_at' => $dispensedAt->toIso8601String(),
            'source_dispense_key' => $this->identifier($resource) ?? "MedicationDispense/{$id}",
            'dispense_channel' => 'other',
            'dispensed_at' => $dispensedAt->toIso8601String(),
            'fhir_backfill' => true,
            ...$coding,
            'source_timestamp_valid' => true,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        return new NormalizedPayload(
            messageType: 'FHIR_MedicationDispense',
            eventType: AncillaryEventVocabulary::eventTypeFor('RX_DISPENSED'),
            payload: $payload,
            idempotencyKey: implode(':', ['ancillary', $profile->sourceKey, 'MedicationDispense', $id, $version]),
            externalId: "MedicationDispense/{$id}/_history/{$version}",
            occurredAt: $dispensedAt->toIso8601String(),
            metadata: [
                'connector_key' => 'ancillary.healthcare',
                'source_protocol' => 'fhir',
                'message_family' => 'FHIR',
                'resource_type' => 'MedicationDispense',
            ],
        );
    }

    /** @return array<string, mixed> */
    private function resource(SourceMessage $message): array
    {
        $resource = $message->payload['resource'] ?? $message->payload;

        return is_array($resource) ? $resource : [];
    }

    /** @param array<string, mixed> $resource */
    private function identifier(array $resource): ?string
    {
        foreach ($resource['identifier'] ?? [] as $identifier) {
            if (is_array($identifier) && is_scalar($identifier['value'] ?? null) && trim((string) $identifier['value']) !== '') {
                return trim((string) $identifier['value']);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $resource */
    private function authorizingRequestId(array $resource): ?string
    {
        foreach ($resource['authorizingPrescription'] ?? [] as $reference) {
            $value = is_array($reference) ? trim((string) ($reference['reference'] ?? '')) : '';
            if ($value === '') {
                continue;
            }
            if (preg_match('#(?:^|/)MedicationRequest/([^/]+)(?:/_history/[^/]+)?$#', $value, $matches)) {
                return trim($matches[1]) ?: null;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $codeableConcept @return array<string, string> */
    private function medicationCoding(array $codeableConcept): array
    {
        $local = null;
        $rxnorm = null;
        $ndc = null;
        foreach ($codeableConcept['coding'] ?? [] as $coding) {
            if (! is_array($coding) || ! is_scalar($coding['code'] ?? null) || trim((string) $coding['code']) === '') {
                continue;
            }
            $candidate = [
                'code' => trim((string) $coding['code']),
                'label' => trim((string) ($coding['display'] ?? '')),
            ];
            $system = strtolower((string) ($coding['system'] ?? ''));
            if (str_contains($system, 'rxnorm')) {
                $rxnorm ??= $candidate;
            } elseif (str_contains($system, '/ndc')) {
                $ndc ??= $candidate;
            } else {
                $local ??= $candidate;
            }
        }
        $label = $local['label'] ?? ($rxnorm['label'] ?? ($ndc['label'] ?? null));

        return array_filter([
            'local_code' => $local['code'] ?? ($rxnorm['code'] ?? ($ndc['code'] ?? null)),
            'medication_label' => $label,
            'rxnorm_cui' => $rxnorm['code'] ?? null,
            'ndc_code' => $ndc['code'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @param array<string, mixed> $resource */
    private function clockClass(array $resource): string
    {
        $explicit = $this->extensionCode($resource, 'clock-class', self::CLOCK_CLASSES);
        if ($explicit !== null) {
            return $explicit;
        }
        foreach ($resource['category'] ?? [] as $category) {
            if (is_array($category) && $this->codingCode($category) === 'discharge') {
                return 'discharge';
            }
        }

        return strtolower((string) ($resource['priority'] ?? 'routine')) === 'stat' ? 'stat' : 'routine';
    }

    /** @param array<string, mixed> $codeableConcept */
    private function codingCode(array $codeableConcept): ?string
    {
        foreach ($codeableConcept['coding'] ?? [] as $coding) {
            if (is_array($coding) && is_scalar($coding['code'] ?? null) && trim((string) $coding['code']) !== '') {
                return trim((string) $coding['code']);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $resource @param list<string> $allowed */
    private function extensionCode(array $resource, string $suffix, array $allowed): ?string
    {
        foreach ($resource['extension'] ?? [] as $extension) {
            if (! is_array($extension) || ! str_ends_with(strtolower((string) ($extension['url'] ?? '')), strtolower($suffix))) {
                continue;
            }
            $candidate = strtolower(trim((string) ($extension['valueCode'] ?? '')));

            return in_array($candidate, $allowed, true) ? $candidate : null;
        }

        return null;
    }

    private function timestamp(mixed $value, string $resourceType): CarbonImmutable
    {
        $timestamp = $this->optionalTimestamp($value, $resourceType);
        if ($timestamp === null) {
            throw new AncillaryIngestException('missing_timestamp', "The Pharmacy FHIR {$resourceType} is missing its operational timestamp.");
        }

        return $timestamp;
    }

    private function optionalTimestamp(mixed $value, string $resourceType): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        if (! preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $value)) {
            throw new AncillaryIngestException('malformed_timestamp', "The Pharmacy FHIR {$resourceType} timestamp must include an explicit offset.");
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable $exception) {
            throw new AncillaryIngestException('malformed_timestamp', "The Pharmacy FHIR {$resourceType} timestamp is malformed.", previous: $exception);
        }
    }

    /** @param array<string, mixed> $resource */
    private function required(array $resource, string $key): string
    {
        $value = $resource[$key] ?? null;
        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new AncillaryIngestException('missing_control_identity', "The Pharmacy FHIR resource is missing {$key}.");
        }

        return trim((string) $value);
    }

    private function pseudonym(string $sourceKey, string $kind, string $value): string
    {
        return hash_hmac('sha256', implode('|', [$sourceKey, $kind, $value]), (string) config('app.key'));
    }
}
