<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        $this->call([
            UserSeeder::class,
            CaseManagementSeeder::class, // Run this before TestDataSeeder since it sets up reference data
            TestDataSeeder::class,
        ]);
    }
}
