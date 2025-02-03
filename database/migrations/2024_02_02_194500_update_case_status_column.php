<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Add status_id column if it doesn't exist
        if (!Schema::hasColumn('prod.or_cases', 'status_id')) {
            Schema::table('prod.or_cases', function (Blueprint $table) {
                $table->integer('status_id')->nullable();
                $table->foreign('status_id')
                    ->references('status_id')
                    ->on('prod.case_statuses')
                    ->onDelete('set null');
            });
        }

        // Set default status_id to 1 (Scheduled)
        DB::statement("
            UPDATE prod.or_cases
            SET status_id = 1
            WHERE status_id IS NULL
        ");

        // Make status_id required after data migration
        Schema::table('prod.or_cases', function (Blueprint $table) {
            $table->integer('status_id')->nullable(false)->change();
        });
    }

    public function down()
    {
        // Drop status_id column if it exists
        if (Schema::hasColumn('prod.or_cases', 'status_id')) {
            Schema::table('prod.or_cases', function (Blueprint $table) {
                $table->dropForeign(['status_id']);
                $table->dropColumn('status_id');
            });
        }
    }
};
