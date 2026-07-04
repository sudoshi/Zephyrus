<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zephyrus 2.0 P7 (Flow) — the discharge-lounge source of truth.
 *
 * flow.discharge_lounge was the last demo-valued Flow metric: no table
 * anywhere recorded who is sitting in the lounge. One stay row per patient
 * visit; census = arrived with no departed_at. Chair capacity lives in the
 * hospital manifest (facility.discharge_lounge_chairs), not here — the
 * lounge is a place, the manifest owns the building.
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        if (! Schema::hasTable('prod.discharge_lounge_stays')) {
            Schema::create('prod.discharge_lounge_stays', function (Blueprint $table) {
                $table->id('lounge_stay_id');
                $table->uuid('stay_uuid')->unique();
                $table->string('patient_ref', 120);
                $table->string('encounter_ref', 120)->nullable();
                $table->foreignId('unit_id')->nullable()->constrained('prod.units', 'unit_id')->nullOnDelete();
                $table->string('origin_unit_label', 160)->nullable();
                $table->timestamp('arrived_at')->useCurrent();
                $table->timestamp('expected_pickup_at')->nullable();
                $table->timestamp('departed_at')->nullable();
                $table->string('departure_mode', 80)->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->unsignedBigInteger('updated_by_user_id')->nullable();
                $table->boolean('is_deleted')->default(false);

                $table->index(['is_deleted', 'departed_at']);
                $table->index('arrived_at');
            });

            DB::statement("ALTER TABLE prod.discharge_lounge_stays ADD CONSTRAINT chk_lounge_departure_mode CHECK (departure_mode IS NULL OR departure_mode IN ('family_ride','rideshare','medical_car','wheelchair_van','ambulance','walkout'))");
        }
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.discharge_lounge_stays');
    }
};
