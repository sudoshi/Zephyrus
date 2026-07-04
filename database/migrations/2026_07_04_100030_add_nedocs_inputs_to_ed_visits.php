<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zephyrus 2.0 P7 (ED) — the one genuinely per-visit NEDOCS input.
 *
 * The other two Weiss inputs (longest-admit-wait, last-bed-time) are NOT new
 * columns: they are aggregates the NedocsService derives from timestamps that
 * already exist (admit_decision_at, bed_assigned_at, arrived_at). Storing a
 * house-level aggregate on every visit row would be a normalization error.
 * Ventilator status, by contrast, IS a per-patient fact with no existing home.
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        if (! Schema::hasColumn('prod.ed_visits', 'is_ventilated')) {
            Schema::table('prod.ed_visits', function (Blueprint $table) {
                $table->boolean('is_ventilated')->default(false)->after('esi_level');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('prod.ed_visits', 'is_ventilated')) {
            Schema::table('prod.ed_visits', function (Blueprint $table) {
                $table->dropColumn('is_ventilated');
            });
        }
    }
};
