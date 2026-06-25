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
        DB::statement('CREATE SCHEMA IF NOT EXISTS integration');
        DB::statement('CREATE SCHEMA IF NOT EXISTS raw');
        DB::statement('CREATE SCHEMA IF NOT EXISTS fhir');

        if (! Schema::hasTable('integration.sources')) {
            Schema::create('integration.sources', function (Blueprint $table) {
                $table->id('source_id');
                $table->uuid('source_uuid')->unique();
                $table->string('source_key', 160)->unique();
                $table->string('tenant_key', 120)->default('default');
                $table->string('facility_key', 120)->nullable();
                $table->string('source_name');
                $table->string('vendor', 120)->nullable();
                $table->string('system_class', 120);
                $table->string('environment', 40)->default('sandbox');
                $table->text('base_url')->nullable();
                $table->string('interface_type', 80);
                $table->string('active_status', 40)->default('inactive');
                $table->string('fhir_version', 40)->nullable();
                $table->string('us_core_version', 40)->nullable();
                $table->boolean('smart_supported')->default(false);
                $table->boolean('bulk_supported')->default(false);
                $table->boolean('subscriptions_supported')->default(false);
                $table->string('contract_status', 40)->default('unknown');
                $table->string('baa_status', 40)->default('unknown');
                $table->boolean('phi_allowed')->default(false);
                $table->string('go_live_status', 40)->default('not_started');
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index(['tenant_key', 'facility_key']);
                $table->index(['system_class', 'interface_type']);
                $table->index(['active_status', 'go_live_status']);
            });
        }

        if (! Schema::hasTable('integration.source_capabilities')) {
            Schema::create('integration.source_capabilities', function (Blueprint $table) {
                $table->id('source_capability_id');
                $table->foreignId('source_id')
                    ->constrained('integration.sources', 'source_id')
                    ->cascadeOnDelete();
                $table->string('resource_type', 120)->nullable();
                $table->string('capability_type', 80);
                $table->string('operation', 120)->nullable();
                $table->string('search_param', 120)->nullable();
                $table->boolean('supported')->default(true);
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index(['source_id', 'resource_type', 'capability_type'], 'source_capabilities_lookup_idx');
            });
        }

        if (! Schema::hasTable('integration.source_endpoints')) {
            Schema::create('integration.source_endpoints', function (Blueprint $table) {
                $table->id('source_endpoint_id');
                $table->foreignId('source_id')
                    ->constrained('integration.sources', 'source_id')
                    ->cascadeOnDelete();
                $table->string('endpoint_type', 80);
                $table->text('url')->nullable();
                $table->string('auth_type', 80)->nullable();
                $table->string('tls_mode', 80)->nullable();
                $table->boolean('is_active')->default(true);
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index(['source_id', 'endpoint_type', 'is_active'], 'source_endpoints_lookup_idx');
            });
        }

        if (! Schema::hasTable('integration.source_credentials')) {
            Schema::create('integration.source_credentials', function (Blueprint $table) {
                $table->id('source_credential_id');
                $table->foreignId('source_id')
                    ->constrained('integration.sources', 'source_id')
                    ->cascadeOnDelete();
                $table->string('credential_key', 120);
                $table->string('credential_type', 80);
                $table->string('secret_ref')->nullable();
                $table->string('certificate_ref')->nullable();
                $table->text('jwks_uri')->nullable();
                $table->timestamp('rotates_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->unique(['source_id', 'credential_key'], 'source_credentials_key_unique');
                $table->index(['credential_type', 'is_active']);
            });
        }

        if (! Schema::hasTable('raw.ingest_runs')) {
            Schema::create('raw.ingest_runs', function (Blueprint $table) {
                $table->id('ingest_run_id');
                $table->uuid('run_uuid')->unique();
                $table->foreignId('source_id')
                    ->constrained('integration.sources', 'source_id')
                    ->cascadeOnDelete();
                $table->string('connector_key', 160);
                $table->string('run_type', 80);
                $table->string('status', 40)->default('running');
                $table->timestamp('started_at')->useCurrent();
                $table->timestamp('completed_at')->nullable();
                $table->unsignedInteger('messages_received')->default(0);
                $table->unsignedInteger('messages_succeeded')->default(0);
                $table->unsignedInteger('messages_failed')->default(0);
                $table->unsignedInteger('messages_skipped')->default(0);
                $table->text('cursor_before')->nullable();
                $table->text('cursor_after')->nullable();
                $table->text('error_summary')->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index(['source_id', 'run_type', 'started_at']);
                $table->index(['connector_key', 'status']);
            });
        }

        if (! Schema::hasTable('raw.inbound_messages')) {
            Schema::create('raw.inbound_messages', function (Blueprint $table) {
                $table->id('inbound_message_id');
                $table->uuid('message_uuid')->unique();
                $table->foreignId('source_id')
                    ->constrained('integration.sources', 'source_id')
                    ->cascadeOnDelete();
                $table->foreignId('ingest_run_id')
                    ->nullable()
                    ->constrained('raw.ingest_runs', 'ingest_run_id')
                    ->nullOnDelete();
                $table->string('message_type', 120);
                $table->string('external_id', 190)->nullable();
                $table->string('idempotency_key', 256);
                $table->string('payload_hash', 128);
                $table->text('storage_pointer')->nullable();
                $table->jsonb('payload')->nullable();
                $table->jsonb('normalized_payload')->nullable();
                $table->timestamp('received_at')->useCurrent();
                $table->string('parse_status', 40)->default('received');
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->unique(['source_id', 'idempotency_key'], 'raw_inbound_source_idempotency_unique');
                $table->index(['source_id', 'message_type', 'received_at'], 'raw_inbound_source_type_received_idx');
                $table->index(['parse_status', 'received_at']);
            });
        }

        if (! Schema::hasTable('integration.canonical_events')) {
            Schema::create('integration.canonical_events', function (Blueprint $table) {
                $table->id('canonical_event_id');
                $table->uuid('event_id')->unique();
                $table->foreignId('source_id')
                    ->nullable()
                    ->constrained('integration.sources', 'source_id')
                    ->nullOnDelete();
                $table->foreignId('ingest_run_id')
                    ->nullable()
                    ->constrained('raw.ingest_runs', 'ingest_run_id')
                    ->nullOnDelete();
                $table->foreignId('inbound_message_id')
                    ->nullable()
                    ->constrained('raw.inbound_messages', 'inbound_message_id')
                    ->nullOnDelete();
                $table->string('event_type', 120);
                $table->string('entity_type', 80)->nullable();
                $table->string('entity_ref', 190)->nullable();
                $table->timestamp('occurred_at');
                $table->timestamp('received_at')->useCurrent();
                $table->jsonb('payload')->default(DB::raw("'{}'::jsonb"));
                $table->string('payload_hash', 128);
                $table->string('correlation_id', 190)->nullable();
                $table->string('causation_id', 190)->nullable();
                $table->string('idempotency_key', 256)->unique();
                $table->string('sequence_key', 190)->nullable();
                $table->string('projection_status', 40)->default('pending');
                $table->timestamp('projected_at')->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index(['source_id', 'event_type', 'occurred_at'], 'canonical_events_source_type_time_idx');
                $table->index(['projection_status', 'occurred_at']);
                $table->index(['entity_type', 'entity_ref']);
            });
        }

        if (! Schema::hasTable('raw.dead_letters')) {
            Schema::create('raw.dead_letters', function (Blueprint $table) {
                $table->id('dead_letter_id');
                $table->uuid('dead_letter_uuid')->unique();
                $table->foreignId('source_id')
                    ->nullable()
                    ->constrained('integration.sources', 'source_id')
                    ->nullOnDelete();
                $table->foreignId('ingest_run_id')
                    ->nullable()
                    ->constrained('raw.ingest_runs', 'ingest_run_id')
                    ->nullOnDelete();
                $table->foreignId('inbound_message_id')
                    ->nullable()
                    ->constrained('raw.inbound_messages', 'inbound_message_id')
                    ->nullOnDelete();
                $table->string('canonical_event_id', 120)->nullable();
                $table->string('failure_stage', 80);
                $table->string('reason_code', 120);
                $table->text('message');
                $table->string('exception_class')->nullable();
                $table->jsonb('context')->default(DB::raw("'{}'::jsonb"));
                $table->string('status', 40)->default('open');
                $table->timestamp('resolved_at')->nullable();
                $table->timestamp('replayed_at')->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index(['status', 'created_at']);
                $table->index(['source_id', 'failure_stage']);
            });
        }

        if (! Schema::hasTable('integration.connector_watermarks')) {
            Schema::create('integration.connector_watermarks', function (Blueprint $table) {
                $table->id('connector_watermark_id');
                $table->foreignId('source_id')
                    ->constrained('integration.sources', 'source_id')
                    ->cascadeOnDelete();
                $table->string('connector_key', 160);
                $table->string('scope_type', 80);
                $table->string('scope_key', 190)->nullable();
                $table->string('watermark_kind', 80);
                $table->text('watermark_value')->nullable();
                $table->timestamp('last_success_at')->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->unique(
                    ['source_id', 'connector_key', 'scope_type', 'scope_key', 'watermark_kind'],
                    'connector_watermarks_scope_unique'
                );
            });
        }

        if (! Schema::hasTable('fhir.resource_versions')) {
            Schema::create('fhir.resource_versions', function (Blueprint $table) {
                $table->id('resource_version_id');
                $table->foreignId('source_id')
                    ->constrained('integration.sources', 'source_id')
                    ->cascadeOnDelete();
                $table->string('resource_type', 80);
                $table->string('fhir_id', 190);
                $table->string('version_id', 120)->nullable();
                $table->timestamp('last_updated')->nullable();
                $table->string('resource_hash', 128);
                $table->jsonb('resource_data');
                $table->foreignId('ingest_run_id')
                    ->nullable()
                    ->constrained('raw.ingest_runs', 'ingest_run_id')
                    ->nullOnDelete();
                $table->timestamp('deleted_at')->nullable();
                $table->timestamps();

                $table->unique(['source_id', 'resource_type', 'fhir_id', 'version_id'], 'fhir_resource_versions_unique');
                $table->index(['resource_type', 'last_updated']);
            });
        }

        if (! Schema::hasTable('fhir.resource_links')) {
            Schema::create('fhir.resource_links', function (Blueprint $table) {
                $table->id('resource_link_id');
                $table->foreignId('source_id')
                    ->constrained('integration.sources', 'source_id')
                    ->cascadeOnDelete();
                $table->string('resource_type', 80);
                $table->string('fhir_id', 190);
                $table->string('internal_schema', 80);
                $table->string('internal_table', 120);
                $table->string('internal_pk', 120);
                $table->string('canonical_key', 190)->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->unique(['source_id', 'resource_type', 'fhir_id', 'internal_schema', 'internal_table', 'internal_pk'], 'fhir_resource_links_unique');
            });
        }

        if (! Schema::hasTable('integration.identity_links')) {
            Schema::create('integration.identity_links', function (Blueprint $table) {
                $table->id('identity_link_id');
                $table->foreignId('source_id')
                    ->nullable()
                    ->constrained('integration.sources', 'source_id')
                    ->nullOnDelete();
                $table->string('identity_type', 80);
                $table->string('external_id', 190);
                $table->string('canonical_ref', 190);
                $table->decimal('confidence', 5, 4)->nullable();
                $table->boolean('is_active')->default(true);
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->unique(['identity_type', 'external_id', 'canonical_ref'], 'identity_links_unique');
                $table->index(['canonical_ref', 'is_active']);
            });
        }

        if (! Schema::hasTable('integration.patient_merge_events')) {
            Schema::create('integration.patient_merge_events', function (Blueprint $table) {
                $table->id('patient_merge_event_id');
                $table->foreignId('source_id')
                    ->nullable()
                    ->constrained('integration.sources', 'source_id')
                    ->nullOnDelete();
                $table->string('merge_event_type', 40);
                $table->string('survivor_ref', 190);
                $table->string('merged_ref', 190);
                $table->timestamp('occurred_at');
                $table->jsonb('payload')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index(['survivor_ref', 'merged_ref']);
            });
        }

        if (! Schema::hasTable('integration.terminology_maps')) {
            Schema::create('integration.terminology_maps', function (Blueprint $table) {
                $table->id('terminology_map_id');
                $table->foreignId('source_id')
                    ->nullable()
                    ->constrained('integration.sources', 'source_id')
                    ->nullOnDelete();
                $table->string('map_type', 80);
                $table->string('source_code_system', 160)->nullable();
                $table->string('source_code', 190);
                $table->string('canonical_code_system', 160)->nullable();
                $table->string('canonical_code', 190);
                $table->string('review_status', 40)->default('approved');
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index(['map_type', 'source_code']);
            });
        }

        if (! Schema::hasTable('integration.provenance_records')) {
            Schema::create('integration.provenance_records', function (Blueprint $table) {
                $table->id('provenance_record_id');
                $table->foreignId('source_id')
                    ->nullable()
                    ->constrained('integration.sources', 'source_id')
                    ->nullOnDelete();
                $table->foreignId('inbound_message_id')
                    ->nullable()
                    ->constrained('raw.inbound_messages', 'inbound_message_id')
                    ->nullOnDelete();
                $table->foreignId('canonical_event_id')
                    ->nullable()
                    ->constrained('integration.canonical_events', 'canonical_event_id')
                    ->nullOnDelete();
                $table->string('target_schema', 80)->nullable();
                $table->string('target_table', 120)->nullable();
                $table->string('target_pk', 120)->nullable();
                $table->jsonb('lineage')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index(['target_schema', 'target_table', 'target_pk'], 'provenance_target_idx');
            });
        }

        if (! Schema::hasTable('integration.event_projection_offsets')) {
            Schema::create('integration.event_projection_offsets', function (Blueprint $table) {
                $table->id('event_projection_offset_id');
                $table->string('projector_key', 160);
                $table->string('scope_type', 80)->default('global');
                $table->string('scope_key', 190)->nullable();
                $table->unsignedBigInteger('last_canonical_event_id')->nullable();
                $table->timestamp('last_projected_at')->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->unique(['projector_key', 'scope_type', 'scope_key'], 'projection_offsets_unique');
            });
        }

        if (! Schema::hasTable('integration.event_projection_errors')) {
            Schema::create('integration.event_projection_errors', function (Blueprint $table) {
                $table->id('event_projection_error_id');
                $table->foreignId('canonical_event_id')
                    ->nullable()
                    ->constrained('integration.canonical_events', 'canonical_event_id')
                    ->nullOnDelete();
                $table->string('projector_key', 160);
                $table->string('error_code', 120);
                $table->text('message');
                $table->string('exception_class')->nullable();
                $table->jsonb('context')->default(DB::raw("'{}'::jsonb"));
                $table->string('status', 40)->default('open');
                $table->timestamps();

                $table->index(['projector_key', 'status']);
            });
        }

        if (! Schema::hasTable('integration.event_replay_jobs')) {
            Schema::create('integration.event_replay_jobs', function (Blueprint $table) {
                $table->id('event_replay_job_id');
                $table->uuid('replay_uuid')->unique();
                $table->string('replay_type', 80);
                $table->string('status', 40)->default('queued');
                $table->jsonb('scope')->default(DB::raw("'{}'::jsonb"));
                $table->unsignedInteger('events_replayed')->default(0);
                $table->unsignedInteger('events_failed')->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->text('error_summary')->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index(['status', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        $this->safeDropIfExists('integration.event_replay_jobs');
        $this->safeDropIfExists('integration.event_projection_errors');
        $this->safeDropIfExists('integration.event_projection_offsets');
        $this->safeDropIfExists('integration.provenance_records');
        $this->safeDropIfExists('integration.terminology_maps');
        $this->safeDropIfExists('integration.patient_merge_events');
        $this->safeDropIfExists('integration.identity_links');
        $this->safeDropIfExists('fhir.resource_links');
        $this->safeDropIfExists('fhir.resource_versions');
        $this->safeDropIfExists('integration.connector_watermarks');
        $this->safeDropIfExists('raw.dead_letters');
        $this->safeDropIfExists('integration.canonical_events');
        $this->safeDropIfExists('raw.inbound_messages');
        $this->safeDropIfExists('raw.ingest_runs');
        $this->safeDropIfExists('integration.source_credentials');
        $this->safeDropIfExists('integration.source_endpoints');
        $this->safeDropIfExists('integration.source_capabilities');
        $this->safeDropIfExists('integration.sources');
    }
};
