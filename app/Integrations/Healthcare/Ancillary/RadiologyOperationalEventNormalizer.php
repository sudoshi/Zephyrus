<?php

namespace App\Integrations\Healthcare\Ancillary;

use App\Integrations\Healthcare\Contracts\SourceMessageNormalizer;
use App\Integrations\Healthcare\DTO\NormalizedPayload;
use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use Carbon\CarbonImmutable;
use Throwable;

final class RadiologyOperationalEventNormalizer implements SourceMessageNormalizer
{
    private const FAMILIES = ['MPPS', 'PACS', 'RAD_TRANSPORT', 'CTRM'];

    public function supports(SourceMessage $message): bool
    {
        return in_array($this->family($message), self::FAMILIES, true);
    }

    public function normalize(SourceMessage $message): NormalizedPayload
    {
        $family = $this->family($message);
        $profile = AncillarySourceProfile::from($message);
        $profile->assertFamily($family);
        if ($profile->departments !== [] && ! in_array('rad', $profile->departments, true)) {
            throw new AncillaryIngestException('source_message_mismatch', 'The Radiology operational event is outside the source department scope.');
        }

        $controlId = $this->required($message->payload, 'control_id', 'missing_control_identity');
        $orderKey = $this->required($message->payload, 'source_order_key', 'missing_order_identity');
        $studyKey = in_array($family, ['MPPS', 'PACS'], true)
            ? $this->required($message->payload, 'source_study_key', 'missing_source_identity')
            : trim((string) ($message->payload['source_study_key'] ?? $orderKey));
        $status = strtoupper($this->required($message->payload, 'status', 'invalid_status'));
        $occurredAt = $this->timestamp($message->payload['occurred_at'] ?? null, 'occurred_at');

        if (in_array($family, ['MPPS', 'PACS'], true)) {
            $this->assertRelaySignatureMetadata($message->payload['source_signature'] ?? null);
        }
        if ($family === 'MPPS') {
            $this->required($message->payload, 'sop_instance_uid', 'missing_source_identity');
        }

        $mapped = match ($family) {
            'MPPS' => $this->mpps($message->payload, $status, $occurredAt),
            'PACS' => $this->pacs($status),
            'RAD_TRANSPORT' => $this->transport($message->payload, $status),
            'CTRM' => $this->critical($message->payload, $status, $occurredAt),
        };
        $milestone = $mapped['milestone_code'];

        $payload = array_filter([
            'department' => 'rad', 'milestone_code' => $milestone, 'work_item_type' => 'imaging_order',
            'source_order_key' => $orderKey, 'source_exam_key' => $studyKey, 'reconciliation_key' => $studyKey,
            'patient_class' => $message->payload['patient_class'] ?? 'unknown',
            'priority' => $message->payload['priority'] ?? 'routine',
            'ordered_at' => $message->payload['ordered_at'] ?? $occurredAt->toIso8601String(),
            'occurred_at' => $occurredAt->toIso8601String(),
            'modality' => $this->modality($message->payload['modality'] ?? null),
            'scanner_key' => $message->payload['scanner_key'] ?? null,
            'source_sop_instance_uid_hash' => $this->hashOptional($message->payload['sop_instance_uid'] ?? null),
            'transport_request_ref' => $message->payload['transport_request_ref'] ?? null,
            ...$mapped,
            'relay_signature' => in_array($family, ['MPPS', 'PACS'], true) ? [
                'algorithm' => $message->payload['source_signature']['algorithm'],
                'keyId' => $message->payload['source_signature']['key_id'],
                'verified' => true,
            ] : null,
            'source_timestamp_valid' => true,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        return new NormalizedPayload(
            messageType: 'RADIOLOGY_'.$family,
            eventType: AncillaryEventVocabulary::eventTypeFor($milestone), payload: $payload,
            idempotencyKey: implode(':', ['ancillary', $profile->sourceKey, $controlId]), externalId: $controlId,
            occurredAt: $occurredAt->toIso8601String(),
            metadata: ['connector_key' => 'ancillary.healthcare', 'source_protocol' => 'forwarded_json', 'message_family' => $family],
        );
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function mpps(array $payload, string $status, CarbonImmutable $occurredAt): array
    {
        $start = $this->timestamp($payload['performed_start_at'] ?? null, 'performed_start_at');
        $end = isset($payload['performed_end_at']) ? $this->timestamp($payload['performed_end_at'], 'performed_end_at') : null;
        if ($end !== null && $end->lessThan($start)) {
            throw new AncillaryIngestException('impossible_interval', 'The MPPS performed interval ends before it starts.');
        }

        return match ($status) {
            'IN PROGRESS', 'IN_PROGRESS' => ['milestone_code' => 'RAD_EXAM_START', 'exam_status' => 'in_progress', 'started_at' => $start->toIso8601String()],
            'COMPLETED' => $end === null
                ? throw new AncillaryIngestException('missing_timestamp', 'Completed MPPS requires performed_end_at.')
                : ['milestone_code' => 'RAD_EXAM_END', 'exam_status' => 'complete', 'started_at' => $start->toIso8601String(), 'completed_at' => $end->toIso8601String()],
            'DISCONTINUED' => ['milestone_code' => 'RAD_CANCELLED', 'exam_status' => 'discontinued', 'started_at' => $start->toIso8601String(), 'cancelled_at' => $occurredAt->toIso8601String()],
            default => throw new AncillaryIngestException('invalid_status', 'The MPPS status is unsupported.'),
        };
    }

    /** @return array<string, mixed> */
    private function pacs(string $status): array
    {
        return match ($status) {
            'IMAGES_AVAILABLE', 'STORAGE_COMMITTED' => ['milestone_code' => 'RAD_IMAGES_AVAILABLE', 'exam_status' => 'complete'],
            default => throw new AncillaryIngestException('invalid_status', 'The PACS relay status is unsupported.'),
        };
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function transport(array $payload, string $status): array
    {
        $requestRef = $this->required($payload, 'transport_request_ref', 'missing_source_identity');

        return match ($status) {
            'REQUESTED' => ['milestone_code' => 'RAD_TRANSPORT_REQUESTED', 'transport_request_ref' => $requestRef],
            'COMPLETED' => ['milestone_code' => 'RAD_TRANSPORT_COMPLETE', 'transport_request_ref' => $requestRef],
            default => throw new AncillaryIngestException('invalid_status', 'The Radiology transport status is unsupported.'),
        };
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function critical(array $payload, string $status, CarbonImmutable $occurredAt): array
    {
        $resultKey = $this->required($payload, 'source_result_key', 'missing_source_identity');
        $findingClass = strtolower((string) ($payload['finding_class'] ?? 'critical'));
        if (! in_array($findingClass, ['critical', 'urgent', 'unexpected', 'other'], true)) {
            throw new AncillaryIngestException('invalid_finding_class', 'The critical-result finding class is unsupported.');
        }

        return match ($status) {
            'NOTIFIED' => ['milestone_code' => 'RAD_CRITICAL_NOTIFIED', 'critical_status' => 'notified', 'source_result_key' => $resultKey, 'finding_class' => $findingClass, 'identified_at' => $payload['identified_at'] ?? $occurredAt->toIso8601String(), 'notified_at' => $occurredAt->toIso8601String(), 'recipient_role' => $payload['recipient_role'] ?? null],
            'ACKNOWLEDGED' => ['milestone_code' => 'RAD_CRITICAL_ACKED', 'critical_status' => 'acknowledged', 'source_result_key' => $resultKey, 'finding_class' => $findingClass, 'acknowledged_at' => $occurredAt->toIso8601String()],
            default => throw new AncillaryIngestException('invalid_status', 'The critical-result status is unsupported.'),
        };
    }

    private function family(SourceMessage $message): string
    {
        return strtoupper((string) preg_replace('/^(RADIOLOGY[._-]|ANCILLARY[._-]|STRUCTURED[._-])/', '', strtoupper($message->messageType)));
    }

    /** @param array<string, mixed> $payload */
    private function required(array $payload, string $key, string $reason): string
    {
        $value = $payload[$key] ?? null;
        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new AncillaryIngestException($reason, "The Radiology operational event is missing {$key}.");
        }

        return trim((string) $value);
    }

    private function timestamp(mixed $value, string $field): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $value)) {
            throw new AncillaryIngestException('malformed_timestamp', "The {$field} timestamp must include an explicit offset.");
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable $exception) {
            throw new AncillaryIngestException('malformed_timestamp', "The {$field} timestamp is malformed.", previous: $exception);
        }
    }

    private function assertRelaySignatureMetadata(mixed $metadata): void
    {
        if (! is_array($metadata) || ($metadata['verified'] ?? false) !== true
            || ! is_string($metadata['algorithm'] ?? null) || ! is_string($metadata['key_id'] ?? null)) {
            throw new AncillaryIngestException('invalid_relay_signature_metadata', 'The forwarded relay lacks verified source-signature metadata.');
        }
    }

    private function modality(mixed $value): ?string
    {
        $candidate = strtoupper(trim((string) $value));

        return in_array($candidate, ['XR', 'CT', 'MRI', 'US', 'NM', 'IR'], true) ? $candidate : null;
    }

    private function hashOptional(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? hash('sha256', trim((string) $value)) : null;
    }
}
