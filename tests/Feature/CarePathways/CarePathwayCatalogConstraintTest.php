<?php

namespace Tests\Feature\CarePathways;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Characterizes the database-level safety guarantees of the inactive care
 * pathway catalog. Every assertion proves PostgreSQL itself — not application
 * code — rejects a malformed, ambiguous, or prematurely-approved row. These
 * guards are why a strong automated evidence signal cannot silently become
 * clinical guidance.
 *
 * Each test issues exactly one rejected statement as its final database
 * operation: a failed statement aborts the surrounding RefreshDatabase
 * transaction, so a second failing statement in the same method would raise a
 * misleading "current transaction is aborted" error instead of the guard under
 * test.
 */
class CarePathwayCatalogConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_release_rejects_a_malformed_source_csv_hash(): void
    {
        $this->assertRejectedByDatabase(
            $this->releaseInsertSql(datasetKey: 'malformed-hash-release', sourceCsvHash: 'not-a-sha-256'),
        );
    }

    public function test_release_rejects_non_object_source_controls(): void
    {
        $this->assertRejectedByDatabase(
            $this->releaseInsertSql(datasetKey: 'array-controls-release', sourceControlsSql: "'[]'::jsonb"),
        );
    }

    public function test_release_rejects_an_evidence_partition_that_does_not_total_pathway_count(): void
    {
        // evidence_verified_count + evidence_limitations_count must equal pathway_count.
        $this->assertRejectedByDatabase(
            $this->releaseInsertSql(datasetKey: 'bad-partition-release', evidenceVerifiedCount: 5),
        );
    }

    public function test_version_rejects_an_inverted_effective_period(): void
    {
        $release = $this->insertRelease('inverted-period-release');
        $definition = $this->insertDefinition('inverted-period-def');

        $this->assertRejectedByDatabase(
            $this->versionInsertSql($definition, $release, rank: 1, effectiveStart: '2026-09-30', effectiveEnd: '2026-04-01'),
        );
    }

    public function test_versions_reject_a_duplicate_release_rank(): void
    {
        $release = $this->insertRelease('duplicate-rank-release');
        $definitionA = $this->insertDefinition('duplicate-rank-def-a');
        $definitionB = $this->insertDefinition('duplicate-rank-def-b');

        DB::statement($this->versionInsertSql($definitionA, $release, rank: 7));

        $this->assertRejectedByDatabase(
            $this->versionInsertSql($definitionB, $release, rank: 7),
        );
    }

    public function test_version_rejects_non_array_unresolved_flags(): void
    {
        $release = $this->insertRelease('flags-shape-release');
        $definition = $this->insertDefinition('flags-shape-def');

        $this->assertRejectedByDatabase(
            $this->versionInsertSql($definition, $release, rank: 1, unresolvedFlagsSql: "'{}'::jsonb"),
        );
    }

    public function test_version_cannot_be_active_without_institutional_approval(): void
    {
        $release = $this->insertRelease('premature-active-release');
        $definition = $this->insertDefinition('premature-active-def');

        $this->assertRejectedByDatabase(
            $this->versionInsertSql(
                $definition,
                $release,
                rank: 1,
                activationStatus: 'active',
                institutionalApprovalStatus: 'not_reviewed',
            ),
        );
    }

    public function test_codebook_rejects_a_non_numeric_drg_code(): void
    {
        $release = $this->insertRelease('invalid-drg-release');
        $digest = hash('sha256', 'invalid-drg');

        $this->assertRejectedByDatabase(
            'INSERT INTO care_pathways.drg_codebook_entries (entry_uuid, catalog_release_id, ms_drg, title, entry_digest) '.
            "VALUES (gen_random_uuid(), {$release}, 'A12', 'Invalid DRG', '{$digest}')",
        );
    }

    public function test_codebook_rejects_a_duplicate_drg_within_a_release(): void
    {
        $release = $this->insertRelease('duplicate-drg-release');
        $digest = hash('sha256', 'duplicate-drg');

        DB::statement(
            'INSERT INTO care_pathways.drg_codebook_entries (entry_uuid, catalog_release_id, ms_drg, title, entry_digest) '.
            "VALUES (gen_random_uuid(), {$release}, '123', 'DRG 123', '{$digest}')",
        );

        $this->assertRejectedByDatabase(
            'INSERT INTO care_pathways.drg_codebook_entries (entry_uuid, catalog_release_id, ms_drg, title, entry_digest) '.
            "VALUES (gen_random_uuid(), {$release}, '123', 'DRG 123 duplicate', '{$digest}')",
        );
    }

    public function test_section_cannot_be_marked_approved_without_approved_text_and_digest(): void
    {
        $release = $this->insertRelease('section-approval-release');
        $definition = $this->insertDefinition('section-approval-def');
        $version = $this->insertVersion($definition, $release, 1);
        $digest = hash('sha256', 'section-source');

        // content_mode=approved requires review_state=approved AND approved_text AND approved_digest.
        $this->assertRejectedByDatabase(
            'INSERT INTO care_pathways.sections (section_uuid, pathway_version_id, section_code, source_text, content_mode, review_state, source_digest) '.
            "VALUES (gen_random_uuid(), {$version}, 'overview', 'Source-only staff reference text', 'approved', 'approved', '{$digest}')",
        );
    }

    public function test_release_source_membership_rejects_a_mismatched_source_digest(): void
    {
        $release = $this->insertRelease('digest-mismatch-release');
        $canonicalDigest = hash('sha256', 'canonical-source');
        $source = $this->insertSource($canonicalDigest);
        $tamperedDigest = hash('sha256', 'tampered-source');

        // The 001600 membership trigger requires the membership digest to equal
        // the immutable source content digest.
        $this->assertRejectedByDatabase(
            'INSERT INTO care_pathways.catalog_release_sources (release_source_uuid, catalog_release_id, source_id, source_content_digest) '.
            "VALUES (gen_random_uuid(), {$release}, {$source}, '{$tamperedDigest}')",
        );
    }

    private function assertRejectedByDatabase(string $sql): void
    {
        try {
            DB::statement($sql);
            $this->fail('Expected the statement to be rejected by a database constraint or trigger.');
        } catch (QueryException $exception) {
            $this->addToAssertionCount(1);
        }
    }

    private function insertRelease(string $datasetKey): int
    {
        $row = DB::selectOne($this->releaseInsertSql($datasetKey).' RETURNING catalog_release_id');

        return (int) $row->catalog_release_id;
    }

    private function insertDefinition(string $key): int
    {
        $row = DB::selectOne(
            'INSERT INTO care_pathways.definitions (pathway_uuid, pathway_key, canonical_name) '.
            "VALUES (gen_random_uuid(), '{$key}', 'Constraint Test Definition') RETURNING pathway_definition_id",
        );

        return (int) $row->pathway_definition_id;
    }

    private function insertSource(string $contentDigest): int
    {
        $row = DB::selectOne(
            'INSERT INTO care_pathways.sources (source_uuid, content_digest) '.
            "VALUES (gen_random_uuid(), '{$contentDigest}') RETURNING source_id",
        );

        return (int) $row->source_id;
    }

    private function insertVersion(int $definitionId, int $releaseId, int $rank): int
    {
        $row = DB::selectOne($this->versionInsertSql($definitionId, $releaseId, $rank).' RETURNING pathway_version_id');

        return (int) $row->pathway_version_id;
    }

    private function releaseInsertSql(
        string $datasetKey,
        ?string $sourceCsvHash = null,
        string $sourceControlsSql = "'{}'::jsonb",
        int $evidenceVerifiedCount = 1,
    ): string {
        $sourceCsvHash ??= str_repeat('a', 64);
        $workbookHash = str_repeat('b', 64);

        return <<<SQL
INSERT INTO care_pathways.catalog_releases (
    catalog_release_uuid, dataset_key, source_csv_sha256, verification_workbook_sha256,
    grouper_version, pathway_count, pathway_drg_association_count, unique_drg_code_count,
    claim_count, source_count, change_count, evidence_verified_count, evidence_limitations_count,
    signoff_queue_count, specialist_review_count, redesign_count, volume_control_total,
    coverage_control_percent, adopted_by, source_controls
) VALUES (
    gen_random_uuid(), '{$datasetKey}', '{$sourceCsvHash}', '{$workbookHash}',
    '43.1-test', 1, 1, 1,
    0, 0, 0, {$evidenceVerifiedCount}, 0,
    1, 0, 0, 1,
    99.0, 'constraint-test', {$sourceControlsSql}
)
SQL;
    }

    private function versionInsertSql(
        int $definitionId,
        int $releaseId,
        int $rank,
        ?string $effectiveStart = null,
        ?string $effectiveEnd = null,
        string $unresolvedFlagsSql = "'[]'::jsonb",
        string $activationStatus = 'inactive',
        string $institutionalApprovalStatus = 'not_reviewed',
    ): string {
        $sourceDigest = hash('sha256', "version-source-{$definitionId}-{$rank}");
        $contentDigest = hash('sha256', "version-content-{$definitionId}-{$rank}");
        $effectiveStartSql = $effectiveStart === null ? 'NULL' : "'{$effectiveStart}'";
        $effectiveEndSql = $effectiveEnd === null ? 'NULL' : "'{$effectiveEnd}'";

        return <<<SQL
INSERT INTO care_pathways.versions (
    pathway_version_uuid, pathway_definition_id, catalog_release_id, semantic_version,
    source_rank, evidence_status, release_disposition, clinical_signoff_status,
    institutional_approval_status, activation_status, source_digest, content_digest,
    effective_start, effective_end, unresolved_flags, raw_snapshot
) VALUES (
    gen_random_uuid(), {$definitionId}, {$releaseId}, '43.1-test-source.{$rank}',
    {$rank}, 'evidence_verified', 'institutional_signoff_candidate', 'not_clinically_approved',
    '{$institutionalApprovalStatus}', '{$activationStatus}', '{$sourceDigest}', '{$contentDigest}',
    {$effectiveStartSql}, {$effectiveEndSql}, {$unresolvedFlagsSql}, '{}'::jsonb
)
SQL;
    }
}
