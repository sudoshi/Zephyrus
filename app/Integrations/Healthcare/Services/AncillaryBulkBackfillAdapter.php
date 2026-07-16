<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Contracts\BulkBackfillAdapter;
use App\Integrations\Healthcare\DTO\BackfillRequest;
use App\Integrations\Healthcare\DTO\BulkBackfillResult;
use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use App\Models\Integration\ConnectorWatermark;
use App\Models\Integration\Source;
use InvalidArgumentException;
use Throwable;

final class AncillaryBulkBackfillAdapter implements BulkBackfillAdapter
{
    public function __construct(private readonly AncillaryMessageIngestPipeline $pipeline) {}

    public function backfill(string $sourceKey, BackfillRequest $request): BulkBackfillResult
    {
        $maximum = max(1, (int) config('integrations.ancillary.bulk_max_records', 500));
        if ($request->messages === [] || count($request->messages) > $maximum) {
            throw new InvalidArgumentException("Ancillary backfill batches must contain 1-{$maximum} records.");
        }

        $scopeKey = trim((string) ($request->scope['scope_key'] ?? 'default'));
        $nextCursor = $request->scope['next_cursor'] ?? null;
        if ($scopeKey === '' || strlen($scopeKey) > 190 || ! is_scalar($nextCursor) || trim((string) $nextCursor) === '') {
            throw new InvalidArgumentException('Ancillary backfill requires a bounded scope_key and non-empty next_cursor.');
        }
        $nextCursor = trim((string) $nextCursor);
        $source = Source::query()->where('source_key', $sourceKey)->firstOrFail();
        $watermark = ConnectorWatermark::query()->where([
            'source_id' => $source->source_id,
            'connector_key' => AncillaryMessageIngestPipeline::CONNECTOR_KEY,
            'scope_type' => 'bulk_backfill',
            'scope_key' => $scopeKey,
            'watermark_kind' => 'opaque_cursor',
        ])->first();
        if ($watermark !== null && (string) $watermark->watermark_value !== (string) $request->cursor) {
            throw new InvalidArgumentException('Ancillary backfill cursor does not match the durable checkpoint.');
        }

        $succeeded = 0;
        $failures = [];
        foreach (array_values($request->messages) as $index => $record) {
            try {
                $message = $record instanceof SourceMessage ? $record : $this->sourceMessage($record);
                $this->pipeline->ingest($sourceKey, $message, 'bulk_backfill', $request->cursor);
                $succeeded++;
            } catch (AncillaryIngestException $exception) {
                $failures[] = ['index' => $index, 'reasonCode' => $exception->reasonCode];
            } catch (Throwable) {
                $failures[] = ['index' => $index, 'reasonCode' => 'invalid_backfill_record'];
            }
        }

        $checkpointAdvanced = $failures === [];
        if ($checkpointAdvanced) {
            ConnectorWatermark::query()->updateOrCreate(
                [
                    'source_id' => $source->source_id,
                    'connector_key' => AncillaryMessageIngestPipeline::CONNECTOR_KEY,
                    'scope_type' => 'bulk_backfill',
                    'scope_key' => $scopeKey,
                    'watermark_kind' => 'opaque_cursor',
                ],
                [
                    'watermark_value' => $nextCursor,
                    'last_success_at' => now(),
                    'metadata' => ['records' => count($request->messages)],
                ],
            );
        }

        return new BulkBackfillResult(
            sourceKey: $sourceKey,
            scopeKey: $scopeKey,
            cursorBefore: $request->cursor,
            cursorAfter: $checkpointAdvanced ? $nextCursor : $request->cursor,
            received: count($request->messages),
            succeeded: $succeeded,
            failed: count($failures),
            checkpointAdvanced: $checkpointAdvanced,
            failures: $failures,
        );
    }

    private function sourceMessage(mixed $record): SourceMessage
    {
        if (! is_array($record) || ! is_array($record['payload'] ?? null)) {
            throw new InvalidArgumentException('Each ancillary backfill record must contain message_type and payload.');
        }

        return new SourceMessage(
            messageType: (string) ($record['message_type'] ?? 'unsupported'),
            payload: $record['payload'],
            externalId: isset($record['external_id']) ? (string) $record['external_id'] : null,
            receivedAt: isset($record['received_at']) ? (string) $record['received_at'] : null,
            metadata: is_array($record['metadata'] ?? null) ? $record['metadata'] : [],
        );
    }
}
