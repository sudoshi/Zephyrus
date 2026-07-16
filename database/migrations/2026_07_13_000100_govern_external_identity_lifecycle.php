<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE prod.user_external_identities
                ADD COLUMN IF NOT EXISTS is_active boolean NOT NULL DEFAULT true,
                ADD COLUMN IF NOT EXISTS unlinked_at timestamptz,
                ADD COLUMN IF NOT EXISTS unlinked_by_user_id bigint,
                ADD COLUMN IF NOT EXISTS unlink_reason text,
                ADD COLUMN IF NOT EXISTS relinked_at timestamptz,
                ADD COLUMN IF NOT EXISTS relinked_by_user_id bigint,
                ADD COLUMN IF NOT EXISTS relink_reason text;

            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'user_external_identities_unlinked_by_fk'
                ) THEN
                    ALTER TABLE prod.user_external_identities
                        ADD CONSTRAINT user_external_identities_unlinked_by_fk
                        FOREIGN KEY (unlinked_by_user_id) REFERENCES prod.users(id) ON DELETE RESTRICT;
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'user_external_identities_relinked_by_fk'
                ) THEN
                    ALTER TABLE prod.user_external_identities
                        ADD CONSTRAINT user_external_identities_relinked_by_fk
                        FOREIGN KEY (relinked_by_user_id) REFERENCES prod.users(id) ON DELETE RESTRICT;
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'user_external_identities_unlink_reason_chk'
                ) THEN
                    ALTER TABLE prod.user_external_identities
                        ADD CONSTRAINT user_external_identities_unlink_reason_chk
                        CHECK (unlink_reason IS NULL OR length(unlink_reason) BETWEEN 10 AND 500);
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'user_external_identities_relink_reason_chk'
                ) THEN
                    ALTER TABLE prod.user_external_identities
                        ADD CONSTRAINT user_external_identities_relink_reason_chk
                        CHECK (relink_reason IS NULL OR length(relink_reason) BETWEEN 10 AND 500);
                END IF;
            END;
            $$;

            CREATE INDEX IF NOT EXISTS user_external_identities_user_active_idx
                ON prod.user_external_identities (user_id, is_active, provider);

            CREATE TABLE IF NOT EXISTS governance.identity_link_events (
                identity_link_event_uuid uuid PRIMARY KEY,
                external_identity_id bigint NOT NULL REFERENCES prod.user_external_identities(id) ON DELETE RESTRICT,
                subject_user_id bigint NOT NULL REFERENCES prod.users(id) ON DELETE RESTRICT,
                event_type text NOT NULL CHECK (event_type IN ('linked', 'unlinked', 'relinked')),
                provider text NOT NULL,
                provider_subject_sha256 char(64) NOT NULL,
                provider_email_sha256 char(64),
                actor_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                reason text NOT NULL,
                occurred_at timestamptz NOT NULL DEFAULT now(),
                metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
                CONSTRAINT identity_link_events_reason_chk CHECK (length(reason) BETWEEN 10 AND 500),
                CONSTRAINT identity_link_events_subject_hash_chk CHECK (provider_subject_sha256 ~ '^[0-9a-f]{64}$'),
                CONSTRAINT identity_link_events_email_hash_chk CHECK (
                    provider_email_sha256 IS NULL OR provider_email_sha256 ~ '^[0-9a-f]{64}$'
                )
            );

            CREATE INDEX IF NOT EXISTS identity_link_events_identity_idx
                ON governance.identity_link_events (external_identity_id, occurred_at DESC);
            CREATE INDEX IF NOT EXISTS identity_link_events_subject_idx
                ON governance.identity_link_events (subject_user_id, occurred_at DESC);

            DROP TRIGGER IF EXISTS identity_link_events_append_only ON governance.identity_link_events;
            CREATE TRIGGER identity_link_events_append_only
                BEFORE UPDATE OR DELETE ON governance.identity_link_events
                FOR EACH ROW EXECUTE FUNCTION governance.reject_change_ledger_mutation();

            ALTER TABLE prod.users
                ADD COLUMN IF NOT EXISTS identity_purged_at timestamptz,
                ADD COLUMN IF NOT EXISTS identity_purge_request_uuid uuid;

            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'users_identity_purge_request_fk'
                ) THEN
                    ALTER TABLE prod.users
                        ADD CONSTRAINT users_identity_purge_request_fk
                        FOREIGN KEY (identity_purge_request_uuid)
                        REFERENCES governance.change_requests(change_request_uuid) ON DELETE RESTRICT;
                END IF;
            END;
            $$;

            DO $$
            DECLARE constraint_row record;
            BEGIN
                FOR constraint_row IN
                    SELECT conname
                    FROM pg_constraint
                    WHERE conrelid = 'governance.change_requests'::regclass
                      AND contype = 'c'
                      AND pg_get_constraintdef(oid) LIKE '%activate_production_source%'
                LOOP
                    EXECUTE format(
                        'ALTER TABLE governance.change_requests DROP CONSTRAINT %I',
                        constraint_row.conname
                    );
                END LOOP;

                ALTER TABLE governance.change_requests
                    ADD CONSTRAINT change_requests_action_type_chk CHECK (action_type IN (
                        'activate_production_source',
                        'rotate_integration_credential',
                        'execute_destructive_replay',
                        'change_outbound_dispatch_policy',
                        'purge_user_identity'
                    ));
            END;
            $$;
        SQL);

        // Establish the immutable baseline for links that predate this
        // lifecycle ledger. Only salted-by-provider SHA-256 fingerprints are
        // copied; raw provider subjects and emails remain in the operational
        // identity table and never enter the governance event ledger.
        DB::table('prod.user_external_identities as identity')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('governance.identity_link_events as event')
                    ->whereColumn('event.external_identity_id', 'identity.id');
            })
            ->orderBy('identity.id')
            ->select([
                'identity.id',
                'identity.user_id',
                'identity.provider',
                'identity.provider_subject',
                'identity.provider_email_at_link',
                'identity.linked_at',
            ])
            ->chunkById(500, function ($identities): void {
                $events = $identities->map(fn ($identity): array => [
                    'identity_link_event_uuid' => (string) Str::uuid7(),
                    'external_identity_id' => $identity->id,
                    'subject_user_id' => $identity->user_id,
                    'event_type' => 'linked',
                    'provider' => $identity->provider,
                    'provider_subject_sha256' => hash(
                        'sha256',
                        $identity->provider.':'.$identity->provider_subject,
                    ),
                    'provider_email_sha256' => filled($identity->provider_email_at_link)
                        ? hash('sha256', strtolower((string) $identity->provider_email_at_link))
                        : null,
                    'actor_user_id' => null,
                    'reason' => 'Historical external identity link baseline migrated.',
                    'occurred_at' => $identity->linked_at ?? now(),
                    'metadata' => json_encode(['source' => 'migration_baseline'], JSON_THROW_ON_ERROR),
                ])->all();

                if ($events !== []) {
                    DB::table('governance.identity_link_events')->insert($events);
                }
            }, 'identity.id', 'id');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE prod.users
                DROP CONSTRAINT IF EXISTS users_identity_purge_request_fk,
                DROP COLUMN IF EXISTS identity_purge_request_uuid,
                DROP COLUMN IF EXISTS identity_purged_at;

            DROP TABLE IF EXISTS governance.identity_link_events;

            ALTER TABLE prod.user_external_identities
                DROP CONSTRAINT IF EXISTS user_external_identities_unlinked_by_fk,
                DROP CONSTRAINT IF EXISTS user_external_identities_relinked_by_fk,
                DROP CONSTRAINT IF EXISTS user_external_identities_unlink_reason_chk,
                DROP CONSTRAINT IF EXISTS user_external_identities_relink_reason_chk,
                DROP COLUMN IF EXISTS relink_reason,
                DROP COLUMN IF EXISTS relinked_by_user_id,
                DROP COLUMN IF EXISTS relinked_at,
                DROP COLUMN IF EXISTS unlink_reason,
                DROP COLUMN IF EXISTS unlinked_by_user_id,
                DROP COLUMN IF EXISTS unlinked_at,
                DROP COLUMN IF EXISTS is_active;

            ALTER TABLE governance.change_requests
                DROP CONSTRAINT IF EXISTS change_requests_action_type_chk;
            ALTER TABLE governance.change_requests
                ADD CONSTRAINT change_requests_action_type_chk CHECK (action_type IN (
                    'activate_production_source',
                    'rotate_integration_credential',
                    'execute_destructive_replay',
                    'change_outbound_dispatch_policy'
                ));
        SQL);
    }
};
