<?php

namespace App\Security\ClinicalPayloads;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ClinicalPayloadQuarantineService
{
    private const REASON_CATEGORIES = [
        'malware', 'unsafe_content', 'consent', 'policy', 'classification', 'integrity', 'encryption',
    ];

    public function __construct(
        private readonly ClinicalPayloadStore $payloads,
        private readonly ClinicalPayloadLifecycleService $lifecycle,
        private readonly ClinicalContentGuard $clinicalContent,
    ) {}

    /** @param array<string, bool|int|float|string|null> $details */
    public function quarantine(
        int $payloadObjectId,
        int $sourceId,
        string $reasonCategory,
        string $reasonCode,
        string $detectedBy,
        ?int $inboundMessageId = null,
        array $details = [],
        ?int $actorUserId = null,
    ): int {
        if (! in_array($reasonCategory, self::REASON_CATEGORIES, true)
            || preg_match('/^[a-z][a-z0-9._-]{2,119}$/', $reasonCode) !== 1
            || preg_match('/^[A-Za-z0-9_.:-]{3,120}$/', $detectedBy) !== 1) {
            throw new ClinicalPayloadException('clinical_payload_quarantine_reason_invalid');
        }
        $this->assertSafeDetails($details);

        return DB::transaction(function () use (
            $payloadObjectId,
            $sourceId,
            $reasonCategory,
            $reasonCode,
            $detectedBy,
            $inboundMessageId,
            $details,
            $actorUserId,
        ): int {
            $payload = DB::table('raw.payload_objects')
                ->where('payload_object_id', $payloadObjectId)
                ->lockForUpdate()
                ->first();
            if ($payload === null || (int) $payload->source_id !== $sourceId) {
                throw new ClinicalPayloadException('clinical_payload_authority_mismatch');
            }
            if ((string) $payload->status === 'quarantined') {
                return (int) DB::table('raw.payload_quarantines')
                    ->where('payload_object_id', $payloadObjectId)
                    ->value('payload_quarantine_id');
            }
            if ((string) $payload->status !== 'ready') {
                throw new ClinicalPayloadException('clinical_payload_quarantine_state_invalid');
            }
            if ($inboundMessageId !== null) {
                $inbound = DB::table('raw.inbound_messages')->where('inbound_message_id', $inboundMessageId)->first();
                if ($inbound === null || (int) $inbound->source_id !== $sourceId
                    || ((int) $inbound->payload_object_id !== $payloadObjectId
                        && (int) $inbound->normalized_payload_object_id !== $payloadObjectId)) {
                    throw new ClinicalPayloadException('clinical_payload_quarantine_inbound_mismatch');
                }
            }

            $quarantineId = (int) DB::table('raw.payload_quarantines')->insertGetId([
                'quarantine_uuid' => (string) Str::uuid7(),
                'payload_object_id' => $payloadObjectId,
                'source_id' => $sourceId,
                'inbound_message_id' => $inboundMessageId,
                'reason_category' => $reasonCategory,
                'reason_code' => $reasonCode,
                'status' => 'open',
                'detected_by' => $detectedBy,
                'details' => json_encode((object) $details, JSON_THROW_ON_ERROR),
                'opened_at' => now(),
                'updated_at' => now(),
            ], 'payload_quarantine_id');
            DB::table('raw.payload_quarantine_events')->insert([
                'event_uuid' => (string) Str::uuid7(),
                'payload_quarantine_id' => $quarantineId,
                'event_type' => 'opened',
                'from_status' => null,
                'to_status' => 'open',
                'reason' => 'Clinical payload was isolated by a bounded data-protection control pending governed review.',
                'actor_user_id' => $actorUserId,
                'occurred_at' => now(),
            ]);
            DB::table('raw.payload_object_events')->insert([
                'event_uuid' => (string) Str::uuid7(),
                'payload_object_id' => $payloadObjectId,
                'source_id' => $sourceId,
                'event_type' => 'quarantined',
                'from_status' => 'ready',
                'to_status' => 'quarantined',
                'legal_hold' => (bool) $payload->legal_hold,
                'reason_code' => $reasonCode,
                'reason' => 'Clinical payload access was blocked by a separate quarantine authority pending governed review.',
                'evidence_sha256' => (string) $payload->ciphertext_sha256,
                'actor_user_id' => $actorUserId,
                'occurred_at' => now(),
            ]);

            return $quarantineId;
        });
    }

