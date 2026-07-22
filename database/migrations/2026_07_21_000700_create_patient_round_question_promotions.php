<?php

/**
 * One explicit staff promotion may link a patient-authored, encrypted message
 * thread to a staff Virtual Rounds question. Content stays in the existing
 * patient message ledger; this bridge stores only relational/audit facts.
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
CREATE TABLE IF NOT EXISTS patient_communications.round_question_promotions (
    round_question_promotion_id  bigserial PRIMARY KEY,
    promotion_uuid               uuid NOT NULL UNIQUE,
    message_thread_id            bigint NOT NULL UNIQUE
                                 REFERENCES patient_experience.message_threads(message_thread_id)
                                 ON DELETE RESTRICT,
    source_message_id            bigint NOT NULL
                                 REFERENCES patient_experience.messages(message_id)
                                 ON DELETE RESTRICT,
    patient_status_message_id    bigint NOT NULL UNIQUE
                                 REFERENCES patient_experience.messages(message_id)
                                 ON DELETE RESTRICT,
    round_patient_id             bigint NOT NULL
                                 REFERENCES rounds.patients(round_patient_id)
                                 ON DELETE RESTRICT,
    round_question_id            bigint NOT NULL UNIQUE
                                 REFERENCES rounds.questions(question_id)
                                 ON DELETE RESTRICT,
    promoted_by_user_id          bigint NOT NULL REFERENCES prod.users(id) ON DELETE RESTRICT,
    promotion_policy_version     varchar(120) NOT NULL,
    idempotency_key_digest       varchar(128) NOT NULL UNIQUE,
    request_payload_digest       varchar(128) NOT NULL,
    promoted_at                  timestamptz NOT NULL,
    created_at                   timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_round_question_promotions_policy_check
        CHECK (btrim(promotion_policy_version) <> ''),
    CONSTRAINT patient_round_question_promotions_digest_check
        CHECK (btrim(idempotency_key_digest) <> '' AND btrim(request_payload_digest) <> '')
);

CREATE INDEX IF NOT EXISTS idx_patient_round_question_promotions_round_patient
    ON patient_communications.round_question_promotions(round_patient_id, promoted_at DESC);

COMMENT ON TABLE patient_communications.round_question_promotions IS
    'Content-free, one-to-one staff promotion fact linking an approved patient message thread, its patient-safe system status, and a Virtual Rounds question.';
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared('DROP TABLE IF EXISTS patient_communications.round_question_promotions;');
    }
};
