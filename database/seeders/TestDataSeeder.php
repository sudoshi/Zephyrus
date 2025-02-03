<?php

namespace Database\Seeders;

use App\Models\ORCase;
use App\Models\ORLog;
use App\Models\Provider;
use App\Models\Room;
use App\Models\Reference\Service;
use App\Models\Reference\CaseStatus;
use App\Models\Reference\CaseType;
use App\Models\Reference\CaseClass;
use App\Models\Reference\PatientClass;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class TestDataSeeder extends Seeder
{
    public function run()
    {
        // Create test services first
        $services = [
            ['name' => 'General Surgery', 'code' => 'GS'],
            ['name' => 'Orthopedics', 'code' => 'ORTHO'],
            ['name' => 'Cardiology', 'code' => 'CARD'],
            ['name' => 'Neurosurgery', 'code' => 'NEURO']
        ];

        $serviceIds = [];
        foreach ($services as $service) {
            $newService = Service::create([
                'name' => $service['name'],
                'code' => $service['code'],
                'active_status' => true,
                'created_by' => 'system',
                'modified_by' => 'system',
                'created_at' => now(),
                'updated_at' => now(),
                'is_deleted' => false
            ]);
            $serviceIds[] = $newService->service_id;
        }

        // Create test rooms
        $rooms = [
            ['name' => 'OR-1', 'type' => 'OR'],
            ['name' => 'OR-2', 'type' => 'OR'],
            ['name' => 'OR-3', 'type' => 'OR'],
            ['name' => 'OR-4', 'type' => 'OR']
        ];

        $roomIds = [];
        foreach ($rooms as $room) {
            $newRoom = Room::create([
                'location_id' => 1,
                'name' => $room['name'],
                'type' => $room['type'],
                'active_status' => true,
                'created_by' => 'system',
                'modified_by' => 'system',
                'created_at' => now(),
                'updated_at' => now(),
                'is_deleted' => false
            ]);
            $roomIds[] = $newRoom->room_id;
        }

        // Create test providers
        $providers = [
            ['name' => 'Dr. Smith', 'provider_type' => 'surgeon', 'specialty_id' => 1],
            ['name' => 'Dr. Johnson', 'provider_type' => 'surgeon', 'specialty_id' => 2],
            ['name' => 'Dr. Williams', 'provider_type' => 'surgeon', 'specialty_id' => 3],
            ['name' => 'Dr. Brown', 'provider_type' => 'surgeon', 'specialty_id' => 4]
        ];

        $providerIds = [];
        foreach ($providers as $provider) {
            $newProvider = Provider::create([
                'name' => $provider['name'],
                'type' => $provider['provider_type'],
                'specialty_id' => $provider['specialty_id'],
                'active_status' => true,
                'created_by' => 'system',
                'modified_by' => 'system',
                'created_at' => now(),
                'updated_at' => now(),
                'is_deleted' => false
            ]);
            $providerIds[] = $newProvider->provider_id;
        }

        // Get status IDs
        $statusMap = [
            'Scheduled' => CaseStatus::where('code', 'SCHED')->first()->status_id,
            'In Progress' => CaseStatus::where('code', 'INPROG')->first()->status_id,
            'Completed' => CaseStatus::where('code', 'COMP')->first()->status_id,
            'Cancelled' => CaseStatus::where('code', 'CANC')->first()->status_id
        ];

        // Create test cases for today
        $today = Carbon::now()->startOfDay();
        $cases = [
            [
                'start_time' => '09:00',
                'duration' => 120,
                'status' => 'In Progress',
                'room' => $roomIds[0],
                'surgeon' => $providerIds[0],
                'service' => $serviceIds[0],
                'procedure' => 'Laparoscopic Cholecystectomy'
            ],
            [
                'start_time' => '11:30',
                'duration' => 180,
                'status' => 'Scheduled',
                'room' => $roomIds[0],
                'surgeon' => $providerIds[0],
                'service' => $serviceIds[0],
                'procedure' => 'Appendectomy'
            ],
            [
                'start_time' => '08:30',
                'duration' => 240,
                'status' => 'Completed',
                'room' => $roomIds[1],
                'surgeon' => $providerIds[1],
                'service' => $serviceIds[1],
                'procedure' => 'Total Knee Replacement'
            ],
            [
                'start_time' => '13:00',
                'duration' => 180,
                'status' => 'Scheduled',
                'room' => $roomIds[1],
                'surgeon' => $providerIds[1],
                'service' => $serviceIds[1],
                'procedure' => 'Hip Arthroplasty'
            ],
            [
                'start_time' => '09:00',
                'duration' => 300,
                'status' => 'Cancelled',
                'room' => $roomIds[2],
                'surgeon' => $providerIds[2],
                'service' => $serviceIds[2],
                'procedure' => 'Coronary Artery Bypass'
            ]
        ];

        foreach ($cases as $case) {
            $startTime = Carbon::createFromFormat('Y-m-d H:i', $today->format('Y-m-d') . ' ' . $case['start_time']);
            
            $orCase = ORCase::create([
                'patient_id' => 'TEST' . rand(1000, 9999),
                'surgery_date' => $today,
                'room_id' => $case['room'],
                'location_id' => 1,
                'primary_surgeon_id' => $case['surgeon'],
                'case_service_id' => $case['service'],
                'scheduled_start_time' => $startTime,
                'scheduled_duration' => $case['duration'],
                'record_create_date' => now(),
                'status_id' => $statusMap[$case['status']],
                'case_type_id' => 1, // Elective
                'case_class_id' => 1, // Inpatient
                'patient_class_id' => 1, // Inpatient
                'procedure_name' => $case['procedure'],
                'created_by' => 'system',
                'modified_by' => 'system',
                'created_at' => now(),
                'updated_at' => now(),
                'is_deleted' => false
            ]);

            // Create OR logs for completed and in-progress cases
            if (in_array($case['status'], ['Completed', 'In Progress'])) {
                $orInTime = $startTime->copy();
                $procedureStartTime = $startTime->copy()->addMinutes(30);
                $procedureEndTime = null;
                $orOutTime = null;

                if ($case['status'] === 'Completed') {
                    $procedureEndTime = $procedureStartTime->copy()->addMinutes($case['duration']);
                    $orOutTime = $procedureEndTime->copy()->addMinutes(30);
                }

                ORLog::create([
                    'log_id' => $orCase->case_id, // Use case_id as log_id
                    'case_id' => $orCase->case_id,
                    'tracking_date' => $today,
                    'or_in_time' => $orInTime,
                    'procedure_start_time' => $procedureStartTime,
                    'procedure_end_time' => $procedureEndTime,
                    'or_out_time' => $orOutTime,
                    'created_by' => 'system',
                    'modified_by' => 'system',
                    'created_at' => now(),
                    'updated_at' => now(),
                    'is_deleted' => false
                ]);
            }
        }
    }
}
