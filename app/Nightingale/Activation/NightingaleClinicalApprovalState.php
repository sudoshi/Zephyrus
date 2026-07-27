<?php

namespace App\Nightingale\Activation;

/**
 * Institution-owned clinical approval prerequisite.
 *
 * Recorded may be emitted only by a future adapter that validates an exact,
 * current, in-scope approval record. It is not inferred from source data,
 * pathway verification, clinician use, or another activation prerequisite.
 */
enum NightingaleClinicalApprovalState: string
{
    case Absent = 'absent';
    case Recorded = 'recorded';

    public function permitsFurtherEvaluation(): bool
    {
        return $this === self::Recorded;
    }
}
