<?php

namespace App\Integrations\Healthcare\Ancillary;

use App\Integrations\Healthcare\Contracts\SourceMessageNormalizer;
use App\Integrations\Healthcare\DTO\NormalizedPayload;
use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use App\Integrations\Healthcare\Services\FhirResourcePolicy;
use Carbon\CarbonImmutable;
use Throwable;

final class LabResultFhirNormalizer implements SourceMessageNormalizer
{
    private const SYSTEM_CLASSES = ['lis', 'lab', 'lab_middleware'];

    public function __construct(private readonly FhirResourcePolicy $policy) {}

    public function supports(SourceMessage $message): bool
    {
        $resource = $this->resource($message);

        return in_array($resource['resourceType'] ?? null, ['Observation', 'DiagnosticReport'], true)
            && in_array(strtolower((string) ($message->metadata['system_class'] ?? '')), self::SYSTEM_CLASSES, true);
    }

    public function normalize(SourceMessage $message): NormalizedPayload
    {
        $resource = $this->resource($message);
        $resourceType = (string) ($resource['resourceType'] ?? '');
        try {
            $this->policy->assertResourceAllowed($resourceType);
        } catch (Throwable $exception) {
            throw new AncillaryIngestException('fhir_resource_not_allowed', 'The Laboratory FHIR result resource type is not enabled.', previous: $exception);
        }

        $profile = AncillarySourceProfile::from($message);
        $profile->assertFamily('FHIR');
        if ($profile->departments !== [] && ! in_array('lab', $profile->departments, true)) {
            throw new AncillaryIngestException('source_message_mismatch', 'The Laboratory FHIR result is outside the source department scope.');
        }

        $id = $this->required($resource, 'id');
        $version = trim((string) ($resource['meta']['versionId'] ?? '1')) ?: '1';
        $requestId = $this->basedOnId($resource);
        if ($requestId === null) {
            throw new AncillaryIngestException('missing_order_identity', 'The Laboratory FHIR result is missing its ServiceRequest identity.');
        }
        $resultedAt = $this->timestamp(
            $resource['issued']
                ?? $resource['effectiveDateTime']
                ?? $resource['effectivePeriod']['end']
                ?? $resource['meta']['lastUpdated']
                ?? null,
            $resourceType,
        );
        $observedAt = $this->optionalTimestamp(
            $resource['effectiveDateTime'] ?? $resource['effectivePeriod']['start'] ?? null,
            $resourceType,
        );
        [$status, $milestones, $verified] = $this->status((string) ($resource['status'] ?? ''));
        $test = $this->testIdentity(is_array($resource['code'] ?? null) ? $resource['code'] : []);
        if (! isset($test['test_code']) && ! isset($test['loinc_code'])) {
            throw new AncillaryIngestException('missing_result_identity', 'The Laboratory FHIR result is missing its local/LOINC test identity.');
        }
        $specimenKey = $this->specimenId($resource);
        $subject = trim((string) ($resource['subject']['reference'] ?? ''));
        $encounter = trim((string) ($resource['encounter']['reference'] ?? ''));
        $abnormal = $this->abnormalFlag($resource);
        $autoVerified = $this->extensionBoolean($resource, 'auto-verified');
        $analyzerReference = trim((string) ($resource['device']['reference'] ?? ''));
        $stage = $this->resultStage($resource, $status);
        $common = array_filter([
            'department' => 'lab',
            'work_item_type' => 'lab_order',
            'source_order_key' => $requestId,
            'reconciliation_key' => "ServiceRequest/{$requestId}",
            'patient_ref' => $subject !== '' ? $this->pseudonym($profile->sourceKey, 'patient', $subject) : null,
            'encounter_ref' => $encounter !== '' ? $this->pseudonym($profile->sourceKey, 'encounter', $encounter) : null,
            'patient_class' => $this->extensionCode($resource, 'patient-class') ?? 'unknown',
            'priority' => 'unknown',
            'ordered_at' => $resultedAt->toIso8601String(),
            'ordered_at_source' => 'result_fallback',
            ...$test,
            'source_specimen_key' => $specimenKey,
            'source_result_key' => "{$resourceType}/{$id}",
            'source_result_version' => $version,
            'result_status' => $status,
            'result_stage' => $stage,
            'abnormal_flag' => $abnormal,
            'is_critical' => $abnormal === 'critical',
            'auto_verified' => $autoVerified,
            'analyzer_ref' => $analyzerReference !== '' ? $this->pseudonym($profile->sourceKey, 'analyzer', $analyzerReference) : null,
            'observed_at' => $observedAt?->toIso8601String(),
            'resulted_at' => $resultedAt->toIso8601String(),
            'corrected_at' => $status === 'corrected' ? $resultedAt->toIso8601String() : null,
            'cancelled_at' => $status === 'cancelled' ? $resultedAt->toIso8601String() : null,
            'critical_identified_at' => $abnormal === 'critical' ? $resultedAt->toIso8601String() : null,
            'result_time_source' => isset($resource['issued']) ? "{$resourceType}.issued" : (isset($resource['effectiveDateTime']) ? "{$resourceType}.effectiveDateTime" : "{$resourceType}.meta.lastUpdated"),
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
        $first = $events[0];

        return new NormalizedPayload(
            messageType: 'FHIR_'.$resourceType,
            eventType: AncillaryEventVocabulary::eventTypeFor((string) $first['milestone_code']),
            payload: count($events) === 1 ? $first : [...$first, 'order_groups' => $events],
            idempotencyKey: implode(':', ['ancillary', $profile->sourceKey, $resourceType, $id, $version]),
            externalId: "{$resourceType}/{$id}/_history/{$version}",
            occurredAt: $resultedAt->toIso8601String(),
            metadata: [
                'connector_key' => 'ancillary.healthcare',
                'source_protocol' => 'fhir',
                'message_family' => 'FHIR',
                'resource_type' => $resourceType,
                'resource_version' => $version,
                'event_count' => count($events),
            ],
        );
    }

    /** @return array<string, mixed> */
    private function resource(SourceMessage $message): array
    {
        $resource = $message->payload['resource'] ?? $message->payload;

        return is_array($resource) ? $resource : [];
    }

    /** @return array{0:string,1:list<string>,2:bool} */
    private function status(string $status): array
    {
        return match (strtolower(trim($status))) {
            'preliminary', 'registered', 'partial' => ['preliminary', ['LAB_PRELIM'], false],
            'final' => ['final', ['LAB_RESULTED', 'LAB_VERIFIED'], true],
            'amended', 'corrected' => ['corrected', ['LAB_CORRECTED'], true],
            'cancelled', 'entered-in-error' => ['cancelled', ['LAB_CANCELLED'], false],
            default => throw new AncillaryIngestException('invalid_result_status', 'The Laboratory FHIR result status is unsupported.'),
        };
    }

    /** @param array<string, mixed> $resource */
    private function basedOnId(array $resource): ?string
    {
        foreach ($resource['basedOn'] ?? [] as $basedOn) {
            $id = $this->referenceId(is_array($basedOn) ? ($basedOn['reference'] ?? null) : null, 'ServiceRequest');
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    private function referenceId(mixed $reference, string $resourceType): ?string
    {
        if (! is_string($reference) || trim($reference) === '') {
            return null;
        }
        if (! preg_match('#(?:^|/)'.preg_quote($resourceType, '#').'/([^/]+)(?:/_history/[^/]+)?$#', trim($reference), $matches)) {
            return null;
        }

        return trim($matches[1]) ?: null;
    }

    /** @param array<string, mixed> $resource */
    private function specimenId(array $resource): ?string
    {
        $specimen = $resource['specimen'] ?? null;
        if (is_array($specimen) && isset($specimen['reference'])) {
            return $this->referenceId($specimen['reference'], 'Specimen');
        }
        foreach (is_array($specimen) ? $specimen : [] as $reference) {
            $id = $this->referenceId(is_array($reference) ? ($reference['reference'] ?? null) : null, 'Specimen');
            if ($id !== null) {
                return $id;
            }
        }

        return null;
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
            $candidate = ['code' => trim((string) $coding['code']), 'label' => trim((string) ($coding['display'] ?? ''))];
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

    /** @param array<string, mixed> $resource */
    private function abnormalFlag(array $resource): string
    {
        $flags = [];
        foreach ($resource['interpretation'] ?? [] as $interpretation) {
            foreach (is_array($interpretation) ? ($interpretation['coding'] ?? []) : [] as $coding) {
                if (is_array($coding)) {
                    $flags[] = strtoupper(trim((string) ($coding['code'] ?? '')));
                }
            }
        }
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

    /** @param array<string, mixed> $resource */
    private function resultStage(array $resource, string $status): string
    {
        if ($status === 'corrected') {
            return 'corrected';
        }
        if ($status === 'cancelled') {
            return 'cancelled';
        }
        $stage = strtoupper((string) ($this->extensionCode($resource, 'microbiology-stage') ?? ''));

        return match ($stage) {
            'ORGANISM', 'ORGANISM_IDENTIFICATION' => 'organism_identification',
            'SUSC', 'SUSCEPTIBILITY' => 'susceptibility',
            'PRELIM', 'PRELIMINARY' => 'preliminary',
            default => $status === 'final' ? 'final' : 'preliminary',
        };
    }

    /** @param array<string, mixed> $resource */
    private function extensionBoolean(array $resource, string $suffix): bool
    {
        foreach ($resource['extension'] ?? [] as $extension) {
            if (is_array($extension)
                && str_ends_with(strtolower((string) ($extension['url'] ?? '')), strtolower($suffix))
                && is_bool($extension['valueBoolean'] ?? null)) {
                return $extension['valueBoolean'];
            }
        }

        return false;
    }

    /** @param array<string, mixed> $resource */
    private function extensionCode(array $resource, string $suffix): ?string
    {
        foreach ($resource['extension'] ?? [] as $extension) {
            if (is_array($extension) && str_ends_with(strtolower((string) ($extension['url'] ?? '')), strtolower($suffix))) {
                $value = trim((string) ($extension['valueCode'] ?? ''));

                return $value !== '' ? $value : null;
            }
        }

        return null;
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
            throw new AncillaryIngestException('missing_control_identity', "The Laboratory FHIR result is missing {$key}.");
        }

        return trim((string) $value);
    }

    private function pseudonym(string $sourceKey, string $kind, string $value): string
    {
        return hash_hmac('sha256', implode('|', [$sourceKey, $kind, $value]), (string) config('app.key'));
    }
}
