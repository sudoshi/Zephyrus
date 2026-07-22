<?php

namespace App\Contracts\Patient;

use App\Models\Patient\PatientEncounterAccessGrant;

/**
 * Implemented by the accountable care-team inbox consumer before messaging
 * can be enabled. Merely setting the patient feature flag is insufficient.
 */
interface PatientMessageHandoffReadiness
{
    public function readyForPolicy(string $policyVersion): bool;

    /**
     * Confirm that this encounter, policy, and topic resolve to an
     * accountable, currently staffed responsibility pool.
     */
    public function routableForGrant(
        string $policyVersion,
        string $topicCode,
        string $responsibilityPoolKey,
        PatientEncounterAccessGrant $grant,
    ): bool;
}
