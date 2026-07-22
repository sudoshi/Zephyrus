<?php

namespace App\Policies\Patient;

use App\Models\Patient\PatientEncounterProjection;
use App\Models\Patient\PatientPrincipal;
use Illuminate\Support\Facades\Gate;

class PatientEncounterProjectionPolicy
{
    public function view(PatientPrincipal $principal, PatientEncounterProjection $projection): bool
    {
        $grant = $projection->accessGrant;
        $policy = $projection->releasePolicyVersion;

        return (bool) $principal->is_active
            && $principal->status === 'active'
            && $grant !== null
            && Gate::forUser($principal)->allows('view', $grant)
            && $grant->permits((string) $projection->required_scope)
            && in_array((string) $grant->relationship, $projection->permitted_relationships ?? [], true)
            && $projection->release_state === 'released'
            && $projection->released_at !== null
            && $projection->released_at->isPast()
            && $policy !== null
            && $policy->version === (string) config('hummingbird-patient.policy_version')
            && $policy->status === 'active'
            && $policy->approved_at !== null
            && $policy->effective_from !== null
            && $policy->effective_from->isPast()
            && ($policy->effective_to === null || $policy->effective_to->isFuture())
            && ! $projection->contentActions()
                ->where('effective_at', '<=', now())
                ->exists();
    }
}
