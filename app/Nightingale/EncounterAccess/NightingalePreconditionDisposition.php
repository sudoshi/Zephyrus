<?php

namespace App\Nightingale\EncounterAccess;

/**
 * This disposition is intentionally weaker than an authorization decision.
 * ContinueToGovernedEvaluation means that later identity-link, grant, purpose,
 * cardinality, race, audit, and serialization gates still must pass.
 */
enum NightingalePreconditionDisposition: string
{
    case Withhold = 'withhold';
    case ContinueToGovernedEvaluation = 'continue_to_governed_evaluation';
}
