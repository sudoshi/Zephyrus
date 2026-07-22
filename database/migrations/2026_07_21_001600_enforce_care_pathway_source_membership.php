<?php

/**
 * Enforce release-source membership at every evidence citation boundary and
 * verify that membership preserves the immutable source content digest.
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
CREATE OR REPLACE FUNCTION care_pathways.enforce_release_source_digest()
RETURNS trigger AS $$
DECLARE
    canonical_digest char(64);
BEGIN
    SELECT sources.content_digest
    INTO canonical_digest
    FROM care_pathways.sources sources
    WHERE sources.source_id = NEW.source_id;

    IF canonical_digest IS NULL OR canonical_digest <> NEW.source_content_digest THEN
        RAISE EXCEPTION 'catalog release source digest does not match immutable source %', NEW.source_id;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION care_pathways.enforce_citation_source_membership()
RETURNS trigger AS $$
DECLARE
    release_id bigint;
BEGIN
    IF NEW.source_id IS NULL THEN
        RAISE EXCEPTION 'care pathway evidence citations require a canonical source';
    END IF;

    IF TG_TABLE_NAME = 'claim_sources' THEN
        SELECT claims.catalog_release_id
        INTO release_id
        FROM care_pathways.evidence_claims claims
        WHERE claims.evidence_claim_id = NEW.evidence_claim_id;
    ELSIF TG_TABLE_NAME = 'section_sources' THEN
        SELECT versions.catalog_release_id
        INTO release_id
        FROM care_pathways.sections sections
        JOIN care_pathways.versions versions
          ON versions.pathway_version_id = sections.pathway_version_id
        WHERE sections.pathway_section_id = NEW.pathway_section_id;
    ELSE
        RAISE EXCEPTION 'unsupported citation table %', TG_TABLE_NAME;
    END IF;

    IF release_id IS NULL OR NOT EXISTS (
        SELECT 1
        FROM care_pathways.catalog_release_sources release_sources
        WHERE release_sources.catalog_release_id = release_id
          AND release_sources.source_id = NEW.source_id
    ) THEN
        RAISE EXCEPTION 'source % does not belong to the citation catalog release', NEW.source_id;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS care_pathway_release_source_digest_guard
    ON care_pathways.catalog_release_sources;
CREATE TRIGGER care_pathway_release_source_digest_guard
BEFORE INSERT ON care_pathways.catalog_release_sources
FOR EACH ROW EXECUTE FUNCTION care_pathways.enforce_release_source_digest();

DROP TRIGGER IF EXISTS care_pathway_claim_source_membership_guard
    ON care_pathways.claim_sources;
CREATE TRIGGER care_pathway_claim_source_membership_guard
BEFORE INSERT ON care_pathways.claim_sources
FOR EACH ROW EXECUTE FUNCTION care_pathways.enforce_citation_source_membership();

DROP TRIGGER IF EXISTS care_pathway_section_source_membership_guard
    ON care_pathways.section_sources;
CREATE TRIGGER care_pathway_section_source_membership_guard
BEFORE INSERT ON care_pathways.section_sources
FOR EACH ROW EXECUTE FUNCTION care_pathways.enforce_citation_source_membership();
SQL);
    }

    public function down(): void
    {
        if (! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS care_pathway_section_source_membership_guard
    ON care_pathways.section_sources;
DROP TRIGGER IF EXISTS care_pathway_claim_source_membership_guard
    ON care_pathways.claim_sources;
DROP TRIGGER IF EXISTS care_pathway_release_source_digest_guard
    ON care_pathways.catalog_release_sources;
DROP FUNCTION IF EXISTS care_pathways.enforce_citation_source_membership();
DROP FUNCTION IF EXISTS care_pathways.enforce_release_source_digest();
SQL);
    }
};
