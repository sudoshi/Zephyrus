<?php

namespace App\Integrations\Healthcare\Ancillary;

use App\Integrations\Healthcare\Contracts\SourceMessageNormalizer;
use App\Integrations\Healthcare\DTO\NormalizedPayload;
use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use Carbon\CarbonImmutable;
use Throwable;

final class AncillaryStructuredMessageNormalizer implements SourceMessageNormalizer
{
    private const FAMILIES = [
        'MPPS', 'ANALYZER', 'AUTOVERIFICATION', 'BARCODE', 'WORKFLOW', 'QUEUE',
        'VEND', 'RETURN', 'WASTE', 'OVERRIDE', 'BATCH', 'FHIR',
    ];

    public function supports(SourceMessage $message): bool
    {
        return in_array($this->family($message), self::FAMILIES, true);
    }

    public function normalize(SourceMessage $message): NormalizedPayload
    {
        $family = $this->family($message);
        $profile = AncillarySourceProfile::from($message);
        $milestoneCode = isset($message->payload['milestone_code'])
            ? strtoupper(trim((string) $message->payload['milestone_code']))
            : $profile->milestoneFor($family);
        $profile->assertFamily($family);

        if (! in_array($milestoneCode, AncillaryEventVocabulary::codes(), true)) {
            throw new AncillaryIngestException('invalid_milestone_code', 'The structured ancillary message has an invalid milestone code.');
        }
        if (! isset($profile->milestoneMap[$family]) || $profile->milestoneMap[$family] !== $milestoneCode) {
            throw new AncillaryIngestException(
                'source_message_mismatch',
                'The structured ancillary milestone is not bound to this governed source family.',
                context: ['source_key' => $profile->sourceKey, 'message_family' => $family],
            );
        }

        $controlId = $this->required($message->payload, 'control_id', 'missing_control_identity');
        $orderKey = $this->required($message->payload, 'source_order_key', 'missing_order_identity');
        $occurredAt = $this->timestamp($message->payload['occurred_at'] ?? null);
        $department = AncillaryEventVocabulary::departmentFor($milestoneCode);
        if ($profile->departments !== [] && ! in_array($department, $profile->departments, true)) {
            throw new AncillaryIngestException('source_message_mismatch', 'The structured ancillary message is outside the source department scope.');
        }

        $payload = array_filter([
            'department' => $department,
            'milestone_code' => $milestoneCode,
            'work_item_type' => $message->payload['work_item_type'] ?? $this->workItemType($department),
            'source_order_key' => $orderKey,
            'reconciliation_key' => $message->payload['reconciliation_key'] ?? null,
            'encounter_id' => $message->payload['encounter_id'] ?? null,
            'encounter_ref' => $message->payload['encounter_ref'] ?? null,
            'patient_ref' => $message->payload['patient_ref'] ?? null,
            'patient_class' => $message->payload['patient_class'] ?? null,
            'priority' => $message->payload['priority'] ?? 'routine',
            'ordered_at' => $message->payload['ordered_at'] ?? $occurredAt->toIso8601String(),
            'unit_id' => $message->payload['unit_id'] ?? null,
            'modality' => $message->payload['modality'] ?? null,
            'test_code' => $message->payload['test_code'] ?? null,
            'test_family' => $message->payload['test_family'] ?? null,
            'decision_class' => $message->payload['decision_class'] ?? null,
            'route' => $message->payload['route'] ?? null,
            'preparation_branch' => $message->payload['preparation_branch'] ?? null,
            'discharge_blocking' => $message->payload['discharge_blocking'] ?? null,
            'correction' => $message->payload['correction'] ?? null,
            'supersedes_assertion_key' => $message->payload['supersedes_assertion_key'] ?? null,
            'source_timestamp_valid' => true,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        return new NormalizedPayload(
            messageType: 'ANCILLARY_'.$family,
            eventType: AncillaryEventVocabulary::eventTypeFor($milestoneCode),
            payload: $payload,
            idempotencyKey: implode(':', ['ancillary', $profile->sourceKey, $controlId]),
            externalId: $controlId,
            occurredAt: $occurredAt->toIso8601String(),
            metadata: [
                'connector_key' => 'ancillary.healthcare',
                'source_protocol' => strtolower((string) ($message->metadata['source_protocol'] ?? 'structured')),
                'message_family' => $family,
            ],
        );
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
            throw new AncillaryIngestException($reasonCode, "The ancillary message is missing its {$key}.");
        }

        return trim((string) $value);
    }

    private function timestamp(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            throw new AncillaryIngestException('missing_timestamp', 'The ancillary message is missing its source event timestamp.');
        }
        if (! preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $value)) {
            throw new AncillaryIngestException('malformed_timestamp', 'The ancillary message timestamp must include an explicit offset.');
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable $exception) {
            throw new AncillaryIngestException('malformed_timestamp', 'The ancillary message contains a malformed source timestamp.', previous: $exception);
        }
    }

    private function workItemType(string $department): string
    {
        return match ($department) {
            'rad' => 'imaging_order',
            'lab' => 'lab_order',
            'pathology' => 'ap_case',
            'blood_bank' => 'blood_bank_request',
            'rx' => 'medication_order',
            default => 'ancillary_order',
        };
    }
}
