<?php

namespace App\Nightingale\Disclosure;

use App\Nightingale\EncounterAccess\NightingalePreconditionDisposition;

/**
 * Collapses all non-disclosable request states into one public disposition.
 *
 * The gate is intentionally pure, identifier-free, route-free, and
 * side-effect-free. It cannot query a patient source, emit an audit event,
 * disclose content, or activate a product surface.
 */
final class NightingaleGenericNonDisclosureGate
{
    public function evaluate(
        NightingalePreconditionDisposition $preconditions,
        NightingaleRelationshipState $relationship,
        NightingaleEncounterBindingState $encounterBinding,
        NightingaleResourceState $resource,
    ): NightingaleDisclosureDisposition {
        if ($preconditions !== NightingalePreconditionDisposition::ContinueToGovernedEvaluation
            || ! $relationship->permitsGovernedEvaluation()
            || ! $encounterBinding->permitsGovernedEvaluation()
            || ! $resource->permitsGovernedEvaluation()
        ) {
            return NightingaleDisclosureDisposition::WithholdNotFound;
        }

        return NightingaleDisclosureDisposition::ContinueToGovernedProjectionEvaluation;
    }
}
