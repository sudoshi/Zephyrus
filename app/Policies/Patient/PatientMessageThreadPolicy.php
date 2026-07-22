<?php

namespace App\Policies\Patient;

use App\Models\Patient\PatientMessageThread;
use App\Models\Patient\PatientPrincipal;

class PatientMessageThreadPolicy
{
    public function view(PatientPrincipal $principal, PatientMessageThread $thread): bool
    {
        $grant = $thread->accessGrant;

        return $principal->can('view', $grant)
            && (int) $grant->principal_id === (int) $principal->getKey()
            && $grant->permits('messaging:read');
    }

    public function send(PatientPrincipal $principal, PatientMessageThread $thread): bool
    {
        return $this->view($principal, $thread)
            && $thread->accessGrant->permits('messaging:write');
    }

    public function amend(PatientPrincipal $principal, PatientMessageThread $thread): bool
    {
        return $this->send($principal, $thread);
    }

    public function close(PatientPrincipal $principal, PatientMessageThread $thread): bool
    {
        return $this->send($principal, $thread);
    }
}
