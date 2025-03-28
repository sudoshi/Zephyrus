<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update existing records to 'superuser'
        DB::table('prod.users')->update(['workflow_preference' => 'superuser']);

        // Change the default value for future records
        Schema::table('prod.users', function (Blueprint $table) {
            $table->string('workflow_preference')->default('superuser')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Change the default value back to 'perioperative'
        Schema::table('prod.users', function (Blueprint $table) {
            $table->string('workflow_preference')->default('perioperative')->change();
        });

        // Update existing records back to 'perioperative'
        DB::table('prod.users')->update(['workflow_preference' => 'perioperative']);
    }
};
