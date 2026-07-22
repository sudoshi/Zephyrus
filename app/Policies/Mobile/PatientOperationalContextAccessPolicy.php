<?php

namespace App\Policies\Mobile;

use App\Models\User;

/**
 * One named staff-authority path for the PHI-minimized operational context.
 * A null result means that the policy does not govern the requested persona;
 * a non-null denial is a terminal, policy-specific refusal.
 */
interface PatientOperationalContextAccessPolicy
{
    public function decide(User $user, string $patientRef, string $roleId): ?PatientOperationalContextAccessDecision;
}
