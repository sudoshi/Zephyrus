<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Traits\SafeMigration;

return new class extends Migration
{
    use SafeMigration;
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
        if (!Schema::hasColumn('prod.or_cases', 'pre_procedure_location')) {
            Schema::table('prod.or_cases', function (Blueprint $table) {
                $table->string('pre_procedure_location')->nullable();
            });
        }
        if (!Schema::hasColumn('prod.or_cases', 'post_procedure_location')) {
            Schema::table('prod.or_cases', function (Blueprint $table) {
                $table->string('post_procedure_location')->nullable();
            });
        }
        if (!Schema::hasColumn('prod.or_cases', 'safety_status')) {
            Schema::table('prod.or_cases', function (Blueprint $table) {
                $table->string('safety_status')->default('Normal');
            });
        }
        if (!Schema::hasColumn('prod.or_cases', 'journey_progress')) {
            Schema::table('prod.or_cases', function (Blueprint $table) {
                $table->integer('journey_progress')->default(0);
            });
        }

        // Create care journey milestones table
        if (!Schema::hasTable('prod.care_journey_milestones')) {
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
        }

        // Create case transport table
        if (!Schema::hasTable('prod.case_transport')) {
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
        }

        // Create case timings table
        if (!Schema::hasTable('prod.case_timings')) {
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
        }

        // Create case safety notes table
        if (!Schema::hasTable('prod.case_safety_notes')) {
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
        }

        // Create case staff table
        if (!Schema::hasTable('prod.case_staff')) {
            Schema::create('prod.case_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('prod.or_cases', 'case_id')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('role');
            $table->timestamps();
        });
        }

        // Add constraints if they don't exist
        if (!$this->constraintExists('prod.or_cases', 'check_safety_status')) {
            DB::statement("ALTER TABLE prod.or_cases ADD CONSTRAINT check_safety_status 
                CHECK (safety_status IN ('Normal', 'Review_Required', 'Alert'))");
        }

        if (!$this->constraintExists('prod.case_safety_notes', 'check_note_type')) {
            DB::statement("ALTER TABLE prod.case_safety_notes ADD CONSTRAINT check_note_type 
                CHECK (note_type IN ('Safety_Alert', 'Barrier', 'General'))");
        }

        if (!$this->constraintExists('prod.case_safety_notes', 'check_severity')) {
            DB::statement("ALTER TABLE prod.case_safety_notes ADD CONSTRAINT check_severity 
                CHECK (severity IN ('Low', 'Medium', 'High', 'Critical'))");
        }

        // Create indexes if they don't exist
        if (!$this->indexExists('prod.care_journey_milestones', 'prod_care_journey_milestones_case_id_status_index')) {
            Schema::table('prod.care_journey_milestones', function (Blueprint $table) {
                $table->index(['case_id', 'status']);
            });
        }
        if (!$this->indexExists('prod.care_journey_milestones', 'prod_care_journey_milestones_milestone_type_status_index')) {
            Schema::table('prod.care_journey_milestones', function (Blueprint $table) {
                $table->index(['milestone_type', 'status']);
            });
        }

        if (!$this->indexExists('prod.case_transport', 'prod_case_transport_case_id_status_index')) {
            Schema::table('prod.case_transport', function (Blueprint $table) {
                $table->index(['case_id', 'status']);
            });
        }
        if (!$this->indexExists('prod.case_transport', 'prod_case_transport_planned_time_index')) {
            Schema::table('prod.case_transport', function (Blueprint $table) {
                $table->index(['planned_time']);
            });
        }
        if (!$this->indexExists('prod.case_transport', 'prod_case_transport_assigned_to_status_index')) {
            Schema::table('prod.case_transport', function (Blueprint $table) {
                $table->index(['assigned_to', 'status']);
            });
        }

        if (!$this->indexExists('prod.case_timings', 'prod_case_timings_case_id_phase_index')) {
            Schema::table('prod.case_timings', function (Blueprint $table) {
                $table->index(['case_id', 'phase']);
            });
        }
        if (!$this->indexExists('prod.case_timings', 'prod_case_timings_planned_start_index')) {
            Schema::table('prod.case_timings', function (Blueprint $table) {
                $table->index(['planned_start']);
            });
        }

        if (!$this->indexExists('prod.case_safety_notes', 'prod_case_safety_notes_case_id_severity_index')) {
            Schema::table('prod.case_safety_notes', function (Blueprint $table) {
                $table->index(['case_id', 'severity']);
            });
        }
        if (!$this->indexExists('prod.case_safety_notes', 'idx_unacknowledged_notes')) {
            Schema::table('prod.case_safety_notes', function (Blueprint $table) {
                $table->index(['acknowledged_at'], 'idx_unacknowledged_notes');
            });
        }
    }

    public function down()
    {
        if ($this->isLocalEnvironment()) {
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
        }

        // Drop tables
        $this->safeDropIfExists('prod.case_staff');
        $this->safeDropIfExists('prod.case_safety_notes');
        $this->safeDropIfExists('prod.case_timings');
        $this->safeDropIfExists('prod.case_transport');
        $this->safeDropIfExists('prod.care_journey_milestones');

        if ($this->isLocalEnvironment()) {
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
    }
};
