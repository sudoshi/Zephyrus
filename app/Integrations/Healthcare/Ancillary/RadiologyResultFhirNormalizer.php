<?php

namespace App\Integrations\Healthcare\Ancillary;

use App\Integrations\Healthcare\Contracts\SourceMessageNormalizer;
use App\Integrations\Healthcare\DTO\NormalizedPayload;
use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use App\Integrations\Healthcare\Services\FhirResourcePolicy;
use Carbon\CarbonImmutable;
use Throwable;

final class RadiologyResultFhirNormalizer implements SourceMessageNormalizer
{
    public function __construct(private readonly FhirResourcePolicy $policy) {}

    public function supports(SourceMessage $message): bool
    {
        $resource = $this->resource($message);

        return in_array($resource['resourceType'] ?? null, ['ImagingStudy', 'DiagnosticReport'], true)
            && in_array(strtolower((string) ($message->metadata['system_class'] ?? '')), ['radiology', 'radiology_reporting', 'ris', 'pacs'], true);
    }

    public function normalize(SourceMessage $message): NormalizedPayload
    {
        $resource = $this->resource($message);
        $type = (string) ($resource['resourceType'] ?? '');
        try {
            $this->policy->assertResourceAllowed($type);
        } catch (Throwable $exception) {
            throw new AncillaryIngestException('fhir_resource_not_allowed', 'The Radiology result resource is not enabled.', previous: $exception);
        }

        $profile = AncillarySourceProfile::from($message);
        $profile->assertFamily('FHIR');
        if ($profile->departments !== [] && ! in_array('rad', $profile->departments, true)) {
            throw new AncillaryIngestException('source_message_mismatch', 'The Radiology result resource is outside the source department scope.');
        }

        $id = $this->required($resource, 'id');
        $version = trim((string) ($resource['meta']['versionId'] ?? '1')) ?: '1';
        $accession = $this->identifier($resource) ?? $this->basedOn($resource) ?? $id;
        $occurredAt = $this->timestamp($resource['issued'] ?? $resource['started'] ?? $resource['effectiveDateTime'] ?? $resource['meta']['lastUpdated'] ?? null);
        $isStudy = $type === 'ImagingStudy';
        $status = $isStudy ? null : $this->reportStatus((string) ($resource['status'] ?? ''));
        $milestone = $isStudy ? 'RAD_IMAGES_AVAILABLE' : ($status === 'preliminary' ? 'RAD_PRELIM' : 'RAD_FINAL');
        $code = $resource['code']['coding'][0] ?? [];
        $sourceReadKey = $isStudy ? null : hash('sha256', implode('|', [$type, $id, $version]));
        $subject = trim((string) ($resource['subject']['reference'] ?? ''));

        $payload = array_filter([
            'department' => 'rad', 'milestone_code' => $milestone, 'work_item_type' => 'imaging_order',
            'source_order_key' => $accession, 'source_exam_key' => $accession, 'reconciliation_key' => $accession,
            'patient_ref' => $subject !== '' ? $this->pseudonym($profile->sourceKey, 'patient', $subject) : null,
            'patient_class' => 'unknown', 'priority' => 'routine', 'ordered_at' => $occurredAt->toIso8601String(),
            'occurred_at' => $occurredAt->toIso8601String(), 'procedure_code' => $code['code'] ?? null,
            'procedure_label' => $code['display'] ?? null, 'modality' => $this->modality($resource),
            'exam_status' => $isStudy ? 'complete' : null,
            'read_status' => $status, 'source_read_key' => $sourceReadKey, 'source_report_version' => $version,
            'preliminary_at' => $status === 'preliminary' ? $occurredAt->toIso8601String() : null,
            'final_at' => in_array($status, ['final', 'addendum'], true) ? $occurredAt->toIso8601String() : null,
            'corrected_at' => $status === 'corrected' ? $occurredAt->toIso8601String() : null,
            'correction' => in_array($status, ['corrected', 'addendum'], true) ? $status : null,
            'source_timestamp_valid' => true,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        return new NormalizedPayload(
            messageType: 'FHIR_'.$type,
            eventType: AncillaryEventVocabulary::eventTypeFor($milestone),
            payload: $payload,
            idempotencyKey: implode(':', ['ancillary', $profile->sourceKey, $type, $id, $version]),
            externalId: "{$type}/{$id}/_history/{$version}", occurredAt: $occurredAt->toIso8601String(),
            metadata: ['connector_key' => 'ancillary.healthcare', 'source_protocol' => 'fhir', 'message_family' => 'FHIR', 'resource_type' => $type, 'resource_version' => $version],
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
    private function basedOn(array $resource): ?string
    {
        $reference = trim((string) ($resource['basedOn'][0]['reference'] ?? ''));

        return $reference === '' ? null : basename($reference);
    }

    private function reportStatus(string $status): string
    {
        return match (strtolower($status)) {
            'preliminary', 'partial' => 'preliminary',
            'final' => 'final',
            'amended', 'corrected' => 'corrected',
            'appended' => 'addendum',
            default => throw new AncillaryIngestException('invalid_report_status', 'The DiagnosticReport status is unsupported.'),
        };
    }

    /** @param array<string, mixed> $resource */
    private function modality(array $resource): ?string
    {
        foreach ($resource['modality'] ?? [] as $entry) {
            $candidate = strtoupper(trim((string) ($entry['code'] ?? $entry['coding'][0]['code'] ?? '')));
            if (in_array($candidate, ['XR', 'CT', 'MRI', 'US', 'NM', 'IR'], true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function timestamp(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $value)) {
            throw new AncillaryIngestException('malformed_timestamp', 'The Radiology result timestamp must include an explicit offset.');
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable $exception) {
            throw new AncillaryIngestException('malformed_timestamp', 'The Radiology result timestamp is malformed.', previous: $exception);
        }
    }

    /** @param array<string, mixed> $resource */
    private function required(array $resource, string $key): string
    {
        $value = $resource[$key] ?? null;
        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new AncillaryIngestException('missing_control_identity', "The Radiology result resource is missing {$key}.");
        }

        return trim((string) $value);
    }

    private function pseudonym(string $sourceKey, string $kind, string $value): string
    {
        return hash_hmac('sha256', implode('|', [$sourceKey, $kind, $value]), (string) config('app.key'));
    }
}
