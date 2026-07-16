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

        $this->createFormulary();
        $this->createRxOrders();
        $this->createRxVerifications();
        $this->createRxPreps();
        $this->createAdcStations();
        $this->createRxDispenses();
        $this->createRxAdministrations();
        $this->createAdcTransactions();
        $this->createRxDischargeQueue();
        $this->addChecks();
        $this->addProjectionGuards();
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Pharmacy, ADC, administration, and discharge facts require application rollback and a forward-repair migration outside local/testing environments.');
        }

        $factTables = [
            'prod.adc_transactions',
            'prod.rx_discharge_queue',
            'prod.rx_administrations',
            'prod.rx_dispenses',
            'prod.rx_preps',
            'prod.rx_verifications',
            'prod.rx_orders',
            'prod.adc_stations',
        ];

        foreach ($factTables as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("{$table} contains facts; preserve the satellite data and use a forward-repair migration.");
            }
        }

        foreach ($factTables as $table) {
            Schema::dropIfExists($table);
        }
        Schema::dropIfExists('hosp_ref.rx_formulary');

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS prod.enforce_rx_order_department();
            DROP FUNCTION IF EXISTS prod.enforce_rx_discharge_queue_order();
        SQL);
    }

    private function createFormulary(): void
    {
        if (Schema::hasTable('hosp_ref.rx_formulary')) {
            return;
        }

        Schema::create('hosp_ref.rx_formulary', function (Blueprint $table): void {
            $table->id('rx_formulary_id');
            $table->uuid('formulary_uuid')->unique();
            $table->string('formulary_key', 160)->unique();
            $table->string('local_code', 80);
            $table->string('rxnorm_cui', 32)->nullable();
            $table->string('ndc_code', 32)->nullable();
            $table->string('terminology_status', 24)->default('unmapped_local');
            $table->string('label', 190);
            $table->string('therapeutic_class', 80);
            $table->string('dosage_form', 80)->nullable();
            $table->string('default_route', 80)->nullable();
            $table->string('default_prep_branch', 24)->default('unknown');
            $table->boolean('is_controlled')->default(false);
            $table->string('controlled_schedule', 8)->nullable();
            $table->boolean('is_hazardous')->default(false);
            $table->boolean('is_high_alert')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->unique(['local_code', 'effective_from'], 'rx_formulary_local_code_version_unique');
            $table->index(['therapeutic_class', 'is_active'], 'rx_formulary_class_active_idx');
            $table->index(['rxnorm_cui', 'is_active'], 'rx_formulary_rxnorm_active_idx');
            $table->index(['is_controlled', 'is_active'], 'rx_formulary_controlled_active_idx');
        });
    }

    private function createRxOrders(): void
    {
        if (Schema::hasTable('prod.rx_orders')) {
            return;
        }

        Schema::create('prod.rx_orders', function (Blueprint $table): void {
            $table->id('rx_order_id');
            $table->uuid('rx_order_uuid')->unique();
            $table->foreignId('ancillary_order_id')->unique()->constrained('prod.ancillary_orders', 'ancillary_order_id')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('integration.sources', 'source_id')->restrictOnDelete();
            $table->string('source_order_key', 190);
            $table->foreignId('encounter_id')->nullable()->constrained('prod.encounters', 'encounter_id')->nullOnDelete();
            $table->foreignId('rx_formulary_id')->nullable()->constrained('hosp_ref.rx_formulary', 'rx_formulary_id')->restrictOnDelete();
            $table->string('local_code', 80);
            $table->string('rxnorm_cui', 32)->nullable();
            $table->string('ndc_code', 32)->nullable();
            $table->string('terminology_status', 24)->default('unmapped_local');
            $table->string('medication_label', 190);
            $table->string('dosage_form', 80)->nullable();
            $table->string('route', 80)->nullable();
            $table->string('clock_class', 24)->default('routine');
            $table->string('preparation_branch', 24)->default('unknown');
            $table->string('order_status', 24)->default('ordered');
            $table->boolean('is_controlled')->default(false);
            $table->string('controlled_schedule', 8)->nullable();
            $table->boolean('is_hazardous')->default(false);
            $table->boolean('on_shortage')->default(false);
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('held_at')->nullable();
            $table->timestampTz('discontinued_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('demo_owner', 120)->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->unique(['source_id', 'source_order_key'], 'rx_orders_source_identity_unique');
            $table->index(['clock_class', 'order_status', 'due_at'], 'rx_orders_clock_worklist_idx');
            $table->index(['preparation_branch', 'order_status'], 'rx_orders_prep_branch_idx');
            $table->index(['encounter_id', 'order_status'], 'rx_orders_encounter_status_idx');
            $table->index(['rx_formulary_id', 'order_status'], 'rx_orders_formulary_status_idx');
            $table->index(['demo_owner', 'created_at'], 'rx_orders_demo_owner_idx');
        });

        DB::statement(<<<'SQL'
            CREATE INDEX rx_orders_open_stat_idx
            ON prod.rx_orders (clock_class, created_at, rx_order_id)
            WHERE clock_class IN ('stat', 'sepsis')
              AND order_status NOT IN ('administered', 'discontinued', 'cancelled', 'completed')
        SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX rx_orders_shortage_open_idx
            ON prod.rx_orders (order_status, created_at, rx_order_id)
            WHERE on_shortage = true
              AND order_status NOT IN ('administered', 'discontinued', 'cancelled', 'completed')
        SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX rx_orders_controlled_idx
            ON prod.rx_orders (order_status, created_at, rx_order_id)
            WHERE is_controlled = true
        SQL);
    }

    private function createRxVerifications(): void
    {
        if (Schema::hasTable('prod.rx_verifications')) {
            return;
        }

        Schema::create('prod.rx_verifications', function (Blueprint $table): void {
            $table->id('rx_verification_id');
            $table->uuid('verification_uuid')->unique();
            $table->foreignId('rx_order_id')->constrained('prod.rx_orders', 'rx_order_id')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('integration.sources', 'source_id')->restrictOnDelete();
            $table->string('source_verification_key', 190);
            $table->string('queue_ref', 120)->nullable();
            $table->string('verification_state', 24)->default('queued');
            $table->string('verifier_ref', 190)->nullable();
            $table->timestampTz('queued_at');
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('removed_at')->nullable();
            $table->string('demo_owner', 120)->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->unique(['source_id', 'source_verification_key'], 'rx_verifications_source_identity_unique');
            $table->index(['rx_order_id', 'verification_state', 'queued_at'], 'rx_verifications_order_state_idx');
            $table->index(['demo_owner', 'queued_at'], 'rx_verifications_demo_owner_idx');
        });

        DB::statement(<<<'SQL'
            CREATE INDEX rx_verifications_open_queue_idx
            ON prod.rx_verifications (queued_at, rx_verification_id)
            WHERE verification_state = 'queued'
        SQL);
    }

    private function createRxPreps(): void
    {
        if (Schema::hasTable('prod.rx_preps')) {
            return;
        }

        Schema::create('prod.rx_preps', function (Blueprint $table): void {
            $table->id('rx_prep_id');
            $table->uuid('prep_uuid')->unique();
            $table->foreignId('rx_order_id')->constrained('prod.rx_orders', 'rx_order_id')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('integration.sources', 'source_id')->restrictOnDelete();
            $table->string('source_prep_key', 190);
            $table->string('prep_type', 24)->default('other');
            $table->string('prep_branch', 24)->default('unknown');
            $table->string('batch_ref', 190)->nullable();
            $table->string('prep_state', 24)->default('pending');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('checked_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('bud_expires_at')->nullable();
            $table->string('demo_owner', 120)->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->unique(['source_id', 'source_prep_key'], 'rx_preps_source_identity_unique');
            $table->index(['rx_order_id', 'prep_state'], 'rx_preps_order_state_idx');
            $table->index(['batch_ref', 'prep_state'], 'rx_preps_batch_idx');
            $table->index(['demo_owner', 'created_at'], 'rx_preps_demo_owner_idx');
        });

        DB::statement(<<<'SQL'
            CREATE INDEX rx_preps_active_work_idx
            ON prod.rx_preps (prep_branch, prep_type, created_at, rx_prep_id)
            WHERE prep_state IN ('pending', 'in_progress')
        SQL);
    }

    private function createAdcStations(): void
    {
        if (Schema::hasTable('prod.adc_stations')) {
            return;
        }

        Schema::create('prod.adc_stations', function (Blueprint $table): void {
            $table->id('adc_station_id');
            $table->uuid('station_uuid')->unique();
            $table->foreignId('source_id')->constrained('integration.sources', 'source_id')->restrictOnDelete();
            $table->string('source_station_key', 190);
            $table->foreignId('unit_id')->nullable()->constrained('prod.units', 'unit_id')->nullOnDelete();
            $table->string('label', 160);
            $table->string('station_type', 24)->default('general');
            $table->string('status', 24)->default('operational');
            $table->boolean('is_profiled')->default(true);
            $table->boolean('controlled_capable')->default(true);
            $table->string('demo_owner', 120)->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->unique(['source_id', 'source_station_key'], 'adc_stations_source_identity_unique');
            $table->index(['unit_id', 'status'], 'adc_stations_unit_status_idx');
            $table->index(['demo_owner', 'status'], 'adc_stations_demo_owner_idx');
        });
    }

    private function createRxDispenses(): void
    {
        if (Schema::hasTable('prod.rx_dispenses')) {
            return;
        }

        Schema::create('prod.rx_dispenses', function (Blueprint $table): void {
            $table->id('rx_dispense_id');
            $table->uuid('dispense_uuid')->unique();
            $table->foreignId('rx_order_id')->constrained('prod.rx_orders', 'rx_order_id')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('integration.sources', 'source_id')->restrictOnDelete();
            $table->string('source_dispense_key', 190);
            $table->string('dispense_channel', 24);
            $table->foreignId('adc_station_id')->nullable()->constrained('prod.adc_stations', 'adc_station_id')->restrictOnDelete();
            $table->string('status', 24)->default('dispensed');
            $table->string('delivery_method', 80)->nullable();
            $table->timestampTz('dispensed_at');
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('returned_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('demo_owner', 120)->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->unique(['source_id', 'source_dispense_key'], 'rx_dispenses_source_identity_unique');
            $table->index(['rx_order_id', 'status', 'dispensed_at'], 'rx_dispenses_order_status_idx');
            $table->index(['adc_station_id', 'dispensed_at'], 'rx_dispenses_station_idx');
            $table->index(['demo_owner', 'dispensed_at'], 'rx_dispenses_demo_owner_idx');
        });

        DB::statement(<<<'SQL'
            CREATE INDEX rx_dispenses_pending_delivery_idx
            ON prod.rx_dispenses (dispensed_at, rx_dispense_id)
            WHERE status = 'dispensed' AND delivered_at IS NULL
        SQL);
    }

    private function createRxAdministrations(): void
    {
        if (Schema::hasTable('prod.rx_administrations')) {
            return;
        }

        Schema::create('prod.rx_administrations', function (Blueprint $table): void {
            $table->id('rx_administration_id');
            $table->uuid('administration_uuid')->unique();
            $table->foreignId('rx_order_id')->constrained('prod.rx_orders', 'rx_order_id')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('integration.sources', 'source_id')->restrictOnDelete();
            $table->string('source_administration_key', 190);
            $table->string('source_row_version', 80)->nullable();
            $table->string('import_batch_key', 190);
            $table->string('administration_source_class', 24)->default('bcma_warehouse');
            $table->string('administration_status', 24)->default('given');
            $table->string('administration_route', 80)->nullable();
            $table->timestampTz('administered_at');
            $table->timestampTz('source_cutoff_at');
            $table->string('demo_owner', 120)->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->index(['rx_order_id', 'administration_status', 'administered_at'], 'rx_administrations_order_status_idx');
            $table->index(['import_batch_key', 'administered_at'], 'rx_administrations_import_batch_idx');
            $table->index(['source_id', 'source_cutoff_at'], 'rx_administrations_freshness_idx');
            $table->index(['demo_owner', 'administered_at'], 'rx_administrations_demo_owner_idx');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX rx_administrations_source_version_unique
            ON prod.rx_administrations (source_id, source_administration_key, COALESCE(source_row_version, ''))
        SQL);
    }

    private function createAdcTransactions(): void
    {
        if (Schema::hasTable('prod.adc_transactions')) {
            return;
        }

        Schema::create('prod.adc_transactions', function (Blueprint $table): void {
            $table->id('adc_transaction_id');
            $table->uuid('transaction_uuid')->unique();
            $table->foreignId('adc_station_id')->constrained('prod.adc_stations', 'adc_station_id')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('integration.sources', 'source_id')->restrictOnDelete();
            $table->string('source_transaction_key', 190);
            $table->foreignId('rx_order_id')->nullable()->constrained('prod.rx_orders', 'rx_order_id')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('prod.units', 'unit_id')->nullOnDelete();
            $table->string('transaction_type', 32);
            $table->boolean('is_controlled')->default(false);
            $table->decimal('quantity', 8, 2)->nullable();
            $table->string('discrepancy_key', 190)->nullable();
            $table->timestampTz('occurred_at');
            $table->string('demo_owner', 120)->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->unique(['source_id', 'source_transaction_key'], 'adc_transactions_source_identity_unique');
            $table->index(['adc_station_id', 'transaction_type', 'occurred_at'], 'adc_transactions_station_rollup_idx');
            $table->index(['unit_id', 'transaction_type', 'occurred_at'], 'adc_transactions_unit_rollup_idx');
            $table->index(['rx_order_id', 'occurred_at'], 'adc_transactions_order_link_idx');
            $table->index(['demo_owner', 'occurred_at'], 'adc_transactions_demo_owner_idx');
        });

        DB::statement(<<<'SQL'
            CREATE INDEX adc_transactions_open_discrepancy_idx
            ON prod.adc_transactions (adc_station_id, occurred_at, adc_transaction_id)
            WHERE transaction_type = 'discrepancy_open'
        SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX adc_transactions_stockout_idx
            ON prod.adc_transactions (adc_station_id, occurred_at, adc_transaction_id)
            WHERE transaction_type = 'stockout'
        SQL);
    }

    private function createRxDischargeQueue(): void
    {
        if (Schema::hasTable('prod.rx_discharge_queue')) {
            return;
        }

        Schema::create('prod.rx_discharge_queue', function (Blueprint $table): void {
            $table->id('rx_discharge_queue_id');
            $table->uuid('discharge_queue_uuid')->unique();
            $table->foreignId('rx_order_id')->unique()->constrained('prod.rx_orders', 'rx_order_id')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('integration.sources', 'source_id')->restrictOnDelete();
            $table->string('source_queue_key', 190);
            $table->foreignId('encounter_id')->nullable()->constrained('prod.encounters', 'encounter_id')->nullOnDelete();
            $table->string('pipeline_status', 32)->default('not_started');
            $table->timestampTz('status_changed_at');
            $table->timestampTz('prior_auth_pending_at')->nullable();
            $table->timestampTz('verification_started_at')->nullable();
            $table->timestampTz('filling_started_at')->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('planned_discharge_at')->nullable();
            $table->string('demo_owner', 120)->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->unique(['source_id', 'source_queue_key'], 'rx_discharge_queue_source_identity_unique');
            $table->index(['encounter_id', 'pipeline_status'], 'rx_discharge_queue_encounter_status_idx');
            $table->index(['pipeline_status', 'planned_discharge_at', 'rx_discharge_queue_id'], 'rx_discharge_queue_pipeline_idx');
            $table->index(['demo_owner', 'planned_discharge_at'], 'rx_discharge_queue_demo_owner_idx');
        });

        DB::statement(<<<'SQL'
            CREATE INDEX rx_discharge_queue_pending_candidate_idx
            ON prod.rx_discharge_queue (planned_discharge_at, rx_discharge_queue_id)
            WHERE pipeline_status NOT IN ('ready', 'delivered')
        SQL);
    }

    private function addChecks(): void
    {
        $terminologyEvidence = <<<'SQL'
            (terminology_status = 'mapped' AND (rxnorm_cui IS NOT NULL OR ndc_code IS NOT NULL)) OR
            (terminology_status = 'unmapped_local' AND rxnorm_cui IS NULL AND ndc_code IS NULL)
        SQL;

        $checks = [
            ['hosp_ref.rx_formulary', 'rx_formulary_terminology_status_check', "terminology_status IN ('mapped', 'unmapped_local')"],
            ['hosp_ref.rx_formulary', 'rx_formulary_terminology_evidence_check', $terminologyEvidence],
            ['hosp_ref.rx_formulary', 'rx_formulary_prep_branch_check', "default_prep_branch IN ('adc', 'iv_room', 'central', 'unknown')"],
            ['hosp_ref.rx_formulary', 'rx_formulary_controlled_schedule_check', "controlled_schedule IS NULL OR (is_controlled = true AND controlled_schedule IN ('II', 'III', 'IV', 'V'))"],
            ['hosp_ref.rx_formulary', 'rx_formulary_controlled_evidence_check', 'is_controlled = false OR controlled_schedule IS NOT NULL'],
            ['hosp_ref.rx_formulary', 'rx_formulary_effective_range_check', 'effective_to IS NULL OR effective_to > effective_from'],
            ['hosp_ref.rx_formulary', 'rx_formulary_metadata_object_check', "jsonb_typeof(metadata) = 'object'"],

            ['prod.rx_orders', 'rx_orders_clock_class_check', "clock_class IN ('stat', 'first_dose', 'sepsis', 'routine', 'timed', 'discharge')"],
            ['prod.rx_orders', 'rx_orders_prep_branch_check', "preparation_branch IN ('adc', 'iv_room', 'central', 'unknown')"],
            ['prod.rx_orders', 'rx_orders_status_check', "order_status IN ('ordered', 'queued', 'verified', 'preparing', 'ready', 'dispensed', 'delivered', 'administered', 'held', 'discontinued', 'cancelled', 'completed')"],
            ['prod.rx_orders', 'rx_orders_terminology_status_check', "terminology_status IN ('mapped', 'unmapped_local')"],
            ['prod.rx_orders', 'rx_orders_terminology_evidence_check', $terminologyEvidence],
            ['prod.rx_orders', 'rx_orders_controlled_schedule_check', "controlled_schedule IS NULL OR (is_controlled = true AND controlled_schedule IN ('II', 'III', 'IV', 'V'))"],
            ['prod.rx_orders', 'rx_orders_controlled_evidence_check', 'is_controlled = false OR controlled_schedule IS NOT NULL'],
            ['prod.rx_orders', 'rx_orders_timed_due_check', "clock_class <> 'timed' OR due_at IS NOT NULL"],
            ['prod.rx_orders', 'rx_orders_status_evidence_check', <<<'SQL'
                (order_status NOT IN ('held', 'discontinued', 'cancelled')) OR
                (order_status = 'held' AND held_at IS NOT NULL) OR
                (order_status = 'discontinued' AND discontinued_at IS NOT NULL) OR
                (order_status = 'cancelled' AND cancelled_at IS NOT NULL)
            SQL],
            ['prod.rx_orders', 'rx_orders_metadata_object_check', "jsonb_typeof(metadata) = 'object'"],

            ['prod.rx_verifications', 'rx_verifications_state_check', "verification_state IN ('queued', 'verified', 'removed', 'rejected')"],
            ['prod.rx_verifications', 'rx_verifications_verified_order_check', 'verified_at IS NULL OR verified_at >= queued_at'],
            ['prod.rx_verifications', 'rx_verifications_removed_order_check', 'removed_at IS NULL OR removed_at >= queued_at'],
            ['prod.rx_verifications', 'rx_verifications_state_evidence_check', <<<'SQL'
                (verification_state = 'queued') OR
                (verification_state = 'verified' AND verified_at IS NOT NULL) OR
                (verification_state IN ('removed', 'rejected') AND removed_at IS NOT NULL)
            SQL],
            ['prod.rx_verifications', 'rx_verifications_metadata_object_check', "jsonb_typeof(metadata) = 'object'"],

            ['prod.rx_preps', 'rx_preps_type_check', "prep_type IN ('iv_batch', 'chemo', 'tpn', 'compound', 'repack', 'other')"],
            ['prod.rx_preps', 'rx_preps_branch_check', "prep_branch IN ('iv_room', 'central', 'unknown')"],
            ['prod.rx_preps', 'rx_preps_state_check', "prep_state IN ('pending', 'in_progress', 'complete', 'checked', 'cancelled')"],
            ['prod.rx_preps', 'rx_preps_completed_order_check', 'completed_at IS NULL OR (started_at IS NOT NULL AND completed_at >= started_at)'],
            ['prod.rx_preps', 'rx_preps_checked_order_check', 'checked_at IS NULL OR (completed_at IS NOT NULL AND checked_at >= completed_at)'],
            ['prod.rx_preps', 'rx_preps_bud_order_check', 'bud_expires_at IS NULL OR started_at IS NULL OR bud_expires_at > started_at'],
            ['prod.rx_preps', 'rx_preps_state_evidence_check', <<<'SQL'
                (prep_state = 'pending') OR
                (prep_state = 'in_progress' AND started_at IS NOT NULL) OR
                (prep_state = 'complete' AND started_at IS NOT NULL AND completed_at IS NOT NULL) OR
                (prep_state = 'checked' AND completed_at IS NOT NULL AND checked_at IS NOT NULL) OR
                (prep_state = 'cancelled' AND cancelled_at IS NOT NULL)
            SQL],
            ['prod.rx_preps', 'rx_preps_metadata_object_check', "jsonb_typeof(metadata) = 'object'"],

            ['prod.adc_stations', 'adc_stations_type_check', "station_type IN ('general', 'anesthesia', 'procedural', 'emergency', 'other')"],
            ['prod.adc_stations', 'adc_stations_status_check', "status IN ('operational', 'downtime', 'retired')"],
            ['prod.adc_stations', 'adc_stations_metadata_object_check', "jsonb_typeof(metadata) = 'object'"],

            ['prod.rx_dispenses', 'rx_dispenses_channel_check', "dispense_channel IN ('adc', 'iv_room', 'central', 'robot', 'other')"],
            ['prod.rx_dispenses', 'rx_dispenses_status_check', "status IN ('dispensed', 'delivered', 'returned', 'cancelled')"],
            ['prod.rx_dispenses', 'rx_dispenses_adc_station_check', "dispense_channel <> 'adc' OR adc_station_id IS NOT NULL"],
            ['prod.rx_dispenses', 'rx_dispenses_delivered_order_check', 'delivered_at IS NULL OR delivered_at >= dispensed_at'],
            ['prod.rx_dispenses', 'rx_dispenses_returned_order_check', 'returned_at IS NULL OR returned_at >= dispensed_at'],
            ['prod.rx_dispenses', 'rx_dispenses_status_evidence_check', <<<'SQL'
                (status = 'dispensed') OR
                (status = 'delivered' AND delivered_at IS NOT NULL) OR
                (status = 'returned' AND returned_at IS NOT NULL) OR
                (status = 'cancelled' AND cancelled_at IS NOT NULL)
            SQL],
            ['prod.rx_dispenses', 'rx_dispenses_metadata_object_check', "jsonb_typeof(metadata) = 'object'"],

            ['prod.rx_administrations', 'rx_administrations_source_class_check', "administration_source_class IN ('bcma_warehouse', 'bcma_realtime', 'ras', 'emar', 'other')"],
            ['prod.rx_administrations', 'rx_administrations_status_check', "administration_status IN ('given', 'held', 'refused', 'missed')"],
            ['prod.rx_administrations', 'rx_administrations_cutoff_order_check', 'administered_at <= source_cutoff_at'],
            ['prod.rx_administrations', 'rx_administrations_import_batch_check', 'length(btrim(import_batch_key)) > 0'],
            ['prod.rx_administrations', 'rx_administrations_metadata_object_check', "jsonb_typeof(metadata) = 'object'"],

            ['prod.adc_transactions', 'adc_transactions_type_check', "transaction_type IN ('vend', 'refill', 'return', 'waste', 'override', 'discrepancy_open', 'discrepancy_resolved', 'stockout')"],
            ['prod.adc_transactions', 'adc_transactions_quantity_check', 'quantity IS NULL OR quantity > 0'],
            ['prod.adc_transactions', 'adc_transactions_discrepancy_key_check', "transaction_type NOT IN ('discrepancy_open', 'discrepancy_resolved') OR discrepancy_key IS NOT NULL"],
            ['prod.adc_transactions', 'adc_transactions_metadata_object_check', "jsonb_typeof(metadata) = 'object'"],

            ['prod.rx_discharge_queue', 'rx_discharge_queue_status_check', "pipeline_status IN ('not_started', 'prior_auth_pending', 'verification', 'filling', 'ready', 'delivered', 'unknown')"],
            ['prod.rx_discharge_queue', 'rx_discharge_queue_delivered_order_check', 'delivered_at IS NULL OR ready_at IS NULL OR delivered_at >= ready_at'],
            ['prod.rx_discharge_queue', 'rx_discharge_queue_status_evidence_check', <<<'SQL'
                (pipeline_status IN ('not_started', 'unknown')) OR
                (pipeline_status = 'prior_auth_pending' AND prior_auth_pending_at IS NOT NULL) OR
                (pipeline_status = 'verification' AND verification_started_at IS NOT NULL) OR
                (pipeline_status = 'filling' AND filling_started_at IS NOT NULL) OR
                (pipeline_status = 'ready' AND ready_at IS NOT NULL) OR
                (pipeline_status = 'delivered' AND ready_at IS NOT NULL AND delivered_at IS NOT NULL)
            SQL],
            ['prod.rx_discharge_queue', 'rx_discharge_queue_metadata_object_check', "jsonb_typeof(metadata) = 'object'"],
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
            CREATE OR REPLACE FUNCTION prod.enforce_rx_order_department()
            RETURNS trigger AS $$
            DECLARE
                order_department text;
                order_encounter_id bigint;
            BEGIN
                SELECT department, encounter_id INTO order_department, order_encounter_id
                FROM prod.ancillary_orders WHERE ancillary_order_id = NEW.ancillary_order_id;

                IF order_department IS DISTINCT FROM 'rx' THEN
                    RAISE EXCEPTION 'rx_orders requires a pharmacy ancillary order';
                END IF;
                IF NEW.encounter_id IS NOT NULL AND order_encounter_id IS DISTINCT FROM NEW.encounter_id THEN
                    RAISE EXCEPTION 'rx_orders encounter must match the ancillary order encounter';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS rx_orders_department_guard ON prod.rx_orders;
            CREATE TRIGGER rx_orders_department_guard
            BEFORE INSERT OR UPDATE OF ancillary_order_id, encounter_id ON prod.rx_orders
            FOR EACH ROW EXECUTE FUNCTION prod.enforce_rx_order_department();

            CREATE OR REPLACE FUNCTION prod.enforce_rx_discharge_queue_order()
            RETURNS trigger AS $$
            DECLARE
                order_clock_class text;
                order_encounter_id bigint;
            BEGIN
                SELECT clock_class, encounter_id INTO order_clock_class, order_encounter_id
                FROM prod.rx_orders WHERE rx_order_id = NEW.rx_order_id;

                IF order_clock_class IS DISTINCT FROM 'discharge' THEN
                    RAISE EXCEPTION 'rx_discharge_queue requires a discharge-clock medication order';
                END IF;
                IF NEW.encounter_id IS NOT NULL AND order_encounter_id IS DISTINCT FROM NEW.encounter_id THEN
                    RAISE EXCEPTION 'rx_discharge_queue encounter must match the medication order encounter';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS rx_discharge_queue_order_guard ON prod.rx_discharge_queue;
            CREATE TRIGGER rx_discharge_queue_order_guard
            BEFORE INSERT OR UPDATE OF rx_order_id, encounter_id ON prod.rx_discharge_queue
            FOR EACH ROW EXECUTE FUNCTION prod.enforce_rx_discharge_queue_order();
        SQL);
    }
};
