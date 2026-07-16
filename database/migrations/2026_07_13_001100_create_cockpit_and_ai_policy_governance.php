<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADM-POLICY — governed Cockpit threshold policy and Zephyrus/Eddy AI provider
 * policy. Both policies gain an append-only, PostgreSQL-trigger-enforced
 * version ledger; the effective policy is never mutated without a version row,
 * and rollback is a NEW version referencing a prior one. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE governance.change_requests
                DROP CONSTRAINT IF EXISTS change_requests_action_type_check;
            ALTER TABLE governance.change_requests
                DROP CONSTRAINT IF EXISTS change_requests_action_type_chk;
            ALTER TABLE governance.change_requests
                ADD CONSTRAINT change_requests_action_type_chk CHECK (action_type IN (
                    'activate_production_source',
                    'schedule_production_source_activation',
                    'apply_source_configuration',
                    'rotate_integration_credential',
                    'execute_destructive_replay',
                    'change_outbound_dispatch_policy',
                    'release_quarantined_payload',
                    'apply_clinical_payload_hold',
                    'release_clinical_payload_hold',
                    'purge_clinical_payload',
                    'purge_quarantined_payload',
                    'recover_clinical_payload_integrity',
                    'purge_user_identity',
                    'apply_cockpit_threshold_policy',
                    'apply_ai_provider_policy'
                ));

            CREATE TABLE IF NOT EXISTS governance.cockpit_threshold_policy_versions (
                cockpit_threshold_policy_version_id bigserial PRIMARY KEY,
                metric_definition_id bigint NOT NULL REFERENCES ops.metric_definitions(metric_definition_id) ON DELETE RESTRICT,
                metric_key varchar(160) NOT NULL,
                version_number integer NOT NULL CHECK (version_number > 0),
                previous_version_id bigint REFERENCES governance.cockpit_threshold_policy_versions(cockpit_threshold_policy_version_id) ON DELETE RESTRICT,
                rolled_back_to_version_id bigint REFERENCES governance.cockpit_threshold_policy_versions(cockpit_threshold_policy_version_id) ON DELETE RESTRICT,
                policy jsonb NOT NULL,
                policy_sha256 char(64) NOT NULL CHECK (policy_sha256 ~ '^[0-9a-f]{64}$'),
                change_kind text NOT NULL CHECK (change_kind IN ('initial', 'backfill', 'direct_update', 'proposal', 'governed_application', 'rollback')),
                change_reason text NOT NULL CHECK (length(change_reason) BETWEEN 10 AND 500),
                effective_at timestamptz NOT NULL DEFAULT now(),
                created_by_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                governed_change_request_uuid uuid REFERENCES governance.change_requests(change_request_uuid) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT cockpit_threshold_policy_versions_number_uniq UNIQUE (metric_definition_id, version_number)
            );

            CREATE INDEX IF NOT EXISTS cockpit_threshold_policy_versions_metric_idx
                ON governance.cockpit_threshold_policy_versions (metric_definition_id, version_number DESC);
            CREATE INDEX IF NOT EXISTS cockpit_threshold_policy_versions_change_idx
                ON governance.cockpit_threshold_policy_versions (governed_change_request_uuid)
                WHERE governed_change_request_uuid IS NOT NULL;

            CREATE TABLE IF NOT EXISTS governance.ai_provider_policy_versions (
                ai_provider_policy_version_id bigserial PRIMARY KEY,
                policy_key varchar(80) NOT NULL CHECK (policy_key ~ '^[a-z][a-z0-9_]{0,79}$'),
                version_number integer NOT NULL CHECK (version_number > 0),
                previous_version_id bigint REFERENCES governance.ai_provider_policy_versions(ai_provider_policy_version_id) ON DELETE RESTRICT,
                rolled_back_to_version_id bigint REFERENCES governance.ai_provider_policy_versions(ai_provider_policy_version_id) ON DELETE RESTRICT,
                policy jsonb NOT NULL,
                policy_sha256 char(64) NOT NULL CHECK (policy_sha256 ~ '^[0-9a-f]{64}$'),
                change_kind text NOT NULL CHECK (change_kind IN ('initial', 'backfill', 'direct_update', 'proposal', 'governed_application', 'rollback')),
                change_reason text NOT NULL CHECK (length(change_reason) BETWEEN 10 AND 500),
                effective_at timestamptz NOT NULL DEFAULT now(),
                created_by_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                governed_change_request_uuid uuid REFERENCES governance.change_requests(change_request_uuid) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT ai_provider_policy_versions_number_uniq UNIQUE (policy_key, version_number)
            );

            CREATE INDEX IF NOT EXISTS ai_provider_policy_versions_key_idx
                ON governance.ai_provider_policy_versions (policy_key, version_number DESC);
            CREATE INDEX IF NOT EXISTS ai_provider_policy_versions_change_idx
                ON governance.ai_provider_policy_versions (governed_change_request_uuid)
                WHERE governed_change_request_uuid IS NOT NULL;

            CREATE OR REPLACE FUNCTION governance.reject_policy_version_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'governance policy version ledgers are append-only';
            END;
            $$;

            DROP TRIGGER IF EXISTS cockpit_threshold_policy_versions_append_only
                ON governance.cockpit_threshold_policy_versions;
            CREATE TRIGGER cockpit_threshold_policy_versions_append_only
                BEFORE UPDATE OR DELETE ON governance.cockpit_threshold_policy_versions
                FOR EACH ROW EXECUTE FUNCTION governance.reject_policy_version_mutation();

            DROP TRIGGER IF EXISTS ai_provider_policy_versions_append_only
                ON governance.ai_provider_policy_versions;
            CREATE TRIGGER ai_provider_policy_versions_append_only
                BEFORE UPDATE OR DELETE ON governance.ai_provider_policy_versions
                FOR EACH ROW EXECUTE FUNCTION governance.reject_policy_version_mutation();
        SQL);

        // Governed AI region residency: additive, nullable, no default drift.
        if (! Schema::hasColumn('eddy.eddy_provider_profiles', 'region')) {
            Schema::table('eddy.eddy_provider_profiles', function (Blueprint $table): void {
                $table->string('region', 80)->nullable();
            });
        }

        $this->backfillCockpitThresholdVersions();
        $this->backfillAiProviderPolicyVersion();
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS governance.cockpit_threshold_policy_versions;
            DROP TABLE IF EXISTS governance.ai_provider_policy_versions;
            DROP FUNCTION IF EXISTS governance.reject_policy_version_mutation();
        SQL);
    }

    /**
     * Capture every existing metric definition as immutable policy version 1 so
     * the effective threshold policy is versioned from the moment this ships.
     */
    private function backfillCockpitThresholdVersions(): void
    {
        $definitions = DB::table('ops.metric_definitions')->orderBy('metric_definition_id')->get();

        foreach ($definitions as $definition) {
            $exists = DB::table('governance.cockpit_threshold_policy_versions')
                ->where('metric_definition_id', $definition->metric_definition_id)
                ->exists();
            if ($exists) {
                continue;
            }

            $policy = $this->canonicalize([
                'metric_key' => (string) $definition->metric_key,
                'owner' => $definition->owner,
                'scope' => $definition->facility_key ?? 'house',
                'unit' => $definition->unit,
                'direction' => (string) $definition->direction,
                'target' => $definition->target_value !== null ? (float) $definition->target_value : null,
                'ok_edge' => $definition->ok_edge !== null ? (float) $definition->ok_edge : null,
                'warn_edge' => $definition->warn_edge !== null ? (float) $definition->warn_edge : null,
                'crit_edge' => $definition->crit_edge !== null ? (float) $definition->crit_edge : null,
                'refresh_secs' => (int) ($definition->refresh_secs ?? 300),
                'alert_template' => $definition->alert_template,
                'is_active' => (bool) ($definition->is_active ?? true),
            ]);
            $encoded = json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            DB::table('governance.cockpit_threshold_policy_versions')->insert([
                'metric_definition_id' => $definition->metric_definition_id,
                'metric_key' => $definition->metric_key,
                'version_number' => 1,
                'policy' => $encoded,
                'policy_sha256' => hash('sha256', $encoded),
                'change_kind' => 'backfill',
                'change_reason' => 'Backfilled from the effective cockpit metric definition.',
                'effective_at' => $definition->updated_at ?? $definition->created_at ?? now(),
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Snapshot the current Eddy provider/surface policy as version 1 when any
     * policy rows already exist. Empty deployments version lazily on first use.
     */
    private function backfillAiProviderPolicyVersion(): void
    {
        if (! Schema::hasTable('eddy.eddy_provider_profiles') || ! Schema::hasTable('eddy.eddy_surface_policies')) {
            return;
        }

        $exists = DB::table('governance.ai_provider_policy_versions')->where('policy_key', 'eddy')->exists();
        if ($exists) {
            return;
        }

        $profiles = DB::table('eddy.eddy_provider_profiles')->orderBy('profile_id')->get();
        $surfaces = DB::table('eddy.eddy_surface_policies')->orderBy('surface')->get();
        if ($profiles->isEmpty() && $surfaces->isEmpty()) {
            return;
        }

        $document = $this->canonicalize([
            'profiles' => $profiles->map(fn (object $profile): array => [
                'profile_id' => (string) $profile->profile_id,
                'display_name' => (string) $profile->display_name,
                'provider_type' => (string) $profile->provider_type,
                'transport' => (string) $profile->transport,
                'entitlement_type' => (string) $profile->entitlement_type,
                'model' => (string) $profile->model,
                'base_url' => $profile->base_url,
                'region' => $profile->region ?? null,
                'is_enabled' => (bool) $profile->is_enabled,
                'capabilities' => $this->decodeList($profile->capabilities),
                'safety' => ['patient_level_context_allowed' => (bool) ($this->decodeMap($profile->safety)['patient_level_context_allowed'] ?? false)],
                'limits' => $this->decodeMap($profile->limits),
                'fallback_profile_ids' => $this->decodeList($profile->fallback_profile_ids),
            ])->values()->all(),
            'surfaces' => $surfaces->map(fn (object $policy): array => [
                'surface' => (string) $policy->surface,
                'provider_mode' => (string) $policy->provider_mode,
                'default_profile_id' => $policy->default_profile_id,
                'fallback_profile_ids' => $this->decodeList($policy->fallback_profile_ids),
                'allow_cloud' => (bool) $policy->allow_cloud,
                'never_send_phi_to_cloud' => (bool) $policy->never_send_phi_to_cloud,
                'required_capabilities' => $this->decodeList($policy->required_capabilities),
            ])->values()->all(),
        ]);
        $encoded = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        DB::table('governance.ai_provider_policy_versions')->insert([
            'policy_key' => 'eddy',
            'version_number' => 1,
            'policy' => $encoded,
            'policy_sha256' => hash('sha256', $encoded),
            'change_kind' => 'backfill',
            'change_reason' => 'Backfilled from the effective Eddy provider and surface policy rows.',
            'effective_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $nested): mixed => $this->canonicalize($nested), $value);
    }

    /** @return list<string> */
    private function decodeList(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /** @return array<string, mixed> */
    private function decodeMap(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : [];
    }
};
