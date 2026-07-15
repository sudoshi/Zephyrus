<?php

namespace Tests\Feature\Demo;

use App\Models\Pharmacy\AdcStation;
use App\Models\Pharmacy\AdcTransaction;
use App\Models\Pharmacy\Administration;
use App\Models\Pharmacy\DischargeQueueItem;
use App\Models\Pharmacy\Dispense;
use App\Models\Pharmacy\MedicationOrder;
use App\Models\Pharmacy\Preparation;
use App\Models\Pharmacy\Verification;
use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\Ancillary\PharmacyDemoGenerator;
use App\Services\Demo\DemoClock;
use App\Services\Demo\DemoInvariantService;
use App\Services\Pharmacy\AdcStationSignalService;
use App\Services\Pharmacy\PharmacyAdministrationFreshnessService;
use App\Services\Rtdc\DischargePrioritiesService;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Database\Seeders\CaseManagementSeeder;
use Database\Seeders\CommandCenterDemoSeeder;
use Database\Seeders\RtdcSeeder;
use Database\Seeders\StaffingReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PharmacyDemoGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $anchor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anchor = CarbonImmutable::parse('2026-07-11T14:00:00Z');
        CarbonImmutable::setTestNow($this->anchor);
        $this->seed([RtdcSeeder::class, CaseManagementSeeder::class, StaffingReferenceSeeder::class, CommandCenterDemoSeeder::class, AncillaryReferenceSeeder::class]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_fixed_cohort_is_coherent_idempotent_and_owner_safe(): void
    {
        $clock = new DemoClock($this->anchor);
        $service = app(AncillaryDemoScenarioService::class);
        $first = $service->refresh($clock);
        $owner = AncillaryDemoScenarioService::OWNER;
        $date = $this->anchor->toDateString();
        $cutoff = $this->anchor->subMinutes(PharmacyDemoGenerator::WAREHOUSE_CUTOFF_MINUTES);
        $rx = collect($first['departments'])->firstWhere('department', 'rx');

        // ---- fixed-seed distribution: counts per scenario class ----
        $this->assertSame(24, $rx['orders']);
        $this->assertSame(105, $rx['milestones']);
        $this->assertSame(8, $rx['operationalEvents']);
        $this->assertSame(24, $rx['medicationOrders']);
        $this->assertSame(24, $rx['verifications']);
        $this->assertSame(4, $rx['preparations']);
        $this->assertSame(12, $rx['dispenses']);
        $this->assertSame(4, $rx['administrations']);
        $this->assertSame(3, $rx['adcStations']);
        $this->assertSame(15, $rx['adcTransactions']);
        $this->assertSame(2, $rx['dischargeQueue']);
        $this->assertSame(1, $rx['openStockoutStations']);
        $this->assertSame(6, $rx['breaches']);

        $key = fn (int $ordinal): string => sprintf('demo:%s:rx:%02d', $date, $ordinal);
        $orders = MedicationOrder::query()->where('demo_owner', $owner)->get()->keyBy('source_order_key');

        // ---- 09:00-11:00 verification-queue surge and shift-boundary dip ----
        $surgeQueueIns = DB::table('prod.ancillary_milestones as m')
            ->join('prod.ancillary_orders as o', 'o.ancillary_order_id', '=', 'm.ancillary_order_id')
            ->where('o.demo_owner', $owner)->where('o.department', 'rx')
            ->where('m.milestone_code', 'RX_QUEUE_IN')
            ->whereRaw("o.metadata->>'demo_shift' = 'verify_surge'")
            ->whereRaw('EXTRACT(HOUR FROM m.occurred_at AT TIME ZONE ?) BETWEEN 9 AND 10', [config('app.timezone')])
            ->count();
        $dipQueueIns = DB::table('prod.ancillary_milestones as m')
            ->join('prod.ancillary_orders as o', 'o.ancillary_order_id', '=', 'm.ancillary_order_id')
            ->where('o.demo_owner', $owner)->where('o.department', 'rx')
            ->where('m.milestone_code', 'RX_QUEUE_IN')
            ->whereRaw("o.metadata->>'demo_shift' = 'shift_boundary_dip'")
            ->count();
        $this->assertSame(6, $surgeQueueIns);
        $this->assertSame(2, $dipQueueIns);
        $this->assertSame(3, Verification::query()->where('demo_owner', $owner)->where('verification_state', 'queued')->count());
        $this->assertSame(21, Verification::query()->where('demo_owner', $owner)->where('verification_state', 'verified')->count());

        // ---- SLA clocks: open and cleared breaches are mathematically valid at the anchor ----
        $breaches = DB::table('prod.ancillary_breaches as b')
            ->join('prod.ancillary_sla_definitions as d', 'd.ancillary_sla_definition_id', '=', 'b.ancillary_sla_definition_id')
            ->join('prod.ancillary_orders as o', 'o.ancillary_order_id', '=', 'b.ancillary_order_id')
            ->where('o.demo_owner', $owner)->where('o.department', 'rx')
            ->orderBy('o.source_order_key')
            ->get(['o.source_order_key', 'd.metric_key', 'b.status', 'b.elapsed_minutes_at_clear']);
        $this->assertSame([
            [$key(1), 'rx.stat_dispense', 'open'],
            [$key(2), 'rx.stat_dispense', 'cleared'],
            [$key(5), 'rx.first_dose_admin', 'cleared'],
            [$key(6), 'rx.first_dose_admin', 'cleared'],
            [$key(7), 'rx.sepsis_abx', 'open'],
            [$key(8), 'rx.sepsis_abx', 'cleared'],
        ], $breaches->map(fn (object $breach): array => [$breach->source_order_key, $breach->metric_key, $breach->status])->all());
        $this->assertSame(0, MedicationOrder::query()->where('demo_owner', $owner)
            ->where('source_order_key', $key(3))
            ->whereHas('ancillaryOrder.breaches')
            ->count(), 'The on-time STAT dispense must never carry a breach.');

        // ---- sepsis clocks are tied to real demo ED encounters ----
        $sepsisOrders = DB::table('prod.ancillary_orders as o')
            ->leftJoin('prod.ed_visits as v', 'v.ed_visit_id', '=', DB::raw("NULLIF(o.metadata->>'ed_visit_id', '')::bigint"))
            ->where('o.demo_owner', $owner)->where('o.department', 'rx')->where('o.priority', 'sepsis')
            ->get(['o.source_order_key', 'v.ed_visit_id', 'v.patient_ref as visit_patient_ref', 'o.patient_ref']);
        $this->assertCount(3, $sepsisOrders);
        $this->assertTrue($sepsisOrders->every(fn (object $order): bool => $order->ed_visit_id !== null
            && $order->visit_patient_ref === $order->patient_ref));

        // ---- discharge medication rows resolve to the CURRENT discharge cohort ----
        $queueRows = DischargeQueueItem::query()->where('demo_owner', $owner)->orderBy('source_queue_key')->get();
        $this->assertSame(['ready', 'prior_auth_pending'], $queueRows->pluck('pipeline_status')->all());
        $visibleIds = collect(app(DischargePrioritiesService::class)->build())
            ->only(['priority1', 'priority2', 'priority3', 'priority4'])
            ->flatten(1)->pluck('id')->map(fn (mixed $id): int => (int) $id);
        $this->assertTrue($queueRows->every(fn (DischargeQueueItem $row): bool => $visibleIds->contains((int) $row->encounter_id)),
            'Every discharge medication row must reference an encounter inside the rendered discharge tiers.');
        $blocking = $orders->get($key(13));
        $this->assertSame('discharge', $blocking->clock_class);
        $this->assertNull($blocking->ancillaryOrder->terminal_at);
        $this->assertTrue((bool) ($blocking->ancillaryOrder->metadata['discharge_blocking'] ?? false));

        // ---- warehouse administration honesty: batch cutoff earlier than the anchor ----
        $administrations = Administration::query()->where('demo_owner', $owner)->get();
        $this->assertCount(4, $administrations);
        $this->assertTrue($administrations->every(fn (Administration $row): bool => $row->source_cutoff_at->equalTo($cutoff)
            && $row->administered_at->lessThanOrEqualTo($row->source_cutoff_at)
            && $row->import_batch_key === "demo:{$date}:rx:mar-extract:01"
            && $row->administration_source_class === 'bcma_warehouse'));
        $envelope = app(PharmacyAdministrationFreshnessService::class)->overallEnvelope($this->anchor);
        $this->assertSame('batch', $envelope->status);
        $this->assertSame($cutoff->getTimestamp(), $envelope->sourceCutoffAt?->getTimestamp());
        $stale = app(PharmacyAdministrationFreshnessService::class)->overallEnvelope($this->anchor->addMinutes(1900));
        $this->assertSame('stale', $stale->status);

        // ---- the refused dose is a satellite fact, never a milestone or status move ----
        $refused = $administrations->firstWhere('administration_status', 'refused');
        $this->assertSame($key(4), $refused->medicationOrder->source_order_key);
        $this->assertSame('dispensed', $refused->medicationOrder->order_status);
        $this->assertSame(0, DB::table('prod.ancillary_milestones as m')
            ->join('prod.ancillary_orders as o', 'o.ancillary_order_id', '=', 'm.ancillary_order_id')
            ->where('o.source_order_key', $key(4))
            ->where('m.milestone_code', 'RX_ADMINISTERED')->count());

        // ---- IVWMS-absent degraded branch: verified jumps to dispensed, no prep evidence ----
        $degraded = $orders->get($key(14));
        $this->assertSame('iv_room', $degraded->preparation_branch);
        $this->assertSame(0, Preparation::query()->where('rx_order_id', $degraded->rx_order_id)->count());
        $this->assertSame(0, DB::table('prod.ancillary_milestones')
            ->where('ancillary_order_id', $degraded->ancillary_order_id)
            ->whereIn('milestone_code', ['RX_PREP_STARTED', 'RX_PREP_COMPLETE', 'RX_CHECKED'])->count());
        $this->assertSame(1, DB::table('prod.ancillary_milestones')
            ->where('ancillary_order_id', $degraded->ancillary_order_id)
            ->where('milestone_code', 'RX_DISPENSED')->count());

        // ---- deterministic source-precedence conflict: pharmacy wins over ADC ----
        $conflict = DB::table('prod.ancillary_current_assertions as v')
            ->join('prod.ancillary_orders as o', 'o.ancillary_order_id', '=', 'v.ancillary_order_id')
            ->join('prod.ancillary_milestones as m', 'm.ancillary_milestone_id', '=', 'v.ancillary_milestone_id')
            ->join('integration.sources as s', 's.source_id', '=', 'm.source_id')
            ->where('o.source_order_key', $key(15))
            ->where('v.milestone_code', 'RX_DISPENSED')
            ->first(['v.assertion_count', 's.system_class']);
        $this->assertSame(2, (int) $conflict->assertion_count);
        $this->assertSame('pharmacy', $conflict->system_class);

        // ---- ADC stations, transactions, and station signals resolve ----
        $this->assertSame(0, AdcTransaction::query()->where('demo_owner', $owner)->whereNull('unit_id')->count());
        $this->assertSame(
            ['discrepancy_open' => 2, 'discrepancy_resolved' => 1, 'override' => 1, 'refill' => 2, 'return' => 1, 'stockout' => 1, 'vend' => 6, 'waste' => 1],
            AdcTransaction::query()->where('demo_owner', $owner)->get()
                ->groupBy('transaction_type')->map(fn ($group): int => $group->count())->sortKeys()->all(),
        );
        $signals = app(AdcStationSignalService::class);
        $openDiscrepancies = $signals->openDiscrepancies();
        $this->assertCount(1, $openDiscrepancies);
        $this->assertSame(1, (int) $openDiscrepancies->first()->open_count);
        $stockouts = $signals->activeStockouts();
        $this->assertCount(1, $stockouts);
        $this->assertSame('demo:rx:station:ED-01', $stockouts->first()->source_station_key);
        $this->assertArrayHasKey('CEFTRIAXONE_1G_IV', $stockouts->first()->metadata['open_stockouts']);
        $stationInventory = AdcStation::query()->where('demo_owner', $owner)->get()->keyBy('source_station_key');
        $edInventory = $stationInventory['demo:rx:station:ED-01']->metadata['inventory'];
        $this->assertSame([2, 12], [$edInventory['ONDANSETRON_INJ']['on_hand'], $edInventory['ONDANSETRON_INJ']['par_level']]);
        $this->assertSame(0, $edInventory['CEFTRIAXONE_1G_IV']['on_hand'], 'The observed stockout also carries an explicit zero inventory fact.');
        $this->assertSame(
            $this->anchor->subMinutes(180)->toIso8601String(),
            $stationInventory['demo:rx:station:MS-01']->metadata['inventory']['MORPHINE_INJ']['captured_at'],
            'The deterministic stale inventory snapshot drives the low-confidence forecast branch.',
        );
        $this->assertArrayNotHasKey('inventory', $stationInventory['demo:rx:station:ICU-01']->metadata, 'ICU deliberately exercises velocity-only coverage.');
        $shortage = $orders->get($key(11));
        $this->assertTrue((bool) $shortage->on_shortage);
        $this->assertSame('CEFTRIAXONE_1G_IV', $shortage->local_code);

        // ---- terminology + preparation satellites (batch refs and BUD) ----
        $this->assertSame('unmapped_local', $orders->get($key(16))->terminology_status);
        $tpn = Preparation::query()->where('demo_owner', $owner)->where('prep_type', 'tpn')->firstOrFail();
        $this->assertSame("demo:{$date}:rx:tpn-batch:01", $tpn->batch_ref);
        $this->assertTrue($tpn->bud_expires_at->greaterThan($this->anchor));
        $this->assertSame(1, Preparation::query()->where('demo_owner', $owner)->where('prep_state', 'in_progress')->count());

        // ---- refresh is idempotent and owner-safe ----
        $semanticBefore = $this->semanticSnapshot($owner);
        $foreignStation = AdcStation::factory()->create(['demo_owner' => null]);
        $foreignTransaction = AdcTransaction::factory()->create(['adc_station_id' => $foreignStation->adc_station_id, 'demo_owner' => null]);
        $second = $service->refresh($clock);
        $this->assertSame($first, $second);
        $this->assertSame($semanticBefore, $this->semanticSnapshot($owner));
        $this->assertDatabaseHas('prod.adc_stations', ['adc_station_id' => $foreignStation->adc_station_id, 'demo_owner' => null]);
        $this->assertDatabaseHas('prod.adc_transactions', ['adc_transaction_id' => $foreignTransaction->adc_transaction_id, 'demo_owner' => null]);
        $this->assertSame(336, DB::table('integration.canonical_events')
            ->whereRaw("metadata->>'demo_owner' = ?", [$owner])
            ->count(), 'Two same-anchor refreshes must not duplicate canonical events.');

        // ---- the full invariant gate passes with zero critical failures ----
        $findings = collect(app(DemoInvariantService::class)->run($clock))->where('category', 'ancillary');
        $this->assertSame([], $findings->where('severity', 'critical')->where('passed', false)->pluck('key')->all());
        $this->assertTrue($findings->firstWhere('key', 'ancillary.pharmacy_distribution_plausible')['passed']);
        $this->assertTrue($findings->firstWhere('key', 'ancillary.pharmacy_administrations_cutoff_qualified')['passed']);
        $this->assertTrue($findings->firstWhere('key', 'ancillary.single_open_breach_per_clock')['passed']);
    }

    /** @return array<string, mixed> */
    private function semanticSnapshot(string $owner): array
    {
        return [
            'orders' => MedicationOrder::query()->where('demo_owner', $owner)->orderBy('source_order_key')->get()
                ->map(fn (MedicationOrder $row): array => [$row->source_order_key, $row->order_status, $row->clock_class, (bool) $row->on_shortage, $row->terminology_status])->all(),
            'verifications' => Verification::query()->where('demo_owner', $owner)->orderBy('source_verification_key')->get()
                ->map(fn (Verification $row): array => [$row->source_verification_key, $row->verification_state, $row->verified_at?->toIso8601String()])->all(),
            'dispenses' => Dispense::query()->where('demo_owner', $owner)->orderBy('source_dispense_key')->get()
                ->map(fn (Dispense $row): array => [$row->source_dispense_key, $row->dispense_channel, $row->dispensed_at->toIso8601String()])->all(),
            'administrations' => Administration::query()->where('demo_owner', $owner)->orderBy('source_administration_key')->get()
                ->map(fn (Administration $row): array => [$row->source_administration_key, $row->administration_status, $row->source_cutoff_at->toIso8601String()])->all(),
            'transactions' => AdcTransaction::query()->where('demo_owner', $owner)->orderBy('source_transaction_key')->get()
                ->map(fn (AdcTransaction $row): array => [$row->source_transaction_key, $row->transaction_type, (bool) $row->is_controlled, $row->occurred_at->toIso8601String()])->all(),
            'stations' => AdcStation::query()->where('demo_owner', $owner)->orderBy('source_station_key')->get()
                ->map(fn (AdcStation $row): array => [$row->source_station_key, $row->station_type, array_keys($row->metadata['open_stockouts'] ?? []), $row->metadata['inventory'] ?? null])->all(),
            'preps' => Preparation::query()->where('demo_owner', $owner)->orderBy('source_prep_key')->get()
                ->map(fn (Preparation $row): array => [$row->source_prep_key, $row->prep_state, $row->batch_ref])->all(),
            'discharge' => DischargeQueueItem::query()->where('demo_owner', $owner)->orderBy('source_queue_key')->get()
                ->map(fn (DischargeQueueItem $row): array => [$row->source_queue_key, $row->pipeline_status, $row->encounter_id])->all(),
        ];
    }
}
