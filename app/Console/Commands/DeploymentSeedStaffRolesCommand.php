<?php

namespace App\Console\Commands;

use App\Services\Staffing\StaffRoleRegistrar;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Projects the config-authored staff-role taxonomy into hosp_ref.staff_roles
 * (Phase 7). Idempotent — safe to run repeatedly.
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§6 task 2)
 */
class DeploymentSeedStaffRolesCommand extends Command
{
    protected $signature = 'deployment:seed-staff-roles';

    protected $description = 'Seed hosp_ref.staff_roles from config/hospital/staff-roles.php.';

    public function handle(StaffRoleRegistrar $registrar): int
    {
        try {
            $count = $registrar->seed();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Staff-role seed failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Staff-role taxonomy seeded ({$count} roles).");

        return self::SUCCESS;
    }
}
