<?php

/**
 * Extend the governed patient projection kinds with `pathway_events`.
 *
 * The "My Path" pathway spine adds a released, patient-language pathway-events
 * timeline projection alongside today/pathway/care_team. Only the projection_kind
 * check constraints on the kernel tables are widened; the release boundary,
 * provenance, freshness, and append-only guarantees are unchanged. This is a
 * forward migration because the original kernel migration (000200) is already
 * applied to the canonical backend.
 */

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    use SafeMigration;

    /** @var list<string> */
    private const TABLES = [
        'source_projection_cursors',
        'source_projection_failures',
        'encounter_projections',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->applyKinds(['today', 'pathway', 'pathway_events', 'discharge_readiness', 'care_team']);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->applyKinds(['today', 'pathway', 'care_team']);
    }

    /** @param  list<string>  $kinds */
    private function applyKinds(array $kinds): void
    {
        $inList = "'".implode("', '", $kinds)."'";

        foreach (self::TABLES as $table) {
            $constraint = "{$table}_projection_kind_check";
            DB::unprepared(<<<SQL
ALTER TABLE patient_experience.{$table}
    DROP CONSTRAINT IF EXISTS {$constraint};
ALTER TABLE patient_experience.{$table}
    ADD CONSTRAINT {$constraint}
        CHECK (projection_kind IN ({$inList}));
SQL);
        }
    }
};
