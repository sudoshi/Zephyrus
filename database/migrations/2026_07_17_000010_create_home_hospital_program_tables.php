<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Home Hospital Phase 0 — program lines, referral funnel, and episode spine
 * (ACUM-PRD-HAH-001 §7; docs/home-hospital/HOME-HOSPITAL-BUILD-PROMPT.md).
 *
 * The episode spine links to an encounter on the virtual_home unit so census,
 * occupancy, huddles, and the cockpit machinery work unmodified. Home tables
 * live ALONGSIDE the census spine (like prod.discharge_facts), never inside
 * prod.encounters. Operational paths carry pseudonymous patient_ref and
 * service zones only — never MRNs or street addresses.
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        if (! Schema::hasTable('prod.home_programs')) {
            Schema::create('prod.home_programs', function (Blueprint $table): void {
                $table->id('home_program_id');
                $table->uuid('program_uuid')->unique();
                $table->string('code', 60)->unique();
                $table->string('name', 160);
                $table->string('program_type', 40);
                // Payer-aware from day one (§2): coverage rules per payer class.
                $table->jsonb('payer_rules')->default(DB::raw("'{}'::jsonb"));
                // Qualifying condition codes for eligibility screening.
                $table->jsonb('conditions')->default(DB::raw("'[]'::jsonb"));
                // Slot capacity by service zone, e.g. {"north": 6, "central": 6}.
                $table->jsonb('zone_slot_capacity')->default(DB::raw("'{}'::jsonb"));
                $table->integer('slot_capacity')->default(0);
                $table->boolean('is_active')->default(true);
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestampsTz();
                $table->boolean('is_deleted')->default(false);

                $table->index(['program_type', 'is_active'], 'home_programs_type_active_idx');
            });
        }

        if (! Schema::hasTable('prod.home_referrals')) {
            Schema::create('prod.home_referrals', function (Blueprint $table): void {
                $table->id('home_referral_id');
                $table->uuid('referral_uuid')->unique();
                $table->foreignId('home_program_id')
                    ->constrained('prod.home_programs', 'home_program_id')
                    ->restrictOnDelete();
                $table->string('patient_ref', 190);
                // Source encounter (ED boarder / inpatient) the referral screens.
                $table->foreignId('encounter_id')->nullable()
                    ->constrained('prod.encounters', 'encounter_id')
                    ->nullOnDelete();
                $table->string('source', 40);
                $table->string('status', 40)->default('referred');
                // Coded decline reason feeds selection-bias analytics (§11).
                $table->string('decline_reason', 60)->nullable();
                $table->text('decline_note')->nullable();
                // Screening payload: zone, payer, home-safety, connectivity.
                $table->jsonb('screening')->default(DB::raw("'{}'::jsonb"));
                $table->string('payer_class', 40)->nullable();
                $table->string('service_zone', 60)->nullable();
                $table->string('referred_by', 190)->nullable();
                $table->timestampTz('referred_at');
                $table->timestampTz('status_changed_at')->nullable();
                $table->timestampTz('activated_at')->nullable();
                $table->timestampTz('declined_at')->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestampsTz();
                $table->boolean('is_deleted')->default(false);

                $table->index(['home_program_id', 'status'], 'home_referrals_program_status_idx');
                $table->index(['status', 'referred_at'], 'home_referrals_status_referred_idx');
                $table->index('patient_ref', 'home_referrals_patient_ref_idx');
            });
        }

        if (! Schema::hasTable('prod.home_episodes')) {
            Schema::create('prod.home_episodes', function (Blueprint $table): void {
                $table->id('home_episode_id');
                $table->uuid('episode_uuid')->unique();
                $table->foreignId('home_program_id')
                    ->constrained('prod.home_programs', 'home_program_id')
                    ->restrictOnDelete();
                $table->foreignId('home_referral_id')->nullable()
                    ->constrained('prod.home_referrals', 'home_referral_id')
                    ->nullOnDelete();
                // The encounter on the virtual_home unit — the census-spine link.
                $table->foreignId('encounter_id')->nullable()
                    ->constrained('prod.encounters', 'encounter_id')
                    ->nullOnDelete();
                $table->string('patient_ref', 190);
                $table->string('condition_code', 60);
                $table->string('condition_label', 160)->nullable();
                $table->string('drg_code', 20)->nullable();
                $table->string('admission_source', 40);
                $table->smallInteger('acuity_tier')->default(3); // 1 = sickest
                $table->string('status', 40)->default('pending_activation');
                $table->string('disposition', 40)->nullable();
                $table->string('service_zone', 60)->nullable();
                $table->decimal('target_los_days', 4, 1)->nullable();
                $table->date('expected_discharge_date')->nullable();
                $table->timestampTz('started_at')->nullable();
                $table->timestampTz('ended_at')->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestampsTz();
                $table->boolean('is_deleted')->default(false);

                $table->index(['home_program_id', 'status'], 'home_episodes_program_status_idx');
                $table->index(['status', 'started_at'], 'home_episodes_status_started_idx');
                $table->index('patient_ref', 'home_episodes_patient_ref_idx');
            });
        }

        $this->addCheckConstraint('prod.home_programs', 'home_programs_program_type_check',
            "program_type IN ('ahcah_acute','observation_at_home','post_discharge_rpm','chronic_rpm','snf_at_home')");
        $this->addCheckConstraint('prod.home_programs', 'home_programs_metadata_object_check',
            "jsonb_typeof(metadata) = 'object'");
        $this->addCheckConstraint('prod.home_referrals', 'home_referrals_source_check',
            "source IN ('ed_diversion','inpatient_stepdown','direct','ambulatory')");
        $this->addCheckConstraint('prod.home_referrals', 'home_referrals_status_check',
            "status IN ('referred','screened','eligible','consented','activated','declined','cancelled')");
        $this->addCheckConstraint('prod.home_referrals', 'home_referrals_metadata_object_check',
            "jsonb_typeof(metadata) = 'object'");
        $this->addCheckConstraint('prod.home_episodes', 'home_episodes_admission_source_check',
            "admission_source IN ('ed_diversion','inpatient_stepdown','direct','ambulatory')");
        $this->addCheckConstraint('prod.home_episodes', 'home_episodes_status_check',
            "status IN ('pending_activation','active','completed','cancelled')");
        $this->addCheckConstraint('prod.home_episodes', 'home_episodes_disposition_check',
            "disposition IS NULL OR disposition IN ('routine_discharge','ed_return','readmitted','transferred','deceased','other')");
        $this->addCheckConstraint('prod.home_episodes', 'home_episodes_acuity_tier_check',
            'acuity_tier BETWEEN 1 AND 5');
        $this->addCheckConstraint('prod.home_episodes', 'home_episodes_metadata_object_check',
            "jsonb_typeof(metadata) = 'object'");
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.home_episodes');
        $this->safeDropIfExists('prod.home_referrals');
        $this->safeDropIfExists('prod.home_programs');
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
