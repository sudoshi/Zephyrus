<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS ops');

        if (! Schema::hasTable('ops.operational_events')) {
            Schema::create('ops.operational_events', function (Blueprint $table) {
                $table->id('operational_event_id');
                $table->uuid('event_uuid')->unique();
                $table->string('event_type', 120);
                $table->timestamp('occurred_at')->useCurrent();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('actor_role', 80)->nullable();
                $table->string('source_surface', 80)->default('hummingbird');
                $table->string('domain', 80)->default('ops');
                $table->jsonb('scope')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('status')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('recommendation')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('relay')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('phi_policy')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('payload')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index(['domain', 'event_type', 'occurred_at'], 'ops_events_domain_type_time_idx');
                $table->index(['actor_role', 'occurred_at'], 'ops_events_actor_role_time_idx');
            });
        }

        if (! Schema::hasTable('ops.operational_event_targets')) {
            Schema::create('ops.operational_event_targets', function (Blueprint $table) {
                $table->id('operational_event_target_id');
                $table->foreignId('operational_event_id')
                    ->constrained('ops.operational_events', 'operational_event_id')
                    ->cascadeOnDelete();
                $table->string('target_role', 80)->nullable();
                $table->unsignedBigInteger('target_user_id')->nullable();
                $table->string('target_scope_type', 80)->nullable();
                $table->string('target_scope_key', 160)->nullable();
                $table->string('delivery', 40)->default('activity');
                $table->string('push_tier', 40)->default('activity');
                $table->timestamp('created_at')->useCurrent();

                $table->index(['target_role', 'delivery'], 'ops_event_targets_role_delivery_idx');
                $table->index('target_user_id', 'ops_event_targets_user_idx');
            });
        }

        if (! Schema::hasTable('ops.operational_event_entities')) {
            Schema::create('ops.operational_event_entities', function (Blueprint $table) {
                $table->id('operational_event_entity_id');
                $table->foreignId('operational_event_id')
                    ->constrained('ops.operational_events', 'operational_event_id')
                    ->cascadeOnDelete();
                $table->string('entity_type', 80);
                $table->string('entity_ref', 160);
                $table->string('patient_ref', 160)->nullable();
                $table->string('encounter_ref', 160)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['entity_type', 'entity_ref'], 'ops_event_entities_lookup_idx');
                $table->index('patient_ref', 'ops_event_entities_patient_idx');
                $table->index('encounter_ref', 'ops_event_entities_encounter_idx');
            });
        }

        if (! Schema::hasTable('ops.operational_event_acknowledgements')) {
            Schema::create('ops.operational_event_acknowledgements', function (Blueprint $table) {
                $table->id('operational_event_acknowledgement_id');
                $table->foreignId('operational_event_id')
                    ->constrained('ops.operational_events', 'operational_event_id')
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('user_id');
                $table->string('ack_role', 80)->nullable();
                $table->timestamp('acknowledged_at')->useCurrent();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));

                $table->unique(['operational_event_id', 'user_id'], 'ops_event_ack_unique');
                $table->index(['user_id', 'acknowledged_at'], 'ops_event_ack_user_time_idx');
            });
        }

        if (! Schema::hasTable('ops.patient_operational_context_cache')) {
            Schema::create('ops.patient_operational_context_cache', function (Blueprint $table) {
                $table->id('patient_operational_context_cache_id');
                $table->string('patient_context_ref', 160)->unique();
                $table->string('patient_ref', 160);
                $table->string('encounter_ref', 160)->nullable();
                $table->timestamp('generated_at')->useCurrent();
                $table->timestamp('expires_at')->nullable();
                $table->jsonb('context_payload')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('phi_policy')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index('patient_ref', 'ops_patient_context_patient_idx');
                $table->index('encounter_ref', 'ops_patient_context_encounter_idx');
            });
        }

        if (! Schema::hasTable('ops.eddy_context_packets')) {
            Schema::create('ops.eddy_context_packets', function (Blueprint $table) {
                $table->id('eddy_context_packet_id');
                $table->uuid('packet_uuid')->unique();
                $table->string('scope_ref', 180);
                $table->string('scope_type', 80);
                $table->foreignId('source_event_id')
                    ->nullable()
                    ->constrained('ops.operational_events', 'operational_event_id')
                    ->nullOnDelete();
                $table->timestamp('generated_at')->useCurrent();
                $table->timestamp('expires_at')->nullable();
                $table->jsonb('packet_payload')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('phi_policy')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index(['scope_type', 'scope_ref'], 'ops_eddy_context_scope_idx');
                $table->index('generated_at', 'ops_eddy_context_generated_idx');
            });
        }
    }

    public function down(): void
    {
        $this->safeDropIfExists('ops.eddy_context_packets');
        $this->safeDropIfExists('ops.patient_operational_context_cache');
        $this->safeDropIfExists('ops.operational_event_acknowledgements');
        $this->safeDropIfExists('ops.operational_event_entities');
        $this->safeDropIfExists('ops.operational_event_targets');
        $this->safeDropIfExists('ops.operational_events');
    }
};
