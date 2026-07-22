<?php

/**
 * A care preference is a patient-authored, nonclinical communication. This
 * table stores no preference text or clinical interpretation; it only links
 * the encrypted message ledger to the patient/grant that owns the request.
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
CREATE TABLE IF NOT EXISTS patient_experience.care_preferences (
    care_preference_id      bigserial PRIMARY KEY,
    preference_uuid         uuid NOT NULL UNIQUE,
    principal_id            bigint NOT NULL
                            REFERENCES patient_experience.principals(principal_id)
                            ON DELETE RESTRICT,
    access_grant_id         bigint NOT NULL
                            REFERENCES patient_experience.encounter_access_grants(access_grant_id)
                            ON DELETE RESTRICT,
    message_thread_id       bigint NOT NULL UNIQUE
                            REFERENCES patient_experience.message_threads(message_thread_id)
                            ON DELETE RESTRICT,
    source_message_id       bigint NOT NULL UNIQUE
                            REFERENCES patient_experience.messages(message_id)
                            ON DELETE RESTRICT,
    policy_version          varchar(120) NOT NULL,
    idempotency_key_digest  varchar(128) NOT NULL UNIQUE,
    request_payload_digest  varchar(128) NOT NULL,
    submitted_at            timestamptz NOT NULL,
    recorded_at             timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_care_preferences_policy_not_blank_check
        CHECK (btrim(policy_version) <> ''),
    CONSTRAINT patient_care_preferences_digest_not_blank_check
        CHECK (btrim(idempotency_key_digest) <> '' AND btrim(request_payload_digest) <> '')
);

CREATE INDEX IF NOT EXISTS idx_patient_care_preferences_grant_time
    ON patient_experience.care_preferences(access_grant_id, submitted_at DESC);

COMMENT ON TABLE patient_experience.care_preferences IS
    'Append-only, content-free association for a patient-authored preference held in the encrypted message ledger. It is not a clinical care-plan, order, consent, or assessment record.';

DROP TRIGGER IF EXISTS patient_care_preferences_append_only
    ON patient_experience.care_preferences;
CREATE TRIGGER patient_care_preferences_append_only
BEFORE UPDATE OR DELETE ON patient_experience.care_preferences
FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_append_only_mutation();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared('DROP TABLE IF EXISTS patient_experience.care_preferences;');
    }
};
