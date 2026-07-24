<?php

namespace Tests\Feature\CarePathways;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Two active versions of one clinical definition must never cover the same
 * effective period. If they did, a serving read could not decide which approved
 * guidance applies on a given date. Because a definition may hold at most one
 * version per catalog release, overlapping active versions can only appear
 * across releases — exactly the ambiguity this database trigger prevents.
 *
 * As in the constraint suite, each rejection is the final database operation of
 * its test so a poisoned transaction never masks the guard under test.
 */
class CarePathwayActiveVersionPeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_active_versions_of_one_definition_cannot_overlap_in_effective_period(): void
    {
        $definition = $this->insertDefinition('overlap-def');
        $releaseA = $this->insertRelease('overlap-release-a');
        $releaseB = $this->insertRelease('overlap-release-b');

        DB::statement($this->versionSql($definition, $releaseA, 1, '2026-04-01', '2026-06-30', 'active', 'approved'));

        $this->assertRejectedByDatabase(
            $this->versionSql($definition, $releaseB, 1, '2026-06-01', '2026-09-30', 'active', 'approved'),
        );
    }

    public function test_adjacent_non_overlapping_active_versions_of_one_definition_are_allowed(): void
    {
        $definition = $this->insertDefinition('adjacent-def');
        $releaseA = $this->insertRelease('adjacent-release-a');
        $releaseB = $this->insertRelease('adjacent-release-b');

        DB::statement($this->versionSql($definition, $releaseA, 1, '2026-04-01', '2026-06-30', 'active', 'approved'));
        DB::statement($this->versionSql($definition, $releaseB, 1, '2026-07-01', '2026-09-30', 'active', 'approved'));

        $this->assertSame(2, $this->activeVersionCount($definition));
    }

    public function test_active_versions_of_different_definitions_may_overlap(): void
    {
        $definitionA = $this->insertDefinition('scope-def-a');
        $definitionB = $this->insertDefinition('scope-def-b');
        $release = $this->insertRelease('scope-release');

        DB::statement($this->versionSql($definitionA, $release, 1, '2026-04-01', '2026-09-30', 'active', 'approved'));
        DB::statement($this->versionSql($definitionB, $release, 2, '2026-04-01', '2026-09-30', 'active', 'approved'));

        $this->assertSame(1, $this->activeVersionCount($definitionA));
        $this->assertSame(1, $this->activeVersionCount($definitionB));
    }

    public function test_an_inactive_version_may_overlap_an_active_version_of_the_same_definition(): void
    {
        $definition = $this->insertDefinition('inactive-overlap-def');
        $releaseA = $this->insertRelease('inactive-overlap-release-a');
        $releaseB = $this->insertRelease('inactive-overlap-release-b');

        DB::statement($this->versionSql($definition, $releaseA, 1, '2026-04-01', '2026-09-30', 'active', 'approved'));
        DB::statement($this->versionSql($definition, $releaseB, 1, '2026-04-01', '2026-09-30', 'inactive', 'not_reviewed'));

        $this->assertSame(1, $this->activeVersionCount($definition));
    }

    public function test_two_open_ended_active_versions_of_one_definition_cannot_coexist(): void
    {
        // NULL bounds are open-ended; two open-ended active versions always overlap.
        $definition = $this->insertDefinition('open-ended-def');
        $releaseA = $this->insertRelease('open-ended-release-a');
        $releaseB = $this->insertRelease('open-ended-release-b');

        DB::statement($this->versionSql($definition, $releaseA, 1, null, null, 'active', 'approved'));

        $this->assertRejectedByDatabase(
            $this->versionSql($definition, $releaseB, 1, null, null, 'active', 'approved'),
        );
    }

    public function test_activating_a_version_into_an_overlapping_period_is_rejected(): void
    {
        $definition = $this->insertDefinition('activate-overlap-def');
        $releaseA = $this->insertRelease('activate-overlap-release-a');
        $releaseB = $this->insertRelease('activate-overlap-release-b');

        DB::statement($this->versionSql($definition, $releaseA, 1, '2026-04-01', '2026-09-30', 'active', 'approved'));
        DB::statement($this->versionSql($definition, $releaseB, 1, '2026-06-01', '2026-08-31', 'inactive', 'not_reviewed'));

        $this->assertRejectedByDatabase(
            "UPDATE care_pathways.versions SET activation_status = 'active', institutional_approval_status = 'approved' ".
            "WHERE pathway_definition_id = {$definition} AND catalog_release_id = {$releaseB}",
        );
    }

    private function activeVersionCount(int $definitionId): int
    {
        return (int) DB::selectOne(
            'SELECT count(*) AS total FROM care_pathways.versions '.
            "WHERE pathway_definition_id = {$definitionId} AND activation_status = 'active'",
        )->total;
    }

    private function assertRejectedByDatabase(string $sql): void
    {
        try {
            DB::statement($sql);
            $this->fail('Expected the statement to be rejected by the active-version overlap trigger.');
        } catch (QueryException $exception) {
            $this->addToAssertionCount(1);
        }
    }

    private function insertRelease(string $datasetKey): int
    {
        $csvHash = str_repeat('a', 64);
        $workbookHash = str_repeat('b', 64);

        $row = DB::selectOne(<<<SQL
INSERT INTO care_pathways.catalog_releases (
    catalog_release_uuid, dataset_key, source_csv_sha256, verification_workbook_sha256,
    grouper_version, pathway_count, pathway_drg_association_count, unique_drg_code_count,
    claim_count, source_count, change_count, evidence_verified_count, evidence_limitations_count,
    signoff_queue_count, specialist_review_count, redesign_count, volume_control_total,
    coverage_control_percent, adopted_by
) VALUES (
    gen_random_uuid(), '{$datasetKey}', '{$csvHash}', '{$workbookHash}',
    '43.1-test', 1, 1, 1,
    0, 0, 0, 1, 0,
    1, 0, 0, 1,
    99.0, 'period-test'
) RETURNING catalog_release_id
SQL);

        return (int) $row->catalog_release_id;
    }

    private function insertDefinition(string $key): int
    {
        $row = DB::selectOne(
            'INSERT INTO care_pathways.definitions (pathway_uuid, pathway_key, canonical_name) '.
            "VALUES (gen_random_uuid(), '{$key}', 'Active Period Test Definition') RETURNING pathway_definition_id",
        );

        return (int) $row->pathway_definition_id;
    }

    private function versionSql(
        int $definitionId,
        int $releaseId,
        int $rank,
        ?string $effectiveStart,
        ?string $effectiveEnd,
        string $activationStatus,
        string $institutionalApprovalStatus,
    ): string {
        $semanticVersion = "43.1-r{$releaseId}s{$rank}";
        $sourceDigest = hash('sha256', "version-source-{$definitionId}-{$releaseId}-{$rank}");
        $contentDigest = hash('sha256', "version-content-{$definitionId}-{$releaseId}-{$rank}");
        $effectiveStartSql = $effectiveStart === null ? 'NULL' : "'{$effectiveStart}'";
        $effectiveEndSql = $effectiveEnd === null ? 'NULL' : "'{$effectiveEnd}'";

        return <<<SQL
INSERT INTO care_pathways.versions (
    pathway_version_uuid, pathway_definition_id, catalog_release_id, semantic_version,
    source_rank, evidence_status, release_disposition, clinical_signoff_status,
    institutional_approval_status, activation_status, source_digest, content_digest,
    effective_start, effective_end, unresolved_flags, raw_snapshot
) VALUES (
    gen_random_uuid(), {$definitionId}, {$releaseId}, '{$semanticVersion}',
    {$rank}, 'evidence_verified', 'institutional_signoff_candidate', 'not_clinically_approved',
    '{$institutionalApprovalStatus}', '{$activationStatus}', '{$sourceDigest}', '{$contentDigest}',
    {$effectiveStartSql}, {$effectiveEndSql}, '[]'::jsonb, '{}'::jsonb
)
SQL;
    }
}
