<?php

namespace Tests\Feature\Arena;

use App\Domain\Ocel\QuantityProjector;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuantityProjectorProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_populates_quantity_operations_from_ocel_log(): void
    {
        DB::table('ocel.objects')->insert([
            ['id' => 'Unit:5N', 'type' => 'Unit', 'attrs' => '{}', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('ocel.events')->insert([
            ['id' => 'ev-admit', 'activity' => 'admit', 'event_time' => '2026-01-01T00:00:00Z', 'attrs' => '{}', 'source_system' => 'test', 'source_ref' => 'a'],
            ['id' => 'ev-disch', 'activity' => 'discharge', 'event_time' => '2026-01-01T06:00:00Z', 'attrs' => '{}', 'source_system' => 'test', 'source_ref' => 'd'],
        ]);
        DB::table('ocel.event_object')->insert([
            ['event_id' => 'ev-admit', 'object_id' => 'Unit:5N', 'qualifier' => 'location'],
            ['event_id' => 'ev-disch', 'object_id' => 'Unit:5N', 'qualifier' => 'location'],
        ]);

        (new QuantityProjector())->project(Carbon::parse('2025-12-31'), Carbon::parse('2026-01-02'));

        $ops = DB::table('ocel.quantity_operations')->where('object_id', 'Unit:5N')->orderBy('event_time')->get();
        $this->assertCount(2, $ops);
        $this->assertSame(1, (int) $ops[0]->delta);
        $this->assertSame(-1, (int) $ops[1]->delta);
    }

    public function test_project_populates_initial_object_quantities_from_pre_floor_events(): void
    {
        DB::table('ocel.objects')->insert([
            ['id' => 'Unit:ICU', 'type' => 'Unit', 'attrs' => '{}', 'created_at' => now(), 'updated_at' => now()],
        ]);
        // Two admits BEFORE the window floor, one discharge before it: net occupancy = 1.
        DB::table('ocel.events')->insert([
            ['id' => 'pre-a1', 'activity' => 'admit', 'event_time' => '2025-12-01T00:00:00Z', 'attrs' => '{}', 'source_system' => 'test', 'source_ref' => 'a1'],
            ['id' => 'pre-a2', 'activity' => 'admit', 'event_time' => '2025-12-02T00:00:00Z', 'attrs' => '{}', 'source_system' => 'test', 'source_ref' => 'a2'],
            ['id' => 'pre-d1', 'activity' => 'discharge', 'event_time' => '2025-12-03T00:00:00Z', 'attrs' => '{}', 'source_system' => 'test', 'source_ref' => 'd1'],
        ]);
        DB::table('ocel.event_object')->insert([
            ['event_id' => 'pre-a1', 'object_id' => 'Unit:ICU', 'qualifier' => 'location'],
            ['event_id' => 'pre-a2', 'object_id' => 'Unit:ICU', 'qualifier' => 'location'],
            ['event_id' => 'pre-d1', 'object_id' => 'Unit:ICU', 'qualifier' => 'location'],
        ]);

        (new QuantityProjector())->project(Carbon::parse('2025-12-31'), Carbon::parse('2026-01-02'));

        $initial = DB::table('ocel.object_quantities')
            ->where('object_id', 'Unit:ICU')->where('item_type', 'occupied_beds')->first();
        $this->assertNotNull($initial);
        $this->assertSame(1, (int) $initial->quantity);
    }
}
