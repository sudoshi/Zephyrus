<?php

namespace Tests\Feature\Rtdc;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_units_and_beds_tables_exist_with_key_columns(): void
    {
        $this->assertTrue(Schema::hasTable('prod.units'));
        $this->assertTrue(Schema::hasColumns('prod.units', [
            'unit_id', 'name', 'type', 'staffed_bed_count', 'ratio_floor',
            'access_standard_minutes', 'is_deleted', 'created_by',
        ]));
        $this->assertTrue(Schema::hasTable('prod.beds'));
        $this->assertTrue(Schema::hasColumns('prod.beds', [
            'bed_id', 'unit_id', 'label', 'status', 'bed_type', 'isolation_capable', 'is_deleted',
        ]));
    }
}
