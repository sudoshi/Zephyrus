<?php

/**
 * Add the patient-language `rounds_summary` projection kind.
 *
 * This is deliberately a governed patient projection, not a view into the
 * staff virtual-rounds workspace. It can contain only content explicitly
 * released under the patient disclosure policy; internal contributions,
 * assignments, routing, and clinical deliberation remain staff-only.
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

        $this->applyKinds([
            'today',
            'pathway',
            'pathway_events',
            'discharge_readiness',
            'rounds_summary',
            'care_team',
        ]);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->applyKinds([
            'today',
            'pathway',
            'pathway_events',
            'discharge_readiness',
            'care_team',
        ]);
    }

    /** @param list<string> $kinds */
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
