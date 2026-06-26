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
        // Per-unit, per-role, per-shift staffing posture. This is the operational
        // reality that makes "staffing is tight on two units" a queryable signal
        // rather than narrative text in a metric definition.
        if (! Schema::hasTable('prod.staffing_plans')) {
            Schema::create('prod.staffing_plans', function (Blueprint $table) {
                $table->id('staffing_plan_id');
                $table->uuid('plan_uuid')->unique();
                $table->foreignId('unit_id')->nullable()->constrained('prod.units', 'unit_id')->nullOnDelete();
                $table->string('unit_label', 120);
                $table->string('role', 40);
                $table->date('shift_date');
                $table->string('shift', 20)->default('day');
                $table->integer('required_count')->default(0);
                $table->integer('scheduled_count')->default(0);
                $table->integer('actual_count')->default(0);
                $table->integer('minimum_safe_count')->default(0);
                $table->integer('census')->default(0);
                $table->decimal('ratio_target', 5, 2)->nullable();
                $table->string('status', 40)->default('balanced');
                $table->string('notes', 400)->nullable();
                $table->jsonb('constraints')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->unsignedBigInteger('updated_by_user_id')->nullable();
                $table->boolean('is_deleted')->default(false);

                $table->unique(['unit_id', 'role', 'shift_date', 'shift'], 'uniq_staffing_plan_slot');
                $table->index(['shift_date', 'shift']);
                $table->index(['status', 'shift_date']);
                $table->index(['role', 'shift_date']);
                $table->index(['unit_id', 'shift_date']);
            });
        }

        // Gap-mitigation requests: the governed action domain for staffing.
        if (! Schema::hasTable('prod.staffing_requests')) {
            Schema::create('prod.staffing_requests', function (Blueprint $table) {
                $table->id('staffing_request_id');
                $table->uuid('request_uuid')->unique();
                $table->foreignId('unit_id')->nullable()->constrained('prod.units', 'unit_id')->nullOnDelete();
                $table->foreignId('staffing_plan_id')->nullable()->constrained('prod.staffing_plans', 'staffing_plan_id')->nullOnDelete();
                $table->string('unit_label', 120);
                $table->string('role', 40);
                $table->date('shift_date');
                $table->string('shift', 20)->default('day');
                $table->string('request_type', 40)->default('fill_gap');
                $table->string('priority', 20)->default('routine');
                $table->string('status', 40)->default('requested');
                $table->integer('headcount_needed')->default(1);
                $table->decimal('hours_needed', 6, 2)->nullable();
                $table->string('requested_by', 120)->nullable();
                $table->timestamp('needed_by')->nullable();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('filled_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->string('assigned_source', 60)->nullable();
                $table->string('assigned_staff_ref', 120)->nullable();
                $table->string('owner_name', 120)->nullable();
                $table->jsonb('risk_flags')->nullable();
                $table->jsonb('resolution_payload')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->unsignedBigInteger('updated_by_user_id')->nullable();
                $table->boolean('is_deleted')->default(false);

                $table->index(['status', 'needed_by']);
                $table->index(['priority', 'needed_by']);
                $table->index(['role', 'status']);
                $table->index(['unit_id', 'status']);
                $table->index(['shift_date', 'shift']);
            });
        }

        // Append-only audit trail for staffing-request transitions.
        if (! Schema::hasTable('prod.staffing_events')) {
            Schema::create('prod.staffing_events', function (Blueprint $table) {
                $table->id('staffing_event_id');
                $table->uuid('event_uuid')->unique();
                $table->foreignId('staffing_request_id')
                    ->constrained('prod.staffing_requests', 'staffing_request_id')
                    ->cascadeOnDelete();
                $table->string('event_type', 120);
                $table->string('from_status', 40)->nullable();
                $table->string('to_status', 40)->nullable();
                $table->jsonb('payload')->nullable();
                $table->string('source', 120)->default('zephyrus');
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->timestamp('occurred_at')->useCurrent();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['staffing_request_id', 'occurred_at']);
                $table->index(['event_type', 'occurred_at']);
                $table->index('source');
            });
        }

        DB::statement("ALTER TABLE prod.staffing_plans ADD CONSTRAINT chk_staffing_plan_role CHECK (role IN ('rn','lpn','tech','charge','provider','respiratory','unit_secretary'))");
        DB::statement("ALTER TABLE prod.staffing_plans ADD CONSTRAINT chk_staffing_plan_shift CHECK (shift IN ('day','evening','night'))");
        DB::statement("ALTER TABLE prod.staffing_plans ADD CONSTRAINT chk_staffing_plan_status CHECK (status IN ('balanced','watch','gap','critical_gap','surplus'))");

        DB::statement("ALTER TABLE prod.staffing_requests ADD CONSTRAINT chk_staffing_request_role CHECK (role IN ('rn','lpn','tech','charge','provider','respiratory','unit_secretary'))");
        DB::statement("ALTER TABLE prod.staffing_requests ADD CONSTRAINT chk_staffing_request_shift CHECK (shift IN ('day','evening','night'))");
        DB::statement("ALTER TABLE prod.staffing_requests ADD CONSTRAINT chk_staffing_request_type CHECK (request_type IN ('fill_gap','float','overtime','agency','on_call','reassign'))");
        DB::statement("ALTER TABLE prod.staffing_requests ADD CONSTRAINT chk_staffing_request_priority CHECK (priority IN ('routine','urgent','stat'))");
        DB::statement("ALTER TABLE prod.staffing_requests ADD CONSTRAINT chk_staffing_request_status CHECK (status IN ('requested','open','sourcing','assigned','filled','completed','canceled','escalated','unfilled'))");
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.staffing_events');
        $this->safeDropIfExists('prod.staffing_requests');
        $this->safeDropIfExists('prod.staffing_plans');
    }
};
