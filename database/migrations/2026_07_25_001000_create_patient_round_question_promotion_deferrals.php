<?php

/**
 * A promoted question may be explicitly deferred once while it remains open
 * for later care-team review. The fact is content-free and immutable; the
 * separately encrypted patient status message contains the only patient copy.
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
CREATE TABLE IF NOT EXISTS patient_communications.round_question_promotion_deferrals (
    round_question_promotion_deferral_id bigserial PRIMARY KEY,
    deferral_uuid                        uuid NOT NULL UNIQUE,
    round_question_promotion_id          bigint NOT NULL UNIQUE
                                         REFERENCES patient_communications.round_question_promotions(round_question_promotion_id)
                                         ON DELETE RESTRICT,
    patient_status_message_id            bigint NOT NULL UNIQUE
                                         REFERENCES patient_experience.messages(message_id)
                                         ON DELETE RESTRICT,
    deferred_by_user_id                  bigint NOT NULL REFERENCES prod.users(id) ON DELETE RESTRICT,
    deferral_policy_version              varchar(120) NOT NULL,
    idempotency_key_digest               varchar(128) NOT NULL UNIQUE,
    request_payload_digest               varchar(128) NOT NULL,
    deferred_at                          timestamptz NOT NULL,
    created_at                           timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_round_question_promotion_deferrals_policy_check
        CHECK (btrim(deferral_policy_version) <> ''),
    CONSTRAINT patient_round_question_promotion_deferrals_digest_check
        CHECK (btrim(idempotency_key_digest) <> '' AND btrim(request_payload_digest) <> '')
);

CREATE INDEX IF NOT EXISTS idx_patient_round_question_promotion_deferrals_deferred_at
    ON patient_communications.round_question_promotion_deferrals(deferred_at DESC);

COMMENT ON TABLE patient_communications.round_question_promotion_deferrals IS
    'Content-free, one-to-one deferral fact for a promoted patient question; patient-visible wording is retained only in the encrypted patient message ledger.';
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared('DROP TABLE IF EXISTS patient_communications.round_question_promotion_deferrals;');
    }
};
