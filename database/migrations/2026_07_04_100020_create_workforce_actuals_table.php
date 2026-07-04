<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zephyrus 2.0 P7 (Staffing + Financial) — the time-and-attendance day fact.
 *
 * One row per cost center (unit) per day. This single table feeds BOTH the
 * staffing cockpit tiles (today's OT% / agency RNs / callouts / sitters /
 * productivity) and the MTD mv_cost_center_productivity materialized view
 * (HPPD, worked/UOS, OT%, labor dollars) — one fact, two altitudes, so the
 * staffing wall and the financial ledger can never disagree about hours.
 *
 * UOS here = census patient-days for the cost center; target_hours is the
 * productivity-model earned-hours budget the worked hours are judged against.
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        if (! Schema::hasTable('prod.workforce_actuals')) {
            Schema::create('prod.workforce_actuals', function (Blueprint $table) {
                $table->id('workforce_actual_id');
                $table->uuid('actual_uuid')->unique();
                $table->foreignId('unit_id')->nullable()->constrained('prod.units', 'unit_id')->nullOnDelete();
                $table->string('cost_center', 80);
                $table->string('cost_center_label', 160)->nullable();
                $table->date('work_date');
                $table->decimal('worked_hours', 9, 2)->default(0);
                $table->decimal('overtime_hours', 9, 2)->default(0);
                $table->decimal('agency_hours', 9, 2)->default(0);
                $table->unsignedSmallInteger('agency_rn_headcount')->default(0);
                $table->unsignedSmallInteger('callouts')->default(0);
                $table->unsignedSmallInteger('sitters')->default(0);
                $table->decimal('census_days', 8, 2)->default(0);
                $table->decimal('target_hours', 9, 2)->default(0);
                $table->decimal('labor_cost', 12, 2)->default(0);
                $table->decimal('premium_cost', 12, 2)->default(0);
                $table->decimal('agency_cost', 12, 2)->default(0);
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->unsignedBigInteger('updated_by_user_id')->nullable();
                $table->boolean('is_deleted')->default(false);

                $table->unique(['cost_center', 'work_date']);
                $table->index(['work_date', 'is_deleted']);
            });
        }
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.workforce_actuals');
    }
};