    public function release(
        int $quarantineId,
        int $sourceId,
        string $governedChangeUuid,
        int $actorUserId,
    ): void {
        if (! Str::isUuid($governedChangeUuid)) {
            throw new ClinicalPayloadException('clinical_payload_governance_reference_invalid');
        }

        DB::transaction(function () use ($quarantineId, $sourceId, $governedChangeUuid, $actorUserId): void {
            $quarantine = DB::table('raw.payload_quarantines')
                ->where('payload_quarantine_id', $quarantineId)
                ->where('source_id', $sourceId)
                ->lockForUpdate()
                ->first();
            if ($quarantine === null) {
                throw new ClinicalPayloadException('clinical_payload_quarantine_missing');
            }
            if ((string) $quarantine->status === 'released') {
                return;
            }
            if ((string) $quarantine->status !== 'open') {
                throw new ClinicalPayloadException('clinical_payload_quarantine_state_invalid');
            }
            $payload = DB::table('raw.payload_objects')
                ->where('payload_object_id', $quarantine->payload_object_id)
                ->lockForUpdate()
                ->first();
            if ($payload === null || (int) $payload->source_id !== $sourceId || (string) $payload->status !== 'quarantined') {
                throw new ClinicalPayloadException('clinical_payload_quarantine_authority_mismatch');
            }

            DB::table('raw.payload_quarantine_events')->insert([
                'event_uuid' => (string) Str::uuid7(),
                'payload_quarantine_id' => $quarantineId,
                'event_type' => 'released',
                'from_status' => 'open',
                'to_status' => 'released',
                'reason' => 'Independently approved governed change released the isolated payload for bounded processing.',
                'actor_user_id' => $actorUserId,
                'governed_change_request_uuid' => $governedChangeUuid,
                'occurred_at' => now(),
            ]);
            DB::table('raw.payload_object_events')->insert([
                'event_uuid' => (string) Str::uuid7(),
                'payload_object_id' => $payload->payload_object_id,
                'source_id' => $sourceId,
                'event_type' => 'released',
                'from_status' => 'quarantined',
                'to_status' => 'ready',
                'legal_hold' => (bool) $payload->legal_hold,
                'reason_code' => 'governed_quarantine_release',
                'reason' => 'Independently approved quarantine release restored bounded access through the payload authority.',
                'evidence_sha256' => hash('sha256', $governedChangeUuid),
                'actor_user_id' => $actorUserId,
                'governed_change_request_uuid' => $governedChangeUuid,
                'occurred_at' => now(),
            ]);
        });
    }

    public function purge(
        int $quarantineId,
        int $sourceId,
        string $governedChangeUuid,
        int $actorUserId,
    ): void {
        if (! Str::isUuid($governedChangeUuid)) {
            throw new ClinicalPayloadException('clinical_payload_governance_reference_invalid');
        }

        // The caller's governed-change transaction is the unit of work. Keeping
        // this operation in that transaction preserves deletion-pending and
        // failure evidence when an external object deletion fails.
        $quarantine = DB::table('raw.payload_quarantines')
            ->where('payload_quarantine_id', $quarantineId)
            ->where('source_id', $sourceId)
            ->lockForUpdate()
            ->first();
        if ($quarantine === null) {
            throw new ClinicalPayloadException('clinical_payload_quarantine_missing');
        }
        if ((string) $quarantine->status === 'purged') {
            return;
        }
        if ((string) $quarantine->status !== 'open') {
            throw new ClinicalPayloadException('clinical_payload_quarantine_state_invalid');
        }
        $payload = DB::table('raw.payload_objects')
            ->where('payload_object_id', $quarantine->payload_object_id)
            ->lockForUpdate()
            ->first();
        if ($payload === null || (int) $payload->source_id !== $sourceId
            || ! in_array((string) $payload->status, ['quarantined', 'deletion_pending', 'deleted'], true)) {
            throw new ClinicalPayloadException('clinical_payload_quarantine_authority_mismatch');
        }
        if ((bool) $payload->legal_hold) {
            throw new ClinicalPayloadException('clinical_payload_legal_hold_active');
        }
        if (($blockers = $this->lifecycle->deletionBlockers((int) $payload->payload_object_id)) !== []) {
            throw new ClinicalPayloadException('clinical_payload_deletion_blocked:'.implode(',', $blockers));
        }

        if ((string) $payload->status !== 'deleted') {
            $this->payloads->markPurgePending(
                (int) $payload->payload_object_id,
                $sourceId,
                $actorUserId,
                $governedChangeUuid,
            );
            $this->payloads->discard(
                (int) $payload->payload_object_id,
                $sourceId,
                'governed_quarantine_purge',
                'Independently approved terminal quarantine purge removed the encrypted object after dependency and hold checks passed.',
                $actorUserId,
                $governedChangeUuid,
            );
        }

        DB::table('raw.payload_quarantine_events')->insert([
            'event_uuid' => (string) Str::uuid7(),
            'payload_quarantine_id' => $quarantineId,
            'event_type' => 'purged',
            'from_status' => 'open',
            'to_status' => 'purged',
            'reason' => 'Independently approved terminal purge removed the isolated encrypted object and retained a non-content tombstone.',
            'actor_user_id' => $actorUserId,
            'governed_change_request_uuid' => $governedChangeUuid,
            'occurred_at' => now(),
        ]);
    }

    /** @param array<string, bool|int|float|string|null> $details */
    private function assertSafeDetails(array $details): void
    {
        if (strlen(json_encode($details, JSON_THROW_ON_ERROR)) > 4096) {
            throw new ClinicalPayloadException('clinical_payload_quarantine_details_invalid');
        }
        foreach ($details as $key => $value) {
            if (! is_string($key)
                || preg_match('/secret|password|token|credential|private.?key|patient|payload|body|message|resource|name|mrn/i', $key)
                || (! is_null($value) && ! is_scalar($value))) {
                throw new ClinicalPayloadException('clinical_payload_quarantine_details_invalid');
            }
            if (is_string($value) && mb_strlen($value) > 190) {
                throw new ClinicalPayloadException('clinical_payload_quarantine_details_invalid');
            }
        }
        $this->clinicalContent->assertSafe($details, 'clinical_content_quarantine_rejected');
    }
}
