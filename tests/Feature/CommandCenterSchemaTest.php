<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CommandCenterSchemaTest extends TestCase
{
    public function test_ed_visits_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('prod.ed_visits'), 'prod.ed_visits table must exist');
    }

    public function test_ed_visits_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('prod.ed_visits', [
                'ed_visit_id',
                'patient_ref',
                'arrived_at',
                'triaged_at',
                'esi_level',
                'provider_seen_at',
                'disposition',
                'admit_decision_at',
                'bed_assigned_at',
                'departed_at',
                'unit_id',
                'created_at',
                'updated_at',
                'is_deleted',
            ])
        );
    }

    public function test_gmlos_references_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('prod.gmlos_references'), 'prod.gmlos_references table must exist');
    }

    public function test_gmlos_references_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('prod.gmlos_references', [
                'gmlos_reference_id',
                'unit_type',
                'gmlos_days',
                'effective_from',
                'created_at',
                'updated_at',
            ])
        );
    }

    public function test_diversion_events_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('prod.diversion_events'), 'prod.diversion_events table must exist');
    }

    public function test_diversion_events_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('prod.diversion_events', [
                'diversion_event_id',
                'scope',
                'unit_id',
                'started_at',
                'ended_at',
                'reason',
                'created_at',
                'updated_at',
                'is_deleted',
            ])
        );
    }

    public function test_pdsa_cycles_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('prod.pdsa_cycles'), 'prod.pdsa_cycles table must exist');
    }

    public function test_pdsa_cycles_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('prod.pdsa_cycles', [
                'pdsa_cycle_id',
                'title',
                'unit_id',
                'status',
                'owner',
                'objective',
                'started_at',
                'completed_at',
                'created_at',
                'updated_at',
                'is_deleted',
            ])
        );
    }
}
