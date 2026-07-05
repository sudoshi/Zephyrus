<?php

namespace App\Services\Staffing;

use App\Models\Org\StaffingSource;
use App\Services\Staffing\Connectors\CsvUploadConnector;
use App\Services\Staffing\Connectors\FhirPractitionerConnector;
use App\Services\Staffing\Contracts\StaffingConnector;
use RuntimeException;

/**
 * Phase 7: builds the concrete StaffingConnector for a configured source. This pass
 * ships the two file-based connectors (CSV + FHIR — the zero-integration path, §12);
 * the API connectors (Workday/UKG/QGenda/Amion/Epic/SCIM/HL7) are provisioned per
 * client integration and register here as they land.
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§4, §12)
 */
class StaffingConnectorFactory
{
    /**
     * Build the connector for a source. Content may be supplied inline (`csv` string /
     * `bundle` array — the wizard's HTTP upload path) or as a file path (`file` / `fhir`
     * — the CLI path). Inline content wins when both are present. A per-run `mapping`
     * overrides the source's saved template (the wizard's field-mapping step).
     *
     * @param  array{file?:string, fhir?:string, csv?:string, bundle?:array<string,mixed>, mapping?:array<string,string>}  $opts
     */
    public function make(StaffingSource $source, array $opts = []): StaffingConnector
    {
        $mapping = isset($opts['mapping']) && is_array($opts['mapping'])
            ? $opts['mapping']
            : (is_array($source->mapping_template) ? $source->mapping_template : []);

        return match ($source->transport) {
            'file_upload' => CsvUploadConnector::fromCsvString(
                $source->source_key,
                $this->csvContent($opts),
                $mapping,
            ),
            'fhir_practitioner' => new FhirPractitionerConnector(
                $source->source_key,
                $this->bundleContent($opts),
            ),
            default => throw new RuntimeException(
                "No connector registered for transport '{$source->transport}' (source {$source->source_key})."
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    private function csvContent(array $opts): string
    {
        if (isset($opts['csv']) && is_string($opts['csv'])) {
            return $opts['csv'];
        }

        return $this->readFile($opts['file'] ?? null, 'file_upload requires a CSV (csv content or --file=<path>).');
    }

    /**
     * @param  array<string, mixed>  $opts
     * @return array<string, mixed>
     */
    private function bundleContent(array $opts): array
    {
        if (isset($opts['bundle']) && is_array($opts['bundle'])) {
            return $opts['bundle'];
        }

        return $this->readJson($opts['fhir'] ?? $opts['file'] ?? null);
    }

    private function readFile(?string $path, string $message): string
    {
        if ($path === null || ! is_file($path)) {
            throw new RuntimeException($message);
        }

        return (string) file_get_contents($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(?string $path): array
    {
        $raw = $this->readFile($path, 'fhir_practitioner requires --fhir=<path to bundle.json>');
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('FHIR bundle is not valid JSON.');
        }

        return $decoded;
    }
}
