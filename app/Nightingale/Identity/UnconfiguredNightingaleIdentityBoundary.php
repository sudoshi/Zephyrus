<?php

namespace App\Nightingale\Identity;

/**
 * Safe foundation implementation. It has no container binding or HTTP caller
 * and can never represent an authenticated or authorized patient.
 */
final class UnconfiguredNightingaleIdentityBoundary implements NightingaleIdentityBoundary
{
    public function state(): NightingaleIdentityState
    {
        return NightingaleIdentityState::Unavailable;
    }
}
