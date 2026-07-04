<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zephyrus 2.0 P0 — extend ops.metric_definitions into the cockpit's
 * kpi_definitions (plan Part VI §2). Purely ADDITIVE and idempotent: every
 * column is hasColumn-guarded, nothing existing is altered, and down() only
 * drops in local (SafeMigration discipline — prod data is sacred).
 *
 * facility_key is TEXT and manifest-keyed ('HOSP1' from HospitalManifest) —
 * decision D1: single-facility, NO facilities table, NO FK, nullable for
 * forward-compat.
 */
return new class extends Migration
{
    use SafeMigration;

    private const TABLE = 'ops.metric_definitions';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            if (! Schema::hasColumn(self::TABLE, 'ok_edge')) {
                $table->decimal('ok_edge', 14, 4)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'warn_edge')) {
                $table->decimal('warn_edge', 14, 4)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'crit_edge')) {
                $table->decimal('crit_edge', 14, 4)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'refresh_secs')) {
                $table->integer('refresh_secs')->default(300);
            }
            if (! Schema::hasColumn(self::TABLE, 'source_system')) {
                $table->string('source_system', 120)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'alert_template')) {
                $table->text('alert_template')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'facility_key')) {
                $table->string('facility_key', 40)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
        });
    }

    public function down(): void
    {
        if (! $this->isLocalEnvironment()) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            foreach (['ok_edge', 'warn_edge', 'crit_edge', 'refresh_secs', 'source_system', 'alert_template', 'facility_key', 'is_active'] as $column) {
                if (Schema::hasColumn(self::TABLE, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
