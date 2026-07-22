<?php

namespace App\Policies\Mobile;

use App\Models\Evs\EvsRequest;
use App\Models\Transport\TransportRequest;
use App\Models\User;

/**
 * Transport and EVS retain the existing task-scoped access boundary: a
 * current task for the context is required at the time of every request.
 */
final class TaskPatientOperationalContextPolicy implements PatientOperationalContextAccessPolicy
{
    public function decide(User $user, string $patientRef, string $roleId): ?PatientOperationalContextAccessDecision
    {
        return match ($roleId) {
            'transport' => $this->activeTransportTask($patientRef)
                ? PatientOperationalContextAccessDecision::allow('transport_active_task', 'transport_task_active')
                : PatientOperationalContextAccessDecision::deny('transport_active_task', 'transport_task_not_active'),
            'evs' => $this->activeEvsTask($patientRef)
                ? PatientOperationalContextAccessDecision::allow('evs_active_task', 'evs_task_active')
                : PatientOperationalContextAccessDecision::deny('evs_active_task', 'evs_task_not_active'),
            default => null,
        };
    }

    private function activeTransportTask(string $patientRef): bool
    {
        return TransportRequest::query()
            ->where('patient_ref', $patientRef)
            ->active()
            ->exists();
    }

    private function activeEvsTask(string $patientRef): bool
    {
        return EvsRequest::query()
            ->where('patient_ref', $patientRef)
            ->active()
            ->exists();
    }
}
