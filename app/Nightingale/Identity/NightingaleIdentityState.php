<?php

namespace App\Nightingale\Identity;

/**
 * Request-scoped identity state for the independent Nightingale realm.
 *
 * This enum is not an authentication mechanism. In particular, VerifiedSelf
 * means only that a future, approved identity adapter completed its own
 * evaluation. It does not grant encounter access or authorize disclosure.
 */
enum NightingaleIdentityState: string
{
    case Unavailable = 'unavailable';
    case Denied = 'denied';
    case VerifiedSelf = 'verified_self';

    public function permitsGovernedEvaluation(): bool
    {
        return $this === self::VerifiedSelf;
    }
}
