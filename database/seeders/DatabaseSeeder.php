<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::create([
            'name' => 'acumenus',
            'email' => 'acumenus@example.com',
            'password' => bcrypt('acumenus'),
            'email_verified_at' => now()
        ]);

        $this->call([
            CaseManagementSeeder::class
        ]);
    }
}
