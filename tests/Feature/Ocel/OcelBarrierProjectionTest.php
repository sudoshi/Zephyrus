<?php

namespace Tests\Feature\Ocel;

use App\Domain\Ocel\OcelProjector;
use App\Models\Barrier;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Seam 1 of the flow reconciliation — barriers become first-class OCEL objects,
 * closing "barriers never emit into OCEL" (the loop can now observe + re-measure
 * barrier lifecycles). Proves the projector fans a prod.barriers row into
 * opened/resolved events over one Barrier object, carries status as object_changes,
 * links the unit, catalogs the new type/verbs, and stays idempotent. This also
 * establishes the first projector-level (DB) OCEL test — the suite had only the
 * pure EmissionMap unit tests before.
 */
class OcelBarrierProjectionTest extends TestCase
{
    use RefreshDatabase;

    private function unit(): Unit
    {
        return Unit::create(['name' => '5 East', 'abbreviation' => '5E', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);
    }

    public function test_barriers_project_into_ocel_as_first_class_objects(): void
    {
        $unit = $this->unit();
        $resolved = Barrier::create(['unit_id' => $unit->unit_id, 'category' => 'placement', 'reason_code' => 'no_bed', 'status' => 'resolved', 'opened_at' => now()->subDay(), 'resolved_at' => now()->subHours(2)]);
        $open = Barrier::create(['unit_id' => $unit->unit_id, 'category' => 'logistical', 'status' => 'open', 'opened_at' => now()->subHours(6)]);

        $result = app(OcelProjector::class)->project();

        // 2 barrier rows fan into 3 emitted events (resolved → 2, open → 1). The
        // per-source counter tallies emitted events, like collectTransport; the
        // row-vs-event distinction is asserted via reconcile() in the next test.
        $this->assertSame(3, $result['source_rows']['prod.barriers']);
        $this->assertSame(3, (int) DB::table('ocel.events')->where('source_system', 'prod.barriers')->count());

        $this->assertDatabaseHas('ocel.events', ['id' => 'bar-'.$resolved->barrier_id.'-opened', 'activity' => 'barrier_opened', 'source_system' => 'prod.barriers']);
        $this->assertDatabaseHas('ocel.events', ['id' => 'bar-'.$resolved->barrier_id.'-resolved', 'activity' => 'barrier_resolved']);
        $this->assertDatabaseMissing('ocel.events', ['id' => 'bar-'.$open->barrier_id.'-resolved']);

        // First-class Barrier object + its O2O link to the unit (via abbreviation slug).
        $this->assertDatabaseHas('ocel.objects', ['id' => 'barrier-'.$resolved->barrier_id, 'type' => 'Barrier']);
        $this->assertDatabaseHas('ocel.object_object', ['from_id' => 'barrier-'.$resolved->barrier_id, 'to_id' => 'unit-5e', 'qualifier' => 'in']);

        // status carried as two time-varying object_changes (open → resolved).
        $this->assertSame(2, (int) DB::table('ocel.object_changes')->where('object_id', 'barrier-'.$resolved->barrier_id)->where('attr', 'status')->count());

        // The catalog was upserted with the new type + verbs.
        $this->assertDatabaseHas('ocel.object_types', ['type' => 'Barrier']);
        $this->assertDatabaseHas('ocel.activities', ['activity' => 'barrier_opened']);
        $this->assertDatabaseHas('ocel.activities', ['activity' => 'barrier_resolved']);
    }

    public function test_barrier_projection_is_idempotent_and_reconciles(): void
    {
        $unit = $this->unit();
        Barrier::create(['unit_id' => $unit->unit_id, 'category' => 'placement', 'status' => 'resolved', 'opened_at' => now()->subDay(), 'resolved_at' => now()->subHours(2)]);

        app(OcelProjector::class)->project();
        app(OcelProjector::class)->project(); // re-run converges, no duplicate rows

        $this->assertSame(2, (int) DB::table('ocel.events')->where('source_system', 'prod.barriers')->count());

        $recon = collect(app(OcelProjector::class)->reconcile())->firstWhere('source', 'prod.barriers');
        $this->assertSame(1, $recon['source_rows']);          // one barrier row in-window
        $this->assertSame(1, $recon['distinct_source_refs']); // both events share its source_ref
        $this->assertSame(2, $recon['projected_events']);     // opened + resolved
    }
}
