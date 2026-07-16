<?php

namespace Tests\Feature\Ancillary;

use App\Models\User;
use App\Services\Ancillary\AncillaryReadinessService;
use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use App\Services\Lab\AmReadiness\LabMorningReadinessService;
use App\Services\Lab\LabDecisionPendingService;
use App\Services\Rtdc\ServiceHuddleService;
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
 * Feature acceptance for the P4-2 opt-in Laboratory AM-readiness FORECAST on the
 * Decision-Pending Results surface and the RTDC morning huddle. Confirms the
 * forecast is off by default, only appears behind the opt-in, surfaces model
 * provenance + the rounds cutoff, never fabricates a score when signals are
 * missing, and — critically — is visually/semantically DISTINCT from the observed
 * readiness axis: a forecast NEVER converts an observed blocked lab axis to ready.
 */
class LabAmReadinessForecastTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $anchor;

    protected function setUp(): void
    {
        parent::setUp();
        // Anchor before the 08:00 rounds cutoff so the cutoff is in the future.
        $this->anchor = CarbonImmutable::parse('2026-07-11T06:00:00Z');
        CarbonImmutable::setTestNow($this->anchor);
        $this->seed([RtdcSeeder::class, CaseManagementSeeder::class, StaffingReferenceSeeder::class, CommandCenterDemoSeeder::class, AncillaryReferenceSeeder::class]);
        app(AncillaryDemoScenarioService::class)->refresh(new DemoClock($this->anchor));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_forecast_is_available_but_off_by_default(): void
    {
        $payload = app(LabDecisionPendingService::class)->build([], true, true);

        $this->assertTrue($payload['amReadinessForecast']['available']);
        $this->assertFalse($payload['amReadinessForecast']['enabled']);
        $this->assertFalse($payload['amReadinessForecast']['requested']);
        $this->assertNotNull($payload['amReadinessForecast']['model']);
        $this->assertTrue($payload['amReadinessForecast']['model']['synthetic']);
        $this->assertNotEmpty($payload['amReadinessForecast']['roundsCutoffLabel']);
        // No row carries a forecast when forecasting was not opted into.
        $this->assertTrue(collect($payload['data'])->every(fn (array $row): bool => $row['amReadiness'] === null));
    }

    public function test_opt_in_forecasts_each_row_with_provenance_and_a_synthetic_label(): void
    {
        $payload = app(LabDecisionPendingService::class)->build(['forecast' => true], true, true);

        $this->assertTrue($payload['amReadinessForecast']['enabled']);
        $this->assertTrue($payload['amReadinessForecast']['requested']);

        $model = $payload['amReadinessForecast']['model'];
        $this->assertNotSame('', $model['modelVersion']);
        $this->assertNotNull($model['calibratedAt']);
        $this->assertTrue($model['synthetic']);
        $this->assertNotEmpty($model['syntheticLabel']);
        $this->assertArrayHasKey('calibrationError', $model['evaluation']);
        $this->assertArrayHasKey('discriminationAuc', $model['evaluation']);
        $this->assertArrayHasKey('coverage', $model['evaluation']);
        $this->assertArrayHasKey('naiveBaseline', $model['evaluation']);

        $forecasts = collect($payload['data'])->pluck('amReadiness')->filter();
        $this->assertNotEmpty($forecasts, 'Every ranked row should carry a forecast when opted in.');
        $forecasts->each(function (array $forecast): void {
            $this->assertSame('forecast', $forecast['kind']);
            $this->assertContains($forecast['availability'], ['available', 'low_confidence', 'unavailable']);
            $this->assertNotEmpty($forecast['roundsCutoffLabel']);
            if ($forecast['availability'] !== 'unavailable') {
                $this->assertGreaterThanOrEqual(0.0, $forecast['probability']);
                $this->assertLessThanOrEqual(1.0, $forecast['probability']);
                $this->assertContains($forecast['band'], ['on_track', 'at_risk', 'unlikely']);
            }
        });
    }

    public function test_observed_and_predicted_readiness_are_semantically_distinct(): void
    {
        $withForecast = app(LabDecisionPendingService::class)->build(['forecast' => true], true, true);
        $withoutForecast = app(LabDecisionPendingService::class)->build([], true, true);

        // The observed SLA/urgency, ranking, and destination of every row are
        // byte-identical whether or not the forecast is requested — the forecast
        // lives in a separate `amReadiness` field and mutates no observed state.
        // Compare JSON-normalized observed state so equal-valued empty scope
        // objects from two separate builds do not fail on object identity.
        $strip = fn (array $payload): string => json_encode(collect($payload['data'])->map(fn (array $row): array => [
            'sla' => $row['sla'],
            'ranking' => $row['ranking'],
            'destination' => $row['destination'],
            'currentStage' => $row['currentStage'],
        ])->all());

        $this->assertSame($strip($withoutForecast), $strip($withForecast));

        // And the forecast field is a genuinely separate structure.
        collect($withForecast['data'])->each(function (array $row): void {
            $this->assertIsArray($row['amReadiness']);
            $this->assertArrayHasKey('kind', $row['amReadiness']);
            $this->assertSame('forecast', $row['amReadiness']['kind']);
            $this->assertArrayNotHasKey('urgency', $row['amReadiness']);
        });
    }

    public function test_a_high_forecast_never_converts_an_observed_blocked_axis_to_ready(): void
    {
        // The demo's discharge_gate order blocks a live encounter's lab axis.
        $encounterId = (int) DB::table('prod.ancillary_orders as o')
            ->where('o.department', 'lab')
            ->whereRaw("o.metadata->>'decision_class' = 'discharge_gate'")
            ->whereNotNull('o.encounter_id')
            ->value('o.encounter_id');
        $this->assertGreaterThan(0, $encounterId, 'The demo must expose a discharge-gate lab order on an encounter.');

        // OBSERVED axis (never touched by the forecast) is blocked.
        $readiness = app(AncillaryReadinessService::class);
        $observedBefore = $readiness->laboratoryForEncounters([$encounterId])->get($encounterId);
        $this->assertSame('blocked', $observedBefore['status']);
        $this->assertTrue($observedBefore['blocking']);

        // Compute the forecast (opt-in). Even if it is high, it lives elsewhere.
        $forecasts = app(LabMorningReadinessService::class)->forecastByOrderUuid(
            DB::table('prod.ancillary_orders as o')
                ->where('o.department', 'lab')
                ->whereRaw("o.metadata->>'decision_class' = 'discharge_gate'")
                ->pluck('o.order_uuid')->map(fn (mixed $uuid): string => (string) $uuid)->all(),
        );
        $this->assertNotEmpty($forecasts);

        // OBSERVED axis is UNCHANGED after the forecast ran — still blocked.
        $observedAfter = $readiness->laboratoryForEncounters([$encounterId])->get($encounterId);
        $this->assertSame('blocked', $observedAfter['status']);
        $this->assertTrue($observedAfter['blocking']);
        $this->assertSame($observedBefore['pendingCount'], $observedAfter['pendingCount']);
    }

    public function test_missing_operational_features_yield_unavailable_not_a_fabricated_forecast(): void
    {
        // Null the test family so queue-depth resolution has no family bucket and
        // remove all results so analyzer state cannot be resolved — the forecast
        // must degrade to unavailable, never a number.
        DB::table('prod.lab_results')->delete();

        $forecasts = app(LabMorningReadinessService::class)->forecastByOrderUuid(
            DB::table('prod.ancillary_orders as o')
                ->where('o.department', 'lab')
                ->whereRaw("COALESCE(o.metadata->>'decision_class', 'none') <> 'none'")
                ->pluck('o.order_uuid')->map(fn (mixed $uuid): string => (string) $uuid)->all(),
        );
        $this->assertNotEmpty($forecasts);
        foreach ($forecasts as $forecast) {
            $this->assertSame('unavailable', $forecast['availability']);
            $this->assertNull($forecast['probability']);
            $this->assertNull($forecast['band']);
            $this->assertNotEmpty($forecast['missingSignals']);
        }
    }

    public function test_rtdc_morning_huddle_surfaces_a_distinct_planning_forecast(): void
    {
        $huddle = app(ServiceHuddleService::class)->build();

        $this->assertArrayHasKey('amReadinessForecast', $huddle);
        $forecast = $huddle['amReadinessForecast'];
        $this->assertTrue($forecast['available']);
        $this->assertNotNull($forecast['model']);
        $this->assertTrue($forecast['model']['synthetic']);
        $this->assertNotEmpty($forecast['roundsCutoffLabel']);
        $this->assertArrayHasKey('bands', $forecast);
        $this->assertArrayHasKey('on_track', $forecast['bands']);
        // The huddle roster (observed) and the forecast (predicted) are distinct
        // top-level keys; the forecast never appears inside a patient row.
        collect($huddle['patients'])->each(function (array $patient): void {
            $this->assertArrayNotHasKey('amReadiness', $patient);
        });
    }

    public function test_inertia_render_and_api_share_the_forecast_contract(): void
    {
        $user = User::factory()->create(['role' => 'lab_manager', 'must_change_password' => false]);
        $expected = app(LabDecisionPendingService::class)->build(['forecast' => true], false, false);

        $this->actingAs($user)->getJson('/api/lab/pending-decisions?forecast=1')
            ->assertOk()
            ->assertJsonPath('amReadinessForecast.enabled', true)
            ->assertJsonPath('amReadinessForecast.model.synthetic', true);
        $this->assertTrue($expected['amReadinessForecast']['enabled']);
    }
}
