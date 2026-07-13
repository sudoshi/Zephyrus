<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS governance');

        Schema::create('governance.access_review_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->uuid('campaign_uuid')->unique();
            $table->string('title', 160);
            $table->date('review_period_start');
            $table->date('review_period_end');
            $table->timestampTz('due_at');
            $table->string('status', 24)->default('open');
            $table->unsignedBigInteger('primary_reviewer_user_id');
            $table->unsignedBigInteger('alternate_reviewer_user_id');
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestampTz('snapshot_at');
            $table->char('snapshot_sha256', 64)->nullable();
            $table->char('evidence_sha256', 64)->nullable();
            $table->timestampTz('opened_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestampsTz();

            $table->foreign('primary_reviewer_user_id')->references('id')->on('prod.users')->restrictOnDelete();
            $table->foreign('alternate_reviewer_user_id')->references('id')->on('prod.users')->restrictOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('prod.users')->restrictOnDelete();
            $table->foreign('cancelled_by_user_id')->references('id')->on('prod.users')->restrictOnDelete();
            $table->index(['status', 'due_at'], 'access_review_campaign_status_due_idx');
        });

        Schema::create('governance.access_review_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('item_uuid')->unique();
            $table->foreignId('campaign_id')->constrained('governance.access_review_campaigns')->restrictOnDelete();
            $table->unsignedBigInteger('subject_user_id');
            $table->unsignedBigInteger('reviewer_user_id');
            $table->jsonb('entitlement_snapshot');
            $table->char('snapshot_sha256', 64);
            $table->jsonb('risk_flags')->default(DB::raw("'[]'::jsonb"));
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('subject_user_id')->references('id')->on('prod.users')->restrictOnDelete();
            $table->foreign('reviewer_user_id')->references('id')->on('prod.users')->restrictOnDelete();
            $table->unique(['campaign_id', 'subject_user_id'], 'access_review_item_subject_unique');
            $table->index(['campaign_id', 'reviewer_user_id'], 'access_review_item_reviewer_idx');
        });

        Schema::create('governance.access_review_decisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('decision_uuid')->unique();
            $table->foreignId('campaign_item_id')->unique()->constrained('governance.access_review_items')->restrictOnDelete();
            $table->string('decision', 16);
            $table->string('reason_code', 80);
            $table->string('rationale', 1000);
            $table->unsignedBigInteger('decided_by_user_id');
            $table->char('reviewed_snapshot_sha256', 64);
            $table->timestampTz('decided_at');

            $table->foreign('decided_by_user_id')->references('id')->on('prod.users')->restrictOnDelete();
        });

        Schema::create('governance.access_review_remediations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('remediation_uuid')->unique();
            $table->foreignId('decision_id')->unique()->constrained('governance.access_review_decisions')->restrictOnDelete();
            $table->unsignedBigInteger('executed_by_user_id');
            $table->jsonb('result');
            $table->timestampTz('executed_at');

            $table->foreign('executed_by_user_id')->references('id')->on('prod.users')->restrictOnDelete();
        });

        Schema::create('governance.access_review_exports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('export_uuid')->unique();
            $table->foreignId('campaign_id')->constrained('governance.access_review_campaigns')->restrictOnDelete();
            $table->string('format', 8);
            $table->char('content_sha256', 64);
            $table->unsignedBigInteger('exported_by_user_id');
            $table->timestampTz('exported_at');

            $table->foreign('exported_by_user_id')->references('id')->on('prod.users')->restrictOnDelete();
            $table->index(['campaign_id', 'exported_at'], 'access_review_export_campaign_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE governance.access_review_campaigns
                ADD CONSTRAINT access_review_campaign_period_chk
                    CHECK (review_period_end >= review_period_start),
                ADD CONSTRAINT access_review_campaign_status_chk
                    CHECK (status IN ('open', 'completed', 'cancelled')),
                ADD CONSTRAINT access_review_campaign_reviewers_chk
                    CHECK (primary_reviewer_user_id <> alternate_reviewer_user_id),
                ADD CONSTRAINT access_review_campaign_snapshot_hash_chk
                    CHECK (snapshot_sha256 IS NULL OR snapshot_sha256 ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT access_review_campaign_evidence_hash_chk
                    CHECK (evidence_sha256 IS NULL OR evidence_sha256 ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT access_review_campaign_closure_chk
                    CHECK (
                        (status = 'open' AND evidence_sha256 IS NULL AND completed_at IS NULL AND cancelled_at IS NULL AND cancelled_by_user_id IS NULL AND cancellation_reason IS NULL)
                        OR (status = 'completed' AND snapshot_sha256 IS NOT NULL AND evidence_sha256 IS NOT NULL AND completed_at IS NOT NULL AND cancelled_at IS NULL AND cancelled_by_user_id IS NULL AND cancellation_reason IS NULL)
                        OR (status = 'cancelled' AND evidence_sha256 IS NULL AND completed_at IS NULL AND cancelled_at IS NOT NULL AND cancelled_by_user_id IS NOT NULL AND cancellation_reason IS NOT NULL)
                    )
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX access_review_campaign_active_period_unique
            ON governance.access_review_campaigns (review_period_start, review_period_end)
            WHERE status <> 'cancelled'
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE governance.access_review_items
                ADD CONSTRAINT access_review_item_reviewer_chk
                    CHECK (subject_user_id <> reviewer_user_id),
                ADD CONSTRAINT access_review_item_snapshot_hash_chk
                    CHECK (snapshot_sha256 ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT access_review_item_snapshot_object_chk
                    CHECK (jsonb_typeof(entitlement_snapshot) = 'object'),
                ADD CONSTRAINT access_review_item_risk_flags_array_chk
                    CHECK (jsonb_typeof(risk_flags) = 'array')
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE governance.access_review_decisions
                ADD CONSTRAINT access_review_decision_value_chk
                    CHECK (decision IN ('retain', 'revoke')),
                ADD CONSTRAINT access_review_decision_snapshot_hash_chk
                    CHECK (reviewed_snapshot_sha256 ~ '^[0-9a-f]{64}$')
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE governance.access_review_remediations
                ADD CONSTRAINT access_review_remediation_result_object_chk
                    CHECK (jsonb_typeof(result) = 'object')
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE governance.access_review_exports
                ADD CONSTRAINT access_review_export_format_chk
                    CHECK (format IN ('json', 'csv')),
                ADD CONSTRAINT access_review_export_hash_chk
                    CHECK (content_sha256 ~ '^[0-9a-f]{64}$')
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION governance.deny_access_review_ledger_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'access review evidence ledger is append-only';
            END;
            $$;

            CREATE TRIGGER access_review_items_append_only
                BEFORE UPDATE OR DELETE ON governance.access_review_items
                FOR EACH ROW EXECUTE FUNCTION governance.deny_access_review_ledger_mutation();
            CREATE TRIGGER access_review_decisions_append_only
                BEFORE UPDATE OR DELETE ON governance.access_review_decisions
                FOR EACH ROW EXECUTE FUNCTION governance.deny_access_review_ledger_mutation();
            CREATE TRIGGER access_review_remediations_append_only
                BEFORE UPDATE OR DELETE ON governance.access_review_remediations
                FOR EACH ROW EXECUTE FUNCTION governance.deny_access_review_ledger_mutation();
            CREATE TRIGGER access_review_exports_append_only
                BEFORE UPDATE OR DELETE ON governance.access_review_exports
                FOR EACH ROW EXECUTE FUNCTION governance.deny_access_review_ledger_mutation();

            CREATE OR REPLACE FUNCTION governance.guard_access_review_campaign_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'access review campaigns may not be deleted';
                END IF;
                IF OLD.status IN ('completed', 'cancelled') THEN
                    RAISE EXCEPTION 'closed access review campaigns are immutable';
                END IF;
                IF NEW.status NOT IN ('open', 'completed', 'cancelled') THEN
                    RAISE EXCEPTION 'invalid access review campaign transition';
                END IF;
                IF OLD.snapshot_sha256 IS NOT NULL
                    AND NEW.snapshot_sha256 IS DISTINCT FROM OLD.snapshot_sha256 THEN
                    RAISE EXCEPTION 'access review campaign snapshot hash is immutable';
                END IF;
                IF ROW(NEW.title, NEW.review_period_start, NEW.review_period_end, NEW.due_at,
                    NEW.primary_reviewer_user_id, NEW.alternate_reviewer_user_id,
                    NEW.created_by_user_id, NEW.snapshot_at, NEW.opened_at)
                    IS DISTINCT FROM
                   ROW(OLD.title, OLD.review_period_start, OLD.review_period_end, OLD.due_at,
                    OLD.primary_reviewer_user_id, OLD.alternate_reviewer_user_id,
                    OLD.created_by_user_id, OLD.snapshot_at, OLD.opened_at) THEN
                    RAISE EXCEPTION 'open access review campaign definition is immutable';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER access_review_campaign_guard
                BEFORE UPDATE OR DELETE ON governance.access_review_campaigns
                FOR EACH ROW EXECUTE FUNCTION governance.guard_access_review_campaign_mutation();

            CREATE OR REPLACE FUNCTION governance.enforce_access_review_decision_actor()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE assigned_reviewer bigint;
            DECLARE subject_user bigint;
            DECLARE campaign_status text;
            BEGIN
                SELECT i.reviewer_user_id, i.subject_user_id, c.status
                  INTO assigned_reviewer, subject_user, campaign_status
                  FROM governance.access_review_items i
                  JOIN governance.access_review_campaigns c ON c.id = i.campaign_id
                 WHERE i.id = NEW.campaign_item_id;

                IF campaign_status <> 'open' THEN
                    RAISE EXCEPTION 'access review campaign is not open';
                END IF;
                IF NEW.decided_by_user_id <> assigned_reviewer THEN
                    RAISE EXCEPTION 'only the independently assigned reviewer may decide this item';
                END IF;
                IF NEW.decided_by_user_id = subject_user THEN
                    RAISE EXCEPTION 'self-certification of access is prohibited';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER access_review_decision_actor_guard
                BEFORE INSERT ON governance.access_review_decisions
                FOR EACH ROW EXECUTE FUNCTION governance.enforce_access_review_decision_actor();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS governance.enforce_access_review_decision_actor() CASCADE;
            DROP FUNCTION IF EXISTS governance.guard_access_review_campaign_mutation() CASCADE;
            DROP FUNCTION IF EXISTS governance.deny_access_review_ledger_mutation() CASCADE;
        SQL);
        Schema::dropIfExists('governance.access_review_exports');
        Schema::dropIfExists('governance.access_review_remediations');
        Schema::dropIfExists('governance.access_review_decisions');
        Schema::dropIfExists('governance.access_review_items');
        Schema::dropIfExists('governance.access_review_campaigns');
    }
};
