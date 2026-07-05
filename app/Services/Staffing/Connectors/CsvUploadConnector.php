<?php

namespace App\Services\Staffing\Connectors;

use App\Services\Staffing\Contracts\StaffingConnector;
use App\Services\Staffing\Support\ConnectionResult;
use App\Services\Staffing\Support\ConnectorCapabilities;
use App\Services\Staffing\Support\PullWindow;
use App\Services\Staffing\Support\RawStaffRecord;

/**
 * Phase 7: the always-available, zero-integration connector. Parses an uploaded
 * roster (rows of source columns) and applies a saved source-column -> canonical-field
 * mapping to stream RawStaffRecords. Drives the wizard's CSV-upload path (§12).
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§4)
 */
class CsvUploadConnector implements StaffingConnector
{
    /**
     * @param  list<array<string, mixed>>  $rows  header-keyed source rows
     * @param  array<string, string>  $mapping  source_column => canonical_field (empty = identity)
     */
    public function __construct(
        private readonly string $sourceKey,
        private readonly array $rows,
        private readonly array $mapping = [],
    ) {}

    /**
     * Parse a CSV string (first row = header) into a connector.
     *
     * @param  array<string, string>  $mapping
     */
    public static function fromCsvString(string $sourceKey, string $csv, array $mapping = []): self
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        $rows = [];
        $header = null;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $cells = str_getcsv($line);
            if ($header === null) {
                $header = array_map(static fn ($h): string => trim((string) $h), $cells);

                continue;
            }
            $row = [];
            foreach ($header as $i => $col) {
                $row[$col] = $cells[$i] ?? null;
            }
            $rows[] = $row;
        }

        return new self($sourceKey, $rows, $mapping);
    }

    public function key(): string
    {
        return $this->sourceKey;
    }

    public function testConnection(): ConnectionResult
    {
        return $this->rows === []
            ? ConnectionResult::fail('No rows in uploaded roster.')
            : ConnectionResult::ok('Roster parsed.', ['rows' => count($this->rows)]);
    }

    public function discoverSchema(): array
    {
        $columns = $this->rows === [] ? [] : array_keys($this->rows[0]);
        $descriptors = [];

        foreach ($columns as $column) {
            $samples = [];
            foreach (array_slice($this->rows, 0, 3) as $row) {
                $value = $row[$column] ?? null;
                if ($value !== null && $value !== '') {
                    $samples[] = (string) $value;
                }
            }
            $descriptors[] = ['field' => $column, 'samples' => $samples];
        }

        return $descriptors;
    }

    public function pullStaff(PullWindow $window): iterable
    {
        foreach ($this->rows as $row) {
            $canonical = $this->applyMapping($row);

            if (($canonical['external_id'] ?? '') === '') {
                continue; // a row with no stable id cannot be deduped — skip
            }

            yield RawStaffRecord::fromArray($this->sourceKey, $canonical);
        }
    }

    public function capabilities(): ConnectorCapabilities
    {
        return new ConnectorCapabilities(incremental: false, onCall: false, credentials: false, push: false);
    }

    /**
     * Map a source row onto canonical fields. With no mapping, headers are assumed
     * to already be canonical field names. The untouched row is preserved in `raw`.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function applyMapping(array $row): array
    {
        if ($this->mapping === []) {
            $row['raw'] = $row;

            return $row;
        }

        $canonical = [];
        foreach ($this->mapping as $sourceColumn => $canonicalField) {
            if (array_key_exists($sourceColumn, $row)) {
                $canonical[$canonicalField] = $row[$sourceColumn];
            }
        }
        $canonical['raw'] = $row;

        return $canonical;
    }
}
