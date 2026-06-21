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

    public function test_encounters_census_and_events_tables_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('prod.encounters', [
            'encounter_id', 'patient_ref', 'unit_id', 'bed_id', 'admitted_at',
            'expected_discharge_date', 'acuity_tier', 'status', 'is_deleted',
        ]));
        $this->assertTrue(Schema::hasColumns('prod.census_snapshots', [
            'census_snapshot_id', 'unit_id', 'captured_at', 'staffed_beds',
            'occupied', 'available', 'blocked', 'acuity_adjusted_capacity',
        ]));
        $this->assertTrue(Schema::hasColumns('prod.operational_events', [
            'operational_event_id', 'event_id', 'type', 'encounter_ref', 'payload', 'occurred_at',
        ]));
    }
}
