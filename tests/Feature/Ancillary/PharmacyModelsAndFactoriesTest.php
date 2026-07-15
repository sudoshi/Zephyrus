<?php

namespace Tests\Feature\Ancillary;

use App\Models\Pharmacy\AdcStation;
use App\Models\Pharmacy\AdcTransaction;
use App\Models\Pharmacy\Administration;
use App\Models\Pharmacy\DischargeQueueItem;
use App\Models\Pharmacy\Dispense;
use App\Models\Pharmacy\FormularyItem;
use App\Models\Pharmacy\MedicationOrder;
use App\Models\Pharmacy\Preparation;
use App\Models\Pharmacy\Verification;
use Database\Seeders\AncillaryReferenceSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PharmacyModelsAndFactoriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AncillaryReferenceSeeder::class);
    }

    public function test_formulary_seeder_is_idempotent_and_terminology_governed(): void
    {
        $uuids = FormularyItem::query()->orderBy('formulary_key')->pluck('formulary_uuid', 'formulary_key');
        $this->seed(AncillaryReferenceSeeder::class);

        $this->assertCount(9, FormularyItem::query()->get());
        $this->assertSame($uuids->all(), FormularyItem::query()->orderBy('formulary_key')->pluck('formulary_uuid', 'formulary_key')->all());

        $unmapped = FormularyItem::query()->unmapped()->get();
        $this->assertCount(1, $unmapped);
        $this->assertSame('rx.tpn_adult', $unmapped->first()->formulary_key);
        $this->assertNull($unmapped->first()->rxnorm_cui);
        $this->assertNull($unmapped->first()->ndc_code);

        $controlled = FormularyItem::query()->controlled()->get();
        $this->assertCount(1, $controlled);
        $this->assertSame('II', $controlled->first()->controlled_schedule);

        $this->assertTrue(FormularyItem::query()->hazardous()->where('formulary_key', 'rx.cyclophosphamide_iv')->exists());
        $this->assertSame(
            ['adc', 'central', 'iv_room'],
            FormularyItem::query()->distinct()->orderBy('default_prep_branch')->pluck('default_prep_branch')->all(),
        );
        $this->assertCount(9, FormularyItem::query()->activeAt(now())->get());
    }

    public function test_order_factories_represent_every_priority_preparation_and_flag_scenario(): void
    {
        $stat = MedicationOrder::factory()->stat()->create();
        $firstDose = MedicationOrder::factory()->firstDose()->create();
        $sepsis = MedicationOrder::factory()->sepsis()->create();
        $adc = MedicationOrder::factory()->create();
        $ivBatch = MedicationOrder::factory()->ivBatch()->create();
        $chemo = MedicationOrder::factory()->chemo()->create();
        $tpn = MedicationOrder::factory()->tpn()->create();
        $discharge = MedicationOrder::factory()->discharge()->create();
        $controlled = MedicationOrder::factory()->controlled()->create();
        $shortage = MedicationOrder::factory()->onShortage()->create();
        $discontinued = MedicationOrder::factory()->discontinued()->create();

        $this->assertSame('stat', $stat->clock_class);
        $this->assertSame('first_dose', $firstDose->clock_class);
        $this->assertNotNull($firstDose->due_at);
        $this->assertSame('sepsis', $sepsis->clock_class);
        $this->assertSame('adc', $adc->preparation_branch);
        $this->assertSame('iv_room', $ivBatch->preparation_branch);
        $this->assertTrue($chemo->is_hazardous);
        $this->assertSame('unmapped_local', $tpn->terminology_status);
        $this->assertSame('discharge', $discharge->clock_class);
        $this->assertTrue($controlled->is_controlled);
        $this->assertSame('II', $controlled->controlled_schedule);
        $this->assertTrue($shortage->on_shortage);
        $this->assertSame('discontinued', $discontinued->order_status);
        $this->assertNotNull($discontinued->discontinued_at);

        $this->assertTrue(MedicationOrder::query()->clockClass(['stat', 'sepsis'])->whereKey($sepsis->getKey())->exists());
        $this->assertTrue(MedicationOrder::query()->preparationBranch('iv_room')->whereKey($ivBatch->getKey())->exists());
        $this->assertTrue(MedicationOrder::query()->controlled()->whereKey($controlled->getKey())->exists());
        $this->assertTrue(MedicationOrder::query()->hazardous()->whereKey($chemo->getKey())->exists());
        $this->assertTrue(MedicationOrder::query()->onShortage()->whereKey($shortage->getKey())->exists());
        $this->assertTrue(MedicationOrder::query()->unmapped()->whereKey($tpn->getKey())->exists());
        $this->assertTrue(MedicationOrder::query()->dischargeQueueable()->whereKey($discharge->getKey())->exists());
        $this->assertFalse(MedicationOrder::query()->open()->whereKey($discontinued->getKey())->exists());
        $this->assertTrue(MedicationOrder::query()->open()->whereKey($stat->getKey())->exists());

        $this->assertSame('rx.tpn_adult', $tpn->formularyItem->formulary_key);
        $this->assertTrue($stat->ancillaryOrder->medicationOrder->is($stat));
        $this->assertInstanceOf(DateTimeImmutable::class, $firstDose->due_at);
        $this->assertIsArray($stat->metadata);
    }

    public function test_verification_preparation_dispense_and_administration_chain_links_one_order(): void
    {
        $order = MedicationOrder::factory()->ivBatch()->create();

        $verification = Verification::factory()->verified()->create([
            'rx_order_id' => $order->rx_order_id,
            'source_id' => $order->source_id,
        ]);
        $prep = Preparation::factory()->checked()->create([
            'rx_order_id' => $order->rx_order_id,
            'source_id' => $order->source_id,
        ]);
        $dispense = Dispense::factory()->ivRoom()->delivered()->create([
            'rx_order_id' => $order->rx_order_id,
            'source_id' => $order->source_id,
        ]);
        $administration = Administration::factory()->create([
            'rx_order_id' => $order->rx_order_id,
            'source_id' => $order->source_id,
        ]);

        $this->assertTrue($order->verifications->contains($verification));
        $this->assertTrue($order->preparations->contains($prep));
        $this->assertTrue($order->dispenses->contains($dispense));
        $this->assertTrue($order->administrations->contains($administration));
        $this->assertTrue($verification->medicationOrder->is($order));
        $this->assertSame('iv_batch', $prep->prep_type);
        $this->assertNotNull($prep->bud_expires_at);
        $this->assertTrue(Verification::query()->verified()->whereKey($verification->getKey())->exists());
        $this->assertFalse(Preparation::query()->active()->whereKey($prep->getKey())->exists());
        $this->assertTrue(Dispense::query()->delivered()->whereKey($dispense->getKey())->exists());
        $this->assertTrue(Administration::query()->given()->whereKey($administration->getKey())->exists());
        $this->assertInstanceOf(DateTimeImmutable::class, $administration->source_cutoff_at);

        $chemoPrep = Preparation::factory()->chemo()->inProgress()->create();
        $tpnPrep = Preparation::factory()->tpn()->create();
        $this->assertSame('chemo', $chemoPrep->prep_type);
        $this->assertSame('tpn', $tpnPrep->prep_type);
        $this->assertTrue(Preparation::query()->ofType(['chemo', 'tpn'])->active()->whereKey($chemoPrep->getKey())->exists());
    }

    public function test_adc_stations_and_transactions_support_station_and_unit_rollups_without_individual_attribution(): void
    {
        $station = AdcStation::factory()->create();
        $vend = AdcTransaction::factory()->create(['adc_station_id' => $station->adc_station_id, 'source_id' => $station->source_id, 'unit_id' => $station->unit_id]);
        $override = AdcTransaction::factory()->override()->create(['adc_station_id' => $station->adc_station_id, 'source_id' => $station->source_id, 'unit_id' => $station->unit_id]);
        $resolvedOpen = AdcTransaction::factory()->discrepancyOpen()->create(['adc_station_id' => $station->adc_station_id, 'source_id' => $station->source_id, 'unit_id' => $station->unit_id]);
        $resolution = AdcTransaction::factory()->discrepancyResolvedFor($resolvedOpen)->create();
        $unresolvedOpen = AdcTransaction::factory()->discrepancyOpen()->create(['adc_station_id' => $station->adc_station_id, 'source_id' => $station->source_id, 'unit_id' => $station->unit_id]);
        $stockout = AdcTransaction::factory()->stockout()->create(['adc_station_id' => $station->adc_station_id, 'source_id' => $station->source_id, 'unit_id' => $station->unit_id]);

        $this->assertNotNull($vend->rx_order_id);
        $this->assertTrue($vend->medicationOrder->adcTransactions->contains($vend));
        $this->assertNull($override->rx_order_id);
        $this->assertSame($resolvedOpen->discrepancy_key, $resolution->discrepancy_key);

        $this->assertSame(6, AdcTransaction::query()->forStation($station->adc_station_id)->count());
        $this->assertSame(6, AdcTransaction::query()->forUnit($station->unit_id)->count());
        $this->assertTrue(AdcTransaction::query()->ofType('override')->controlled()->whereKey($override->getKey())->exists());
        $this->assertTrue(AdcTransaction::query()->stockouts()->whereKey($stockout->getKey())->exists());

        $openDiscrepancies = AdcTransaction::query()->openDiscrepancies()->get();
        $this->assertCount(1, $openDiscrepancies);
        $this->assertTrue($openDiscrepancies->first()->is($unresolvedOpen));

        $this->assertTrue($station->transactions->contains($vend));
        $this->assertTrue(AdcStation::query()->operational()->forUnit($station->unit_id)->whereKey($station->getKey())->exists());

        foreach ([$vend, $override, $resolvedOpen, $resolution, $unresolvedOpen, $stockout] as $transaction) {
            $this->assertEmpty(
                preg_grep('/diversion|risk|score|staff|user|nurse/i', array_keys($transaction->metadata)),
                'ADC transaction factories must never imply an individual diversion score.',
            );
        }
    }

    public function test_discharge_queue_pipeline_states_carry_governed_status_and_history_timestamps(): void
    {
        $notStarted = DischargeQueueItem::factory()->create();
        $priorAuth = DischargeQueueItem::factory()->priorAuthPending()->create();
        $verification = DischargeQueueItem::factory()->verification()->create();
        $filling = DischargeQueueItem::factory()->filling()->create();
        $ready = DischargeQueueItem::factory()->ready()->create();
        $delivered = DischargeQueueItem::factory()->delivered()->create();
        $unknown = DischargeQueueItem::factory()->unknown()->create();

        $this->assertSame(
            ['not_started', 'prior_auth_pending', 'verification', 'filling', 'ready', 'delivered', 'unknown'],
            [
                $notStarted->pipeline_status,
                $priorAuth->pipeline_status,
                $verification->pipeline_status,
                $filling->pipeline_status,
                $ready->pipeline_status,
                $delivered->pipeline_status,
                $unknown->pipeline_status,
            ],
        );

        // The delivered row retains its full transition history on governed timestamp columns.
        $this->assertNotNull($delivered->verification_started_at);
        $this->assertNotNull($delivered->filling_started_at);
        $this->assertNotNull($delivered->ready_at);
        $this->assertNotNull($delivered->delivered_at);
        $this->assertTrue($delivered->delivered_at >= $delivered->ready_at);

        $this->assertTrue(DischargeQueueItem::query()->openPipeline()->whereKey($priorAuth->getKey())->exists());
        $this->assertFalse(DischargeQueueItem::query()->openPipeline()->whereKey($ready->getKey())->exists());
        $this->assertTrue(DischargeQueueItem::query()->pipeline('unknown')->whereKey($unknown->getKey())->exists());
        $this->assertTrue(DischargeQueueItem::query()->dueBy(now()->addHours(6))->whereKey($notStarted->getKey())->exists());

        $this->assertSame('discharge', $notStarted->medicationOrder->clock_class);
        $this->assertTrue($notStarted->medicationOrder->dischargeQueueItem->is($notStarted));
        $this->assertSame($notStarted->encounter_id, $notStarted->medicationOrder->encounter_id);
        $this->assertInstanceOf(DateTimeImmutable::class, $notStarted->status_changed_at);
    }

    public function test_administration_freshness_and_versioned_corrections_are_representable(): void
    {
        $administration = Administration::factory()->fromImportBatch('bcma-extract-20260713-0500', now()->subHours(2))->create([
            'administered_at' => now()->subHours(3),
        ]);
        $stale = Administration::factory()->fromImportBatch('bcma-extract-20260712-0500', now()->subHours(30))->create([
            'administered_at' => now()->subHours(31),
        ]);
        $corrected = Administration::factory()->correctionOf($administration)->create();

        $this->assertTrue(Administration::query()->freshSince(now()->subHours(6))->whereKey($administration->getKey())->exists());
        $this->assertFalse(Administration::query()->freshSince(now()->subHours(6))->whereKey($stale->getKey())->exists());
        $this->assertSame(1, Administration::query()->forImportBatch('bcma-extract-20260713-0500')->count());
        $this->assertSame($administration->source_administration_key, $corrected->source_administration_key);
        $this->assertSame('2', $corrected->source_row_version);
        $this->assertTrue($corrected->medicationOrder->is($administration->medicationOrder));
    }
}
