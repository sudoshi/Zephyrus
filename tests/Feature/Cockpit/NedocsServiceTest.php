<?php

namespace Tests\Feature\Cockpit;

use App\Services\Ed\NedocsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Zephyrus 2.0 P7 (ED) — the live NEDOCS composite (Weiss 2004) that retires
 * the seeded demo constant. The formula and band cut points are pinned so a
 * later refactor can't silently drift the marquee crowding score.
 */
class NedocsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function visit(array $overrides): void
    {
        DB::table('prod.ed_visits')->insert($overrides + [
            'patient_ref' => 'nedocs-'.uniqid(),
            'arrived_at' => now()->subHours(2),
            'esi_level' => 3,
            'is_ventilated' => false,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_empty_ed_scores_zero_after_the_floor_clamp(): void
    {
        // No patients → the −20 intercept clamps to the 0 floor, never negative.
        $this->assertSame(0.0, app(NedocsService::class)->score());
    }

    public function test_a_crowded_ed_scores_into_the_severe_band(): void
    {
        // 55 patients in a 50-bay ED (no house census in isolation → the
        // hospital-beds denominator falls back to the manifest's licensed 500).
        for ($i = 0; $i < 55; $i++) {
            $this->visit(['is_ventilated' => $i < 4]);
        }
        // One boarder (8h admit hold) and one recently bedded patient.
        $this->visit(['disposition' => 'admitted', 'admit_decision_at' => now()->subHours(8), 'arrived_at' => now()->subHours(9)]);
        $this->visit([
            'disposition' => 'admitted',
            'admit_decision_at' => now()->subHours(3),
            'bed_assigned_at' => now()->subHours(2),
            'departed_at' => now()->subHour(),
            'arrived_at' => now()->subHours(4),
        ]);

        $svc = app(NedocsService::class);
        $score = $svc->score();

        $this->assertNotNull($score);
        $this->assertGreaterThanOrEqual(141, $score); // severe / crit
        $this->assertSame('Severe', $svc->band($score));

        $inputs = $svc->inputs();
        $this->assertSame(4, $inputs['ventilated']);
        $this->assertSame(50, $inputs['ed_beds']); // manifest nedocs_bed_capacity
        $this->assertEqualsWithDelta(8.0, $inputs['longest_admit_hrs'], 0.1);
    }

    public function test_band_cut_points_match_the_published_scale(): void
    {
        $svc = app(NedocsService::class);

        $this->assertSame('Not busy', $svc->band(15));
        $this->assertSame('Busy', $svc->band(50));
        $this->assertSame('Extremely busy', $svc->band(90));
        $this->assertSame('Overcrowded', $svc->band(120));
        $this->assertSame('Severe', $svc->band(160));
        $this->assertSame('Dangerous', $svc->band(190));
    }
}
