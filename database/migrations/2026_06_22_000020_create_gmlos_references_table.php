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
        Schema::create('prod.gmlos_references', function (Blueprint $table) {
            $table->id('gmlos_reference_id');
            $table->string('unit_type'); // matches units.type: med_surg|icu|step_down|ed
            $table->decimal('gmlos_days', 5, 2);
            $table->date('effective_from')->nullable();
            $table->timestamps();

            $table->unique('unit_type');
        });

        DB::statement("ALTER TABLE prod.gmlos_references ADD CONSTRAINT chk_gmlos_unit_type CHECK (unit_type IN ('med_surg','icu','step_down','ed'))");
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.gmlos_references');
    }
};
