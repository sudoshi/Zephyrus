<?php

namespace App\Nightingale\Identity;

/**
 * Request-scoped port implemented only after the Nightingale identity,
 * proofing, session, recovery, and representative decisions are approved.
 *
 * The port intentionally accepts no legacy principal, token, grant, or source
 * identifier. A future ingress adapter must create a request-scoped
 * implementation without leaking credentials into this domain boundary.
 */
interface NightingaleIdentityBoundary
{
    public function state(): NightingaleIdentityState;
}
