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
        Schema::create('prod.huddles', function (Blueprint $table) {
            $table->id('huddle_id');
            $table->string('type'); // unit | hospital
            $table->foreignId('unit_id')->nullable()->constrained('prod.units', 'unit_id');
            $table->date('service_date');
            $table->string('status')->default('open'); // open | closed
            $table->foreignId('facilitator_id')->nullable()->constrained('prod.users', 'id');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->boolean('is_deleted')->default(false);
        });

        DB::statement("ALTER TABLE prod.huddles ADD CONSTRAINT chk_huddle_type CHECK (type IN ('unit','hospital'))");

        Schema::create('prod.barriers', function (Blueprint $table) {
            $table->id('barrier_id');
            $table->foreignId('encounter_id')->nullable()->constrained('prod.encounters', 'encounter_id');
            $table->foreignId('unit_id')->nullable()->constrained('prod.units', 'unit_id');
            $table->string('category'); // medical | logistical | placement | social
            $table->string('reason_code')->nullable();
            $table->text('description')->nullable();
            $table->string('owner')->nullable();
            $table->string('status')->default('open'); // open | resolved
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->boolean('is_deleted')->default(false);
            $table->index(['unit_id', 'status']);
        });

        DB::statement("ALTER TABLE prod.barriers ADD CONSTRAINT chk_barrier_category CHECK (category IN ('medical','logistical','placement','social'))");
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.barriers');
        $this->safeDropIfExists('prod.huddles');
    }
};
