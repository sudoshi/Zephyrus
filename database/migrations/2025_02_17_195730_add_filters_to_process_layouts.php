<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('process_layouts', function (Blueprint $table) {
            $table->string('hospital')->default('Virtua Marlton Hospital');
            $table->string('workflow')->default('Admissions');
            $table->string('time_range')->default('24 Hours');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('process_layouts', function (Blueprint $table) {
            $table->dropColumn('hospital');
            $table->dropColumn('workflow');
            $table->dropColumn('time_range');
        });
    }
};
