<?php

namespace App\Nightingale\Inpatient;

/**
 * Patient-context eligibility state reported by a future authoritative source.
 *
 * This is deliberately not the legacy prod.encounters status vocabulary. A
 * future adapter must reconcile source availability and contradictions before
 * it may return ConfirmedCurrent.
 */
enum NightingaleInpatientSourceState: string
{
    case Unavailable = 'unavailable';
    case Inconsistent = 'inconsistent';
    case ConfirmedClosed = 'confirmed_closed';
    case ConfirmedCurrent = 'confirmed_current';

    public function permitsGovernedEvaluation(): bool
    {
        return $this === self::ConfirmedCurrent;
    }
}
