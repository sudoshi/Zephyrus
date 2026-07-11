<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS integration');

        if (Schema::hasTable('integration.configuration_audits')) {
            return;
        }

        Schema::create('integration.configuration_audits', function (Blueprint $table) {
            $table->id('configuration_audit_id');
            $table->uuid('audit_uuid')->unique();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('action', 40);
            $table->string('entity_type', 80);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('entity_key', 190)->nullable();
            $table->jsonb('before_payload')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('after_payload')->default(DB::raw("'{}'::jsonb"));
            $table->uuid('correlation_id');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id', 'created_at'], 'integration_config_audit_entity_idx');
            $table->index(['actor_user_id', 'created_at'], 'integration_config_audit_actor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration.configuration_audits');
    }
};
