<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Create enums for milestone types and statuses
        DB::statement("DO $$ 
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'milestone_type_enum') THEN
                CREATE TYPE milestone_type_enum AS ENUM (
                    'H&P', 'Consent', 'Labs', 'Safety_Check', 'Transport'
                );
            END IF;
        END $$;");

        DB::statement("DO $$ 
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'milestone_status_enum') THEN
                CREATE TYPE milestone_status_enum AS ENUM (
                    'Pending', 'In_Progress', 'Completed', 'Verified', 'Action_Required'
                );
            END IF;
        END $$;");

        // Add new columns to or_cases table
        Schema::table('prod.or_cases', function (Blueprint $table) {
            $table->string('pre_procedure_location')->nullable();
            $table->string('post_procedure_location')->nullable();
            $table->string('safety_status')->default('Normal');
            $table->integer('journey_progress')->default(0);
        });

        // Create care journey milestones table
        Schema::create('prod.care_journey_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('prod.or_cases', 'case_id')->onDelete('cascade');
            $table->string('milestone_type');
            $table->string('status');
            $table->boolean('required')->default(true);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Create case transport table
        Schema::create('prod.case_transport', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('prod.or_cases', 'case_id')->onDelete('cascade');
            $table->string('transport_type');
            $table->string('status')->default('Pending');
            $table->string('location_from');
            $table->string('location_to');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('planned_time');
            $table->timestamp('actual_start')->nullable();
            $table->timestamp('actual_end')->nullable();
            $table->timestamps();
        });

        // Create case timings table
        Schema::create('prod.case_timings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('prod.or_cases', 'case_id')->onDelete('cascade');
            $table->string('phase');
            $table->timestamp('planned_start');
            $table->integer('planned_duration');
            $table->timestamp('actual_start')->nullable();
            $table->integer('actual_duration')->nullable();
            $table->integer('variance')->nullable();
            $table->timestamps();
        });

        // Create case safety notes table
        Schema::create('prod.case_safety_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('prod.or_cases', 'case_id')->onDelete('cascade');
            $table->string('note_type');
            $table->text('content');
            $table->string('severity');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
        });

        // Create case staff table
        Schema::create('prod.case_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('prod.or_cases', 'case_id')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('role');
            $table->timestamps();
        });

        // Add constraints
        DB::statement("ALTER TABLE prod.or_cases ADD CONSTRAINT check_safety_status 
            CHECK (safety_status IN ('Normal', 'Review_Required', 'Alert'))");

        DB::statement("ALTER TABLE prod.case_safety_notes ADD CONSTRAINT check_note_type 
            CHECK (note_type IN ('Safety_Alert', 'Barrier', 'General'))");

        DB::statement("ALTER TABLE prod.case_safety_notes ADD CONSTRAINT check_severity 
            CHECK (severity IN ('Low', 'Medium', 'High', 'Critical'))");

        // Create indexes
        Schema::table('prod.care_journey_milestones', function (Blueprint $table) {
            $table->index(['case_id', 'status']);
            $table->index(['milestone_type', 'status']);
        });

        Schema::table('prod.case_transport', function (Blueprint $table) {
            $table->index(['case_id', 'status']);
            $table->index(['planned_time']);
            $table->index(['assigned_to', 'status']);
        });

        Schema::table('prod.case_timings', function (Blueprint $table) {
            $table->index(['case_id', 'phase']);
            $table->index(['planned_start']);
        });

        Schema::table('prod.case_safety_notes', function (Blueprint $table) {
            $table->index(['case_id', 'severity']);
            $table->index(['acknowledged_at'], 'idx_unacknowledged_notes');
        });
    }

    public function down()
    {
        // Drop indexes first
        Schema::table('prod.care_journey_milestones', function (Blueprint $table) {
            $table->dropIndex(['case_id', 'status']);
            $table->dropIndex(['milestone_type', 'status']);
        });

        Schema::table('prod.case_transport', function (Blueprint $table) {
            $table->dropIndex(['case_id', 'status']);
            $table->dropIndex(['planned_time']);
            $table->dropIndex(['assigned_to', 'status']);
        });

        Schema::table('prod.case_timings', function (Blueprint $table) {
            $table->dropIndex(['case_id', 'phase']);
            $table->dropIndex(['planned_start']);
        });

        Schema::table('prod.case_safety_notes', function (Blueprint $table) {
            $table->dropIndex(['case_id', 'severity']);
            $table->dropIndex('idx_unacknowledged_notes');
        });

        // Drop tables
        Schema::dropIfExists('prod.case_staff');
        Schema::dropIfExists('prod.case_safety_notes');
        Schema::dropIfExists('prod.case_timings');
        Schema::dropIfExists('prod.case_transport');
        Schema::dropIfExists('prod.care_journey_milestones');

        // Drop columns from or_cases
        Schema::table('prod.or_cases', function (Blueprint $table) {
            $table->dropColumn([
                'pre_procedure_location',
                'post_procedure_location',
                'safety_status',
                'journey_progress'
            ]);
        });

        // Drop enums
        DB::statement('DROP TYPE IF EXISTS milestone_type_enum');
        DB::statement('DROP TYPE IF EXISTS milestone_status_enum');
    }
};
