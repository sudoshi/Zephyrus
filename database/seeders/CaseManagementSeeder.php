<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ORCase;
use App\Models\CareJourneyMilestone;
use App\Models\CaseTransport;
use App\Models\CaseTiming;
use App\Models\CaseSafetyNote;
use App\Models\User;
use App\Models\Provider;
use App\Models\Room;
use App\Models\Reference\Service;
use Carbon\Carbon;

class CaseManagementSeeder extends Seeder
{
    public function run()
    {
        // Load CSV data
        $surgicalCases = $this->loadCsv(base_path('sample-pages/surgical_cases.csv'));
        $procedures = $this->loadCsv(base_path('sample-pages/procedures.csv'));
        $staff = $this->loadCsv(base_path('sample-pages/staff.csv'));

        // Create sample users for staff
        foreach ($staff as $member) {
            User::firstOrCreate(
                ['email' => strtolower(str_replace(' ', '.', $member['name'])) . '@hospital.com'],
                [
                    'name' => $member['name'],
                    'password' => bcrypt('password'),
                ]
            );
        }

        // Create specialties if needed
        $specialties = [];
        foreach ($staff as $member) {
            if (!empty($member['specialty']) && !isset($specialties[$member['specialty']])) {
                $specialty = \App\Models\Reference\Specialty::firstOrCreate([
                    'name' => $member['specialty']
                ], [
                    'code' => strtoupper(str_replace(' ', '_', $member['specialty'])),
                    'active_status' => true,
                    'is_deleted' => false
                ]);
                $specialties[$member['specialty']] = $specialty->specialty_id;
            }
        }

        // Create sample providers
        $providers = Provider::all();
        if ($providers->isEmpty()) {
            foreach ($staff as $member) {
                if ($member['role'] === 'Surgeon' || $member['role'] === 'Anesthesiologist') {
                    Provider::create([
                        'name' => $member['name'],
                        'type' => strtolower($member['role']),
                        'specialty_id' => $specialties[$member['specialty']] ?? null,
                        'npi' => 'NPI' . str_pad(rand(1, 999999), 6, '0', STR_PAD_LEFT),
                        'active_status' => true,
                        'is_deleted' => false
                    ]);
                }
            }
        }

        // Create sample rooms if needed
        $rooms = Room::all();
        if ($rooms->isEmpty()) {
            $roomTypes = [
                ['name' => 'OR 1', 'unit_type' => 'OR', 'capacity' => 1],
                ['name' => 'OR 2', 'unit_type' => 'OR', 'capacity' => 1],
                ['name' => 'OR 3', 'unit_type' => 'OR', 'capacity' => 1],
                ['name' => 'Cath Lab 1', 'unit_type' => 'cath_lab', 'capacity' => 1],
                ['name' => 'Cath Lab 2', 'unit_type' => 'cath_lab', 'capacity' => 1],
                ['name' => 'Pre-Op 1', 'unit_type' => 'pre_op', 'capacity' => 2],
                ['name' => 'Pre-Op 2', 'unit_type' => 'pre_op', 'capacity' => 2],
                ['name' => 'Recovery 1', 'unit_type' => 'post_op', 'capacity' => 3],
                ['name' => 'Recovery 2', 'unit_type' => 'post_op', 'capacity' => 3],
            ];

            foreach ($roomTypes as $room) {
                Room::create($room);
            }
        }

        // Create sample services if needed
        $services = Service::all();
        if ($services->isEmpty()) {
            $serviceTypes = [
                ['name' => 'General Surgery', 'color_code' => 'info'],
                ['name' => 'Orthopedics', 'color_code' => 'success'],
                ['name' => 'OBGYN', 'color_code' => 'warning'],
                ['name' => 'Cardiac', 'color_code' => 'error'],
                ['name' => 'Cath Lab', 'color_code' => 'primary'],
            ];

            foreach ($serviceTypes as $service) {
                Service::create($service);
            }
        }

        // Create cases with related data
        foreach ($surgicalCases as $case) {
            $provider = Provider::inRandomOrder()->first();
            $room = Room::where('unit_type', 'OR')->inRandomOrder()->first();
            $service = Service::inRandomOrder()->first();

            $orCase = ORCase::create([
                'patient_name' => "Patient " . $case['mrn'],
                'procedure_name' => $procedures[rand(0, count($procedures) - 1)]['name'],
                'provider_id' => $provider->id,
                'room_id' => $room->id,
                'service_id' => $service->id,
                'status' => $case['status'],
                'phase' => $case['phase'],
                'scheduled_date' => Carbon::today(),
                'scheduled_start_time' => $case['scheduled_start_time'],
                'expected_duration' => $case['expected_duration'],
                'progress_percentage' => $case['journey_progress'],
                'resource_status' => $case['resource_status'],
                'pre_procedure_location' => 'Pre-Op ' . rand(1, 2),
                'post_procedure_location' => 'Recovery ' . rand(1, 2),
                'safety_status' => 'Normal'
            ]);

            // Add milestones
            foreach (['H&P', 'Consent', 'Labs', 'Safety_Check'] as $type) {
                $orCase->addMilestone($type, true);
            }

            // Add transports
            $orCase->scheduleTransport(
                'Pre_Procedure',
                $orCase->pre_procedure_location,
                $room->name,
                Carbon::parse($case['scheduled_start_time'])->subMinutes(30)
            );

            $orCase->scheduleTransport(
                'Post_Procedure',
                $room->name,
                $orCase->post_procedure_location,
                Carbon::parse($case['scheduled_start_time'])->addMinutes($case['expected_duration'])
            );

            // Add timings
            $orCase->recordTiming(
                'Pre_Procedure',
                Carbon::parse($case['scheduled_start_time'])->subHours(2),
                90
            );

            $orCase->recordTiming(
                'Procedure',
                Carbon::parse($case['scheduled_start_time']),
                $case['expected_duration']
            );

            $orCase->recordTiming(
                'Recovery',
                Carbon::parse($case['scheduled_start_time'])->addMinutes($case['expected_duration']),
                60
            );

            // Add sample safety notes
            if (rand(0, 10) > 7) {
                $orCase->addSafetyNote(
                    'Sample safety concern',
                    CaseSafetyNote::TYPE_SAFETY_ALERT,
                    CaseSafetyNote::SEVERITY_MEDIUM,
                    User::inRandomOrder()->first()->id
                );
            }

            // Assign random staff
            $staffRoles = ['Surgeon', 'Anesthesiologist', 'Nurse'];
            foreach ($staffRoles as $role) {
                $staffMember = User::inRandomOrder()->first();
                $orCase->assignStaff($staffMember->id, $role);
            }
        }
    }

    private function loadCsv($path)
    {
        $data = [];
        if (($handle = fopen($path, "r")) !== false) {
            $headers = fgetcsv($handle);
            while (($row = fgetcsv($handle)) !== false) {
                $data[] = array_combine($headers, $row);
            }
            fclose($handle);
        }
        return $data;
    }
}
