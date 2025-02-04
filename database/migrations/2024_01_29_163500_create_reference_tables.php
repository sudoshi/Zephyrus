<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Service
        if (!Schema::hasTable('prod.services')) {
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
        }

        // CaseType
        if (!Schema::hasTable('prod.case_types')) {
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
        }

        // CaseClass
        if (!Schema::hasTable('prod.case_classes')) {
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
        }

        // PatientClass
        if (!Schema::hasTable('prod.patient_classes')) {
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
        }

        // CaseStatus
        if (!Schema::hasTable('prod.case_statuses')) {
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
        }

        // CancellationReason
        if (!Schema::hasTable('prod.cancellation_reasons')) {
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
        }

        // ASARating
        if (!Schema::hasTable('prod.asa_ratings')) {
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
        }

        // Specialty
        if (!Schema::hasTable('prod.specialties')) {
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
    }

    public function down()
    {
        // Only drop tables in prod schema
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
