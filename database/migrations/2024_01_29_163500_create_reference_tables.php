<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Service
        Schema::create('prod.services', function (Blueprint $table) {
            $table->id('service_id');
            $table->string('name');
            $table->string('code');
            $table->boolean('active_status')->default(true);
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();
        });

        // CaseType
        Schema::create('prod.case_types', function (Blueprint $table) {
            $table->id('case_type_id');
            $table->string('name');
            $table->string('code');
            $table->boolean('active_status')->default(true);
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
        });

        // CaseClass
        Schema::create('prod.case_classes', function (Blueprint $table) {
            $table->id('case_class_id');
            $table->string('name');
            $table->string('code');
            $table->boolean('active_status')->default(true);
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
        });

        // PatientClass
        Schema::create('prod.patient_classes', function (Blueprint $table) {
            $table->id('patient_class_id');
            $table->string('name');
            $table->string('code');
            $table->boolean('active_status')->default(true);
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
        });

        // CaseStatus
        Schema::create('prod.case_statuses', function (Blueprint $table) {
            $table->id('status_id');
            $table->string('name');
            $table->string('code');
            $table->boolean('active_status')->default(true);
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
        });

        // CancellationReason
        Schema::create('prod.cancellation_reasons', function (Blueprint $table) {
            $table->id('cancellation_id');
            $table->string('name');
            $table->string('code');
            $table->boolean('active_status')->default(true);
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
        });

        // ASARating
        Schema::create('prod.asa_ratings', function (Blueprint $table) {
            $table->id('asa_id');
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
        });

        // Specialty
        Schema::create('prod.specialties', function (Blueprint $table) {
            $table->id('specialty_id');
            $table->string('name');
            $table->string('code');
            $table->boolean('active_status')->default(true);
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
        });
    }

    public function down()
    {
        Schema::dropIfExists('prod.specialties');
        Schema::dropIfExists('prod.asa_ratings');
        Schema::dropIfExists('prod.cancellation_reasons');
        Schema::dropIfExists('prod.case_statuses');
        Schema::dropIfExists('prod.patient_classes');
        Schema::dropIfExists('prod.case_classes');
        Schema::dropIfExists('prod.case_types');
        Schema::dropIfExists('prod.services');
    }
};
