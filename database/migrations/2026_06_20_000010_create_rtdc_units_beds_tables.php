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
        Schema::create('prod.units', function (Blueprint $table) {
            $table->id('unit_id');
            $table->string('name');
            $table->string('abbreviation')->nullable();
            $table->string('type'); // ed | med_surg | icu | step_down
            $table->integer('staffed_bed_count')->default(0);
            $table->integer('ratio_floor')->default(4); // max patients per nurse
            $table->integer('access_standard_minutes')->default(120);
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
        });

        Schema::create('prod.beds', function (Blueprint $table) {
            $table->id('bed_id');
            $table->foreignId('unit_id')->constrained('prod.units', 'unit_id');
            $table->string('label');
            $table->string('status')->default('available'); // available | occupied | blocked | dirty
            $table->string('bed_type')->default('standard');
            $table->boolean('isolation_capable')->default(false);
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
        });

        DB::statement("ALTER TABLE prod.beds ADD CONSTRAINT chk_bed_status CHECK (status IN ('available','occupied','blocked','dirty'))");
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.beds');
        $this->safeDropIfExists('prod.units');
    }
};
