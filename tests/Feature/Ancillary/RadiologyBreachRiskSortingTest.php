<?php

namespace Tests\Feature\Ancillary;

use App\Models\User;
use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use App\Services\Radiology\RadiologyWorklistService;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Database\Seeders\CaseManagementSeeder;
use Database\Seeders\CommandCenterDemoSeeder;
use Database\Seeders\RtdcSeeder;
use Database\Seeders\StaffingReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature acceptance for the P4-1 opt-in breach-risk planning column on the
 * Radiology worklist. Confirms scoring is off by default, only appears behind
 * the opt-in, surfaces model provenance + top factors, and never fabricates a
 * score when live operational signals are missing.
 */
class RadiologyBreachRiskSortingTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $anchor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anchor = CarbonImmutable::parse('2026-07-11T14:00:00Z');
        CarbonImmutable::setTestNow($this->anchor);
        $this->seed([RtdcSeeder::class, CaseManagementSeeder::class, StaffingReferenceSeeder::class, CommandCenterDemoSeeder::class, AncillaryReferenceSeeder::class]);
        app(AncillaryDemoScenarioService::class)->refresh(new DemoClock($this->anchor));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_risk_scoring_is_available_but_off_by_default(): void
    {
        $worklist = app(RadiologyWorklistService::class)->build(['perPage' => 50]);

        $this->assertTrue($worklist['predictiveSort']['available']);
        $this->assertFalse($worklist['predictiveSort']['enabled']);
        $this->assertFalse($worklist['predictiveSort']['requested']);
        $this->assertNotNull($worklist['predictiveSort']['model']);
        $this->assertTrue($worklist['predictiveSort']['model']['synthetic']);
        // No row carries a score when scoring was not opted into.
        $this->assertTrue(collect($worklist['data'])->every(fn (array $row): bool => $row['risk'] === null));
    }

    public function test_opt_in_scores_the_page_with_provenance_factors_and_a_synthetic_label(): void
    {
        $worklist = app(RadiologyWorklistService::class)->build(['perPage' => 50, 'risk' => true, 'sort' => 'breach_risk']);

        $this->assertTrue($worklist['predictiveSort']['enabled']);
        $this->assertTrue($worklist['predictiveSort']['requested']);

        $model = $worklist['predictiveSort']['model'];
        $this->assertNotSame('', $model['modelVersion']);
        $this->assertNotNull($model['calibratedAt']);
        $this->assertTrue($model['synthetic']);
        $this->assertNotEmpty($model['syntheticLabel']);
        $this->assertArrayHasKey('calibrationError', $model['evaluation']);
        $this->assertArrayHasKey('discriminationAuc', $model['evaluation']);
        $this->assertArrayHasKey('brierScore', $model['evaluation']);
        $this->assertArrayHasKey('coverage', $model['evaluation']);
        $this->assertArrayHasKey('naiveBaseline', $model['evaluation']);

        $scored = collect($worklist['data'])->filter(fn (array $row): bool => $row['risk'] !== null && $row['risk']['availability'] !== 'unavailable');
        $this->assertNotEmpty($scored, 'At least one open order should carry a defensible score.');
        $scored->each(function (array $row): void {
            $risk = $row['risk'];
            $this->assertContains($risk['availability'], ['available', 'low_confidence']);
            $this->assertGreaterThanOrEqual(0.0, $risk['probability']);
            $this->assertLessThanOrEqual(1.0, $risk['probability']);
            $this->assertContains($risk['band'], ['low', 'moderate', 'high']);
            $this->assertNotEmpty($risk['explanation']);
            foreach ($risk['factors'] as $factor) {
                $this->assertArrayHasKey('label', $factor);
                $this->assertGreaterThan(0.0, $factor['contribution']);
            }
        });
    }

    public function test_scored_page_is_ordered_by_descending_risk(): void
    {
        $worklist = app(RadiologyWorklistService::class)->build(['perPage' => 50, 'risk' => true, 'sort' => 'breach_risk']);
        $probabilities = collect($worklist['data'])
            ->map(fn (array $row): float => $row['risk']['probability'] ?? -1.0)
            ->values()
            ->all();

        $sorted = $probabilities;
        rsort($sorted);
        $this->assertSame($sorted, $probabilities, 'The opted-in page must be ordered by descending planning risk (unavailable last).');
    }

    public function test_missing_operational_features_yield_unavailable_not_a_fabricated_score(): void
    {
        // Retire every scanner so scanner-down state cannot be resolved; the
        // score for such orders must degrade to unavailable, never a number.
        DB::table('prod.rad_exams')->update(['modality_code' => null]);

        $worklist = app(RadiologyWorklistService::class)->build(['perPage' => 50, 'risk' => true]);
        $rows = collect($worklist['data'])->filter(fn (array $row): bool => $row['risk'] !== null);
        $this->assertNotEmpty($rows);
        $rows->each(function (array $row): void {
            $this->assertSame('unavailable', $row['risk']['availability']);
            $this->assertNull($row['risk']['probability']);
            $this->assertNull($row['risk']['band']);
            $this->assertNotEmpty($row['risk']['missingSignals']);
        });
    }

    public function test_inertia_render_and_api_share_the_scored_contract(): void
    {
        $user = User::factory()->create(['role' => 'radiology_manager', 'must_change_password' => false]);
        $expected = app(RadiologyWorklistService::class)->build(['risk' => true, 'sort' => 'breach_risk', 'perPage' => 5]);

        $this->actingAs($user)->getJson('/api/radiology/worklist?risk=1&sort=breach_risk&perPage=5')
            ->assertOk()
            ->assertExactJson($expected);
    }
}
