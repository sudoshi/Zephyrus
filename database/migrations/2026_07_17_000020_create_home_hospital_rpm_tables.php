<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Home Hospital Phase 0 — RPM kit/device inventory, enrollments, the
 * high-volume observation ledger, and patient-level clinical alerts
 * (ACUM-PRD-HAH-001 §5.1–5.2).
 *
 * prod.rpm_observations ships UNPARTITIONED (build brief §13 Q5 default):
 * no declarative table-partitioning convention exists in this repo, and one
 * must not be introduced silently. Retention policy: observations older than
 * 18 months are eligible for rollup into daily aggregates (metadata carries
 * the rollup marker); monthly range-partitioning is a proposed, reviewed
 * follow-up once volume warrants it. observation_uuid is the idempotency key
 * (vendor transmission id), mirroring raw.inbound_messages dedupe.
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        if (! Schema::hasTable('prod.rpm_kits')) {
            Schema::create('prod.rpm_kits', function (Blueprint $table): void {
                $table->id('rpm_kit_id');
                $table->uuid('kit_uuid')->unique();
                $table->string('kit_code', 60)->unique();
                $table->string('vendor', 80)->nullable();
                $table->string('model', 80)->nullable();
                $table->string('status', 40)->default('available');
                // Prefer cellular backhaul — broadband equity guardrail (§8).
                $table->string('connectivity', 40)->nullable();
                $table->smallInteger('battery_pct')->nullable();
                $table->timestampTz('last_seen_at')->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestampsTz();
                $table->boolean('is_deleted')->default(false);

                $table->index(['status', 'is_deleted'], 'rpm_kits_status_idx');
            });
        }

        if (! Schema::hasTable('prod.rpm_devices')) {
            Schema::create('prod.rpm_devices', function (Blueprint $table): void {
                $table->id('rpm_device_id');
                $table->uuid('device_uuid')->unique();
                $table->foreignId('rpm_kit_id')
                    ->constrained('prod.rpm_kits', 'rpm_kit_id')
                    ->restrictOnDelete();
                $table->string('device_type', 40);
                $table->string('serial_number', 120)->nullable();
                // FHIR Device id once projected (fhir.resource_links owns the map).
                $table->string('fhir_device_id', 190)->nullable();
                $table->string('status', 40)->default('active');
                $table->smallInteger('battery_pct')->nullable();
                $table->timestampTz('last_transmission_at')->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestampsTz();
                $table->boolean('is_deleted')->default(false);

                $table->index(['rpm_kit_id', 'status'], 'rpm_devices_kit_status_idx');
            });
        }

        if (! Schema::hasTable('prod.rpm_enrollments')) {
            Schema::create('prod.rpm_enrollments', function (Blueprint $table): void {
                $table->id('rpm_enrollment_id');
                $table->uuid('enrollment_uuid')->unique();
                $table->foreignId('home_episode_id')
                    ->constrained('prod.home_episodes', 'home_episode_id')
                    ->restrictOnDelete();
                $table->foreignId('rpm_kit_id')
                    ->constrained('prod.rpm_kits', 'rpm_kit_id')
                    ->restrictOnDelete();
                $table->string('patient_ref', 190);
                $table->string('status', 40)->default('pending');
                // Per-vital cadence + personalized thresholds + baseline window.
                $table->jsonb('monitoring_plan')->default(DB::raw("'{}'::jsonb"));
                // Patient-specific baselines calibrated over the first 24h (HEWS input).
                $table->jsonb('baseline')->default(DB::raw("'{}'::jsonb"));
                $table->timestampTz('started_at')->nullable();
                $table->timestampTz('ended_at')->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestampsTz();
                $table->boolean('is_deleted')->default(false);

                $table->index(['home_episode_id', 'status'], 'rpm_enrollments_episode_status_idx');
                $table->index(['rpm_kit_id', 'status'], 'rpm_enrollments_kit_status_idx');
            });
        }

        if (! Schema::hasTable('prod.rpm_observations')) {
            Schema::create('prod.rpm_observations', function (Blueprint $table): void {
                $table->id('rpm_observation_id');
                // Idempotency key = vendor transmission id (deduped at ingest).
                $table->uuid('observation_uuid')->unique();
                $table->foreignId('rpm_enrollment_id')
                    ->constrained('prod.rpm_enrollments', 'rpm_enrollment_id')
                    ->restrictOnDelete();
                $table->foreignId('rpm_device_id')->nullable()
                    ->constrained('prod.rpm_devices', 'rpm_device_id')
                    ->nullOnDelete();
                $table->string('patient_ref', 190);
                // LOINC vital-sign codes: HR 8867-4, SpO2 59408-5, SBP 8480-6,
                // DBP 8462-4, RR 9279-1, temp 8310-5, weight 29463-7.
                $table->string('loinc_code', 20);
                $table->string('display', 80)->nullable();
                $table->decimal('value', 10, 2);
                $table->string('unit', 20)->nullable();
                $table->timestampTz('observed_at');
                $table->timestampTz('received_at');
                // Transmission provenance (source + vendor transmission id).
                $table->string('source_key', 60)->nullable();
                $table->string('transmission_id', 190)->nullable();
                $table->string('quality_flag', 20)->default('ok');
                $table->boolean('is_breach')->default(false);
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestampsTz();
                $table->boolean('is_deleted')->default(false);

                $table->index(['rpm_enrollment_id', 'observed_at'], 'rpm_observations_enrollment_observed_idx');
                $table->index(['patient_ref', 'loinc_code', 'observed_at'], 'rpm_observations_patient_vital_idx');
                $table->index(['is_breach', 'observed_at'], 'rpm_observations_breach_idx');
            });
        }

        if (! Schema::hasTable('prod.rpm_alerts')) {
            Schema::create('prod.rpm_alerts', function (Blueprint $table): void {
                $table->id('rpm_alert_id');
                $table->uuid('alert_uuid')->unique();
                $table->foreignId('home_episode_id')
                    ->constrained('prod.home_episodes', 'home_episode_id')
                    ->restrictOnDelete();
                $table->foreignId('rpm_enrollment_id')->nullable()
                    ->constrained('prod.rpm_enrollments', 'rpm_enrollment_id')
                    ->nullOnDelete();
                $table->foreignId('rpm_observation_id')->nullable()
                    ->constrained('prod.rpm_observations', 'rpm_observation_id')
                    ->nullOnDelete();
                $table->string('patient_ref', 190);
                $table->string('rule_key', 80);
                $table->string('severity', 20);
                $table->string('status', 40)->default('open');
                $table->timestampTz('opened_at');
                $table->timestampTz('acknowledged_at')->nullable();
                $table->string('acknowledged_by', 190)->nullable();
                $table->timestampTz('resolved_at')->nullable();
                $table->string('resolved_by', 190)->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestampsTz();
                $table->boolean('is_deleted')->default(false);

                $table->index(['home_episode_id', 'status'], 'rpm_alerts_episode_status_idx');
                $table->index(['status', 'severity', 'opened_at'], 'rpm_alerts_status_severity_idx');
            });
        }

        $this->addCheckConstraint('prod.rpm_kits', 'rpm_kits_status_check',
            "status IN ('available','assigned','in_transit','maintenance','retired','lost')");
        $this->addCheckConstraint('prod.rpm_kits', 'rpm_kits_connectivity_check',
            "connectivity IS NULL OR connectivity IN ('cellular','wifi','hybrid')");
        $this->addCheckConstraint('prod.rpm_kits', 'rpm_kits_metadata_object_check',
            "jsonb_typeof(metadata) = 'object'");
        $this->addCheckConstraint('prod.rpm_devices', 'rpm_devices_device_type_check',
            "device_type IN ('bp_monitor','pulse_oximeter','thermometer','scale','ecg_patch','glucometer','stethoscope','tablet','other')");
        $this->addCheckConstraint('prod.rpm_devices', 'rpm_devices_status_check',
            "status IN ('active','inactive','fault','retired')");
        $this->addCheckConstraint('prod.rpm_devices', 'rpm_devices_metadata_object_check',
            "jsonb_typeof(metadata) = 'object'");
        $this->addCheckConstraint('prod.rpm_enrollments', 'rpm_enrollments_status_check',
            "status IN ('pending','active','paused','ended')");
        $this->addCheckConstraint('prod.rpm_enrollments', 'rpm_enrollments_metadata_object_check',
            "jsonb_typeof(metadata) = 'object'");
        $this->addCheckConstraint('prod.rpm_observations', 'rpm_observations_quality_flag_check',
            "quality_flag IN ('ok','suspect','artifact','stale')");
        $this->addCheckConstraint('prod.rpm_observations', 'rpm_observations_metadata_object_check',
            "jsonb_typeof(metadata) = 'object'");
        $this->addCheckConstraint('prod.rpm_alerts', 'rpm_alerts_severity_check',
            "severity IN ('watch','warning','critical')");
        $this->addCheckConstraint('prod.rpm_alerts', 'rpm_alerts_status_check',
            "status IN ('open','acknowledged','resolved','expired')");
        $this->addCheckConstraint('prod.rpm_alerts', 'rpm_alerts_metadata_object_check',
            "jsonb_typeof(metadata) = 'object'");
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.rpm_alerts');
        $this->safeDropIfExists('prod.rpm_observations');
        $this->safeDropIfExists('prod.rpm_enrollments');
        $this->safeDropIfExists('prod.rpm_devices');
        $this->safeDropIfExists('prod.rpm_kits');
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
};
