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

        $this->createTestCatalog();
        $this->createLabSpecimens();
        $this->createLabResults();
        $this->createLabCriticalValues();
        $this->createAnatomicPathologyCases();
        $this->createBloodBankReadiness();
        $this->addChecks();
        $this->addProjectionGuards();
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Lab, Pathology, and Blood Bank facts require application rollback and a forward-repair migration outside local/testing environments.');
        }

        foreach (['prod.lab_critical_values', 'prod.lab_results', 'prod.lab_specimens', 'prod.ap_cases', 'prod.bb_readiness'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("{$table} contains facts; preserve the satellite data and use a forward-repair migration.");
            }
        }

        Schema::dropIfExists('prod.lab_critical_values');
        Schema::dropIfExists('prod.lab_results');
        Schema::dropIfExists('prod.lab_specimens');
        Schema::dropIfExists('prod.ap_cases');
        Schema::dropIfExists('prod.bb_readiness');
        Schema::dropIfExists('hosp_ref.lab_test_catalog');

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS prod.enforce_lab_specimen_order();
            DROP FUNCTION IF EXISTS prod.enforce_lab_result_order();
            DROP FUNCTION IF EXISTS prod.enforce_ap_case_order();
            DROP FUNCTION IF EXISTS prod.enforce_bb_readiness_order();
        SQL);
    }

    private function createTestCatalog(): void
    {
        if (Schema::hasTable('hosp_ref.lab_test_catalog')) {
            return;
        }

        Schema::create('hosp_ref.lab_test_catalog', function (Blueprint $table): void {
            $table->id('lab_test_catalog_id');
            $table->uuid('catalog_uuid')->unique();
            $table->string('catalog_key', 160)->unique();
            $table->string('local_code', 80);
            $table->string('loinc_code', 32)->nullable();
            $table->string('label', 190);
            $table->string('department', 32);
            $table->string('test_family', 80);
            $table->string('expected_tat_class', 24);
            $table->string('decision_class', 24)->default('none');
            $table->string('specimen_type', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->unique(['department', 'local_code', 'effective_from'], 'lab_test_catalog_department_code_version_unique');
            $table->index(['department', 'test_family', 'is_active'], 'lab_test_catalog_family_active_idx');
            $table->index(['loinc_code', 'is_active'], 'lab_test_catalog_loinc_active_idx');
            $table->index(['decision_class', 'is_active'], 'lab_test_catalog_decision_active_idx');
        });
    }

    private function createLabSpecimens(): void
    {
        if (Schema::hasTable('prod.lab_specimens')) {
            return;
        }

        Schema::create('prod.lab_specimens', function (Blueprint $table): void {
            $table->id('lab_specimen_id');
            $table->uuid('specimen_uuid')->unique();
            $table->foreignId('ancillary_order_id')->constrained('prod.ancillary_orders', 'ancillary_order_id')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('integration.sources', 'source_id')->restrictOnDelete();
            $table->string('source_specimen_key', 190);
            $table->string('source_accession_key', 190)->nullable();
            $table->foreignId('parent_specimen_id')->nullable()->constrained('prod.lab_specimens', 'lab_specimen_id')->restrictOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained('prod.encounters', 'encounter_id')->nullOnDelete();
            $table->string('specimen_type', 80);
            $table->string('container_type', 80)->nullable();
            $table->string('collector_role', 120)->nullable();
            $table->string('collection_method', 80)->nullable();
            $table->string('status', 32)->default('collection_pending');
            $table->string('rejection_reason_code', 80)->nullable();
            $table->timestampTz('collected_at')->nullable();
            $table->timestampTz('in_transit_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampTz('recollect_ordered_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('demo_owner', 120)->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->unique(['source_id', 'source_specimen_key'], 'lab_specimens_source_identity_unique');
            $table->index(['source_id', 'source_accession_key'], 'lab_specimens_accession_idx');
            $table->index(['parent_specimen_id', 'created_at'], 'lab_specimens_recollect_lineage_idx');
            $table->index(['encounter_id', 'status'], 'lab_specimens_encounter_status_idx');
            $table->index(['status', 'collected_at', 'lab_specimen_id'], 'lab_specimens_status_collection_idx');
            $table->index(['demo_owner', 'created_at'], 'lab_specimens_demo_owner_idx');
        });

        DB::statement(<<<'SQL'
            CREATE INDEX lab_specimens_pending_collection_idx
            ON prod.lab_specimens (ancillary_order_id, created_at, lab_specimen_id)
            WHERE collected_at IS NULL AND status = 'collection_pending'
        SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX lab_specimens_pending_receipt_idx
            ON prod.lab_specimens (status, collected_at, lab_specimen_id)
            WHERE received_at IS NULL AND status IN ('collected', 'in_transit')
        SQL);
    }

    private function createLabResults(): void
    {
        if (Schema::hasTable('prod.lab_results')) {
            return;
        }

        Schema::create('prod.lab_results', function (Blueprint $table): void {
            $table->id('lab_result_id');
            $table->uuid('result_uuid')->unique();
            $table->foreignId('ancillary_order_id')->constrained('prod.ancillary_orders', 'ancillary_order_id')->cascadeOnDelete();
            $table->foreignId('lab_specimen_id')->nullable()->constrained('prod.lab_specimens', 'lab_specimen_id')->nullOnDelete();
            $table->foreignId('lab_test_catalog_id')->constrained('hosp_ref.lab_test_catalog', 'lab_test_catalog_id')->restrictOnDelete();
            $table->foreignId('source_id')->constrained('integration.sources', 'source_id')->restrictOnDelete();
            $table->string('source_result_key', 190);
            $table->string('source_result_version', 80)->nullable();
            $table->foreignId('parent_lab_result_id')->nullable()->constrained('prod.lab_results', 'lab_result_id')->nullOnDelete();
            $table->string('local_code', 80);
            $table->string('loinc_code', 32)->nullable();
            $table->string('result_status', 24);
            $table->string('result_stage', 40)->default('final');
            $table->string('abnormal_flag', 24)->default('unknown');
            $table->boolean('auto_verified')->default(false);
            $table->boolean('is_critical')->default(false);
            $table->string('analyzer_ref', 190)->nullable();
            $table->timestampTz('observed_at')->nullable();
            $table->timestampTz('resulted_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('corrected_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('demo_owner', 120)->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->index(['ancillary_order_id', 'result_status', 'resulted_at'], 'lab_results_order_status_idx');
            $table->index(['lab_specimen_id', 'result_status', 'resulted_at'], 'lab_results_specimen_status_idx');
            $table->index(['lab_test_catalog_id', 'result_status', 'resulted_at'], 'lab_results_catalog_status_idx');
            $table->index(['analyzer_ref', 'result_status', 'resulted_at'], 'lab_results_analyzer_status_idx');
            $table->index(['parent_lab_result_id', 'created_at'], 'lab_results_correction_lineage_idx');
            $table->index(['demo_owner', 'resulted_at'], 'lab_results_demo_owner_idx');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX lab_results_source_version_unique
            ON prod.lab_results (source_id, source_result_key, COALESCE(source_result_version, ''))
        SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX lab_results_pending_decision_idx
            ON prod.lab_results (lab_test_catalog_id, result_status, resulted_at, ancillary_order_id)
            WHERE result_status IN ('preliminary', 'final')
        SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX lab_results_critical_open_idx
            ON prod.lab_results (result_status, resulted_at, lab_result_id)
            WHERE is_critical = true
        SQL);
    }

    private function createLabCriticalValues(): void
    {
        if (Schema::hasTable('prod.lab_critical_values')) {
            return;
        }

        Schema::create('prod.lab_critical_values', function (Blueprint $table): void {
            $table->id('lab_critical_value_id');
            $table->uuid('critical_value_uuid')->unique();
            $table->foreignId('lab_result_id')->constrained('prod.lab_results', 'lab_result_id')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('integration.sources', 'source_id')->restrictOnDelete();
            $table->string('source_critical_key', 190);
            $table->string('severity', 24)->default('critical');
            $table->string('callback_state', 32)->default('pending_notification');
            $table->timestampTz('identified_at');
            $table->timestampTz('notified_at')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('escalated_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->string('recipient_role', 120)->nullable();
            $table->string('demo_owner', 120)->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->unique(['source_id', 'source_critical_key'], 'lab_critical_values_source_identity_unique');
            $table->index(['callback_state', 'identified_at'], 'lab_critical_values_open_loop_idx');
            $table->index(['lab_result_id', 'callback_state'], 'lab_critical_values_result_state_idx');
            $table->index(['demo_owner', 'identified_at'], 'lab_critical_values_demo_owner_idx');
        });
    }

    private function createAnatomicPathologyCases(): void
    {
        if (Schema::hasTable('prod.ap_cases')) {
            return;
        }

        Schema::create('prod.ap_cases', function (Blueprint $table): void {
            $table->id('ap_case_id');
            $table->uuid('ap_case_uuid')->unique();
            $table->foreignId('ancillary_order_id')->unique()->constrained('prod.ancillary_orders', 'ancillary_order_id')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('integration.sources', 'source_id')->restrictOnDelete();
            $table->string('source_case_key', 190);
            $table->foreignId('case_id')->nullable()->constrained('prod.or_cases', 'case_id')->nullOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained('prod.encounters', 'encounter_id')->nullOnDelete();
            $table->string('source_accession_key', 190)->nullable();
            $table->string('specimen_ref', 190)->nullable();
            $table->string('procedure_code', 80)->nullable();
            $table->string('procedure_label', 190)->nullable();
            $table->string('case_type', 32)->default('surgical');
            $table->string('stage', 32)->default('specimen_out');
            $table->timestampTz('current_stage_at');
            $table->timestampTz('specimen_out_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestampTz('grossed_at')->nullable();
            $table->timestampTz('processing_batch_at')->nullable();
            $table->timestampTz('slides_ready_at')->nullable();
            $table->timestampTz('diagnosed_at')->nullable();
            $table->timestampTz('signed_out_at')->nullable();
            $table->string('frozen_status', 24)->default('not_applicable');
            $table->timestampTz('frozen_started_at')->nullable();
            $table->timestampTz('frozen_resulted_at')->nullable();
            $table->string('pathologist_ref', 190)->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('demo_owner', 120)->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->unique(['source_id', 'source_case_key'], 'ap_cases_source_identity_unique');
            $table->index(['stage', 'current_stage_at', 'ap_case_id'], 'ap_cases_stage_aging_idx');
            $table->index(['case_id', 'frozen_status', 'current_stage_at'], 'ap_cases_or_frozen_readiness_idx');
            $table->index(['encounter_id', 'stage'], 'ap_cases_encounter_stage_idx');
            $table->index(['source_id', 'source_accession_key'], 'ap_cases_accession_idx');
            $table->index(['demo_owner', 'current_stage_at'], 'ap_cases_demo_owner_idx');
        });
    }

    private function createBloodBankReadiness(): void
    {
        if (Schema::hasTable('prod.bb_readiness')) {
            return;
        }

        Schema::create('prod.bb_readiness', function (Blueprint $table): void {
            $table->id('bb_readiness_id');
            $table->uuid('readiness_uuid')->unique();
            $table->foreignId('ancillary_order_id')->unique()->constrained('prod.ancillary_orders', 'ancillary_order_id')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('integration.sources', 'source_id')->restrictOnDelete();
            $table->string('source_request_key', 190);
            $table->foreignId('case_id')->nullable()->constrained('prod.or_cases', 'case_id')->nullOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained('prod.encounters', 'encounter_id')->nullOnDelete();
            $table->string('product_class', 32)->default('red_cells');
            $table->string('readiness_state', 32)->default('ordered');
            $table->string('type_screen_state', 24)->default('pending');
            $table->string('crossmatch_state', 24)->default('pending');
            $table->unsignedSmallInteger('units_requested')->default(1);
            $table->unsignedSmallInteger('units_allocated')->default(0);
            $table->unsignedSmallInteger('units_issued')->default(0);
            $table->timestampTz('ordered_at');
            $table->timestampTz('needed_by')->nullable();
            $table->timestampTz('type_screen_ready_at')->nullable();
            $table->timestampTz('crossmatch_ready_at')->nullable();
            $table->timestampTz('allocated_at')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('mtp_activated_at')->nullable();
            $table->timestampTz('mtp_closed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('demo_owner', 120)->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->unique(['source_id', 'source_request_key'], 'bb_readiness_source_identity_unique');
            $table->index(['case_id', 'readiness_state', 'needed_by'], 'bb_readiness_or_schedule_idx');
            $table->index(['encounter_id', 'readiness_state'], 'bb_readiness_encounter_state_idx');
            $table->index(['readiness_state', 'needed_by', 'bb_readiness_id'], 'bb_readiness_state_due_idx');
            $table->index(['demo_owner', 'needed_by'], 'bb_readiness_demo_owner_idx');
        });

        DB::statement(<<<'SQL'
            CREATE INDEX bb_readiness_pending_or_idx
            ON prod.bb_readiness (case_id, needed_by, bb_readiness_id)
            WHERE case_id IS NOT NULL AND readiness_state NOT IN ('issued', 'cancelled', 'complete')
        SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX bb_readiness_active_mtp_idx
            ON prod.bb_readiness (mtp_activated_at, bb_readiness_id)
            WHERE mtp_activated_at IS NOT NULL AND mtp_closed_at IS NULL
        SQL);
    }

    private function addChecks(): void
    {
        $checks = [
            ['hosp_ref.lab_test_catalog', 'lab_test_catalog_department_check', "department IN ('chemistry', 'hematology', 'coagulation', 'microbiology', 'molecular', 'pathology', 'blood_bank', 'other')"],
            ['hosp_ref.lab_test_catalog', 'lab_test_catalog_tat_class_check', "expected_tat_class IN ('stat', 'urgent', 'routine', 'send_out', 'extended')"],
            ['hosp_ref.lab_test_catalog', 'lab_test_catalog_decision_class_check', "decision_class IN ('ed_disposition', 'discharge_gate', 'or_gate', 'none')"],
            ['hosp_ref.lab_test_catalog', 'lab_test_catalog_effective_range_check', 'effective_to IS NULL OR effective_to > effective_from'],
            ['hosp_ref.lab_test_catalog', 'lab_test_catalog_metadata_object_check', "jsonb_typeof(metadata) = 'object'"],

            ['prod.lab_specimens', 'lab_specimens_status_check', "status IN ('collection_pending', 'collected', 'in_transit', 'received', 'processing', 'rejected', 'recollect_requested', 'cancelled')"],
            ['prod.lab_specimens', 'lab_specimens_parent_not_self_check', 'parent_specimen_id IS NULL OR parent_specimen_id <> lab_specimen_id'],
            ['prod.lab_specimens', 'lab_specimens_transit_order_check', 'in_transit_at IS NULL OR (collected_at IS NOT NULL AND in_transit_at >= collected_at)'],
            ['prod.lab_specimens', 'lab_specimens_received_order_check', 'received_at IS NULL OR (collected_at IS NOT NULL AND received_at >= collected_at)'],
            ['prod.lab_specimens', 'lab_specimens_rejected_order_check', 'rejected_at IS NULL OR collected_at IS NULL OR rejected_at >= collected_at'],
            ['prod.lab_specimens', 'lab_specimens_recollect_order_check', 'recollect_ordered_at IS NULL OR (rejected_at IS NOT NULL AND recollect_ordered_at >= rejected_at)'],
            ['prod.lab_specimens', 'lab_specimens_status_evidence_check', <<<'SQL'
                (status = 'collection_pending') OR
                (status = 'collected' AND collected_at IS NOT NULL) OR
                (status = 'in_transit' AND collected_at IS NOT NULL AND in_transit_at IS NOT NULL) OR
                (status IN ('received', 'processing') AND collected_at IS NOT NULL AND received_at IS NOT NULL) OR
                (status = 'rejected' AND rejected_at IS NOT NULL AND rejection_reason_code IS NOT NULL) OR
                (status = 'recollect_requested' AND rejected_at IS NOT NULL AND rejection_reason_code IS NOT NULL AND recollect_ordered_at IS NOT NULL) OR
                (status = 'cancelled' AND cancelled_at IS NOT NULL)
            SQL],
            ['prod.lab_specimens', 'lab_specimens_metadata_object_check', "jsonb_typeof(metadata) = 'object'"],

            ['prod.lab_results', 'lab_results_status_check', "result_status IN ('preliminary', 'final', 'corrected', 'cancelled')"],
            ['prod.lab_results', 'lab_results_stage_check', "result_stage IN ('preliminary', 'organism_identification', 'susceptibility', 'final', 'corrected', 'cancelled')"],
            ['prod.lab_results', 'lab_results_abnormal_flag_check', "abnormal_flag IN ('normal', 'abnormal', 'critical', 'unknown')"],
            ['prod.lab_results', 'lab_results_result_time_check', 'resulted_at IS NULL OR observed_at IS NULL OR resulted_at >= observed_at'],
            ['prod.lab_results', 'lab_results_verified_time_check', 'verified_at IS NULL OR (resulted_at IS NOT NULL AND verified_at >= resulted_at)'],
            ['prod.lab_results', 'lab_results_corrected_time_check', 'corrected_at IS NULL OR (resulted_at IS NOT NULL AND corrected_at >= resulted_at)'],
            ['prod.lab_results', 'lab_results_auto_verify_check', 'auto_verified = false OR verified_at IS NOT NULL'],
            ['prod.lab_results', 'lab_results_status_evidence_check', <<<'SQL'
                (result_status IN ('preliminary', 'final') AND resulted_at IS NOT NULL) OR
                (result_status = 'corrected' AND resulted_at IS NOT NULL AND corrected_at IS NOT NULL AND parent_lab_result_id IS NOT NULL) OR
                (result_status = 'cancelled' AND cancelled_at IS NOT NULL)
            SQL],
            ['prod.lab_results', 'lab_results_metadata_object_check', "jsonb_typeof(metadata) = 'object'"],

            ['prod.lab_critical_values', 'lab_critical_values_severity_check', "severity IN ('critical', 'urgent', 'other')"],
            ['prod.lab_critical_values', 'lab_critical_values_state_check', "callback_state IN ('pending_notification', 'notified', 'acknowledged', 'escalated', 'closed')"],
            ['prod.lab_critical_values', 'lab_critical_values_notify_order_check', 'notified_at IS NULL OR notified_at >= identified_at'],
            ['prod.lab_critical_values', 'lab_critical_values_ack_order_check', 'acknowledged_at IS NULL OR (notified_at IS NOT NULL AND acknowledged_at >= notified_at)'],
            ['prod.lab_critical_values', 'lab_critical_values_escalation_order_check', 'escalated_at IS NULL OR escalated_at >= identified_at'],
            ['prod.lab_critical_values', 'lab_critical_values_close_order_check', 'closed_at IS NULL OR closed_at >= identified_at'],
            ['prod.lab_critical_values', 'lab_critical_values_state_evidence_check', <<<'SQL'
                (callback_state = 'pending_notification') OR
                (callback_state = 'notified' AND notified_at IS NOT NULL) OR
                (callback_state = 'acknowledged' AND notified_at IS NOT NULL AND acknowledged_at IS NOT NULL) OR
                (callback_state = 'escalated' AND escalated_at IS NOT NULL) OR
                (callback_state = 'closed' AND closed_at IS NOT NULL)
            SQL],
            ['prod.lab_critical_values', 'lab_critical_values_metadata_object_check', "jsonb_typeof(metadata) = 'object'"],

            ['prod.ap_cases', 'ap_cases_case_type_check', "case_type IN ('surgical', 'biopsy', 'cytology', 'frozen_section', 'autopsy', 'other')"],
            ['prod.ap_cases', 'ap_cases_stage_check', "stage IN ('specimen_out', 'received', 'grossed', 'processing', 'slides_ready', 'diagnosed', 'signed_out', 'cancelled')"],
            ['prod.ap_cases', 'ap_cases_frozen_status_check', "frozen_status IN ('not_applicable', 'pending', 'in_progress', 'resulted', 'cancelled')"],
            ['prod.ap_cases', 'ap_cases_received_order_check', 'received_at IS NULL OR specimen_out_at IS NULL OR received_at >= specimen_out_at'],
            ['prod.ap_cases', 'ap_cases_grossed_order_check', 'grossed_at IS NULL OR received_at IS NULL OR grossed_at >= received_at'],
            ['prod.ap_cases', 'ap_cases_processing_order_check', 'processing_batch_at IS NULL OR grossed_at IS NULL OR processing_batch_at >= grossed_at'],
            ['prod.ap_cases', 'ap_cases_slides_order_check', 'slides_ready_at IS NULL OR processing_batch_at IS NULL OR slides_ready_at >= processing_batch_at'],
            ['prod.ap_cases', 'ap_cases_diagnosed_order_check', 'diagnosed_at IS NULL OR slides_ready_at IS NULL OR diagnosed_at >= slides_ready_at'],
            ['prod.ap_cases', 'ap_cases_signed_out_order_check', 'signed_out_at IS NULL OR diagnosed_at IS NULL OR signed_out_at >= diagnosed_at'],
            ['prod.ap_cases', 'ap_cases_frozen_order_check', 'frozen_resulted_at IS NULL OR (frozen_started_at IS NOT NULL AND frozen_resulted_at >= frozen_started_at)'],
            ['prod.ap_cases', 'ap_cases_frozen_evidence_check', <<<'SQL'
                (frozen_status IN ('not_applicable', 'pending')) OR
                (frozen_status = 'in_progress' AND frozen_started_at IS NOT NULL) OR
                (frozen_status = 'resulted' AND frozen_started_at IS NOT NULL AND frozen_resulted_at IS NOT NULL) OR
                (frozen_status = 'cancelled')
            SQL],
            ['prod.ap_cases', 'ap_cases_stage_evidence_check', "stage <> 'signed_out' OR signed_out_at IS NOT NULL"],
            ['prod.ap_cases', 'ap_cases_metadata_object_check', "jsonb_typeof(metadata) = 'object'"],

            ['prod.bb_readiness', 'bb_readiness_product_class_check', "product_class IN ('red_cells', 'plasma', 'platelets', 'cryo', 'whole_blood', 'mixed', 'other')"],
            ['prod.bb_readiness', 'bb_readiness_state_check', "readiness_state IN ('ordered', 'testing', 'type_screen_ready', 'crossmatch_ready', 'allocated', 'issued', 'unavailable', 'cancelled', 'complete')"],
            ['prod.bb_readiness', 'bb_readiness_type_screen_state_check', "type_screen_state IN ('not_required', 'pending', 'ready', 'expired', 'incompatible', 'unknown')"],
            ['prod.bb_readiness', 'bb_readiness_crossmatch_state_check', "crossmatch_state IN ('not_required', 'pending', 'ready', 'expired', 'incompatible', 'unknown')"],
            ['prod.bb_readiness', 'bb_readiness_units_check', 'units_requested > 0 AND units_issued <= units_allocated'],
            ['prod.bb_readiness', 'bb_readiness_type_screen_time_check', 'type_screen_ready_at IS NULL OR type_screen_ready_at >= ordered_at'],
            ['prod.bb_readiness', 'bb_readiness_crossmatch_time_check', 'crossmatch_ready_at IS NULL OR crossmatch_ready_at >= ordered_at'],
            ['prod.bb_readiness', 'bb_readiness_allocation_time_check', 'allocated_at IS NULL OR allocated_at >= ordered_at'],
            ['prod.bb_readiness', 'bb_readiness_issue_time_check', 'issued_at IS NULL OR issued_at >= ordered_at'],
            ['prod.bb_readiness', 'bb_readiness_mtp_time_check', 'mtp_closed_at IS NULL OR (mtp_activated_at IS NOT NULL AND mtp_closed_at >= mtp_activated_at)'],
            ['prod.bb_readiness', 'bb_readiness_state_evidence_check', <<<'SQL'
                (readiness_state NOT IN ('type_screen_ready', 'crossmatch_ready', 'allocated', 'issued', 'cancelled')) OR
                (readiness_state = 'type_screen_ready' AND type_screen_ready_at IS NOT NULL) OR
                (readiness_state = 'crossmatch_ready' AND crossmatch_ready_at IS NOT NULL) OR
                (readiness_state = 'allocated' AND allocated_at IS NOT NULL AND units_allocated > 0) OR
                (readiness_state = 'issued' AND issued_at IS NOT NULL AND units_issued > 0) OR
                (readiness_state = 'cancelled' AND cancelled_at IS NOT NULL)
            SQL],
            ['prod.bb_readiness', 'bb_readiness_metadata_object_check', "jsonb_typeof(metadata) = 'object'"],
        ];

        foreach ($checks as [$table, $name, $expression]) {
            $alreadyExists = DB::table('pg_constraint')
                ->where('conname', $name)
                ->whereRaw('conrelid = CAST(? AS regclass)', [$table])
                ->exists();
            if ($alreadyExists) {
                continue;
            }

            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    private function addProjectionGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prod.enforce_lab_specimen_order()
            RETURNS trigger AS $$
            DECLARE
                order_department text;
                order_encounter_id bigint;
            BEGIN
                SELECT department, encounter_id INTO order_department, order_encounter_id
                FROM prod.ancillary_orders WHERE ancillary_order_id = NEW.ancillary_order_id;

                IF order_department IS DISTINCT FROM 'lab' THEN
                    RAISE EXCEPTION 'lab_specimens requires a lab ancillary order';
                END IF;
                IF NEW.encounter_id IS NOT NULL AND order_encounter_id IS DISTINCT FROM NEW.encounter_id THEN
                    RAISE EXCEPTION 'lab_specimens encounter must match the ancillary order encounter';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER lab_specimens_order_guard
            BEFORE INSERT OR UPDATE OF ancillary_order_id, encounter_id ON prod.lab_specimens
            FOR EACH ROW EXECUTE FUNCTION prod.enforce_lab_specimen_order();

            CREATE OR REPLACE FUNCTION prod.enforce_lab_result_order()
            RETURNS trigger AS $$
            DECLARE
                order_department text;
                specimen_order_id bigint;
                parent_order_id bigint;
                catalog_department text;
            BEGIN
                SELECT department INTO order_department
                FROM prod.ancillary_orders WHERE ancillary_order_id = NEW.ancillary_order_id;
                IF order_department IS DISTINCT FROM 'lab' THEN
                    RAISE EXCEPTION 'lab_results requires a lab ancillary order';
                END IF;

                IF NEW.lab_specimen_id IS NOT NULL THEN
                    SELECT ancillary_order_id INTO specimen_order_id
                    FROM prod.lab_specimens WHERE lab_specimen_id = NEW.lab_specimen_id;
                    IF specimen_order_id IS DISTINCT FROM NEW.ancillary_order_id THEN
                        RAISE EXCEPTION 'lab_results specimen must belong to the same ancillary order';
                    END IF;
                END IF;

                IF NEW.parent_lab_result_id IS NOT NULL THEN
                    SELECT ancillary_order_id INTO parent_order_id
                    FROM prod.lab_results WHERE lab_result_id = NEW.parent_lab_result_id;
                    IF parent_order_id IS DISTINCT FROM NEW.ancillary_order_id THEN
                        RAISE EXCEPTION 'corrected lab result must retain the original ancillary order';
                    END IF;
                END IF;

                SELECT department INTO catalog_department
                FROM hosp_ref.lab_test_catalog WHERE lab_test_catalog_id = NEW.lab_test_catalog_id;
                IF catalog_department IN ('pathology', 'blood_bank') THEN
                    RAISE EXCEPTION 'lab_results requires a clinical lab test catalog entry';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER lab_results_order_guard
            BEFORE INSERT OR UPDATE OF ancillary_order_id, lab_specimen_id, parent_lab_result_id, lab_test_catalog_id ON prod.lab_results
            FOR EACH ROW EXECUTE FUNCTION prod.enforce_lab_result_order();

            CREATE OR REPLACE FUNCTION prod.enforce_ap_case_order()
            RETURNS trigger AS $$
            DECLARE
                order_department text;
                order_encounter_id bigint;
            BEGIN
                SELECT department, encounter_id INTO order_department, order_encounter_id
                FROM prod.ancillary_orders WHERE ancillary_order_id = NEW.ancillary_order_id;
                IF order_department IS DISTINCT FROM 'pathology' THEN
                    RAISE EXCEPTION 'ap_cases requires a pathology ancillary order';
                END IF;
                IF NEW.encounter_id IS NOT NULL AND order_encounter_id IS DISTINCT FROM NEW.encounter_id THEN
                    RAISE EXCEPTION 'ap_cases encounter must match the ancillary order encounter';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER ap_cases_order_guard
            BEFORE INSERT OR UPDATE OF ancillary_order_id, encounter_id ON prod.ap_cases
            FOR EACH ROW EXECUTE FUNCTION prod.enforce_ap_case_order();

            CREATE OR REPLACE FUNCTION prod.enforce_bb_readiness_order()
            RETURNS trigger AS $$
            DECLARE
                order_department text;
                order_encounter_id bigint;
            BEGIN
                SELECT department, encounter_id INTO order_department, order_encounter_id
                FROM prod.ancillary_orders WHERE ancillary_order_id = NEW.ancillary_order_id;
                IF order_department IS DISTINCT FROM 'blood_bank' THEN
                    RAISE EXCEPTION 'bb_readiness requires a blood-bank ancillary order';
                END IF;
                IF NEW.encounter_id IS NOT NULL AND order_encounter_id IS DISTINCT FROM NEW.encounter_id THEN
                    RAISE EXCEPTION 'bb_readiness encounter must match the ancillary order encounter';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER bb_readiness_order_guard
            BEFORE INSERT OR UPDATE OF ancillary_order_id, encounter_id ON prod.bb_readiness
            FOR EACH ROW EXECUTE FUNCTION prod.enforce_bb_readiness_order();
        SQL);
    }
};
