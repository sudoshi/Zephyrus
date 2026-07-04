<?php

namespace Tests\Unit\Cockpit;

use App\Events\Cockpit\CockpitSnapshotUpdated;
use PHPUnit\Framework\TestCase;

/**
 * P6 WS-7 — the reload ping stays a PING: public channel, stable event name,
 * and a payload of exactly {facility_key, generated_at}. Anything more on
 * this wire is a contract break AND a PHI-doctrine break (channels.php).
 */
class CockpitSnapshotUpdatedTest extends TestCase
{
    public function test_broadcast_contract_is_a_phi_free_reload_ping(): void
    {
        $event = new CockpitSnapshotUpdated('HOSP1', '2026-07-04T15:00:00+00:00');

        $this->assertSame('hospital.cockpit', $event->broadcastOn()->name);
        $this->assertSame('cockpit.updated', $event->broadcastAs());
        $this->assertSame(
            ['facility_key' => 'HOSP1', 'generated_at' => '2026-07-04T15:00:00+00:00'],
            $event->broadcastWith(),
        );
    }
}
