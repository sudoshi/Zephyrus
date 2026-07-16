<?php

namespace App\Integrations\Healthcare\Ancillary;

use App\Integrations\Healthcare\Contracts\SourceMessageNormalizer;
use App\Integrations\Healthcare\DTO\NormalizedPayload;
use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use App\Integrations\Healthcare\Services\FhirResourcePolicy;
use Carbon\CarbonImmutable;
use Throwable;

final class LabOrderFhirNormalizer implements SourceMessageNormalizer
{
    private const SYSTEM_CLASSES = ['lis', 'lab', 'lab_middleware', 'ehr', 'ehr_cpoe'];

    public function __construct(private readonly FhirResourcePolicy $policy) {}

    public function supports(SourceMessage $message): bool
    {
        $resource = $this->resource($message);

        return in_array($resource['resourceType'] ?? null, ['ServiceRequest', 'Specimen'], true)
            && in_array(strtolower((string) ($message->metadata['system_class'] ?? '')), self::SYSTEM_CLASSES, true);
    }

    public function normalize(SourceMessage $message): NormalizedPayload
    {
        $resource = $this->resource($message);
        $resourceType = (string) ($resource['resourceType'] ?? '');
        try {
            $this->policy->assertResourceAllowed($resourceType);
        } catch (Throwable $exception) {
            throw new AncillaryIngestException('fhir_resource_not_allowed', 'The Laboratory FHIR resource type is not enabled for ancillary ingestion.', previous: $exception);
        }

        $profile = AncillarySourceProfile::from($message);
        $profile->assertFamily('FHIR');
        if ($profile->departments !== [] && ! in_array('lab', $profile->departments, true)) {
            throw new AncillaryIngestException('source_message_mismatch', 'The Laboratory FHIR resource is outside the source department scope.');
        }

        return $resourceType === 'ServiceRequest'
            ? $this->serviceRequest($resource, $profile)
            : $this->specimen($resource, $profile);
    }

