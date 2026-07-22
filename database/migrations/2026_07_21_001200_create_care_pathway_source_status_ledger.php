<?php

/**
 * Add an append-only status history for immutable evidence sources. The source
 * row remains the imported baseline; later retractions and supersessions are
 * recorded as new facts and projected through one current-status view.
 */

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS care_pathways.source_status_events (
    source_status_event_id bigserial PRIMARY KEY,
    status_event_uuid      uuid NOT NULL UNIQUE,
    source_id              bigint NOT NULL REFERENCES care_pathways.sources(source_id) ON DELETE RESTRICT,
    supersession_state     text NOT NULL
                           CHECK (supersession_state IN ('current', 'superseded', 'retracted', 'unknown')),
    reason                 text NOT NULL,
    evidence_url           text,
    observed_at            timestamptz NOT NULL DEFAULT now(),
    effective_at           timestamptz NOT NULL DEFAULT now(),
    recorded_by_user_id    bigint,
    recorded_by_ref        text,
    metadata               jsonb NOT NULL DEFAULT '{}'::jsonb,
    event_digest           char(64) NOT NULL CHECK (event_digest ~ '^[0-9a-f]{64}$'),
    created_at             timestamptz NOT NULL DEFAULT now(),
    UNIQUE (source_id, event_digest),
    CONSTRAINT care_pathway_source_status_actor_chk CHECK (
        num_nonnulls(recorded_by_user_id, recorded_by_ref) = 1
    ),
    CONSTRAINT care_pathway_source_status_metadata_json_chk CHECK (
        jsonb_typeof(metadata) = 'object'
    )
);

CREATE INDEX IF NOT EXISTS idx_care_pathway_source_status_current
    ON care_pathways.source_status_events(source_id, effective_at DESC, source_status_event_id DESC);

DROP TRIGGER IF EXISTS care_pathway_source_status_events_append_only
    ON care_pathways.source_status_events;
CREATE TRIGGER care_pathway_source_status_events_append_only
BEFORE UPDATE OR DELETE ON care_pathways.source_status_events
FOR EACH ROW EXECUTE FUNCTION care_pathways.reject_append_only_mutation();

CREATE OR REPLACE VIEW care_pathways.current_source_statuses AS
SELECT
    sources.source_id,
    sources.source_uuid,
    COALESCE(status_events.supersession_state, sources.supersession_state) AS supersession_state,
    status_events.source_status_event_id,
    status_events.status_event_uuid,
    status_events.reason,
    status_events.evidence_url,
    status_events.observed_at,
    COALESCE(status_events.effective_at, sources.verified_date::timestamptz, sources.created_at) AS effective_at,
    status_events.recorded_by_user_id,
    status_events.recorded_by_ref,
    status_events.metadata,
    status_events.event_digest
FROM care_pathways.sources sources
LEFT JOIN LATERAL (
    SELECT events.*
    FROM care_pathways.source_status_events events
    WHERE events.source_id = sources.source_id
      AND events.effective_at <= now()
    ORDER BY events.effective_at DESC, events.source_status_event_id DESC
    LIMIT 1
) status_events ON true;

COMMENT ON TABLE care_pathways.source_status_events IS
    'Append-only source currency facts. Retractions and supersessions are new events; imported source rows are never overwritten.';

COMMENT ON VIEW care_pathways.current_source_statuses IS
    'Current effective source status, using the immutable imported source state until an append-only status event supersedes it.';
SQL);
    }

    public function down(): void
    {
        if (! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP VIEW IF EXISTS care_pathways.current_source_statuses;
DROP TABLE IF EXISTS care_pathways.source_status_events;
SQL);
    }
};
