<?php

namespace App\Security\ClinicalPayloads;

use Illuminate\Support\Facades\DB;
use Throwable;

final class ClinicalPayloadLifecycleService
{
    public function __construct(private readonly ClinicalPayloadStore $payloads) {}

    /** @return array{scanned: int, eligible: int, blocked: int, deleted: int, failed: int} */
    public function enforce(?int $sourceId = null, int $limit = 100, bool $execute = false, ?int $actorUserId = null): array
    {
        $limit = max(1, min(1000, $limit));
        $rows = DB::table('raw.payload_objects')
            ->whereIn('status', ['ready', 'retention_pending'])
            ->where('legal_hold', false)
            ->where('retain_until', '<=', now())
            ->when($sourceId !== null, fn ($query) => $query->where('source_id', $sourceId))
            ->orderBy('retain_until')
            ->orderBy('payload_object_id')
            ->limit($limit)
            ->get();
        $counts = ['scanned' => 0, 'eligible' => 0, 'blocked' => 0, 'deleted' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            $counts['scanned']++;
            if ($this->deletionBlockers((int) $row->payload_object_id) !== []) {
                $counts['blocked']++;

                continue;
            }
            $counts['eligible']++;
            if (! $execute) {
                continue;
            }
            try {
                $this->payloads->markRetentionPending(
                    (int) $row->payload_object_id,
                    (int) $row->source_id,
                    $actorUserId,
                );
                $this->payloads->discard(
                    (int) $row->payload_object_id,
                    (int) $row->source_id,
                    'retention_policy_deletion',
                    'Encrypted clinical payload was deleted after its retention boundary and dependency checks passed.',
                    $actorUserId,
                );
                $counts['deleted']++;
            } catch (Throwable) {
                $counts['failed']++;
            }
        }

        return $counts;
    }

    /** @return list<string> */
    public function deletionBlockers(int $payloadObjectId): array
    {
        $blockers = [];

        if (DB::table('ops.writeback_drafts')
            ->where('payload_object_id', $payloadObjectId)
            ->whereNotIn('status', ['sent', 'cancelled', 'rejected', 'expired'])
            ->exists()) {
            $blockers[] = 'unresolved_writeback';
        }

        if (DB::table('integration.canonical_events')
            ->where('payload_object_id', $payloadObjectId)
            ->whereIn('projection_status', ['pending', 'failed'])
            ->exists()) {
            $blockers[] = 'unresolved_canonical_projection';
        }

        $inboundIds = DB::table('raw.inbound_messages')
            ->where('payload_object_id', $payloadObjectId)
            ->orWhere('normalized_payload_object_id', $payloadObjectId)
            ->pluck('inbound_message_id');
        if ($inboundIds->isNotEmpty()) {
            if (DB::table('raw.dead_letters')
                ->whereIn('inbound_message_id', $inboundIds)
                ->where('status', 'open')
                ->exists()) {
                $blockers[] = 'open_dead_letter';
            }
            if (DB::table('integration.canonical_events')
                ->whereIn('inbound_message_id', $inboundIds)
                ->whereIn('projection_status', ['pending', 'failed'])
                ->exists()) {
                $blockers[] = 'unresolved_inbound_projection';
            }
        }

        if (DB::table('raw.payload_backfill_items')
            ->where('payload_object_id', $payloadObjectId)
            ->where('status', '<>', 'verified')
            ->exists()) {
            $blockers[] = 'unresolved_backfill';
        }

        sort($blockers);

        return $blockers;
    }

    /** @return array{payloadObjectId: int, status: string} */
    public function purge(
        int $payloadObjectId,
        int $sourceId,
        string $governedChangeUuid,
        int $actorUserId,
    ): array {
        $row = DB::table('raw.payload_objects')
            ->where('payload_object_id', $payloadObjectId)
            ->where('source_id', $sourceId)
            ->first();
        if ($row === null) {
            throw new ClinicalPayloadException('clinical_payload_authority_mismatch');
        }
        if ((bool) $row->legal_hold) {
            throw new ClinicalPayloadException('clinical_payload_legal_hold_active');
        }
        if ((string) $row->status === 'quarantined') {
            throw new ClinicalPayloadException('clinical_payload_quarantine_active');
        }
        if ((string) $row->status === 'deleted') {
            throw new ClinicalPayloadException('clinical_payload_deleted');
        }
        if (($blockers = $this->deletionBlockers($payloadObjectId)) !== []) {
            throw new ClinicalPayloadException('clinical_payload_deletion_blocked:'.implode(',', $blockers));
        }

        $this->payloads->markPurgePending($payloadObjectId, $sourceId, $actorUserId, $governedChangeUuid);
        $this->payloads->discard(
            $payloadObjectId,
            $sourceId,
            'governed_exceptional_purge',
            'Independently approved exceptional purge removed the encrypted object after dependency and hold checks passed.',
            $actorUserId,
            $governedChangeUuid,
        );

        return ['payloadObjectId' => $payloadObjectId, 'status' => 'deleted'];
    }

    /** @return array{sampled: int, verified: int, failed: int} */
    public function sampleIntegrity(?int $sourceId = null, ?int $limit = null, ?int $actorUserId = null): array
    {
        $limit = max(1, min(100, $limit ?? (int) config('clinical-payloads.integrity_sample_limit', 25)));
        $rows = DB::table('raw.payload_objects')
            ->where('status', 'ready')
            ->when($sourceId !== null, fn ($query) => $query->where('source_id', $sourceId))
            ->orderByRaw('last_verified_at ASC NULLS FIRST')
            ->orderBy('payload_object_id')
            ->limit($limit)
            ->get(['payload_object_id', 'source_id']);
        $counts = ['sampled' => 0, 'verified' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            $counts['sampled']++;
            try {
                $this->payloads->verify((int) $row->payload_object_id, (int) $row->source_id, $actorUserId);
                $counts['verified']++;
            } catch (Throwable) {
                $counts['failed']++;
            }
        }

        return $counts;
    }
}
