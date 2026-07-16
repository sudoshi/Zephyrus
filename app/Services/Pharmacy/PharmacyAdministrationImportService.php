<?php

namespace App\Services\Pharmacy;

use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use App\Integrations\Healthcare\Services\AncillaryMessageIngestPipeline;
use Illuminate\Support\Arr;

/**
 * Ingests a PharmacyAdministrationImport v1 batch THROUGH the governed
 * raw/canonical/projection/provenance pipeline — one inbound envelope,
 * canonical event, and projected rx_administrations row per batch row, never
 * a direct untracked insert. Row-level failures (missing cutoff or identity,
 * administered-after-cutoff, unparseable timestamps, unmatched or ambiguous
 * order linkage) fail closed into the dead-letter reconciliation ledger with
 * precise reason codes; the batch keeps going so one bad row never hides the
 * rest of the extract.
 */
final class PharmacyAdministrationImportService
{
    public function __construct(private readonly AncillaryMessageIngestPipeline $pipeline) {}

    /**
     * @param  array<string, mixed>  $batch
     * @return array{rows: int, projected: int, duplicates: int, dead_lettered: int, reasons: array<string, int>, extract_id: ?string, source_cutoff_at: ?string}
     */
    public function import(string $sourceKey, array $batch): array
    {
        $rows = is_array($batch['administrations'] ?? null) ? array_values($batch['administrations']) : [];
        if ($rows === []) {
            throw new AncillaryIngestException('empty_administration_batch', 'The administration import batch contains no administration rows.');
        }

        $header = Arr::only($batch, ['envelope_version', 'extract_id', 'source_cutoff_at', 'source_class']);
        $summary = [
            'rows' => count($rows),
            'projected' => 0,
            'duplicates' => 0,
            'dead_lettered' => 0,
            'reasons' => [],
            'extract_id' => is_scalar($batch['extract_id'] ?? null) ? (string) $batch['extract_id'] : null,
            'source_cutoff_at' => is_scalar($batch['source_cutoff_at'] ?? null) ? (string) $batch['source_cutoff_at'] : null,
        ];

        foreach ($rows as $row) {
            $row = is_array($row) ? $row : [];
            $message = new SourceMessage(
                messageType: 'RX_ADMIN_BATCH',
                payload: [...$header, 'administration' => $row],
                externalId: is_scalar($row['administration_id'] ?? null) ? (string) $row['administration_id'] : null,
                metadata: $this->rowMetadata($sourceKey, $header, $row),
            );

            try {
                $receipt = $this->pipeline->ingest($sourceKey, $message, runType: 'warehouse_batch_import');
                $receipt['duplicate'] ? $summary['duplicates']++ : $summary['projected']++;
            } catch (AncillaryIngestException $exception) {
                $summary['dead_lettered']++;
                $summary['reasons'][$exception->reasonCode] = ($summary['reasons'][$exception->reasonCode] ?? 0) + 1;
            }
        }

        return $summary;
    }

    /**
     * A stable extract-scoped inbound idempotency key: reimporting the same
     * batch short-circuits, while the same identity re-sent inside one
     * extract with a changed payload fails closed as a key conflict.
     *
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function rowMetadata(string $sourceKey, array $header, array $row): array
    {
        $extract = is_scalar($header['extract_id'] ?? null) ? trim((string) $header['extract_id']) : '';
        $identity = is_scalar($row['administration_id'] ?? null) ? trim((string) $row['administration_id']) : '';
        if ($extract === '' || $identity === '') {
            return [];
        }
        $version = is_scalar($row['row_version'] ?? null) ? trim((string) $row['row_version']) : '';
        $key = implode(':', ['ancillary', $sourceKey, 'rxadmin', $extract, $identity, $version !== '' ? $version : '0']);
        if (strlen($key) > 190 || ! preg_match('/^[A-Za-z0-9._:-]+$/', $key)) {
            return [];
        }

        return ['idempotency_key' => $key];
    }
}
