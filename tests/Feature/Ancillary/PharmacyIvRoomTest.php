<?php

declare(strict_types=1);

namespace Tests\Feature\Ancillary;

use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use App\Services\Pharmacy\PharmacyIvRoomService;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Database\Seeders\CaseManagementSeeder;
use Database\Seeders\CommandCenterDemoSeeder;
use Database\Seeders\RtdcSeeder;
use Database\Seeders\StaffingReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PharmacyIvRoomTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $anchor;

    protected function setUp(): void
    {
        parent::setUp();
        // 14:00 UTC: an anchor whose 18h/24h BUD windows fall on the NEXT
        // calendar day, exercising the across-midnight case.
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

    public function test_build_reports_batches_active_work_and_the_chemo_timeline(): void
    {
        $payload = app(PharmacyIvRoomService::class)->build();

        $this->assertSame('degraded', $payload['state']);
        $this->assertTrue($payload['degradedMode']);
        $this->assertSame('fresh', $payload['freshnessStatus']);

        // The demo seeds four rx_preps rows across two batches plus one unbatched.
        $this->assertSame(4, $payload['data']['summary']['totalPreps']);
        $this->assertSame(1, $payload['data']['summary']['activePreps']); // ordinal 9 in_progress
        $this->assertSame(1, $payload['data']['summary']['tpnPreps']);    // ordinal 16 tpn
        $this->assertSame(0, $payload['data']['summary']['chemoPreps']);  // no chemo prep_type rows

        // Two named batches (iv-batch:am with two preps, tpn-batch:01) plus one
        // unbatched in-progress prep are grouped distinctly.
        $ivBatch = collect($payload['data']['batches'])->firstWhere('batched', true);
        $this->assertNotNull($ivBatch);
        $amBatch = collect($payload['data']['batches'])->first(fn (array $b): bool => is_string($b['batchRef']) && str_contains($b['batchRef'], 'iv-batch:am'));
        $this->assertNotNull($amBatch);
        $this->assertSame(2, $amBatch['prepCount']);
        $this->assertContains(true, array_column($payload['data']['batches'], 'batched'));
        $this->assertContains(false, array_column($payload['data']['batches'], 'batched'), 'The unbatched in-progress prep must group separately.');

        // Active work carries the in-progress prep with a MEASURED elapsed value,
        // never a fabricated zero, and its stages come from real timestamps.
        $this->assertCount(1, $payload['data']['activeWork']);
        $active = $payload['data']['activeWork'][0];
        $this->assertSame('in_progress', $active['prepState']);
        $this->assertTrue($active['elapsedIsMeasured']);
        $this->assertIsInt($active['elapsedMinutes']);
        $this->assertGreaterThan(0, $active['elapsedMinutes']);
        $startedStage = collect($active['stages'])->firstWhere('code', 'started');
        $this->assertSame('complete', $startedStage['state']);
        $checkedStage = collect($active['stages'])->firstWhere('code', 'checked');
        $this->assertSame('pending', $checkedStage['state']);
        $this->assertNull($checkedStage['at']);
    }

    public function test_bud_windows_and_tpn_cutoff_are_handled_across_the_day_boundary(): void
    {
        $payload = app(PharmacyIvRoomService::class)->build();

        // Every seeded BUD (18h/24h/12h ahead of a 14:00 anchor) lands tomorrow.
        $tpnBatch = collect($payload['data']['batches'])->first(fn (array $b): bool => $b['prepType'] === 'tpn');
        $this->assertNotNull($tpnBatch);
        $this->assertNotNull($tpnBatch['budExpiresAt']);
        // 24h ahead of 2026-07-11T14:00Z is 2026-07-12T14:00Z — a different day.
        $this->assertSame('2026-07-12T14:00:00+00:00', CarbonImmutable::parse($tpnBatch['budExpiresAt'])->toAtomString());
        $this->assertTrue($tpnBatch['budCrossesDayBoundary'], 'A next-day BUD must be flagged as crossing the day boundary.');
        // The BUD is far in the future, so the policy window classifies it in-window.
        $this->assertSame('within_window', $tpnBatch['budState']);
        $this->assertGreaterThan(PharmacyIvRoomService::BUD_WARNING_MINUTES, $tpnBatch['budMinutesRemaining']);

        // The TPN cutoff is POLICY, surfaced separately from any measured event.
        $this->assertSame('configuration', $payload['policy']['kind']);
        $this->assertSame(PharmacyIvRoomService::TPN_CUTOFF_HOUR, $payload['policy']['tpnCutoff']['localHour']);
        // 14:00 is before the 18:00 cutoff, so the next enforceable cutoff is today at 18:00Z.
        $this->assertSame('2026-07-11T18:00:00+00:00', CarbonImmutable::parse($payload['policy']['tpnCutoff']['nextCutoffAt'])->toAtomString());
        // The policy block declares itself configuration and never carries a measured timestamp key.
        $this->assertArrayNotHasKey('observedAt', $payload['policy']['tpnCutoff']);
    }

    public function test_bud_expiry_state_folds_correctly_against_the_policy_window(): void
    {
        // Move the AM iv-batch BUD into the past (still after its start, so the
        // ordering constraint holds), leaving the orders inside the operational
        // window so the batch still renders. This proves the policy fold
        // classifies an elapsed BUD as expired independent of the across-midnight
        // computation exercised by the sibling test.
        DB::table('prod.rx_preps')
            ->whereRaw("batch_ref LIKE '%iv-batch:am%'")
            ->update(['bud_expires_at' => $this->anchor->subMinutes(30)]); // 2026-07-11T13:30:00Z, past but after start

        $payload = app(PharmacyIvRoomService::class)->build();

        $amBatch = collect($payload['data']['batches'])->first(fn (array $b): bool => is_string($b['batchRef']) && str_contains($b['batchRef'], 'iv-batch:am'));
        $this->assertNotNull($amBatch);
        // The BUD is in the past → expired, with a non-positive minutes-remaining.
        $this->assertSame('expired', $amBatch['budState']);
        $this->assertLessThanOrEqual(0, $amBatch['budMinutesRemaining']);
        $this->assertGreaterThanOrEqual(1, $payload['data']['summary']['budExpired']);

        // The next-day TPN BUD (2026-07-12T14:00Z) is unchanged: still in-window
        // and still flagged as crossing the calendar-day boundary.
        $tpnBatch = collect($payload['data']['batches'])->first(fn (array $b): bool => $b['prepType'] === 'tpn');
        $this->assertSame('within_window', $tpnBatch['budState']);
        $this->assertTrue($tpnBatch['budCrossesDayBoundary']);
        $this->assertGreaterThan(0, $tpnBatch['budMinutesRemaining']);
    }

    public function test_missing_ivwms_removes_prep_stages_and_shows_a_coverage_statement(): void
    {
        $payload = app(PharmacyIvRoomService::class)->build();

        $degraded = $payload['data']['degradedOrders'];
        $this->assertSame('partial', $degraded['coverage']);
        $this->assertCount(1, $degraded['orders']);

        $order = $degraded['orders'][0];
        // The IVWMS-absent order gets a COARSE verify→dispense clock, never a
        // fabricated prep stage and never a zero interval.
        $this->assertSame('coarse', $order['clockResolution']);
        $this->assertArrayNotHasKey('stages', $order);
        $this->assertNotNull($order['verifiedAt']);
        $this->assertNotNull($order['dispensedAt']);
        $this->assertIsInt($order['coarseVerifyToDispenseMinutes']);
        $this->assertGreaterThan(0, $order['coarseVerifyToDispenseMinutes']);

        // The coverage statement names the gap explicitly and disclaims zero duration.
        $this->assertStringContainsStringIgnoringCase('coarse verify-to-dispense', $degraded['coverageStatement']);
        $this->assertStringContainsStringIgnoringCase('never shown as zero', $degraded['coverageStatement']);

        // This degraded order carries NO rx_preps rows in the database.
        $degradedHasNoPreps = DB::table('prod.ancillary_orders as o')
            ->join('prod.rx_orders as x', 'x.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->where('o.order_uuid', $order['orderUuid'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('prod.rx_preps as p')->whereColumn('p.rx_order_id', 'x.rx_order_id'))
            ->exists();
        $this->assertTrue($degradedHasNoPreps);
    }

    public function test_waste_measures_declare_denominator_and_time_range(): void
    {
        $payload = app(PharmacyIvRoomService::class)->build();

        $waste = $payload['data']['waste'];
        // The demo seeds one controlled-substance partial waste transaction.
        $this->assertGreaterThanOrEqual(1, $waste['wasteEvents']);
        $this->assertGreaterThan(0, $waste['wasteQuantity']);

        // The rate carries an explicit denominator (vend transactions) and count.
        $this->assertNotEmpty($waste['denominatorLabel']);
        $this->assertGreaterThan(0, $waste['denominatorCount']);
        $this->assertIsFloat($waste['wastePerHundredVends']);

        // The measure declares its time range explicitly, ordered start < end.
        $this->assertSame(24, $waste['windowHours']);
        $this->assertTrue(CarbonImmutable::parse($waste['windowStartAt'])->lessThan(CarbonImmutable::parse($waste['windowEndAt'])));
        $this->assertSame($this->anchor->toAtomString(), CarbonImmutable::parse($waste['windowEndAt'])->toAtomString());
    }

    public function test_prep_type_filter_scopes_batches_and_suppresses_the_degraded_cohort(): void
    {
        $payload = app(PharmacyIvRoomService::class)->build(['prepType' => 'tpn']);

        $this->assertSame('tpn', $payload['filters']['prepType']);
        $this->assertSame(1, $payload['data']['summary']['totalPreps']);
        $this->assertNotEmpty($payload['data']['batches']);
        foreach ($payload['data']['batches'] as $batch) {
            $this->assertSame('tpn', $batch['prepType']);
        }

        // A prep-type filter cannot match the prep-less degraded cohort, so it is empty.
        $this->assertSame('available', $payload['data']['degradedOrders']['coverage']);
        $this->assertSame(0, $payload['data']['summary']['degradedOrders']);
    }

    public function test_stale_source_renders_iv_room_state_stale(): void
    {
        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'stale']);

        $payload = app(PharmacyIvRoomService::class)->build();

        $this->assertSame('stale', $payload['state']);
        $this->assertSame('stale', $payload['freshnessStatus']);
    }

    public function test_payload_exposes_no_compounding_recipe_or_individual_dimension(): void
    {
        $payload = app(PharmacyIvRoomService::class)->build();

        $this->assertFalse($payload['privacy']['compoundingRecipeIncluded']);
        $this->assertFalse($payload['privacy']['doseInstructionsIncluded']);
        $this->assertFalse($payload['privacy']['individualPerformanceIncluded']);

        // Scan the data surface (not the privacy disclaimer prose) for any recipe,
        // ingredient, dose, or user-level dimension. None may reach the browser.
        $flat = json_encode(['data' => $payload['data'], 'filters' => $payload['filters']]);
        foreach (['ingredient', 'recipe', 'diluent', 'additive', 'dose_instruction', 'pharmacist', 'technician', 'verifier', 'badge', 'risk_score', 'diversion'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, (string) $flat, "Forbidden fragment leaked: {$forbidden}");
        }
    }
}
