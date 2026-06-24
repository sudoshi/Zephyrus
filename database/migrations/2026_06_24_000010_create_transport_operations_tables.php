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
        Schema::create('prod.transport_requests', function (Blueprint $table) {
            $table->id('transport_request_id');
            $table->uuid('request_uuid')->unique();
            $table->string('request_type'); // inpatient | transfer | discharge | ems | care_transition
            $table->string('priority')->default('routine'); // routine | urgent | stat
            $table->string('status')->default('requested');
            $table->string('patient_ref');
            $table->string('encounter_ref')->nullable();
            $table->string('origin');
            $table->string('destination');
            $table->string('transport_mode')->default('wheelchair');
            $table->string('clinical_service')->nullable();
            $table->string('requested_by')->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('needed_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('assigned_team')->nullable();
            $table->string('assigned_vendor')->nullable();
            $table->string('external_system')->nullable();
            $table->string('external_id')->nullable();
            $table->jsonb('segments')->nullable();
            $table->jsonb('risk_flags')->nullable();
            $table->jsonb('handoff')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->boolean('is_deleted')->default(false);

            $table->index(['status', 'needed_at']);
            $table->index(['request_type', 'status']);
            $table->index(['priority', 'needed_at']);
            $table->index('patient_ref');
            $table->index('encounter_ref');
            $table->index(['external_system', 'external_id']);
        });

        Schema::create('prod.transport_events', function (Blueprint $table) {
            $table->id('transport_event_id');
            $table->uuid('event_uuid')->unique();
            $table->foreignId('transport_request_id')
                ->constrained('prod.transport_requests', 'transport_request_id')
                ->cascadeOnDelete();
            $table->string('event_type');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->jsonb('payload')->nullable();
            $table->string('source')->default('zephyrus');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['transport_request_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
            $table->index('source');
        });

        DB::statement("ALTER TABLE prod.transport_requests ADD CONSTRAINT chk_transport_request_type CHECK (request_type IN ('inpatient','transfer','discharge','ems','care_transition'))");
        DB::statement("ALTER TABLE prod.transport_requests ADD CONSTRAINT chk_transport_priority CHECK (priority IN ('routine','urgent','stat'))");
        DB::statement("ALTER TABLE prod.transport_requests ADD CONSTRAINT chk_transport_status CHECK (status IN ('requested','accepted','queued','assigned','dispatched','arrived_pickup','patient_ready','patient_not_ready','picked_up','en_route','arrived_destination','handoff_started','handoff_complete','completed','canceled','escalated','failed'))");
        DB::statement("ALTER TABLE prod.transport_requests ADD CONSTRAINT chk_transport_mode CHECK (transport_mode IN ('ambulatory','wheelchair','stretcher','bed','rideshare','nemt','bls','als','critical_care','ems','air','courier'))");
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.transport_events');
        $this->safeDropIfExists('prod.transport_requests');
    }
};
