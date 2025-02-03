<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // ORCase
        Schema::create('prod.or_cases', function (Blueprint $table) {
            $table->id('case_id');
            $table->string('patient_id');
            $table->date('surgery_date');
            $table->foreignId('room_id')->constrained('prod.rooms', 'room_id');
            $table->foreignId('location_id')->constrained('prod.locations', 'location_id');
            $table->foreignId('primary_surgeon_id')->constrained('prod.providers', 'provider_id');
            $table->foreignId('case_service_id')->constrained('prod.services', 'service_id');
            $table->dateTime('scheduled_start_time');
            $table->integer('scheduled_duration');
            $table->timestamp('record_create_date');
            $table->foreignId('status_id')->constrained('prod.case_statuses', 'status_id');
            $table->foreignId('cancellation_reason_id')->nullable()->constrained('prod.cancellation_reasons', 'cancellation_id');
            $table->foreignId('asa_rating_id')->constrained('prod.asa_ratings', 'asa_id');
            $table->foreignId('case_type_id')->constrained('prod.case_types', 'case_type_id');
            $table->foreignId('case_class_id')->constrained('prod.case_classes', 'case_class_id');
            $table->foreignId('patient_class_id')->constrained('prod.patient_classes', 'patient_class_id');
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
        });

        // ORLog
        Schema::create('prod.or_logs', function (Blueprint $table) {
            $table->id('log_id');
            $table->foreignId('case_id')->constrained('prod.or_cases', 'case_id');
            $table->date('tracking_date');
            $table->dateTime('periop_arrival_time')->nullable();
            $table->dateTime('preop_in_time')->nullable();
            $table->dateTime('preop_out_time')->nullable();
            $table->dateTime('or_in_time')->nullable();
            $table->dateTime('anesthesia_start_time')->nullable();
            $table->dateTime('procedure_start_time')->nullable();
            $table->dateTime('procedure_closing_time')->nullable();
            $table->dateTime('procedure_end_time')->nullable();
            $table->dateTime('or_out_time')->nullable();
            $table->dateTime('anesthesia_end_time')->nullable();
            $table->dateTime('pacu_in_time')->nullable();
            $table->dateTime('pacu_out_time')->nullable();
            $table->string('destination')->nullable();
            $table->integer('number_of_panels')->nullable();
            $table->string('primary_procedure');
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
        });

        // CaseMetrics
        Schema::create('prod.case_metrics', function (Blueprint $table) {
            $table->foreignId('case_id')->primary()->constrained('prod.or_cases', 'case_id');
            $table->integer('turnover_time')->nullable();
            $table->decimal('utilization_percentage', 5, 2)->nullable();
            $table->integer('in_block_time')->nullable();
            $table->integer('out_of_block_time')->nullable();
            $table->integer('prime_time_minutes')->nullable();
            $table->integer('non_prime_time_minutes')->nullable();
            $table->integer('late_start_minutes')->nullable();
            $table->integer('early_finish_minutes')->nullable();
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
        });

        // BlockUtilization
        Schema::create('prod.block_utilization', function (Blueprint $table) {
            $table->id();
            $table->foreignId('block_id')->constrained('prod.block_templates', 'block_id');
            $table->date('date');
            $table->foreignId('service_id')->constrained('prod.services', 'service_id');
            $table->foreignId('location_id')->constrained('prod.locations', 'location_id');
            $table->integer('scheduled_minutes');
            $table->integer('actual_minutes');
            $table->decimal('utilization_percentage', 5, 2);
            $table->integer('cases_scheduled');
            $table->integer('cases_performed');
            $table->decimal('prime_time_percentage', 5, 2);
            $table->decimal('non_prime_time_percentage', 5, 2);
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
        });

        // RoomUtilization
        Schema::create('prod.room_utilization', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('prod.rooms', 'room_id');
            $table->date('date');
            $table->integer('available_minutes');
            $table->integer('utilized_minutes');
            $table->integer('turnover_minutes');
            $table->decimal('utilization_percentage', 5, 2);
            $table->integer('cases_performed');
            $table->integer('avg_case_duration');
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
        });
    }

    public function down()
    {
        // Only drop tables in prod schema
        Schema::dropIfExists('prod.room_utilization');
        Schema::dropIfExists('prod.block_utilization');
        Schema::dropIfExists('prod.case_metrics');
        Schema::dropIfExists('prod.or_logs');
        Schema::dropIfExists('prod.or_cases');
    }
};
