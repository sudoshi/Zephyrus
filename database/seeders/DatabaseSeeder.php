<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        $this->call([
            UserSeeder::class,
            CaseManagementSeeder::class, // Case reference data (case_statuses).
            // RtdcSeeder is the sole creator of prod.units + prod.beds. It MUST run
            // before CommandCenterDemoSeeder, whose per-unit loops only read/tune
            // units and silently no-op when units are absent. (Previously RtdcSeeder
            // ran only via the rtdc:demo-reset command, so a bare `db:seed` left the
            // RTDC spine empty.) RtdcSeeder is idempotent (firstOrCreate).
            RtdcSeeder::class,
            // CommandCenterDemoSeeder owns the full reference set (locations,
            // specialties, services, providers, rooms, ASA/case-type/class) AND the
            // operational demo data. (TestDataSeeder was removed from the chain: its
            // services/rooms/providers were redundant with CCDS and it failed on a
            // pristine DB by referencing prod.locations/specialties before CCDS
            // creates them.)
            CommandCenterDemoSeeder::class,
            ImprovementDemoSeeder::class,
        ]);
    }
}
