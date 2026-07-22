<?php

namespace App\Policies\Mobile;

use App\Models\User;

/**
 * These specific house-operations personas have a governed operational need
 * to open a current patient context without a unit pivot or active task.
 */
final class HousePatientOperationalContextPolicy implements PatientOperationalContextAccessPolicy
{
    private const ROLE_IDS = ['bed_manager', 'house_supervisor', 'capacity_lead'];

    public function decide(User $user, string $patientRef, string $roleId): ?PatientOperationalContextAccessDecision
    {
        if (! in_array($roleId, self::ROLE_IDS, true)) {
            return null;
        }

        return PatientOperationalContextAccessDecision::allow(
            'house_operations_persona',
            'house_operations_persona_authorized',
        );
    }
}
