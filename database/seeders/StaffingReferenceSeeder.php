<?php

namespace Database\Seeders;

use App\Services\Deployment\ServiceLineNormalizer;
use App\Services\Deployment\ServiceLineRegistrar;
use App\Services\Staffing\StaffRoleRegistrar;
use Illuminate\Database\Seeder;

class StaffingReferenceSeeder extends Seeder
{
    public function run(ServiceLineRegistrar $serviceLines, StaffRoleRegistrar $staffRoles): void
    {
        $serviceLines->seedServiceLines();
        ServiceLineNormalizer::flush();
        $staffRoles->seed();
    }
}
