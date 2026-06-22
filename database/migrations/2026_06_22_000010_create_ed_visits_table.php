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
        Schema::create('prod.ed_visits', function (Blueprint $table) {
            $table->id('ed_visit_id');
            $table->string('patient_ref'); // pseudonymous, e.g. sim-ed-0001
            $table->timestamp('arrived_at');
            $table->timestamp('triaged_at')->nullable();
            $table->unsignedTinyInteger('esi_level')->nullable(); // 1–5
            $table->timestamp('provider_seen_at')->nullable();
            $table->string('disposition')->nullable(); // admitted|discharged|lwbs|transfer|eloped; NULL = still in ED
            $table->timestamp('admit_decision_at')->nullable(); // boarding clock start
            $table->timestamp('bed_assigned_at')->nullable(); // boarding clock end
            $table->timestamp('departed_at')->nullable();
            $table->foreignId('unit_id')->nullable()->constrained('prod.units', 'unit_id'); // admitting unit
            $table->timestamps();
            $table->boolean('is_deleted')->default(false);

            $table->index('arrived_at');
            $table->index('disposition');
        });

        DB::statement('ALTER TABLE prod.ed_visits ADD CONSTRAINT chk_ed_visits_esi CHECK (esi_level BETWEEN 1 AND 5)');
        DB::statement("ALTER TABLE prod.ed_visits ADD CONSTRAINT chk_ed_visits_disposition CHECK (disposition IN ('admitted','discharged','lwbs','transfer','eloped'))");
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.ed_visits');
    }
};
