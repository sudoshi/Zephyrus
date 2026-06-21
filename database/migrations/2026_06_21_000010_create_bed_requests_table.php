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
        Schema::create('prod.bed_requests', function (Blueprint $table) {
            $table->id('bed_request_id');
            $table->string('patient_ref'); // pseudonymous
            $table->string('source'); // ed | transfer | direct | or
            $table->string('sex')->nullable(); // M | F | other (captured; gender constraint deferred)
            $table->string('service')->nullable();
            $table->unsignedTinyInteger('acuity_tier')->default(2);
            $table->string('isolation_required')->default('none'); // none | contact | droplet | airborne
            $table->string('required_unit_type')->default('any'); // any | med_surg | icu | step_down
            $table->string('status')->default('pending'); // pending | placed | cancelled
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->index('status');
        });

        DB::statement('ALTER TABLE prod.bed_requests ADD CONSTRAINT chk_bedreq_acuity CHECK (acuity_tier BETWEEN 1 AND 4)');
        DB::statement("ALTER TABLE prod.bed_requests ADD CONSTRAINT chk_bedreq_source CHECK (source IN ('ed','transfer','direct','or'))");
        DB::statement("ALTER TABLE prod.bed_requests ADD CONSTRAINT chk_bedreq_iso CHECK (isolation_required IN ('none','contact','droplet','airborne'))");
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.bed_requests');
    }
};
