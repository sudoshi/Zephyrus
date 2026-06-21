<?php

namespace Tests\Feature\Rtdc;

use App\Models\Barrier;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HuddleBarrierModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_scope_filters_resolved_barriers(): void
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);
        Barrier::create(['unit_id' => $unit->unit_id, 'category' => 'placement', 'status' => 'open']);
        Barrier::create(['unit_id' => $unit->unit_id, 'category' => 'social', 'status' => 'resolved']);

        $this->assertEquals(1, Barrier::open()->count());
    }
}
