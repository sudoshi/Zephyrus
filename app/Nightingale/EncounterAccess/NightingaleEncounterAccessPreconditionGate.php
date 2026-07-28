<?php

namespace App\Nightingale\EncounterAccess;

use App\Nightingale\Identity\NightingaleIdentityBoundary;
use App\Nightingale\Inpatient\NightingaleInpatientContextSource;

/**
 * Combines only the two prerequisite ports established in the foundation ADR.
 *
 * It never grants access and never returns data. Any non-positive or
 * unavailable prerequisite fails closed.
 */
final class NightingaleEncounterAccessPreconditionGate
{
    public function evaluate(
        NightingaleIdentityBoundary $identity,
        NightingaleInpatientContextSource $inpatientSource,
    ): NightingalePreconditionDisposition {
        if (! $identity->state()->permitsGovernedEvaluation()
            || ! $inpatientSource->state()->permitsGovernedEvaluation()
        ) {
            return NightingalePreconditionDisposition::Withhold;
        }

        return NightingalePreconditionDisposition::ContinueToGovernedEvaluation;
    }
}
