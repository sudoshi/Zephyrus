<?php

namespace Tests\Feature\Arena;

use App\Domain\Ocel\QuantityProjector;
use Tests\TestCase;

class QuantityProjectorTest extends TestCase
{
    public function test_compute_quantities_classifies_occupancy_deltas(): void
    {
        $projector = new QuantityProjector;

        $events = [
            ['event_id' => 'e1', 'activity' => 'admit', 'unit_id' => 'Unit:5N', 'time' => '2026-01-01T00:00:00Z'],
            ['event_id' => 'e2', 'activity' => 'discharge', 'unit_id' => 'Unit:5N', 'time' => '2026-01-01T06:00:00Z'],
            ['event_id' => 'e3', 'activity' => 'triage', 'unit_id' => 'Unit:5N', 'time' => '2026-01-01T01:00:00Z'],
        ];
        $out = $projector->computeQuantities($events, ['Unit:5N' => 4]);

        $this->assertCount(2, $out['operations']);
        $deltas = array_column($out['operations'], 'delta');
        $this->assertEqualsCanonicalizing([1, -1], $deltas);

        $this->assertSame(
            [['object_id' => 'Unit:5N', 'item_type' => 'occupied_beds', 'quantity' => 4]],
            $out['initial'],
        );
        $this->assertSame('occupied_beds', $out['operations'][0]['item_type']);
    }

    public function test_compute_quantities_skips_events_without_a_unit(): void
    {
        $out = (new QuantityProjector)->computeQuantities(
            [['event_id' => 'e1', 'activity' => 'admit', 'unit_id' => null, 'time' => '2026-01-01T00:00:00Z']],
            [],
        );
        $this->assertSame([], $out['operations']);
    }
}
