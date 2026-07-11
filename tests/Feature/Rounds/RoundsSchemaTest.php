<?php

namespace Tests\Feature\Rounds;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoundsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_rounds_schema_tables_exist(): void
    {
        foreach ([
            'rounds.templates', 'rounds.runs', 'rounds.patients', 'rounds.participants',
            'rounds.contributions', 'rounds.questions', 'rounds.tasks', 'rounds.events',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table {$table}");
        }
    }

    public function test_rounds_patients_has_workflow_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('rounds.patients', [
            'round_patient_uuid', 'run_id', 'encounter_ref', 'prod_encounter_id', 'patient_ref',
            'status', 'priority_band', 'priority_reasons', 'queue_position',
            'eta_window_start', 'eta_window_end', 'version',
        ]));
    }

    public function test_contribution_partial_unique_blocks_duplicate_submitted_rows(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        \Illuminate\Support\Facades\DB::statement(<<<'SQL'
            INSERT INTO rounds.templates (template_uuid, name) VALUES (gen_random_uuid(), 't');
            INSERT INTO rounds.runs (run_uuid, template_id, scope_type, scope_key)
                VALUES (gen_random_uuid(), (SELECT template_id FROM rounds.templates LIMIT 1), 'unit', '1');
            INSERT INTO rounds.patients (round_patient_uuid, run_id, encounter_ref, patient_ref)
                VALUES (gen_random_uuid(), (SELECT run_id FROM rounds.runs LIMIT 1), 'e1', 'p1');
            INSERT INTO rounds.contributions (contribution_uuid, round_patient_id, author_user_id, author_role, section_code, status)
                VALUES (gen_random_uuid(), (SELECT round_patient_id FROM rounds.patients LIMIT 1), 1, 'attending', 'clinical_plan', 'submitted');
            INSERT INTO rounds.contributions (contribution_uuid, round_patient_id, author_user_id, author_role, section_code, status)
                VALUES (gen_random_uuid(), (SELECT round_patient_id FROM rounds.patients LIMIT 1), 1, 'attending', 'clinical_plan', 'submitted');
        SQL);
    }
}
