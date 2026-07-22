<?php

/**
 * Patient-authored personal goals remain distinct from clinical care plans.
 * The encrypted message ledger holds the text; this table holds only a
 * content-free, append-only association and never writes to a clinical source.
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
CREATE TABLE IF NOT EXISTS patient_experience.patient_authored_goals (
    patient_authored_goal_id bigint PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
    goal_uuid                uuid NOT NULL UNIQUE,
    principal_id             bigint NOT NULL
                             REFERENCES patient_experience.principals(principal_id)
                             ON DELETE RESTRICT,
    access_grant_id          bigint NOT NULL
                             REFERENCES patient_experience.encounter_access_grants(access_grant_id)
                             ON DELETE RESTRICT,
    message_thread_id        bigint NOT NULL UNIQUE
                             REFERENCES patient_experience.message_threads(message_thread_id)
                             ON DELETE RESTRICT,
    source_message_id        bigint NOT NULL UNIQUE
                             REFERENCES patient_experience.messages(message_id)
                             ON DELETE RESTRICT,
    policy_version           varchar(120) NOT NULL,
    idempotency_key_digest   varchar(128) NOT NULL UNIQUE,
    request_payload_digest   varchar(128) NOT NULL,
    submitted_at             timestamptz NOT NULL,
    recorded_at              timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_authored_goals_policy_not_blank_check
        CHECK (btrim(policy_version) <> ''),
    CONSTRAINT patient_authored_goals_digest_not_blank_check
        CHECK (btrim(idempotency_key_digest) <> '' AND btrim(request_payload_digest) <> '')
);

CREATE INDEX IF NOT EXISTS idx_patient_authored_goals_grant_time
    ON patient_experience.patient_authored_goals(access_grant_id, submitted_at DESC);

COMMENT ON TABLE patient_experience.patient_authored_goals IS
    'Append-only, content-free association for a patient-authored personal goal held in the encrypted message ledger. It is not a clinical goal, care plan, order, consent, or assessment.';

DROP TRIGGER IF EXISTS patient_authored_goals_append_only
    ON patient_experience.patient_authored_goals;
CREATE TRIGGER patient_authored_goals_append_only
BEFORE UPDATE OR DELETE ON patient_experience.patient_authored_goals
FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_append_only_mutation();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared('DROP TABLE IF EXISTS patient_experience.patient_authored_goals;');
    }
};
