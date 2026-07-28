<?php

namespace App\Nightingale\Activation;

/**
 * Product feature-switch prerequisite.
 *
 * Enabled is an independently governed state and cannot substitute for any
 * clinical, content, enrollment, source, identity, or authorization decision.
 */
enum NightingaleFeatureActivationState: string
{
    case Disabled = 'disabled';
    case Enabled = 'enabled';

    public function permitsFurtherEvaluation(): bool
    {
        return $this === self::Enabled;
    }
}
