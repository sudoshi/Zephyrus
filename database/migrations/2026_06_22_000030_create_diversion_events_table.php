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
        Schema::create('prod.diversion_events', function (Blueprint $table) {
            $table->id('diversion_event_id');
            $table->string('scope')->default('ed'); // ed|hospital
            $table->foreignId('unit_id')->nullable()->constrained('prod.units', 'unit_id');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable(); // NULL = ongoing
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->boolean('is_deleted')->default(false);
        });

        DB::statement("ALTER TABLE prod.diversion_events ADD CONSTRAINT chk_diversion_scope CHECK (scope IN ('ed','hospital'))");
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.diversion_events');
    }
};
