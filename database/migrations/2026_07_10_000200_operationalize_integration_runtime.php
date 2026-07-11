<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration.sources', function (Blueprint $table): void {
            if (! Schema::hasColumn('integration.sources', 'protocol_health_status')) {
                $table->string('protocol_health_status', 40)->default('unobserved');
            }
            if (! Schema::hasColumn('integration.sources', 'protocol_health_checked_at')) {
                $table->timestamp('protocol_health_checked_at')->nullable();
            }
            if (! Schema::hasColumn('integration.sources', 'protocol_health_error')) {
                $table->string('protocol_health_error', 120)->nullable();
            }
        });

        Schema::table('integration.fhir_client_connections', function (Blueprint $table): void {
            if (! Schema::hasColumn('integration.fhir_client_connections', 'health_status')) {
                $table->string('health_status', 40)->default('unobserved');
            }
            if (! Schema::hasColumn('integration.fhir_client_connections', 'health_checked_at')) {
                $table->timestamp('health_checked_at')->nullable();
            }
            if (! Schema::hasColumn('integration.fhir_client_connections', 'last_health_error')) {
                $table->string('last_health_error', 120)->nullable();
            }
            if (! Schema::hasColumn('integration.fhir_client_connections', 'smart_configuration')) {
                $table->jsonb('smart_configuration')->default('{}');
            }
        });

        Schema::table('integration.event_replay_jobs', function (Blueprint $table): void {
            if (! Schema::hasColumn('integration.event_replay_jobs', 'source_id')) {
                $table->unsignedBigInteger('source_id')->nullable();
                $table->foreign('source_id', 'integration_replay_source_fk')
                    ->references('source_id')->on('integration.sources')->nullOnDelete();
            }
            if (! Schema::hasColumn('integration.event_replay_jobs', 'requested_by_user_id')) {
                $table->unsignedBigInteger('requested_by_user_id')->nullable();
            }
            if (! Schema::hasColumn('integration.event_replay_jobs', 'idempotency_key')) {
                $table->string('idempotency_key', 190)->nullable();
                $table->unique('idempotency_key', 'integration_replay_idempotency_unique');
            }
            if (! Schema::hasColumn('integration.event_replay_jobs', 'request_hash')) {
                $table->string('request_hash', 64)->nullable();
            }
            if (! Schema::hasColumn('integration.event_replay_jobs', 'dry_run')) {
                $table->boolean('dry_run')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('integration.event_replay_jobs', function (Blueprint $table): void {
            if (Schema::hasColumn('integration.event_replay_jobs', 'source_id')) {
                $table->dropForeign('integration_replay_source_fk');
            }
            if (Schema::hasColumn('integration.event_replay_jobs', 'idempotency_key')) {
                $table->dropUnique('integration_replay_idempotency_unique');
            }
            $columns = array_values(array_filter([
                'source_id', 'requested_by_user_id', 'idempotency_key', 'request_hash', 'dry_run',
            ], fn (string $column): bool => Schema::hasColumn('integration.event_replay_jobs', $column)));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('integration.fhir_client_connections', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                'health_status', 'health_checked_at', 'last_health_error', 'smart_configuration',
            ], fn (string $column): bool => Schema::hasColumn('integration.fhir_client_connections', $column)));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('integration.sources', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                'protocol_health_status', 'protocol_health_checked_at', 'protocol_health_error',
            ], fn (string $column): bool => Schema::hasColumn('integration.sources', $column)));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
