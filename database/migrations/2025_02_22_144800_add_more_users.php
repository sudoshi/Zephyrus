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
        $users = [
            [
                'name' => 'Sanjay',
                'email' => 'sanjay@example.com',
                'username' => 'sanjay',
                'password' => Hash::make('sanjay'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Darshan',
                'email' => 'darshan@example.com',
                'username' => 'darshan',
                'password' => Hash::make('darshan'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Dev Sheth',
                'email' => 'devsheth@example.com',
                'username' => 'devsheth',
                'password' => Hash::make('devsheth'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($users as $user) {
            DB::table('prod.users')->insert($user);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('prod.users')
            ->whereIn('username', ['sanjay', 'darshan', 'devsheth'])
            ->delete();
    }
};
