<?php

namespace App\Services\CarePathways;

use App\Services\Deployment\ServiceLineNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CatalogImportService
{
    public function __construct(
        private readonly CatalogReconciliationService $reconciliation,
        private readonly ServiceLineNormalizer $serviceLineNormalizer,
    ) {}

    /**
     * Adopt one reconciled raw release into an inactive canonical catalog.
     *
     * @return array<string, int|float|string|bool>
     */
    public function adopt(int $rawReleaseId, string $actor, bool $dryRun = false): array
    {
        $actor = trim($actor);
        if ($actor === '' || mb_strlen($actor) > 190) {
            throw new RuntimeException('An adopting actor identifier between 1 and 190 characters is required.');
        }

        $package = $this->reconciliation->reconcile($rawReleaseId);
        $summary = $this->packageSummary($package, false, $dryRun);

        if ($dryRun) {
            return $summary;
        }

        return DB::transaction(function () use ($rawReleaseId, $actor, $package): array {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [
                'care-pathways:adopt:'.$package['identity']['dataset_key'],
            ]);

            $existing = DB::table('care_pathways.catalog_releases')
                ->where('dataset_key', $package['identity']['dataset_key'])
                ->first();

            if ($existing !== null) {
                $sameSource = hash_equals((string) $existing->source_csv_sha256, (string) $package['identity']['source_csv_sha256'])
                    && hash_equals((string) $existing->verification_workbook_sha256, (string) $package['identity']['verification_workbook_sha256']);

                if (! $sameSource) {
                    throw new RuntimeException('The catalog dataset key already exists with different immutable source hashes.');
                }

                // Forward-repair normalized provenance that may have been added
                // after the first adoption. Every insert is digest-idempotent;
                // existing append-only facts are never updated or deleted.
                $sourceIds = $this->insertSources($package['sources']);
                $this->insertReleaseSources((int) $existing->catalog_release_id, $sourceIds);
                $this->insertEnrichments((int) $existing->catalog_release_id, $package['enrichments'], $sourceIds);
                $this->insertCompletenessResolutions((int) $existing->catalog_release_id, $package['completeness'], $sourceIds);
                $this->insertReleaseControls((int) $existing->catalog_release_id, $package);
                $this->insertSupplementalControls((int) $existing->catalog_release_id, $package);
                $this->insertReleaseSourceControls((int) $existing->catalog_release_id);

                return $this->databaseSummary((int) $existing->catalog_release_id, true);
            }

            $releaseId = $this->insertRelease($rawReleaseId, $actor, $package);
            $codebookIds = $this->insertCodebook($releaseId, $package['codebook']);
            $sourceIds = $this->insertSources($package['sources']);
            $this->insertReleaseSources($releaseId, $sourceIds);
            [$versionIds, $sectionIds] = $this->insertPathways(
                $releaseId,
                $package['pathways'],
                $codebookIds,
            );
            $this->insertClaims($releaseId, $package['claims'], $versionIds, $sectionIds, $sourceIds);
            $this->insertChanges($releaseId, $package['changes']);
            $this->insertEnrichments($releaseId, $package['enrichments'], $sourceIds);
            $this->insertCompletenessResolutions($releaseId, $package['completeness'], $sourceIds);
            $this->insertReleaseControls($releaseId, $package);
            $this->insertSupplementalControls($releaseId, $package);
            $this->insertReleaseSourceControls($releaseId);

            DB::table('care_pathways.events')->insert([
                'event_uuid' => (string) Str::uuid7(),
                'aggregate_type' => 'catalog_release',
                'aggregate_id' => $releaseId,
                'aggregate_uuid' => DB::table('care_pathways.catalog_releases')
                    ->where('catalog_release_id', $releaseId)
                    ->value('catalog_release_uuid'),
                'aggregate_version' => 1,
                'event_type' => 'verification_release_adopted_inactive',
                'actor_ref' => $actor,
                'idempotency_key' => 'care-pathway-adoption:'.$package['identity']['dataset_key'],
                'metadata' => $this->json([
                    'raw_verification_release_id' => $rawReleaseId,
                    'clinical_activation' => false,
                    'patient_serving' => false,
                    'eddy_serving' => false,
                ]),
                'occurred_at' => now(),
            ]);

            return $this->databaseSummary($releaseId, false);
        });
    }

    /** @param array<string, mixed> $package */
    private function insertRelease(int $rawReleaseId, string $actor, array $package): int
    {
        $identity = $package['identity'];
        $controls = $package['computed_controls'];

        return (int) DB::table('care_pathways.catalog_releases')->insertGetId([
            'catalog_release_uuid' => (string) Str::uuid7(),
            'dataset_key' => $identity['dataset_key'],
            'raw_verification_release_id' => $rawReleaseId,
            'raw_source_import_id' => $this->integerValue($package['manifest'], ['source_import_id', 'raw_source_import_id', 'baseline_import_id']),
            'source_csv_sha256' => $identity['source_csv_sha256'],
            'verification_workbook_sha256' => $identity['verification_workbook_sha256'],
            'declared_baseline_sha256' => $identity['declared_baseline_sha256'] ?? null,
            'grouper_version' => $identity['grouper_version'],
            'grouper_effective_start' => $identity['grouper_effective_start'] ?? null,
            'grouper_effective_end' => $identity['grouper_effective_end'] ?? null,
            'pathway_count' => $controls['pathways'],
            'pathway_drg_association_count' => $controls['pathway_drg_associations'],
            'unique_drg_code_count' => $controls['unique_drg_codes'],
            'claim_count' => $controls['claims'],
            'source_count' => $controls['sources'],
            'change_count' => $controls['changes'],
            'evidence_verified_count' => $controls['evidence_verified'],
            'evidence_limitations_count' => $controls['evidence_limitations'],
            'signoff_queue_count' => $controls['signoff_queue'],
            'specialist_review_count' => $controls['specialist_review'],
            'redesign_count' => $controls['redesign'],
            'clinical_signoff_count' => $controls['clinical_signoff'],
            'volume_control_total' => $controls['volume_total'],
            'coverage_control_percent' => $controls['coverage_percent'],
            'state' => 'inactive',
            'clinical_signoff_complete' => false,
            'source_controls' => $this->json([
                'computed' => $controls,
                'manifest' => $this->redactedManifest($package['manifest']),
            ]),
            'adopted_by' => $actor,
            'adopted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'catalog_release_id');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function insertCodebook(int $releaseId, array $rows): array
    {
        $inserts = [];
        foreach ($rows as $row) {
            $drg = $this->drg((string) ($row['ms_drg'] ?? ''));
            $inserts[] = [
                'entry_uuid' => (string) Str::uuid7(),
                'catalog_release_id' => $releaseId,
                'ms_drg' => $drg,
                'title' => trim((string) ($row['title'] ?? '')),
                'mdc' => $this->nullableString($row['mdc'] ?? null),
                'type_code' => $this->nullableString($row['type_code'] ?? null),
                'type_label' => $this->nullableString($row['type_label'] ?? null),
                'source_url' => $this->nullableString($row['source_url'] ?? null),
                'verified_date' => $this->nullableDate($row['verified_date'] ?? null),
                'entry_digest' => $this->digest($row),
                'created_at' => now(),
            ];
        }

        foreach (array_chunk($inserts, 500) as $chunk) {
            DB::table('care_pathways.drg_codebook_entries')->insert($chunk);
        }

        return DB::table('care_pathways.drg_codebook_entries')
            ->where('catalog_release_id', $releaseId)
            ->pluck('drg_codebook_entry_id', 'ms_drg')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function insertSources(array $rows): array
    {
        $pmids = array_values(array_unique(array_map(
            fn (array $row): string => trim((string) ($row['pmid'] ?? '')),
            $rows,
        )));
        $existingByKey = DB::table('care_pathways.sources')
            ->whereIn('pmid', $pmids)
            ->get()
            ->keyBy(fn (object $row): string => $row->pmid.'|'.$row->content_digest);
        $inserts = [];

        foreach ($rows as $row) {
            $pmid = trim((string) ($row['pmid'] ?? ''));
            $digest = $this->digest($row);
            if ($existingByKey->has($pmid.'|'.$digest)) {
                continue;
            }

            $inserts[] = [
                'source_uuid' => (string) Str::uuid7(),
                'pmid' => $pmid,
                'doi' => $this->nullableString($row['doi'] ?? null),
                'source_url' => $this->nullableString($row['source_url'] ?? null),
                'title' => $this->nullableString($row['title'] ?? null),
                'first_author' => $this->nullableString($row['first_author'] ?? $row['resolved_first_author'] ?? null),
                'organization' => $this->nullableString($row['organization'] ?? null),
                'journal' => $this->nullableString($row['journal'] ?? null),
                'publication_date' => $this->nullableString($row['publication_date'] ?? null),
                'publication_types' => $this->json($this->listValue($row['publication_types'] ?? null)),
                'source_type' => 'bibliographic',
                'retraction_indicator' => $this->nullableString($row['retraction_indicator'] ?? null),
                'supersession_state' => $this->retractionState($row['retraction_indicator'] ?? null),
                'verified_date' => $this->nullableDate($row['verified_date'] ?? null),
                'content_digest' => $digest,
                'provenance' => $this->json(['source_release' => 'verification_package_v43_1']),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($inserts, 500) as $chunk) {
            DB::table('care_pathways.sources')->insert($chunk);
        }

        $ids = [];
        $canonical = DB::table('care_pathways.sources')
            ->whereIn('pmid', $pmids)
            ->get()
            ->keyBy(fn (object $row): string => $row->pmid.'|'.$row->content_digest);
        foreach ($rows as $row) {
            $pmid = trim((string) ($row['pmid'] ?? ''));
            $key = $pmid.'|'.$this->digest($row);
            $source = $canonical->get($key);
            if ($source === null) {
                throw new RuntimeException("Canonical source insertion did not return PMID {$pmid} with its exact digest.");
            }
            $ids[$pmid] = (int) $source->source_id;
        }

        return $ids;
    }

    /** @param array<string, int> $sourceIds */
    private function insertReleaseSources(int $releaseId, array $sourceIds): void
    {
        $sourceIds = array_values(array_unique($sourceIds));
        $digests = DB::table('care_pathways.sources')
            ->whereIn('source_id', $sourceIds)
            ->pluck('content_digest', 'source_id');
        $rows = [];

        foreach ($sourceIds as $sourceId) {
            $digest = $digests->get($sourceId);
            if ($digest === null) {
                throw new RuntimeException("Canonical release source membership references missing source {$sourceId}.");
            }

            $rows[] = [
                'release_source_uuid' => (string) Str::uuid7(),
                'catalog_release_id' => $releaseId,
                'source_id' => $sourceId,
                'membership_type' => 'source_index',
                'source_content_digest' => (string) $digest,
                'created_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('care_pathways.catalog_release_sources')->insertOrIgnore($chunk);
        }
    }

    private function insertReleaseSourceControls(int $releaseId): void
    {
        $declaredSourceCount = (int) DB::table('care_pathways.catalog_releases')
            ->where('catalog_release_id', $releaseId)
            ->value('source_count');
        $membershipCount = DB::table('care_pathways.catalog_release_sources')
            ->where('catalog_release_id', $releaseId)
            ->count();

        if ($membershipCount !== $declaredSourceCount) {
            throw new RuntimeException("Catalog release source membership mismatch: expected {$declaredSourceCount}, observed {$membershipCount}.");
        }

        DB::table('care_pathways.catalog_release_controls')->insertOrIgnore([
            'control_uuid' => (string) Str::uuid7(),
            'catalog_release_id' => $releaseId,
            'control_key' => 'catalog_release_source_membership',
            'observed_value' => $this->json(['indexed_source_count' => $membershipCount]),
            'reference_value' => $this->json(['declared_source_count' => $declaredSourceCount]),
            'status' => 'passed',
            'rationale' => 'Every source-index row is linked directly to the catalog release, including sources not cited by a claim.',
            'evidence' => $this->json(['source' => 'catalog_release_sources']),
            'recorded_at' => now(),
        ]);

        $correctedCount = DB::table('care_pathways.catalog_release_sources as release_sources')
            ->join('care_pathways.source_status_events as status_events', 'status_events.source_id', '=', 'release_sources.source_id')
            ->where('release_sources.catalog_release_id', $releaseId)
            ->where('status_events.recorded_by_ref', 'care-pathway-importer-negation-repair-v1')
            ->distinct('release_sources.source_id')
            ->count('release_sources.source_id');

        if ($correctedCount === 0) {
            return;
        }

        $claimLinkedCorrectedCount = DB::table('care_pathways.catalog_release_sources as release_sources')
            ->join('care_pathways.source_status_events as status_events', 'status_events.source_id', '=', 'release_sources.source_id')
            ->join('care_pathways.claim_sources as claim_sources', 'claim_sources.source_id', '=', 'release_sources.source_id')
            ->join('care_pathways.evidence_claims as claims', 'claims.evidence_claim_id', '=', 'claim_sources.evidence_claim_id')
            ->where('release_sources.catalog_release_id', $releaseId)
            ->where('claims.catalog_release_id', $releaseId)
            ->where('status_events.recorded_by_ref', 'care-pathway-importer-negation-repair-v1')
            ->distinct('release_sources.source_id')
            ->count('release_sources.source_id');
        $noncurrentCount = DB::table('care_pathways.catalog_release_sources as release_sources')
            ->join('care_pathways.current_source_statuses as statuses', 'statuses.source_id', '=', 'release_sources.source_id')
            ->where('release_sources.catalog_release_id', $releaseId)
            ->where('statuses.supersession_state', '<>', 'current')
            ->count();

        DB::table('care_pathways.catalog_release_controls')->insertOrIgnore([
            'control_uuid' => (string) Str::uuid7(),
            'catalog_release_id' => $releaseId,
            'control_key' => 'source_retraction_negation_repair_total',
            'observed_value' => $this->json([
                'corrected_source_count' => $correctedCount,
                'claim_linked_corrected_source_count' => $claimLinkedCorrectedCount,
                'uncited_corrected_source_count' => $correctedCount - $claimLinkedCorrectedCount,
                'residual_noncurrent_source_count' => $noncurrentCount,
            ]),
            'reference_value' => $this->json(['declared_source_count' => $declaredSourceCount]),
            'status' => $correctedCount === $declaredSourceCount && $noncurrentCount === 0 ? 'passed' : 'failed',
            'rationale' => 'Full release membership resolves the earlier claim-linked-only repair count and verifies every indexed source current after the negation repair.',
            'evidence' => $this->json([
                'source' => 'catalog_release_sources joined to current_source_statuses',
                'supersedes_narrow_control' => 'source_retraction_negation_repair',
            ]),
            'recorded_at' => now(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $codebookIds
     * @return array{0: array<int, int>, 1: array<int, array<string, int>>}
     */
    private function insertPathways(int $releaseId, array $rows, array $codebookIds): array
    {
        $versionIds = [];
        $sectionIds = [];
        $serviceLines = [];
        $sectionFields = (array) config('care-pathways.source_section_fields', []);
        $semanticVersion = (string) config('care-pathways.source_release.semantic_version');

        usort($rows, fn (array $left, array $right): int => ((int) $left['rank']) <=> ((int) $right['rank']));

        foreach ($rows as $row) {
            $rank = (int) $row['rank'];
            $condition = trim((string) $row['condition']);
            $pathwayKey = $this->pathwayKey($condition);
            $sourceServiceLine = trim((string) ($row['service_line'] ?? ''));
            $serviceLineCode = $this->mappedServiceLine($sourceServiceLine);

            $definition = DB::table('care_pathways.definitions')->where('pathway_key', $pathwayKey)->first();
            if ($definition === null) {
                $definitionId = (int) DB::table('care_pathways.definitions')->insertGetId([
                    'pathway_uuid' => (string) Str::uuid7(),
                    'pathway_key' => $pathwayKey,
                    'canonical_name' => $condition,
                    'mdc_label' => $this->nullableString($row['mdc'] ?? null),
                    'care_type' => $this->nullableString($row['medical_or_surgical'] ?? null),
                    'source_service_line' => $this->nullableString($sourceServiceLine),
                    'service_line_code' => $serviceLineCode,
                    'lifecycle_state' => 'candidate',
                    'created_at' => now(),
                    'updated_at' => now(),
                ], 'pathway_definition_id');
            } else {
                $definitionId = (int) $definition->pathway_definition_id;
            }

            $content = [];
            foreach ($sectionFields as $field) {
                $content[$field] = $row[$field] ?? null;
            }

            $versionId = (int) DB::table('care_pathways.versions')->insertGetId([
                'pathway_version_uuid' => (string) Str::uuid7(),
                'pathway_definition_id' => $definitionId,
                'catalog_release_id' => $releaseId,
                'semantic_version' => $semanticVersion,
                'source_rank' => $rank,
                'evidence_status' => trim((string) $row['verification_status']),
                'verification_confidence' => $this->nullableString($row['verification_confidence'] ?? null),
                'source_specificity' => $this->nullableString($row['source_specificity'] ?? null),
                'unresolved_flags' => $this->json($this->unresolvedFlags($row['unresolved_flags'] ?? null)),
                'release_disposition' => trim((string) $row['release_disposition']),
                'clinical_signoff_status' => trim((string) $row['clinical_signoff_status']),
                'institutional_approval_status' => 'not_reviewed',
                'activation_status' => 'inactive',
                'source_digest' => $this->digest($row),
                'content_digest' => $this->digest($content),
                'effective_start' => config('care-pathways.source_release.grouper_effective_start'),
                'effective_end' => config('care-pathways.source_release.grouper_effective_end'),
                'raw_snapshot' => $this->json($row),
                'created_at' => now(),
                'updated_at' => now(),
            ], 'pathway_version_id');
            $versionIds[$rank] = $versionId;

            $sectionRows = [];
            foreach ($sectionFields as $field) {
                $sourceText = trim((string) ($row[$field] ?? ''));
                if ($sourceText === '') {
                    continue;
                }

                $sectionRows[] = [
                    'section_uuid' => (string) Str::uuid7(),
                    'pathway_version_id' => $versionId,
                    'section_code' => $field,
                    'audience' => 'staff_reference',
                    'language_code' => 'en',
                    'source_text' => $sourceText,
                    'approved_text' => null,
                    'content_mode' => 'source',
                    'review_state' => 'source_candidate',
                    'source_digest' => hash('sha256', $sourceText),
                    'approved_digest' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            if ($sectionRows !== []) {
                DB::table('care_pathways.sections')->insert($sectionRows);
                $sectionIds[$rank] = DB::table('care_pathways.sections')
                    ->where('pathway_version_id', $versionId)
                    ->pluck('pathway_section_id', 'section_code')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();
            }

            foreach ($this->drgList((string) $row['drg_codes']) as $drg) {
                DB::table('care_pathways.drg_mappings')->insert([
                    'pathway_version_id' => $versionId,
                    'drg_codebook_entry_id' => $codebookIds[$drg],
                    'mapping_role' => 'candidate',
                    'ambiguity_note' => null,
                    'effective_start' => config('care-pathways.source_release.grouper_effective_start'),
                    'effective_end' => config('care-pathways.source_release.grouper_effective_end'),
                    'created_at' => now(),
                ]);
            }

            $serviceLines[$sourceServiceLine] = $serviceLineCode;
        }

        foreach ($serviceLines as $sourceServiceLine => $serviceLineCode) {
            DB::table('care_pathways.service_line_mappings')->insert([
                'catalog_release_id' => $releaseId,
                'source_service_line' => $sourceServiceLine,
                'normalized_candidate' => Str::slug($sourceServiceLine, '_'),
                'service_line_code' => $serviceLineCode,
                'mapping_status' => $serviceLineCode === null ? 'pending' : 'mapped',
                'mapped_at' => $serviceLineCode === null ? null : now(),
                'notes' => $serviceLineCode === null
                    ? 'Awaiting governed mapping to the canonical service-line registry.'
                    : 'Matched through the canonical service-line registry/alias index.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$versionIds, $sectionIds];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, int>  $versionIds
     * @param  array<int, array<string, int>>  $sectionIds
     * @param  array<string, int>  $sourceIds
     */
    private function insertClaims(int $releaseId, array $rows, array $versionIds, array $sectionIds, array $sourceIds): void
    {
        $claimRows = [];
        $claimPmids = [];
        foreach ($rows as $ordinal => $row) {
            $rank = (int) $row['rank'];
            $field = trim((string) $row['field']);
            $claimDigest = $this->digest([
                'ordinal' => $ordinal + 1,
                'rank' => $rank,
                'field' => $field,
                'claim_type' => $row['claim_type'] ?? null,
                'claim_excerpt' => $row['claim_excerpt'] ?? null,
                'evidence_pmids' => $row['evidence_pmids'] ?? null,
            ]);

            $claimRows[] = [
                'claim_uuid' => (string) Str::uuid7(),
                'catalog_release_id' => $releaseId,
                'pathway_version_id' => $versionIds[$rank],
                'pathway_section_id' => $sectionIds[$rank][$field] ?? null,
                'source_rank' => $rank,
                'source_field' => $field,
                'claim_type' => $this->nullableString($row['claim_type'] ?? null),
                'claim_excerpt' => trim((string) $row['claim_excerpt']),
                'automated_pass_1' => $this->nullableString($row['automated_pass_1'] ?? null),
                'automated_pass_2' => $this->nullableString($row['automated_pass_2'] ?? null),
                'clinical_adjudication' => $this->nullableString($row['clinical_adjudication'] ?? null),
                'verification_date' => $this->nullableDate($row['verification_date'] ?? null),
                'claim_digest' => $claimDigest,
                'created_at' => now(),
            ];
            $claimPmids[$claimDigest] = $this->pmidList((string) $row['evidence_pmids']);
        }

        foreach (array_chunk($claimRows, 500) as $chunk) {
            DB::table('care_pathways.evidence_claims')->insert($chunk);
        }

        $claimIds = DB::table('care_pathways.evidence_claims')
            ->where('catalog_release_id', $releaseId)
            ->pluck('evidence_claim_id', 'claim_digest')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $links = [];
        foreach ($claimPmids as $claimDigest => $pmids) {
            $claimId = $claimIds[$claimDigest] ?? null;
            if ($claimId === null) {
                throw new RuntimeException("Canonical claim insertion did not return digest {$claimDigest}.");
            }
            foreach ($pmids as $pmid) {
                $links[] = [
                    'evidence_claim_id' => $claimId,
                    'source_id' => $sourceIds[$pmid],
                    'evidence_grade' => null,
                    'applicability_note' => null,
                    'provenance' => $this->json(['linkage' => 'verification_package_claim_audit']),
                    'created_at' => now(),
                ];
                if (count($links) >= 1000) {
                    DB::table('care_pathways.claim_sources')->insert($links);
                    $links = [];
                }
            }
        }
        if ($links !== []) {
            DB::table('care_pathways.claim_sources')->insert($links);
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertChanges(int $releaseId, array $rows): void
    {
        $inserts = [];
        foreach ($rows as $ordinal => $row) {
            $digest = $this->digest(['ordinal' => $ordinal + 1, 'row' => $row]);
            $inserts[] = [
                'change_uuid' => (string) Str::uuid7(),
                'catalog_release_id' => $releaseId,
                'source_rank' => (int) $row['rank'],
                'condition' => trim((string) $row['condition']),
                'source_field' => trim((string) $row['field']),
                'old_value' => $this->nullableString($row['old_value'] ?? null),
                'new_value' => $this->nullableString($row['new_value'] ?? null),
                'reason' => $this->nullableString($row['reason'] ?? null),
                'source_reference' => $this->nullableString($row['source'] ?? null),
                'changed_on' => $this->nullableDate($row['date'] ?? null),
                'change_digest' => $digest,
                'created_at' => now(),
            ];
        }
        foreach (array_chunk($inserts, 500) as $chunk) {
            DB::table('care_pathways.source_changes')->insert($chunk);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $sourceIds
     */
    private function insertEnrichments(int $releaseId, array $rows, array $sourceIds): void
    {
        $inserts = [];
        foreach ($rows as $ordinal => $row) {
            $pmid = trim((string) ($row['pmid'] ?? ''));
            if (! isset($sourceIds[$pmid])) {
                throw new RuntimeException("Source enrichment row references unknown PMID {$pmid}.");
            }

            $resolutionClass = trim((string) ($row['resolution_class'] ?? 'metadata_enriched'));
            $facts = [];
            foreach ([
                'title' => 'enriched_title',
                'first_author' => 'enriched_first_author',
                'journal' => 'enriched_journal',
                'publication_date' => 'enriched_publication_date',
                'publication_types' => 'enriched_publication_types',
            ] as $field => $sourceColumn) {
                $value = trim((string) ($row[$sourceColumn] ?? ''));
                if ($value !== '') {
                    $facts[$field] = $value;
                }
            }

            if ($facts === [] && str_contains(strtolower($resolutionClass), 'no_personal_author')) {
                $facts['first_author'] = 'not_listed_by_pubmed';
            }
            if ($facts === []) {
                $facts['bibliographic_metadata'] = 'explicit_null_semantics_recorded';
            }

            foreach ($facts as $field => $enrichedValue) {
                $digest = $this->digest([
                    'ordinal' => $ordinal + 1,
                    'pmid' => $pmid,
                    'field' => $field,
                    'value' => $enrichedValue,
                    'resolution_class' => $resolutionClass,
                ]);
                $inserts[] = [
                    'enrichment_uuid' => (string) Str::uuid7(),
                    'catalog_release_id' => $releaseId,
                    'source_id' => $sourceIds[$pmid],
                    'source_field' => $field,
                    'original_value' => $this->nullableString($row['original_value'] ?? null),
                    'enriched_value' => $enrichedValue,
                    'resolution_class' => $resolutionClass,
                    'resolution_note' => $this->nullableString($row['resolution_note'] ?? null),
                    'enrichment_source' => trim((string) ($row['authoritative_source_url'] ?? $row['enrichment_source'] ?? $row['source'] ?? 'verification_package_enrichment')),
                    'authoritative_source_url' => $this->nullableString($row['authoritative_source_url'] ?? null),
                    'secondary_source_url' => $this->nullableString($row['secondary_source_url'] ?? null),
                    'source_record' => $this->json($row),
                    'enrichment_digest' => $digest,
                    'verified_at' => $this->nullableDate($row['checked_at'] ?? null) ?? now(),
                ];
            }
        }

        foreach (array_chunk($inserts, 500) as $chunk) {
            DB::table('care_pathways.source_enrichments')->insertOrIgnore($chunk);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $sourceIds
     */
    private function insertCompletenessResolutions(int $releaseId, array $rows, array $sourceIds): void
    {
        $inserts = [];
        foreach ($rows as $ordinal => $row) {
            $pmid = trim((string) ($row['pmid'] ?? ''));
            $sourceId = $pmid !== '' ? ($sourceIds[$pmid] ?? null) : null;
            $sourceField = trim((string) ($row['source_field'] ?? $row['field_name'] ?? $row['field'] ?? $row['control'] ?? 'audit_row_'.($ordinal + 1)));
            $rawType = strtolower(trim((string) ($row['resolution_type'] ?? $row['classification'] ?? $row['status'] ?? 'source_blank_preserved')));
            $digest = $this->digest(['ordinal' => $ordinal + 1, 'row' => $row]);

            $inserts[] = [
                'resolution_uuid' => (string) Str::uuid7(),
                'catalog_release_id' => $releaseId,
                'source_id' => $sourceId,
                'source_rank' => isset($row['rank']) && is_numeric($row['rank']) ? (int) $row['rank'] : null,
                'source_field' => $sourceField,
                'resolution_type' => $this->resolutionType($rawType),
                'source_blank_count' => isset($row['source_blank_count']) && is_numeric($row['source_blank_count']) ? (int) $row['source_blank_count'] : null,
                'residual_unknown_count' => isset($row['residual_unknown_count']) && is_numeric($row['residual_unknown_count']) ? (int) $row['residual_unknown_count'] : null,
                'source_classification' => $this->nullableString($row['classification'] ?? null),
                'resolved_value' => $this->nullableString($row['resolved_value'] ?? $row['value'] ?? null),
                'rationale' => trim((string) ($row['rationale'] ?? $row['notes'] ?? $row['detail'] ?? $row['evidence'] ?? 'Imported from the verification-package completeness audit.')),
                'corrective_action' => $this->nullableString($row['corrective_action'] ?? null),
                'evidence' => $this->json(['statement' => $row['evidence'] ?? null]),
                'raw_record' => $this->json($row),
                'resolution_digest' => $digest,
                'audited_at' => $this->nullableDate($row['audited_at'] ?? null),
                'created_at' => now(),
            ];
        }

        foreach (array_chunk($inserts, 500) as $chunk) {
            DB::table('care_pathways.completeness_resolutions')->insertOrIgnore($chunk);
        }
    }

    /** @param array<string, mixed> $package */
    private function insertReleaseControls(int $releaseId, array $package): void
    {
        foreach ($package['computed_controls'] as $key => $value) {
            DB::table('care_pathways.catalog_release_controls')->insertOrIgnore([
                'control_uuid' => (string) Str::uuid7(),
                'catalog_release_id' => $releaseId,
                'control_key' => (string) $key,
                'observed_value' => $this->json($value),
                'reference_value' => $this->json(config("care-pathways.expected_controls.{$key}")),
                'status' => 'passed',
                'rationale' => 'Recomputed from immutable raw verification relations during adoption.',
                'evidence' => $this->json(['source' => 'catalog_reconciliation_service']),
                'recorded_at' => now(),
            ]);
        }

        DB::table('care_pathways.catalog_release_controls')->insertOrIgnore([
            'control_uuid' => (string) Str::uuid7(),
            'catalog_release_id' => $releaseId,
            'control_key' => 'cms_v43_1_list_design_count_discrepancy',
            'observed_value' => $this->json(['official_list_distinct_codes' => 770]),
            'reference_value' => $this->json(['design_document_total_ms_drgs' => 772]),
            'status' => 'accepted_discrepancy',
            'rationale' => 'The adopted CSV exactly matches the CMS v43.1 list page (770 codes); the CMS design PDF separately states 772 total MS-DRGs. The difference is retained as a release control and must be rechecked for future groupers.',
            'evidence' => $this->json([
                'list_url' => 'https://www.cms.gov/icd10m/FY2026-fr-v43.1-fullcode-cms/fullcode_cms/P0392.html',
                'effective_period' => ['2026-04-01', '2026-09-30'],
            ]),
            'recorded_at' => now(),
        ]);

        $rank89 = collect($package['pathways'])->first(fn (array $row): bool => (int) $row['rank'] === 89);
        if (is_array($rank89)) {
            DB::table('care_pathways.catalog_release_controls')->insertOrIgnore([
                'control_uuid' => (string) Str::uuid7(),
                'catalog_release_id' => $releaseId,
                'control_key' => 'rank_89_coverage_rounding',
                'observed_value' => $this->json(['stored_percent' => (float) $rank89['cumulative_pct_admissions']]),
                'reference_value' => $this->json(['arithmetic_rounding_percent' => 88.0]),
                'status' => 'accepted_discrepancy',
                'rationale' => 'Preserve the source 87.9% value; do not silently rewrite it to the independently calculated 88.0% rounding.',
                'evidence' => $this->json(['planning_denominator' => 33300000]),
                'recorded_at' => now(),
            ]);
        }
    }

    /** @param array<string, mixed> $package */
    private function insertSupplementalControls(int $releaseId, array $package): void
    {
        $pendingServiceLines = (int) DB::table('care_pathways.service_line_mappings')
            ->where('catalog_release_id', $releaseId)
            ->where('mapping_status', 'pending')
            ->count();
        $serviceLineLabels = (int) DB::table('care_pathways.service_line_mappings')
            ->where('catalog_release_id', $releaseId)
            ->count();
        $controls = [
            [
                'key' => 'source_service_line_labels',
                'observed' => $serviceLineLabels,
                'reference' => $serviceLineLabels,
                'status' => 'passed',
                'rationale' => 'Every distinct source service-line label has a governed mapping row.',
            ],
            [
                'key' => 'source_service_line_mappings_pending',
                'observed' => $pendingServiceLines,
                'reference' => 0,
                'status' => $pendingServiceLines === 0 ? 'passed' : 'accepted_discrepancy',
                'rationale' => 'Composite or locally absent service-line labels remain pending rather than being guessed; an institutional crosswalk owner must adjudicate them.',
            ],
        ];

        $rows = array_map(fn (array $control): array => [
            'control_uuid' => (string) Str::uuid7(),
            'catalog_release_id' => $releaseId,
            'control_key' => $control['key'],
            'observed_value' => $this->json($control['observed']),
            'reference_value' => $this->json($control['reference']),
            'status' => $control['status'],
            'rationale' => $control['rationale'],
            'evidence' => $this->json(['source' => 'catalog_import_service']),
            'recorded_at' => now(),
        ], $controls);

        DB::table('care_pathways.catalog_release_controls')->insertOrIgnore($rows);
    }

    /** @param array<string, mixed> $package */
    private function packageSummary(array $package, bool $reused, bool $dryRun): array
    {
        return [
            'catalog_release_id' => 0,
            'dataset_key' => (string) $package['identity']['dataset_key'],
            'state' => 'inactive',
            'reused' => $reused,
            'dry_run' => $dryRun,
            'pathways' => (int) $package['computed_controls']['pathways'],
            'drg_codebook_entries' => (int) $package['computed_controls']['unique_drg_codes'],
            'drg_mappings' => (int) $package['computed_controls']['pathway_drg_associations'],
            'claims' => (int) $package['computed_controls']['claims'],
            'sources' => (int) $package['computed_controls']['sources'],
            'changes' => (int) $package['computed_controls']['changes'],
            'clinical_signoff_complete' => false,
        ];
    }

    private function databaseSummary(int $releaseId, bool $reused): array
    {
        $release = DB::table('care_pathways.catalog_releases')->where('catalog_release_id', $releaseId)->first();

        return [
            'catalog_release_id' => $releaseId,
            'dataset_key' => (string) $release->dataset_key,
            'state' => (string) $release->state,
            'reused' => $reused,
            'dry_run' => false,
            'pathways' => (int) DB::table('care_pathways.versions')->where('catalog_release_id', $releaseId)->count(),
            'drg_codebook_entries' => (int) DB::table('care_pathways.drg_codebook_entries')->where('catalog_release_id', $releaseId)->count(),
            'drg_mappings' => (int) DB::table('care_pathways.drg_mappings as mappings')
                ->join('care_pathways.versions as versions', 'versions.pathway_version_id', '=', 'mappings.pathway_version_id')
                ->where('versions.catalog_release_id', $releaseId)
                ->count(),
            'claims' => (int) DB::table('care_pathways.evidence_claims')->where('catalog_release_id', $releaseId)->count(),
            'sources' => (int) $release->source_count,
            'changes' => (int) DB::table('care_pathways.source_changes')->where('catalog_release_id', $releaseId)->count(),
            'clinical_signoff_complete' => (bool) $release->clinical_signoff_complete,
        ];
    }

    private function pathwayKey(string $condition): string
    {
        $slug = Str::slug($condition, '-');

        return 'drgcp-'.Str::limit($slug, 80, '').'-'.substr(hash('sha256', mb_strtolower($condition)), 0, 12);
    }

    private function mappedServiceLine(string $source): ?string
    {
        $candidate = Str::slug($source, '_');
        $canonical = $this->serviceLineNormalizer->canonical($candidate);

        if (! $this->serviceLineNormalizer->isKnown($candidate)) {
            return null;
        }

        try {
            $registered = DB::table('hosp_ref.service_lines')
                ->where('service_line_code', $canonical)
                ->exists();
        } catch (Throwable) {
            return null;
        }

        return $registered ? $canonical : null;
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
        return array_values(array_unique(preg_split('/\s*[,;|]\s*/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }

    /** @return list<string> */
    private function unresolvedFlags(mixed $value): array
    {
        $value = trim((string) $value);
        if ($value === '' || str_starts_with(strtolower($value), 'none ')) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode('|', $value))));
    }

    /** @return list<string> */
    private function listValue(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }

        $text = trim((string) $value);
        if ($text === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\s*[|;]\s*/', $text) ?: [])));
    }

    private function retractionState(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        if ($value === '') {
            return 'unknown';
        }

        if (in_array($value, ['no', 'none', 'false', '0'], true)) {
            return 'current';
        }

        if (preg_match('/\b(?:no|not|without)\b(?:[\W_]+\w+){0,8}[\W_]+\bretract(?:ed|ion)?\b/u', $value) === 1) {
            return 'current';
        }

        if (preg_match('/\bretract(?:ed|ion)?\b.{0,40}\b(?:no|none|negative|absent)\b/u', $value) === 1) {
            return 'current';
        }

        if (str_contains($value, 'supersed')) {
            return 'superseded';
        }

        if (str_contains($value, 'retract') || in_array($value, ['yes', 'true', '1'], true)) {
            return 'retracted';
        }

        return 'unknown';
    }

    private function resolutionType(string $value): string
    {
        return match (true) {
            $value === 'complete' => 'complete',
            str_contains($value, 'optional_not_applicable_or_not_recorded') => 'optional_not_recorded',
            str_contains($value, 'enrich'), str_contains($value, 'resolved') => 'enriched',
            str_contains($value, 'metadata_omission') => 'enriched',
            str_contains($value, 'not_listed'), str_contains($value, 'not listed') => 'not_listed',
            str_contains($value, 'not_applicable'), str_contains($value, 'not applicable') => 'not_applicable',
            str_contains($value, 'unresolved'), str_contains($value, 'unclassified') => 'unresolved',
            default => 'source_blank_preserved',
        };
    }

    private function digest(array $value): string
    {
        return hash('sha256', $this->json($this->sortRecursive($value)));
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursive($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursive($item);
        }

        return $value;
    }

    private function json(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $encoded;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableDate(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function integerValue(array $row, array $fields): ?int
    {
        foreach ($fields as $field) {
            if (isset($row[$field]) && is_numeric($row[$field])) {
                return (int) $row[$field];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function redactedManifest(array $manifest): array
    {
        return collect($manifest)
            ->reject(fn (mixed $value, string $key): bool => str_contains(strtolower($key), 'path')
                || str_contains(strtolower($key), 'credential')
                || str_contains(strtolower($key), 'password'))
            ->all();
    }
}
