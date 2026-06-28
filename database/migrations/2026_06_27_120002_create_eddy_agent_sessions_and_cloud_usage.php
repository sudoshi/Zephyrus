<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Eddy — Phase 0: agent-session ledger + per-call cloud-cost audit.
 *
 * A *session* is the long-lived conversational/agentic context that issues a
 * scoped token, accrues cost, and maps to a Reverb channel (port of Parthenon's
 * `agent_sessions`). One session => many `ops.agent_runs`. Action/tool audit is
 * NOT duplicated here — it reuses the existing `ops.agent_*` control plane.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS eddy');

        if (! Schema::hasTable('eddy.eddy_agent_sessions')) {
            Schema::create('eddy.eddy_agent_sessions', function (Blueprint $table) {
                $table->id('eddy_agent_session_id');
                $table->uuid('eddy_agent_session_uuid')->unique();
                $table->string('profile', 64)->default('eddy');          // eddy_ops|eddy_rtdc|...
                $table->string('subject_type', 64)->default('global');   // unit|or_room|transport_request|recommendation|global
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('provider_session_id')->nullable();       // anthropic/ollama upstream session id (resume key)
                $table->string('status', 32)->default('active');         // active|closed|error
                $table->decimal('cost_usd', 10, 4)->default(0);
                $table->unsignedBigInteger('tokens_in')->default(0);
                $table->unsignedBigInteger('tokens_out')->default(0);
                $table->unsignedBigInteger('token_id')->nullable();      // personal_access_tokens.id for revocation
                $table->string('channel')->nullable();                   // eddy.session.{uuid}
                $table->jsonb('context_json')->default(DB::raw("'{}'::jsonb"));
                $table->timestamp('last_active_at')->nullable();
                $table->timestamps();

                $table->index(['profile', 'subject_type', 'subject_id']);
            });
        }

        if (! Schema::hasTable('eddy.eddy_cloud_usage')) {
            Schema::create('eddy.eddy_cloud_usage', function (Blueprint $table) {
                $table->id('eddy_cloud_usage_id');
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('department', 100)->nullable();
                $table->integer('tokens_in');
                $table->integer('tokens_out');
                $table->decimal('cost_usd', 10, 6);
                $table->string('model', 200);
                $table->char('request_hash', 64)->nullable();            // dedup
                $table->integer('sanitizer_redaction_count')->default(0); // PHI-redaction accounting (compliance signal)
                $table->string('route_reason', 100)->nullable();
                // provider-neutral block (ported as base):
                $table->string('provider', 50)->default('anthropic');
                $table->string('transport', 80)->nullable();
                $table->string('provider_profile_id', 100)->nullable();
                $table->string('entitlement_type', 80)->default('org_api_key');
                $table->string('request_surface', 80)->default('eddy_chat');
                $table->string('status', 40)->default('success');
                $table->string('error_class', 80)->nullable();
                $table->string('fallback_reason', 100)->nullable();
                $table->double('response_latency_ms')->nullable();
                $table->jsonb('usage_metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamp('created_at')->useCurrent();

                $table->index(['provider', 'created_at']);
                $table->index(['status', 'created_at']);
                $table->index(['provider_profile_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('eddy.eddy_cloud_usage');
        Schema::dropIfExists('eddy.eddy_agent_sessions');
    }
};
