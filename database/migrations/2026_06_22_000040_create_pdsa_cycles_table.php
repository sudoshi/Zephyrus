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
        Schema::create('prod.pdsa_cycles', function (Blueprint $table) {
            $table->id('pdsa_cycle_id');
            $table->string('title');
            $table->foreignId('unit_id')->nullable()->constrained('prod.units', 'unit_id');
            $table->string('status')->default('active'); // planned|active|completed|abandoned
            $table->string('owner')->nullable();
            $table->text('objective')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->boolean('is_deleted')->default(false);
        });

        DB::statement("ALTER TABLE prod.pdsa_cycles ADD CONSTRAINT chk_pdsa_status CHECK (status IN ('planned','active','completed','abandoned'))");
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.pdsa_cycles');
    }
};
