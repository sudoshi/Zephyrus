<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE flow_core.occupancy_snapshots
                ADD COLUMN IF NOT EXISTS occupancy_details jsonb NOT NULL DEFAULT '[]'::jsonb,
                ADD COLUMN IF NOT EXISTS timer_status_counts jsonb NOT NULL DEFAULT '{}'::jsonb,
                ADD COLUMN IF NOT EXISTS service_line_timer_counts jsonb NOT NULL DEFAULT '{}'::jsonb,
                ADD COLUMN IF NOT EXISTS persona_timer_counts jsonb NOT NULL DEFAULT '{}'::jsonb,
                ADD COLUMN IF NOT EXISTS active_blocker_counts jsonb NOT NULL DEFAULT '{}'::jsonb,
                ADD COLUMN IF NOT EXISTS projection_window jsonb NOT NULL DEFAULT '{}'::jsonb;

            CREATE INDEX IF NOT EXISTS idx_flow_occupancy_details_gin
                ON flow_core.occupancy_snapshots USING gin(occupancy_details);

            CREATE INDEX IF NOT EXISTS idx_flow_occupancy_service_line_timers_gin
                ON flow_core.occupancy_snapshots USING gin(service_line_timer_counts);

            COMMENT ON COLUMN flow_core.occupancy_snapshots.occupancy_details IS
                'Disk-ready occupancy detail payloads for the 4D viewer: stay duration, origin, anticipated move, and timers.';

            COMMENT ON COLUMN flow_core.occupancy_snapshots.timer_status_counts IS
                'Roll-up of ok/watch/delayed timer states at this snapshot.';

            COMMENT ON COLUMN flow_core.occupancy_snapshots.service_line_timer_counts IS
                'Service-line compounded occupancy pressure counts for RTDC demand-capacity views.';

            COMMENT ON COLUMN flow_core.occupancy_snapshots.persona_timer_counts IS
                'Persona-facing counts for transport, EVS, bed management, and capacity leadership.';
        SQL);
    }

    public function down(): void
    {
        if (! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE flow_core.occupancy_snapshots
                DROP COLUMN IF EXISTS projection_window,
                DROP COLUMN IF EXISTS active_blocker_counts,
                DROP COLUMN IF EXISTS persona_timer_counts,
                DROP COLUMN IF EXISTS service_line_timer_counts,
                DROP COLUMN IF EXISTS timer_status_counts,
                DROP COLUMN IF EXISTS occupancy_details;
        SQL);
    }
};
