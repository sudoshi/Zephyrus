<?php

namespace Tests\Feature\PatientFlow;

use App\Models\Barrier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seam 4 of the flow reconciliation — un-blinding the 48h Navigator spine. The
 * new /api/patient-flow/barriers read must surface only currently-open barriers,
 * carry the numeric unit_id the FE anchors on, stay patient-free (encounter_ref
 * redacted), and — being aggregate — stay open to any authenticated persona while
 * still refusing anonymous callers. summary() also gains an open_barriers count.
 */
class PatientFlowBarriersTest extends TestCase
{
    use RefreshDatabase;

    private function seedBarriers(): Unit
    {
        $unit = Unit::create(['name' => '4 West', 'type' => 'med_surg', 'staffed_bed_count' => 28, 'ratio_floor' => 4]);

        // Two open (one unit-scoped, one house-level), one resolved (excluded).
        Barrier::create(['unit_id' => $unit->unit_id, 'category' => 'placement', 'status' => 'open', 'owner' => 'C. Ramos', 'description' => 'Isolation bed shortage', 'opened_at' => now()->subHours(5)]);
        Barrier::create(['unit_id' => null, 'category' => 'logistical', 'status' => 'open', 'owner' => 'J. Lee', 'description' => 'Transport delay', 'opened_at' => now()->subHours(2)]);
        Barrier::create(['unit_id' => $unit->unit_id, 'category' => 'social', 'status' => 'resolved', 'opened_at' => now()->subHours(10), 'resolved_at' => now()]);

        return $unit;
    }

    public function test_barriers_endpoint_returns_only_open_barriers_anchored_by_unit(): void
    {
        $unit = $this->seedBarriers();

        $body = $this->actingAs(User::factory()->create(['role' => 'user']))
            ->getJson('/api/patient-flow/barriers')
            ->assertOk()
            ->assertJsonPath('count', 2) // the resolved one is excluded
            ->assertJsonCount(2, 'open_barriers')
            ->json();

        // Ordered by opened_at: the 5h-old placement hold first, then the house-level one.
        $this->assertSame('placement', $body['open_barriers'][0]['category']);
        $this->assertSame($unit->unit_id, $body['open_barriers'][0]['unit_id']);
        $this->assertSame('4 West', $body['open_barriers'][0]['unit_label']);
        $this->assertNull($body['open_barriers'][0]['encounter_ref']); // patient identity stays redacted

        // House-level barrier carries a null unit (FE renders it chronobar/HUD-only).
        $this->assertNull($body['open_barriers'][1]['unit_id']);
        $this->assertNull($body['open_barriers'][1]['unit_label']);
    }

    public function test_barriers_endpoint_filters_by_unit(): void
    {
        $unit = $this->seedBarriers();

        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->getJson('/api/patient-flow/barriers?unit_id='.$unit->unit_id)
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('open_barriers.0.category', 'placement');
    }

    public function test_summary_reports_the_open_barrier_count(): void
    {
        $this->seedBarriers();

        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->getJson('/api/patient-flow/summary')
            ->assertOk()
            ->assertJsonPath('open_barriers', 2);
    }

    public function test_barriers_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/patient-flow/barriers')->assertUnauthorized();
    }
}
