<?php

namespace App\Nightingale\Disclosure;

/**
 * Request-scoped relationship result produced by a future approved
 * authorization adapter.
 *
 * These states deliberately contain no principal, patient, grant, encounter,
 * source, or representative identifier. Active means only that this
 * prerequisite may proceed; it never grants disclosure by itself.
 */
enum NightingaleRelationshipState: string
{
    case Unknown = 'unknown';
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';
    case CrossPrincipal = 'cross_principal';

    public function permitsGovernedEvaluation(): bool
    {
        return $this === self::Active;
    }
}
