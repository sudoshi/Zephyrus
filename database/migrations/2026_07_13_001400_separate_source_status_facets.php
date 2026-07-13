<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * INT-LIFECYCLE (facet separation) + INT-SECRET (mTLS peer pinning metadata).
 *
 * `integration.sources` conflates several unrelated signals. This migration
 * makes the SIX status facets independently addressable so that lifecycle
 * state, protocol health, data freshness, conformance, contract, and incident
 * status can each be surfaced and reasoned about on their own:
 *
 *   - lifecycle_state       — already governed on integration.sources
 *   - protocol_health       — already read-only runtime evidence (health_observations)
 *   - data_freshness        — already derived (SourceHealthDigestService / watermarks)
 *   - conformance_status    — NEW governed, append-only history + current projection
 *   - contract_status       — NEW governed, append-only, evidence-pointer only
 *   - incident_status       — NEW operator-updatable, append-only, incident-linkable
 *
 * The three NEW facets each get an append-only event ledger and a single
 * trigger-guarded current projection (integration.source_status_facets) that
 * must exactly reflect the latest event of each facet. The append-only trigger
 * style and clinical-content guards are copied from
 * 2026_07_13_001000_create_source_observability_control_plane.php. Every
 * authority is insert-only; nothing here mutates an existing table's data.
 *
 * INT-SECRET also adds explicit mTLS server-peer trust/pinning policy METADATA
 * (integration.source_peer_pin_policies) so a partner contract that requires
 * controls beyond normal CA + server-name verification can pin an SPKI or cert
 * fingerprint (or issuer DN) with a required flag and an effective interval.
 * IntegrationConnectionGuard consults it and fails closed on a required mismatch.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Append-only conformance history. A source's declared vendor/version
            -- conformance state against a named profile at a named version.
            CREATE TABLE integration.source_conformance_events (
                source_conformance_event_id bigserial PRIMARY KEY,
                event_uuid uuid NOT NULL UNIQUE,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                previous_event_id bigint REFERENCES integration.source_conformance_events(source_conformance_event_id) ON DELETE RESTRICT,
                conformance_status varchar(20) NOT NULL,
                profile_key varchar(120),
                profile_version varchar(60),
                evidence_reference_sha256 char(64),
                reason varchar(500) NOT NULL,
                actor_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                occurred_at timestamptz NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT source_conformance_status_chk CHECK (
                    conformance_status IN ('not_started', 'in_progress', 'passed', 'failed', 'waived')
                ),
                CONSTRAINT source_conformance_profile_key_chk CHECK (
                    profile_key IS NULL OR profile_key ~ '^[a-z0-9][a-z0-9._:-]{0,118}[a-z0-9]$'
                ),
                CONSTRAINT source_conformance_profile_version_chk CHECK (
                    profile_version IS NULL OR profile_version ~ '^[a-zA-Z0-9][a-zA-Z0-9._+-]{0,58}[a-zA-Z0-9]$'
                ),
                CONSTRAINT source_conformance_evidence_chk CHECK (
                    evidence_reference_sha256 IS NULL OR evidence_reference_sha256 ~ '^[0-9a-f]{64}$'
                ),
                CONSTRAINT source_conformance_reason_chk CHECK (char_length(reason) BETWEEN 10 AND 500)
            );

            CREATE INDEX source_conformance_events_source_idx
                ON integration.source_conformance_events (source_id, source_conformance_event_id DESC);
            CREATE UNIQUE INDEX source_conformance_events_previous_once_idx
                ON integration.source_conformance_events (previous_event_id)
                WHERE previous_event_id IS NOT NULL;

            -- Append-only contract/legal-entitlement presence history (BAA / DUA /
            -- support). Evidence-pointer only: an existing source_evidence_records
            -- reference and its hash, NEVER document content.
            CREATE TABLE integration.source_contract_events (
                source_contract_event_id bigserial PRIMARY KEY,
                event_uuid uuid NOT NULL UNIQUE,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                previous_event_id bigint REFERENCES integration.source_contract_events(source_contract_event_id) ON DELETE RESTRICT,
                contract_status varchar(20) NOT NULL,
                source_evidence_record_id bigint REFERENCES integration.source_evidence_records(source_evidence_record_id) ON DELETE RESTRICT,
                evidence_reference_sha256 char(64),
                expires_at timestamptz,
                reason varchar(500) NOT NULL,
                actor_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                occurred_at timestamptz NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT source_contract_status_chk CHECK (
                    contract_status IN ('none', 'pending', 'active', 'expired')
                ),
                CONSTRAINT source_contract_evidence_chk CHECK (
                    evidence_reference_sha256 IS NULL OR evidence_reference_sha256 ~ '^[0-9a-f]{64}$'
                ),
                CONSTRAINT source_contract_active_requires_evidence_chk CHECK (
                    contract_status <> 'active' OR source_evidence_record_id IS NOT NULL
                ),
                CONSTRAINT source_contract_reason_chk CHECK (char_length(reason) BETWEEN 10 AND 500)
            );

            CREATE INDEX source_contract_events_source_idx
                ON integration.source_contract_events (source_id, source_contract_event_id DESC);
            CREATE UNIQUE INDEX source_contract_events_previous_once_idx
                ON integration.source_contract_events (previous_event_id)
                WHERE previous_event_id IS NOT NULL;

            -- Append-only incident history. Operator-updatable and linkable to the
            -- slo_breach incident SHA-256 references that already exist.
            CREATE TABLE integration.source_incident_events (
                source_incident_event_id bigserial PRIMARY KEY,
                event_uuid uuid NOT NULL UNIQUE,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                previous_event_id bigint REFERENCES integration.source_incident_events(source_incident_event_id) ON DELETE RESTRICT,
                incident_status varchar(20) NOT NULL,
                incident_reference_hash char(64),
                slo_breach_id bigint REFERENCES integration.slo_breaches(slo_breach_id) ON DELETE RESTRICT,
                reason varchar(500) NOT NULL,
                actor_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                occurred_at timestamptz NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT source_incident_status_chk CHECK (
                    incident_status IN ('none', 'open', 'monitoring', 'resolved')
                ),
                CONSTRAINT source_incident_reference_chk CHECK (
                    incident_reference_hash IS NULL OR incident_reference_hash ~ '^[0-9a-f]{64}$'
                ),
                CONSTRAINT source_incident_reason_chk CHECK (char_length(reason) BETWEEN 10 AND 500)
            );

            CREATE INDEX source_incident_events_source_idx
                ON integration.source_incident_events (source_id, source_incident_event_id DESC);
            CREATE UNIQUE INDEX source_incident_events_previous_once_idx
                ON integration.source_incident_events (previous_event_id)
                WHERE previous_event_id IS NOT NULL;

            -- Single current-facet projection. Each column exactly reflects the
            -- latest event of its facet; the projection trigger enforces that.
            CREATE TABLE integration.source_status_facets (
                source_id bigint PRIMARY KEY REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                conformance_status varchar(20) NOT NULL DEFAULT 'not_started',
                conformance_event_id bigint REFERENCES integration.source_conformance_events(source_conformance_event_id) ON DELETE RESTRICT,
                conformance_profile_key varchar(120),
                conformance_profile_version varchar(60),
                contract_status varchar(20) NOT NULL DEFAULT 'none',
                contract_event_id bigint REFERENCES integration.source_contract_events(source_contract_event_id) ON DELETE RESTRICT,
                contract_expires_at timestamptz,
                incident_status varchar(20) NOT NULL DEFAULT 'none',
                incident_event_id bigint REFERENCES integration.source_incident_events(source_incident_event_id) ON DELETE RESTRICT,
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT source_status_facets_conformance_chk CHECK (
                    conformance_status IN ('not_started', 'in_progress', 'passed', 'failed', 'waived')
                ),
                CONSTRAINT source_status_facets_contract_chk CHECK (
                    contract_status IN ('none', 'pending', 'active', 'expired')
                ),
                CONSTRAINT source_status_facets_incident_chk CHECK (
                    incident_status IN ('none', 'open', 'monitoring', 'resolved')
                )
            );

            CREATE INDEX source_status_facets_conformance_idx
                ON integration.source_status_facets (conformance_status);
            CREATE INDEX source_status_facets_contract_idx
                ON integration.source_status_facets (contract_status);
            CREATE INDEX source_status_facets_incident_idx
                ON integration.source_status_facets (incident_status);

            -- INT-SECRET: explicit mTLS server-peer trust/pinning policy metadata.
            -- One non-retired policy may attach to a source network route where a
            -- partner contract requires controls beyond normal CA + server-name
            -- verification. Fingerprints are stored (they are public artifacts of
            -- the peer certificate), never private key material.
            CREATE TABLE integration.source_peer_pin_policies (
                source_peer_pin_policy_id bigserial PRIMARY KEY,
                policy_uuid uuid NOT NULL UNIQUE,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                source_network_route_id bigint NOT NULL,
                pin_mode varchar(20) NOT NULL,
                pinned_fingerprints jsonb NOT NULL DEFAULT '[]'::jsonb,
                pinned_issuer_dn varchar(400),
                expected_peer_subject varchar(400),
                expected_peer_san varchar(253),
                required boolean NOT NULL DEFAULT false,
                effective_from timestamptz,
                effective_until timestamptz,
                status varchar(20) NOT NULL DEFAULT 'active',
                change_reason varchar(500) NOT NULL,
                created_by_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT source_peer_pin_policies_mode_chk CHECK (
                    pin_mode IN ('none', 'spki_sha256', 'cert_sha256', 'issuer_dn')
                ),
                CONSTRAINT source_peer_pin_policies_status_chk CHECK (status IN ('active', 'retired')),
                CONSTRAINT source_peer_pin_policies_fingerprints_chk CHECK (jsonb_typeof(pinned_fingerprints) = 'array'),
                CONSTRAINT source_peer_pin_policies_fingerprint_presence_chk CHECK (
                    pin_mode NOT IN ('spki_sha256', 'cert_sha256')
                    OR jsonb_array_length(pinned_fingerprints) > 0
                ),
                CONSTRAINT source_peer_pin_policies_issuer_presence_chk CHECK (
                    pin_mode <> 'issuer_dn' OR pinned_issuer_dn IS NOT NULL
                ),
                CONSTRAINT source_peer_pin_policies_interval_chk CHECK (
                    effective_from IS NULL OR effective_until IS NULL OR effective_until > effective_from
                ),
                CONSTRAINT source_peer_pin_policies_reason_chk CHECK (char_length(change_reason) BETWEEN 10 AND 500)
            );

            CREATE UNIQUE INDEX source_peer_pin_policies_active_route_uniq
                ON integration.source_peer_pin_policies (source_network_route_id)
                WHERE status <> 'retired';
            CREATE INDEX source_peer_pin_policies_source_idx
                ON integration.source_peer_pin_policies (source_id, status);

            -- INT-SECRET: credential-rotation threshold crossings become real
            -- operational alerts through the shared dispatcher rather than a
            -- console badge. Extend the delivery ledger's allowed subject types
            -- (additive: the set only broadens, existing rows stay valid) so
            -- credential-rotation deliveries land in the same PHI-free ledger.
            ALTER TABLE integration.operational_alert_deliveries
                DROP CONSTRAINT operational_alert_delivery_subject_chk;
            ALTER TABLE integration.operational_alert_deliveries
                ADD CONSTRAINT operational_alert_delivery_subject_chk
                CHECK (subject_type IN ('slo_breach', 'system_health_component', 'credential_rotation'));

            -- Append-only per-band dedupe ledger so a threshold band pages ONCE
            -- per crossing (mirroring the breach path's flap damping), not every
            -- scheduler run. PHI-free/secret-free: a credential id, a band, and a
            -- dispatch outcome only — never the reference, provider, or secret.
            CREATE TABLE integration.credential_rotation_alert_states (
                credential_rotation_alert_state_id bigserial PRIMARY KEY,
                state_uuid uuid NOT NULL UNIQUE,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                source_credential_id bigint NOT NULL REFERENCES integration.source_credentials(source_credential_id) ON DELETE RESTRICT,
                rotation_band varchar(16) NOT NULL,
                days_until_expiry integer,
                dispatch_outcome varchar(20) NOT NULL,
                recipient_count integer NOT NULL DEFAULT 0,
                reason_code varchar(80) NOT NULL,
                correlation_uuid uuid,
                observed_at timestamptz NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT credential_rotation_alert_band_chk CHECK (
                    rotation_band IN ('due_90', 'due_60', 'due_30', 'due_14', 'due_7', 'expired')
                ),
                CONSTRAINT credential_rotation_alert_outcome_chk CHECK (
                    dispatch_outcome IN ('dispatched', 'inert', 'deduped')
                ),
                CONSTRAINT credential_rotation_alert_recipients_chk CHECK (recipient_count >= 0),
                CONSTRAINT credential_rotation_alert_reason_chk CHECK (reason_code ~ '^[a-z][a-z0-9_]{0,79}$')
            );

            CREATE INDEX credential_rotation_alert_states_credential_idx
                ON integration.credential_rotation_alert_states (source_credential_id, credential_rotation_alert_state_id DESC);
            -- One dispatched alert per credential per band; a re-entry of the same
            -- band is deduped in the application before this uniqueness ever trips.
            CREATE UNIQUE INDEX credential_rotation_alert_states_band_uniq
                ON integration.credential_rotation_alert_states (source_credential_id, rotation_band)
                WHERE dispatch_outcome = 'dispatched';

            CREATE OR REPLACE FUNCTION integration.reject_credential_rotation_alert_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'credential rotation alert states are append-only';
            END;
            $$;

            CREATE TRIGGER credential_rotation_alert_states_append_only
                BEFORE UPDATE OR DELETE ON integration.credential_rotation_alert_states
                FOR EACH ROW EXECUTE FUNCTION integration.reject_credential_rotation_alert_mutation();
            CREATE TRIGGER credential_rotation_alert_states_clinical_content_guard
                BEFORE INSERT ON integration.credential_rotation_alert_states
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();

            -- Append-only enforcement guards for the three facet ledgers.
            CREATE OR REPLACE FUNCTION integration.reject_source_status_facet_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'source status facet histories are append-only';
            END;
            $$;

            CREATE OR REPLACE FUNCTION integration.enforce_source_facet_event_contiguity()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE previous_source_id bigint;
            BEGIN
                IF NEW.previous_event_id IS NOT NULL THEN
                    EXECUTE format(
                        'SELECT source_id FROM %I.%I WHERE %I = $1',
                        TG_TABLE_SCHEMA, TG_TABLE_NAME, TG_ARGV[0]
                    ) INTO previous_source_id USING NEW.previous_event_id;
                    IF previous_source_id IS DISTINCT FROM NEW.source_id THEN
                        RAISE EXCEPTION 'source facet history must be contiguous within one source';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION integration.enforce_source_incident_breach_scope()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE breach_source_id bigint;
            BEGIN
                IF NEW.slo_breach_id IS NOT NULL THEN
                    SELECT source_id INTO breach_source_id
                    FROM integration.slo_breaches
                    WHERE slo_breach_id = NEW.slo_breach_id;
                    IF breach_source_id IS DISTINCT FROM NEW.source_id THEN
                        RAISE EXCEPTION 'linked SLO breach must belong to the incident source';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION integration.enforce_source_peer_pin_route_scope()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE route_source_id bigint;
            BEGIN
                SELECT source_id INTO route_source_id
                FROM integration.source_network_routes
                WHERE source_network_route_id = NEW.source_network_route_id;
                IF route_source_id IS NULL THEN
                    RAISE EXCEPTION 'peer pin policy references an unknown network route';
                END IF;
                IF route_source_id IS DISTINCT FROM NEW.source_id THEN
                    RAISE EXCEPTION 'peer pin policy route must belong to the pinned source';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION integration.enforce_source_contract_evidence_scope()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE evidence_source_id bigint;
            BEGIN
                IF NEW.source_evidence_record_id IS NOT NULL THEN
                    SELECT source_id INTO evidence_source_id
                    FROM integration.source_evidence_records
                    WHERE source_evidence_record_id = NEW.source_evidence_record_id;
                    IF evidence_source_id IS DISTINCT FROM NEW.source_id THEN
                        RAISE EXCEPTION 'contract evidence pointer must belong to the contract source';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION integration.enforce_source_status_facet_projection()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE conformance integration.source_conformance_events%ROWTYPE;
            DECLARE contract integration.source_contract_events%ROWTYPE;
            DECLARE incident integration.source_incident_events%ROWTYPE;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'source status facet projection cannot be deleted';
                END IF;

                IF NEW.conformance_event_id IS NOT NULL THEN
                    SELECT * INTO conformance FROM integration.source_conformance_events
                    WHERE source_conformance_event_id = NEW.conformance_event_id;
                    IF conformance.source_id IS DISTINCT FROM NEW.source_id
                       OR conformance.conformance_status IS DISTINCT FROM NEW.conformance_status
                       OR conformance.profile_key IS DISTINCT FROM NEW.conformance_profile_key
                       OR conformance.profile_version IS DISTINCT FROM NEW.conformance_profile_version THEN
                        RAISE EXCEPTION 'conformance projection must exactly reflect its latest event';
                    END IF;
                ELSIF NEW.conformance_status <> 'not_started' THEN
                    RAISE EXCEPTION 'a non-default conformance projection requires a governing event';
                END IF;

                IF NEW.contract_event_id IS NOT NULL THEN
                    SELECT * INTO contract FROM integration.source_contract_events
                    WHERE source_contract_event_id = NEW.contract_event_id;
                    IF contract.source_id IS DISTINCT FROM NEW.source_id
                       OR contract.contract_status IS DISTINCT FROM NEW.contract_status
                       OR contract.expires_at IS DISTINCT FROM NEW.contract_expires_at THEN
                        RAISE EXCEPTION 'contract projection must exactly reflect its latest event';
                    END IF;
                ELSIF NEW.contract_status <> 'none' THEN
                    RAISE EXCEPTION 'a non-default contract projection requires a governing event';
                END IF;

                IF NEW.incident_event_id IS NOT NULL THEN
                    SELECT * INTO incident FROM integration.source_incident_events
                    WHERE source_incident_event_id = NEW.incident_event_id;
                    IF incident.source_id IS DISTINCT FROM NEW.source_id
                       OR incident.incident_status IS DISTINCT FROM NEW.incident_status THEN
                        RAISE EXCEPTION 'incident projection must exactly reflect its latest event';
                    END IF;
                ELSIF NEW.incident_status <> 'none' THEN
                    RAISE EXCEPTION 'a non-default incident projection requires a governing event';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER source_conformance_events_scope
                BEFORE INSERT ON integration.source_conformance_events
                FOR EACH ROW EXECUTE FUNCTION integration.enforce_source_facet_event_contiguity('source_conformance_event_id');
            CREATE TRIGGER source_conformance_events_append_only
                BEFORE UPDATE OR DELETE ON integration.source_conformance_events
                FOR EACH ROW EXECUTE FUNCTION integration.reject_source_status_facet_mutation();
            CREATE TRIGGER source_conformance_events_clinical_content_guard
                BEFORE INSERT ON integration.source_conformance_events
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();

            CREATE TRIGGER source_contract_events_scope
                BEFORE INSERT ON integration.source_contract_events
                FOR EACH ROW EXECUTE FUNCTION integration.enforce_source_facet_event_contiguity('source_contract_event_id');
            CREATE TRIGGER source_contract_events_evidence_scope
                BEFORE INSERT ON integration.source_contract_events
                FOR EACH ROW EXECUTE FUNCTION integration.enforce_source_contract_evidence_scope();
            CREATE TRIGGER source_contract_events_append_only
                BEFORE UPDATE OR DELETE ON integration.source_contract_events
                FOR EACH ROW EXECUTE FUNCTION integration.reject_source_status_facet_mutation();
            CREATE TRIGGER source_contract_events_clinical_content_guard
                BEFORE INSERT ON integration.source_contract_events
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();

            CREATE TRIGGER source_incident_events_scope
                BEFORE INSERT ON integration.source_incident_events
                FOR EACH ROW EXECUTE FUNCTION integration.enforce_source_facet_event_contiguity('source_incident_event_id');
            CREATE TRIGGER source_incident_events_breach_scope
                BEFORE INSERT ON integration.source_incident_events
                FOR EACH ROW EXECUTE FUNCTION integration.enforce_source_incident_breach_scope();
            CREATE TRIGGER source_incident_events_append_only
                BEFORE UPDATE OR DELETE ON integration.source_incident_events
                FOR EACH ROW EXECUTE FUNCTION integration.reject_source_status_facet_mutation();
            CREATE TRIGGER source_incident_events_clinical_content_guard
                BEFORE INSERT ON integration.source_incident_events
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();

            CREATE TRIGGER source_status_facets_projection
                BEFORE INSERT OR UPDATE OR DELETE ON integration.source_status_facets
                FOR EACH ROW EXECUTE FUNCTION integration.enforce_source_status_facet_projection();
            CREATE TRIGGER source_status_facets_clinical_content_guard
                BEFORE INSERT OR UPDATE ON integration.source_status_facets
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();

            CREATE TRIGGER source_peer_pin_policies_route_scope
                BEFORE INSERT OR UPDATE ON integration.source_peer_pin_policies
                FOR EACH ROW EXECUTE FUNCTION integration.enforce_source_peer_pin_route_scope();
            CREATE TRIGGER source_peer_pin_policies_clinical_content_guard
                BEFORE INSERT OR UPDATE ON integration.source_peer_pin_policies
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();
        SQL);

        $this->backfillFacets();
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS integration.source_peer_pin_policies;
            DROP TABLE IF EXISTS integration.source_status_facets;
            DROP TABLE IF EXISTS integration.source_incident_events;
            DROP TABLE IF EXISTS integration.source_contract_events;
            DROP TABLE IF EXISTS integration.source_conformance_events;
            DROP TABLE IF EXISTS integration.credential_rotation_alert_states;
            DROP FUNCTION IF EXISTS integration.reject_credential_rotation_alert_mutation();
            DROP FUNCTION IF EXISTS integration.enforce_source_status_facet_projection();
            DROP FUNCTION IF EXISTS integration.enforce_source_peer_pin_route_scope();
            DROP FUNCTION IF EXISTS integration.enforce_source_contract_evidence_scope();
            DROP FUNCTION IF EXISTS integration.enforce_source_incident_breach_scope();
            DROP FUNCTION IF EXISTS integration.enforce_source_facet_event_contiguity();
            DROP FUNCTION IF EXISTS integration.reject_source_status_facet_mutation();
            ALTER TABLE integration.operational_alert_deliveries
                DROP CONSTRAINT IF EXISTS operational_alert_delivery_subject_chk;
            ALTER TABLE integration.operational_alert_deliveries
                ADD CONSTRAINT operational_alert_delivery_subject_chk
                CHECK (subject_type IN ('slo_breach', 'system_health_component'));
        SQL);
    }

    /**
     * Backfill sensibly: conformance = not_started, contract = none unless a
     * verified BAA/DUA/support evidence row exists, incident = none. Every facet
     * projection starts from a governing initial event so the projection trigger
     * is satisfied and downstream separation is real from day one.
     */
    private function backfillFacets(): void
    {
        $now = now();
        DB::table('integration.sources')->orderBy('source_id')->get()->each(function (object $source) use ($now): void {
            $sourceId = (int) $source->source_id;

            // Conformance: derive an initial state from the onboarding profile's
            // declared conformance_status when present; otherwise not_started.
            $onboarding = DB::table('integration.source_onboarding_versions')
                ->where('source_id', $sourceId)
                ->orderByDesc('version_number')
                ->first();
            // The onboarding profile uses its own conformance vocabulary
            // (not_tested/planned/testing/passed/failed/expired); map it onto the
            // facet vocabulary (not_started/in_progress/passed/failed/waived).
            $conformanceStatus = match ((string) ($onboarding->conformance_status ?? 'not_tested')) {
                'passed' => 'passed',
                'failed', 'expired' => 'failed',
                'planned', 'testing' => 'in_progress',
                default => 'not_started',
            };
            $conformanceEventId = (int) DB::table('integration.source_conformance_events')->insertGetId([
                'event_uuid' => (string) Str::uuid7(),
                'source_id' => $sourceId,
                'previous_event_id' => null,
                'conformance_status' => $conformanceStatus,
                'profile_key' => null,
                'profile_version' => null,
                'evidence_reference_sha256' => null,
                'reason' => 'Backfilled the conformance facet from the source onboarding authority.',
                'actor_user_id' => null,
                'occurred_at' => $source->updated_at ?? $source->created_at ?? $now,
                'created_at' => $now,
            ], 'source_conformance_event_id');

            // Contract: active only if a verified contract/BAA/DUA/support evidence
            // pointer exists; otherwise none. Evidence content is never read here.
            $contractEvidence = DB::table('integration.source_evidence_records')
                ->where('source_id', $sourceId)
                ->whereIn('evidence_type', ['contract', 'baa', 'dua'])
                ->where('evidence_status', 'verified')
                ->orderByDesc('source_evidence_record_id')
                ->first();
            $contractStatus = $contractEvidence !== null ? 'active' : 'none';
            $contractEventId = (int) DB::table('integration.source_contract_events')->insertGetId([
                'event_uuid' => (string) Str::uuid7(),
                'source_id' => $sourceId,
                'previous_event_id' => null,
                'contract_status' => $contractStatus,
                'source_evidence_record_id' => $contractEvidence?->source_evidence_record_id,
                'evidence_reference_sha256' => $contractEvidence?->reference_sha256,
                'expires_at' => $contractEvidence?->expires_at,
                'reason' => 'Backfilled the contract facet from existing legal evidence pointers.',
                'actor_user_id' => null,
                'occurred_at' => $source->updated_at ?? $source->created_at ?? $now,
                'created_at' => $now,
            ], 'source_contract_event_id');

            // Incident: none for every existing source.
            $incidentEventId = (int) DB::table('integration.source_incident_events')->insertGetId([
                'event_uuid' => (string) Str::uuid7(),
                'source_id' => $sourceId,
                'previous_event_id' => null,
                'incident_status' => 'none',
                'incident_reference_hash' => null,
                'slo_breach_id' => null,
                'reason' => 'Backfilled the incident facet in the cleared state.',
                'actor_user_id' => null,
                'occurred_at' => $source->updated_at ?? $source->created_at ?? $now,
                'created_at' => $now,
            ], 'source_incident_event_id');

            DB::table('integration.source_status_facets')->insert([
                'source_id' => $sourceId,
                'conformance_status' => $conformanceStatus,
                'conformance_event_id' => $conformanceEventId,
                'conformance_profile_key' => null,
                'conformance_profile_version' => null,
                'contract_status' => $contractStatus,
                'contract_event_id' => $contractEventId,
                'contract_expires_at' => $contractEvidence?->expires_at,
                'incident_status' => 'none',
                'incident_event_id' => $incidentEventId,
                'updated_at' => $now,
            ]);
        });
    }
};
