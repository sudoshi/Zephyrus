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
        DB::statement('CREATE SCHEMA IF NOT EXISTS ops');

        if (! Schema::hasTable('integration.interface_engines')) {
            Schema::create('integration.interface_engines', function (Blueprint $table) {
                $table->id('interface_engine_id');
                $table->uuid('interface_engine_uuid')->unique();
                $table->string('engine_key', 120)->unique();
                $table->string('label', 160);
                $table->string('engine_type', 80);
                $table->string('environment', 40)->default('sandbox');
                $table->string('status', 40)->default('planned');
                $table->jsonb('boundary_payload')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('integration.fhir_client_connections')) {
            Schema::create('integration.fhir_client_connections', function (Blueprint $table) {
                $table->id('fhir_client_connection_id');
                $table->uuid('connection_uuid')->unique();
                $table->foreignId('source_id')
                    ->constrained('integration.sources', 'source_id')
                    ->cascadeOnDelete();
                $table->string('connection_key', 160);
                $table->string('status', 40)->default('discovered');
                $table->text('base_url')->nullable();
                $table->string('fhir_version', 40)->nullable();
                $table->timestamp('capability_checked_at')->nullable();
                $table->jsonb('capability_statement')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('polling_payload')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->unique(['source_id', 'connection_key'], 'fhir_client_connection_source_key_unique');
            });
        }

        if (! Schema::hasTable('integration.smart_backend_credentials')) {
            Schema::create('integration.smart_backend_credentials', function (Blueprint $table) {
                $table->id('smart_backend_credential_id');
                $table->uuid('credential_uuid')->unique();
                $table->foreignId('source_id')
                    ->constrained('integration.sources', 'source_id')
                    ->cascadeOnDelete();
                $table->string('credential_key', 160);
                $table->string('status', 40)->default('planned');
                $table->string('client_id')->nullable();
                $table->string('jwks_secret_ref')->nullable();
                $table->string('token_url')->nullable();
                $table->timestamp('issued_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('rotates_at')->nullable();
                $table->jsonb('scope_payload')->default(DB::raw("'[]'::jsonb"));
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->unique(['source_id', 'credential_key'], 'smart_backend_source_key_unique');
            });
        }

        if (! Schema::hasTable('integration.connector_playbooks')) {
            Schema::create('integration.connector_playbooks', function (Blueprint $table) {
                $table->id('connector_playbook_id');
                $table->uuid('playbook_uuid')->unique();
                $table->string('vendor_key', 120)->unique();
                $table->string('label', 160);
                $table->string('system_class', 120);
                $table->string('status', 40)->default('ready');
                $table->jsonb('capability_payload')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('implementation_steps')->default(DB::raw("'[]'::jsonb"));
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('integration.coexistence_adapters')) {
            Schema::create('integration.coexistence_adapters', function (Blueprint $table) {
                $table->id('coexistence_adapter_id');
                $table->uuid('adapter_uuid')->unique();
                $table->string('adapter_key', 120)->unique();
                $table->string('label', 160);
                $table->string('vendor_key', 120);
                $table->string('status', 40)->default('ready');
                $table->jsonb('coexistence_payload')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ops.writeback_drafts')) {
            Schema::create('ops.writeback_drafts', function (Blueprint $table) {
                $table->id('writeback_draft_id');
                $table->uuid('writeback_draft_uuid')->unique();
                $table->foreignId('source_id')
                    ->nullable()
                    ->constrained('integration.sources', 'source_id')
                    ->nullOnDelete();
                $table->foreignId('action_id')
                    ->nullable()
                    ->constrained('ops.actions', 'action_id')
                    ->nullOnDelete();
                $table->foreignId('approval_id')
                    ->nullable()
                    ->constrained('ops.approvals', 'approval_id')
                    ->nullOnDelete();
                $table->string('target_system', 120);
                $table->string('resource_type', 80);
                $table->string('draft_type', 80);
                $table->string('status', 40)->default('pending_approval');
                $table->jsonb('resource_payload')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('routing_payload')->default(DB::raw("'{}'::jsonb"));
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                $table->index(['target_system', 'resource_type', 'status']);
                $table->index(['action_id', 'approval_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ops.writeback_drafts');
        Schema::dropIfExists('integration.coexistence_adapters');
        Schema::dropIfExists('integration.connector_playbooks');
        Schema::dropIfExists('integration.smart_backend_credentials');
        Schema::dropIfExists('integration.fhir_client_connections');
        Schema::dropIfExists('integration.interface_engines');
    }
};
