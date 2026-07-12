<?php

namespace App\Integrations\Healthcare\Ancillary;

use App\Integrations\Healthcare\Contracts\SourceMessageNormalizer;
use App\Integrations\Healthcare\DTO\NormalizedPayload;
use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use App\Integrations\Healthcare\Services\FhirResourcePolicy;
use Carbon\CarbonImmutable;
use Throwable;

final class RadiologyOrderFhirNormalizer implements SourceMessageNormalizer
{
    public function __construct(private readonly FhirResourcePolicy $policy) {}

    public function supports(SourceMessage $message): bool
    {
        $resource = $this->resource($message);

        return in_array($resource['resourceType'] ?? null, ['ServiceRequest', 'Appointment'], true)
            && in_array(strtolower((string) ($message->metadata['system_class'] ?? '')), ['radiology', 'ris'], true);
    }

    public function normalize(SourceMessage $message): NormalizedPayload
    {
        $resource = $this->resource($message);
        $resourceType = (string) ($resource['resourceType'] ?? '');
        try {
            $this->policy->assertResourceAllowed($resourceType);
        } catch (Throwable $exception) {
            throw new AncillaryIngestException('fhir_resource_not_allowed', 'The Radiology FHIR resource type is not enabled for ancillary ingestion.', previous: $exception);
        }

        $profile = AncillarySourceProfile::from($message);
        $profile->assertFamily('FHIR');
        if ($profile->departments !== [] && ! in_array('rad', $profile->departments, true)) {
            throw new AncillaryIngestException('source_message_mismatch', 'The Radiology FHIR resource is outside the source department scope.');
        }

        $id = $this->required($resource, 'id');
        $status = strtolower((string) ($resource['status'] ?? ''));
        $cancelled = in_array($status, ['cancelled', 'entered-in-error', 'revoked'], true);
        $milestone = $cancelled ? 'RAD_CANCELLED' : ($resourceType === 'Appointment' ? 'RAD_SCHEDULED' : 'RAD_ORDERED');
        $sourceOrderKey = $this->identifier($resource) ?? $id;
        $occurredAt = $this->timestamp($resourceType === 'Appointment'
            ? ($resource['created'] ?? $resource['meta']['lastUpdated'] ?? null)
            : ($resource['authoredOn'] ?? $resource['occurrenceDateTime'] ?? null));
        $version = trim((string) ($resource['meta']['versionId'] ?? '1')) ?: '1';
        $subject = trim((string) ($resource['subject']['reference'] ?? ''));
        $encounter = trim((string) ($resource['encounter']['reference'] ?? ''));
        $code = $resourceType === 'Appointment'
            ? ($resource['serviceType'][0]['coding'][0] ?? [])
            : ($resource['code']['coding'][0] ?? []);
        $modality = $this->modality($resource);
        $scheduledStart = $resourceType === 'Appointment' ? $this->optionalTimestamp($resource['start'] ?? null) : null;
        $scheduledEnd = $resourceType === 'Appointment' ? $this->optionalTimestamp($resource['end'] ?? null) : null;
        $degraded = array_values(array_filter([
            $modality === null ? 'modality' : null,
            ! is_string($code['code'] ?? null) ? 'procedure_code' : null,
            $resourceType === 'Appointment' && ! isset($resource['start']) ? 'scheduled_start_at' : null,
        ]));

        $payload = array_filter([
            'department' => 'rad', 'milestone_code' => $milestone, 'work_item_type' => 'imaging_order',
            'source_order_key' => $sourceOrderKey, 'source_exam_key' => $sourceOrderKey, 'reconciliation_key' => $sourceOrderKey,
            'patient_ref' => $subject !== '' ? $this->pseudonym($profile->sourceKey, 'patient', $subject) : null,
            'encounter_ref' => $encounter !== '' ? $this->pseudonym($profile->sourceKey, 'encounter', $encounter) : null,
            'priority' => $this->priority($resource), 'ordered_at' => $occurredAt->toIso8601String(),
            'modality' => $modality, 'procedure_code' => $code['code'] ?? null, 'procedure_label' => $code['display'] ?? null,
            'scheduled_start_at' => $scheduledStart?->toIso8601String(),
            'scheduled_end_at' => $scheduledEnd?->toIso8601String(),
            'exam_status' => $cancelled ? 'cancelled' : ($resourceType === 'Appointment' ? 'scheduled' : 'ordered'),
            'degraded_fields' => $degraded, 'source_timestamp_valid' => true,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        return new NormalizedPayload(
            messageType: 'FHIR_'.$resourceType,
            eventType: AncillaryEventVocabulary::eventTypeFor($milestone),
            payload: $payload,
            idempotencyKey: implode(':', ['ancillary', $profile->sourceKey, $resourceType, $id, $version]),
            externalId: "{$resourceType}/{$id}/_history/{$version}",
            occurredAt: $occurredAt->toIso8601String(),
            metadata: ['connector_key' => 'ancillary.healthcare', 'source_protocol' => 'fhir', 'message_family' => 'FHIR', 'resource_type' => $resourceType],
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
    private function modality(array $resource): ?string
    {
        foreach ($resource['extension'] ?? [] as $extension) {
            $candidate = strtoupper(trim((string) ($extension['valueCode'] ?? '')));
            if (str_ends_with(strtolower((string) ($extension['url'] ?? '')), 'modality') && in_array($candidate, ['XR', 'CT', 'MRI', 'US', 'NM', 'IR'], true)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $resource */
    private function priority(array $resource): string
    {
        return match (strtolower((string) ($resource['priority'] ?? 'routine'))) {
            'stat' => 'stat', 'asap', 'urgent' => 'urgent', default => 'routine',
        };
    }

    private function timestamp(mixed $value): CarbonImmutable
    {
        $timestamp = $this->optionalTimestamp($value);
        if ($timestamp === null) {
            throw new AncillaryIngestException('missing_timestamp', 'The Radiology FHIR resource is missing its operational timestamp.');
        }

        return $timestamp;
    }

    private function optionalTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        if (! preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $value)) {
            throw new AncillaryIngestException('malformed_timestamp', 'The Radiology FHIR timestamp must include an explicit offset.');
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable $exception) {
            throw new AncillaryIngestException('malformed_timestamp', 'The Radiology FHIR timestamp is malformed.', previous: $exception);
        }
    }

    /** @param array<string, mixed> $resource */
    private function required(array $resource, string $key): string
    {
        $value = $resource[$key] ?? null;
        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new AncillaryIngestException('missing_control_identity', "The Radiology FHIR resource is missing {$key}.");
        }

        return trim((string) $value);
    }

    private function pseudonym(string $sourceKey, string $kind, string $value): string
    {
        return hash_hmac('sha256', implode('|', [$sourceKey, $kind, $value]), (string) config('app.key'));
    }
}
