<?php

/**
 * Restores every projection-kernel guard as an individually executed DDL
 * statement. The original kernel and review/release migrations establish the
 * tables and functions; this migration makes the deployment state explicit
 * and also repairs installations that recorded those migrations without every
 * trigger surviving schema setup.
 */

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $triggers = [
            [
                'name' => 'patient_release_policy_versions_append_only',
                'table' => 'patient_experience.release_policy_versions',
                'definition' => 'BEFORE UPDATE OR DELETE ON patient_experience.release_policy_versions FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_projection_kernel_mutation()',
            ],
            [
                'name' => 'patient_projection_cursors_append_only',
                'table' => 'patient_experience.source_projection_cursors',
                'definition' => 'BEFORE UPDATE OR DELETE ON patient_experience.source_projection_cursors FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_projection_kernel_mutation()',
            ],
            [
                'name' => 'patient_projection_failures_append_only',
                'table' => 'patient_experience.source_projection_failures',
                'definition' => 'BEFORE UPDATE OR DELETE ON patient_experience.source_projection_failures FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_projection_kernel_mutation()',
            ],
            [
                'name' => 'patient_encounter_projections_append_only',
                'table' => 'patient_experience.encounter_projections',
                'definition' => 'BEFORE UPDATE OR DELETE ON patient_experience.encounter_projections FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_projection_kernel_mutation()',
            ],
            [
                'name' => 'patient_content_actions_append_only',
                'table' => 'patient_experience.content_actions',
                'definition' => 'BEFORE UPDATE OR DELETE ON patient_experience.content_actions FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_projection_kernel_mutation()',
            ],
            [
                'name' => 'patient_released_projection_outbox',
                'table' => 'patient_experience.encounter_projections',
                'definition' => 'AFTER INSERT ON patient_experience.encounter_projections FOR EACH ROW EXECUTE FUNCTION patient_experience.enqueue_released_projection_outbox()',
            ],
            [
                'name' => 'patient_pathway_projection_review_valid',
                'table' => 'patient_experience.pathway_projection_reviews',
                'definition' => 'BEFORE INSERT OR UPDATE ON patient_experience.pathway_projection_reviews FOR EACH ROW EXECUTE FUNCTION patient_experience.validate_pathway_projection_review()',
            ],
            [
                'name' => 'patient_pathway_projection_release_execution_valid',
                'table' => 'patient_experience.pathway_projection_release_executions',
                'definition' => 'BEFORE INSERT OR UPDATE ON patient_experience.pathway_projection_release_executions FOR EACH ROW EXECUTE FUNCTION patient_experience.validate_pathway_projection_release_execution()',
            ],
            [
                'name' => 'patient_pathway_projection_reviews_append_only',
                'table' => 'patient_experience.pathway_projection_reviews',
                'definition' => 'BEFORE UPDATE OR DELETE ON patient_experience.pathway_projection_reviews FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_projection_kernel_mutation()',
            ],
            [
                'name' => 'patient_pathway_projection_release_executions_append_only',
                'table' => 'patient_experience.pathway_projection_release_executions',
                'definition' => 'BEFORE UPDATE OR DELETE ON patient_experience.pathway_projection_release_executions FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_projection_kernel_mutation()',
            ],
        ];

        foreach ($triggers as $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger['name']} ON {$trigger['table']}");
            DB::statement("CREATE TRIGGER {$trigger['name']} {$trigger['definition']}");
        }

        DB::statement(
            'DROP TRIGGER IF EXISTS patient_pathway_release_execution_required ON patient_experience.encounter_projections',
        );
        DB::statement(
            'CREATE CONSTRAINT TRIGGER patient_pathway_release_execution_required '
            .'AFTER INSERT ON patient_experience.encounter_projections '
            .'DEFERRABLE INITIALLY DEFERRED FOR EACH ROW '
            .'EXECUTE FUNCTION patient_experience.require_pathway_release_execution()',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! $this->isLocalEnvironment()) {
            return;
        }

        DB::statement(
            'DROP TRIGGER IF EXISTS patient_pathway_release_execution_required ON patient_experience.encounter_projections',
        );
    }
};
