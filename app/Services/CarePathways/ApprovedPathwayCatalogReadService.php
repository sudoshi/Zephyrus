<?php

namespace App\Services\CarePathways;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ApprovedPathwayCatalogReadService
{
    /**
     * Return one exact, active, institutionally approved staff version.
     * Raw snapshots and source prose never leave this boundary.
     *
     * @return array<string, mixed>|null
     */
    public function findVersion(string $versionUuid, string $audience = 'staff_workflow', ?string $asOf = null): ?array
    {
        if (! config('care-pathways.catalog_enabled')) {
            return null;
        }

        $this->assertStaffAudience($audience);
        $version = $this->approvedVersions($asOf)
            ->where('versions.pathway_version_uuid', $versionUuid)
            ->first();

        if ($version === null) {
            return null;
        }

        $projection = $this->project($version, $audience);

        return $projection['sections'] === [] ? null : $projection;
    }

    /**
     * Return approved candidate definitions for a DRG reconciliation signal.
     * A caller must still apply the encounter matcher and clinician-confirmation
     * workflow; this method never returns an assignment decision.
     *
     * @return list<array<string, mixed>>
     */
    public function candidatesForDrg(string $msDrg, string $audience = 'staff_workflow', ?string $asOf = null): array
    {
        if (! config('care-pathways.catalog_enabled')) {
            return [];
        }

        $this->assertStaffAudience($audience);
        $msDrg = str_pad(trim($msDrg), 3, '0', STR_PAD_LEFT);
        if (! preg_match('/^[0-9]{3}$/', $msDrg)) {
            throw new InvalidArgumentException('MS-DRG must be a three-digit code.');
        }

        return $this->approvedVersions($asOf)
            ->join('care_pathways.drg_mappings as mappings', 'mappings.pathway_version_id', '=', 'versions.pathway_version_id')
            ->join('care_pathways.drg_codebook_entries as codebook', 'codebook.drg_codebook_entry_id', '=', 'mappings.drg_codebook_entry_id')
            ->addSelect('mappings.mapping_role')
            ->where('codebook.ms_drg', $msDrg)
            ->orderBy('versions.source_rank')
            ->get()
            ->map(fn (object $version): array => $this->project($version, $audience) + [
                'match' => [
                    'type' => 'drg_candidate',
                    'ms_drg' => $msDrg,
                    'mapping_role' => (string) $version->mapping_role,
                    'requires_clinician_confirmation' => true,
                ],
            ])
            ->filter(fn (array $candidate): bool => $candidate['sections'] !== [])
            ->values()
            ->all();
    }

