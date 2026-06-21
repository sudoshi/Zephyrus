<?php

namespace Tests\Feature\Rtdc;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BedRequestSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_bed_requests_table(): void
    {
        $this->assertTrue(Schema::hasColumns('prod.bed_requests', [
            'bed_request_id', 'patient_ref', 'source', 'sex', 'service',
            'acuity_tier', 'isolation_required', 'required_unit_type', 'status', 'is_deleted',
        ]));
    }

    public function test_bed_placement_decisions_table(): void
    {
        $this->assertTrue(Schema::hasColumns('prod.bed_placement_decisions', [
            'bed_placement_decision_id', 'bed_request_id', 'recommended_bed_id',
            'chosen_bed_id', 'action', 'reason', 'score_snapshot', 'decided_by',
        ]));
    }
}
