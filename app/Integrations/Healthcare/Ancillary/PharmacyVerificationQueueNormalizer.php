<?php

namespace App\Integrations\Healthcare\Ancillary;

use App\Integrations\Healthcare\Contracts\SourceMessageNormalizer;
use App\Integrations\Healthcare\DTO\NormalizedPayload;
use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Normalizes the versioned, vendor-neutral pharmacy verification-queue JSON
 * envelope (message family RX_QUEUE). Vendor-specific queue exports — for
 * example Epic verify-queue entry/exit events — are mapped into this envelope
 * at the adapter edge; no vendor field names cross this boundary.
 *
 * Envelope schema, version 1:
 * - envelope_version  int, REQUIRED, must equal 1.
 * - control_id        string, REQUIRED. Source-scoped event identity used for
 *                     idempotency; a re-sent event with the same control_id
 *                     and payload is a duplicate.
 * - queue_state       'entered' | 'verified' | 'removed', REQUIRED.
 * - occurred_at       ISO-8601 timestamp WITH explicit offset, REQUIRED.
 * - source_order_key  string, REQUIRED. Filler/order identity matching the
 *                     RDE order identity.
 * - placer_order_key  string, optional.
 * - queue_ref         string, optional queue identity; defaults to
 *                     'verification'.
 * - source_verification_key string, optional; defaults to
 *                     '{source_order_key}:{queue_ref}'.
 * - verifier_ref      string, optional; pseudonymized before persistence.
 * - queued_at         ISO-8601 with offset, optional; lets verified/removed
 *                     events backfill the original queue-entry time.
 * - removal_reason    for queue_state 'removed' only:
 *                     'verified' (default) | 'order_discontinued' |
 *                     'order_cancelled'. Removal for verification maps to
 *                     RX_VERIFIED; removal because the order ended maps to
 *                     the terminal RX_DISCONTINUED assertion.
 * - patient_id / encounter_id  raw source identifiers, optional; always
 *                     pseudonymized, never persisted raw.
 */
final class PharmacyVerificationQueueNormalizer implements SourceMessageNormalizer
{
    public const ENVELOPE_VERSION = 1;

    private const FAMILY = 'RX_QUEUE';

    private const STATES = ['entered', 'verified', 'removed'];

    private const REMOVAL_REASONS = ['verified', 'order_discontinued', 'order_cancelled'];

    public function supports(SourceMessage $message): bool
    {
        return $this->family($message) === self::FAMILY;
    }

