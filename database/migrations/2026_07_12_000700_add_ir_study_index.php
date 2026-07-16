<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS rad_exams_ir_scheduled_idx
            ON prod.rad_exams (scheduled_start_at, rad_scanner_id, rad_exam_id)
            WHERE is_ir = true
              AND scheduled_start_at IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS prod.rad_exams_ir_scheduled_idx');
    }
};
