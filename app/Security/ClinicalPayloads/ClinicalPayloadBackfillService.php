<?php

namespace App\Security\ClinicalPayloads;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final class ClinicalPayloadBackfillService
{
    /** @var list<array{table: string, pk: string, column: string, pointer: string, kind: string, clear: null|string, time: string}> */
    private const TARGETS = [
        ['table' => 'raw.inbound_messages', 'pk' => 'inbound_message_id', 'column' => 'payload', 'pointer' => 'payload_object_id', 'kind' => 'raw_message', 'clear' => null, 'time' => 'received_at'],
        ['table' => 'raw.inbound_messages', 'pk' => 'inbound_message_id', 'column' => 'normalized_payload', 'pointer' => 'normalized_payload_object_id', 'kind' => 'normalized_message', 'clear' => null, 'time' => 'received_at'],
        ['table' => 'fhir.resource_versions', 'pk' => 'resource_version_id', 'column' => 'resource_data', 'pointer' => 'payload_object_id', 'kind' => 'fhir_resource', 'clear' => '{}', 'time' => 'created_at'],
        ['table' => 'integration.canonical_events', 'pk' => 'canonical_event_id', 'column' => 'payload', 'pointer' => 'payload_object_id', 'kind' => 'canonical_event', 'clear' => '{}', 'time' => 'received_at'],
        ['table' => 'ops.writeback_drafts', 'pk' => 'writeback_draft_id', 'column' => 'resource_payload', 'pointer' => 'payload_object_id', 'kind' => 'writeback_draft', 'clear' => '{}', 'time' => 'created_at'],
    ];

    public function __construct(private readonly ClinicalPayloadStore $payloads) {}

    /** @return array<string, int|string|null> */
    public function run(
        string $mode,
        ?int $sourceId = null,
        int $limit = 100,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        ?int $actorUserId = null,
    ): array {
        if (! in_array($mode, ['inventory', 'backfill'], true)) {
            throw new ClinicalPayloadException('clinical_payload_backfill_mode_invalid');
        }
        $limit = max(1, min(1000, $limit));
        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            throw new ClinicalPayloadException('clinical_payload_backfill_time_range_invalid');
        }
        if ($sourceId !== null && ! DB::table('integration.sources')->where('source_id', $sourceId)->exists()) {
            throw new ClinicalPayloadException('clinical_payload_source_missing');
        }

