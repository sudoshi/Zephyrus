<?php

namespace Tests\Feature\Arena;

use App\Domain\Ocel\QuantityExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuantityExporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_emits_initial_and_operations(): void
    {
        DB::table('ocel.object_quantities')->insert([
            'object_id' => 'Unit:5N', 'item_type' => 'occupied_beds', 'quantity' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ocel.quantity_operations')->insert([
            ['event_id' => 'e1', 'object_id' => 'Unit:5N', 'item_type' => 'occupied_beds', 'delta' => 1, 'event_time' => '2026-01-01T00:00:00Z'],
        ]);

        $doc = (new QuantityExporter)->export();

        $this->assertSame(3, $doc['initial'][0]['quantity']);
        $this->assertSame('Unit:5N', $doc['operations'][0]['object_id']);
        $this->assertSame(1, $doc['operations'][0]['delta']);
    }
}
