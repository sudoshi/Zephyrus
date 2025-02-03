<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CaseManagementSeeder extends Seeder
{
    public function run()
    {
        // Seed case statuses
        $statuses = [
            [
                'status_id' => 1,
                'name' => 'Scheduled',
                'code' => 'SCHED',
                'active_status' => true,
                'created_by' => 'system',
                'modified_by' => 'system',
                'created_at' => now(),
                'updated_at' => now(),
                'is_deleted' => false
            ],
            [
                'status_id' => 2,
                'name' => 'In Progress',
                'code' => 'INPROG',
                'active_status' => true,
                'created_by' => 'system',
                'modified_by' => 'system',
                'created_at' => now(),
                'updated_at' => now(),
                'is_deleted' => false
            ],
            [
                'status_id' => 3,
                'name' => 'Delayed',
                'code' => 'DELAY',
                'active_status' => true,
                'created_by' => 'system',
                'modified_by' => 'system',
                'created_at' => now(),
                'updated_at' => now(),
                'is_deleted' => false
            ],
            [
                'status_id' => 4,
                'name' => 'Completed',
                'code' => 'COMP',
                'active_status' => true,
                'created_by' => 'system',
                'modified_by' => 'system',
                'created_at' => now(),
                'updated_at' => now(),
                'is_deleted' => false
            ],
            [
                'status_id' => 5,
                'name' => 'Cancelled',
                'code' => 'CANC',
                'active_status' => true,
                'created_by' => 'system',
                'modified_by' => 'system',
                'created_at' => now(),
                'updated_at' => now(),
                'is_deleted' => false
            ]
        ];

        foreach ($statuses as $status) {
            DB::table('prod.case_statuses')->updateOrInsert(
                ['status_id' => $status['status_id']],
                $status
            );
        }
    }
}
