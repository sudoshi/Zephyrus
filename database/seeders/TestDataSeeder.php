<?php

namespace Database\Seeders;

use App\Models\Provider;
use App\Models\Reference\Service;
use App\Models\Room;
use Illuminate\Database\Seeder;

class TestDataSeeder extends Seeder
{
    public function run()
    {
        // Create test services first
        $services = [
            ['name' => 'General Surgery', 'code' => 'GS'],
            ['name' => 'Orthopedics', 'code' => 'ORTHO'],
            ['name' => 'Cardiology', 'code' => 'CARD'],
            ['name' => 'Neurosurgery', 'code' => 'NEURO'],
        ];

        // Idempotent throughout: firstOrCreate keyed on natural keys so a repeated
        // `php artisan db:seed` neither duplicates rows nor throws. (Previously these
        // used create(); providers also omitted the NOT-NULL UNIQUE npi column, which
        // broke `db:seed` entirely on any already-populated database.)
        $serviceIds = [];
        foreach ($services as $service) {
            $newService = Service::firstOrCreate(
                ['code' => $service['code']],
                [
                    'name' => $service['name'],
                    'active_status' => true,
                    'created_by' => 'system',
                    'modified_by' => 'system',
                    'is_deleted' => false,
                ]
            );
            $serviceIds[] = $newService->service_id;
        }

        // Create test rooms
        $rooms = [
            ['name' => 'OR-1', 'type' => 'OR'],
            ['name' => 'OR-2', 'type' => 'OR'],
            ['name' => 'OR-3', 'type' => 'OR'],
            ['name' => 'OR-4', 'type' => 'OR'],
        ];

        $roomIds = [];
        foreach ($rooms as $room) {
            $newRoom = Room::firstOrCreate(
                ['name' => $room['name'], 'location_id' => 1],
                [
                    'type' => $room['type'],
                    'active_status' => true,
                    'created_by' => 'system',
                    'modified_by' => 'system',
                    'is_deleted' => false,
                ]
            );
            $roomIds[] = $newRoom->room_id;
        }

        // Create test providers. npi is NOT NULL + UNIQUE — supply a deterministic
        // value and key firstOrCreate on it so re-seeds match the existing rows.
        $providers = [
            ['name' => 'Dr. Smith', 'provider_type' => 'surgeon', 'specialty_id' => 1, 'npi' => '1000000001'],
            ['name' => 'Dr. Johnson', 'provider_type' => 'surgeon', 'specialty_id' => 2, 'npi' => '1000000002'],
            ['name' => 'Dr. Williams', 'provider_type' => 'surgeon', 'specialty_id' => 3, 'npi' => '1000000003'],
            ['name' => 'Dr. Brown', 'provider_type' => 'surgeon', 'specialty_id' => 4, 'npi' => '1000000004'],
        ];

        $providerIds = [];
        foreach ($providers as $provider) {
            $newProvider = Provider::firstOrCreate(
                ['npi' => $provider['npi']],
                [
                    'name' => $provider['name'],
                    'type' => $provider['provider_type'],
                    'specialty_id' => $provider['specialty_id'],
                    'active_status' => true,
                    'created_by' => 'system',
                    'modified_by' => 'system',
                    'is_deleted' => false,
                ]
            );
            $providerIds[] = $newProvider->provider_id;
        }

        // OR cases / OR logs are intentionally NOT seeded here. CommandCenterDemoSeeder
        // is the authoritative perioperative case seeder (100 cases, ~20 today, with
        // correct ASA/case-type/cancellation references and rich OR-log timings). The
        // legacy block here had drifted from the current prod.or_cases schema (phantom
        // procedure_name column, missing NOT-NULL asa_rating_id) and depended on
        // reference data seeded later in the chain — so it broke `db:seed` outright and
        // duplicated CommandCenterDemoSeeder's dataset. This seeder now owns only the
        // service/room/provider reference rows above.
    }
}
