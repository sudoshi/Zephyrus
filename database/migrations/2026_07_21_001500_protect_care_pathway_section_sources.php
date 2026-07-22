<?php

/**
 * Approved section citations are evidence-selection facts. Protect them with
 * the same append-only semantics as claim-source links.
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
DROP TRIGGER IF EXISTS care_pathway_section_sources_append_only
    ON care_pathways.section_sources;
CREATE TRIGGER care_pathway_section_sources_append_only
BEFORE UPDATE OR DELETE ON care_pathways.section_sources
FOR EACH ROW EXECUTE FUNCTION care_pathways.reject_append_only_mutation();
SQL);
    }

    public function down(): void
    {
        if (! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS care_pathway_section_sources_append_only
    ON care_pathways.section_sources;
SQL);
    }
};
