<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS ops');

        if (! Schema::hasTable('ops.agent_definitions')) {
            Schema::create('ops.agent_definitions', function (Blueprint $table) {
                $table->id('agent_definition_id');
                $table->uuid('agent_definition_uuid')->unique();
                $table->string('agent_key', 120)->unique();
                $table->string('label', 160);
                $table->text('description')->nullable();
                $table->string('mode', 80)->default('rules_only');
                $table->string('status', 40)->default('active');
                $table->boolean('read_only')->default(true);
                $table->string('minimum_role', 80)->default('user');
                $table->jsonb('tool_allowlist')->default(DB::raw("'[]'::jsonb"));
                $table->jsonb('safety_policy')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index(['status', 'read_only']);
            });
        }

        if (! Schema::hasTable('ops.agent_runs')) {
            Schema::create('ops.agent_runs', function (Blueprint $table) {
                $table->id('agent_run_id');
                $table->uuid('agent_run_uuid')->unique();
                $table->foreignId('agent_definition_id')
                    ->constrained('ops.agent_definitions', 'agent_definition_id')
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('status', 40)->default('running');
                $table->string('mode', 80)->default('rules_only');
                $table->text('objective')->nullable();
                $table->jsonb('input_payload')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('output_payload')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('summary_payload')->default(DB::raw("'{}'::jsonb"));
                $table->text('blocked_reason')->nullable();
                $table->timestamp('started_at')->useCurrent();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'started_at']);
                $table->index('actor_user_id');
            });
        }

        if (! Schema::hasTable('ops.agent_tool_calls')) {
            Schema::create('ops.agent_tool_calls', function (Blueprint $table) {
                $table->id('agent_tool_call_id');
                $table->foreignId('agent_run_id')
                    ->constrained('ops.agent_runs', 'agent_run_id')
                    ->cascadeOnDelete();
                $table->string('tool_key', 160);
                $table->string('status', 40)->default('started');
                $table->boolean('read_only')->default(true);
                $table->jsonb('request_payload')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('response_payload')->default(DB::raw("'{}'::jsonb"));
                $table->text('error_message')->nullable();
                $table->timestamp('started_at')->useCurrent();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['tool_key', 'status']);
            });
        }

        if (! Schema::hasTable('ops.agent_approvals')) {
            Schema::create('ops.agent_approvals', function (Blueprint $table) {
                $table->id('agent_approval_id');
                $table->foreignId('agent_run_id')
                    ->constrained('ops.agent_runs', 'agent_run_id')
                    ->cascadeOnDelete();
                $table->string('approval_key', 160);
                $table->string('status', 40)->default('pending');
                $table->unsignedBigInteger('requested_by_user_id')->nullable();
                $table->unsignedBigInteger('decided_by_user_id')->nullable();
                $table->text('reason')->nullable();
                $table->timestamp('requested_at')->useCurrent();
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'requested_at']);
            });
        }

        if (! Schema::hasTable('ops.agent_evaluations')) {
            Schema::create('ops.agent_evaluations', function (Blueprint $table) {
                $table->id('agent_evaluation_id');
                $table->foreignId('agent_run_id')
                    ->constrained('ops.agent_runs', 'agent_run_id')
                    ->cascadeOnDelete();
                $table->string('evaluation_key', 160);
                $table->string('status', 40);
                $table->decimal('score', 5, 2)->default(0);
                $table->text('detail')->nullable();
                $table->jsonb('payload')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index(['evaluation_key', 'status']);
            });
        }

        if (! Schema::hasTable('ops.agent_safety_events')) {
            Schema::create('ops.agent_safety_events', function (Blueprint $table) {
                $table->id('agent_safety_event_id');
                $table->foreignId('agent_run_id')
                    ->constrained('ops.agent_runs', 'agent_run_id')
                    ->cascadeOnDelete();
                $table->string('event_type', 120);
                $table->string('severity', 40)->default('warning');
                $table->string('status', 40)->default('open');
                $table->text('detail');
                $table->text('input_excerpt')->nullable();
                $table->jsonb('payload')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index(['event_type', 'severity']);
                $table->index(['status', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ops.agent_safety_events');
        Schema::dropIfExists('ops.agent_evaluations');
        Schema::dropIfExists('ops.agent_approvals');
        Schema::dropIfExists('ops.agent_tool_calls');
        Schema::dropIfExists('ops.agent_runs');
        Schema::dropIfExists('ops.agent_definitions');
    }
};
