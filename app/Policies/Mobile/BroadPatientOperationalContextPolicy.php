<?php

namespace App\Policies\Mobile;

use App\Models\User;
use App\Services\Mobile\MobilePersonaCatalog;

/** Authorizes operators with the governed assume-any-mobile-persona capability. */
final class BroadPatientOperationalContextPolicy implements PatientOperationalContextAccessPolicy
{
    public function __construct(private readonly MobilePersonaCatalog $personas) {}

    public function decide(User $user, string $patientRef, string $roleId): ?PatientOperationalContextAccessDecision
    {
        if (! $this->personas->isBroadAccessUser($user)) {
            return null;
        }

        return PatientOperationalContextAccessDecision::allow(
            'broad_mobile_persona',
            'broad_mobile_persona_authorized',
        );
    }
}
