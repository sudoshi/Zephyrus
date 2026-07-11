<?php

namespace Tests\Feature\Arena;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OcelQuantitySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_quantity_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('ocel.object_quantities'));
        $this->assertTrue(Schema::hasTable('ocel.quantity_operations'));
        $this->assertTrue(Schema::hasColumns('ocel.quantity_operations', [
            'event_id', 'object_id', 'item_type', 'delta', 'event_time',
        ]));
        $this->assertTrue(Schema::hasColumns('ocel.object_quantities', [
            'object_id', 'item_type', 'quantity',
        ]));
    }
}
