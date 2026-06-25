<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        Schema::create('prod.rtdc_predictions', function (Blueprint $table) {
            $table->id('rtdc_prediction_id');
            $table->foreignId('unit_id')->constrained('prod.units', 'unit_id');
            $table->date('service_date');
            $table->string('horizon'); // by_2pm | by_midnight
            // Predicted discharges (clinician-entered confidence tiers).
            $table->integer('discharges_definite')->default(0);
            $table->integer('discharges_probable')->default(0);
            $table->integer('discharges_possible')->default(0);
            $table->decimal('discharges_weighted', 6, 2)->default(0);
            // Predicted demand by source.
            $table->integer('demand_ed')->default(0);
            $table->integer('demand_or')->default(0);
            $table->integer('demand_transfer')->default(0);
            $table->integer('demand_direct')->default(0);
            $table->integer('demand_expected')->default(0);
            // Capacity + the headline number.
            $table->integer('capacity_now')->default(0);
            $table->integer('bed_need')->default(0); // demand - (available + weighted discharges)
            $table->string('status')->default('open'); // open | closed
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->unique(['unit_id', 'service_date', 'horizon'], 'uq_rtdc_pred_unit_date_horizon');
        });

        DB::statement("ALTER TABLE prod.rtdc_predictions ADD CONSTRAINT chk_rtdc_horizon CHECK (horizon IN ('by_2pm','by_midnight'))");

        Schema::create('prod.rtdc_plans', function (Blueprint $table) {
            $table->id('rtdc_plan_id');
            $table->foreignId('rtdc_prediction_id')->nullable()->constrained('prod.rtdc_predictions', 'rtdc_prediction_id');
            $table->text('action_text');
            $table->string('owner')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->string('status')->default('open'); // open | done
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->boolean('is_deleted')->default(false);
        });
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.rtdc_plans');
        $this->safeDropIfExists('prod.rtdc_predictions');
    }
};
