<?php

declare(strict_types=1);

namespace Tests\Feature\Ancillary;

use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use App\Services\Pharmacy\ControlledSubstanceOperationsService;
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
 * X-10 — Controlled Substances OPERATIONAL view. This is the diversion-ADJACENT
 * boundary (§13): the single most important assertion in this file is that no
 * individual/user/staff/person dimension, no per-person risk score, and no
 * ranked staff list appears anywhere in the data surface. Aging against the
 * shift-end policy and resolution transitions are proven with the demo fixture.
 */
final class PharmacyControlledTest extends TestCase
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

    public function test_only_the_unresolved_controlled_discrepancy_is_open(): void
    {
        // The demo seeds two controlled discrepancies on the same morphine local
        // code: disc-01 (Med/Surg) is OPENED then RESOLVED on the same key, and
        // disc-02 (ED) is opened and never resolved. Only the ED one is open.
        $payload = app(ControlledSubstanceOperationsService::class)->build();

        $this->assertSame('normal', $payload['state']);
        $open = $payload['data']['openDiscrepancies'];
        $this->assertSame(1, $open['count']);
        $this->assertCount(1, $open['items']);

        $item = $open['items'][0];
        $this->assertStringContainsString('Morphine', $item['medicationLabel']);
        // It carries a pseudonymous discrepancy key, a station, and a unit — never a person.
        $this->assertStringContainsString('disc:02', $item['discrepancyKey']);
        $this->assertGreaterThan(0, $item['stationId']);
    }

    public function test_a_resolved_discrepancy_pair_no_longer_counts_as_open(): void
    {
        // Baseline: exactly one open (ED). Now resolve the ED discrepancy too by
        // inserting a matching discrepancy_resolved on the same key/station.
        $before = app(ControlledSubstanceOperationsService::class)->build();
        $this->assertSame(1, $before['data']['openDiscrepancies']['count']);

        $edOpen = DB::table('prod.adc_transactions')
            ->where('transaction_type', 'discrepancy_open')
            ->where('discrepancy_key', 'like', '%disc:02')
            ->first();
        $this->assertNotNull($edOpen);

        DB::table('prod.adc_transactions')->insert([
            'transaction_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'adc_station_id' => $edOpen->adc_station_id,
            'source_id' => $edOpen->source_id,
            'source_transaction_key' => $edOpen->source_transaction_key.'-resolved-test',
            'unit_id' => $edOpen->unit_id,
            'transaction_type' => 'discrepancy_resolved',
            'is_controlled' => true,
            'discrepancy_key' => $edOpen->discrepancy_key,
            'occurred_at' => $this->anchor->subMinutes(10),
            'metadata' => json_encode(['medication_label' => 'Morphine injection']),
            'created_at' => $this->anchor,
            'updated_at' => $this->anchor,
        ]);

        $after = app(ControlledSubstanceOperationsService::class)->build();
        // The pair is now closed: zero open, and the station overlay drops to zero.
        $this->assertSame(0, $after['data']['openDiscrepancies']['count']);
        foreach ($after['data']['stationPatterns']['stations'] as $station) {
            $this->assertSame(0, $station['openDiscrepancies']);
        }
    }

    public function test_open_discrepancy_is_aged_past_the_applicable_shift_end(): void
    {
        // Shift-ends are 07:00/19:00 America/New_York. The ED discrepancy opened
        // at 12:25Z (08:25 EDT); its applicable shift-end is 07:00 EDT the same
        // day. At now() = 10:00 EDT it is ~180 min past shift-end → past_policy.
        config(['pharmacy.controlled.shift_end.times' => ['07:00', '19:00']]);
        config(['pharmacy.controlled.shift_end.timezone' => 'America/New_York']);
        config(['pharmacy.controlled.reconciliation_grace_minutes' => 0]);

        $payload = app(ControlledSubstanceOperationsService::class)->build();
        $item = $payload['data']['openDiscrepancies']['items'][0];

        $this->assertSame('past_policy', $item['agingStatus']);
        $this->assertGreaterThan(0, $item['minutesPastShiftEnd']);
        // The shift-end (07:00 EDT / 11:00Z) precedes the open (12:25Z), so the
        // clock has run further past the shift boundary than since opening.
        $this->assertGreaterThan(0, $item['minutesOpen']);
        $this->assertGreaterThan($item['minutesOpen'], $item['minutesPastShiftEnd']);
        // The summary rolls the past-policy count up.
        $this->assertSame(1, $payload['data']['summary']['openDiscrepanciesPastPolicy']);
        $this->assertNotNull($payload['data']['summary']['oldestOpenMinutes']);
    }

    public function test_a_generous_grace_keeps_a_fresh_discrepancy_within_the_shift(): void
    {
        // Insert an OPEN controlled discrepancy that opened five minutes ago, so
        // it is within the current shift — and a large grace guarantees it is not
        // past policy regardless of the shift boundary.
        config(['pharmacy.controlled.reconciliation_grace_minutes' => 100000]);

        $edOpen = DB::table('prod.adc_transactions')
            ->where('transaction_type', 'discrepancy_open')->first();
        $this->assertNotNull($edOpen);

        DB::table('prod.adc_transactions')->insert([
            'transaction_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'adc_station_id' => $edOpen->adc_station_id,
            'source_id' => $edOpen->source_id,
            'source_transaction_key' => 'demo:rx:txn:disc-fresh-test',
            'unit_id' => $edOpen->unit_id,
            'transaction_type' => 'discrepancy_open',
            'is_controlled' => true,
            'discrepancy_key' => 'demo:rx:disc:fresh-test',
            'occurred_at' => $this->anchor->subMinutes(5),
            'metadata' => json_encode(['medication_label' => 'Hydromorphone injection']),
            'created_at' => $this->anchor,
            'updated_at' => $this->anchor,
        ]);

        $payload = app(ControlledSubstanceOperationsService::class)->build();
        $fresh = collect($payload['data']['openDiscrepancies']['items'])
            ->firstWhere('discrepancyKey', 'demo:rx:disc:fresh-test');
        $this->assertNotNull($fresh);
        // With an enormous grace nothing is ever "past policy".
        $this->assertNotSame('past_policy', $fresh['agingStatus']);
        $this->assertSame(0, $payload['data']['summary']['openDiscrepanciesPastPolicy']);
    }

    public function test_controlled_override_rate_is_over_a_declared_controlled_vend_denominator(): void
    {
        // Seed a controlled vend and a controlled override at one station so the
        // rate has a real controlled denominator (the demo's override is on a
        // non-controlled med, so we add controlled events explicitly).
        $station = DB::table('prod.adc_transactions')->value('adc_station_id');
        $this->assertNotNull($station);
        $sourceId = DB::table('prod.adc_transactions')->value('source_id');
        $unitId = DB::table('prod.adc_transactions')->where('adc_station_id', $station)->value('unit_id');

        foreach ([
            ['type' => 'vend', 'suffix' => 'cv-1'],
            ['type' => 'vend', 'suffix' => 'cv-2'],
            ['type' => 'vend', 'suffix' => 'cv-3'],
            ['type' => 'vend', 'suffix' => 'cv-4'],
            ['type' => 'override', 'suffix' => 'co-1'],
        ] as $row) {
            DB::table('prod.adc_transactions')->insert([
                'transaction_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'adc_station_id' => $station,
                'source_id' => $sourceId,
                'source_transaction_key' => 'demo:rx:txn:ctrl-'.$row['suffix'],
                'unit_id' => $unitId,
                'transaction_type' => $row['type'],
                'is_controlled' => true,
                'occurred_at' => $this->anchor->subMinutes(30),
                'metadata' => json_encode(['medication_label' => 'Morphine injection']),
                'created_at' => $this->anchor,
                'updated_at' => $this->anchor,
            ]);
        }

        $payload = app(ControlledSubstanceOperationsService::class)->build();
        $row = collect($payload['data']['stationPatterns']['stations'])
            ->firstWhere('stationId', (int) $station);
        $this->assertNotNull($row);
        $this->assertSame(4, $row['controlledVends']);
        $this->assertSame(1, $row['controlledOverrides']);
        $this->assertTrue($row['hasDenominator']);
        // 1 override / 4 controlled vends = 25% — over the 5% policy target.
        $this->assertEqualsWithDelta(25.0, $row['overrideRatePercent'], 0.05);
        $this->assertSame('over_target', $row['overrideStatus']);
    }

    public function test_a_station_with_no_controlled_vends_reports_no_data_not_zero_percent(): void
    {
        // The ED station carries an open controlled discrepancy but no controlled
        // vend in the demo, so its override rate has no denominator.
        $payload = app(ControlledSubstanceOperationsService::class)->build();

        $withOpen = collect($payload['data']['stationPatterns']['stations'])
            ->first(fn (array $s): bool => $s['openDiscrepancies'] > 0 && $s['controlledVends'] === 0);
        $this->assertNotNull($withOpen);
        $this->assertFalse($withOpen['hasDenominator']);
        $this->assertNull($withOpen['overrideRatePercent']);
        $this->assertSame('no_data', $withOpen['overrideStatus']);
    }

    public function test_policy_is_configuration_kept_distinct_from_measured_values(): void
    {
        $payload = app(ControlledSubstanceOperationsService::class)->build();

        $this->assertSame('local_policy', $payload['policy']['kind']);
        $this->assertNotEmpty($payload['policy']['shiftEnd']['times']);
        $this->assertArrayHasKey('graceMinutes', $payload['policy']['shiftEnd']);
        // The policy block never carries a measured/observed timestamp or rate.
        $this->assertArrayNotHasKey('observedAt', $payload['policy']['shiftEnd']);
        $this->assertArrayNotHasKey('measuredRate', $payload['policy']['overrideTargetRate']);
    }

    public function test_the_out_of_scope_statement_is_present_and_non_accusatory(): void
    {
        $payload = app(ControlledSubstanceOperationsService::class)->build();

        $scope = $payload['scope'];
        $this->assertFalse($scope['diversionInvestigationInScope']);
        $this->assertFalse($scope['individualScoringInScope']);
        $this->assertFalse($scope['individualPerformanceIncluded']);
        $this->assertFalse($scope['userLevelDimensionIncluded']);
        $this->assertSame('unit_and_station', $scope['aggregationLevel']);
        $this->assertSame('operational_non_accusatory', $scope['tone']);
        $this->assertStringContainsString('out of scope', strtolower($scope['statement']));
        $this->assertStringContainsString('unit and station', strtolower($scope['statement']));
        // Export is deferred / not enabled unless separately authorized.
        $this->assertFalse($scope['exportEnabled']);
        $this->assertStringContainsString('deferred', strtolower($scope['exportStatement']));

        // The tone must not carry accusatory framing anywhere in the data surface.
        $flat = strtolower((string) json_encode($payload['data']));
        foreach (['divert', 'suspect', 'culprit', 'offender', 'blame', 'accus', 'theft', 'steal'] as $accusatory) {
            $this->assertStringNotContainsString($accusatory, $flat, "Accusatory fragment leaked into the data surface: {$accusatory}");
        }
    }

    public function test_stale_source_renders_controlled_state_stale(): void
    {
        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'stale']);

        $payload = app(ControlledSubstanceOperationsService::class)->build();

        $this->assertSame('stale', $payload['state']);
        $this->assertSame('stale', $payload['freshnessStatus']);
    }

    public function test_no_individual_or_user_level_dimension_exists_anywhere(): void
    {
        $payload = app(ControlledSubstanceOperationsService::class)->build();

        // The scope block explicitly declares the safety posture.
        $this->assertFalse($payload['scope']['individualPerformanceIncluded']);
        $this->assertFalse($payload['scope']['userLevelDimensionIncluded']);

        // Scan the DATA surface only (never the deliberate out-of-scope
        // disclaimer text) for any user/actor/staff/person/risk/rank token. This
        // is the diversion-adjacent boundary — none may EVER appear here.
        // Note: bare "name" is intentionally NOT scanned — unitName/stationType
        // are legitimate LOCATION labels. Person-name fields are caught by the
        // precise fragments below and the recursive key guard (staff/actor/etc.).
        $dataFlat = strtolower((string) json_encode($payload['data']));
        foreach ([
            'user_id', 'user_name', 'username', 'staff', 'pharmacist', 'technician',
            'nurse', 'verifier', 'employee', 'badge', 'actor', 'performed_by',
            'person_', 'risk_score', 'risk_rank', 'diversion_risk',
            'diversion_score', 'ranked', 'outlier_user',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dataFlat, "Forbidden individual-level fragment leaked into the data surface: {$forbidden}");
        }

        // Recursively assert no key anywhere under `data` carries an
        // individual-level fragment (a structural guard beyond the value scan).
        $this->assertNoIndividualKeys($payload['data'], 'data');
    }

    /**
     * Recursively walk an array asserting no KEY contains an individual-level
     * fragment. 'name' is allowed only as unitName/stationName-style location
     * labels, so we forbid the person-name fragments precisely.
     */
    private function assertNoIndividualKeys(mixed $value, string $path): void
    {
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $key => $nested) {
            if (is_string($key)) {
                $lower = strtolower($key);
                foreach (['user', 'staff', 'person', 'actor', 'verifier', 'employee', 'badge', 'risk', 'rank', 'performed', 'diversion'] as $fragment) {
                    $this->assertStringNotContainsString($fragment, $lower, "Forbidden individual-level key '{$key}' at {$path}");
                }
            }
            $this->assertNoIndividualKeys($nested, $path.'.'.$key);
        }
    }

    public function test_open_discrepancy_pairing_uses_the_open_discrepancy_index(): void
    {
        // The open-discrepancy pairing is an anti-join of discrepancy_open against
        // discrepancy_resolved on (adc_station_id, discrepancy_key) — served by
        // adc_transactions_open_discrepancy_idx from X-1. Prove the planner can
        // reach the partial index rather than a bare sequential scan at demo scale.
        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = collect(DB::select(<<<'SQL'
            EXPLAIN (FORMAT JSON)
            SELECT o.adc_station_id, o.discrepancy_key, o.occurred_at
            FROM prod.adc_transactions o
            WHERE o.transaction_type = 'discrepancy_open'
              AND NOT EXISTS (
                SELECT 1 FROM prod.adc_transactions r
                WHERE r.adc_station_id = o.adc_station_id
                  AND r.discrepancy_key = o.discrepancy_key
                  AND r.transaction_type = 'discrepancy_resolved'
              )
        SQL))->pluck('QUERY PLAN')->first();

        $planText = is_string($plan) ? $plan : json_encode($plan);
        $this->assertStringContainsString('adc_transactions_open_discrepancy_idx', (string) $planText,
            'The open-discrepancy pairing must be able to use the X-1 partial index.');
    }
}
