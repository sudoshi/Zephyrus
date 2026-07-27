<?php

namespace App\Nightingale\Activation;

/**
 * Patient-content release prerequisite.
 *
 * Released means only that a future content service validated an exact,
 * audience-specific release. It does not imply clinical approval, feature
 * activation, patient enrollment, connector deployment, or disclosure.
 */
enum NightingaleContentReleaseState: string
{
    case Unreleased = 'unreleased';
    case Released = 'released';

    public function permitsFurtherEvaluation(): bool
    {
        return $this === self::Released;
    }
}
