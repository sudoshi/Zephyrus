<?php

namespace Tests\Feature\Rtdc;

use App\Models\Bed;
use App\Models\BedRequest;
use App\Models\Encounter;
use App\Models\Unit;
use App\Rtdc\Optimizer\HeuristicBedAssignmentOptimizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeuristicOptimizerTest extends TestCase
{
    use RefreshDatabase;

    private function optimizer(): HeuristicBedAssignmentOptimizer
    {
        return app(HeuristicBedAssignmentOptimizer::class);
    }

    public function test_capability_isolation_and_safety_are_hard_constraints(): void
    {
        $ms = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);
        $icu = Unit::create(['name' => 'ICU', 'type' => 'icu', 'staffed_bed_count' => 4, 'ratio_floor' => 2]);

        $msBed = Bed::create(['unit_id' => $ms->unit_id, 'label' => '5E-01', 'status' => 'available', 'isolation_capable' => false]);
        $msIso = Bed::create(['unit_id' => $ms->unit_id, 'label' => '5E-02', 'status' => 'available', 'isolation_capable' => true]);
        $icuBed = Bed::create(['unit_id' => $icu->unit_id, 'label' => 'ICU-01', 'status' => 'available', 'isolation_capable' => true]);

        // Request: med_surg, contact isolation, tier 2.
        $req = BedRequest::create(['patient_ref' => 'p', 'source' => 'ed', 'acuity_tier' => 2, 'isolation_required' => 'contact', 'required_unit_type' => 'med_surg']);

        $result = $this->optimizer()->recommend($req);
        $recBedIds = array_map(fn ($r) => $r->bedId, $result->recommendations);

        // Only the isolation-capable med_surg bed is feasible.
        $this->assertSame([$msIso->bed_id], $recBedIds);
        // The non-iso med_surg bed and the ICU bed are excluded with reasons.
        $excludedIds = array_map(fn ($e) => $e->bedId, $result->excluded);
        $this->assertContains($msBed->bed_id, $excludedIds);
        $this->assertContains($icuBed->bed_id, $excludedIds);
    }

    public function test_unsafe_unit_is_pruned(): void
    {
        $unit = Unit::create(['name' => 'SD', 'type' => 'step_down', 'staffed_bed_count' => 2, 'ratio_floor' => 3]);
        $bed = Bed::create(['unit_id' => $unit->unit_id, 'label' => 'SD-01', 'status' => 'available']);
        // Saturate workload: a tier-4 (2.2) leaves 2-2.2 = -0.2 remaining.
        Encounter::create(['patient_ref' => 'x', 'unit_id' => $unit->unit_id, 'acuity_tier' => 4, 'status' => 'active']);

        $req = BedRequest::create(['patient_ref' => 'p', 'source' => 'ed', 'acuity_tier' => 1, 'isolation_required' => 'none', 'required_unit_type' => 'any']);
        $result = $this->optimizer()->recommend($req);

        $this->assertTrue($result->isEmpty());
        $this->assertContains($bed->bed_id, array_map(fn ($e) => $e->bedId, $result->excluded));
    }

    public function test_isolation_fragmentation_penalty_orders_non_iso_first(): void
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);
        $plain = Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-01', 'status' => 'available', 'isolation_capable' => false]);
        $iso = Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-02', 'status' => 'available', 'isolation_capable' => true]);

        // Non-isolation request: should prefer the plain bed (preserve the scarce iso bed).
        $req = BedRequest::create(['patient_ref' => 'p', 'source' => 'ed', 'acuity_tier' => 2, 'isolation_required' => 'none', 'required_unit_type' => 'med_surg']);
        $result = $this->optimizer()->recommend($req);

        $this->assertSame($plain->bed_id, $result->top()->bedId);
        $this->assertNotNull($result->runnerUpDelta());
    }

    public function test_safety_guarantee_no_recommendation_violates_hard_constraints(): void
    {
        // Deterministic randomized pool; every recommendation must satisfy all hard constraints.
        mt_srand(7);
        $units = collect(['med_surg', 'icu', 'step_down'])->map(fn ($t) => Unit::create([
            'name' => strtoupper($t), 'type' => $t, 'staffed_bed_count' => mt_rand(2, 8), 'ratio_floor' => 3,
        ]));
        foreach ($units as $u) {
            for ($i = 0; $i < mt_rand(1, 5); $i++) {
                Bed::create(['unit_id' => $u->unit_id, 'label' => "{$u->name}-$i", 'status' => 'available', 'isolation_capable' => (bool) mt_rand(0, 1)]);
            }
            for ($i = 0; $i < mt_rand(0, 4); $i++) {
                Encounter::create(['patient_ref' => "occ-{$u->unit_id}-$i", 'unit_id' => $u->unit_id, 'acuity_tier' => mt_rand(1, 4), 'status' => 'active']);
            }
        }
        $acuity = app(\App\Services\AcuityService::class);

        foreach (['any', 'med_surg', 'icu', 'step_down'] as $type) {
            foreach (['none', 'contact'] as $iso) {
                foreach ([1, 3, 4] as $tier) {
                    $req = BedRequest::create(['patient_ref' => 'r', 'source' => 'ed', 'acuity_tier' => $tier, 'isolation_required' => $iso, 'required_unit_type' => $type]);
                    $result = $this->optimizer()->recommend($req);
                    foreach ($result->recommendations as $rec) {
                        $bed = Bed::with('unit')->find($rec->bedId);
                        // capability
                        $this->assertTrue($type === 'any' || $bed->unit->type === $type, "capability violated for {$type}");
                        // isolation
                        $this->assertTrue($iso === 'none' || $bed->isolation_capable, 'isolation violated');
                        // safety: the unit could accept this acuity at recommend time
                        $this->assertTrue($acuity->canAccept($bed->unit_id, $tier), 'recommended bed unit must have acuity headroom');
                    }
                    $req->delete();
                    Encounter::where('patient_ref', 'r')->delete();
                }
            }
        }
        $this->assertTrue(true); // reached without constraint assertion failures
    }
}
