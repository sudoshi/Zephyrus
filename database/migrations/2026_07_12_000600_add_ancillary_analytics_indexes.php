<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS ancillary_orders_department_ordered_idx
            ON prod.ancillary_orders (department, ordered_at, ancillary_order_id)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS prod.ancillary_orders_department_ordered_idx');
    }
};
