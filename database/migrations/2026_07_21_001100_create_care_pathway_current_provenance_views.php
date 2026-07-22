<?php

/**
 * Stable read views for normalized, digest-addressed provenance. Historical
 * generic facts from the initial canonical adoption remain append-only but are
 * intentionally excluded from these current views.
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
CREATE OR REPLACE VIEW care_pathways.current_source_enrichments AS
SELECT enrichments.*
FROM care_pathways.source_enrichments enrichments
WHERE enrichments.resolution_class IS NOT NULL;

CREATE OR REPLACE VIEW care_pathways.current_completeness_resolutions AS
SELECT resolutions.*
FROM care_pathways.completeness_resolutions resolutions
WHERE resolutions.resolution_digest IS NOT NULL;

COMMENT ON VIEW care_pathways.current_source_enrichments IS
    'Normalized field-level enrichment facts. Use this view for review/read services; historical generic adoption facts remain in the append-only base table.';

COMMENT ON VIEW care_pathways.current_completeness_resolutions IS
    'Digest-addressed completeness controls with source counts, classification, corrective action, evidence, and residual unknown count.';
SQL);
    }

    public function down(): void
    {
        if (! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP VIEW IF EXISTS care_pathways.current_completeness_resolutions;
DROP VIEW IF EXISTS care_pathways.current_source_enrichments;
SQL);
    }
};
