<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Home Hospital Phase 0 — field visits, escalations, and transitions
 * (ACUM-PRD-HAH-001 §4.2, §7).
 *
 * home_visits carries the CMS AHCAH waiver operating floor as first-class
 * telemetry: is_waiver_required marks the ≥2-in-person-visits-per-day + daily
 * MD evaluation floor, and on-time state is derived from scheduled vs actual.
 * home_escalations stores the full response timing chain (initiated →
 * dispatched → arrived → resolved) against the 30-minute emergency-response
 * requirement. home_transitions reuses the regional graph
 * (regional.facilities) and prod.transport_requests rather than rebuilding
 * destination selection.
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        if (! Schema::hasTable('prod.home_visits')) {
            Schema::create('prod.home_visits', function (Blueprint $table): void {
                $table->id('home_visit_id');
                $table->uuid('visit_uuid')->unique();
                $table->foreignId('home_episode_id')
                    ->constrained('prod.home_episodes', 'home_episode_id')
                    ->restrictOnDelete();
                $table->string('patient_ref', 190);
                $table->string('visit_type', 40);
                $table->boolean('is_waiver_required')->default(false);
                $table->string('status', 40)->default('scheduled');
                $table->timestampTz('scheduled_start');
                $table->timestampTz('scheduled_end')->nullable();
                $table->timestampTz('started_at')->nullable();
                $table->timestampTz('completed_at')->nullable();
                // Staffing-model-agnostic assignee ref (employed RN or contracted
                // community paramedic — open question §13 Q2).
                $table->string('assigned_to', 190)->nullable();
                $table->boolean('on_time')->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestampsTz();
                $table->boolean('is_deleted')->default(false);

                $table->index(['home_episode_id', 'scheduled_start'], 'home_visits_episode_scheduled_idx');
                $table->index(['status', 'scheduled_start'], 'home_visits_status_scheduled_idx');
                $table->index(['is_waiver_required', 'scheduled_start'], 'home_visits_waiver_idx');
            });
        }

        if (! Schema::hasTable('prod.home_escalations')) {
            Schema::create('prod.home_escalations', function (Blueprint $table): void {
                $table->id('home_escalation_id');
                $table->uuid('escalation_uuid')->unique();
                $table->foreignId('home_episode_id')
                    ->constrained('prod.home_episodes', 'home_episode_id')
                    ->restrictOnDelete();
                $table->foreignId('rpm_alert_id')->nullable()
                    ->constrained('prod.rpm_alerts', 'rpm_alert_id')
                    ->nullOnDelete();
                $table->string('patient_ref', 190);
                $table->string('trigger_type', 40);
                $table->string('response_mode', 40)->nullable();
                $table->string('status', 40)->default('open');
                // Full timing chain — response-time telemetry vs the 30-min floor.
                $table->timestampTz('initiated_at');
                $table->timestampTz('dispatched_at')->nullable();
                $table->timestampTz('arrived_at')->nullable();
                $table->timestampTz('resolved_at')->nullable();
                $table->integer('response_minutes')->nullable();
                $table->string('outcome', 40)->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestampsTz();
                $table->boolean('is_deleted')->default(false);

                $table->index(['home_episode_id', 'initiated_at'], 'home_escalations_episode_initiated_idx');
                $table->index(['status', 'initiated_at'], 'home_escalations_status_idx');
            });
        }

        if (! Schema::hasTable('prod.home_transitions')) {
            Schema::create('prod.home_transitions', function (Blueprint $table): void {
                $table->id('home_transition_id');
                $table->uuid('transition_uuid')->unique();
                $table->foreignId('home_episode_id')
                    ->constrained('prod.home_episodes', 'home_episode_id')
                    ->restrictOnDelete();
                $table->string('patient_ref', 190);
                $table->string('direction', 20); // inbound activation | outbound handoff
                $table->string('status', 40)->default('pending');
                // Inbound: consent, home-safety check, kit delivery, first visit.
                // Outbound: discharge readiness items. Checklist item => state.
                $table->jsonb('checklist')->default(DB::raw("'{}'::jsonb"));
                $table->string('handoff_owner', 190)->nullable();
                $table->string('receiving_entity_type', 40)->nullable();
                $table->foreignId('regional_facility_id')->nullable()
                    ->constrained('regional.facilities', 'regional_facility_id')
                    ->nullOnDelete();
                $table->foreignId('transport_request_id')->nullable()
                    ->constrained('prod.transport_requests', 'transport_request_id')
                    ->nullOnDelete();
                $table->jsonb('barriers')->default(DB::raw("'[]'::jsonb"));
                $table->timestampTz('started_at')->nullable();
                $table->timestampTz('completed_at')->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestampsTz();
                $table->boolean('is_deleted')->default(false);

                $table->index(['home_episode_id', 'direction'], 'home_transitions_episode_direction_idx');
                $table->index(['status', 'direction'], 'home_transitions_status_idx');
            });
        }

        $this->addCheckConstraint('prod.home_visits', 'home_visits_visit_type_check',
            "visit_type IN ('rn','community_paramedic','md_np_tele','md_np_in_person','lab_draw','delivery','other')");
        $this->addCheckConstraint('prod.home_visits', 'home_visits_status_check',
            "status IN ('scheduled','en_route','in_progress','completed','missed','cancelled')");
        $this->addCheckConstraint('prod.home_visits', 'home_visits_metadata_object_check',
            "jsonb_typeof(metadata) = 'object'");
        $this->addCheckConstraint('prod.home_escalations', 'home_escalations_trigger_type_check',
            "trigger_type IN ('critical_vital','clinical_deterioration','patient_request','caregiver_request','device_failure','other')");
        $this->addCheckConstraint('prod.home_escalations', 'home_escalations_response_mode_check',
            "response_mode IS NULL OR response_mode IN ('tele_assessment','field_dispatch','ems','ed_return')");
        $this->addCheckConstraint('prod.home_escalations', 'home_escalations_status_check',
            "status IN ('open','responding','resolved')");
        $this->addCheckConstraint('prod.home_escalations', 'home_escalations_outcome_check',
            "outcome IS NULL OR outcome IN ('managed_at_home','ed_return','readmitted','deceased','other')");
        $this->addCheckConstraint('prod.home_escalations', 'home_escalations_metadata_object_check',
            "jsonb_typeof(metadata) = 'object'");
        $this->addCheckConstraint('prod.home_transitions', 'home_transitions_direction_check',
            "direction IN ('inbound','outbound')");
        $this->addCheckConstraint('prod.home_transitions', 'home_transitions_status_check',
            "status IN ('pending','in_progress','completed','blocked')");
        $this->addCheckConstraint('prod.home_transitions', 'home_transitions_receiving_entity_check',
            "receiving_entity_type IS NULL OR receiving_entity_type IN ('pcp','home_health','snf','other')");
        $this->addCheckConstraint('prod.home_transitions', 'home_transitions_metadata_object_check',
            "jsonb_typeof(metadata) = 'object'");
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.home_transitions');
        $this->safeDropIfExists('prod.home_escalations');
        $this->safeDropIfExists('prod.home_visits');
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