    public function normalize(SourceMessage $message): NormalizedPayload
    {
        $profile = AncillarySourceProfile::from($message);
        $profile->assertFamily(self::FAMILY);
        if ($profile->departments !== [] && ! in_array('rx', $profile->departments, true)) {
            throw new AncillaryIngestException('source_message_mismatch', 'The pharmacy verification-queue event is outside the source department scope.');
        }

        $version = $message->payload['envelope_version'] ?? null;
        if (! is_int($version) || $version !== self::ENVELOPE_VERSION) {
            throw new AncillaryIngestException('unsupported_envelope_version', 'The pharmacy verification-queue envelope version is unsupported.');
        }

        $controlId = $this->required($message->payload, 'control_id', 'missing_control_identity');
        $orderKey = $this->required($message->payload, 'source_order_key', 'missing_order_identity');
        $state = strtolower($this->required($message->payload, 'queue_state', 'invalid_queue_state'));
        if (! in_array($state, self::STATES, true)) {
            throw new AncillaryIngestException('invalid_queue_state', 'The pharmacy verification-queue state is unsupported.');
        }

        $occurredAt = $this->timestamp($message->payload['occurred_at'] ?? null, 'occurred_at');
        $queuedAt = isset($message->payload['queued_at'])
            ? $this->timestamp($message->payload['queued_at'], 'queued_at')
            : null;
        $removalReason = null;
        if ($state === 'removed') {
            $removalReason = strtolower(trim((string) ($message->payload['removal_reason'] ?? 'verified'))) ?: 'verified';
            if (! in_array($removalReason, self::REMOVAL_REASONS, true)) {
                throw new AncillaryIngestException('invalid_removal_reason', 'The pharmacy verification-queue removal reason is unsupported.');
            }
        }

        $milestone = match (true) {
            $state === 'entered' => 'RX_QUEUE_IN',
            $state === 'verified', $removalReason === 'verified' => 'RX_VERIFIED',
            default => 'RX_DISCONTINUED',
        };
        $queueRef = trim((string) ($message->payload['queue_ref'] ?? '')) ?: 'verification';
        $verificationKey = trim((string) ($message->payload['source_verification_key'] ?? '')) ?: "{$orderKey}:{$queueRef}";
        $placer = trim((string) ($message->payload['placer_order_key'] ?? '')) ?: null;
        $verifier = trim((string) ($message->payload['verifier_ref'] ?? ''));
        $patient = trim((string) ($message->payload['patient_id'] ?? ''));
        $encounter = trim((string) ($message->payload['encounter_id'] ?? ''));

        $payload = array_filter([
            'department' => 'rx',
            'milestone_code' => $milestone,
            'work_item_type' => 'medication_order',
            'source_order_key' => $orderKey,
            'reconciliation_key' => $orderKey,
            'placer_order_key' => $placer,
            'patient_ref' => $patient !== '' ? $this->pseudonym($profile->sourceKey, 'patient', $patient) : null,
            'encounter_ref' => $encounter !== '' ? $this->pseudonym($profile->sourceKey, 'encounter', $encounter) : null,
            'priority' => 'unknown',
            'ordered_at' => ($queuedAt ?? $occurredAt)->toIso8601String(),
            'ordered_at_source' => 'queue_fallback',
            'occurred_at' => $occurredAt->toIso8601String(),
            'queue_state' => $state,
            'queue_ref' => $queueRef,
            'source_verification_key' => $verificationKey,
            'verifier_ref' => $verifier !== '' ? $this->pseudonym($profile->sourceKey, 'verifier', $verifier) : null,
            'queued_at' => ($state === 'entered' ? $occurredAt : $queuedAt)?->toIso8601String(),
            'verified_at' => $milestone === 'RX_VERIFIED' ? $occurredAt->toIso8601String() : null,
            'removed_at' => $state === 'removed' ? $occurredAt->toIso8601String() : null,
            'removal_reason' => $removalReason,
            'discontinued_at' => $removalReason === 'order_discontinued' ? $occurredAt->toIso8601String() : null,
            'cancelled_at' => $removalReason === 'order_cancelled' ? $occurredAt->toIso8601String() : null,
            'source_timestamp_valid' => true,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        return new NormalizedPayload(
            messageType: 'ANCILLARY_'.self::FAMILY,
            eventType: AncillaryEventVocabulary::eventTypeFor($milestone),
            payload: $payload,
            idempotencyKey: implode(':', ['ancillary', $profile->sourceKey, $controlId]),
            externalId: $controlId,
            occurredAt: $occurredAt->toIso8601String(),
            metadata: [
                'connector_key' => 'ancillary.healthcare',
                'source_protocol' => strtolower((string) ($message->metadata['source_protocol'] ?? 'forwarded_json')),
                'message_family' => self::FAMILY,
                'envelope_version' => self::ENVELOPE_VERSION,
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
            throw new AncillaryIngestException($reasonCode, "The pharmacy verification-queue event is missing its {$key}.");
        }

        return trim((string) $value);
    }

    private function timestamp(mixed $value, string $field): CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            throw new AncillaryIngestException('missing_timestamp', "The pharmacy verification-queue event is missing {$field}.");
        }
        if (! preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $value)) {
            throw new AncillaryIngestException('malformed_timestamp', "The pharmacy verification-queue {$field} must include an explicit offset.");
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable $exception) {
            throw new AncillaryIngestException('malformed_timestamp', "The pharmacy verification-queue {$field} is malformed.", previous: $exception);
        }
    }

    private function pseudonym(string $sourceKey, string $kind, string $value): string
    {
        return hash_hmac('sha256', implode('|', [$sourceKey, $kind, $value]), (string) config('app.key'));
    }
}
