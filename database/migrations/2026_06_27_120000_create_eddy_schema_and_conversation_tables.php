<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Eddy — Phase 0: the dedicated `eddy` schema + conversation memory tables.
 *
 * Eddy persistence is isolated in its own Postgres schema (parallel to `prod`
 * and `ops`) so a PHI audit is a trivial `pg_dump --schema=eddy`. User columns
 * are plain `unsignedBigInteger` (no hard cross-schema FK to prod.users), matching
 * the `ops.agent_*` control-plane convention and avoiding migrate-order coupling.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS eddy');

        if (! Schema::hasTable('eddy.eddy_conversations')) {
            Schema::create('eddy.eddy_conversations', function (Blueprint $table) {
                $table->id('eddy_conversation_id');
                $table->uuid('eddy_conversation_uuid')->unique();
                $table->unsignedBigInteger('user_id')->index();          // -> prod.users.id (soft FK)
                $table->string('title', 500)->nullable();
                $table->string('surface', 64)->default('general');       // ed|rtdc|periop|transport|evs|staffing|improvement|command_center|general
                $table->string('role_context', 40)->nullable();          // charge_nurse|bed_manager|evs|transport|ops_leader|executive
                $table->string('origin', 24)->default('web');            // web|hummingbird
                $table->jsonb('pinned_context')->default(DB::raw("'{}'::jsonb")); // {unit_id?, or_room?, request_id?}
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'surface']);
            });
        }

        if (! Schema::hasTable('eddy.eddy_messages')) {
            Schema::create('eddy.eddy_messages', function (Blueprint $table) {
                $table->id('eddy_message_id');
                $table->foreignId('eddy_conversation_id')
                    ->constrained('eddy.eddy_conversations', 'eddy_conversation_id')
                    ->cascadeOnDelete();
                $table->string('role', 16);                              // user|assistant|tool|system
                $table->text('content');
                // metadata carries: provider_profile_id, model, token usage, tool_calls[],
                //                   runner_up suggestion, confidence, run_uuid, redaction_count
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamp('created_at')->useCurrent();           // NO updated_at (model UPDATED_AT = null)

                $table->index('eddy_conversation_id');
            });
        }

        if (! Schema::hasTable('eddy.eddy_user_profiles')) {
            Schema::create('eddy.eddy_user_profiles', function (Blueprint $table) {
                $table->id('eddy_user_profile_id');
                $table->unsignedBigInteger('user_id')->unique();         // one profile per user
                $table->jsonb('focus_units')->default(DB::raw("'[]'::jsonb"));            // e.g. ["ICU","ED","OR-3"]
                $table->jsonb('role_context')->default(DB::raw("'{}'::jsonb"));           // {primary_role, departments[]}
                $table->jsonb('interaction_preferences')->default(DB::raw("'{}'::jsonb")); // tone, verbosity, default_surface
                $table->jsonb('frequently_used')->default(DB::raw("'{}'::jsonb"));        // learned tool/surface frequency
                $table->timestamp('learned_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('eddy.eddy_user_profiles');
        Schema::dropIfExists('eddy.eddy_messages');
        Schema::dropIfExists('eddy.eddy_conversations');
    }
};
