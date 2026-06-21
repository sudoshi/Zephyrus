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
        Schema::create('prod.bed_placement_decisions', function (Blueprint $table) {
            $table->id('bed_placement_decision_id');
            $table->foreignId('bed_request_id')->constrained('prod.bed_requests', 'bed_request_id');
            $table->foreignId('recommended_bed_id')->nullable()->constrained('prod.beds', 'bed_id');
            $table->foreignId('chosen_bed_id')->nullable()->constrained('prod.beds', 'bed_id');
            $table->string('action'); // accepted | edited | rejected
            $table->text('reason')->nullable();
            $table->jsonb('score_snapshot')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('prod.users', 'id');
            $table->timestamps();
        });

        DB::statement("ALTER TABLE prod.bed_placement_decisions ADD CONSTRAINT chk_bpd_action CHECK (action IN ('accepted','edited','rejected'))");
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.bed_placement_decisions');
    }
};
