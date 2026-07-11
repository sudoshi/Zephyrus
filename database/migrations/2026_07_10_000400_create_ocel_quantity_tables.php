<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part X — Phase XO.3 (QEL capacity). Additive quantity extension for the OCEL
 * 2.0 store: initial quantities per object+item (oqty) and per-event quantity
 * operations (qop). Occupancy is a downstream projection of ocel.* (admit=+1,
 * discharge=-1 on a Unit); these tables carry unit-level counts only — no PHI.
 * Removable by dropping both tables; the rest of ocel.* is unaffected.
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS ocel');

        if (! Schema::hasTable('ocel.object_quantities')) {
            Schema::create('ocel.object_quantities', function (Blueprint $table) {
                $table->id();
                $table->string('object_id', 160);
                $table->string('item_type', 60);
                $table->integer('quantity')->default(0);
                $table->timestamps();

                $table->unique(['object_id', 'item_type'], 'ocel_oqty_unique');
            });
        }

        if (! Schema::hasTable('ocel.quantity_operations')) {
            Schema::create('ocel.quantity_operations', function (Blueprint $table) {
                $table->id();
                $table->string('event_id', 160);
                $table->string('object_id', 160);
                $table->string('item_type', 60);
                $table->integer('delta');
                $table->timestampTz('event_time');
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['event_id', 'object_id', 'item_type'], 'ocel_qop_unique');
                $table->index(['object_id', 'item_type', 'event_time'], 'ocel_qop_series_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ocel.quantity_operations');
        Schema::dropIfExists('ocel.object_quantities');
    }
};