    /** @param array<string, mixed> $resource */
    private function serviceRequest(array $resource, AncillarySourceProfile $profile): NormalizedPayload
    {
        $id = $this->required($resource, 'id');
        $version = trim((string) ($resource['meta']['versionId'] ?? '1')) ?: '1';
        $status = strtolower((string) ($resource['status'] ?? ''));
        $cancelled = in_array($status, ['cancelled', 'entered-in-error', 'revoked'], true);
        $occurredAt = $this->timestamp($resource['authoredOn'] ?? $resource['meta']['lastUpdated'] ?? null, 'ServiceRequest');
        $sourceOrderKey = $this->identifier($resource) ?? $id;
        $reconciliationKey = "ServiceRequest/{$id}";
        $subject = trim((string) ($resource['subject']['reference'] ?? ''));
        $encounter = trim((string) ($resource['encounter']['reference'] ?? ''));
        $test = $this->testIdentity(is_array($resource['code'] ?? null) ? $resource['code'] : []);
        $specimens = [];
        foreach ($resource['specimen'] ?? [] as $reference) {
            if (! is_array($reference)) {
                continue;
            }
            $specimenKey = $this->referenceId($reference['reference'] ?? null, 'Specimen');
            if ($specimenKey !== null) {
                $specimens[] = [
                    'source_specimen_key' => $specimenKey,
                    'specimen_type' => 'unknown',
                    'specimen_status' => 'collection_pending',
                ];
            }
        }

        $payload = array_filter([
            'department' => 'lab',
            'milestone_code' => $cancelled ? 'LAB_CANCELLED' : 'LAB_ORDERED',
            'work_item_type' => 'lab_order',
            'source_order_key' => $sourceOrderKey,
            'reconciliation_key' => $reconciliationKey,
            'placer_order_key' => $sourceOrderKey,
            'order_status' => $status ?: null,
            'patient_ref' => $subject !== '' ? $this->pseudonym($profile->sourceKey, 'patient', $subject) : null,
            'encounter_ref' => $encounter !== '' ? $this->pseudonym($profile->sourceKey, 'encounter', $encounter) : null,
            'patient_class' => $this->patientClass($resource),
            'priority' => $this->priority($resource),
            'ordered_at' => $occurredAt->toIso8601String(),
            'ordered_at_source' => isset($resource['authoredOn']) ? 'ServiceRequest.authoredOn' : 'ServiceRequest.meta.lastUpdated',
            'occurred_at' => $occurredAt->toIso8601String(),
            ...$test,
            'specimens' => $specimens,
            'cancelled_at' => $cancelled ? $occurredAt->toIso8601String() : null,
            'source_timestamp_valid' => true,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        return new NormalizedPayload(
            messageType: 'FHIR_ServiceRequest',
            eventType: AncillaryEventVocabulary::eventTypeFor((string) $payload['milestone_code']),
            payload: $payload,
            idempotencyKey: implode(':', ['ancillary', $profile->sourceKey, 'ServiceRequest', $id, $version]),
            externalId: "ServiceRequest/{$id}/_history/{$version}",
            occurredAt: $occurredAt->toIso8601String(),
            metadata: [
                'connector_key' => 'ancillary.healthcare',
                'source_protocol' => 'fhir',
                'message_family' => 'FHIR',
                'resource_type' => 'ServiceRequest',
            ],
        );
    }

    /** @param array<string, mixed> $resource */
    private function specimen(array $resource, AncillarySourceProfile $profile): NormalizedPayload
    {
        $id = $this->required($resource, 'id');
        $version = trim((string) ($resource['meta']['versionId'] ?? '1')) ?: '1';
        $requestReference = $this->requestReference($resource);
        if ($requestReference === null) {
            throw new AncillaryIngestException('missing_order_identity', 'The Laboratory FHIR Specimen is missing its ServiceRequest identity.');
        }
        $sourceOrderKey = $this->referenceId($requestReference, 'ServiceRequest');
        if ($sourceOrderKey === null) {
            throw new AncillaryIngestException('missing_order_identity', 'The Laboratory FHIR Specimen request reference is invalid.');
        }

        $collectedAt = $this->optionalTimestamp(
            $resource['collection']['collectedDateTime']
                ?? $resource['collection']['collectedPeriod']['start']
                ?? null,
            'Specimen',
        );
        if ($collectedAt === null) {
            throw new AncillaryIngestException('missing_collection_assertion', 'The Laboratory FHIR Specimen does not assert a collection timestamp.');
        }

        $subject = trim((string) ($resource['subject']['reference'] ?? ''));
        $collector = trim((string) ($resource['collection']['collector']['reference'] ?? ''));
        $businessKey = $this->identifier($resource);
        $reconciliationKey = "ServiceRequest/{$sourceOrderKey}";
        $payload = array_filter([
            'department' => 'lab',
            'milestone_code' => 'LAB_COLLECTED',
            'work_item_type' => 'lab_order',
            'source_order_key' => $sourceOrderKey,
            'reconciliation_key' => $reconciliationKey,
            'patient_ref' => $subject !== '' ? $this->pseudonym($profile->sourceKey, 'patient', $subject) : null,
            'priority' => 'unknown',
            'ordered_at' => $collectedAt->toIso8601String(),
            'ordered_at_source' => 'collection_fallback',
            'occurred_at' => $collectedAt->toIso8601String(),
            'source_specimen_key' => $id,
            'source_specimen_business_key' => $businessKey,
            'source_accession_key' => $businessKey,
            'specimen_type' => $this->codingCode($resource['type'] ?? []) ?? 'unknown',
            'container_type' => $this->codingCode($resource['container'][0]['type'] ?? []),
            'collector_role' => $this->extensionCode($resource, 'collector-role', ['nurse_collect', 'lab_collect']),
            'collector_ref' => $collector !== '' ? $this->pseudonym($profile->sourceKey, 'collector', $collector) : null,
            'collection_method' => $this->codingCode($resource['collection']['method'] ?? []),
            'collected_at' => $collectedAt->toIso8601String(),
            'specimen_status' => 'collected',
            'collection_source' => isset($resource['collection']['collectedDateTime']) ? 'Specimen.collection.collectedDateTime' : 'Specimen.collection.collectedPeriod.start',
            'source_timestamp_valid' => true,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        return new NormalizedPayload(
            messageType: 'FHIR_Specimen',
            eventType: AncillaryEventVocabulary::eventTypeFor('LAB_COLLECTED'),
            payload: $payload,
            idempotencyKey: implode(':', ['ancillary', $profile->sourceKey, 'Specimen', $id, $version]),
            externalId: "Specimen/{$id}/_history/{$version}",
            occurredAt: $collectedAt->toIso8601String(),
            metadata: [
                'connector_key' => 'ancillary.healthcare',
                'source_protocol' => 'fhir',
                'message_family' => 'FHIR',
                'resource_type' => 'Specimen',
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
    private function requestReference(array $resource): ?string
    {
        foreach ($resource['request'] ?? [] as $request) {
            $reference = is_array($request) ? trim((string) ($request['reference'] ?? '')) : '';
            if ($reference !== '') {
                return $reference;
            }
        }

        return null;
    }

    private function referenceId(mixed $reference, string $resourceType): ?string
    {
        if (! is_string($reference) || trim($reference) === '') {
            return null;
        }
        $reference = trim($reference);
        if (! preg_match('#(?:^|/)'.preg_quote($resourceType, '#').'/([^/]+)(?:/_history/[^/]+)?$#', $reference, $matches)) {
            return null;
        }

        return trim($matches[1]) ?: null;
    }

    /** @param array<string, mixed> $codeableConcept @return array<string, string> */
    private function testIdentity(array $codeableConcept): array
    {
        $local = null;
        $loinc = null;
        foreach ($codeableConcept['coding'] ?? [] as $coding) {
            if (! is_array($coding) || ! is_scalar($coding['code'] ?? null)) {
                continue;
            }
            $candidate = [
                'code' => trim((string) $coding['code']),
                'label' => trim((string) ($coding['display'] ?? '')),
            ];
            if (str_contains(strtolower((string) ($coding['system'] ?? '')), 'loinc')) {
                $loinc ??= $candidate;
            } else {
                $local ??= $candidate;
            }
        }
        $local ??= $loinc;

        return array_filter([
            'test_code' => $local['code'] ?? null,
            'test_label' => $local['label'] ?? null,
            'loinc_code' => $loinc['code'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
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

    /** @param array<string, mixed> $resource */
    private function patientClass(array $resource): string
    {
        $candidate = $this->extensionCode($resource, 'patient-class', ['emergency', 'inpatient', 'outpatient', 'perioperative']);

        return $candidate ?? 'unknown';
    }

    /** @param array<string, mixed> $resource */
    private function priority(array $resource): string
    {
        return match (strtolower((string) ($resource['priority'] ?? 'routine'))) {
            'stat' => 'stat',
            'asap', 'urgent' => 'urgent',
            default => 'routine',
        };
    }

    private function timestamp(mixed $value, string $resourceType): CarbonImmutable
    {
        $timestamp = $this->optionalTimestamp($value, $resourceType);
        if ($timestamp === null) {
            throw new AncillaryIngestException('missing_timestamp', "The Laboratory FHIR {$resourceType} is missing its operational timestamp.");
        }

        return $timestamp;
    }

    private function optionalTimestamp(mixed $value, string $resourceType): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        if (! preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $value)) {
            throw new AncillaryIngestException('malformed_timestamp', "The Laboratory FHIR {$resourceType} timestamp must include an explicit offset.");
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable $exception) {
            throw new AncillaryIngestException('malformed_timestamp', "The Laboratory FHIR {$resourceType} timestamp is malformed.", previous: $exception);
        }
    }

    /** @param array<string, mixed> $resource */
    private function required(array $resource, string $key): string
    {
        $value = $resource[$key] ?? null;
        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new AncillaryIngestException('missing_control_identity', "The Laboratory FHIR resource is missing {$key}.");
        }

        return trim((string) $value);
    }

    private function pseudonym(string $sourceKey, string $kind, string $value): string
    {
        return hash_hmac('sha256', implode('|', [$sourceKey, $kind, $value]), (string) config('app.key'));
    }
}
