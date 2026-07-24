<?php

/**
 * Prevent two active versions of one care pathway definition from covering the
 * same effective period. A definition holds at most one version per catalog
 * release, so overlapping active periods can only arise across releases; without
 * this guard a serving read could not determine which approved guidance applies
 * on a given date. NULL bounds are treated as open-ended (-infinity / infinity).
 *
 * This is a forward-only, additive trigger. The production release is inactive
 * with zero active versions, so it is a no-op against existing data.
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
CREATE OR REPLACE FUNCTION care_pathways.enforce_active_version_non_overlap()
RETURNS trigger AS $$
BEGIN
    IF NEW.activation_status = 'active' AND EXISTS (
        SELECT 1
        FROM care_pathways.versions other
        WHERE other.pathway_definition_id = NEW.pathway_definition_id
          AND other.pathway_version_id IS DISTINCT FROM NEW.pathway_version_id
          AND other.activation_status = 'active'
          AND daterange(
                  COALESCE(other.effective_start, '-infinity'::date),
                  COALESCE(other.effective_end, 'infinity'::date),
                  '[]'
              ) && daterange(
                  COALESCE(NEW.effective_start, '-infinity'::date),
                  COALESCE(NEW.effective_end, 'infinity'::date),
                  '[]'
              )
    ) THEN
        RAISE EXCEPTION 'an active care pathway version already covers an overlapping effective period for definition %', NEW.pathway_definition_id
            USING ERRCODE = 'exclusion_violation';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS care_pathway_active_version_non_overlap
    ON care_pathways.versions;
CREATE TRIGGER care_pathway_active_version_non_overlap
BEFORE INSERT OR UPDATE ON care_pathways.versions
FOR EACH ROW EXECUTE FUNCTION care_pathways.enforce_active_version_non_overlap();
SQL);
    }

    public function down(): void
    {
        if (! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS care_pathway_active_version_non_overlap
    ON care_pathways.versions;
DROP FUNCTION IF EXISTS care_pathways.enforce_active_version_non_overlap();
SQL);
    }
};
