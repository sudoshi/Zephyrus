<?php

/**
 * Released patient projections need a durable, content-free publication fact.
 *
 * The database trigger keeps a projection insert and its outbox event in the
 * same transaction, including for future approved source adapters. This does
 * not send a push notification or expose a projection; delivery remains a
 * separately governed worker responsibility.
 */

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS uq_patient_projection_release_outbox
    ON patient_experience.notification_outbox(aggregate_uuid, event_type)
    WHERE destination = 'projection'
      AND event_type = 'patient.projection.released';

CREATE OR REPLACE FUNCTION patient_experience.enqueue_released_projection_outbox()
RETURNS trigger AS $$
DECLARE
    grant_principal_id bigint;
    deterministic_outbox_uuid uuid;
    operation_digest varchar(128);
BEGIN
    IF NEW.release_state <> 'released' OR NEW.released_at IS NULL THEN
        RETURN NEW;
    END IF;

    SELECT principal_id
      INTO grant_principal_id
      FROM patient_experience.encounter_access_grants
     WHERE access_grant_id = NEW.access_grant_id;

    IF grant_principal_id IS NULL THEN
        RAISE EXCEPTION 'released patient projection is missing its access-grant principal';
    END IF;

    -- Both values are deterministic from an already stored projection UUID.
    -- No source identifier, patient content, encryption material, or routing
    -- decision is copied into this content-free publication ledger.
    operation_digest := md5('patient.projection.release.outbox:' || NEW.projection_uuid::text);
    deterministic_outbox_uuid := (
        substr(operation_digest, 1, 8) || '-' ||
        substr(operation_digest, 9, 4) || '-' ||
        substr(operation_digest, 13, 4) || '-' ||
        substr(operation_digest, 17, 4) || '-' ||
        substr(operation_digest, 21, 12)
    )::uuid;

    INSERT INTO patient_experience.notification_outbox (
        outbox_uuid,
        principal_id,
        access_grant_id,
        aggregate_type,
        aggregate_uuid,
        event_type,
        destination,
        encrypted_payload,
        encryption_key_version,
        payload_digest,
        routing_metadata,
        idempotency_key_digest,
        available_at,
        occurred_at
    ) VALUES (
        deterministic_outbox_uuid,
        grant_principal_id,
        NEW.access_grant_id,
        'patient_encounter_projection',
        NEW.projection_uuid,
        'patient.projection.released',
        'projection',
        NULL,
        NULL,
        NULL,
        jsonb_build_object(
            'schema_version', 1,
            'content_included', false,
            'projection_kind', NEW.projection_kind
        ),
        operation_digest,
        NEW.released_at,
        NEW.released_at
    ) ON CONFLICT DO NOTHING;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS patient_released_projection_outbox
    ON patient_experience.encounter_projections;
CREATE TRIGGER patient_released_projection_outbox
AFTER INSERT ON patient_experience.encounter_projections
FOR EACH ROW EXECUTE FUNCTION patient_experience.enqueue_released_projection_outbox();

COMMENT ON FUNCTION patient_experience.enqueue_released_projection_outbox() IS
    'Atomically appends exactly one content-free projection-publication outbox fact for every released patient projection.';
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS patient_released_projection_outbox
    ON patient_experience.encounter_projections;
DROP INDEX IF EXISTS patient_experience.uq_patient_projection_release_outbox;
SQL);
    }
};
