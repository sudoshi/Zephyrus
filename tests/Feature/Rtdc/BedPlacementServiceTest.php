<?php

namespace Tests\Feature\Rtdc;

use App\Models\Bed;
use App\Models\BedRequest;
use App\Models\Unit;
use App\Services\BedPlacementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BedPlacementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_accept_places_patient_creates_encounter_and_audits(): void
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);
        $bed = Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-01', 'status' => 'available']);
        $req = BedRequest::create(['patient_ref' => 'p1', 'source' => 'ed', 'acuity_tier' => 2, 'isolation_required' => 'none', 'required_unit_type' => 'med_surg']);

        $svc = app(BedPlacementService::class);
        $decision = $svc->decide($req, action: 'accepted', chosenBedId: $bed->bed_id, reason: null, decidedBy: null);

        // Encounter created on the bed (via the S2 dispatcher), census reflects occupancy.
        $this->assertDatabaseHas('prod.encounters', ['patient_ref' => 'p1', 'bed_id' => $bed->bed_id, 'status' => 'active']);
        $this->assertEquals('occupied', $bed->fresh()->status);
        $this->assertEquals('placed', $req->fresh()->status);
        $this->assertDatabaseHas('prod.bed_placement_decisions', ['bed_request_id' => $req->bed_request_id, 'action' => 'accepted', 'chosen_bed_id' => $bed->bed_id]);
        $this->assertEquals('accepted', $decision->action);
    }

    public function test_reject_captures_reason_and_does_not_place(): void
    {
        $req = BedRequest::create(['patient_ref' => 'p2', 'source' => 'ed', 'acuity_tier' => 2]);
        $svc = app(BedPlacementService::class);
        $svc->decide($req, action: 'rejected', chosenBedId: null, reason: 'family request to wait', decidedBy: null);

        $this->assertEquals('pending', $req->fresh()->status);
        $this->assertDatabaseHas('prod.bed_placement_decisions', ['bed_request_id' => $req->bed_request_id, 'action' => 'rejected', 'reason' => 'family request to wait']);
        $this->assertDatabaseMissing('prod.encounters', ['patient_ref' => 'p2']);
    }
}
