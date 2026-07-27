<?php

namespace App\Nightingale\Activation;

/**
 * Authoritative-source connector deployment prerequisite.
 *
 * Deployed may be emitted only after a future source boundary validates the
 * approved adapter, facility scope, health, version, and rollback posture.
 */
enum NightingaleSourceConnectorState: string
{
    case Undeployed = 'undeployed';
    case Deployed = 'deployed';

    public function permitsFurtherEvaluation(): bool
    {
        return $this === self::Deployed;
    }
}
