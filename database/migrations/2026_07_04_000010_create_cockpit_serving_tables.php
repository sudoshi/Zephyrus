<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zephyrus 2.0 P1 — the cockpit serving layer (plan Part VI §4).
 *
 * cockpit_snapshots is a SINGLE replaced row keyed by facility_key TEXT
 * (decision D1: 'HOSP1' from HospitalManifest; no facilities table, no FK).
 * cockpit_alerts carries the open/clear lifecycle INCLUDING hold_count now
 * (flap-damping state, Part II.1 correction — created in P1, not ALTER'd in
 * P6). Additive + hasTable-guarded; down() drops only in local.
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        if (! Schema::hasTable('prod.cockpit_snapshots')) {
            Schema::create('prod.cockpit_snapshots', function (Blueprint $table) {
                $table->string('facility_key', 40)->primary();
                $table->jsonb('payload');
                $table->timestampTz('generated_at');
            });
        }

        if (! Schema::hasTable('prod.cockpit_alerts')) {
            Schema::create('prod.cockpit_alerts', function (Blueprint $table) {
                $table->id('cockpit_alert_id');
                $table->string('facility_key', 40);
                $table->string('key', 160);
                // Logical ISA-101 alert tiers only ('warn' | 'crit') — the
                // AlertEngine (P6) derives these from the StatusEngine.
                $table->string('status', 20);
                $table->text('text');
                $table->timestampTz('opened_at');
                $table->timestampTz('cleared_at')->nullable();
                // Flap-damping: consecutive snapshots the candidate state has
                // held; the P6 AlertEngine opens/clears only at K holds.
                $table->integer('hold_count')->default(0);
                $table->timestamps();

                $table->index(['facility_key', 'key']);
                $table->index('cleared_at');
            });
        }
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.cockpit_alerts');
        $this->safeDropIfExists('prod.cockpit_snapshots');
    }
};
