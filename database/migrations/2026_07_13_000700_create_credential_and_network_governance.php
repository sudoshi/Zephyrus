<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration.source_credentials', function (Blueprint $table): void {
            $table->unsignedBigInteger('current_credential_version_id')->nullable();
            $table->string('credential_state', 32)->default('planned');
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('rotation_overlap_ends_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
        });
        Schema::table('integration.smart_backend_credentials', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_credential_id')->nullable()->index();
            $table->foreign('source_credential_id', 'smart_backend_source_credential_fk')
                ->references('source_credential_id')
                ->on('integration.source_credentials')
                ->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            CREATE TABLE integration.source_credential_versions (
                source_credential_version_id bigserial PRIMARY KEY,
                credential_version_uuid uuid NOT NULL UNIQUE,
                source_credential_id bigint NOT NULL REFERENCES integration.source_credentials(source_credential_id) ON DELETE RESTRICT,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                version_number integer NOT NULL CHECK (version_number > 0),
                previous_version_id bigint REFERENCES integration.source_credential_versions(source_credential_version_id) ON DELETE RESTRICT,
                credential_type varchar(80) NOT NULL,
                secret_ref varchar(255),
                certificate_ref varchar(255),
                jwks_uri text,
                credential_state varchar(32) NOT NULL,
                valid_from timestamptz,
                expires_at timestamptz,
                rotates_at timestamptz,
                rotation_overlap_ends_at timestamptz,
                authority_sha256 char(64) NOT NULL CHECK (authority_sha256 ~ '^[0-9a-f]{64}$'),
                change_reason text NOT NULL CHECK (length(change_reason) BETWEEN 10 AND 500),
                created_by_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                governed_change_request_uuid uuid REFERENCES governance.change_requests(change_request_uuid) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT source_credential_versions_number_uniq UNIQUE (source_credential_id, version_number),
                CONSTRAINT source_credential_versions_source_chk CHECK (source_id > 0),
                CONSTRAINT source_credential_versions_reference_chk CHECK (
                    secret_ref IS NOT NULL OR certificate_ref IS NOT NULL OR jwks_uri IS NOT NULL
                    OR credential_state IN ('disabled', 'revoked', 'expired')
                ),
                CONSTRAINT source_credential_versions_state_chk CHECK (
                    credential_state IN ('planned', 'active', 'rotating', 'disabled', 'revoked', 'expired')
                ),
                CONSTRAINT source_credential_versions_time_chk CHECK (
                    expires_at IS NULL OR valid_from IS NULL OR expires_at > valid_from
                ),
                CONSTRAINT source_credential_versions_overlap_chk CHECK (
                    rotation_overlap_ends_at IS NULL OR valid_from IS NULL OR rotation_overlap_ends_at > valid_from
                )
            );

            CREATE INDEX source_credential_versions_credential_idx
                ON integration.source_credential_versions (source_credential_id, version_number DESC);
            CREATE INDEX source_credential_versions_source_idx
                ON integration.source_credential_versions (source_id, created_at DESC);

            CREATE TABLE integration.credential_validation_observations (
                credential_validation_observation_id bigserial PRIMARY KEY,
                observation_uuid uuid NOT NULL UNIQUE,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                source_credential_id bigint NOT NULL REFERENCES integration.source_credentials(source_credential_id) ON DELETE RESTRICT,
                credential_version_id bigint NOT NULL REFERENCES integration.source_credential_versions(source_credential_version_id) ON DELETE RESTRICT,
                validation_status varchar(24) NOT NULL,
                provider_scheme varchar(40),
                provider_version varchar(190),
                provider_lease_expires_at timestamptz,
                credential_expires_at timestamptz,
                rotation_state varchar(32) NOT NULL,
                reference_fingerprints jsonb NOT NULL DEFAULT '{}'::jsonb,
                certificate_metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
                requirement_results jsonb NOT NULL DEFAULT '[]'::jsonb,
                input_sha256 char(64) NOT NULL CHECK (input_sha256 ~ '^[0-9a-f]{64}$'),
                error_code varchar(120),
                validated_for_at timestamptz NOT NULL,
                validated_by_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                observed_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT credential_validation_status_chk CHECK (
                    validation_status IN ('ready', 'not_ready', 'failed')
                ),
                CONSTRAINT credential_validation_rotation_chk CHECK (
                    rotation_state IN ('current', 'due_90', 'due_60', 'due_30', 'due_14', 'due_7', 'expired', 'revoked', 'disabled')
                ),
                CONSTRAINT credential_validation_reference_json_chk CHECK (jsonb_typeof(reference_fingerprints) = 'object'),
                CONSTRAINT credential_validation_certificate_json_chk CHECK (jsonb_typeof(certificate_metadata) = 'object'),
                CONSTRAINT credential_validation_requirements_json_chk CHECK (jsonb_typeof(requirement_results) = 'array')
            );

            CREATE INDEX credential_validation_source_observed_idx
                ON integration.credential_validation_observations (source_id, observed_at DESC);
            CREATE INDEX credential_validation_credential_observed_idx
                ON integration.credential_validation_observations (source_credential_id, observed_at DESC);

            CREATE TABLE integration.source_network_routes (
                source_network_route_id bigserial PRIMARY KEY,
                route_uuid uuid NOT NULL UNIQUE,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                source_endpoint_id bigint REFERENCES integration.source_endpoints(source_endpoint_id) ON DELETE RESTRICT,
                route_key varchar(120) NOT NULL,
                environment varchar(40) NOT NULL,
                transport varchar(40) NOT NULL,
                hostname varchar(253) NOT NULL,
                port integer NOT NULL,
                proxy_url text,
                dns_policy varchar(32) NOT NULL,
                allowed_ip_cidrs jsonb NOT NULL DEFAULT '[]'::jsonb,
                egress_policy_key varchar(120) NOT NULL,
                mtls_required boolean NOT NULL DEFAULT false,
                client_credential_id bigint REFERENCES integration.source_credentials(source_credential_id) ON DELETE RESTRICT,
                server_name varchar(253),
                status varchar(24) NOT NULL DEFAULT 'unverified',
                change_reason text NOT NULL CHECK (length(change_reason) BETWEEN 10 AND 500),
                created_by_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT source_network_routes_key_uniq UNIQUE (source_id, route_key),
                CONSTRAINT source_network_routes_environment_chk CHECK (
                    environment IN ('sandbox', 'testing', 'staging', 'production')
                ),
                CONSTRAINT source_network_routes_transport_chk CHECK (
                    transport IN ('public_internet', 'vpn', 'private_link', 'direct_connect', 'interface_engine')
                ),
                CONSTRAINT source_network_routes_port_chk CHECK (port BETWEEN 1 AND 65535),
                CONSTRAINT source_network_routes_dns_policy_chk CHECK (
                    dns_policy IN ('public_only', 'allowlist', 'private_only')
                ),
                CONSTRAINT source_network_routes_status_chk CHECK (
                    status IN ('unverified', 'validated', 'degraded', 'blocked', 'retired')
                ),
                CONSTRAINT source_network_routes_cidrs_chk CHECK (jsonb_typeof(allowed_ip_cidrs) = 'array'),
                CONSTRAINT source_network_routes_mtls_chk CHECK (
                    NOT mtls_required OR client_credential_id IS NOT NULL
                )
            );

            CREATE INDEX source_network_routes_source_status_idx
                ON integration.source_network_routes (source_id, status, environment);
            CREATE UNIQUE INDEX source_network_routes_active_endpoint_uniq
                ON integration.source_network_routes (source_endpoint_id)
                WHERE source_endpoint_id IS NOT NULL AND status <> 'retired';
            CREATE INDEX source_network_routes_host_port_idx
                ON integration.source_network_routes (hostname, port);

            CREATE TABLE integration.network_route_observations (
                network_route_observation_id bigserial PRIMARY KEY,
                observation_uuid uuid NOT NULL UNIQUE,
                source_network_route_id bigint NOT NULL REFERENCES integration.source_network_routes(source_network_route_id) ON DELETE RESTRICT,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                observation_status varchar(24) NOT NULL,
                address_count integer NOT NULL DEFAULT 0 CHECK (address_count >= 0),
                address_fingerprints jsonb NOT NULL DEFAULT '[]'::jsonb,
                policy_sha256 char(64) NOT NULL CHECK (policy_sha256 ~ '^[0-9a-f]{64}$'),
                error_code varchar(120),
                observed_by_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                observed_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT network_route_observations_status_chk CHECK (
                    observation_status IN ('validated', 'degraded', 'blocked')
                ),
                CONSTRAINT network_route_observations_addresses_chk CHECK (jsonb_typeof(address_fingerprints) = 'array')
            );

            CREATE INDEX network_route_observations_route_observed_idx
                ON integration.network_route_observations (source_network_route_id, observed_at DESC);
        SQL);

        $this->backfillSmartCredentialAuthority();
        $this->backfillCredentialVersions();

        DB::unprepared(<<<'SQL'
            ALTER TABLE integration.source_credentials
                ADD CONSTRAINT source_credentials_current_version_fk
                FOREIGN KEY (current_credential_version_id)
                REFERENCES integration.source_credential_versions(source_credential_version_id)
                ON DELETE RESTRICT;
            ALTER TABLE integration.source_credentials
                ADD CONSTRAINT source_credentials_state_chk
                CHECK (credential_state IN ('planned', 'active', 'rotating', 'disabled', 'revoked', 'expired'));
            ALTER TABLE integration.source_credentials
                ADD CONSTRAINT source_credentials_time_chk
                CHECK (expires_at IS NULL OR valid_from IS NULL OR expires_at > valid_from);
            ALTER TABLE integration.source_credentials
                ADD CONSTRAINT source_credentials_overlap_chk
                CHECK (rotation_overlap_ends_at IS NULL OR valid_from IS NULL OR rotation_overlap_ends_at > valid_from);

            CREATE INDEX source_credentials_current_version_idx
                ON integration.source_credentials (current_credential_version_id);
            CREATE INDEX source_credentials_rotation_idx
                ON integration.source_credentials (credential_state, rotates_at, expires_at);

            CREATE OR REPLACE FUNCTION integration.reject_credential_governance_ledger_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'credential governance evidence is append-only';
            END;
            $$;

            CREATE TRIGGER source_credential_versions_append_only
                BEFORE UPDATE OR DELETE ON integration.source_credential_versions
                FOR EACH ROW EXECUTE FUNCTION integration.reject_credential_governance_ledger_mutation();
            CREATE TRIGGER credential_validation_observations_append_only
                BEFORE UPDATE OR DELETE ON integration.credential_validation_observations
                FOR EACH ROW EXECUTE FUNCTION integration.reject_credential_governance_ledger_mutation();
            CREATE TRIGGER network_route_observations_append_only
                BEFORE UPDATE OR DELETE ON integration.network_route_observations
                FOR EACH ROW EXECUTE FUNCTION integration.reject_credential_governance_ledger_mutation();

            CREATE OR REPLACE FUNCTION integration.enforce_source_credential_projection()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE authority integration.source_credential_versions%ROWTYPE;
            DECLARE expected_active boolean;
            BEGIN
                IF NEW.source_id IS DISTINCT FROM OLD.source_id
                   OR NEW.credential_key IS DISTINCT FROM OLD.credential_key THEN
                    RAISE EXCEPTION 'integration credential identity is immutable';
                END IF;
                IF NEW.current_credential_version_id IS NULL THEN
                    RAISE EXCEPTION 'a versioned integration credential cannot return to an unversioned state';
                END IF;

                SELECT version.* INTO authority
                FROM integration.source_credential_versions AS version
                WHERE version.source_credential_version_id = NEW.current_credential_version_id
                  AND version.source_credential_id = NEW.source_credential_id
                  AND version.source_id = NEW.source_id;

                IF authority.source_credential_version_id IS NULL THEN
                    RAISE EXCEPTION 'current credential version does not belong to the credential and source';
                END IF;

                expected_active := authority.credential_state IN ('planned', 'active', 'rotating');
                IF NEW.credential_type IS DISTINCT FROM authority.credential_type
                   OR NEW.secret_ref IS DISTINCT FROM authority.secret_ref
                   OR NEW.certificate_ref IS DISTINCT FROM authority.certificate_ref
                   OR NEW.jwks_uri IS DISTINCT FROM authority.jwks_uri
                   OR NEW.credential_state IS DISTINCT FROM authority.credential_state
                   OR NEW.valid_from IS DISTINCT FROM authority.valid_from
                   OR NEW.expires_at IS DISTINCT FROM authority.expires_at
                   OR NEW.rotates_at IS DISTINCT FROM authority.rotates_at
                   OR NEW.rotation_overlap_ends_at IS DISTINCT FROM authority.rotation_overlap_ends_at
                   OR NEW.is_active IS DISTINCT FROM expected_active THEN
                    RAISE EXCEPTION 'integration credential projection must exactly match its immutable authority version';
                END IF;

                IF NEW.credential_state = 'revoked' AND NEW.revoked_at IS NULL THEN
                    RAISE EXCEPTION 'a revoked integration credential requires revocation evidence';
                END IF;
                IF NEW.credential_state <> 'revoked' AND NEW.revoked_at IS NOT NULL THEN
                    RAISE EXCEPTION 'revocation evidence is valid only for a revoked integration credential';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER source_credentials_authority_projection
                BEFORE UPDATE OF
                    source_id, credential_key, credential_type, secret_ref, certificate_ref,
                    jwks_uri, rotates_at, is_active, current_credential_version_id,
                    credential_state, valid_from, expires_at, rotation_overlap_ends_at, revoked_at
                ON integration.source_credentials
                FOR EACH ROW EXECUTE FUNCTION integration.enforce_source_credential_projection();

            CREATE OR REPLACE FUNCTION integration.reject_versioned_credential_delete()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.current_credential_version_id IS NOT NULL THEN
                    RAISE EXCEPTION 'versioned credentials must be revoked, not deleted';
                END IF;
                RETURN OLD;
            END;
            $$;

            CREATE TRIGGER source_credentials_no_versioned_delete
                BEFORE DELETE ON integration.source_credentials
                FOR EACH ROW EXECUTE FUNCTION integration.reject_versioned_credential_delete();

            CREATE OR REPLACE FUNCTION integration.enforce_network_route_scope()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE endpoint_source_id bigint;
            DECLARE credential_source_id bigint;
            DECLARE source_environment text;
            BEGIN
                SELECT environment INTO source_environment
                FROM integration.sources WHERE source_id = NEW.source_id;
                IF source_environment IS NULL OR source_environment <> NEW.environment THEN
                    RAISE EXCEPTION 'network route environment must match the integration source';
                END IF;

                IF NEW.source_endpoint_id IS NOT NULL THEN
                    SELECT source_id INTO endpoint_source_id
                    FROM integration.source_endpoints WHERE source_endpoint_id = NEW.source_endpoint_id;
                    IF endpoint_source_id IS DISTINCT FROM NEW.source_id THEN
                        RAISE EXCEPTION 'network route endpoint must belong to the integration source';
                    END IF;
                END IF;

                IF NEW.client_credential_id IS NOT NULL THEN
                    SELECT source_id INTO credential_source_id
                    FROM integration.source_credentials WHERE source_credential_id = NEW.client_credential_id;
                    IF credential_source_id IS DISTINCT FROM NEW.source_id THEN
                        RAISE EXCEPTION 'network route credential must belong to the integration source';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER source_network_routes_scope
                BEFORE INSERT OR UPDATE ON integration.source_network_routes
                FOR EACH ROW EXECUTE FUNCTION integration.enforce_network_route_scope();

            CREATE OR REPLACE FUNCTION integration.enforce_smart_credential_authority_scope()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE authority_source_id bigint;
            DECLARE authority_secret_ref text;
            BEGIN
                IF NEW.jwks_secret_ref IS NOT NULL AND NEW.source_credential_id IS NULL THEN
                    RAISE EXCEPTION 'SMART runtime secret references require governed credential authority';
                END IF;
                IF NEW.source_credential_id IS NOT NULL THEN
                    SELECT source_id, secret_ref INTO authority_source_id, authority_secret_ref
                    FROM integration.source_credentials
                    WHERE source_credential_id = NEW.source_credential_id;
                    IF authority_source_id IS DISTINCT FROM NEW.source_id THEN
                        RAISE EXCEPTION 'SMART runtime credential authority must belong to the integration source';
                    END IF;
                    IF authority_secret_ref IS DISTINCT FROM NEW.jwks_secret_ref THEN
                        RAISE EXCEPTION 'SMART runtime reference must match its governed credential projection';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER smart_backend_credentials_authority_scope
                BEFORE INSERT OR UPDATE OF source_id, source_credential_id, jwks_secret_ref
                ON integration.smart_backend_credentials
                FOR EACH ROW EXECUTE FUNCTION integration.enforce_smart_credential_authority_scope();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS smart_backend_credentials_authority_scope ON integration.smart_backend_credentials;
            DROP FUNCTION IF EXISTS integration.enforce_smart_credential_authority_scope();
            DROP TRIGGER IF EXISTS source_network_routes_scope ON integration.source_network_routes;
            DROP FUNCTION IF EXISTS integration.enforce_network_route_scope();
            DROP TRIGGER IF EXISTS source_credentials_no_versioned_delete ON integration.source_credentials;
            DROP FUNCTION IF EXISTS integration.reject_versioned_credential_delete();
            DROP TRIGGER IF EXISTS source_credentials_authority_projection ON integration.source_credentials;
            DROP FUNCTION IF EXISTS integration.enforce_source_credential_projection();
            DROP TRIGGER IF EXISTS network_route_observations_append_only ON integration.network_route_observations;
            DROP TRIGGER IF EXISTS credential_validation_observations_append_only ON integration.credential_validation_observations;
            DROP TRIGGER IF EXISTS source_credential_versions_append_only ON integration.source_credential_versions;
            DROP FUNCTION IF EXISTS integration.reject_credential_governance_ledger_mutation();
            DROP TABLE IF EXISTS integration.network_route_observations;
            DROP TABLE IF EXISTS integration.source_network_routes;
            DROP TABLE IF EXISTS integration.credential_validation_observations;
            ALTER TABLE integration.source_credentials DROP CONSTRAINT IF EXISTS source_credentials_current_version_fk;
            ALTER TABLE integration.source_credentials DROP CONSTRAINT IF EXISTS source_credentials_state_chk;
            ALTER TABLE integration.source_credentials DROP CONSTRAINT IF EXISTS source_credentials_time_chk;
            ALTER TABLE integration.source_credentials DROP CONSTRAINT IF EXISTS source_credentials_overlap_chk;
            DROP TABLE IF EXISTS integration.source_credential_versions;
        SQL);

        Schema::table('integration.smart_backend_credentials', function (Blueprint $table): void {
            $table->dropForeign('smart_backend_source_credential_fk');
            $table->dropColumn('source_credential_id');
        });

        Schema::table('integration.source_credentials', function (Blueprint $table): void {
            $table->dropColumn([
                'current_credential_version_id',
                'credential_state',
                'valid_from',
                'expires_at',
                'rotation_overlap_ends_at',
                'revoked_at',
                'last_used_at',
            ]);
        });
    }

    private function backfillCredentialVersions(): void
    {
        DB::table('integration.source_credentials')
            ->orderBy('source_credential_id')
            ->each(function (object $credential): void {
                $hasReference = filled($credential->secret_ref)
                    || filled($credential->certificate_ref)
                    || filled($credential->jwks_uri);
                $state = (bool) $credential->is_active && $hasReference ? 'active' : 'disabled';
                $validFrom = $credential->created_at ?? now();
                $authority = [
                    'credential_type' => (string) $credential->credential_type,
                    'secret_ref' => $credential->secret_ref,
                    'certificate_ref' => $credential->certificate_ref,
                    'jwks_uri' => $credential->jwks_uri,
                    'credential_state' => $state,
                    'valid_from' => (string) $validFrom,
                    'expires_at' => null,
                    'rotates_at' => $credential->rotates_at !== null ? (string) $credential->rotates_at : null,
                    'rotation_overlap_ends_at' => null,
                ];
                $versionId = (int) DB::table('integration.source_credential_versions')->insertGetId([
                    'credential_version_uuid' => (string) \Illuminate\Support\Str::uuid7(),
                    'source_credential_id' => $credential->source_credential_id,
                    'source_id' => $credential->source_id,
                    'version_number' => 1,
                    'previous_version_id' => null,
                    ...$authority,
                    'authority_sha256' => hash('sha256', json_encode($authority, JSON_THROW_ON_ERROR)),
                    'change_reason' => 'Backfilled from the existing credential reference projection.',
                    'created_at' => $validFrom,
                ], 'source_credential_version_id');

                DB::table('integration.source_credentials')
                    ->where('source_credential_id', $credential->source_credential_id)
                    ->update([
                        'current_credential_version_id' => $versionId,
                        'credential_state' => $state,
                        'valid_from' => $validFrom,
                        'is_active' => $state === 'active',
                    ]);
            });
    }

    private function backfillSmartCredentialAuthority(): void
    {
        DB::table('integration.smart_backend_credentials')
            ->whereNotNull('jwks_secret_ref')
            ->whereNull('source_credential_id')
            ->orderBy('smart_backend_credential_id')
            ->each(function (object $smart): void {
                $credentialKey = substr((string) $smart->credential_key, 0, 120);
                $existing = DB::table('integration.source_credentials')
                    ->where('source_id', $smart->source_id)
                    ->where('credential_key', $credentialKey)
                    ->first();
                if ($existing !== null && (string) $existing->secret_ref !== (string) $smart->jwks_secret_ref) {
                    $credentialKey = substr('smart-runtime-'.$smart->smart_backend_credential_id, 0, 120);
                    $existing = null;
                }
                $credentialId = $existing?->source_credential_id;
                if ($credentialId === null) {
                    $credentialId = DB::table('integration.source_credentials')->insertGetId([
                        'source_id' => $smart->source_id,
                        'credential_key' => $credentialKey,
                        'credential_type' => 'smart_backend_services',
                        'secret_ref' => $smart->jwks_secret_ref,
                        'certificate_ref' => null,
                        'jwks_uri' => null,
                        'rotates_at' => $smart->rotates_at,
                        'is_active' => true,
                        'credential_state' => 'active',
                        'valid_from' => $smart->created_at ?? now(),
                        'metadata' => json_encode([
                            'managed_by' => 'smart_backend_credentials_backfill',
                            'legacy_smart_backend_credential_id' => (int) $smart->smart_backend_credential_id,
                        ], JSON_THROW_ON_ERROR),
                        'created_at' => $smart->created_at ?? now(),
                        'updated_at' => $smart->updated_at ?? now(),
                    ], 'source_credential_id');
                }
                DB::table('integration.smart_backend_credentials')
                    ->where('smart_backend_credential_id', $smart->smart_backend_credential_id)
                    ->update(['source_credential_id' => $credentialId]);
            });
    }
};
