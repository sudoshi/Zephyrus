<?php

namespace Tests\Feature\Ancillary;

use App\Integrations\Healthcare\Ancillary\PharmacyAdcTransactionNormalizer;
use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use App\Integrations\Healthcare\Services\AncillaryMessageIngestPipeline;
use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Integration\Source;
use App\Models\Pharmacy\AdcStation;
use App\Models\Pharmacy\AdcTransaction;
use App\Models\Pharmacy\Dispense;
use App\Models\Pharmacy\MedicationOrder;
use App\Services\Pharmacy\AdcStationSignalService;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdcTransactionIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AncillaryReferenceSeeder::class);
        $this->unitId('4E', 'Four East Med Surg');
        $this->unitId('ICU', 'Intensive Care Unit');
    }

    public function test_order_linked_vend_creates_dispense_milestone_satellite_and_station_transaction(): void
    {
        $source = $this->source('adc.linked-vend', ['RDE', 'RX_ADC']);
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $pipeline->ingest($source->source_key, new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('stat-sepsis-ceftriaxone')]));

        $vend = new SourceMessage('RX_ADC', $this->adcEvent('TXN-VEND-1', 'vend', [
            'order' => ['source_order_key' => 'ACC-RX-CEF', 'placer_order_key' => 'PLACER-RX-CEF'],
            'patient_id' => 'PATIENT-RX-SEPSIS',
            'encounter_id' => 'ENC-RX-SEPSIS',
        ]));
        $receipt = $pipeline->ingest($source->source_key, $vend);
        $this->assertCount(1, $receipt['canonical_event_ids']);

        // One order, one milestone on the linked order, one dispense with the
        // 'adc' channel carrying its station, one station/unit rollup row.
        $this->assertSame(1, AncillaryOrder::query()->count());
        $order = AncillaryOrder::query()->firstOrFail();
        $this->assertSame('RX_DISPENSED', $order->current_milestone_code);
        $this->assertSame(1, DB::table('prod.ancillary_milestones')->where('milestone_code', 'RX_DISPENSED')->count());

        $station = AdcStation::query()->firstOrFail();
        $this->assertSame('PYXIS-4E-01', $station->source_station_key);
        $this->assertSame('Pyxis 4 East', $station->label);
        $this->assertNotNull($station->unit_id);

        $medication = MedicationOrder::query()->firstOrFail();
        $this->assertSame('dispensed', $medication->order_status);
        $dispense = Dispense::query()->firstOrFail();
        $this->assertSame('adc', $dispense->dispense_channel);
        $this->assertSame('TXN-VEND-1', $dispense->source_dispense_key);
        $this->assertSame((int) $station->adc_station_id, (int) $dispense->adc_station_id);
        $this->assertSame('2026-07-13T13:30:00+00:00', $dispense->dispensed_at->toIso8601String());

        $transaction = AdcTransaction::query()->firstOrFail();
        $this->assertSame('vend', $transaction->transaction_type);
        $this->assertSame((int) $medication->rx_order_id, (int) $transaction->rx_order_id);
        $this->assertSame((int) $station->unit_id, (int) $transaction->unit_id);
        $this->assertSame('explicit', $transaction->metadata['order_link']);
        $this->assertSame('mapped', $transaction->metadata['terminology_status']);
        $this->assertSame('2193', $transaction->metadata['rxnorm_cui']);
        $this->assertDatabaseHas('integration.provenance_records', [
            'target_schema' => 'prod', 'target_table' => 'rx_dispenses', 'target_pk' => (string) $dispense->rx_dispense_id,
        ]);
        $this->assertDatabaseHas('integration.provenance_records', [
            'target_schema' => 'prod', 'target_table' => 'adc_transactions', 'target_pk' => (string) $transaction->adc_transaction_id,
        ]);

        // Exact replay short-circuits; a re-sent transaction identity with a
        // moved timestamp is captured as a conflict, never a second row.
        $this->assertTrue($pipeline->ingest($source->source_key, $vend)['duplicate']);
        $pipeline->ingest($source->source_key, new SourceMessage('RX_ADC', $this->adcEvent('TXN-VEND-1', 'vend', [
            'occurred_at' => '2026-07-13T09:45:00-04:00',
            'order' => ['source_order_key' => 'ACC-RX-CEF'],
        ])));
        $this->assertSame(1, AdcTransaction::query()->count());
        $this->assertSame(1, Dispense::query()->count());
        $this->assertSame(1, DB::table('prod.ancillary_milestones')->where('milestone_code', 'RX_DISPENSED')->count());
        $conflict = AdcTransaction::query()->firstOrFail()->metadata['replay_conflict'];
        $this->assertSame('2026-07-13T13:30:00+00:00', $conflict['retained']);
    }

    public function test_unlinked_override_stays_at_station_level_without_milestone_or_order_guessing(): void
    {
        $source = $this->source('adc.override', ['RX_ADC']);
        $pipeline = app(AncillaryMessageIngestPipeline::class);

        $pipeline->ingest($source->source_key, new SourceMessage('RX_ADC', $this->adcEvent('TXN-OVR-1', 'override', [
            'medication' => ['local_code' => 'MORPHINE_INJ'],
        ])));

        // Operational station fact only: no ancillary order, no milestone,
        // no medication order, no order link.
        $this->assertSame(0, AncillaryOrder::query()->count());
        $this->assertSame(0, MedicationOrder::query()->count());
        $this->assertSame(0, DB::table('prod.ancillary_milestones')->count());
        $transaction = AdcTransaction::query()->firstOrFail();
        $this->assertSame('override', $transaction->transaction_type);
        $this->assertNull($transaction->rx_order_id);
        $this->assertTrue((bool) $transaction->is_controlled);
        $this->assertSame('RX_OVERRIDE', $transaction->metadata['rx_event_code']);
        $this->assertSame('ancillary.pharmacy.adc_transaction', DB::table('integration.canonical_events')->value('event_type'));

        // An explicit link that matches nothing records `unmatched`; a link
        // ambiguous across two candidates is never guessed.
        $pipeline->ingest($source->source_key, new SourceMessage('RX_ADC', $this->adcEvent('TXN-OVR-2', 'override', [
            'medication' => ['local_code' => 'MORPHINE_INJ'],
            'order' => ['source_order_key' => 'ACC-RX-GHOST'],
        ])));
        $unmatched = AdcTransaction::query()->where('source_transaction_key', 'TXN-OVR-2')->firstOrFail();
        $this->assertNull($unmatched->rx_order_id);
        $this->assertSame('unmatched', $unmatched->metadata['order_link']);

        MedicationOrder::factory()->create(['source_order_key' => 'ACC-RX-DUP']);
        MedicationOrder::factory()->create(['source_order_key' => 'ACC-RX-DUP']);
        $pipeline->ingest($source->source_key, new SourceMessage('RX_ADC', $this->adcEvent('TXN-OVR-3', 'override', [
            'medication' => ['local_code' => 'MORPHINE_INJ'],
            'order' => ['source_order_key' => 'ACC-RX-DUP'],
        ])));
        $ambiguous = AdcTransaction::query()->where('source_transaction_key', 'TXN-OVR-3')->firstOrFail();
        $this->assertNull($ambiguous->rx_order_id);
        $this->assertSame('ambiguous', $ambiguous->metadata['order_link']);
        $this->assertSame(0, DB::table('prod.ancillary_milestones')->count());
    }

    public function test_linked_return_and_waste_map_to_catalog_milestones_without_status_movement(): void
    {
        $source = $this->source('adc.reverse', ['RDE', 'RX_ADC']);
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $pipeline->ingest($source->source_key, new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('stat-sepsis-ceftriaxone')]));

        $pipeline->ingest($source->source_key, new SourceMessage('RX_ADC', $this->adcEvent('TXN-RET-1', 'return', [
            'order' => ['source_order_key' => 'ACC-RX-CEF'],
        ])));
        $pipeline->ingest($source->source_key, new SourceMessage('RX_ADC', $this->adcEvent('TXN-WST-1', 'waste', [
            'occurred_at' => '2026-07-13T10:00:00-04:00',
            'quantity' => 0.5,
            'order' => ['source_order_key' => 'ACC-RX-CEF'],
        ])));

        $this->assertSame(1, AncillaryOrder::query()->count());
        $this->assertSame(1, MedicationOrder::query()->count());
        $this->assertSame(1, DB::table('prod.ancillary_milestones')->where('milestone_code', 'RX_RETURNED')->count());
        $this->assertSame(1, DB::table('prod.ancillary_milestones')->where('milestone_code', 'RX_WASTED')->count());

        $medication = MedicationOrder::query()->firstOrFail();
        $this->assertSame('ordered', $medication->order_status);
        $this->assertNull(AncillaryOrder::query()->firstOrFail()->terminal_at);

        $linked = AdcTransaction::query()->orderBy('adc_transaction_id')->get();
        $this->assertCount(2, $linked);
        $this->assertSame(['return', 'waste'], $linked->pluck('transaction_type')->all());
        $this->assertTrue($linked->every(fn (AdcTransaction $row): bool => (int) $row->rx_order_id === (int) $medication->rx_order_id));
        $this->assertSame(1, AdcStation::query()->count());
    }

    public function test_discrepancy_pairing_and_stockout_state_stay_station_level(): void
    {
        $source = $this->source('adc.controlled', ['RDE', 'RX_ADC']);
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $signals = app(AdcStationSignalService::class);
        $station = ['station_key' => 'OMNI-ICU-01', 'unit' => 'ICU', 'label' => 'Omnicell ICU'];

        $pipeline->ingest($source->source_key, new SourceMessage('RX_ADC', $this->adcEvent('TXN-DISC-1', 'discrepancy_open', [
            'station' => $station, 'medication' => null, 'quantity' => null, 'discrepancy_key' => 'DISC-2026-1',
        ])));
        $open = $signals->openDiscrepancies();
        $this->assertCount(1, $open);
        $this->assertSame(1, (int) $open->first()->open_count);

        // A source-linked discrepancy carries the explicit order reference on
        // the operational row but still never asserts an order milestone.
        $pipeline->ingest($source->source_key, new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('stat-sepsis-ceftriaxone')]));
        $pipeline->ingest($source->source_key, new SourceMessage('RX_ADC', $this->adcEvent('TXN-DISC-2', 'discrepancy_open', [
            'station' => $station, 'medication' => null, 'quantity' => null, 'discrepancy_key' => 'DISC-2026-2',
            'order' => ['source_order_key' => 'ACC-RX-CEF'],
        ])));
        $linkedDiscrepancy = AdcTransaction::query()->where('source_transaction_key', 'TXN-DISC-2')->firstOrFail();
        $this->assertNotNull($linkedDiscrepancy->rx_order_id);
        $this->assertSame('RX_DISCREPANCY_OPEN', $linkedDiscrepancy->metadata['rx_event_code']);
        $this->assertSame(0, DB::table('prod.ancillary_milestones')->whereIn('milestone_code', ['RX_DISCREPANCY_OPEN', 'RX_DISCREPANCY_RESOLVED', 'RX_OVERRIDE'])->count());

        $pipeline->ingest($source->source_key, new SourceMessage('RX_ADC', $this->adcEvent('TXN-DISC-3', 'discrepancy_resolved', [
            'occurred_at' => '2026-07-13T11:00:00-04:00',
            'station' => $station, 'medication' => null, 'quantity' => null, 'discrepancy_key' => 'DISC-2026-1',
        ])));
        $pipeline->ingest($source->source_key, new SourceMessage('RX_ADC', $this->adcEvent('TXN-DISC-4', 'discrepancy_resolved', [
            'occurred_at' => '2026-07-13T11:05:00-04:00',
            'station' => $station, 'medication' => null, 'quantity' => null, 'discrepancy_key' => 'DISC-2026-2',
        ])));
        $this->assertCount(0, $signals->openDiscrepancies());

        // Stockouts set and clear per-medication station state.
        $pipeline->ingest($source->source_key, new SourceMessage('RX_ADC', $this->adcEvent('TXN-STK-1', 'stockout', [
            'station' => $station, 'medication' => ['local_code' => 'MORPHINE_INJ'], 'quantity' => null,
        ])));
        $stocked = $signals->activeStockouts();
        $this->assertCount(1, $stocked);
        $this->assertArrayHasKey('MORPHINE_INJ', $stocked->first()->metadata['open_stockouts']);

        $pipeline->ingest($source->source_key, new SourceMessage('RX_ADC', $this->adcEvent('TXN-STK-2', 'stockout', [
            'occurred_at' => '2026-07-13T12:00:00-04:00',
            'station' => $station, 'medication' => ['local_code' => 'MORPHINE_INJ'], 'quantity' => null,
            'stockout_state' => 'resolved',
        ])));
        $this->assertCount(0, $signals->activeStockouts());
        $this->assertSame(2, AdcTransaction::query()->stockouts()->count());
    }

    public function test_station_and_unit_rollups_aggregate_every_transaction_type(): void
    {
        $source = $this->source('adc.rollups', ['RX_ADC']);
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $signals = app(AdcStationSignalService::class);
        $icu = ['station_key' => 'OMNI-ICU-01', 'unit' => 'ICU'];

        $events = [
            ['TXN-R-1', 'vend', []],
            ['TXN-R-2', 'vend', []],
            ['TXN-R-3', 'refill', ['quantity' => 25]],
            ['TXN-R-4', 'waste', ['quantity' => 0.5, 'medication' => ['local_code' => 'MORPHINE_INJ']]],
            ['TXN-R-5', 'override', ['medication' => ['local_code' => 'MORPHINE_INJ'], 'station' => $icu]],
            ['TXN-R-6', 'stockout', ['medication' => null, 'quantity' => null, 'station' => $icu]],
        ];
        foreach ($events as [$id, $type, $overrides]) {
            $pipeline->ingest($source->source_key, new SourceMessage('RX_ADC', $this->adcEvent($id, $type, $overrides)));
        }

        $from = CarbonImmutable::parse('2026-07-13T00:00:00Z');
        $to = CarbonImmutable::parse('2026-07-14T00:00:00Z');
        $fourEast = AdcStation::query()->where('source_station_key', 'PYXIS-4E-01')->firstOrFail();
        $omnicell = AdcStation::query()->where('source_station_key', 'OMNI-ICU-01')->firstOrFail();

        $byStation = $signals->stationRollup($from, $to)
            ->groupBy('adc_station_id')
            ->map(fn ($rows) => $rows->pluck('transaction_count', 'transaction_type')->all());
        $this->assertSame(['refill' => 1, 'vend' => 2, 'waste' => 1], $byStation[$fourEast->adc_station_id]);
        $this->assertSame(['override' => 1, 'stockout' => 1], $byStation[$omnicell->adc_station_id]);

        $controlled = $signals->stationRollup($from, $to)
            ->first(fn (object $row): bool => $row->transaction_type === 'override');
        $this->assertSame(1, (int) $controlled->controlled_count);

        $byUnit = $signals->unitRollup($from, $to)
            ->groupBy('unit_id')
            ->map(fn ($rows) => $rows->sum('transaction_count'));
        $this->assertSame(4, (int) $byUnit[$fourEast->unit_id]);
        $this->assertSame(2, (int) $byUnit[$omnicell->unit_id]);
    }

    public function test_unmapped_unit_dead_letters_while_unmapped_medication_only_flags(): void
    {
        $source = $this->source('adc.quality', ['RX_ADC']);
        $pipeline = app(AncillaryMessageIngestPipeline::class);

        try {
            $pipeline->ingest($source->source_key, new SourceMessage('RX_ADC', $this->adcEvent('TXN-DQ-1', 'vend', [
                'station' => ['station_key' => 'PYXIS-GHOST-01', 'unit' => 'GHOST-UNIT'],
            ])));
            $this->fail('An unmapped station unit was silently coerced.');
        } catch (AncillaryIngestException $exception) {
            $this->assertSame('unmapped_station_unit', $exception->reasonCode);
        }
        $this->assertDatabaseHas('raw.dead_letters', ['reason_code' => 'unmapped_station_unit']);
        $this->assertSame(0, AdcTransaction::query()->count());
        $this->assertSame(0, AdcStation::query()->count());

        // Terminology follows the shared X-2 rule: unmapped local codes flag,
        // they never dead-letter.
        $pipeline->ingest($source->source_key, new SourceMessage('RX_ADC', $this->adcEvent('TXN-DQ-2', 'vend', [
            'medication' => ['local_code' => 'HOUSE_COMPOUND_42', 'label' => 'House compound 42'],
        ])));
        $transaction = AdcTransaction::query()->firstOrFail();
        $this->assertSame('unmapped_local', $transaction->metadata['terminology_status']);
        $this->assertSame('HOUSE_COMPOUND_42', $transaction->metadata['local_code']);
        $this->assertArrayNotHasKey('rxnorm_cui', $transaction->metadata);
        $this->assertArrayNotHasKey('ndc_code', $transaction->metadata);
        $this->assertSame(1, DB::table('raw.dead_letters')->count());

        foreach ([
            ['TXN-DQ-3', ['envelope_version' => 2], 'unsupported_envelope_version'],
            ['TXN-DQ-4', ['transaction_type' => 'diversion_flag'], 'invalid_transaction_type'],
            ['TXN-DQ-5', ['transaction_type' => 'discrepancy_open', 'medication' => null, 'quantity' => null], 'missing_discrepancy_key'],
            ['TXN-DQ-6', ['station' => ['unit' => '4E']], 'missing_station_identity'],
            ['TXN-DQ-7', ['station' => ['station_key' => 'PYXIS-4E-01']], 'missing_station_unit'],
        ] as [$id, $overrides, $reason]) {
            try {
                $pipeline->ingest($source->source_key, new SourceMessage('RX_ADC', $this->adcEvent($id, 'vend', $overrides)));
                $this->fail("The malformed ADC envelope [{$reason}] was accepted.");
            } catch (AncillaryIngestException $exception) {
                $this->assertSame($reason, $exception->reasonCode);
            }
        }
        $this->assertSame(1, AdcTransaction::query()->count());
    }

    public function test_governed_source_profile_rejects_unauthorized_family_and_department(): void
    {
        $noAdc = $this->source('adc.no-family', ['RDE']);
        try {
            app(AncillaryMessageIngestPipeline::class)->ingest($noAdc->source_key, new SourceMessage('RX_ADC', $this->adcEvent('TXN-GOV-1', 'vend')));
            $this->fail('An ADC event was accepted by a source without RX_ADC authorization.');
        } catch (AncillaryIngestException $exception) {
            $this->assertSame('source_message_mismatch', $exception->reasonCode);
        }

        $labScope = $this->source('adc.lab-scope', ['RX_ADC'], ['lab']);
        try {
            app(AncillaryMessageIngestPipeline::class)->ingest($labScope->source_key, new SourceMessage('RX_ADC', $this->adcEvent('TXN-GOV-2', 'vend')));
            $this->fail('An ADC event was accepted outside the governed rx department scope.');
        } catch (AncillaryIngestException $exception) {
            $this->assertSame('source_message_mismatch', $exception->reasonCode);
        }

        $this->assertSame(0, AdcTransaction::query()->count());
        $this->assertSame(2, DB::table('raw.dead_letters')->where('reason_code', 'source_message_mismatch')->count());
    }

    public function test_no_user_actor_or_risk_dimension_exists_in_schema_envelope_or_projection(): void
    {
        $prohibited = '/user|actor|staff|witness|badge|employee|nurse|risk|score|rank|diversion/i';

        // The X-1 schema carries no individual or risk column — assert it
        // structurally so a future migration cannot regress the boundary.
        foreach (['adc_transactions', 'adc_stations'] as $table) {
            $columns = DB::table('information_schema.columns')
                ->where('table_schema', 'prod')
                ->where('table_name', $table)
                ->pluck('column_name');
            $violations = $columns->filter(fn (string $column): bool => (bool) preg_match($prohibited, $column));
            $this->assertSame([], $violations->values()->all(), "prod.{$table} must stay free of user/actor/risk columns");
        }

        // The canonical envelope v1 defines no user or risk field.
        foreach (PharmacyAdcTransactionNormalizer::ENVELOPE_KEYS as $key) {
            $this->assertDoesNotMatchRegularExpression($prohibited, $key, 'Envelope v1 must not define user/actor/risk fields');
        }

        // A vendor payload that failed to strip user attribution at the
        // adapter edge never crosses the canonical boundary.
        $source = $this->source('adc.safety', ['RX_ADC']);
        app(AncillaryMessageIngestPipeline::class)->ingest($source->source_key, new SourceMessage('RX_ADC', [
            ...$this->adcEvent('TXN-SAFE-1', 'override', ['medication' => ['local_code' => 'MORPHINE_INJ']]),
            'user_id' => 'U-SECRET-99',
            'witness_id' => 'W-SECRET-11',
            'risk_score' => 0.97,
        ]));

        $canonical = DB::table('integration.canonical_events')->pluck('payload')->implode('\n');
        foreach (['U-SECRET-99', 'W-SECRET-11', 'user_id', 'witness_id', 'risk_score'] as $needle) {
            $this->assertStringNotContainsString($needle, $canonical);
        }
        $transaction = AdcTransaction::query()->firstOrFail();
        $row = json_encode($transaction->getAttributes(), JSON_THROW_ON_ERROR);
        foreach (['U-SECRET-99', 'W-SECRET-11', 'risk_score'] as $needle) {
            $this->assertStringNotContainsString($needle, $row);
        }
    }

    /** @return array<string, mixed> */
    private function adcEvent(string $transactionId, string $type, array $overrides = []): array
    {
        $event = [
            'envelope_version' => 1,
            'transaction_id' => $transactionId,
            'transaction_type' => $type,
            'occurred_at' => '2026-07-13T09:30:00-04:00',
            'station' => ['station_key' => 'PYXIS-4E-01', 'unit' => '4E', 'label' => 'Pyxis 4 East', 'station_type' => 'general'],
            'medication' => ['local_code' => 'CEFTRIAXONE_1G_IV', 'label' => 'Ceftriaxone 1 g intravenous'],
            'quantity' => 1,
        ];
        foreach ($overrides as $key => $value) {
            $event[$key] = $value;
        }

        return array_filter($event, fn (mixed $value): bool => $value !== null);
    }

    /** @param list<string> $families @param list<string> $departments */
    private function source(string $key, array $families, array $departments = ['rx']): Source
    {
        return app(SourceRegistryService::class)->ensureSource([
            ...$this->canonicalIntegrationSourceScope(),
            'source_key' => $key,
            'source_name' => $key,
            'system_class' => 'pharmacy',
            'interface_type' => 'structured',
            'active_status' => 'active',
            'phi_allowed' => true,
            'metadata' => [
                'ancillary_ingest' => [
                    'enabled' => true,
                    'message_families' => $families,
                    'departments' => $departments,
                ],
            ],
        ]);
    }

    private function unitId(string $abbreviation, string $name): int
    {
        return (int) DB::table('prod.units')->insertGetId([
            'name' => $name,
            'abbreviation' => $abbreviation,
            'type' => 'med_surg',
            'staffed_bed_count' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'unit_id');
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/hl7/rx/{$name}.hl7"));
    }
}
