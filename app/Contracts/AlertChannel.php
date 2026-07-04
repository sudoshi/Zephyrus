<?php

namespace App\Contracts;

use App\Models\Cockpit\CockpitAlert;

/**
 * A delivery lane for a cockpit alert that just OPENED (Zephyrus 2.0 P6).
 *
 * Channels fire only on the flap-damped open transition — never on held or
 * cleared snapshots — and AlertFanout has already applied the paging policy
 * (crit always; warn only when the KPI opts in). Implementations MUST stay
 * PHI-free: keys, tiers, and deep links — never clinical detail.
 */
interface AlertChannel
{
    /** @return int the number of recipients/endpoints the alert was dispatched to */
    public function send(CockpitAlert $alert): int;
}
