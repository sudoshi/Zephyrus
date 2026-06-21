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
        Schema::create('prod.encounters', function (Blueprint $table) {
            $table->id('encounter_id');
            $table->string('patient_ref'); // pseudonymous, never MRN
            $table->foreignId('unit_id')->nullable()->constrained('prod.units', 'unit_id');
            $table->foreignId('bed_id')->nullable()->constrained('prod.beds', 'bed_id');
            $table->timestamp('admitted_at')->nullable();
            $table->date('expected_discharge_date')->nullable();
            $table->unsignedTinyInteger('acuity_tier')->default(2); // 1..4
            $table->string('status')->default('active'); // active | discharged
            $table->timestamp('discharged_at')->nullable();
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->index(['unit_id', 'status']);
        });

        DB::statement('ALTER TABLE prod.encounters ADD CONSTRAINT chk_acuity_tier CHECK (acuity_tier BETWEEN 1 AND 4)');

        Schema::create('prod.census_snapshots', function (Blueprint $table) {
            $table->id('census_snapshot_id');
            $table->foreignId('unit_id')->constrained('prod.units', 'unit_id');
            $table->timestamp('captured_at');
            $table->integer('staffed_beds')->default(0);
            $table->integer('occupied')->default(0);
            $table->integer('available')->default(0);
            $table->integer('blocked')->default(0);
            $table->integer('acuity_adjusted_capacity')->default(0);
            $table->timestamps();
            $table->index(['unit_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.census_snapshots');
        $this->safeDropIfExists('prod.encounters');
    }
};
