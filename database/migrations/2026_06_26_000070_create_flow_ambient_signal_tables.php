<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS flow_realtime');

        if (! Schema::hasTable('flow_realtime.ambient_signal_adapters')) {
            Schema::create('flow_realtime.ambient_signal_adapters', function (Blueprint $table) {
                $table->id('ambient_signal_adapter_id');
                $table->uuid('adapter_uuid')->unique();
                $table->string('adapter_key', 120)->unique();
                $table->string('label', 160);
                $table->string('source_type', 80);
                $table->boolean('enabled')->default(true);
                $table->decimal('base_confidence', 5, 4)->default(0.7000);
                $table->string('minimum_role', 80)->nullable();
                $table->jsonb('capability_payload')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index(['source_type', 'enabled']);
            });
        }

        if (! Schema::hasTable('flow_realtime.ambient_signal_events')) {
            Schema::create('flow_realtime.ambient_signal_events', function (Blueprint $table) {
                $table->id('ambient_signal_event_id');
                $table->uuid('event_uuid')->unique();
                $table->foreignId('ambient_signal_adapter_id')
                    ->constrained('flow_realtime.ambient_signal_adapters', 'ambient_signal_adapter_id')
                    ->cascadeOnDelete();
                $table->string('external_event_id', 160);
                $table->string('signal_type', 120);
                $table->timestamp('occurred_at');
                $table->string('location_code', 160)->nullable();
                $table->foreignId('facility_space_id')
                    ->nullable()
                    ->constrained('hosp_space.facility_spaces', 'facility_space_id')
                    ->nullOnDelete();
                $table->string('subject_ref_hash', 160)->nullable();
                $table->decimal('confidence_score', 5, 4)->default(0.7000);
                $table->string('confidence_level', 40)->default('medium');
                $table->jsonb('normalized_payload')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('raw_payload')->default(DB::raw("'{}'::jsonb"));
                $table->string('linked_flow_event_id')->nullable();
                $table->timestamps();

                $table->unique(['ambient_signal_adapter_id', 'external_event_id'], 'ambient_signal_adapter_external_unique');
                $table->index(['signal_type', 'occurred_at']);
                $table->index(['confidence_level', 'confidence_score']);
                $table->index('facility_space_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_realtime.ambient_signal_events');
        Schema::dropIfExists('flow_realtime.ambient_signal_adapters');
    }
};
