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
            // RtdcSeeder is the sole creator of prod.units + prod.beds. It MUST run
            // before CommandCenterDemoSeeder, whose per-unit loops only read/tune
            // units and silently no-op when units are absent. (Previously RtdcSeeder
            // ran only via the rtdc:demo-reset command, so a bare `db:seed` left the
            // RTDC spine empty.) RtdcSeeder is now idempotent (firstOrCreate).
            RtdcSeeder::class,
            CommandCenterDemoSeeder::class,
        ]);
    }
}
