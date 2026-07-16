<?php

namespace Tests\Feature\Ancillary;

use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use App\Integrations\Healthcare\Services\AncillaryMessageIngestPipeline;
use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Integration\Source;
use App\Models\Pharmacy\Dispense;
use App\Models\Pharmacy\MedicationOrder;
use App\Models\Pharmacy\Verification;
use Database\Seeders\AncillaryReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PharmacyOrderIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AncillaryReferenceSeeder::class);
        config([
            'integrations.fhir_resources.MedicationRequest.enabled' => true,
            'integrations.fhir_resources.MedicationDispense.enabled' => true,
        ]);
    }

    public function test_golden_rde_orders_cover_sepsis_first_dose_iv_and_discharge_clock_classes(): void
    {
        $source = $this->source('pharmacy.orders', ['RDE', 'RDS']);
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        foreach (['stat-sepsis-ceftriaxone', 'routine-first-dose-ondansetron', 'iv-vancomycin-timed', 'discharge-warfarin'] as $fixture) {
            $receipt = $pipeline->ingest(
                $source->source_key,
                new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture($fixture)]),
            );
            $this->assertCount(1, $receipt['canonical_event_ids']);
        }

        $this->assertSame(4, AncillaryOrder::query()->department('rx')->count());
        $this->assertSame(4, MedicationOrder::query()->count());
        $this->assertSame(4, DB::table('prod.ancillary_milestones')->where('milestone_code', 'RX_ORDERED')->count());

        $sepsis = AncillaryOrder::query()->where('source_order_key', 'ACC-RX-CEF')->firstOrFail();
        $this->assertSame('sepsis', $sepsis->priority);
        $this->assertSame('emergency', $sepsis->patient_class);
        $this->assertSame('medication_order', $sepsis->work_item_type);
        $this->assertSame('2026-07-13T12:00:00+00:00', $sepsis->ordered_at->toIso8601String());
        $this->assertNotSame('PATIENT-RX-SEPSIS', $sepsis->patient_ref);
        $this->assertNotSame('ENC-RX-SEPSIS', $sepsis->encounter_ref);

        $ceftriaxone = MedicationOrder::query()->where('source_order_key', 'ACC-RX-CEF')->firstOrFail();
        $this->assertSame('sepsis', $ceftriaxone->clock_class);
        $this->assertSame('CEFTRIAXONE_1G_IV', $ceftriaxone->local_code);
        $this->assertSame('2193', $ceftriaxone->rxnorm_cui);
        $this->assertSame('mapped', $ceftriaxone->terminology_status);
        $this->assertSame('IV', $ceftriaxone->route);
        $this->assertSame('INJ', $ceftriaxone->dosage_form);
        $this->assertSame('adc', $ceftriaxone->preparation_branch);
        $this->assertNotNull($ceftriaxone->rx_formulary_id);

        $firstDose = MedicationOrder::query()->where('source_order_key', 'ACC-RX-OND')->firstOrFail();
        $this->assertSame('first_dose', $firstDose->clock_class);
        $this->assertSame('2026-07-13T13:30:00+00:00', $firstDose->due_at->toIso8601String());

        $vancomycin = MedicationOrder::query()->where('source_order_key', 'ACC-RX-VANC')->firstOrFail();
        $this->assertSame('timed', $vancomycin->clock_class);
        $this->assertSame('iv_room', $vancomycin->preparation_branch);
        $this->assertSame('2026-07-13T18:00:00+00:00', $vancomycin->due_at->toIso8601String());

        $warfarin = MedicationOrder::query()->where('source_order_key', 'ACC-RX-WARF')->firstOrFail();
        $this->assertSame('discharge', $warfarin->clock_class);
        $this->assertSame('discharge', $warfarin->ancillaryOrder->priority);

        $canonicalPayloads = DB::table('integration.canonical_events')->pluck('payload')->implode('\n');
        $this->assertStringNotContainsString('PATIENT-RX-SEPSIS', $canonicalPayloads);
        $this->assertStringNotContainsString('ENC-RX-VANC', $canonicalPayloads);
        $this->assertDatabaseHas('integration.provenance_records', [
            'target_schema' => 'prod',
            'target_table' => 'rx_orders',
            'target_pk' => (string) $ceftriaxone->rx_order_id,
        ]);
    }

    public function test_verification_queue_envelope_maps_queue_in_verified_and_survives_duplicate_events(): void
    {
        $source = $this->source('pharmacy.queue', ['RDE', 'RX_QUEUE']);
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $pipeline->ingest($source->source_key, new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('stat-sepsis-ceftriaxone')]));

        $entered = new SourceMessage('RX_QUEUE', $this->queueEvent('RXQ-CEF-IN-1', 'entered', '2026-07-13T08:02:00-04:00'));
        $receipt = $pipeline->ingest($source->source_key, $entered);
        $this->assertCount(1, $receipt['canonical_event_ids']);

        $order = AncillaryOrder::query()->where('source_order_key', 'ACC-RX-CEF')->firstOrFail();
        $this->assertSame('RX_QUEUE_IN', $order->current_milestone_code);
        $verification = Verification::query()->firstOrFail();
        $this->assertSame('queued', $verification->verification_state);
        $this->assertSame('ACC-RX-CEF:verification', $verification->source_verification_key);
        $this->assertSame('queued', MedicationOrder::query()->firstOrFail()->order_status);

        $verified = new SourceMessage('RX_QUEUE', [
            ...$this->queueEvent('RXQ-CEF-VER-1', 'verified', '2026-07-13T08:07:00-04:00'),
            'verifier_ref' => 'RPH-777',
        ]);
        $pipeline->ingest($source->source_key, $verified);
        $verification->refresh();
        $this->assertSame('verified', $verification->verification_state);
        $this->assertSame('2026-07-13T12:07:00+00:00', $verification->verified_at->toIso8601String());
        $this->assertSame('2026-07-13T12:02:00+00:00', $verification->queued_at->toIso8601String());
        $this->assertNotSame('RPH-777', $verification->metadata['verifier_ref']);
        $this->assertSame('verified', MedicationOrder::query()->firstOrFail()->order_status);
        $this->assertSame('RX_VERIFIED', $order->refresh()->current_milestone_code);

        // Exact duplicate replay short-circuits; a re-announced queue entry
        // appends a retained assertion without duplicating or regressing the
        // verification satellite.
        $this->assertTrue($pipeline->ingest($source->source_key, $entered)['duplicate']);
        $pipeline->ingest($source->source_key, new SourceMessage('RX_QUEUE', $this->queueEvent('RXQ-CEF-IN-2', 'entered', '2026-07-13T08:02:00-04:00')));
        $this->assertSame(1, Verification::query()->count());
        $this->assertSame('verified', Verification::query()->firstOrFail()->verification_state);
        $this->assertSame('verified', MedicationOrder::query()->firstOrFail()->order_status);
        $this->assertSame(2, DB::table('prod.ancillary_milestones')->where('milestone_code', 'RX_QUEUE_IN')->count());
        $this->assertSame('RX_VERIFIED', $order->refresh()->current_milestone_code);
        $this->assertDatabaseHas('integration.provenance_records', [
            'target_schema' => 'prod',
            'target_table' => 'rx_verifications',
            'target_pk' => (string) $verification->rx_verification_id,
        ]);

        try {
            $pipeline->ingest($source->source_key, new SourceMessage('RX_QUEUE', [
                ...$this->queueEvent('RXQ-CEF-BAD-1', 'entered', '2026-07-13T08:02:00-04:00'),
                'envelope_version' => 2,
            ]));
            $this->fail('An unsupported verification-queue envelope version was accepted.');
        } catch (AncillaryIngestException $exception) {
            $this->assertSame('unsupported_envelope_version', $exception->reasonCode);
        }
        $this->assertDatabaseHas('raw.dead_letters', ['reason_code' => 'unsupported_envelope_version']);
    }

    public function test_rds_dispense_before_verification_does_not_regress_when_queue_events_arrive_late(): void
    {
        $source = $this->source('pharmacy.out-of-order', ['RDE', 'RDS', 'RX_QUEUE']);
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $pipeline->ingest($source->source_key, new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('stat-sepsis-ceftriaxone')]));

        $dispenseMessage = new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('rds-dispense-ceftriaxone')]);
        $pipeline->ingest($source->source_key, $dispenseMessage);

        $this->assertSame(1, AncillaryOrder::query()->count());
        $this->assertSame(1, MedicationOrder::query()->count());
        $dispense = Dispense::query()->firstOrFail();
        $this->assertSame('RXFILL-CEF-1', $dispense->source_dispense_key);
        $this->assertSame('central', $dispense->dispense_channel);
        $this->assertSame('2026-07-13T12:15:00+00:00', $dispense->dispensed_at->toIso8601String());
        $this->assertSame('dispensed', MedicationOrder::query()->firstOrFail()->order_status);
        $order = AncillaryOrder::query()->firstOrFail();
        $this->assertSame('RX_DISPENSED', $order->current_milestone_code);

        // The verification-queue events land after the dispense but occurred
        // earlier; history fills in without regressing the projected state.
        $pipeline->ingest($source->source_key, new SourceMessage('RX_QUEUE', $this->queueEvent('RXQ-LATE-IN-1', 'entered', '2026-07-13T08:03:00-04:00')));
        $pipeline->ingest($source->source_key, new SourceMessage('RX_QUEUE', [
            ...$this->queueEvent('RXQ-LATE-REM-1', 'removed', '2026-07-13T08:09:00-04:00'),
            'removal_reason' => 'verified',
        ]));

        $verification = Verification::query()->firstOrFail();
        $this->assertSame('verified', $verification->verification_state);
        $this->assertNotNull($verification->verified_at);
        $this->assertNotNull($verification->removed_at);
        $this->assertSame(1, DB::table('prod.ancillary_milestones')->where('milestone_code', 'RX_QUEUE_IN')->count());
        $this->assertSame(1, DB::table('prod.ancillary_milestones')->where('milestone_code', 'RX_VERIFIED')->count());
        $this->assertSame('dispensed', MedicationOrder::query()->firstOrFail()->order_status);
        $this->assertSame('RX_DISPENSED', $order->refresh()->current_milestone_code);
        $this->assertNull($order->terminal_at);

        $this->assertTrue($pipeline->ingest($source->source_key, $dispenseMessage)['duplicate']);
        $this->assertSame(1, Dispense::query()->count());
        $this->assertSame(1, DB::table('prod.ancillary_milestones')->where('milestone_code', 'RX_DISPENSED')->count());
    }

    public function test_modification_appends_change_log_and_discontinuation_stays_terminal_under_late_dispense(): void
    {
        $source = $this->source('pharmacy.lifecycle', ['RDE', 'RDS']);
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $pipeline->ingest($source->source_key, new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('iv-vancomycin-timed')]));

        $orderedAt = AncillaryOrder::query()->firstOrFail()->ordered_at;
        $modify = new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('modify-vancomycin')]);
        $pipeline->ingest($source->source_key, $modify);

        $this->assertSame(1, AncillaryOrder::query()->count());
        $this->assertSame(1, MedicationOrder::query()->count());
        $medication = MedicationOrder::query()->firstOrFail();
        $this->assertSame('IVPB', $medication->dosage_form);
        $this->assertSame('2026-07-13T22:00:00+00:00', $medication->due_at->toIso8601String());
        $changeLog = $medication->metadata['order_change_log'];
        $this->assertCount(1, $changeLog);
        $this->assertSame('RX-IV-VANC-XO-1', $changeLog[0]['correlation']);
        $this->assertSame('INF', $changeLog[0]['changes']['dosage_form']['from']);
        $this->assertSame('IVPB', $changeLog[0]['changes']['dosage_form']['to']);

        // The modify re-asserts the ordering clock instead of rewriting it:
        // both RX_ORDERED assertions are retained and the selected ordering
        // time is unchanged.
        $this->assertSame(2, DB::table('prod.ancillary_milestones')->where('milestone_code', 'RX_ORDERED')->count());
        $order = AncillaryOrder::query()->firstOrFail();
        $this->assertTrue($order->ordered_at->equalTo($orderedAt));
        $selected = DB::table('prod.ancillary_current_assertions')
            ->where('ancillary_order_id', $order->ancillary_order_id)
            ->where('milestone_code', 'RX_ORDERED')
            ->first();
        $this->assertSame(2, (int) $selected->assertion_count);
        $this->assertSame(0, (int) $selected->disagreement_seconds);

        $this->assertTrue($pipeline->ingest($source->source_key, $modify)['duplicate']);
        $this->assertCount(1, MedicationOrder::query()->firstOrFail()->metadata['order_change_log']);

        $pipeline->ingest($source->source_key, new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('discontinue-vancomycin')]));
        $order->refresh();
        $medication->refresh();
        $this->assertSame('RX_DISCONTINUED', $order->current_milestone_code);
        $this->assertSame('rx_discontinued', $order->current_state);
        $this->assertSame('2026-07-13T19:00:00+00:00', $order->terminal_at->toIso8601String());
        $this->assertSame('discontinued', $medication->order_status);
        $this->assertSame('2026-07-13T19:00:00+00:00', $medication->discontinued_at->toIso8601String());

        // A dispense that occurred before discontinuation but arrives after
        // it appends the fact without resurrecting the order.
        $lateDispense = str_replace(
            ['RX-DISP-CEF-1', 'PLACER-RX-CEF', 'ACC-RX-CEF', 'CEFTRIAXONE_1G_IV^Ceftriaxone 1 g intravenous', 'RXFILL-CEF-1', 'PATIENT-RX-SEPSIS', 'ENC-RX-SEPSIS', '20260713081500-0400'],
            ['RX-DISP-VANC-1', 'PLACER-RX-VANC', 'ACC-RX-VANC', 'VANCOMYCIN_IV^Vancomycin intravenous infusion', 'RXFILL-VANC-1', 'PATIENT-RX-VANC', 'ENC-RX-VANC', '20260713143000-0400'],
            $this->fixture('rds-dispense-ceftriaxone'),
        );
        $pipeline->ingest($source->source_key, new SourceMessage('HL7V2', ['raw_hl7' => $lateDispense]));

        $this->assertSame(1, Dispense::query()->count());
        $order->refresh();
        $medication->refresh();
        $this->assertSame('RX_DISCONTINUED', $order->current_milestone_code);
        $this->assertNotNull($order->terminal_at);
        $this->assertSame('discontinued', $medication->order_status);
        $this->assertSame(1, DB::table('prod.ancillary_milestones')->where('milestone_code', 'RX_DISPENSED')->count());
    }

    public function test_missing_terminology_mapping_flags_unmapped_local_without_failing_the_order(): void
    {
        $source = $this->source('pharmacy.unmapped', ['RDE']);
        $receipt = app(AncillaryMessageIngestPipeline::class)->ingest(
            $source->source_key,
            new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('unmapped-local-compound')]),
        );

        $this->assertCount(1, $receipt['canonical_event_ids']);
        $this->assertSame(0, DB::table('raw.dead_letters')->count());
        $this->assertSame(1, AncillaryOrder::query()->count());
        $medication = MedicationOrder::query()->firstOrFail();
        $this->assertSame('unmapped_local', $medication->terminology_status);
        $this->assertSame('MAGIC_MOUTHWASH_SUSP', $medication->local_code);
        $this->assertNull($medication->rxnorm_cui);
        $this->assertNull($medication->ndc_code);
        $this->assertNull($medication->rx_formulary_id);
        $this->assertSame('Magic mouthwash suspension', $medication->medication_label);
        $this->assertSame(1, MedicationOrder::query()->unmapped()->count());
        $this->assertSame(1, DB::table('prod.ancillary_milestones')->where('milestone_code', 'RX_ORDERED')->count());
    }

    public function test_fhir_medication_request_and_dispense_backfill_converge_without_fabricating_administration(): void
    {
        $source = $this->source('pharmacy.fhir', ['FHIR'], 'fhir');
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $pipeline->ingest($source->source_key, new SourceMessage('FHIR', ['resource' => [
            'resourceType' => 'MedicationRequest',
            'id' => 'mr-rx-1',
            'meta' => ['versionId' => '1'],
            'identifier' => [['system' => 'urn:rx:order', 'value' => 'FHIR-RX-1']],
            'status' => 'active',
            'intent' => 'order',
            'priority' => 'stat',
            'authoredOn' => '2026-07-13T08:00:00-04:00',
            'subject' => ['reference' => 'Patient/secret-rx-patient'],
            'encounter' => ['reference' => 'Encounter/secret-rx-encounter'],
            'medicationCodeableConcept' => ['coding' => [
                ['system' => 'urn:rx:local', 'code' => 'CEFTRIAXONE_1G_IV', 'display' => 'Ceftriaxone 1 g intravenous'],
                ['system' => 'http://www.nlm.nih.gov/research/umls/rxnorm', 'code' => '2193', 'display' => 'Ceftriaxone'],
            ]],
            'dosageInstruction' => [['route' => ['coding' => [['code' => 'IV']]]]],
            'extension' => [['url' => 'https://zephyrus.example/fhir/StructureDefinition/patient-class', 'valueCode' => 'emergency']],
        ]]));

        $order = AncillaryOrder::query()->firstOrFail();
        $medication = MedicationOrder::query()->firstOrFail();
        $this->assertSame('FHIR-RX-1', $order->source_order_key);
        $this->assertSame('MedicationRequest/mr-rx-1', $order->metadata['reconciliation_key']);
        $this->assertSame('stat', $order->priority);
        $this->assertSame('emergency', $order->patient_class);
        $this->assertSame('stat', $medication->clock_class);
        $this->assertSame('2193', $medication->rxnorm_cui);
        $this->assertSame('mapped', $medication->terminology_status);
        $this->assertSame('IV', $medication->route);

        $dispense = new SourceMessage('FHIR', ['resource' => [
            'resourceType' => 'MedicationDispense',
            'id' => 'md-rx-1',
            'meta' => ['versionId' => '1'],
            'identifier' => [['system' => 'urn:rx:fill', 'value' => 'FHIR-FILL-1']],
            'status' => 'completed',
            'authorizingPrescription' => [['reference' => 'https://fhir.example.test/r4/MedicationRequest/mr-rx-1']],
            'subject' => ['reference' => 'Patient/secret-rx-patient'],
            'medicationCodeableConcept' => ['coding' => [
                ['system' => 'urn:rx:local', 'code' => 'CEFTRIAXONE_1G_IV', 'display' => 'Ceftriaxone 1 g intravenous'],
            ]],
            'whenPrepared' => '2026-07-13T08:10:00-04:00',
            'whenHandedOver' => '2026-07-13T08:20:00-04:00',
        ]]);
        $pipeline->ingest($source->source_key, $dispense);
        $this->assertTrue($pipeline->ingest($source->source_key, $dispense)['duplicate']);

        $this->assertSame(1, AncillaryOrder::query()->count());
        $this->assertSame(1, MedicationOrder::query()->count());
        $this->assertSame(1, Dispense::query()->count());
        $projected = Dispense::query()->firstOrFail();
        $this->assertSame('FHIR-FILL-1', $projected->source_dispense_key);
        $this->assertSame('other', $projected->dispense_channel);
        $this->assertTrue((bool) $projected->metadata['fhir_backfill']);
        $this->assertSame('2026-07-13T12:20:00+00:00', $projected->dispensed_at->toIso8601String());
        $this->assertSame('RX_DISPENSED', AncillaryOrder::query()->firstOrFail()->current_milestone_code);

        // FHIR dispense data never substitutes for administration evidence.
        $this->assertSame(0, DB::table('prod.ancillary_milestones')->where('milestone_code', 'RX_ADMINISTERED')->count());
        $this->assertSame(0, DB::table('prod.rx_administrations')->count());
        $this->assertNotSame('administered', MedicationOrder::query()->firstOrFail()->order_status);

        $canonicalPayloads = DB::table('integration.canonical_events')->pluck('payload')->implode('\n');
        $this->assertStringNotContainsString('secret-rx-patient', $canonicalPayloads);
        $this->assertStringNotContainsString('secret-rx-encounter', $canonicalPayloads);
    }

    public function test_governed_source_profile_rejects_unauthorized_family_and_department(): void
    {
        $noRde = $this->source('pharmacy.no-rde', ['RDS']);
        try {
            app(AncillaryMessageIngestPipeline::class)->ingest(
                $noRde->source_key,
                new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('stat-sepsis-ceftriaxone')]),
            );
            $this->fail('An RDE message was accepted by a source without RDE authorization.');
        } catch (AncillaryIngestException $exception) {
            $this->assertSame('source_message_mismatch', $exception->reasonCode);
        }

        $labOnly = $this->source('pharmacy.lab-scope', ['RDE'], 'hl7v2', ['lab']);
        try {
            app(AncillaryMessageIngestPipeline::class)->ingest(
                $labOnly->source_key,
                new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('stat-sepsis-ceftriaxone')]),
            );
            $this->fail('An RDE message was accepted outside the governed department scope.');
        } catch (AncillaryIngestException $exception) {
            $this->assertSame('source_message_mismatch', $exception->reasonCode);
        }

        $this->assertSame(0, AncillaryOrder::query()->count());
        $this->assertSame(2, DB::table('raw.dead_letters')->where('reason_code', 'source_message_mismatch')->count());
    }

    /** @return array<string, mixed> */
    private function queueEvent(string $controlId, string $state, string $occurredAt): array
    {
        return [
            'envelope_version' => 1,
            'control_id' => $controlId,
            'queue_state' => $state,
            'occurred_at' => $occurredAt,
            'source_order_key' => 'ACC-RX-CEF',
            'placer_order_key' => 'PLACER-RX-CEF',
            'queue_ref' => 'verification',
            'patient_id' => 'PATIENT-RX-SEPSIS',
            'encounter_id' => 'ENC-RX-SEPSIS',
        ];
    }

    /** @param list<string> $families @param list<string> $departments */
    private function source(
        string $key,
        array $families,
        string $interfaceType = 'hl7v2',
        array $departments = ['rx'],
    ): Source {
        return app(SourceRegistryService::class)->ensureSource([
            ...$this->canonicalIntegrationSourceScope(),
            'source_key' => $key,
            'source_name' => $key,
            'system_class' => 'pharmacy',
            'interface_type' => $interfaceType,
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

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/hl7/rx/{$name}.hl7"));
    }
}
