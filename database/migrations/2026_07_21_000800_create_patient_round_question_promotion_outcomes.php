<?php

/**
 * A promoted patient question can receive exactly one patient-safe outcome
 * after its staff Virtual Rounds question reaches a terminal resolution. The
 * outcome fact deliberately contains no question text, staff response, or
 * rounds deliberation; the patient-visible wording lives only in the existing
 * encrypted patient-message ledger.
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
CREATE TABLE IF NOT EXISTS patient_communications.round_question_promotion_outcomes (
    round_question_promotion_outcome_id bigserial PRIMARY KEY,
    outcome_uuid                       uuid NOT NULL UNIQUE,
    round_question_promotion_id        bigint NOT NULL UNIQUE
                                       REFERENCES patient_communications.round_question_promotions(round_question_promotion_id)
                                       ON DELETE RESTRICT,
    patient_status_message_id          bigint NOT NULL UNIQUE
                                       REFERENCES patient_experience.messages(message_id)
                                       ON DELETE RESTRICT,
    resolved_by_user_id                bigint NOT NULL REFERENCES prod.users(id) ON DELETE RESTRICT,
    resolved_status                    varchar(32) NOT NULL
                                       CHECK (resolved_status IN ('answered', 'dismissed')),
    outcome_policy_version             varchar(120) NOT NULL,
    resolved_at                        timestamptz NOT NULL,
    created_at                         timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_round_question_promotion_outcomes_policy_check
        CHECK (btrim(outcome_policy_version) <> '')
);

CREATE INDEX IF NOT EXISTS idx_patient_round_question_promotion_outcomes_resolved_at
    ON patient_communications.round_question_promotion_outcomes(resolved_at DESC);

COMMENT ON TABLE patient_communications.round_question_promotion_outcomes IS
    'Content-free, one-to-one outcome fact linking a promoted patient question to one patient-safe encrypted status after terminal staff review.';
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared('DROP TABLE IF EXISTS patient_communications.round_question_promotion_outcomes;');
    }
};
