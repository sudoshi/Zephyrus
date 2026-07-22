<?php

namespace App\Services\CarePathways;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CatalogGovernanceReadService
{
    public const CLINICAL_APPROVAL_WARNING = 'Automated evidence verification is not institutional clinical approval.';

    /** @return array<string, mixed>|null */
    public function summary(?string $releaseUuid = null): ?array
    {
        $release = $this->release($releaseUuid);
        if ($release === null) {
            return null;
        }

        $releaseId = (int) $release->catalog_release_id;
        $versionCounts = DB::table('care_pathways.versions')
            ->where('catalog_release_id', $releaseId)
            ->selectRaw('count(*) AS total')
            ->selectRaw("count(*) FILTER (WHERE institutional_approval_status = 'approved') AS institutionally_approved")
            ->selectRaw("count(*) FILTER (WHERE activation_status = 'active') AS active")
            ->first();
        $sectionCounts = DB::table('care_pathways.sections as sections')
            ->join('care_pathways.versions as versions', 'versions.pathway_version_id', '=', 'sections.pathway_version_id')
            ->where('versions.catalog_release_id', $releaseId)
            ->selectRaw('count(*) AS total')
            ->selectRaw('count(*) FILTER (WHERE sections.approved_text IS NOT NULL) AS approved_text')
            ->selectRaw("count(*) FILTER (WHERE sections.audience IN ('patient', 'caregiver')) AS patient_or_caregiver")
            ->first();
        $sourceCounts = DB::table('care_pathways.catalog_release_sources as release_sources')
            ->join('care_pathways.current_source_statuses as statuses', 'statuses.source_id', '=', 'release_sources.source_id')
            ->where('release_sources.catalog_release_id', $releaseId)
            ->selectRaw('count(*) AS indexed')
            ->selectRaw("count(*) FILTER (WHERE statuses.supersession_state = 'current') AS current")
            ->selectRaw("count(*) FILTER (WHERE statuses.supersession_state <> 'current') AS noncurrent")
            ->first();

        $controlsByStatus = DB::table('care_pathways.catalog_release_controls')
            ->where('catalog_release_id', $releaseId)
            ->select('status', DB::raw('count(*) AS total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
        $serviceLinesByStatus = DB::table('care_pathways.service_line_mappings')
            ->where('catalog_release_id', $releaseId)
            ->select('mapping_status', DB::raw('count(*) AS total'))
            ->groupBy('mapping_status')
            ->pluck('total', 'mapping_status')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();

        return $this->envelope($release, [
            'release' => $this->releaseDto($release),
            'catalog' => [
                'definitions' => DB::table('care_pathways.definitions as definitions')
                    ->join('care_pathways.versions as versions', 'versions.pathway_definition_id', '=', 'definitions.pathway_definition_id')
                    ->where('versions.catalog_release_id', $releaseId)
                    ->distinct('definitions.pathway_definition_id')
                    ->count('definitions.pathway_definition_id'),
                'versions' => (int) ($versionCounts->total ?? 0),
                'institutionally_approved_versions' => (int) ($versionCounts->institutionally_approved ?? 0),
                'active_versions' => (int) ($versionCounts->active ?? 0),
                'sections' => (int) ($sectionCounts->total ?? 0),
                'approved_sections' => (int) ($sectionCounts->approved_text ?? 0),
                'patient_or_caregiver_sections' => (int) ($sectionCounts->patient_or_caregiver ?? 0),
                'drg_codebook_entries' => DB::table('care_pathways.drg_codebook_entries')->where('catalog_release_id', $releaseId)->count(),
                'drg_mappings' => DB::table('care_pathways.drg_mappings as mappings')
                    ->join('care_pathways.versions as versions', 'versions.pathway_version_id', '=', 'mappings.pathway_version_id')
                    ->where('versions.catalog_release_id', $releaseId)
                    ->count(),
                'evidence_claims' => DB::table('care_pathways.evidence_claims')->where('catalog_release_id', $releaseId)->count(),
                'sources' => (int) ($sourceCounts->indexed ?? 0),
                'current_sources' => (int) ($sourceCounts->current ?? 0),
                'noncurrent_sources' => (int) ($sourceCounts->noncurrent ?? 0),
                'changes' => DB::table('care_pathways.source_changes')->where('catalog_release_id', $releaseId)->count(),
            ],
            'review_queues' => [
                'evidence_verified' => (int) $release->evidence_verified_count,
                'evidence_limitations' => (int) $release->evidence_limitations_count,
                'institutional_signoff' => (int) $release->signoff_queue_count,
                'specialist_review' => (int) $release->specialist_review_count,
                'redesign' => (int) $release->redesign_count,
                'recorded_reviews' => DB::table('care_pathways.reviews as reviews')
                    ->join('care_pathways.versions as versions', 'versions.pathway_version_id', '=', 'reviews.pathway_version_id')
                    ->where('versions.catalog_release_id', $releaseId)
                    ->count(),
                'recorded_approvals' => DB::table('care_pathways.approvals as approvals')
                    ->join('care_pathways.versions as versions', 'versions.pathway_version_id', '=', 'approvals.pathway_version_id')
                    ->where('versions.catalog_release_id', $releaseId)
                    ->count(),
            ],
            'controls' => [
                'by_status' => $controlsByStatus,
                'failed' => $controlsByStatus['failed'] ?? 0,
                'residual_unknowns' => (int) DB::table('care_pathways.current_completeness_resolutions')
                    ->where('catalog_release_id', $releaseId)
                    ->sum('residual_unknown_count'),
                'service_line_mappings' => $serviceLinesByStatus,
            ],
            'serving_flags' => $this->servingFlags(),
            'release_readiness' => [
                'clinical_signoff_complete' => (bool) $release->clinical_signoff_complete,
                'may_serve_approved_catalog' => $release->state === 'active'
                    && (bool) $release->clinical_signoff_complete
                    && (int) ($versionCounts->active ?? 0) === (int) $release->pathway_count
                    && (int) ($versionCounts->institutionally_approved ?? 0) === (int) $release->pathway_count,
                'patient_projection_released' => false,
                'eddy_retrieval_released' => false,
            ],
        ]);
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    public function pathways(array $filters): ?array
    {
        $release = $this->release($filters['release_uuid'] ?? null);
        if ($release === null) {
            return null;
        }

        $query = DB::table('care_pathways.versions as versions')
            ->join('care_pathways.definitions as definitions', 'definitions.pathway_definition_id', '=', 'versions.pathway_definition_id')
            ->where('versions.catalog_release_id', $release->catalog_release_id)
            ->select([
                'versions.pathway_version_id',
                'versions.pathway_version_uuid',
                'versions.semantic_version',
                'versions.source_rank',
                'versions.evidence_status',
                'versions.verification_confidence',
                'versions.source_specificity',
                'versions.release_disposition',
                'versions.clinical_signoff_status',
                'versions.institutional_approval_status',
                'versions.activation_status',
                'versions.effective_start',
                'versions.effective_end',
                'versions.content_digest',
                'definitions.pathway_uuid',
                'definitions.pathway_key',
                'definitions.canonical_name',
                'definitions.mdc_label',
                'definitions.care_type',
                'definitions.source_service_line',
                'definitions.service_line_code',
                'definitions.lifecycle_state',
            ]);

        $this->applyPathwayFilters($query, $filters);
        $paginator = $query
            ->orderBy('versions.source_rank')
            ->paginate(
                (int) ($filters['per_page'] ?? 25),
                ['*'],
                'page',
                (int) ($filters['page'] ?? 1),
            );
        $rows = collect($paginator->items());
        $versionIds = $rows->pluck('pathway_version_id')->map(fn (mixed $id): int => (int) $id)->all();

        $drgs = $this->drgsByVersion($versionIds);
        $claimCounts = $this->countsByVersion('care_pathways.evidence_claims', 'pathway_version_id', $versionIds);
        $sectionCounts = $this->sectionCountsByVersion($versionIds);
        $reviewCounts = $this->countsByVersion('care_pathways.reviews', 'pathway_version_id', $versionIds);
        $approvalCounts = $this->countsByVersion('care_pathways.approvals', 'pathway_version_id', $versionIds);
        $noncurrent = $this->noncurrentVersionIds($versionIds);
        $sourceCutoff = $this->sourceCutoff($release);

        $items = $rows->map(function (object $row) use (
            $drgs,
            $claimCounts,
            $sectionCounts,
            $reviewCounts,
            $approvalCounts,
            $noncurrent,
            $sourceCutoff,
        ): array {
            $versionId = (int) $row->pathway_version_id;
            $sections = $sectionCounts[$versionId] ?? ['total' => 0, 'approved' => 0];

            return [
                'pathway' => [
                    'uuid' => (string) $row->pathway_uuid,
                    'key' => (string) $row->pathway_key,
                    'name' => (string) $row->canonical_name,
                    'mdc' => $row->mdc_label,
                    'care_type' => $row->care_type,
                    'source_service_line' => $row->source_service_line,
                    'service_line_code' => $row->service_line_code,
                    'lifecycle_state' => (string) $row->lifecycle_state,
                ],
                'version' => $this->versionIdentity($row, $sourceCutoff),
                'drg_candidates' => $drgs[$versionId] ?? [],
                'evidence' => [
                    'status' => (string) $row->evidence_status,
                    'confidence' => $row->verification_confidence,
                    'source_specificity' => $row->source_specificity,
                    'release_disposition' => (string) $row->release_disposition,
                    'claim_count' => $claimCounts[$versionId] ?? 0,
                    'source_currency' => in_array($versionId, $noncurrent, true) ? 'noncurrent_source_linked' : 'current',
                ],
                'governance' => [
                    'clinical_signoff_status' => (string) $row->clinical_signoff_status,
                    'institutional_approval_status' => (string) $row->institutional_approval_status,
                    'activation_status' => (string) $row->activation_status,
                    'section_count' => $sections['total'],
                    'approved_section_count' => $sections['approved'],
                    'review_count' => $reviewCounts[$versionId] ?? 0,
                    'approval_count' => $approvalCounts[$versionId] ?? 0,
                ],
            ];
        })->all();

        return $this->envelope($release, $items, ['pagination' => $this->pagination($paginator)]);
    }

    /** @return array<string, mixed>|null */
    public function version(string $versionUuid, ?string $releaseUuid = null): ?array
    {
        $release = $this->release($releaseUuid);
        if ($release === null) {
            return null;
        }

        $version = DB::table('care_pathways.versions as versions')
            ->join('care_pathways.definitions as definitions', 'definitions.pathway_definition_id', '=', 'versions.pathway_definition_id')
            ->where('versions.catalog_release_id', $release->catalog_release_id)
            ->where('versions.pathway_version_uuid', $versionUuid)
            ->first([
                'versions.pathway_version_id',
                'versions.pathway_version_uuid',
                'versions.semantic_version',
                'versions.source_rank',
                'versions.evidence_status',
                'versions.verification_confidence',
                'versions.source_specificity',
                'versions.unresolved_flags',
                'versions.release_disposition',
                'versions.clinical_signoff_status',
                'versions.institutional_approval_status',
                'versions.activation_status',
                'versions.source_digest',
                'versions.content_digest',
                'versions.effective_start',
                'versions.effective_end',
                'definitions.pathway_uuid',
                'definitions.pathway_key',
                'definitions.canonical_name',
                'definitions.mdc_label',
                'definitions.care_type',
                'definitions.source_service_line',
                'definitions.service_line_code',
                'definitions.lifecycle_state',
            ]);

        if ($version === null) {
            return null;
        }

        $versionId = (int) $version->pathway_version_id;
        $sections = DB::table('care_pathways.sections')
            ->where('pathway_version_id', $versionId)
            ->orderBy('section_code')
            ->orderBy('audience')
            ->get([
                'section_uuid',
                'section_code',
                'audience',
                'language_code',
                'source_text',
                'approved_text',
                'content_mode',
                'review_state',
                'source_digest',
                'approved_digest',
            ])
            ->map(fn (object $section): array => [
                'section_uuid' => (string) $section->section_uuid,
                'section_code' => (string) $section->section_code,
                'audience' => (string) $section->audience,
                'language_code' => (string) $section->language_code,
                'source_text' => (string) $section->source_text,
                'approved_text' => $section->approved_text,
                'content_mode' => (string) $section->content_mode,
                'review_state' => (string) $section->review_state,
                'source_digest' => (string) $section->source_digest,
                'approved_digest' => $section->approved_digest,
            ])->all();

        $reviews = DB::table('care_pathways.reviews')
            ->where('pathway_version_id', $versionId)
            ->orderByDesc('reviewed_at')
            ->get([
                'review_uuid', 'reviewer_role', 'review_scope', 'decision', 'reason', 'issues', 'reviewed_at',
            ])->map(fn (object $review): array => [
                'review_uuid' => (string) $review->review_uuid,
                'reviewer_role' => (string) $review->reviewer_role,
                'review_scope' => (string) $review->review_scope,
                'decision' => (string) $review->decision,
                'reason' => (string) $review->reason,
                'issues' => json_decode((string) $review->issues, true, 512, JSON_THROW_ON_ERROR),
                'reviewed_at' => $review->reviewed_at,
            ])->all();
        $approvals = DB::table('care_pathways.approvals')
            ->where('pathway_version_id', $versionId)
            ->orderByDesc('decided_at')
            ->get([
                'approval_uuid', 'approval_type', 'decision', 'conditions', 'effective_start', 'effective_end', 'decided_at',
            ])->map(fn (object $approval): array => [
                'approval_uuid' => (string) $approval->approval_uuid,
                'approval_type' => (string) $approval->approval_type,
                'decision' => (string) $approval->decision,
                'conditions' => $approval->conditions,
                'effective_period' => ['start' => $approval->effective_start, 'end' => $approval->effective_end],
                'decided_at' => $approval->decided_at,
            ])->all();

        return $this->envelope($release, [
            'pathway' => [
                'uuid' => (string) $version->pathway_uuid,
                'key' => (string) $version->pathway_key,
                'name' => (string) $version->canonical_name,
                'mdc' => $version->mdc_label,
                'care_type' => $version->care_type,
                'source_service_line' => $version->source_service_line,
                'service_line_code' => $version->service_line_code,
                'lifecycle_state' => (string) $version->lifecycle_state,
            ],
            'version' => $this->versionIdentity($version, $this->sourceCutoff($release)) + [
                'source_digest' => (string) $version->source_digest,
                'unresolved_flags' => json_decode((string) $version->unresolved_flags, true, 512, JSON_THROW_ON_ERROR),
            ],
            'drg_candidates' => $this->drgsByVersion([$versionId])[$versionId] ?? [],
            'evidence' => [
                'status' => (string) $version->evidence_status,
                'confidence' => $version->verification_confidence,
                'source_specificity' => $version->source_specificity,
                'release_disposition' => (string) $version->release_disposition,
                'claim_count' => DB::table('care_pathways.evidence_claims')->where('pathway_version_id', $versionId)->count(),
                'source_currency' => $this->noncurrentVersionIds([$versionId]) === [] ? 'current' : 'noncurrent_source_linked',
            ],
            'governance' => [
                'clinical_signoff_status' => (string) $version->clinical_signoff_status,
                'institutional_approval_status' => (string) $version->institutional_approval_status,
                'activation_status' => (string) $version->activation_status,
                'reviews' => $reviews,
                'approvals' => $approvals,
            ],
            'sections' => $sections,
            'authoring' => $this->authoringDefinitions($versionId),
            'changes' => DB::table('care_pathways.source_changes')
                ->where('catalog_release_id', $release->catalog_release_id)
                ->where('source_rank', $version->source_rank)
                ->orderBy('source_change_id')
                ->get(['change_uuid', 'source_field', 'old_value', 'new_value', 'reason', 'source_reference', 'changed_on'])
                ->map(fn (object $change): array => (array) $change)
                ->all(),
        ]);
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    public function claims(string $versionUuid, array $filters): ?array
    {
        $release = $this->release($filters['release_uuid'] ?? null);
        if ($release === null) {
            return null;
        }

        $version = DB::table('care_pathways.versions')
            ->where('catalog_release_id', $release->catalog_release_id)
            ->where('pathway_version_uuid', $versionUuid)
            ->first(['pathway_version_id', 'pathway_version_uuid', 'semantic_version', 'content_digest', 'effective_start', 'effective_end']);
        if ($version === null) {
            return null;
        }

        $query = DB::table('care_pathways.evidence_claims')
            ->where('pathway_version_id', $version->pathway_version_id)
            ->when(isset($filters['source_field']), fn (Builder $query) => $query->where('source_field', $filters['source_field']))
            ->when(isset($filters['claim_type']), fn (Builder $query) => $query->where('claim_type', $filters['claim_type']))
            ->orderBy('evidence_claim_id');
        $paginator = $query->paginate(
            (int) ($filters['per_page'] ?? 50),
            ['*'],
            'page',
            (int) ($filters['page'] ?? 1),
        );
        $claims = collect($paginator->items());
        $claimIds = $claims->pluck('evidence_claim_id')->map(fn (mixed $id): int => (int) $id)->all();
        $sources = $this->claimSources($claimIds);

        $items = $claims->map(fn (object $claim): array => [
            'claim_uuid' => (string) $claim->claim_uuid,
            'source_rank' => (int) $claim->source_rank,
            'source_field' => (string) $claim->source_field,
            'claim_type' => $claim->claim_type,
            'claim_excerpt' => (string) $claim->claim_excerpt,
            'automated_review' => [
                'pass_1' => $claim->automated_pass_1,
                'pass_2' => $claim->automated_pass_2,
            ],
            'clinical_adjudication' => $claim->clinical_adjudication,
            'verification_date' => $claim->verification_date,
            'claim_digest' => (string) $claim->claim_digest,
            'sources' => $sources[(int) $claim->evidence_claim_id] ?? [],
        ])->all();

        return $this->envelope($release, $items, [
            'version' => $this->versionIdentity($version, $this->sourceCutoff($release)),
            'pagination' => $this->pagination($paginator),
        ]);
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    public function sources(array $filters): ?array
    {
        $release = $this->release($filters['release_uuid'] ?? null);
        if ($release === null) {
            return null;
        }

        $query = $this->releaseSourcesQuery($release);
        $this->applySourceFilters($query, $filters, (int) $release->catalog_release_id);
        $paginator = $query->orderByRaw('sources.pmid NULLS LAST')->orderBy('sources.source_id')->paginate(
            (int) ($filters['per_page'] ?? 25),
            ['*'],
            'page',
            (int) ($filters['page'] ?? 1),
        );
        $rows = collect($paginator->items());
        $sourceIds = $rows->pluck('source_id')->map(fn (mixed $id): int => (int) $id)->all();
        $citationCounts = $this->citationCountsBySource($sourceIds, (int) $release->catalog_release_id);
        $enrichmentCounts = DB::table('care_pathways.current_source_enrichments')
            ->where('catalog_release_id', $release->catalog_release_id)
            ->whereIn('source_id', $sourceIds)
            ->select('source_id', DB::raw('count(*) AS total'))
            ->groupBy('source_id')
            ->pluck('total', 'source_id')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();

        $items = $rows->map(function (object $source) use ($citationCounts, $enrichmentCounts): array {
            $sourceId = (int) $source->source_id;
            $citations = $citationCounts[$sourceId] ?? 0;

            return $this->sourceDto($source) + [
                'citation_count' => $citations,
                'uncited' => $citations === 0,
                'enrichment_fact_count' => $enrichmentCounts[$sourceId] ?? 0,
            ];
        })->all();

        return $this->envelope($release, $items, ['pagination' => $this->pagination($paginator)]);
    }

    /** @return array<string, mixed>|null */
    public function source(string $sourceUuid, ?string $releaseUuid = null): ?array
    {
        $release = $this->release($releaseUuid);
        if ($release === null) {
            return null;
        }

        $source = $this->releaseSourcesQuery($release)
            ->where('sources.source_uuid', $sourceUuid)
            ->first();
        if ($source === null) {
            return null;
        }

        $sourceId = (int) $source->source_id;
        $enrichments = DB::table('care_pathways.current_source_enrichments')
            ->where('catalog_release_id', $release->catalog_release_id)
            ->where('source_id', $sourceId)
            ->orderBy('source_field')
            ->get([
                'enrichment_uuid', 'source_field', 'original_value', 'enriched_value', 'resolution_class',
                'resolution_note', 'enrichment_source', 'authoritative_source_url', 'secondary_source_url',
                'enrichment_digest', 'verified_at',
            ])->map(fn (object $fact): array => (array) $fact)->all();
        $statusHistory = DB::table('care_pathways.source_status_events')
            ->where('source_id', $sourceId)
            ->orderByDesc('effective_at')
            ->orderByDesc('source_status_event_id')
            ->get([
                'status_event_uuid', 'supersession_state', 'reason', 'evidence_url', 'observed_at',
                'effective_at', 'recorded_by_ref', 'event_digest',
            ])->map(fn (object $event): array => (array) $event)->all();
        $completeness = DB::table('care_pathways.current_completeness_resolutions')
            ->where('catalog_release_id', $release->catalog_release_id)
            ->where('source_id', $sourceId)
            ->orderBy('source_field')
            ->get([
                'resolution_uuid', 'source_field', 'resolution_type', 'source_blank_count',
                'residual_unknown_count', 'source_classification', 'resolved_value', 'rationale',
                'corrective_action', 'resolution_digest', 'audited_at',
            ])->map(fn (object $resolution): array => (array) $resolution)->all();

        return $this->envelope($release, $this->sourceDto($source) + [
            'citation_count' => $this->citationCountsBySource([$sourceId], (int) $release->catalog_release_id)[$sourceId] ?? 0,
            'enrichments' => $enrichments,
            'status_history' => $statusHistory,
            'completeness_resolutions' => $completeness,
        ]);
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    public function controls(array $filters): ?array
    {
        $release = $this->release($filters['release_uuid'] ?? null);
        if ($release === null) {
            return null;
        }

        $query = DB::table('care_pathways.catalog_release_controls')
            ->where('catalog_release_id', $release->catalog_release_id)
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->orderBy('control_key');
        $paginator = $query->paginate(
            (int) ($filters['per_page'] ?? 50),
            ['*'],
            'page',
            (int) ($filters['page'] ?? 1),
        );
        $items = collect($paginator->items())->map(fn (object $control): array => [
            'control_uuid' => (string) $control->control_uuid,
            'key' => (string) $control->control_key,
            'observed_value' => json_decode((string) $control->observed_value, true, 512, JSON_THROW_ON_ERROR),
            'reference_value' => $control->reference_value === null
                ? null
                : json_decode((string) $control->reference_value, true, 512, JSON_THROW_ON_ERROR),
            'status' => (string) $control->status,
            'rationale' => (string) $control->rationale,
            'evidence' => json_decode((string) $control->evidence, true, 512, JSON_THROW_ON_ERROR),
            'recorded_at' => $control->recorded_at,
        ])->all();

        return $this->envelope($release, $items, ['pagination' => $this->pagination($paginator)]);
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    public function reviews(array $filters): ?array
    {
        return $this->versionLedger('reviews', 'reviewed_at', $filters);
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    public function approvals(array $filters): ?array
    {
        return $this->versionLedger('approvals', 'decided_at', $filters);
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    public function events(array $filters): ?array
    {
        $release = $this->release($filters['release_uuid'] ?? null);
        if ($release === null) {
            return null;
        }

        $query = DB::table('care_pathways.events')
            ->where(function (Builder $query) use ($release): void {
                $query->where(function (Builder $query) use ($release): void {
                    $query->where('aggregate_type', 'catalog_release')
                        ->where('aggregate_id', $release->catalog_release_id);
                })->orWhere(function (Builder $query) use ($release): void {
                    $query->where('aggregate_type', 'pathway_version')
                        ->whereIn('aggregate_id', DB::table('care_pathways.versions')
                            ->where('catalog_release_id', $release->catalog_release_id)
                            ->select('pathway_version_id'));
                })->orWhere(function (Builder $query) use ($release): void {
                    $query->where('aggregate_type', 'pathway_definition')
                        ->whereIn('aggregate_id', DB::table('care_pathways.versions')
                            ->where('catalog_release_id', $release->catalog_release_id)
                            ->select('pathway_definition_id'));
                });
            })
            ->when(isset($filters['event_type']), fn (Builder $query) => $query->where('event_type', $filters['event_type']))
            ->orderByDesc('occurred_at')
            ->orderByDesc('pathway_event_id');
        $paginator = $query->paginate(
            (int) ($filters['per_page'] ?? 50),
            ['*'],
            'page',
            (int) ($filters['page'] ?? 1),
        );
        $items = collect($paginator->items())->map(fn (object $event): array => [
            'event_uuid' => (string) $event->event_uuid,
            'aggregate_type' => (string) $event->aggregate_type,
            'aggregate_uuid' => $event->aggregate_uuid,
            'aggregate_version' => $event->aggregate_version,
            'event_type' => (string) $event->event_type,
            'actor_ref' => $event->actor_ref,
            'correlation_id' => $event->correlation_id,
            'metadata' => json_decode((string) $event->metadata, true, 512, JSON_THROW_ON_ERROR),
            'occurred_at' => $event->occurred_at,
        ])->all();

        return $this->envelope($release, $items, ['pagination' => $this->pagination($paginator)]);
    }

    /** @param array<string, mixed> $filters */
    private function applyPathwayFilters(Builder $query, array $filters): void
    {
        if (isset($filters['q'])) {
            $pattern = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $filters['q'])).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->where('definitions.canonical_name', 'ilike', $pattern)
                    ->orWhere('definitions.pathway_key', 'ilike', $pattern);
            });
        }
        if (isset($filters['drg'])) {
            $drg = str_pad((string) $filters['drg'], 3, '0', STR_PAD_LEFT);
            $query->whereExists(function (Builder $query) use ($drg): void {
                $query->selectRaw('1')
                    ->from('care_pathways.drg_mappings as mappings')
                    ->join('care_pathways.drg_codebook_entries as codebook', 'codebook.drg_codebook_entry_id', '=', 'mappings.drg_codebook_entry_id')
                    ->whereColumn('mappings.pathway_version_id', 'versions.pathway_version_id')
                    ->where('codebook.ms_drg', $drg);
            });
        }
        if (isset($filters['mdc'])) {
            $query->where('definitions.mdc_label', 'ilike', '%'.trim((string) $filters['mdc']).'%');
        }
        if (isset($filters['service_line'])) {
            $serviceLine = trim((string) $filters['service_line']);
            $query->where(function (Builder $query) use ($serviceLine): void {
                $query->where('definitions.service_line_code', $serviceLine)
                    ->orWhere('definitions.source_service_line', 'ilike', '%'.$serviceLine.'%');
            });
        }
        if (isset($filters['evidence_state'])) {
            $query->where('versions.evidence_status', $this->evidenceStatus((string) $filters['evidence_state']));
        }
        if (isset($filters['disposition'])) {
            $query->where('versions.release_disposition', $this->releaseDisposition((string) $filters['disposition']));
        }
        if (isset($filters['institutional_approval_status'])) {
            $query->where('versions.institutional_approval_status', $filters['institutional_approval_status']);
        }
        if (isset($filters['activation_status'])) {
            $query->where('versions.activation_status', $filters['activation_status']);
        }
    }

    /** @param array<string, mixed> $filters */
    private function applySourceFilters(Builder $query, array $filters, int $releaseId): void
    {
        if (isset($filters['q'])) {
            $pattern = '%'.trim((string) $filters['q']).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->where('sources.title', 'ilike', $pattern)
                    ->orWhere('sources.pmid', 'ilike', $pattern)
                    ->orWhere('sources.first_author', 'ilike', $pattern)
                    ->orWhere('sources.organization', 'ilike', $pattern);
            });
        }
        if (isset($filters['status'])) {
            $query->where('statuses.supersession_state', $filters['status']);
        }
        if (isset($filters['verified_before'])) {
            $query->whereDate('sources.verified_date', '<=', $filters['verified_before']);
        }
        if (array_key_exists('cited', $filters)) {
            $method = (bool) $filters['cited'] ? 'whereExists' : 'whereNotExists';
            $query->{$method}(function (Builder $query) use ($releaseId): void {
                $query->selectRaw('1')
                    ->from('care_pathways.claim_sources as claim_sources')
                    ->join('care_pathways.evidence_claims as claims', 'claims.evidence_claim_id', '=', 'claim_sources.evidence_claim_id')
                    ->whereColumn('claim_sources.source_id', 'sources.source_id')
                    ->where('claims.catalog_release_id', $releaseId);
            });
        }
    }

    private function releaseSourcesQuery(object $release): Builder
    {
        return DB::table('care_pathways.catalog_release_sources as release_sources')
            ->join('care_pathways.sources as sources', 'sources.source_id', '=', 'release_sources.source_id')
            ->join('care_pathways.current_source_statuses as statuses', 'statuses.source_id', '=', 'sources.source_id')
            ->where('release_sources.catalog_release_id', $release->catalog_release_id)
            ->select([
                'sources.source_id',
                'sources.source_uuid',
                'sources.pmid',
                'sources.doi',
                'sources.source_url',
                'sources.title',
                'sources.first_author',
                'sources.organization',
                'sources.journal',
                'sources.publication_date',
                'sources.publication_types',
                'sources.source_type',
                'sources.retraction_indicator',
                'sources.verified_date',
                'sources.content_digest',
                'statuses.supersession_state as current_status',
                'statuses.effective_at as status_effective_at',
            ]);
    }

    /** @return array<string, mixed> */
    private function sourceDto(object $source): array
    {
        return [
            'source_uuid' => (string) $source->source_uuid,
            'pmid' => $source->pmid,
            'doi' => $source->doi,
            'url' => $source->source_url,
            'title' => $source->title,
            'first_author' => $source->first_author,
            'organization' => $source->organization,
            'journal' => $source->journal,
            'publication_date' => $source->publication_date,
            'publication_types' => json_decode((string) $source->publication_types, true, 512, JSON_THROW_ON_ERROR),
            'source_type' => (string) $source->source_type,
            'retraction_indicator' => $source->retraction_indicator,
            'current_status' => (string) $source->current_status,
            'status_effective_at' => $source->status_effective_at,
            'verified_date' => $source->verified_date,
            'content_digest' => (string) $source->content_digest,
        ];
    }

    /** @param list<int> $versionIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function drgsByVersion(array $versionIds): array
    {
        if ($versionIds === []) {
            return [];
        }

        return DB::table('care_pathways.drg_mappings as mappings')
            ->join('care_pathways.drg_codebook_entries as codebook', 'codebook.drg_codebook_entry_id', '=', 'mappings.drg_codebook_entry_id')
            ->whereIn('mappings.pathway_version_id', $versionIds)
            ->orderBy('codebook.ms_drg')
            ->get([
                'mappings.pathway_version_id', 'mappings.mapping_role', 'mappings.ambiguity_note',
                'codebook.ms_drg', 'codebook.title', 'codebook.mdc', 'codebook.type_code', 'codebook.type_label',
            ])->groupBy('pathway_version_id')
            ->map(fn (Collection $rows): array => $rows->map(fn (object $row): array => [
                'ms_drg' => (string) $row->ms_drg,
                'title' => (string) $row->title,
                'mdc' => $row->mdc,
                'type_code' => $row->type_code,
                'type_label' => $row->type_label,
                'mapping_role' => (string) $row->mapping_role,
                'ambiguity_note' => $row->ambiguity_note,
                'requires_clinician_confirmation' => true,
            ])->all())->all();
    }

    /** @param list<int> $versionIds
     * @return array<int, int>
     */
    private function countsByVersion(string $table, string $column, array $versionIds): array
    {
        if ($versionIds === []) {
            return [];
        }

        return DB::table($table)
            ->whereIn($column, $versionIds)
            ->select($column, DB::raw('count(*) AS total'))
            ->groupBy($column)
            ->pluck('total', $column)
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }

    /** @param list<int> $versionIds
     * @return array<int, array{total: int, approved: int}>
     */
    private function sectionCountsByVersion(array $versionIds): array
    {
        if ($versionIds === []) {
            return [];
        }

        return DB::table('care_pathways.sections')
            ->whereIn('pathway_version_id', $versionIds)
            ->select('pathway_version_id')
            ->selectRaw('count(*) AS total')
            ->selectRaw("count(*) FILTER (WHERE review_state = 'approved' AND approved_text IS NOT NULL) AS approved")
            ->groupBy('pathway_version_id')
            ->get()->mapWithKeys(fn (object $row): array => [(int) $row->pathway_version_id => [
                'total' => (int) $row->total,
                'approved' => (int) $row->approved,
            ]])->all();
    }

    /** @param list<int> $versionIds
     * @return list<int>
     */
    private function noncurrentVersionIds(array $versionIds): array
    {
        if ($versionIds === []) {
            return [];
        }

        $claimVersions = DB::table('care_pathways.evidence_claims as claims')
            ->join('care_pathways.claim_sources as links', 'links.evidence_claim_id', '=', 'claims.evidence_claim_id')
            ->join('care_pathways.current_source_statuses as statuses', 'statuses.source_id', '=', 'links.source_id')
            ->whereIn('claims.pathway_version_id', $versionIds)
            ->where('statuses.supersession_state', '<>', 'current')
            ->pluck('claims.pathway_version_id');
        $sectionVersions = DB::table('care_pathways.sections as sections')
            ->join('care_pathways.section_sources as links', 'links.pathway_section_id', '=', 'sections.pathway_section_id')
            ->join('care_pathways.current_source_statuses as statuses', 'statuses.source_id', '=', 'links.source_id')
            ->whereIn('sections.pathway_version_id', $versionIds)
            ->where('statuses.supersession_state', '<>', 'current')
            ->pluck('sections.pathway_version_id');

        return $claimVersions->merge($sectionVersions)->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
    }

    /** @param list<int> $claimIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function claimSources(array $claimIds): array
    {
        if ($claimIds === []) {
            return [];
        }

        return DB::table('care_pathways.claim_sources as links')
            ->join('care_pathways.sources as sources', 'sources.source_id', '=', 'links.source_id')
            ->join('care_pathways.current_source_statuses as statuses', 'statuses.source_id', '=', 'sources.source_id')
            ->whereIn('links.evidence_claim_id', $claimIds)
            ->orderBy('sources.pmid')
            ->get([
                'links.evidence_claim_id', 'links.evidence_grade', 'links.applicability_note',
                'sources.source_uuid', 'sources.pmid', 'sources.title', 'sources.content_digest',
                'statuses.supersession_state',
            ])->groupBy('evidence_claim_id')
            ->map(fn (Collection $rows): array => $rows->map(fn (object $row): array => [
                'source_uuid' => (string) $row->source_uuid,
                'pmid' => $row->pmid,
                'title' => $row->title,
                'current_status' => (string) $row->supersession_state,
                'content_digest' => (string) $row->content_digest,
                'evidence_grade' => $row->evidence_grade,
                'applicability_note' => $row->applicability_note,
            ])->all())->all();
    }

    /** @param list<int> $sourceIds
     * @return array<int, int>
     */
    private function citationCountsBySource(array $sourceIds, int $releaseId): array
    {
        if ($sourceIds === []) {
            return [];
        }

        return DB::table('care_pathways.claim_sources as links')
            ->join('care_pathways.evidence_claims as claims', 'claims.evidence_claim_id', '=', 'links.evidence_claim_id')
            ->where('claims.catalog_release_id', $releaseId)
            ->whereIn('links.source_id', $sourceIds)
            ->select('links.source_id', DB::raw('count(DISTINCT links.evidence_claim_id) AS total'))
            ->groupBy('links.source_id')
            ->pluck('total', 'source_id')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function authoringDefinitions(int $versionId): array
    {
        return [
            'milestones' => DB::table('care_pathways.milestone_definitions')
                ->where('pathway_version_id', $versionId)
                ->orderBy('sequence')
                ->orderBy('stable_key')
                ->get([
                    'milestone_uuid', 'stable_key', 'title', 'phase', 'sequence', 'predecessor_keys',
                    'expected_range', 'applicability_ref', 'completion_evidence_ref', 'review_state',
                ])->map(fn (object $row): array => [
                    'milestone_uuid' => (string) $row->milestone_uuid,
                    'stable_key' => (string) $row->stable_key,
                    'title' => (string) $row->title,
                    'phase' => $row->phase,
                    'sequence' => $row->sequence === null ? null : (int) $row->sequence,
                    'predecessor_keys' => $this->jsonObject($row->predecessor_keys, []),
                    'expected_range' => $this->jsonObject($row->expected_range),
                    'applicability_ref' => $row->applicability_ref,
                    'completion_evidence_ref' => $row->completion_evidence_ref,
                    'review_state' => (string) $row->review_state,
                ])->all(),
            'activities' => DB::table('care_pathways.activity_definitions')
                ->where('pathway_version_id', $versionId)
                ->orderBy('stable_key')
                ->get([
                    'activity_uuid', 'stable_key', 'activity_type', 'title', 'performer_role', 'timing',
                    'preconditions', 'executable', 'fhir_canonical_ref', 'review_state',
                ])->map(fn (object $row): array => [
                    'activity_uuid' => (string) $row->activity_uuid,
                    'stable_key' => (string) $row->stable_key,
                    'activity_type' => (string) $row->activity_type,
                    'title' => (string) $row->title,
                    'performer_role' => $row->performer_role,
                    'timing' => $this->jsonObject($row->timing),
                    'preconditions' => $this->jsonObject($row->preconditions, []),
                    'executable' => (bool) $row->executable,
                    'fhir_canonical_ref' => $row->fhir_canonical_ref,
                    'review_state' => (string) $row->review_state,
                ])->all(),
            'goals' => DB::table('care_pathways.goal_definitions')
                ->where('pathway_version_id', $versionId)
                ->orderBy('stable_key')
                ->get([
                    'goal_uuid', 'stable_key', 'goal_code', 'goal_text', 'author_type', 'target',
                    'patient_visible_explanation', 'review_state',
                ])->map(fn (object $row): array => [
                    'goal_uuid' => (string) $row->goal_uuid,
                    'stable_key' => (string) $row->stable_key,
                    'goal_code' => $row->goal_code,
                    'goal_text' => (string) $row->goal_text,
                    'author_type' => (string) $row->author_type,
                    'target' => $this->jsonObject($row->target),
                    'patient_visible_explanation' => $row->patient_visible_explanation,
                    'review_state' => (string) $row->review_state,
                ])->all(),
            'education' => DB::table('care_pathways.education_definitions')
                ->where('pathway_version_id', $versionId)
                ->orderBy('stable_key')
                ->get([
                    'education_uuid', 'stable_key', 'audience', 'language_code', 'reading_level', 'title',
                    'approved_content', 'teach_back_prompt', 'required_reviewer_role', 'content_digest', 'review_state',
                ])->map(fn (object $row): array => [
                    'education_uuid' => (string) $row->education_uuid,
                    'stable_key' => (string) $row->stable_key,
                    'audience' => (string) $row->audience,
                    'language_code' => (string) $row->language_code,
                    'reading_level' => $row->reading_level,
                    'title' => (string) $row->title,
                    'approved_content' => $row->approved_content,
                    'teach_back_prompt' => $row->teach_back_prompt,
                    'required_reviewer_role' => $row->required_reviewer_role,
                    'content_digest' => $row->content_digest,
                    'review_state' => (string) $row->review_state,
                ])->all(),
        ];
    }

    /** @return array<mixed> */
    private function jsonObject(mixed $value, array $default = []): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    private function versionLedger(string $table, string $timestamp, array $filters): ?array
    {
        $release = $this->release($filters['release_uuid'] ?? null);
        if ($release === null) {
            return null;
        }

        $query = DB::table("care_pathways.{$table} as ledger")
            ->join('care_pathways.versions as versions', 'versions.pathway_version_id', '=', 'ledger.pathway_version_id')
            ->join('care_pathways.definitions as definitions', 'definitions.pathway_definition_id', '=', 'versions.pathway_definition_id')
            ->where('versions.catalog_release_id', $release->catalog_release_id)
            ->when(isset($filters['decision']), fn (Builder $query) => $query->where('ledger.decision', $filters['decision']))
            ->orderByDesc("ledger.{$timestamp}");
        $paginator = $query->paginate(
            (int) ($filters['per_page'] ?? 50),
            ['ledger.*', 'versions.pathway_version_uuid', 'versions.semantic_version', 'definitions.pathway_uuid', 'definitions.canonical_name'],
            'page',
            (int) ($filters['page'] ?? 1),
        );
        $items = collect($paginator->items())->map(function (object $row) use ($table): array {
            $base = [
                'pathway_uuid' => (string) $row->pathway_uuid,
                'pathway_name' => (string) $row->canonical_name,
                'version_uuid' => (string) $row->pathway_version_uuid,
                'semantic_version' => (string) $row->semantic_version,
                'decision' => (string) $row->decision,
            ];

            if ($table === 'reviews') {
                return $base + [
                    'review_uuid' => (string) $row->review_uuid,
                    'reviewer_role' => (string) $row->reviewer_role,
                    'review_scope' => (string) $row->review_scope,
                    'reason' => (string) $row->reason,
                    'issues' => json_decode((string) $row->issues, true, 512, JSON_THROW_ON_ERROR),
                    'reviewed_at' => $row->reviewed_at,
                ];
            }

            return $base + [
                'approval_uuid' => (string) $row->approval_uuid,
                'approval_type' => (string) $row->approval_type,
                'conditions' => $row->conditions,
                'effective_period' => ['start' => $row->effective_start, 'end' => $row->effective_end],
                'decided_at' => $row->decided_at,
            ];
        })->all();

        return $this->envelope($release, $items, ['pagination' => $this->pagination($paginator)]);
    }

    private function release(?string $releaseUuid): ?object
    {
        if (! config('care-pathways.governance_enabled', false)) {
            return null;
        }

        return DB::table('care_pathways.catalog_releases')
            ->when($releaseUuid !== null, fn (Builder $query) => $query->where('catalog_release_uuid', $releaseUuid))
            ->when($releaseUuid === null, fn (Builder $query) => $query->orderByDesc('catalog_release_id'))
            ->first();
    }

    /** @return array<string, mixed> */
    private function releaseDto(object $release): array
    {
        return [
            'catalog_release_uuid' => (string) $release->catalog_release_uuid,
            'dataset_key' => (string) $release->dataset_key,
            'grouper_version' => (string) $release->grouper_version,
            'grouper_effective_period' => [
                'start' => $release->grouper_effective_start,
                'end' => $release->grouper_effective_end,
            ],
            'state' => (string) $release->state,
            'clinical_signoff_complete' => (bool) $release->clinical_signoff_complete,
            'clinical_signoff_count' => (int) $release->clinical_signoff_count,
            'pathway_count' => (int) $release->pathway_count,
            'adopted_at' => $release->adopted_at,
            'activated_at' => $release->activated_at,
            'withdrawn_at' => $release->withdrawn_at,
        ];
    }

    /** @return array<string, mixed> */
    private function versionIdentity(object $version, ?string $sourceCutoff): array
    {
        return [
            'version_uuid' => (string) $version->pathway_version_uuid,
            'semantic_version' => (string) $version->semantic_version,
            'source_rank' => isset($version->source_rank) ? (int) $version->source_rank : null,
            'content_digest' => (string) $version->content_digest,
            'effective_period' => ['start' => $version->effective_start, 'end' => $version->effective_end],
            'source_cutoff_date' => $sourceCutoff,
            'exact_version' => true,
        ];
    }

    /** @param array<string, mixed> $data
     * @param  array<string, mixed>  $extraMeta
     * @return array<string, mixed>
     */
    private function envelope(object $release, array $data, array $extraMeta = []): array
    {
        return [
            'data' => $data,
            'meta' => array_merge([
                'schema' => 'care_pathway_governance.v1',
                'catalog_release_uuid' => (string) $release->catalog_release_uuid,
                'dataset_key' => (string) $release->dataset_key,
                'grouper_version' => (string) $release->grouper_version,
                'source_cutoff_date' => $this->sourceCutoff($release),
                'as_of' => now()->utc()->toIso8601String(),
                'clinical_approval_warning' => self::CLINICAL_APPROVAL_WARNING,
                'patient_serving' => false,
                'hummingbird_serving' => false,
                'eddy_serving' => false,
            ], $extraMeta),
        ];
    }

    private function sourceCutoff(object $release): ?string
    {
        return DB::table('care_pathways.catalog_release_sources as release_sources')
            ->join('care_pathways.sources as sources', 'sources.source_id', '=', 'release_sources.source_id')
            ->where('release_sources.catalog_release_id', $release->catalog_release_id)
            ->max('sources.verified_date');
    }

    /** @return array<string, bool> */
    private function servingFlags(): array
    {
        return [
            'approved_catalog' => (bool) config('care-pathways.catalog_enabled', false),
            'assignment' => (bool) config('care-pathways.assignment_enabled', false),
            'rounds' => (bool) config('care-pathways.rounds_enabled', false),
            'staff_mobile' => (bool) config('care-pathways.staff_mobile_enabled', false),
            'patient' => (bool) config('care-pathways.patient_enabled', false),
            'eddy_reference' => (bool) config('care-pathways.eddy_reference_enabled', false),
            'eddy_instance' => (bool) config('care-pathways.eddy_instance_enabled', false),
            'writeback' => (bool) config('care-pathways.writeback_enabled', false),
        ];
    }

    /** @return array<string, int> */
    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    private function evidenceStatus(string $value): string
    {
        return match ($value) {
            'verified' => (string) config('care-pathways.status_labels.evidence_verified'),
            'limitations' => (string) config('care-pathways.status_labels.evidence_limitations'),
        };
    }

    private function releaseDisposition(string $value): string
    {
        return match ($value) {
            'signoff' => (string) config('care-pathways.status_labels.signoff_queue'),
            'specialist_review' => (string) config('care-pathways.status_labels.specialist_review'),
            'redesign' => (string) config('care-pathways.status_labels.redesign'),
        };
    }
}
