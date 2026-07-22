<?php

/**
 * A patient may ask for clarification only about education that was already
 * released in their pathway. This table stores no question text, completion,
 * comprehension, consent, clinical assessment, or staff response: it is a
 * content-free immutable link to the encrypted message ledger and release.
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
CREATE TABLE IF NOT EXISTS patient_experience.education_clarification_requests (
    education_clarification_request_id bigserial PRIMARY KEY,
    clarification_uuid                  uuid NOT NULL UNIQUE,
    principal_id                        bigint NOT NULL
                                        REFERENCES patient_experience.principals(principal_id)
                                        ON DELETE RESTRICT,
    access_grant_id                     bigint NOT NULL
                                        REFERENCES patient_experience.encounter_access_grants(access_grant_id)
                                        ON DELETE RESTRICT,
    pathway_projection_id               bigint NOT NULL
                                        REFERENCES patient_experience.encounter_projections(encounter_projection_id)
                                        ON DELETE RESTRICT,
    education_item_uuid                 uuid NOT NULL,
    message_thread_id                   bigint NOT NULL UNIQUE
                                        REFERENCES patient_experience.message_threads(message_thread_id)
                                        ON DELETE RESTRICT,
    source_message_id                   bigint NOT NULL UNIQUE
                                        REFERENCES patient_experience.messages(message_id)
                                        ON DELETE RESTRICT,
    policy_version                      varchar(120) NOT NULL,
    idempotency_key_digest              varchar(128) NOT NULL UNIQUE,
    request_payload_digest              varchar(128) NOT NULL,
    requested_at                        timestamptz NOT NULL,
    recorded_at                         timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_education_clarification_policy_not_blank_check
        CHECK (btrim(policy_version) <> ''),
    CONSTRAINT patient_education_clarification_digest_not_blank_check
        CHECK (btrim(idempotency_key_digest) <> '' AND btrim(request_payload_digest) <> '')
);

CREATE INDEX IF NOT EXISTS idx_patient_education_clarification_grant_time
    ON patient_experience.education_clarification_requests(access_grant_id, requested_at DESC);

COMMENT ON TABLE patient_experience.education_clarification_requests IS
    'Append-only, content-free association between a released pathway education item and an encrypted patient clarification message. It is not a comprehension, completion, consent, or clinical-assessment record.';

DROP TRIGGER IF EXISTS patient_education_clarification_requests_append_only
    ON patient_experience.education_clarification_requests;
CREATE TRIGGER patient_education_clarification_requests_append_only
BEFORE UPDATE OR DELETE ON patient_experience.education_clarification_requests
FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_append_only_mutation();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared('DROP TABLE IF EXISTS patient_experience.education_clarification_requests;');
    }
};
