<?php

namespace App\Policies\Mobile;

use App\Models\User;
use App\Services\Mobile\PatientOperationalContextLookup;
use Illuminate\Support\Facades\Schema;

/**
 * Unit-scoped clinicians may open a patient only while their current user-unit
 * assignment overlaps an active patient unit from an authoritative source.
 */
final class UnitPatientOperationalContextPolicy implements PatientOperationalContextAccessPolicy
{
    private const ROLE_IDS = ['charge_nurse', 'bedside_nurse', 'hospitalist', 'intensivist'];

    public function __construct(private readonly PatientOperationalContextLookup $contexts) {}

    public function decide(User $user, string $patientRef, string $roleId): ?PatientOperationalContextAccessDecision
    {
        if (! in_array($roleId, self::ROLE_IDS, true)) {
            return null;
        }

        $patientUnitIds = $this->contexts->activeUnitIds($patientRef);
        if ($patientUnitIds === [] || ! Schema::hasTable('prod.user_unit')) {
            return PatientOperationalContextAccessDecision::deny(
                'shared_active_unit',
                'patient_unit_assignment_unavailable',
            );
        }

        $sharesUnit = $user->units()
            ->wherePivotIn('unit_id', $patientUnitIds)
            ->exists();

        return $sharesUnit
            ? PatientOperationalContextAccessDecision::allow('shared_active_unit', 'shared_active_unit_authorized')
            : PatientOperationalContextAccessDecision::deny('shared_active_unit', 'shared_active_unit_required');
    }
}
