<?php

namespace App\Services\Mobile;

use App\Models\User;
use App\Policies\Mobile\BroadPatientOperationalContextPolicy;
use App\Policies\Mobile\HousePatientOperationalContextPolicy;
use App\Policies\Mobile\PatientOperationalContextAccessDecision;
use App\Policies\Mobile\TaskPatientOperationalContextPolicy;
use App\Policies\Mobile\UnitPatientOperationalContextPolicy;

/**
 * The single evaluator for a staff disclosure of the mobile operational
 * patient context. Client responses remain generic; the precise, non-PHI
 * outcome is retained only in the immutable staff audit ledger.
 */
final class MobilePatientContextAuthorizationService
{
    public function __construct(
        private readonly PatientOperationalContextLookup $contexts,
        private readonly MobilePatientContextReferenceStore $references,
        private readonly MobilePersonaCatalog $personas,
        private readonly BroadPatientOperationalContextPolicy $broad,
        private readonly HousePatientOperationalContextPolicy $house,
        private readonly TaskPatientOperationalContextPolicy $task,
        private readonly UnitPatientOperationalContextPolicy $unit,
    ) {}

    public function decide(
        string $requestedRef,
        ?string $patientRef,
        ?User $user,
        string $roleId,
    ): PatientOperationalContextAccessDecision {
        if (! $user) {
            return PatientOperationalContextAccessDecision::deny('authenticated_staff', 'staff_authentication_required');
        }

        if (! (bool) $user->is_active || $user->deactivated_at !== null) {
            return PatientOperationalContextAccessDecision::deny('active_staff_account', 'staff_account_inactive');
        }

        if (! $this->references->isOpaque($requestedRef)) {
            return PatientOperationalContextAccessDecision::deny('opaque_context_reference', 'opaque_context_reference_required');
        }

        if ($patientRef === null || ! $this->contexts->exists($patientRef)) {
            return PatientOperationalContextAccessDecision::deny('current_patient_context', 'patient_context_unavailable');
        }

        if (! in_array($roleId, $this->personas->allowedForUser($user), true)) {
            return PatientOperationalContextAccessDecision::deny('authorized_persona', 'mobile_persona_not_authorized');
        }

        foreach ([$this->broad, $this->house, $this->task, $this->unit] as $policy) {
            $decision = $policy->decide($user, $patientRef, $roleId);
            if ($decision !== null) {
                return $decision;
            }
        }

        return PatientOperationalContextAccessDecision::deny('patient_context_scope', 'patient_context_scope_not_authorized');
    }
}
