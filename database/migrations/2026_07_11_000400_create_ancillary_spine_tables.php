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

        if (! Schema::hasTable('hosp_ref.ancillary_milestone_types')) {
            Schema::create('hosp_ref.ancillary_milestone_types', function (Blueprint $table): void {
                $table->string('code', 80)->primary();
                $table->string('department', 24);
                $table->string('label', 160);
                $table->string('phase', 80);
                $table->unsignedSmallInteger('ordinal');
                $table->boolean('is_terminal')->default(false);
                $table->boolean('is_optional')->default(false);
                $table->boolean('is_minimum_feed')->default(false);
                $table->string('expected_source_class', 120)->nullable();
                $table->jsonb('source_precedence')->default(DB::raw("'[]'::jsonb"));
                $table->string('ocel_event_type', 160)->nullable();
                $table->jsonb('process_ids')->default(DB::raw("'[]'::jsonb"));
                $table->jsonb('display_metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestampsTz();

                $table->unique(['department', 'ordinal'], 'ancillary_milestone_types_department_ordinal_unique');
                $table->index(['department', 'phase', 'ordinal'], 'ancillary_milestone_types_workflow_idx');
            });
        }

        if (! Schema::hasTable('hosp_ref.ancillary_barrier_reasons')) {
            Schema::create('hosp_ref.ancillary_barrier_reasons', function (Blueprint $table): void {
                $table->string('reason_code', 80)->primary();
                $table->string('department', 24);
                $table->string('category', 24);
                $table->string('label', 160);
                $table->boolean('is_active')->default(true);
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestampsTz();

                $table->index(['department', 'is_active'], 'ancillary_barrier_reasons_active_idx');
            });
        }

        if (! Schema::hasTable('prod.ancillary_orders')) {
            Schema::create('prod.ancillary_orders', function (Blueprint $table): void {
                $table->id('ancillary_order_id');
                $table->uuid('order_uuid')->unique();
                $table->string('department', 24);
                $table->string('work_item_type', 40);
                $table->foreignId('source_id')
                    ->constrained('integration.sources', 'source_id')
                    ->restrictOnDelete();
                $table->string('source_order_key', 190);
                $table->foreignId('encounter_id')
                    ->nullable()
                    ->constrained('prod.encounters', 'encounter_id')
                    ->nullOnDelete();
                $table->string('encounter_ref', 190)->nullable();
                $table->string('patient_ref', 190)->nullable();
                $table->string('patient_class', 24)->default('unknown');
                $table->string('priority', 24)->default('unknown');
                $table->timestampTz('ordered_at');
                $table->timestampTz('terminal_at')->nullable();
                $table->string('current_state', 80)->default('ordered');
                $table->string('current_milestone_code', 80)->nullable();
                $table->timestampTz('current_milestone_at')->nullable();
                $table->foreignId('unit_id')
                    ->nullable()
                    ->constrained('prod.units', 'unit_id')
                    ->nullOnDelete();
                $table->timestampTz('source_cutoff_at');
                $table->string('demo_owner', 120)->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestampsTz();

                $table->unique(['source_id', 'department', 'source_order_key'], 'ancillary_orders_source_identity_unique');
                $table->index(['department', 'current_state', 'current_milestone_at'], 'ancillary_orders_live_worklist_idx');
                $table->index(['encounter_id', 'department', 'current_state'], 'ancillary_orders_readiness_idx');
                $table->index(['unit_id', 'department', 'current_state'], 'ancillary_orders_unit_worklist_idx');
                $table->index(['demo_owner', 'ordered_at'], 'ancillary_orders_demo_owner_idx');
            });
        }

        if (! Schema::hasTable('prod.ancillary_milestones')) {
            Schema::create('prod.ancillary_milestones', function (Blueprint $table): void {
                $table->id('ancillary_milestone_id');
                $table->uuid('milestone_uuid')->unique();
                $table->foreignId('ancillary_order_id')
                    ->constrained('prod.ancillary_orders', 'ancillary_order_id')
                    ->cascadeOnDelete();
                $table->string('milestone_code', 80);
                $table->timestampTz('occurred_at');
                $table->timestampTz('received_at');
                $table->foreignId('source_id')
                    ->constrained('integration.sources', 'source_id')
                    ->restrictOnDelete();
                $table->foreignId('canonical_event_id')
                    ->constrained('integration.canonical_events', 'canonical_event_id')
                    ->restrictOnDelete();
                $table->foreignId('provenance_record_id')
                    ->nullable()
                    ->constrained('integration.provenance_records', 'provenance_record_id')
                    ->nullOnDelete();
                $table->string('assertion_key', 256)->unique();
                $table->unsignedSmallInteger('source_rank')->default(1000);
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestampsTz();

                $table->index(['ancillary_order_id', 'milestone_code', 'source_rank', 'received_at'], 'ancillary_milestones_selection_idx');
                $table->index(['canonical_event_id', 'ancillary_order_id'], 'ancillary_milestones_canonical_event_idx');
                $table->index(['source_id', 'occurred_at'], 'ancillary_milestones_source_time_idx');
            });
        }

        if (! Schema::hasTable('prod.ancillary_sla_definitions')) {
            Schema::create('prod.ancillary_sla_definitions', function (Blueprint $table): void {
                $table->id('ancillary_sla_definition_id');
                $table->uuid('definition_uuid')->unique();
                $table->string('department', 24);
                $table->string('metric_key', 160);
                $table->string('label', 190);
                $table->string('start_milestone_code', 80);
                $table->string('stop_milestone_code', 80);
                $table->string('priority', 24)->nullable();
                $table->string('patient_class', 24)->nullable();
                $table->jsonb('scope')->default(DB::raw("'{}'::jsonb"));
                $table->string('statistic', 40)->default('item_clock');
                $table->unsignedInteger('warning_minutes')->nullable();
                $table->unsignedInteger('breach_minutes')->nullable();
                $table->decimal('target_value', 12, 4)->nullable();
                $table->string('direction', 24)->default('lower_is_better');
                $table->string('unit', 40)->default('minutes');
                $table->timestampTz('effective_from');
                $table->timestampTz('effective_to')->nullable();
                $table->unsignedInteger('version');
                $table->boolean('active')->default(false);
                $table->foreignId('approved_by_user_id')
                    ->nullable()
                    ->constrained('prod.users', 'id')
                    ->nullOnDelete();
                $table->timestampTz('approved_at')->nullable();
                $table->text('definition_text');
                $table->string('source_reference_id', 190)->nullable();
                $table->timestampsTz();

                $table->unique(['metric_key', 'version'], 'ancillary_sla_definitions_metric_version_unique');
                $table->index(['department', 'metric_key', 'active', 'effective_from'], 'ancillary_sla_definitions_effective_idx');
            });
        }

        if (! Schema::hasTable('prod.ancillary_breaches')) {
            Schema::create('prod.ancillary_breaches', function (Blueprint $table): void {
                $table->id('ancillary_breach_id');
                $table->uuid('breach_uuid')->unique();
                $table->foreignId('ancillary_order_id')
                    ->constrained('prod.ancillary_orders', 'ancillary_order_id')
                    ->restrictOnDelete();
                $table->foreignId('ancillary_sla_definition_id')
                    ->constrained('prod.ancillary_sla_definitions', 'ancillary_sla_definition_id')
                    ->restrictOnDelete();
                $table->string('status', 16)->default('open');
                $table->timestampTz('warning_at')->nullable();
                $table->timestampTz('breached_at');
                $table->timestampTz('cleared_at')->nullable();
                $table->foreignId('start_assertion_id')
                    ->constrained('prod.ancillary_milestones', 'ancillary_milestone_id')
                    ->restrictOnDelete();
                $table->foreignId('stop_assertion_id')
                    ->nullable()
                    ->constrained('prod.ancillary_milestones', 'ancillary_milestone_id')
                    ->restrictOnDelete();
                $table->decimal('elapsed_minutes_at_open', 12, 3);
                $table->decimal('elapsed_minutes_at_clear', 12, 3)->nullable();
                $table->foreignId('barrier_id')
                    ->nullable()
                    ->constrained('prod.barriers', 'barrier_id')
                    ->nullOnDelete();
                $table->uuid('opened_event_uuid')->nullable();
                $table->uuid('cleared_event_uuid')->nullable();
                $table->timestampTz('last_evaluated_at');
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestampsTz();

                $table->index(['status', 'breached_at'], 'ancillary_breaches_status_time_idx');
                $table->index(['ancillary_sla_definition_id', 'status'], 'ancillary_breaches_definition_status_idx');
                $table->index(['barrier_id', 'status'], 'ancillary_breaches_barrier_status_idx');
            });
        }

        $this->addConstraintsAndProjections();
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Ancillary ledgers require application rollback and a forward-repair migration outside local/testing environments.');
        }

        if (Schema::hasTable('prod.ancillary_milestones') && DB::table('prod.ancillary_milestones')->exists()) {
            throw new RuntimeException('Ancillary milestone assertions exist; preserve the append-only ledger and use a forward-repair migration.');
        }

        DB::unprepared(<<<'SQL'
            DROP VIEW IF EXISTS prod.ancillary_current_assertions;
            DROP TRIGGER IF EXISTS ancillary_sla_definitions_no_overlap ON prod.ancillary_sla_definitions;
            DROP FUNCTION IF EXISTS prod.enforce_ancillary_sla_definition();
            DROP TRIGGER IF EXISTS ancillary_milestones_append_only ON prod.ancillary_milestones;
            DROP FUNCTION IF EXISTS prod.reject_ancillary_milestone_mutation();
            DROP TRIGGER IF EXISTS ancillary_orders_owned_delete ON prod.ancillary_orders;
            DROP FUNCTION IF EXISTS prod.guard_ancillary_order_delete();
        SQL);

        Schema::dropIfExists('prod.ancillary_breaches');
        Schema::dropIfExists('prod.ancillary_sla_definitions');
        Schema::dropIfExists('prod.ancillary_milestones');
        Schema::dropIfExists('prod.ancillary_orders');
        Schema::dropIfExists('hosp_ref.ancillary_barrier_reasons');
        Schema::dropIfExists('hosp_ref.ancillary_milestone_types');
    }

    private function addConstraintsAndProjections(): void
    {
        $this->addCheckConstraint('hosp_ref.ancillary_milestone_types', 'ancillary_milestone_types_department_check', "department IN ('rad', 'lab', 'pathology', 'blood_bank', 'rx')");
        $this->addCheckConstraint('hosp_ref.ancillary_milestone_types', 'ancillary_milestone_types_source_precedence_array_check', "jsonb_typeof(source_precedence) = 'array'");
        $this->addCheckConstraint('hosp_ref.ancillary_milestone_types', 'ancillary_milestone_types_process_ids_array_check', "jsonb_typeof(process_ids) = 'array'");
        $this->addCheckConstraint('hosp_ref.ancillary_milestone_types', 'ancillary_milestone_types_display_metadata_object_check', "jsonb_typeof(display_metadata) = 'object'");

        $this->addCheckConstraint('hosp_ref.ancillary_barrier_reasons', 'ancillary_barrier_reasons_department_check', "department IN ('rad', 'lab', 'pathology', 'blood_bank', 'rx')");
        $this->addCheckConstraint('hosp_ref.ancillary_barrier_reasons', 'ancillary_barrier_reasons_category_check', "category IN ('medical', 'logistical', 'placement', 'social')");
        $this->addCheckConstraint('hosp_ref.ancillary_barrier_reasons', 'ancillary_barrier_reasons_metadata_object_check', "jsonb_typeof(metadata) = 'object'");

        $this->addCheckConstraint('prod.ancillary_orders', 'ancillary_orders_department_check', "department IN ('rad', 'lab', 'pathology', 'blood_bank', 'rx')");
        $this->addCheckConstraint('prod.ancillary_orders', 'ancillary_orders_work_item_type_check', "work_item_type IN ('imaging_order', 'lab_order', 'ap_case', 'blood_bank_request', 'medication_order')");
        $this->addCheckConstraint('prod.ancillary_orders', 'ancillary_orders_department_work_item_check', <<<'SQL'
            (department = 'rad' AND work_item_type = 'imaging_order') OR
            (department = 'lab' AND work_item_type = 'lab_order') OR
            (department = 'pathology' AND work_item_type = 'ap_case') OR
            (department = 'blood_bank' AND work_item_type = 'blood_bank_request') OR
            (department = 'rx' AND work_item_type = 'medication_order')
        SQL);
        $this->addCheckConstraint('prod.ancillary_orders', 'ancillary_orders_patient_class_check', "patient_class IN ('emergency', 'inpatient', 'outpatient', 'observation', 'perioperative', 'unknown')");
        $this->addCheckConstraint('prod.ancillary_orders', 'ancillary_orders_priority_check', "priority IN ('stat', 'urgent', 'routine', 'timed', 'first_dose', 'sepsis', 'discharge', 'unknown')");
        $this->addCheckConstraint('prod.ancillary_orders', 'ancillary_orders_terminal_time_check', 'terminal_at IS NULL OR terminal_at >= ordered_at');
        $this->addCheckConstraint('prod.ancillary_orders', 'ancillary_orders_current_milestone_pair_check', '(current_milestone_code IS NULL) = (current_milestone_at IS NULL)');
        $this->addCheckConstraint('prod.ancillary_orders', 'ancillary_orders_source_cutoff_check', 'source_cutoff_at >= ordered_at');
        $this->addCheckConstraint('prod.ancillary_orders', 'ancillary_orders_metadata_object_check', "jsonb_typeof(metadata) = 'object'");

        $this->addCheckConstraint('prod.ancillary_milestones', 'ancillary_milestones_time_check', "received_at >= occurred_at - INTERVAL '365 days'");
        $this->addCheckConstraint('prod.ancillary_milestones', 'ancillary_milestones_metadata_object_check', "jsonb_typeof(metadata) = 'object'");

        $this->addCheckConstraint('prod.ancillary_sla_definitions', 'ancillary_sla_definitions_department_check', "department IN ('rad', 'lab', 'pathology', 'blood_bank', 'rx')");
        $this->addCheckConstraint('prod.ancillary_sla_definitions', 'ancillary_sla_definitions_priority_check', "priority IS NULL OR priority IN ('stat', 'urgent', 'routine', 'timed', 'first_dose', 'sepsis', 'discharge', 'unknown')");
        $this->addCheckConstraint('prod.ancillary_sla_definitions', 'ancillary_sla_definitions_patient_class_check', "patient_class IS NULL OR patient_class IN ('emergency', 'inpatient', 'outpatient', 'observation', 'perioperative', 'unknown')");
        $this->addCheckConstraint('prod.ancillary_sla_definitions', 'ancillary_sla_definitions_statistic_check', "statistic IN ('item_clock', 'compliance_rate', 'median', 'p90', 'count', 'oldest_age')");
        $this->addCheckConstraint('prod.ancillary_sla_definitions', 'ancillary_sla_definitions_direction_check', "direction IN ('lower_is_better', 'higher_is_better', 'target_range')");
        $this->addCheckConstraint('prod.ancillary_sla_definitions', 'ancillary_sla_definitions_threshold_order_check', 'warning_minutes IS NULL OR breach_minutes IS NULL OR warning_minutes <= breach_minutes');
        $this->addCheckConstraint('prod.ancillary_sla_definitions', 'ancillary_sla_definitions_effective_range_check', 'effective_to IS NULL OR effective_to > effective_from');
        $this->addCheckConstraint('prod.ancillary_sla_definitions', 'ancillary_sla_definitions_scope_object_check', "jsonb_typeof(scope) = 'object'");

        $this->addCheckConstraint('prod.ancillary_breaches', 'ancillary_breaches_status_check', "status IN ('open', 'cleared')");
        $this->addCheckConstraint('prod.ancillary_breaches', 'ancillary_breaches_lifecycle_check', <<<'SQL'
            (status = 'open' AND cleared_at IS NULL AND stop_assertion_id IS NULL AND elapsed_minutes_at_clear IS NULL) OR
            (status = 'cleared' AND cleared_at IS NOT NULL AND stop_assertion_id IS NOT NULL AND elapsed_minutes_at_clear IS NOT NULL)
        SQL);
        $this->addCheckConstraint('prod.ancillary_breaches', 'ancillary_breaches_warning_time_check', 'warning_at IS NULL OR warning_at <= breached_at');
        $this->addCheckConstraint('prod.ancillary_breaches', 'ancillary_breaches_clear_time_check', 'cleared_at IS NULL OR cleared_at >= breached_at');
        $this->addCheckConstraint('prod.ancillary_breaches', 'ancillary_breaches_elapsed_check', 'elapsed_minutes_at_open >= 0 AND (elapsed_minutes_at_clear IS NULL OR elapsed_minutes_at_clear >= 0)');
        $this->addCheckConstraint('prod.ancillary_breaches', 'ancillary_breaches_metadata_object_check', "jsonb_typeof(metadata) = 'object'");

        $this->addForeignKey('prod.ancillary_orders', 'ancillary_orders_current_milestone_type_fk', 'current_milestone_code', 'hosp_ref.ancillary_milestone_types', 'code');
        $this->addForeignKey('prod.ancillary_milestones', 'ancillary_milestones_milestone_type_fk', 'milestone_code', 'hosp_ref.ancillary_milestone_types', 'code');
        $this->addForeignKey('prod.ancillary_sla_definitions', 'ancillary_sla_definitions_start_milestone_type_fk', 'start_milestone_code', 'hosp_ref.ancillary_milestone_types', 'code');
        $this->addForeignKey('prod.ancillary_sla_definitions', 'ancillary_sla_definitions_stop_milestone_type_fk', 'stop_milestone_code', 'hosp_ref.ancillary_milestone_types', 'code');

        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS ancillary_orders_open_idx
                ON prod.ancillary_orders (department, priority, current_milestone_at, ancillary_order_id)
                WHERE terminal_at IS NULL
        SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS ancillary_orders_reconciliation_key_idx
                ON prod.ancillary_orders (department, (metadata->>'reconciliation_key'))
                WHERE jsonb_exists(metadata, 'reconciliation_key')
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS ancillary_breaches_one_open_definition_idx
                ON prod.ancillary_breaches (ancillary_order_id, ancillary_sla_definition_id)
                WHERE status = 'open'
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prod.reject_ancillary_milestone_mutation()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE'
                   AND pg_trigger_depth() > 1
                   AND current_setting('zephyrus.allow_ancillary_demo_reset', true) = 'on' THEN
                    RETURN OLD;
                END IF;

                RAISE EXCEPTION 'prod.ancillary_milestones is append-only';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS ancillary_milestones_append_only ON prod.ancillary_milestones;
            CREATE TRIGGER ancillary_milestones_append_only
            BEFORE UPDATE OR DELETE ON prod.ancillary_milestones
            FOR EACH ROW EXECUTE FUNCTION prod.reject_ancillary_milestone_mutation();

            CREATE OR REPLACE FUNCTION prod.guard_ancillary_order_delete()
            RETURNS trigger AS $$
            BEGIN
                IF current_setting('zephyrus.allow_ancillary_demo_reset', true) = 'on'
                   AND OLD.demo_owner IS NOT NULL THEN
                    RETURN OLD;
                END IF;

                RAISE EXCEPTION 'ancillary orders with audit facts may only be deleted by the guarded owned-demo reset';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS ancillary_orders_owned_delete ON prod.ancillary_orders;
            CREATE TRIGGER ancillary_orders_owned_delete
            BEFORE DELETE ON prod.ancillary_orders
            FOR EACH ROW EXECUTE FUNCTION prod.guard_ancillary_order_delete();

            CREATE OR REPLACE FUNCTION prod.enforce_ancillary_sla_definition()
            RETURNS trigger AS $$
            DECLARE
                start_department text;
                stop_department text;
                scope_lock text;
            BEGIN
                SELECT department INTO start_department
                FROM hosp_ref.ancillary_milestone_types
                WHERE code = NEW.start_milestone_code;

                SELECT department INTO stop_department
                FROM hosp_ref.ancillary_milestone_types
                WHERE code = NEW.stop_milestone_code;

                IF start_department IS DISTINCT FROM NEW.department
                   OR stop_department IS DISTINCT FROM NEW.department THEN
                    RAISE EXCEPTION 'SLA milestone departments must match definition department %', NEW.department;
                END IF;

                scope_lock := concat_ws('|', NEW.department, NEW.metric_key, COALESCE(NEW.priority, '*'), COALESCE(NEW.patient_class, '*'), NEW.scope::text);
                PERFORM pg_advisory_xact_lock(hashtextextended(scope_lock, 0));

                IF EXISTS (
                    SELECT 1
                    FROM prod.ancillary_sla_definitions existing
                    WHERE existing.ancillary_sla_definition_id <> COALESCE(NEW.ancillary_sla_definition_id, 0)
                      AND existing.department = NEW.department
                      AND existing.metric_key = NEW.metric_key
                      AND existing.priority IS NOT DISTINCT FROM NEW.priority
                      AND existing.patient_class IS NOT DISTINCT FROM NEW.patient_class
                      AND existing.scope = NEW.scope
                      AND tstzrange(existing.effective_from, COALESCE(existing.effective_to, 'infinity'::timestamptz), '[)')
                          && tstzrange(NEW.effective_from, COALESCE(NEW.effective_to, 'infinity'::timestamptz), '[)')
                ) THEN
                    RAISE EXCEPTION 'overlapping ancillary SLA effective range for metric %', NEW.metric_key;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS ancillary_sla_definitions_no_overlap ON prod.ancillary_sla_definitions;
            CREATE TRIGGER ancillary_sla_definitions_no_overlap
            BEFORE INSERT OR UPDATE ON prod.ancillary_sla_definitions
            FOR EACH ROW EXECUTE FUNCTION prod.enforce_ancillary_sla_definition();

            CREATE OR REPLACE VIEW prod.ancillary_current_assertions AS
            WITH ranked AS (
                SELECT
                    milestones.*,
                    row_number() OVER (
                        PARTITION BY milestones.ancillary_order_id, milestones.milestone_code
                        ORDER BY milestones.source_rank ASC, milestones.received_at DESC, milestones.ancillary_milestone_id DESC
                    ) AS selection_rank,
                    count(*) OVER (
                        PARTITION BY milestones.ancillary_order_id, milestones.milestone_code
                    ) AS assertion_count,
                    EXTRACT(EPOCH FROM (
                        max(milestones.occurred_at) OVER (
                            PARTITION BY milestones.ancillary_order_id, milestones.milestone_code
                        ) - min(milestones.occurred_at) OVER (
                            PARTITION BY milestones.ancillary_order_id, milestones.milestone_code
                        )
                    ))::bigint AS disagreement_seconds
                FROM prod.ancillary_milestones milestones
            )
            SELECT
                ancillary_milestone_id,
                milestone_uuid,
                ancillary_order_id,
                milestone_code,
                occurred_at,
                received_at,
                source_id,
                canonical_event_id,
                provenance_record_id,
                assertion_key,
                source_rank,
                assertion_count,
                disagreement_seconds,
                metadata,
                created_at,
                updated_at
            FROM ranked
            WHERE selection_rank = 1;

            COMMENT ON TABLE prod.ancillary_orders IS
                'PHI-minimized current projection of source-owned ancillary work items. source_order_key and patient_ref are source-scoped pseudonymous reconciliation identifiers, never browser-safe direct identifiers.';
            COMMENT ON COLUMN prod.ancillary_orders.source_cutoff_at IS
                'Newest source evidence included in this projection; consumers must qualify freshness against this timestamp.';
            COMMENT ON TABLE prod.ancillary_milestones IS
                'Append-only source assertion ledger. occurred_at is source-asserted time; received_at is Zephyrus receipt time. Corrections append new assertions.';
            COMMENT ON COLUMN prod.ancillary_milestones.assertion_key IS
                'Stable idempotency identity derived from canonical event, milestone code, source, and work-item identity.';
            COMMENT ON TABLE prod.ancillary_sla_definitions IS
                'Effective-dated governed clock policies. Active definitions are versioned and never overwritten in place.';
            COMMENT ON TABLE prod.ancillary_breaches IS
                'Materialized warning/breach lifecycle state referencing the exact policy and milestone assertions used for reconstruction.';
            COMMENT ON VIEW prod.ancillary_current_assertions IS
                'Rebuildable selected assertion per order and milestone. Lower source_rank wins; equal rank uses newest receipt while preserving all ledger rows.';
        SQL);
    }

    private function addCheckConstraint(string $table, string $name, string $expression): void
    {
        [$schema, $relation] = explode('.', $table, 2);
        $exists = DB::table('pg_constraint as constraint')
            ->join('pg_class as relation', 'relation.oid', '=', 'constraint.conrelid')
            ->join('pg_namespace as namespace', 'namespace.oid', '=', 'relation.relnamespace')
            ->where('namespace.nspname', $schema)
            ->where('relation.relname', $relation)
            ->where('constraint.conname', $name)
            ->exists();

        if (! $exists) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    private function addForeignKey(
        string $table,
        string $name,
        string $column,
        string $targetTable,
        string $targetColumn,
    ): void {
        [$schema, $relation] = explode('.', $table, 2);
        $exists = DB::table('pg_constraint as constraint')
            ->join('pg_class as relation', 'relation.oid', '=', 'constraint.conrelid')
            ->join('pg_namespace as namespace', 'namespace.oid', '=', 'relation.relnamespace')
            ->where('namespace.nspname', $schema)
            ->where('relation.relname', $relation)
            ->where('constraint.conname', $name)
            ->exists();

        if (! $exists) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} FOREIGN KEY ({$column}) REFERENCES {$targetTable} ({$targetColumn}) ON DELETE RESTRICT");
        }
    }
};
