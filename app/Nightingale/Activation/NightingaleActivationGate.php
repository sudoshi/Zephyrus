<?php

namespace App\Nightingale\Activation;

/**
 * Keeps approval, content, feature, enrollment, and source deployment
 * independent and conjunctive.
 *
 * This pure gate accepts no identifiers or records and has no route, container
 * binding, configuration reader, source query, audit writer, or native caller.
 */
final class NightingaleActivationGate
{
    public function evaluate(
        NightingaleClinicalApprovalState $clinicalApproval,
        NightingaleContentReleaseState $contentRelease,
        NightingaleFeatureActivationState $featureActivation,
        NightingalePilotEnrollmentState $pilotEnrollment,
        NightingaleSourceConnectorState $sourceConnector,
    ): NightingaleActivationDisposition {
        if (! $clinicalApproval->permitsFurtherEvaluation()
            || ! $contentRelease->permitsFurtherEvaluation()
            || ! $featureActivation->permitsFurtherEvaluation()
            || ! $pilotEnrollment->permitsFurtherEvaluation()
            || ! $sourceConnector->permitsFurtherEvaluation()
        ) {
            return NightingaleActivationDisposition::Hold;
        }

        return NightingaleActivationDisposition::ContinueToOperationSpecificReleaseEvaluation;
    }
}
