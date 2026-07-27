<?php

namespace App\Nightingale\Disclosure;

/**
 * Whether the requested opaque handle remains bound to the request-scoped
 * current inpatient context at the disclosure boundary.
 *
 * No raw handle or encounter identifier crosses this domain seam.
 */
enum NightingaleEncounterBindingState: string
{
    case MatchesCurrentContext = 'matches_current_context';
    case WrongEncounter = 'wrong_encounter';

    public function permitsGovernedEvaluation(): bool
    {
        return $this === self::MatchesCurrentContext;
    }
}
