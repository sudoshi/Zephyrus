<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update any users that don't have a username with their email prefix
        DB::statement("
            UPDATE prod.users 
            SET username = SPLIT_PART(email, '@', 1)
            WHERE username IS NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot reliably reverse this operation
    }
};
