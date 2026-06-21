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
        Schema::create('prod.operational_events', function (Blueprint $table) {
            $table->id('operational_event_id');
            $table->uuid('event_id')->unique(); // idempotency key
            $table->string('type'); // EncounterStarted | EncounterTransferred | EncounterDischarged | BedStatusChanged | AcuityChanged
            $table->string('encounter_ref')->nullable();
            $table->jsonb('payload');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['type', 'occurred_at']);
            $table->index('encounter_ref');
        });
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.operational_events');
    }
};
