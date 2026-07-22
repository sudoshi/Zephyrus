<?php

namespace App\Policies\Patient;

use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientPrincipal;

class PatientEncounterAccessGrantPolicy
{
    public function viewAny(PatientPrincipal $principal): bool
    {
        return (bool) $principal->is_active;
    }

    public function view(PatientPrincipal $principal, PatientEncounterAccessGrant $grant): bool
    {
        return (bool) $principal->is_active
            && (int) $grant->principal_id === (int) $principal->getKey()
            && $grant->status === 'active'
            && $grant->revoked_at === null
            && ($grant->valid_from === null || $grant->valid_from->isPast())
            && ($grant->expires_at === null || $grant->expires_at->isFuture());
    }
}
