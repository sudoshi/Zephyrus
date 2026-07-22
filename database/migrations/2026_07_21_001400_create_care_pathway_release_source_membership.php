<?php

/**
 * Preserve complete source-index membership independently from claim citation.
 * A verification release may intentionally contain sources that no claim cites;
 * those sources still belong to the release and must remain auditable.
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
CREATE TABLE IF NOT EXISTS care_pathways.catalog_release_sources (
    catalog_release_source_id bigserial PRIMARY KEY,
    release_source_uuid       uuid NOT NULL UNIQUE,
    catalog_release_id        bigint NOT NULL REFERENCES care_pathways.catalog_releases(catalog_release_id) ON DELETE RESTRICT,
    source_id                 bigint NOT NULL REFERENCES care_pathways.sources(source_id) ON DELETE RESTRICT,
    membership_type           text NOT NULL DEFAULT 'source_index'
                              CHECK (membership_type IN ('source_index')),
    source_content_digest     char(64) NOT NULL CHECK (source_content_digest ~ '^[0-9a-f]{64}$'),
    created_at                timestamptz NOT NULL DEFAULT now(),
    UNIQUE (catalog_release_id, source_id)
);

CREATE INDEX IF NOT EXISTS idx_care_pathway_release_sources_source
    ON care_pathways.catalog_release_sources(source_id, catalog_release_id);

DROP TRIGGER IF EXISTS care_pathway_release_sources_append_only
    ON care_pathways.catalog_release_sources;
CREATE TRIGGER care_pathway_release_sources_append_only
BEFORE UPDATE OR DELETE ON care_pathways.catalog_release_sources
FOR EACH ROW EXECUTE FUNCTION care_pathways.reject_append_only_mutation();

COMMENT ON TABLE care_pathways.catalog_release_sources IS
    'Complete append-only source-index membership for a catalog release, independent of whether a source is cited by an evidence claim.';
SQL);
    }

    public function down(): void
    {
        if (! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared('DROP TABLE IF EXISTS care_pathways.catalog_release_sources;');
    }
};
