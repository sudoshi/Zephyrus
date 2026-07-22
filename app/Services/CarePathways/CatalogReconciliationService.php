<?php

namespace App\Services\CarePathways;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

class CatalogReconciliationService
{
    /**
     * Recompute the release controls from immutable raw relations.
     *
     * @return array<string, mixed>
     */
    public function reconcile(int $rawReleaseId): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Care-pathway release reconciliation requires PostgreSQL.');
        }

        $tables = (array) config('care-pathways.raw_tables');
        $release = (array) config('care-pathways.source_release');
        $expected = (array) config('care-pathways.expected_controls');
        $labels = (array) config('care-pathways.status_labels');

        $manifestRows = $this->rows($tables['manifest'] ?? '', true);
        $manifest = $this->manifestForRelease($manifestRows, $rawReleaseId);
        $pathways = $this->rows($tables['pathways'] ?? '', true);
        $ledger = $this->rows($tables['ledger'] ?? '', true);
        $claims = $this->rows($tables['claims'] ?? '', true);
        $sources = $this->rows($tables['sources'] ?? '', true);
        $changes = $this->rows($tables['changes'] ?? '', true);
        $codebook = $this->rows($tables['codebook'] ?? '', true);
        $enrichments = $this->rows($tables['source_enrichments'] ?? '', true);
        $completeness = $this->rows($tables['completeness'] ?? '', true);

        $this->assertManifestIdentity($manifest, $release, $rawReleaseId);
        $this->assertCount('pathways', $pathways, $expected);
        $this->assertCount('claims', $claims, $expected);
        $this->assertCount('sources', $sources, $expected);
        $this->assertCount('changes', $changes, $expected);
        $this->assertCount('unique_drg_codes', $codebook, $expected);
        $this->assertCount('pathways', $ledger, $expected, 'verification ledger rows');
        $this->assertCount('source_enrichment_resolution_records', $enrichments, $expected);
        $this->assertCount('completeness_audit_rows', $completeness, $expected);

        $enrichmentFieldFacts = array_sum(array_map(function (array $row): int {
            $count = 0;
            foreach (['enriched_title', 'enriched_first_author', 'enriched_journal', 'enriched_publication_date', 'enriched_publication_types'] as $field) {
                if ($this->string($row, $field) !== '') {
                    $count++;
                }
            }

            return $count > 0 ? $count : 1;
        }, $enrichments));
        $this->assertControl('source_enrichment_field_facts', $enrichmentFieldFacts, $expected);

        $pathwaysByRank = $this->keyByUniqueInteger($pathways, 'rank', 'verified pathways');
        $ledgerByRank = $this->keyByUniqueInteger($ledger, 'rank', 'verification ledger');

        foreach ($pathwaysByRank as $rank => $pathway) {
            $ledgerRow = $ledgerByRank[$rank] ?? null;
            if ($ledgerRow === null
                || $this->string($ledgerRow, 'condition') !== $this->string($pathway, 'condition')
                || $this->string($ledgerRow, 'drg_codes') !== $this->string($pathway, 'drg_codes')) {
                throw new RuntimeException("Verified pathway rank {$rank} does not match its verification-ledger row.");
            }
        }

        $evidenceVerified = $this->countValue($pathways, 'verification_status', (string) $labels['evidence_verified']);
        $evidenceLimitations = $this->countValue($pathways, 'verification_status', (string) $labels['evidence_limitations']);
        $signoffQueue = $this->countValue($pathways, 'release_disposition', (string) $labels['signoff_queue']);
        $specialistReview = $this->countValue($pathways, 'release_disposition', (string) $labels['specialist_review']);
        $redesign = $this->countValue($pathways, 'release_disposition', (string) $labels['redesign']);
        $clinicalSignoff = count(array_filter(
            $pathways,
            fn (array $row): bool => $this->string($row, 'clinical_signoff_status') !== (string) $labels['not_clinically_approved'],
        ));

        $this->assertControl('evidence_verified', $evidenceVerified, $expected);
        $this->assertControl('evidence_limitations', $evidenceLimitations, $expected);
        $this->assertControl('signoff_queue', $signoffQueue, $expected);
        $this->assertControl('specialist_review', $specialistReview, $expected);
        $this->assertControl('redesign', $redesign, $expected);
        $this->assertControl('clinical_signoff', $clinicalSignoff, $expected);

        $codebookByDrg = [];
        foreach ($codebook as $row) {
            $drg = $this->drg($this->string($row, 'ms_drg'));
            if (isset($codebookByDrg[$drg])) {
                throw new RuntimeException("Duplicate MS-DRG {$drg} in the verification codebook.");
            }
            $codebookByDrg[$drg] = $row;
        }

        $mappingCounts = [];
        $associations = 0;
        foreach ($pathways as $row) {
            $drgs = $this->drgList($this->string($row, 'drg_codes'));
            if ($drgs === []) {
                throw new RuntimeException('Every verified pathway must retain at least one MS-DRG candidate mapping.');
            }

            foreach ($drgs as $drg) {
                if (! isset($codebookByDrg[$drg])) {
                    throw new RuntimeException("Pathway rank {$row['rank']} references MS-DRG {$drg}, which is absent from the codebook.");
                }
                $mappingCounts[$drg] = ($mappingCounts[$drg] ?? 0) + 1;
                $associations++;
            }
        }

        if (array_diff_key($codebookByDrg, $mappingCounts) !== []) {
            throw new RuntimeException('The pathway/codebook MS-DRG sets do not match exactly.');
        }

        $overlappingDrgs = count(array_filter($mappingCounts, fn (int $count): bool => $count > 1));
        $this->assertControl('pathway_drg_associations', $associations, $expected);
        $this->assertControl('overlapping_drg_codes', $overlappingDrgs, $expected);

        $sourcesByPmid = [];
        foreach ($sources as $row) {
            $pmid = $this->string($row, 'pmid');
            if ($pmid === '' || isset($sourcesByPmid[$pmid])) {
                throw new RuntimeException('Every complete source-index row must have one unique PMID.');
            }
            if ($this->string($row, 'title') === '' || $this->string($row, 'source_url') === '') {
                throw new RuntimeException("Source PMID {$pmid} still has an unclassified required bibliographic absence.");
            }
            $sourcesByPmid[$pmid] = $row;
        }

        foreach ($claims as $row) {
            $rank = (int) ($row['rank'] ?? 0);
            if (! isset($pathwaysByRank[$rank])) {
                throw new RuntimeException("Claim row references unknown pathway rank {$rank}.");
            }

            $pmids = $this->pmidList($this->string($row, 'evidence_pmids'));
            if ($pmids === []) {
                throw new RuntimeException("Claim row for rank {$rank} has no evidence PMID.");
            }
            foreach ($pmids as $pmid) {
                if (! isset($sourcesByPmid[$pmid])) {
                    throw new RuntimeException("Claim row for rank {$rank} references PMID {$pmid}, which is absent from the complete source index.");
                }
            }
        }

        $volumeTotal = array_sum(array_map(fn (array $row): int => (int) ($row['approx_annual_discharges'] ?? 0), $pathways));
        $lastPathway = $pathwaysByRank[max(array_keys($pathwaysByRank))];
        $coverage = (float) ($lastPathway['cumulative_pct_admissions'] ?? 0);
        $this->assertControl('volume_total', $volumeTotal, $expected);
        $this->assertFloatControl('coverage_percent', $coverage, $expected);
        $this->assertNoResidualUnclassifiedAbsence($completeness, (int) ($expected['residual_unclassified_absence'] ?? 0));

        return [
            'identity' => $release,
            'manifest' => $manifest,
            'pathways' => $pathways,
            'ledger' => $ledger,
            'claims' => $claims,
            'sources' => $sources,
            'changes' => $changes,
            'codebook' => $codebook,
            'enrichments' => $enrichments,
            'completeness' => $completeness,
            'computed_controls' => [
                'pathways' => count($pathways),
                'pathway_drg_associations' => $associations,
                'unique_drg_codes' => count($codebookByDrg),
                'overlapping_drg_codes' => $overlappingDrgs,
                'claims' => count($claims),
                'sources' => count($sources),
                'changes' => count($changes),
                'evidence_verified' => $evidenceVerified,
                'evidence_limitations' => $evidenceLimitations,
                'signoff_queue' => $signoffQueue,
                'specialist_review' => $specialistReview,
                'redesign' => $redesign,
                'clinical_signoff' => $clinicalSignoff,
                'volume_total' => $volumeTotal,
                'coverage_percent' => $coverage,
                'residual_unclassified_absence' => 0,
                'source_enrichment_resolution_records' => count($enrichments),
                'source_enrichment_field_facts' => $enrichmentFieldFacts,
                'completeness_audit_rows' => count($completeness),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $relation, bool $required): array
    {
        if ($relation === '' || ! preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $relation)) {
            throw new RuntimeException('Invalid configured raw care-pathway relation.');
        }

        $exists = DB::selectOne('SELECT to_regclass(?) AS relation_name', [$relation]);
        if (! $exists instanceof stdClass || $exists->relation_name === null) {
            if ($required) {
                throw new RuntimeException("Required raw care-pathway relation {$relation} does not exist.");
            }

            return [];
        }

        return DB::table($relation)
            ->get()
            ->map(fn (stdClass $row): array => (array) $row)
            ->all();
    }

    /** @param list<array<string, mixed>> $rows */
    private function manifestForRelease(array $rows, int $releaseId): array
    {
        foreach ($rows as $row) {
            $candidate = $this->firstValue($row, ['verification_release_id', 'release_id', 'id']);
            if ($candidate !== null && (int) $candidate === $releaseId) {
                return $row;
            }
        }

        if (count($rows) === 1 && $releaseId === 1) {
            return $rows[0];
        }

        throw new RuntimeException("Raw verification release {$releaseId} does not exist in the manifest.");
    }

    private function assertManifestIdentity(array $manifest, array $release, int $rawReleaseId): void
    {
        $checks = [
            ['dataset_key', ['dataset_key']],
            ['source_csv_sha256', ['source_csv_sha256', 'csv_sha256', 'baseline_source_sha256']],
            ['verification_workbook_sha256', ['verification_workbook_sha256', 'workbook_sha256', 'source_sha256']],
        ];

        foreach ($checks as [$expectedKey, $candidates]) {
            $actual = $this->firstValue($manifest, $candidates);
            $wanted = $release[$expectedKey] ?? null;
            if ($actual !== null && $wanted !== null && (string) $actual !== (string) $wanted) {
                throw new RuntimeException("Raw verification release {$rawReleaseId} failed manifest control {$expectedKey}.");
            }
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function assertCount(string $key, array $rows, array $expected, ?string $label = null): void
    {
        $this->assertControl($key, count($rows), $expected, $label);
    }

    private function assertControl(string $key, int $actual, array $expected, ?string $label = null): void
    {
        $wanted = (int) ($expected[$key] ?? -1);
        if ($actual !== $wanted) {
            throw new RuntimeException(sprintf('%s control mismatch: expected %d, found %d.', $label ?? $key, $wanted, $actual));
        }
    }

    private function assertFloatControl(string $key, float $actual, array $expected): void
    {
        $wanted = (float) ($expected[$key] ?? -1);
        if (abs($actual - $wanted) > 0.0005) {
            throw new RuntimeException(sprintf('%s control mismatch: expected %.3f, found %.3f.', $key, $wanted, $actual));
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function keyByUniqueInteger(array $rows, string $field, string $label): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            $key = (int) ($row[$field] ?? 0);
            if ($key <= 0 || isset($keyed[$key])) {
                throw new RuntimeException("{$label} contains a missing or duplicate {$field}.");
            }
            $keyed[$key] = $row;
        }
        ksort($keyed);

        if (array_keys($keyed) !== range(1, count($keyed))) {
            throw new RuntimeException("{$label} ranks must be unique and sequential from 1.");
        }

        return $keyed;
    }

    /** @param list<array<string, mixed>> $rows */
    private function countValue(array $rows, string $field, string $expected): int
    {
        return count(array_filter($rows, fn (array $row): bool => $this->string($row, $field) === $expected));
    }

    /** @return list<string> */
    private function drgList(string $value): array
    {
        $values = preg_split('/\s*,\s*/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_map(fn (string $drg): string => $this->drg($drg), $values)));
    }

    private function drg(string $value): string
    {
        $drg = str_pad(trim($value), 3, '0', STR_PAD_LEFT);
        if (! preg_match('/^[0-9]{3}$/', $drg)) {
            throw new RuntimeException("Invalid MS-DRG code {$value}.");
        }

        return $drg;
    }

    /** @return list<string> */
    private function pmidList(string $value): array
    {
        $values = preg_split('/\s*[,;|]\s*/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($values as $pmid) {
            if (! preg_match('/^[0-9]+$/', $pmid)) {
                throw new RuntimeException("Invalid evidence PMID {$pmid}.");
            }
        }

        return array_values(array_unique($values));
    }

    /** @param list<array<string, mixed>> $rows */
    private function assertNoResidualUnclassifiedAbsence(array $rows, int $expected): void
    {
        $residual = 0;
        foreach ($rows as $row) {
            foreach ($row as $field => $value) {
                if ((str_contains(strtolower((string) $field), 'residual_unclassified')
                    || str_contains(strtolower((string) $field), 'residual_unknown'))
                    && is_numeric($value)) {
                    $residual += (int) $value;
                }
            }

            $classification = strtolower((string) $this->firstValue($row, ['classification', 'resolution_type', 'status']));
            if (in_array($classification, ['unclassified', 'unresolved_required'], true)) {
                $residual++;
            }
        }

        if ($residual !== $expected) {
            throw new RuntimeException("Residual unclassified absence control mismatch: expected {$expected}, found {$residual}.");
        }
    }

    private function string(array $row, string $field): string
    {
        return trim((string) ($row[$field] ?? ''));
    }

    private function firstValue(array $row, array $fields): mixed
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
                return $row[$field];
            }
        }

        return null;
    }
}