        $runId = (int) DB::table('raw.payload_backfill_runs')->insertGetId([
            'run_uuid' => (string) Str::uuid7(),
            'source_id' => $sourceId,
            'mode' => $mode,
            'status' => 'running',
            'requested_kinds' => json_encode(array_column(self::TARGETS, 'kind'), JSON_THROW_ON_ERROR),
            'lease_owner' => 'payload-backfill:'.Str::lower(Str::random(20)),
            'lease_expires_at' => now()->addMinutes(10),
            'requested_by_user_id' => $actorUserId,
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'payload_backfill_run_id');
        $this->event($runId, null, 'run_started', 'running', 'clinical_payload_backfill_started');

        $counts = ['scanned' => 0, 'protected' => 0, 'skipped' => 0, 'failed' => 0, 'mismatch' => 0];

        try {
            foreach (self::TARGETS as $target) {
                $remaining = $limit - $counts['scanned'];
                if ($remaining <= 0) {
                    break;
                }
                $query = DB::table($target['table'])
                    ->select([
                        $target['pk'].' as source_pk',
                        'source_id',
                        $target['column'].' as legacy_payload',
                    ])
                    ->whereNull($target['pointer'])
                    ->whereNotNull($target['column'])
                    ->when($sourceId !== null, fn ($builder) => $builder->where('source_id', $sourceId))
                    ->when($from !== null, fn ($builder) => $builder->where($target['time'], '>=', $from))
                    ->when($to !== null, fn ($builder) => $builder->where($target['time'], '<=', $to))
                    ->orderBy($target['pk'])
                    ->limit($remaining);
                if ($target['clear'] === '{}') {
                    $query->whereRaw($target['column']." <> '{}'::jsonb");
                }

                foreach ($query->get() as $row) {
                    $counts['scanned']++;
                    if ($row->source_id === null) {
                        $legacySha256 = hash('sha256', (string) $row->legacy_payload);
                        $item = $this->registerItem($runId, $target, $row, $legacySha256);
                        $this->finishItem(
                            (int) $item->payload_backfill_item_id,
                            'failed',
                            null,
                            'clinical_payload_source_missing',
                        );
                        $this->event(
                            $runId,
                            (int) $item->payload_backfill_item_id,
                            'failed',
                            'failed',
                            'clinical_payload_source_missing',
                            $legacySha256,
                        );
                        $counts['failed']++;

                        continue;
                    }
                    try {
                        $payload = $this->decode($row->legacy_payload);
                        $legacySha256 = $this->hash($payload);
                    } catch (Throwable $exception) {
                        $legacySha256 = hash('sha256', (string) $row->legacy_payload);
                        $item = $this->registerItem($runId, $target, $row, $legacySha256);
                        $errorCode = $this->errorCode($exception);
                        $this->finishItem((int) $item->payload_backfill_item_id, 'failed', null, $errorCode);
                        $this->event($runId, (int) $item->payload_backfill_item_id, 'failed', 'failed', $errorCode, $legacySha256);
                        $counts['failed']++;

                        continue;
                    }
                    $item = $this->registerItem($runId, $target, $row, $legacySha256);
                    if ($item->status === 'mismatch') {
                        $counts['mismatch']++;

                        continue;
                    }
                    if ($mode === 'inventory') {
                        continue;
                    }
                    $result = $this->protect($runId, $target, $row, $payload, $legacySha256, $actorUserId);
                    $counts[$result]++;
                }
            }

            $status = $counts['failed'] > 0 || $counts['mismatch'] > 0 ? 'completed_with_errors' : 'completed';
            DB::table('raw.payload_backfill_runs')->where('payload_backfill_run_id', $runId)->update([
                'status' => $status,
                'scanned_count' => $counts['scanned'],
                'protected_count' => $counts['protected'],
                'skipped_count' => $counts['skipped'],
                'failed_count' => $counts['failed'],
                'mismatch_count' => $counts['mismatch'],
                'lease_owner' => null,
                'lease_expires_at' => null,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->event($runId, null, 'run_completed', $status, 'clinical_payload_backfill_completed', null, $counts);

            return [
                'runId' => $runId,
                'mode' => $mode,
                'status' => $status,
                ...$counts,
            ];
        } catch (Throwable $exception) {
            DB::table('raw.payload_backfill_runs')->where('payload_backfill_run_id', $runId)->update([
                'status' => 'failed',
                'error_code' => $this->errorCode($exception),
                'scanned_count' => $counts['scanned'],
                'protected_count' => $counts['protected'],
                'skipped_count' => $counts['skipped'],
                'failed_count' => $counts['failed'] + 1,
                'mismatch_count' => $counts['mismatch'],
                'lease_owner' => null,
                'lease_expires_at' => null,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->event($runId, null, 'run_completed', 'failed', $this->errorCode($exception), null, $counts);

            throw $exception;
        }
    }

    /** @param array{table: string, pk: string, column: string, pointer: string, kind: string, clear: null|string, time: string} $target */
    private function registerItem(int $runId, array $target, object $row, string $legacySha256): object
    {
        $item = DB::table('raw.payload_backfill_items')
            ->where('source_table', $target['table'])
            ->where('source_pk', $row->source_pk)
            ->where('source_column', $target['column'])
            ->first();
        if ($item !== null) {
            if (! hash_equals((string) $item->legacy_sha256, $legacySha256)) {
                DB::table('raw.payload_backfill_items')->where('payload_backfill_item_id', $item->payload_backfill_item_id)->update([
                    'status' => 'mismatch',
                    'last_error_code' => 'clinical_payload_legacy_hash_drift',
                    'updated_at' => now(),
                ]);
                $this->event($runId, (int) $item->payload_backfill_item_id, 'mismatch', 'mismatch', 'clinical_payload_legacy_hash_drift', $legacySha256);

                return DB::table('raw.payload_backfill_items')->where('payload_backfill_item_id', $item->payload_backfill_item_id)->first();
            }

            return $item;
        }

        $itemId = (int) DB::table('raw.payload_backfill_items')->insertGetId([
            'source_table' => $target['table'],
            'source_pk' => $row->source_pk,
            'source_column' => $target['column'],
            'source_id' => $row->source_id,
            'payload_kind' => $target['kind'],
            'legacy_sha256' => $legacySha256,
            'status' => 'pending',
            'first_seen_at' => now(),
            'updated_at' => now(),
        ], 'payload_backfill_item_id');
        $this->event($runId, $itemId, 'inventoried', 'pending', 'clinical_payload_legacy_row_inventoried', $legacySha256);

        return DB::table('raw.payload_backfill_items')->where('payload_backfill_item_id', $itemId)->first();
    }

    /**
     * @param  array{table: string, pk: string, column: string, pointer: string, kind: string, clear: null|string, time: string}  $target
     * @param  array<string, mixed>|list<mixed>  $payload
     * @return 'protected'|'skipped'|'failed'|'mismatch'
     */
    private function protect(
        int $runId,
        array $target,
        object $row,
        array $payload,
        string $legacySha256,
        ?int $actorUserId,
    ): string {
        $item = DB::table('raw.payload_backfill_items')
            ->where('source_table', $target['table'])
            ->where('source_pk', $row->source_pk)
            ->where('source_column', $target['column'])
            ->firstOrFail();
        $leaseOwner = 'payload-item:'.Str::lower(Str::random(20));
        $claimed = DB::table('raw.payload_backfill_items')
            ->where('payload_backfill_item_id', $item->payload_backfill_item_id)
            ->whereIn('status', ['pending', 'failed'])
            ->where('attempt_count', '<', 25)
            ->where(fn ($builder) => $builder->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))
            ->update([
                'lease_owner' => $leaseOwner,
                'lease_expires_at' => now()->addMinutes(5),
                'attempt_count' => DB::raw('attempt_count + 1'),
                'last_error_code' => null,
                'updated_at' => now(),
            ]);
        if ($claimed !== 1) {
            $this->event($runId, (int) $item->payload_backfill_item_id, 'skipped', 'skipped', 'clinical_payload_backfill_item_leased', $legacySha256);

            return 'skipped';
        }
        $this->event($runId, (int) $item->payload_backfill_item_id, 'lease_acquired', 'pending', 'clinical_payload_backfill_lease_acquired', $legacySha256);

        $stored = null;
        try {
            $stored = $this->payloads->storeJson((int) $row->source_id, $target['kind'], $payload, actorUserId: $actorUserId);
            $updated = DB::table($target['table'])
                ->where($target['pk'], $row->source_pk)
                ->whereNull($target['pointer'])
                ->whereRaw($target['column'].' = ?::jsonb', [$this->encode($payload)])
                ->update([
                    $target['column'] => $target['clear'],
                    $target['pointer'] => $stored->payloadObjectId,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                $this->payloads->discard(
                    $stored->payloadObjectId,
                    (int) $row->source_id,
                    'clinical_payload_backfill_link_race',
                    'Encrypted backfill object was discarded because the legacy row changed before the pointer became authoritative.',
                    $actorUserId,
                );
                $pointer = DB::table($target['table'])->where($target['pk'], $row->source_pk)->value($target['pointer']);
                $status = $pointer === null ? 'mismatch' : 'skipped';
                $this->finishItem((int) $item->payload_backfill_item_id, $status, null, $status === 'mismatch' ? 'clinical_payload_legacy_row_drift' : null);
                $this->event($runId, (int) $item->payload_backfill_item_id, $status, $status, 'clinical_payload_backfill_link_race', $legacySha256);

                return $status;
            }
            $this->finishItem((int) $item->payload_backfill_item_id, 'protected', $stored->payloadObjectId);
            $this->event($runId, (int) $item->payload_backfill_item_id, 'protected', 'protected', 'clinical_payload_object_linked', $legacySha256);

            $restored = $this->payloads->readJson($stored->payloadObjectId, (int) $row->source_id, $target['kind']);
            if (! hash_equals($legacySha256, $this->hash($restored))) {
                $this->finishItem((int) $item->payload_backfill_item_id, 'mismatch', $stored->payloadObjectId, 'clinical_payload_restore_hash_mismatch');
                $this->event($runId, (int) $item->payload_backfill_item_id, 'mismatch', 'mismatch', 'clinical_payload_restore_hash_mismatch', $legacySha256);

                return 'mismatch';
            }
            $this->finishItem((int) $item->payload_backfill_item_id, 'verified', $stored->payloadObjectId);
            $this->event($runId, (int) $item->payload_backfill_item_id, 'verified', 'verified', 'clinical_payload_restore_verified', $legacySha256);

            return 'protected';
        } catch (Throwable $exception) {
            if ($stored !== null) {
                try {
                    $this->payloads->discard(
                        $stored->payloadObjectId,
                        (int) $row->source_id,
                        'clinical_payload_backfill_failed',
                        'Encrypted backfill object was discarded because the protected link did not complete.',
                        $actorUserId,
                    );
                } catch (Throwable) {
                    // The retained manifest and deletion-failure evidence remain available for reconciliation.
                }
            }
            $errorCode = $this->errorCode($exception);
            $this->finishItem((int) $item->payload_backfill_item_id, 'failed', $stored?->payloadObjectId, $errorCode);
            $this->event($runId, (int) $item->payload_backfill_item_id, 'failed', 'failed', $errorCode, $legacySha256);

            return 'failed';
        }
    }

    private function finishItem(int $itemId, string $status, ?int $payloadObjectId, ?string $errorCode = null): void
    {
        DB::table('raw.payload_backfill_items')->where('payload_backfill_item_id', $itemId)->update([
            'status' => $status,
            'payload_object_id' => $payloadObjectId,
            'last_error_code' => $errorCode,
            'protected_at' => in_array($status, ['protected', 'verified'], true) ? now() : null,
            'verified_at' => $status === 'verified' ? now() : null,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, int> $counts */
    private function event(
        int $runId,
        ?int $itemId,
        string $eventType,
        string $status,
        string $reasonCode,
        ?string $evidenceSha256 = null,
        array $counts = [],
    ): void {
        DB::table('raw.payload_backfill_events')->insert([
            'event_uuid' => (string) Str::uuid7(),
            'payload_backfill_run_id' => $runId,
            'payload_backfill_item_id' => $itemId,
            'event_type' => $eventType,
            'status' => $status,
            'reason_code' => Str::limit($reasonCode, 120, ''),
            'evidence_sha256' => $evidenceSha256,
            'counts' => json_encode((object) $counts, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
        ]);
    }

    /** @return array<string, mixed>|list<mixed> */
    private function decode(mixed $value): array
    {
        try {
            $decoded = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;
        } catch (JsonException) {
            throw new ClinicalPayloadException('clinical_payload_legacy_json_invalid');
        }
        if (! is_array($decoded)) {
            throw new ClinicalPayloadException('clinical_payload_legacy_json_invalid');
        }

        return $decoded;
    }

    /** @param array<string, mixed>|list<mixed> $payload */
    private function hash(array $payload): string
    {
        return hash('sha256', $this->encode($payload));
    }

    /** @param array<string, mixed>|list<mixed> $payload */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function errorCode(Throwable $exception): string
    {
        return $exception instanceof ClinicalPayloadException
            ? $exception->errorCode
            : 'clinical_payload_backfill_failed';
    }
}
