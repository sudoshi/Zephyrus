<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('prod.users')->insert([
            'name' => 'Kartheek',
            'email' => 'kartheek@example.com',
            'username' => 'kartheek',
            'password' => Hash::make('kartheek'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('prod.users')->where('username', 'kartheek')->delete();
    }
};
