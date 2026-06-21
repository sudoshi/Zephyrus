<?php

namespace Tests\Feature\Rtdc;

use App\Exceptions\BedUnavailableException;
use App\Exceptions\UnsafePlacementException;
use App\Models\Bed;
use App\Models\BedRequest;
use App\Models\Encounter;
use App\Models\Unit;
use App\Models\User;
use App\Services\BedPlacementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The placement (decide/accept) path must re-check hard constraints and bed
 * availability server-side — a client-supplied chosen_bed_id is never trusted.
 */
class UnsafePlacementGuardTest extends TestCase
{
    use RefreshDatabase;

    private function service(): BedPlacementService
    {
        return app(BedPlacementService::class);
    }

    public function test_accepting_isolation_incompatible_bed_throws_at_service_level(): void
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);
        $bed = Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-01', 'status' => 'available', 'isolation_capable' => false]);
        $req = BedRequest::create(['patient_ref' => 'iso1', 'source' => 'ed', 'acuity_tier' => 2, 'isolation_required' => 'contact', 'required_unit_type' => 'med_surg']);

        try {
            $this->service()->decide($req, action: 'accepted', chosenBedId: $bed->bed_id, reason: null, decidedBy: null);
            $this->fail('Expected UnsafePlacementException for isolation-incompatible bed.');
        } catch (UnsafePlacementException $e) {
            $this->assertStringContainsString('isolation', $e->getMessage());
        }

        // Transaction rolled back: no audit row, no encounter, request still pending.
        $this->assertDatabaseMissing('prod.encounters', ['patient_ref' => 'iso1']);
        $this->assertDatabaseMissing('prod.bed_placement_decisions', ['bed_request_id' => $req->bed_request_id]);
        $this->assertEquals('pending', $req->fresh()->status);
        $this->assertEquals('available', $bed->fresh()->status);
    }

    public function test_accepting_isolation_incompatible_bed_returns_422(): void
    {
        $user = User::factory()->create();
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);
        $bed = Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-01', 'status' => 'available', 'isolation_capable' => false]);
        $req = BedRequest::create(['patient_ref' => 'iso2', 'source' => 'ed', 'acuity_tier' => 2, 'isolation_required' => 'contact', 'required_unit_type' => 'med_surg']);

        $this->actingAs($user)->postJson("/api/rtdc/bed-requests/{$req->bed_request_id}/decision", [
            'action' => 'accepted', 'chosen_bed_id' => $bed->bed_id,
        ])->assertStatus(422)->assertJsonStructure(['error']);

        $this->assertDatabaseMissing('prod.encounters', ['patient_ref' => 'iso2']);
    }

    public function test_accepting_bed_in_unsafe_unit_returns_422(): void
    {
        $user = User::factory()->create();
        // Unit staffed for 2; a tier-4 (weight 2.2) saturates it → canAccept false.
        $unit = Unit::create(['name' => 'SD', 'type' => 'step_down', 'staffed_bed_count' => 2, 'ratio_floor' => 3]);
        $bed = Bed::create(['unit_id' => $unit->unit_id, 'label' => 'SD-01', 'status' => 'available']);
        Encounter::create(['patient_ref' => 'occ', 'unit_id' => $unit->unit_id, 'acuity_tier' => 4, 'status' => 'active']);

        $req = BedRequest::create(['patient_ref' => 'unsafe1', 'source' => 'ed', 'acuity_tier' => 1, 'isolation_required' => 'none', 'required_unit_type' => 'any']);

        // Service level proves the typed exception.
        try {
            $this->service()->decide($req, action: 'accepted', chosenBedId: $bed->bed_id, reason: null, decidedBy: null);
            $this->fail('Expected UnsafePlacementException for acuity-saturated unit.');
        } catch (UnsafePlacementException $e) {
            $this->assertStringContainsString('safety', $e->getMessage());
        }

        // API level returns 422.
        $this->actingAs($user)->postJson("/api/rtdc/bed-requests/{$req->bed_request_id}/decision", [
            'action' => 'accepted', 'chosen_bed_id' => $bed->bed_id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('prod.encounters', ['patient_ref' => 'unsafe1']);
    }

    public function test_accepting_already_occupied_bed_throws_and_returns_409(): void
    {
        $user = User::factory()->create();
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);
        $bed = Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-01', 'status' => 'occupied']);
        $req = BedRequest::create(['patient_ref' => 'occ1', 'source' => 'ed', 'acuity_tier' => 2, 'isolation_required' => 'none', 'required_unit_type' => 'med_surg']);

        try {
            $this->service()->decide($req, action: 'accepted', chosenBedId: $bed->bed_id, reason: null, decidedBy: null);
            $this->fail('Expected BedUnavailableException for an occupied bed.');
        } catch (BedUnavailableException $e) {
            $this->assertStringContainsString((string) $bed->bed_id, $e->getMessage());
        }

        $this->actingAs($user)->postJson("/api/rtdc/bed-requests/{$req->bed_request_id}/decision", [
            'action' => 'accepted', 'chosen_bed_id' => $bed->bed_id,
        ])->assertStatus(409)->assertJsonStructure(['error']);

        $this->assertDatabaseMissing('prod.encounters', ['patient_ref' => 'occ1']);
    }

    public function test_double_accept_on_same_bed_yields_one_encounter_and_409(): void
    {
        $user = User::factory()->create();
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);
        $bed = Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-01', 'status' => 'available']);

        $reqA = BedRequest::create(['patient_ref' => 'A', 'source' => 'ed', 'acuity_tier' => 2, 'isolation_required' => 'none', 'required_unit_type' => 'med_surg']);
        $reqB = BedRequest::create(['patient_ref' => 'B', 'source' => 'ed', 'acuity_tier' => 2, 'isolation_required' => 'none', 'required_unit_type' => 'med_surg']);

        // Patient A is placed on the bed.
        $this->actingAs($user)->postJson("/api/rtdc/bed-requests/{$reqA->bed_request_id}/decision", [
            'action' => 'accepted', 'chosen_bed_id' => $bed->bed_id,
        ])->assertOk();

        // The bed is now occupied; accepting it for B must be rejected.
        $this->actingAs($user)->postJson("/api/rtdc/bed-requests/{$reqB->bed_request_id}/decision", [
            'action' => 'accepted', 'chosen_bed_id' => $bed->bed_id,
        ])->assertStatus(409);

        // Exactly one active encounter on that bed.
        $this->assertEquals(1, Encounter::active()->where('bed_id', $bed->bed_id)->count());
        $this->assertDatabaseHas('prod.encounters', ['patient_ref' => 'A', 'bed_id' => $bed->bed_id, 'status' => 'active']);
        $this->assertDatabaseMissing('prod.encounters', ['patient_ref' => 'B']);
    }
}
