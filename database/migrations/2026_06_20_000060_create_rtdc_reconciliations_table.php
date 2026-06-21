<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        Schema::create('prod.rtdc_reconciliations', function (Blueprint $table) {
            $table->id('rtdc_reconciliation_id');
            $table->foreignId('unit_id')->constrained('prod.units', 'unit_id');
            $table->date('service_date');
            $table->decimal('predicted_discharges', 6, 2)->default(0);
            $table->integer('actual_discharges')->default(0);
            $table->integer('predicted_admissions')->default(0);
            $table->integer('actual_admissions')->default(0);
            $table->decimal('reliability_score', 5, 4)->nullable(); // 0..1
            $table->timestamps();
            $table->unique(['unit_id', 'service_date'], 'uq_rtdc_recon_unit_date');
        });
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.rtdc_reconciliations');
    }
};
