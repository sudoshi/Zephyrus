<?php

namespace Tests\Feature\Ancillary;

use App\Models\Pharmacy\AdcStation;
use App\Models\User;
use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use App\Services\Pharmacy\PharmacyDispenseService;
use App\Services\Pharmacy\PharmacyFlowBoardService;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Database\Seeders\CaseManagementSeeder;
use Database\Seeders\CommandCenterDemoSeeder;
use Database\Seeders\RtdcSeeder;
use Database\Seeders\StaffingReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** End-to-end acceptance for the opt-in P4-3 Pharmacy planning forecasts. */
class PharmacyForecastTest extends TestCase
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

    public function test_both_forecasts_are_off_by_default(): void
    {
        $flow = app(PharmacyFlowBoardService::class)->build();
        $dispense = app(PharmacyDispenseService::class)->build();

        $this->assertFalse($flow['filters']['forecast']);
        $this->assertFalse($flow['planningForecast']['requested']);
        $this->assertFalse($flow['planningForecast']['enabled']);
        $this->assertNull($flow['planningForecast']['queue']);
        $this->assertFalse($dispense['filters']['forecast']);
        $this->assertFalse($dispense['planningForecast']['requested']);
        $this->assertFalse($dispense['planningForecast']['enabled']);
        $this->assertNull($dispense['planningForecast']['stockout']);
    }

    public function test_queue_opt_in_returns_eight_nonnegative_hours_known_demand_and_provenance(): void
    {
        DB::table('prod.rx_orders')
            ->whereNotIn('order_status', ['administered', 'discontinued', 'cancelled', 'completed'])
            ->orderBy('rx_order_id')
            ->limit(1)
            ->update(['due_at' => $this->anchor->addHours(2)]);

        $payload = app(PharmacyFlowBoardService::class)->build(['forecast' => true]);
        $forecast = $payload['planningForecast']['queue'];

        $this->assertTrue($payload['planningForecast']['requested']);
        $this->assertSame('forecast', $forecast['kind']);
        $this->assertSame('verification_queue_depth', $forecast['target']);
        $this->assertSame('low_confidence', $forecast['status'], 'The compact demo history must be labelled low confidence.');
        $this->assertCount(8, $forecast['points']);
        $this->assertIsInt($forecast['history']['historyHours']);
        $this->assertSame($payload['data']['verificationQueue']['depth'], $forecast['currentDepth']);
        $this->assertSame(range(1, 8), array_column($forecast['points'], 'horizonHour'));
        $this->assertTrue(collect($forecast['points'])->every(fn (array $point): bool => $point['forecastDepth'] >= 0 && $point['lowerDepth'] >= 0 && $point['upperDepth'] >= $point['lowerDepth']));
        $this->assertGreaterThanOrEqual(1, collect($forecast['points'])->sum('scheduledDemand'));
        $this->assertTrue($forecast['provenance']['synthetic']);
        $this->assertTrue($forecast['provenance']['queueEvaluation']['beatsBaselines']);
        $this->assertNotEmpty($forecast['missingSignals']);
    }

    public function test_stockout_opt_in_preserves_observed_missing_and_stale_coverage_states(): void
    {
        $payload = app(PharmacyDispenseService::class)->build(['forecast' => true]);
        $forecast = $payload['planningForecast']['stockout'];
        $rows = collect($forecast['rows']);

        $this->assertSame('station_medication_stockout_within_six_hours', $forecast['target']);
        $this->assertTrue($forecast['provenance']['synthetic']);
        $this->assertTrue($forecast['provenance']['stockoutEvaluation']['beatsBaseline']);

        $current = $rows->first(fn (array $row): bool => $row['localCode'] === 'ONDANSETRON_INJ' && str_contains($row['stationLabel'], 'ED Bay'));
        $this->assertNotNull($current);
        $this->assertContains($current['availability'], ['available', 'low_confidence']);
        $this->assertNotNull($current['probability']);
        $this->assertContains($current['band'], ['low', 'watch', 'elevated']);

        $observed = $rows->first(fn (array $row): bool => $row['localCode'] === 'CEFTRIAXONE_1G_IV' && $row['availability'] === 'observed');
        $this->assertNotNull($observed);
        $this->assertSame('observed', $observed['availability']);
        $this->assertSame('stockout_open', $observed['observedState']);
        $this->assertNull($observed['probability']);
        $this->assertNull($observed['band']);

        $stale = $rows->first(fn (array $row): bool => $row['localCode'] === 'MORPHINE_INJ' && str_contains($row['stationLabel'], 'Med/Surg'));
        $this->assertNotNull($stale);
        $this->assertSame('low_confidence', $stale['availability']);
        $this->assertNotNull($stale['probability']);
        $this->assertNotNull($stale['inventory']['capturedAt']);
        $this->assertContains('inventory_snapshot_stale', $stale['missingSignals']);

        $velocityOnly = $rows->first(fn (array $row): bool => $row['localCode'] === 'HEPARIN_INFUSION' && str_contains($row['stationLabel'], 'ICU'));
        $this->assertNotNull($velocityOnly);
        $this->assertSame('velocity_only', $velocityOnly['availability']);
        $this->assertNull($velocityOnly['probability']);
        $this->assertNull($velocityOnly['band']);
        $this->assertContains($velocityOnly['terminologyStatus'], ['mapped', 'unmapped_local']);
        $this->assertGreaterThanOrEqual(1, $forecast['coverage']['probabilityAvailable']);
        $this->assertGreaterThanOrEqual(1, $forecast['coverage']['velocityOnly']);
        $this->assertGreaterThanOrEqual(1, $forecast['coverage']['observedStockouts']);

        foreach ($rows->where('availability', 'available')->groupBy('availability') as $availableRows) {
            $probabilities = $availableRows->pluck('probability')->all();
            $sorted = $probabilities;
            rsort($sorted);
            $this->assertSame($sorted, $probabilities, 'Probability-available rows must be sorted descending within their coverage state.');
        }
    }

    public function test_opt_in_never_mutates_observed_flow_dispense_or_sla_state(): void
    {
        $flowOff = app(PharmacyFlowBoardService::class)->build();
        $flowOn = app(PharmacyFlowBoardService::class)->build(['forecast' => true]);
        $this->assertSame(json_encode($flowOff['data']), json_encode($flowOn['data']));
        $this->assertSame($flowOff['state'], $flowOn['state']);
        $this->assertSame(json_encode($flowOff['appliedSlaDefinitions']), json_encode($flowOn['appliedSlaDefinitions']));

        $dispenseOff = app(PharmacyDispenseService::class)->build();
        $dispenseOn = app(PharmacyDispenseService::class)->build(['forecast' => true]);
        $this->assertSame($dispenseOff['data'], $dispenseOn['data']);
        $this->assertSame($dispenseOff['state'], $dispenseOn['state']);
        $this->assertSame($dispenseOff['policy'], $dispenseOn['policy']);
    }

    public function test_missing_inventory_yields_only_observed_or_velocity_context_with_no_probability(): void
    {
        AdcStation::query()->get()->each(function (AdcStation $station): void {
            $metadata = is_array($station->metadata) ? $station->metadata : [];
            unset($metadata['inventory']);
            $station->metadata = $metadata;
            $station->save();
        });

        $forecast = app(PharmacyDispenseService::class)->build(['forecast' => true])['planningForecast']['stockout'];

        $this->assertNotEmpty($forecast['rows']);
        $this->assertSame(0, $forecast['coverage']['probabilityAvailable']);
        foreach ($forecast['rows'] as $row) {
            $this->assertContains($row['availability'], ['observed', 'velocity_only']);
            $this->assertNull($row['probability']);
            $this->assertNull($row['band']);
        }
    }

    public function test_station_filter_applies_to_forecast_and_contract_has_no_individual_dimension(): void
    {
        $forecast = app(PharmacyDispenseService::class)->build(['forecast' => true, 'stationType' => 'general'])['planningForecast']['stockout'];

        $this->assertNotEmpty($forecast['rows']);
        $this->assertTrue(collect($forecast['rows'])->every(fn (array $row): bool => ! str_contains($row['stationLabel'], 'ED Bay')));
        $this->assertFalse($forecast['privacy']['individualStaffFeaturesIncluded']);
        $this->assertFalse($forecast['privacy']['controlledDiversionScoreIncluded']);

        $keys = collect($forecast['rows'])->flatMap(fn (array $row): array => array_keys($row));
        foreach (['user', 'staff', 'pharmacist', 'technician', 'nurse', 'verifier', 'employee', 'badge', 'rank'] as $fragment) {
            $this->assertTrue($keys->every(fn (string $key): bool => ! str_contains(strtolower($key), $fragment)), "Forecast row key leaked forbidden fragment {$fragment}.");
        }
    }

    public function test_web_api_and_boolean_validation_share_the_opt_in_contract(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);

        $this->actingAs($admin)->get('/pharmacy?forecast=1')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Pharmacy/FlowBoard')
                ->where('flowBoard.planningForecast.requested', true)
                ->where('flowBoard.planningForecast.queue.kind', 'forecast'));
        $this->actingAs($admin)->getJson('/api/pharmacy/flow-board?forecast=1')->assertOk()
            ->assertJsonPath('planningForecast.requested', true)
            ->assertJsonCount(8, 'planningForecast.queue.points');

        $this->actingAs($admin)->get('/pharmacy/dispense?forecast=1')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Pharmacy/Dispense')
                ->where('dispense.planningForecast.requested', true)
                ->where('dispense.planningForecast.stockout.kind', 'forecast'));
        $this->actingAs($admin)->getJson('/api/pharmacy/dispense?forecast=1')->assertOk()
            ->assertJsonPath('planningForecast.requested', true)
            ->assertJsonPath('planningForecast.stockout.privacy.stationMedicationAggregatesOnly', true);

        $this->actingAs($admin)->getJson('/api/pharmacy/flow-board?forecast=maybe')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/api/pharmacy/dispense?forecast=maybe')->assertUnprocessable();
    }
}
