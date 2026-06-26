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
        if (! Schema::hasTable('prod.evs_requests')) {
            Schema::create('prod.evs_requests', function (Blueprint $table) {
                $table->id('evs_request_id');
                $table->uuid('request_uuid')->unique();
                $table->string('request_type', 80);
                $table->string('priority', 40)->default('routine');
                $table->string('status', 40)->default('requested');
                $table->foreignId('room_id')->nullable()->constrained('prod.rooms', 'room_id')->nullOnDelete();
                $table->foreignId('bed_id')->nullable()->constrained('prod.beds', 'bed_id')->nullOnDelete();
                $table->foreignId('unit_id')->nullable()->constrained('prod.units', 'unit_id')->nullOnDelete();
                $table->string('patient_ref', 120)->nullable();
                $table->string('encounter_ref', 120)->nullable();
                $table->string('location_label', 160);
                $table->string('turn_type', 80)->default('standard');
                $table->boolean('isolation_required')->default(false);
                $table->string('requested_by', 120)->nullable();
                $table->timestamp('requested_at')->useCurrent();
                $table->timestamp('needed_at')->nullable();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->string('assigned_team', 120)->nullable();
                $table->string('assigned_user_ref', 120)->nullable();
                $table->string('external_system', 120)->nullable();
                $table->string('external_id', 160)->nullable();
                $table->jsonb('risk_flags')->nullable();
                $table->jsonb('completion_payload')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->unsignedBigInteger('updated_by_user_id')->nullable();
                $table->boolean('is_deleted')->default(false);

                $table->index(['status', 'needed_at']);
                $table->index(['request_type', 'status']);
                $table->index(['priority', 'needed_at']);
                $table->index(['room_id', 'status']);
                $table->index(['bed_id', 'status']);
                $table->index(['unit_id', 'status']);
                $table->index(['external_system', 'external_id']);
            });
        }

        if (! Schema::hasTable('prod.evs_events')) {
            Schema::create('prod.evs_events', function (Blueprint $table) {
                $table->id('evs_event_id');
                $table->uuid('event_uuid')->unique();
                $table->foreignId('evs_request_id')
                    ->constrained('prod.evs_requests', 'evs_request_id')
                    ->cascadeOnDelete();
                $table->string('event_type', 120);
                $table->string('from_status', 40)->nullable();
                $table->string('to_status', 40)->nullable();
                $table->jsonb('payload')->nullable();
                $table->string('source', 120)->default('zephyrus');
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->timestamp('occurred_at')->useCurrent();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['evs_request_id', 'occurred_at']);
                $table->index(['event_type', 'occurred_at']);
                $table->index('source');
            });
        }

        DB::statement("ALTER TABLE prod.evs_requests ADD CONSTRAINT chk_evs_request_type CHECK (request_type IN ('bed_clean','room_clean','terminal_clean','isolation_clean','spill','discharge_turnover','procedure_turnover'))");
        DB::statement("ALTER TABLE prod.evs_requests ADD CONSTRAINT chk_evs_priority CHECK (priority IN ('routine','urgent','stat'))");
        DB::statement("ALTER TABLE prod.evs_requests ADD CONSTRAINT chk_evs_status CHECK (status IN ('requested','queued','assigned','in_progress','completed','canceled','escalated','failed'))");
        DB::statement("ALTER TABLE prod.evs_requests ADD CONSTRAINT chk_evs_turn_type CHECK (turn_type IN ('standard','terminal','isolation','stat','procedure','spill'))");
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.evs_events');
        $this->safeDropIfExists('prod.evs_requests');
    }
};
