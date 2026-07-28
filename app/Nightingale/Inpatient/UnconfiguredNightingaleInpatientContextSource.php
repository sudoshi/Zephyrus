<?php

namespace App\Nightingale\Inpatient;

/**
 * Safe foundation implementation. It performs no database or network query
 * and can never report a current inpatient context.
 */
final class UnconfiguredNightingaleInpatientContextSource implements NightingaleInpatientContextSource
{
    public function state(): NightingaleInpatientSourceState
    {
        return NightingaleInpatientSourceState::Unavailable;
    }
}
