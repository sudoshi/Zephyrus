<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS hosp_ref');

        if (! Schema::hasColumn('prod.barriers', 'demo_owner')) {
            Schema::table('prod.barriers', function (Blueprint $table): void {
                $table->string('demo_owner', 120)->nullable()->index('barriers_demo_owner_idx');
            });
        }

        if (! Schema::hasTable('hosp_ref.rad_modalities')) {
            Schema::create('hosp_ref.rad_modalities', function (Blueprint $table): void {
                $table->string('code', 16)->primary();
                $table->string('label', 120);
                $table->string('modality_group', 40);
                $table->boolean('is_cross_sectional')->default(false);
                $table->boolean('supports_portable')->default(false);
                $table->boolean('contrast_screening_applicable')->default(false);
                $table->boolean('is_active')->default(true);
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestampsTz();

                $table->index(['is_active', 'modality_group'], 'rad_modalities_active_group_idx');
            });
        }

        if (! Schema::hasTable('hosp_ref.rad_subspecialties')) {
            Schema::create('hosp_ref.rad_subspecialties', function (Blueprint $table): void {
                $table->string('code', 40)->primary();
                $table->string('label', 120);
                $table->boolean('is_active')->default(true);
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestampsTz();

                $table->index(['is_active', 'label'], 'rad_subspecialties_active_label_idx');
            });
        }

        if (! Schema::hasTable('prod.rad_scanners')) {
            Schema::create('prod.rad_scanners', function (Blueprint $table): void {
                $table->id('rad_scanner_id');
                $table->uuid('scanner_uuid')->unique();
                $table->foreignId('source_id')->constrained('integration.sources', 'source_id')->restrictOnDelete();
                $table->string('source_scanner_key', 190);
                $table->unsignedBigInteger('facility_id')->nullable();
                $table->foreignId('unit_id')->nullable()->constrained('prod.units', 'unit_id')->nullOnDelete();
                $table->foreignId('location_id')->nullable()->constrained('prod.locations', 'location_id')->nullOnDelete();
                $table->string('modality_code', 16);
                $table->string('label', 160);
                $table->unsignedSmallInteger('capacity')->default(1);
                $table->string('status', 24)->default('operational');
                $table->boolean('portable_capable')->default(false);
                $table->string('demo_owner', 120)->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestampsTz();

                $table->unique(['source_id', 'source_scanner_key'], 'rad_scanners_source_identity_unique');
                $table->index(['facility_id', 'modality_code', 'status'], 'rad_scanners_facility_modality_idx');
                $table->index(['unit_id', 'location_id', 'status'], 'rad_scanners_location_status_idx');
                $table->index(['demo_owner', 'status'], 'rad_scanners_demo_owner_idx');
            });
        }

        if (! Schema::hasTable('prod.rad_scanner_downtimes')) {
            Schema::create('prod.rad_scanner_downtimes', function (Blueprint $table): void {
                $table->id('rad_scanner_downtime_id');
                $table->uuid('downtime_uuid')->unique();
                $table->foreignId('rad_scanner_id')->constrained('prod.rad_scanners', 'rad_scanner_id')->cascadeOnDelete();
                $table->foreignId('source_id')->constrained('integration.sources', 'source_id')->restrictOnDelete();
                $table->string('source_downtime_key', 190);
                $table->string('status', 24)->default('scheduled');
                $table->string('reason_code', 80);
                $table->string('label', 190)->nullable();
                $table->timestampTz('starts_at');
                $table->timestampTz('ends_at')->nullable();
                $table->string('demo_owner', 120)->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestampsTz();

                $table->unique(['source_id', 'source_downtime_key'], 'rad_scanner_downtimes_source_identity_unique');
                $table->index(['rad_scanner_id', 'starts_at', 'ends_at'], 'rad_scanner_downtimes_window_idx');
                $table->index(['status', 'starts_at'], 'rad_scanner_downtimes_status_idx');
                $table->index(['demo_owner', 'starts_at'], 'rad_scanner_downtimes_demo_owner_idx');
            });
        }

        if (! Schema::hasTable('prod.rad_exams')) {
            Schema::create('prod.rad_exams', function (Blueprint $table): void {
                $table->id('rad_exam_id');
                $table->uuid('exam_uuid')->unique();
                $table->foreignId('ancillary_order_id')->unique()->constrained('prod.ancillary_orders', 'ancillary_order_id')->cascadeOnDelete();
                $table->foreignId('source_id')->constrained('integration.sources', 'source_id')->restrictOnDelete();
                $table->string('source_exam_key', 190);
                $table->foreignId('encounter_id')->nullable()->constrained('prod.encounters', 'encounter_id')->nullOnDelete();
                $table->string('modality_code', 16)->nullable();
                $table->string('body_region', 80)->nullable();
                $table->string('subspecialty_code', 40)->nullable();
                $table->string('procedure_code', 80)->nullable();
                $table->string('procedure_label', 190)->nullable();
                $table->string('protocol', 190)->nullable();
                $table->string('contrast_status', 32)->default('unknown');
                $table->boolean('is_portable')->default(false);
                $table->boolean('is_ir')->default(false);
                $table->foreignId('rad_scanner_id')->nullable()->constrained('prod.rad_scanners', 'rad_scanner_id')->nullOnDelete();
                $table->timestampTz('scheduled_start_at')->nullable();
                $table->timestampTz('scheduled_end_at')->nullable();
                $table->timestampTz('started_at')->nullable();
                $table->timestampTz('completed_at')->nullable();
                $table->timestampTz('cancelled_at')->nullable();
                $table->string('status', 24)->default('ordered');
                $table->jsonb('preparation')->default(DB::raw("'{}'::jsonb"));
                $table->string('demo_owner', 120)->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestampsTz();

                $table->unique(['source_id', 'source_exam_key'], 'rad_exams_source_identity_unique');
                $table->index(['status', 'modality_code', 'scheduled_start_at'], 'rad_exams_open_worklist_idx');
                $table->index(['rad_scanner_id', 'scheduled_start_at', 'scheduled_end_at'], 'rad_exams_scanner_day_idx');
                $table->index(['encounter_id', 'status'], 'rad_exams_encounter_status_idx');
                $table->index(['status', 'completed_at'], 'rad_exams_unread_backlog_idx');
                $table->index(['demo_owner', 'scheduled_start_at'], 'rad_exams_demo_owner_idx');
            });
        }

        if (! Schema::hasTable('prod.rad_reads')) {
            Schema::create('prod.rad_reads', function (Blueprint $table): void {
                $table->id('rad_read_id');
                $table->uuid('read_uuid')->unique();
                $table->foreignId('rad_exam_id')->constrained('prod.rad_exams', 'rad_exam_id')->cascadeOnDelete();
                $table->foreignId('source_id')->constrained('integration.sources', 'source_id')->restrictOnDelete();
                $table->string('source_read_key', 190);
                $table->string('source_report_version', 80)->nullable();
                $table->string('status', 24);
                $table->string('radiologist_ref', 190)->nullable();
                $table->string('subspecialty_code', 40)->nullable();
                $table->boolean('is_teleradiology')->default(false);
                $table->timestampTz('preliminary_at')->nullable();
                $table->timestampTz('final_at')->nullable();
                $table->timestampTz('corrected_at')->nullable();
                $table->foreignId('parent_rad_read_id')->nullable()->constrained('prod.rad_reads', 'rad_read_id')->nullOnDelete();
                $table->string('demo_owner', 120)->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestampsTz();

                $table->unique(['source_id', 'source_read_key'], 'rad_reads_source_identity_unique');
                $table->index(['rad_exam_id', 'status', 'final_at'], 'rad_reads_exam_status_idx');
                $table->index(['status', 'subspecialty_code', 'created_at'], 'rad_reads_queue_idx');
                $table->index(['radiologist_ref', 'final_at'], 'rad_reads_radiologist_idx');
                $table->index(['demo_owner', 'status'], 'rad_reads_demo_owner_idx');
            });
        }

        if (! Schema::hasTable('prod.rad_critical_results')) {
            Schema::create('prod.rad_critical_results', function (Blueprint $table): void {
                $table->id('rad_critical_result_id');
                $table->uuid('critical_result_uuid')->unique();
                $table->foreignId('rad_exam_id')->constrained('prod.rad_exams', 'rad_exam_id')->cascadeOnDelete();
                $table->foreignId('rad_read_id')->nullable()->constrained('prod.rad_reads', 'rad_read_id')->nullOnDelete();
                $table->foreignId('source_id')->constrained('integration.sources', 'source_id')->restrictOnDelete();
                $table->string('source_result_key', 190);
                $table->string('finding_class', 32);
                $table->string('policy_state', 32)->default('pending_notification');
                $table->timestampTz('identified_at');
                $table->timestampTz('notified_at')->nullable();
                $table->timestampTz('acknowledged_at')->nullable();
                $table->timestampTz('escalated_at')->nullable();
                $table->timestampTz('closed_at')->nullable();
                $table->string('recipient_role', 120)->nullable();
                $table->string('demo_owner', 120)->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestampsTz();

                $table->unique(['source_id', 'source_result_key'], 'rad_critical_results_source_identity_unique');
                $table->index(['policy_state', 'identified_at'], 'rad_critical_results_open_loop_idx');
                $table->index(['rad_exam_id', 'policy_state'], 'rad_critical_results_exam_state_idx');
                $table->index(['demo_owner', 'identified_at'], 'rad_critical_results_demo_owner_idx');
            });
        }

        $this->addForeignKeys();
        $this->addChecks();
        $this->addOrderGuard();
    }

    public function down(): void
    {
        Schema::dropIfExists('prod.rad_critical_results');
        Schema::dropIfExists('prod.rad_reads');
        Schema::dropIfExists('prod.rad_exams');
        Schema::dropIfExists('prod.rad_scanner_downtimes');
        Schema::dropIfExists('prod.rad_scanners');
        DB::statement('DROP FUNCTION IF EXISTS prod.enforce_rad_exam_order()');
        Schema::dropIfExists('hosp_ref.rad_subspecialties');
        Schema::dropIfExists('hosp_ref.rad_modalities');
        if (Schema::hasColumn('prod.barriers', 'demo_owner')) {
            Schema::table('prod.barriers', fn (Blueprint $table) => $table->dropColumn('demo_owner'));
        }
    }

    private function addForeignKeys(): void
    {
        DB::statement('ALTER TABLE prod.rad_scanners ADD CONSTRAINT rad_scanners_facility_fk FOREIGN KEY (facility_id) REFERENCES hosp_org.facilities(facility_id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE prod.rad_scanners ADD CONSTRAINT rad_scanners_modality_fk FOREIGN KEY (modality_code) REFERENCES hosp_ref.rad_modalities(code) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE prod.rad_exams ADD CONSTRAINT rad_exams_modality_fk FOREIGN KEY (modality_code) REFERENCES hosp_ref.rad_modalities(code) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE prod.rad_exams ADD CONSTRAINT rad_exams_subspecialty_fk FOREIGN KEY (subspecialty_code) REFERENCES hosp_ref.rad_subspecialties(code) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE prod.rad_reads ADD CONSTRAINT rad_reads_subspecialty_fk FOREIGN KEY (subspecialty_code) REFERENCES hosp_ref.rad_subspecialties(code) ON DELETE RESTRICT');
    }

    private function addChecks(): void
    {
        $checks = [
            ['prod.rad_scanners', 'rad_scanners_capacity_check', 'capacity > 0'],
            ['prod.rad_scanners', 'rad_scanners_status_check', "status IN ('operational', 'limited', 'downtime', 'retired')"],
            ['prod.rad_scanner_downtimes', 'rad_scanner_downtimes_status_check', "status IN ('scheduled', 'active', 'complete', 'cancelled')"],
            ['prod.rad_scanner_downtimes', 'rad_scanner_downtimes_interval_check', 'ends_at IS NULL OR ends_at > starts_at'],
            ['prod.rad_exams', 'rad_exams_status_check', "status IN ('ordered', 'scheduled', 'in_progress', 'complete', 'cancelled', 'discontinued')"],
            ['prod.rad_exams', 'rad_exams_contrast_status_check', "contrast_status IN ('not_required', 'ordered', 'screening', 'ready', 'administered', 'contraindicated', 'unknown')"],
            ['prod.rad_exams', 'rad_exams_schedule_interval_check', 'scheduled_end_at IS NULL OR scheduled_start_at IS NULL OR scheduled_end_at > scheduled_start_at'],
            ['prod.rad_exams', 'rad_exams_performed_interval_check', 'completed_at IS NULL OR started_at IS NULL OR completed_at >= started_at'],
            ['prod.rad_reads', 'rad_reads_status_check', "status IN ('preliminary', 'final', 'corrected', 'addendum', 'cancelled')"],
            ['prod.rad_critical_results', 'rad_critical_results_finding_class_check', "finding_class IN ('critical', 'urgent', 'unexpected', 'other')"],
            ['prod.rad_critical_results', 'rad_critical_results_policy_state_check', "policy_state IN ('pending_notification', 'notified', 'acknowledged', 'escalated', 'closed')"],
            ['prod.rad_critical_results', 'rad_critical_results_notification_order_check', 'notified_at IS NULL OR notified_at >= identified_at'],
            ['prod.rad_critical_results', 'rad_critical_results_ack_order_check', 'acknowledged_at IS NULL OR (notified_at IS NOT NULL AND acknowledged_at >= notified_at)'],
        ];

        foreach ($checks as [$table, $name, $expression]) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    private function addOrderGuard(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prod.enforce_rad_exam_order()
            RETURNS trigger AS $$
            DECLARE
                order_department text;
                order_encounter_id bigint;
            BEGIN
                SELECT department, encounter_id
                INTO order_department, order_encounter_id
                FROM prod.ancillary_orders
                WHERE ancillary_order_id = NEW.ancillary_order_id;

                IF order_department IS DISTINCT FROM 'rad' THEN
                    RAISE EXCEPTION 'rad_exams requires a rad ancillary order';
                END IF;

                IF NEW.encounter_id IS NOT NULL AND order_encounter_id IS DISTINCT FROM NEW.encounter_id THEN
                    RAISE EXCEPTION 'rad_exams encounter must match the ancillary order encounter';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS rad_exams_order_guard ON prod.rad_exams;
            CREATE TRIGGER rad_exams_order_guard
            BEFORE INSERT OR UPDATE OF ancillary_order_id, encounter_id ON prod.rad_exams
            FOR EACH ROW EXECUTE FUNCTION prod.enforce_rad_exam_order();
            SQL);
    }
};
