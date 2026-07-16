<?php

namespace App\Contracts;

use App\Services\Alerting\OperationalAlert;

/**
 * A delivery lane for a PHI-free OperationalAlert (INT-OBS 5 / ADM-HEALTH 6).
 *
 * This generalizes the cockpit AlertChannel to the shared on-call delivery
 * abstraction. Integration SLO breaches and critical system-health
 * observations route through implementations of this contract. Lanes MUST stay
 * inert-by-default behind an env gate and MUST NOT emit clinical content or
 * secrets — the OperationalAlertDispatcher guards every field first.
 */
interface OperationalAlertChannel
{
    /** @return int the number of recipients/endpoints the alert was dispatched to */
    public function deliver(OperationalAlert $alert): int;

    /** A stable, PHI-free lane identifier for delivery audit rows. */
    public function name(): string;
}
