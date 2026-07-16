<?php

declare(strict_types=1);

namespace Tests\Feature\Ancillary;

use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use App\Services\Pharmacy\PharmacyDispenseService;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Database\Seeders\CaseManagementSeeder;
use Database\Seeders\CommandCenterDemoSeeder;
use Database\Seeders\RtdcSeeder;
use Database\Seeders\StaffingReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PharmacyDispenseTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $anchor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anchor = CarbonImmutable::parse('2026-07-11T14:00:00Z');
        CarbonImmutable::setTestNow($this->anchor);
        $this->seed([
            RtdcSeeder::class,
            CaseManagementSeeder::class,
            StaffingReferenceSeeder::class,
            CommandCenterDemoSeeder::class,
            AncillaryReferenceSeeder::class,
        ]);
        app(AncillaryDemoScenarioService::class)->refresh(new DemoClock($this->anchor));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_station_rollup_reports_override_rate_over_a_declared_vend_denominator(): void
    {
        $payload = app(PharmacyDispenseService::class)->build();

        $this->assertSame('normal', $payload['state']);
        $this->assertSame('fresh', $payload['freshnessStatus']);
        $this->assertSame(24, $payload['window']['hours']);

        // The ED cabinet vended three orders (scenarios 2, 7, 8) and carries one
        // override transaction — an observed override rate of 1/3 = 33.3%.
        $ed = collect($payload['data']['stations'])->first(fn (array $s): bool => str_contains($s['label'], 'ED'));
        $this->assertNotNull($ed);
        $this->assertSame(3, $ed['vends']);
        $this->assertSame(1, $ed['overrides']);
        $this->assertTrue($ed['hasDenominator']);
        $this->assertEqualsWithDelta(33.3, $ed['overrideRatePercent'], 0.05);
        // 33.3% is over the 5% policy target — a server-provided status, not a JSX compare.
        $this->assertSame('over_target', $ed['overrideStatus']);
        $this->assertTrue($ed['hasActiveStockout'], 'The ED station carries the seeded open stockout.');

        // The aggregate override rate uses the SAME declared vend denominator:
        // one override across the six seeded vends = 16.7%.
        $this->assertSame(6, $payload['data']['summary']['totalVends']);
        $this->assertSame(1, $payload['data']['summary']['totalOverrides']);
        $this->assertEqualsWithDelta(16.7, $payload['data']['summary']['overrideRatePercent'], 0.05);
        $this->assertGreaterThanOrEqual(1, $payload['data']['summary']['stationsOverOverrideTarget']);
        $this->assertGreaterThanOrEqual(1, $payload['data']['summary']['stationsWithActiveStockout']);
    }

    public function test_a_station_with_no_vends_reports_no_data_not_zero_percent(): void
    {
        // The ICU cabinet only receives a refill in the demo — it never vends,
        // so it has no override/stockout denominator.
        $payload = app(PharmacyDispenseService::class)->build();

        $icu = collect($payload['data']['stations'])->first(fn (array $s): bool => str_contains($s['label'], 'ICU'));
        $this->assertNotNull($icu, 'The ICU station appears in the rollup because it has a refill transaction.');
        $this->assertSame(0, $icu['vends']);
        $this->assertFalse($icu['hasDenominator']);
        // The rate is NULL — never a fabricated 0%.
        $this->assertNull($icu['overrideRatePercent']);
        $this->assertNull($icu['stockoutRatePercent']);
        // And its status is explicitly no_data, never a target comparison.
        $this->assertSame('no_data', $icu['overrideStatus']);
        $this->assertSame('no_data', $icu['stockoutStatus']);
    }

    public function test_missing_dose_chains_are_counted_and_surfaced(): void
    {
        // Scenario 10 is the missing-dose loop: ADC vend, delivered, RX_MISSING_DOSE,
        // then a central re-dispense.
        $payload = app(PharmacyDispenseService::class)->build();

        $missing = $payload['data']['missingDose'];
        $this->assertSame(1, $missing['chainCount']);
        $this->assertCount(1, $missing['chains']);
        $chain = $missing['chains'][0];
        $this->assertSame('Ondansetron injection', $chain['medicationLabel']);
        // The re-dispense channel is central (the X-5 re-request path).
        $this->assertSame('central', $chain['reDispenseChannel']);
        $this->assertNotNull($chain['missingDoseAt']);
        // With patient detail authorized (default), an order UUID is surfaced.
        $this->assertNotNull($chain['orderUuid']);
    }

    public function test_shortage_context_surfaces_flagged_orders_with_station_linkage(): void
    {
        // Scenario 11 is verified-but-blocked-on-shortage; the ED cabinet carries
        // the matching open stockout, and the shortage context names the station.
        $payload = app(PharmacyDispenseService::class)->build();

        $shortages = $payload['data']['shortages'];
        $this->assertSame(1, $shortages['count']);
        $this->assertCount(1, $shortages['orders']);
        $order = $shortages['orders'][0];
        $this->assertSame('Ceftriaxone 1 g intravenous', $order['medicationLabel']);
        $this->assertSame('RX_STOCKOUT', $order['reasonCode']);
        $this->assertStringContainsString('ED-01', (string) $order['stationKey']);
        $this->assertNotNull($order['notedAt']);
    }

    public function test_vend_to_refill_interval_is_measured_only_where_a_refill_follows_a_vend(): void
    {
        // Med/Surg has both vends and a refill (refill-01 at 200 min, following
        // earlier vends) — a measurable interval. A station with a refill but no
        // preceding vend is excluded, never reported as zero.
        $payload = app(PharmacyDispenseService::class)->build();

        $vtr = $payload['data']['vendToRefill'];
        $this->assertGreaterThanOrEqual(1, $vtr['measurableStations']);
        foreach ($vtr['stations'] as $station) {
            $this->assertGreaterThanOrEqual(1, $station['pairCount']);
            // Every measurable interval is a positive duration, never zero.
            $this->assertNotNull($station['medianMinutes']);
            $this->assertGreaterThan(0, $station['medianMinutes']);
        }
    }

    public function test_delivery_segments_degrade_cleanly_when_delivery_tracking_is_absent(): void
    {
        // The demo projector never sets rx_dispenses.delivered_at, so delivery
        // tracking is absent: coverage is 'absent' and NO interval is fabricated.
        $payload = app(PharmacyDispenseService::class)->build();

        $delivery = $payload['data']['delivery'];
        $this->assertSame('absent', $delivery['coverage']);
        $this->assertGreaterThan(0, $delivery['dispenses']);
        $this->assertSame(0, $delivery['delivered']);
        $this->assertNull($delivery['medianMinutes']);
        $this->assertNull($delivery['p90Minutes']);
        $this->assertStringContainsStringIgnoringCase('never shown as zero', $delivery['coverageStatement']);
    }

    public function test_delivery_segments_measure_the_interval_when_delivery_timestamps_exist(): void
    {
        // Backfill one delivery timestamp so the OPTIONAL delivery segment path
        // activates: coverage flips to 'available' with a measured interval.
        $dispense = DB::table('prod.rx_dispenses')->orderBy('rx_dispense_id')->first();
        $this->assertNotNull($dispense);
        DB::table('prod.rx_dispenses')
            ->where('rx_dispense_id', $dispense->rx_dispense_id)
            ->update(['status' => 'delivered', 'delivered_at' => CarbonImmutable::parse($dispense->dispensed_at)->addMinutes(20)]);

        $payload = app(PharmacyDispenseService::class)->build();

        $delivery = $payload['data']['delivery'];
        $this->assertSame('available', $delivery['coverage']);
        $this->assertGreaterThanOrEqual(1, $delivery['delivered']);
        $this->assertSame(20, $delivery['medianMinutes']);
    }

    public function test_policy_targets_are_configuration_kept_distinct_from_measured_rates(): void
    {
        $payload = app(PharmacyDispenseService::class)->build();

        $this->assertSame('local_policy', $payload['policy']['kind']);
        $this->assertSame(PharmacyDispenseService::OVERRIDE_TARGET_RATE, $payload['policy']['overrideTargetRate']['ratePercent']);
        $this->assertSame(PharmacyDispenseService::STOCKOUT_TARGET_RATE, $payload['policy']['stockoutTargetRate']['ratePercent']);
        // The policy block never carries a measured/observed timestamp or rate.
        $this->assertArrayNotHasKey('observedAt', $payload['policy']['overrideTargetRate']);
        $this->assertArrayNotHasKey('ratePercentObserved', $payload['policy']['overrideTargetRate']);
    }

    public function test_order_level_drill_is_gated_behind_patient_detail_authorization(): void
    {
        // Without patient-detail authorization the aggregate view still renders,
        // but no order UUID or patient reference reaches the browser.
        $payload = app(PharmacyDispenseService::class)->build([], false);

        $this->assertFalse($payload['canViewPatientDetail']);
        foreach ($payload['data']['shortages']['orders'] as $order) {
            $this->assertNull($order['orderUuid']);
            $this->assertSame('Patient context restricted', $order['patientRef']);
        }
        foreach ($payload['data']['missingDose']['chains'] as $chain) {
            $this->assertNull($chain['orderUuid']);
            $this->assertSame('Patient context restricted', $chain['patientRef']);
        }
        // The station/unit aggregates remain fully populated regardless.
        $this->assertNotEmpty($payload['data']['stations']);
    }

    public function test_stale_source_renders_dispense_state_stale(): void
    {
        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'stale']);

        $payload = app(PharmacyDispenseService::class)->build();

        $this->assertSame('stale', $payload['state']);
        $this->assertSame('stale', $payload['freshnessStatus']);
    }

    public function test_station_rollup_query_uses_the_adc_station_rollup_index(): void
    {
        // The station rollup is grouped by (adc_station_id, transaction_type)
        // inside an occurred_at window — served by adc_transactions_station_rollup_idx
        // from X-1. Prove the planner reaches for that index (or a bitmap/index
        // scan over it) rather than a bare sequential scan at demo scale.
        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = collect(DB::select(<<<'SQL'
            EXPLAIN (FORMAT JSON)
            SELECT adc_station_id, transaction_type, count(*)
            FROM prod.adc_transactions
            WHERE adc_station_id IS NOT NULL
              AND occurred_at BETWEEN ?::timestamptz AND ?::timestamptz
            GROUP BY adc_station_id, transaction_type
        SQL, [$this->anchor->subDay()->toIso8601String(), $this->anchor->toIso8601String()]))
            ->pluck('QUERY PLAN')
            ->first();

        $planText = is_string($plan) ? $plan : json_encode($plan);
        $this->assertStringContainsString('adc_transactions_station_rollup_idx', (string) $planText,
            'The station rollup must be able to use the X-1 station rollup index.');
    }

    public function test_no_individual_or_user_level_dimension_exists_anywhere(): void
    {
        $payload = app(PharmacyDispenseService::class)->build();

        // The contract explicitly declares the safety posture.
        $this->assertFalse($payload['privacy']['individualPerformanceIncluded']);
        $this->assertFalse($payload['privacy']['diversionScoringIncluded']);
        $this->assertFalse($payload['privacy']['userLevelDimensionIncluded']);

        // Scan the entire data + filter surface for any user/actor/staff/risk/rank
        // token. This is the diversion-adjacent boundary — none may ever appear.
        $flat = json_encode(['data' => $payload['data'], 'filters' => $payload['filters'], 'policy' => $payload['policy']]);
        foreach ([
            'user_id', 'user_name', 'staff', 'pharmacist', 'technician', 'nurse', 'verifier',
            'employee', 'badge', 'risk_score', 'risk_rank', 'diversion_risk', 'ranked', 'outlier_user',
        ] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, (string) $flat, "Forbidden individual-level fragment leaked: {$forbidden}");
        }

        // No station or chain row may carry any user-keyed field.
        foreach ($payload['data']['stations'] as $station) {
            foreach (array_keys($station) as $key) {
                $this->assertStringNotContainsStringIgnoringCase('user', $key);
                $this->assertStringNotContainsStringIgnoringCase('staff', $key);
                $this->assertStringNotContainsStringIgnoringCase('risk', $key);
            }
        }
    }
}
