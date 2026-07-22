<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
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
      AND events.effective_at <= clock_timestamp()
    ORDER BY events.effective_at DESC, events.source_status_event_id DESC
    LIMIT 1
) status_events ON true;

COMMENT ON VIEW care_pathways.current_source_statuses IS
    'Current effective source status, using wall-clock time so append-only status facts are visible immediately within a long-running transaction.';
SQL);
    }

    public function down(): void
    {
        // Forward-only correctness repair for the effective-status projection.
    }
};
