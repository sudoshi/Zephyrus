<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The current Red Stretch Plan (free-text capacity-escalation note) for each
 * unit. One row per unit — the latest plan is upserted from the System Capacity
 * unit-status table. Previously the update endpoint logged-and-returned-success
 * without persisting (a false-positive save); this gives it durable storage.
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        if (Schema::hasTable('prod.rtdc_red_stretch_plans')) {
            return;
        }

        Schema::create('prod.rtdc_red_stretch_plans', function (Blueprint $table) {
            $table->id('rtdc_red_stretch_plan_id');
            $table->foreignId('unit_id')->unique()->constrained('prod.units', 'unit_id');
            $table->text('plan');
            $table->string('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.rtdc_red_stretch_plans');
    }
};