    private function approvedVersions(?string $asOf): Builder
    {
        $asOf ??= now()->toDateString();

        return DB::table('care_pathways.versions as versions')
            ->join('care_pathways.definitions as definitions', 'definitions.pathway_definition_id', '=', 'versions.pathway_definition_id')
            ->join('care_pathways.catalog_releases as releases', 'releases.catalog_release_id', '=', 'versions.catalog_release_id')
            ->where('releases.state', 'active')
            ->where('releases.clinical_signoff_complete', true)
            ->where('definitions.lifecycle_state', 'active')
            ->where('versions.institutional_approval_status', 'approved')
            ->where('versions.activation_status', 'active')
            ->where(function (Builder $query) use ($asOf): void {
                $query->whereNull('versions.effective_start')
                    ->orWhereDate('versions.effective_start', '<=', $asOf);
            })
            ->where(function (Builder $query) use ($asOf): void {
                $query->whereNull('versions.effective_end')
                    ->orWhereDate('versions.effective_end', '>=', $asOf);
            })
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('care_pathways.evidence_claims as claims')
                    ->join('care_pathways.claim_sources as claim_sources', 'claim_sources.evidence_claim_id', '=', 'claims.evidence_claim_id')
                    ->join('care_pathways.sources as sources', 'sources.source_id', '=', 'claim_sources.source_id')
                    ->join('care_pathways.current_source_statuses as source_statuses', 'source_statuses.source_id', '=', 'sources.source_id')
                    ->whereColumn('claims.pathway_version_id', 'versions.pathway_version_id')
                    ->where('source_statuses.supersession_state', '<>', 'current');
            })
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('care_pathways.sections as cited_sections')
                    ->join('care_pathways.section_sources as section_sources', 'section_sources.pathway_section_id', '=', 'cited_sections.pathway_section_id')
                    ->join('care_pathways.current_source_statuses as source_statuses', 'source_statuses.source_id', '=', 'section_sources.source_id')
                    ->whereColumn('cited_sections.pathway_version_id', 'versions.pathway_version_id')
                    ->where('source_statuses.supersession_state', '<>', 'current');
            })
            ->select([
                'versions.pathway_version_id',
                'versions.pathway_version_uuid',
                'versions.semantic_version',
                'versions.source_rank',
                'versions.evidence_status',
                'versions.verification_confidence',
                'versions.source_specificity',
                'versions.release_disposition',
                'versions.effective_start',
                'versions.effective_end',
                'versions.content_digest',
                'definitions.pathway_uuid',
                'definitions.pathway_key',
                'definitions.canonical_name',
                'definitions.mdc_label',
                'definitions.care_type',
                'definitions.service_line_code',
                'releases.catalog_release_uuid',
                'releases.grouper_version',
                DB::raw('(SELECT max(source_rows.verified_date)
                    FROM care_pathways.catalog_release_sources release_sources
                    JOIN care_pathways.sources source_rows ON source_rows.source_id = release_sources.source_id
                    WHERE release_sources.catalog_release_id = releases.catalog_release_id) AS source_cutoff_date'),
            ]);
    }

    /** @return array<string, mixed> */
    private function project(object $version, string $audience): array
    {
        $sections = DB::table('care_pathways.sections')
            ->where('pathway_version_id', $version->pathway_version_id)
            ->where('audience', $audience)
            ->where('review_state', 'approved')
            ->whereIn('content_mode', ['approved', 'released'])
            ->whereNotNull('approved_text')
            ->orderBy('section_code')
            ->get([
                'section_uuid',
                'section_code',
                'language_code',
                'approved_text',
                'approved_digest',
            ])
            ->map(fn (object $section): array => [
                'section_uuid' => (string) $section->section_uuid,
                'section_code' => (string) $section->section_code,
                'language_code' => (string) $section->language_code,
                'text' => (string) $section->approved_text,
                'content_digest' => (string) $section->approved_digest,
            ])
            ->all();

        return [
            'pathway_uuid' => (string) $version->pathway_uuid,
            'pathway_key' => (string) $version->pathway_key,
            'name' => (string) $version->canonical_name,
            'mdc' => $version->mdc_label,
            'care_type' => $version->care_type,
            'service_line_code' => $version->service_line_code,
            'version_uuid' => (string) $version->pathway_version_uuid,
            'semantic_version' => (string) $version->semantic_version,
            'exact_version' => true,
            'source_rank' => (int) $version->source_rank,
            'catalog_release_uuid' => (string) $version->catalog_release_uuid,
            'grouper_version' => (string) $version->grouper_version,
            'source_cutoff_date' => $version->source_cutoff_date,
            'evidence' => [
                'status' => (string) $version->evidence_status,
                'confidence' => $version->verification_confidence,
                'source_specificity' => $version->source_specificity,
                'release_disposition' => (string) $version->release_disposition,
            ],
            'effective_period' => [
                'start' => $version->effective_start,
                'end' => $version->effective_end,
            ],
            'content_digest' => (string) $version->content_digest,
            'audience' => $audience,
            'sections' => $sections,
        ];
    }

    private function assertStaffAudience(string $audience): void
    {
        if (! in_array($audience, ['staff_reference', 'staff_workflow'], true)) {
            throw new InvalidArgumentException('The approved catalog read service is staff-only; patient content must use released patient projections.');
        }
    }
}
