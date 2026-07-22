<?php

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
ALTER TABLE patient_experience.message_routing_events
    DROP CONSTRAINT IF EXISTS message_routing_events_event_type_check;

ALTER TABLE patient_experience.message_routing_events
    ADD CONSTRAINT message_routing_events_event_type_check CHECK (event_type IN (
        'thread_opened',
        'message_submitted',
        'message_corrected',
        'message_retracted',
        'assigned',
        'acknowledged',
        'rerouted',
        'escalated',
        'responded',
        'closed'
    ));
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE patient_experience.message_routing_events
    DROP CONSTRAINT IF EXISTS message_routing_events_event_type_check;

ALTER TABLE patient_experience.message_routing_events
    ADD CONSTRAINT message_routing_events_event_type_check CHECK (event_type IN (
        'thread_opened',
        'message_submitted',
        'assigned',
        'acknowledged',
        'rerouted',
        'escalated',
        'responded',
        'closed'
    ));
SQL);
    }
};
