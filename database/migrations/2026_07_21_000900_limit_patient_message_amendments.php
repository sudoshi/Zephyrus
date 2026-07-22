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
CREATE UNIQUE INDEX IF NOT EXISTS uq_patient_messages_one_amendment_per_source
    ON patient_experience.messages(relates_to_message_id)
    WHERE message_kind IN ('correction', 'retraction');
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared('DROP INDEX IF EXISTS patient_experience.uq_patient_messages_one_amendment_per_source;');
    }
};
