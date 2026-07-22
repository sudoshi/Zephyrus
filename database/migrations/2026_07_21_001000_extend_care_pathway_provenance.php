<?php

/**
 * Preserve field-level source enrichment and completeness semantics that are
 * present in the verified raw release. Existing append-only facts remain in
 * place; the exact-rerun repair path appends normalized facts with digests.
 */

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE care_pathways.source_enrichments
    ADD COLUMN IF NOT EXISTS resolution_class text,
    ADD COLUMN IF NOT EXISTS resolution_note text,
    ADD COLUMN IF NOT EXISTS authoritative_source_url text,
    ADD COLUMN IF NOT EXISTS secondary_source_url text,
    ADD COLUMN IF NOT EXISTS source_record jsonb NOT NULL DEFAULT '{}'::jsonb;

ALTER TABLE care_pathways.completeness_resolutions
    ADD COLUMN IF NOT EXISTS source_blank_count integer,
    ADD COLUMN IF NOT EXISTS residual_unknown_count integer,
    ADD COLUMN IF NOT EXISTS source_classification text,
    ADD COLUMN IF NOT EXISTS corrective_action text,
    ADD COLUMN IF NOT EXISTS raw_record jsonb NOT NULL DEFAULT '{}'::jsonb,
    ADD COLUMN IF NOT EXISTS resolution_digest char(64),
    ADD COLUMN IF NOT EXISTS audited_at timestamptz;

ALTER TABLE care_pathways.completeness_resolutions
    DROP CONSTRAINT IF EXISTS completeness_resolutions_resolution_type_check;
ALTER TABLE care_pathways.completeness_resolutions
    ADD CONSTRAINT completeness_resolutions_resolution_type_check
    CHECK (resolution_type IN (
        'complete',
        'enriched',
        'not_listed',
        'not_applicable',
        'optional_not_recorded',
        'source_blank_preserved',
        'unresolved'
    ));

ALTER TABLE care_pathways.completeness_resolutions
    DROP CONSTRAINT IF EXISTS care_pathway_completeness_source_blank_count_chk;
ALTER TABLE care_pathways.completeness_resolutions
    ADD CONSTRAINT care_pathway_completeness_source_blank_count_chk
    CHECK (source_blank_count IS NULL OR source_blank_count >= 0);

ALTER TABLE care_pathways.completeness_resolutions
    DROP CONSTRAINT IF EXISTS care_pathway_completeness_residual_count_chk;
ALTER TABLE care_pathways.completeness_resolutions
    ADD CONSTRAINT care_pathway_completeness_residual_count_chk
    CHECK (residual_unknown_count IS NULL OR residual_unknown_count >= 0);

ALTER TABLE care_pathways.completeness_resolutions
    DROP CONSTRAINT IF EXISTS care_pathway_completeness_raw_record_json_chk;
ALTER TABLE care_pathways.completeness_resolutions
    ADD CONSTRAINT care_pathway_completeness_raw_record_json_chk
    CHECK (jsonb_typeof(raw_record) = 'object');

ALTER TABLE care_pathways.completeness_resolutions
    DROP CONSTRAINT IF EXISTS care_pathway_completeness_digest_chk;
ALTER TABLE care_pathways.completeness_resolutions
    ADD CONSTRAINT care_pathway_completeness_digest_chk
    CHECK (resolution_digest IS NULL OR resolution_digest ~ '^[0-9a-f]{64}$');

ALTER TABLE care_pathways.source_enrichments
    DROP CONSTRAINT IF EXISTS care_pathway_source_enrichment_record_json_chk;
ALTER TABLE care_pathways.source_enrichments
    ADD CONSTRAINT care_pathway_source_enrichment_record_json_chk
    CHECK (jsonb_typeof(source_record) = 'object');

CREATE UNIQUE INDEX IF NOT EXISTS uq_care_pathway_completeness_digest
    ON care_pathways.completeness_resolutions(catalog_release_id, resolution_digest)
    WHERE resolution_digest IS NOT NULL;
SQL);
    }

    public function down(): void
    {
        // Forward repair only. Append-only provenance must never be discarded.
    }
};
