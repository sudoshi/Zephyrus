<?php

namespace Tests\Feature\Rtdc;

use App\Events\Rtdc\CensusUpdated;
use App\Models\CensusSnapshot;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_census_updated_broadcasts_on_unit_channel_with_payload(): void
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);
        $snap = CensusSnapshot::create([
            'unit_id' => $unit->unit_id, 'captured_at' => now(),
            'staffed_beds' => 30, 'occupied' => 10, 'available' => 18, 'blocked' => 2, 'acuity_adjusted_capacity' => 16,
        ]);

        $event = new CensusUpdated($snap);

        $this->assertEquals('unit.'.$unit->unit_id, $event->broadcastOn()->name);
        $this->assertEquals('census.updated', $event->broadcastAs());
        $this->assertEquals(10, $event->broadcastWith()['occupied']);
    }
}
