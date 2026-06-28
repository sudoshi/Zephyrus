<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Eddy — Phase 0: provider profiles x surface policies (port of Parthenon's
 * abby_provider_profiles / abby_surface_policies). The routing engine is fully
 * domain-agnostic; only the SURFACES list is hospital-ops-specific.
 *
 * Secrets are NEVER stored here — provider API keys live ONLY in the Eddy
 * FastAPI service's own env. A profile row says which provider/model/mode a
 * surface uses; Eddy resolves the secret on its side.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS eddy');

        if (! Schema::hasTable('eddy.eddy_provider_profiles')) {
            Schema::create('eddy.eddy_provider_profiles', function (Blueprint $table) {
                $table->id();
                $table->string('profile_id', 100)->unique();             // local-medgemma | claude-frontier | byo-openai
                $table->string('display_name', 200);
                $table->string('provider_type', 50);                     // ollama | anthropic | openai | openai_compatible
                $table->string('transport', 80);                         // ollama_chat | anthropic_messages | anthropic_compatible_proxy | ...
                $table->string('entitlement_type', 50)->default('local'); // local | org_api_key | user_api_key | acumenus_managed_api
                $table->string('model', 200)->default('');
                $table->string('base_url', 500)->nullable();
                $table->string('provider_setting_type', 50)->nullable();  // which Eddy-side secret holds the key
                $table->boolean('is_enabled')->default(true);
                $table->jsonb('capabilities')->default(DB::raw("'[]'::jsonb"));
                $table->jsonb('safety')->default(DB::raw("'{}'::jsonb"));  // {patient_level_context_allowed: bool}
                $table->jsonb('limits')->default(DB::raw("'{}'::jsonb"));  // {timeout, max_output_tokens, monthly_budget_usd}
                $table->jsonb('fallback_profile_ids')->default(DB::raw("'[]'::jsonb"));
                $table->jsonb('notes')->default(DB::raw("'{}'::jsonb"));
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->index(['provider_type', 'transport']);
                $table->index('is_enabled');
            });
        }

        if (! Schema::hasTable('eddy.eddy_surface_policies')) {
            Schema::create('eddy.eddy_surface_policies', function (Blueprint $table) {
                $table->id();
                $table->string('surface', 80)->unique();                 // chat|rtdc|ed|periop|transport|evs|staffing|improvement|command_center|eddy_agent
                $table->string('provider_mode', 40)->default('local_only');
                $table->string('default_profile_id', 100)->nullable();
                $table->jsonb('fallback_profile_ids')->default(DB::raw("'[]'::jsonb"));
                $table->boolean('never_send_phi_to_cloud')->default(true);
                $table->boolean('allow_cloud')->default(false);
                $table->jsonb('required_capabilities')->default(DB::raw("'[]'::jsonb"));
                $table->jsonb('settings')->default(DB::raw("'{}'::jsonb"));
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->index(['surface', 'provider_mode']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('eddy.eddy_surface_policies');
        Schema::dropIfExists('eddy.eddy_provider_profiles');
    }
};
