<?php

namespace Tests\Feature\Ancillary;

use App\Integrations\Healthcare\Ancillary\PharmacyAdministrationImportNormalizer;
use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Services\AncillaryMessageIngestPipeline;
use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Integration\Source;
use App\Models\Pharmacy\Administration;
use App\Models\Pharmacy\MedicationOrder;
use App\Services\Pharmacy\PharmacyAdministrationFreshnessService;
use App\Services\Pharmacy\PharmacyAdministrationImportService;
use App\Services\Pharmacy\RxAdministrationProjector;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PharmacyAdministrationImportTest extends TestCase
{
    use RefreshDatabase;

    private const CUTOFF = '2026-07-14T05:00:00-04:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AncillaryReferenceSeeder::class);
    }

    public function test_given_administration_attaches_to_exactly_one_order_through_raw_canonical_projection_provenance(): void
    {
        $warehouse = $this->orderedScenario();
        $summary = app(PharmacyAdministrationImportService::class)->import($warehouse->source_key, $this->batch());

        $this->assertSame(['rows' => 1, 'projected' => 1, 'duplicates' => 0, 'dead_lettered' => 0], array_intersect_key($summary, array_flip(['rows', 'projected', 'duplicates', 'dead_lettered'])));

        // One spine order (never fabricated), the RX_ADMINISTERED milestone
        // asserted from the warehouse source, and the projected satellite row
        // stamped with the batch as-of cutoff.
        $this->assertSame(1, AncillaryOrder::query()->count());
        $order = AncillaryOrder::query()->firstOrFail();
        $this->assertSame('RX_ADMINISTERED', $order->current_milestone_code);
        $this->assertSame(1, DB::table('prod.ancillary_milestones')->where('milestone_code', 'RX_ADMINISTERED')->count());

        $medication = MedicationOrder::query()->firstOrFail();
        $this->assertSame('administered', $medication->order_status);

        $administration = Administration::query()->firstOrFail();
        $this->assertSame((int) $medication->rx_order_id, (int) $administration->rx_order_id);
        $this->assertSame('MAR-1001', $administration->source_administration_key);
        $this->assertSame('1', $administration->source_row_version);
        $this->assertSame('BCMA-EXTRACT-20260714-0500', $administration->import_batch_key);
        $this->assertSame('bcma_warehouse', $administration->administration_source_class);
        $this->assertSame('given', $administration->administration_status);
        $this->assertSame('IV', $administration->administration_route);
        $this->assertSame('2026-07-13T13:15:00+00:00', $administration->administered_at->toIso8601String());
        $this->assertSame('2026-07-14T09:00:00+00:00', $administration->source_cutoff_at->toIso8601String());
        $this->assertSame('CEFTRIAXONE_1G_IV', $administration->metadata['local_code']);

        // Raw envelope + canonical event + provenance all exist per row.
        $this->assertSame(1, DB::table('raw.inbound_messages')->where('message_type', 'ANCILLARY_RX_ADMIN_BATCH')->count());
        $this->assertSame(1, DB::table('integration.canonical_events')->where('event_type', 'ancillary.pharmacy.administered')->count());
        $this->assertDatabaseHas('integration.provenance_records', [
            'target_schema' => 'prod', 'target_table' => 'rx_administrations', 'target_pk' => (string) $administration->rx_administration_id,
        ]);
        $this->assertSame(0, DB::table('raw.dead_letters')->count());
    }

    public function test_reimport_is_idempotent_and_corrections_append_versioned_evidence(): void
    {
        $warehouse = $this->orderedScenario();
        $importer = app(PharmacyAdministrationImportService::class);
        $importer->import($warehouse->source_key, $this->batch());

        // Exact batch reimport short-circuits without duplicating anything.
        $replay = $importer->import($warehouse->source_key, $this->batch());
        $this->assertSame(1, $replay['duplicates']);
        $this->assertSame(0, $replay['projected']);
        $this->assertSame(1, Administration::query()->count());
        $this->assertSame(1, DB::table('prod.ancillary_milestones')->where('milestone_code', 'RX_ADMINISTERED')->count());

        // A corrected warehouse row (same identity, higher version) APPENDS a
        // second versioned row; the prior fact is never mutated.
        $corrected = $this->batch([
            'extract_id' => 'BCMA-EXTRACT-20260714-0500-C1',
            'administrations' => [$this->row(['row_version' => '2', 'administered_at' => '2026-07-13T09:45:00-04:00'])],
        ]);
        $correction = $importer->import($warehouse->source_key, $corrected);
        $this->assertSame(1, $correction['projected']);

        $versions = Administration::query()->orderBy('rx_administration_id')->get();
        $this->assertCount(2, $versions);
        $this->assertSame('1', $versions[0]->source_row_version);
        $this->assertSame('2026-07-13T13:15:00+00:00', $versions[0]->administered_at->toIso8601String());
        $this->assertSame('2', $versions[1]->source_row_version);
        $this->assertSame('2026-07-13T13:45:00+00:00', $versions[1]->administered_at->toIso8601String());
        $this->assertSame((int) $versions[0]->rx_administration_id, (int) $versions[1]->metadata['correction_of']['rx_administration_id']);
        $this->assertSame('1', $versions[1]->metadata['correction_of']['source_row_version']);

        // Projection re-selects the correction: the current version, the
        // current-versions scope, and the selected milestone clock all move
        // to the corrected assertion while both originals are retained.
        $sourceId = (int) $versions[1]->source_id;
        $current = app(RxAdministrationProjector::class)->currentVersion($sourceId, 'MAR-1001');
        $this->assertSame('2', $current?->source_row_version);
        $this->assertSame(['2'], Administration::query()->currentVersions()->pluck('source_row_version')->all());
        $this->assertSame(2, DB::table('prod.ancillary_milestones')->where('milestone_code', 'RX_ADMINISTERED')->count());
        $order = AncillaryOrder::query()->firstOrFail();
        $this->assertSame('2026-07-13 13:45:00', $order->current_milestone_at->toDateTimeString());
        // The disagreement surfaces as a data-quality flag instead of a
        // silent average (§7.5.6).
        $this->assertTrue((bool) ($order->metadata['has_source_conflict'] ?? false));

        // Replaying the corrected batch appends nothing further.
        $importer->import($warehouse->source_key, $corrected);
        $this->assertSame(2, Administration::query()->count());
    }

    public function test_unmatched_and_ambiguous_administrations_never_attach_to_the_wrong_order(): void
    {
        $warehouse = $this->source('rx.warehouse', ['RX_ADMIN_BATCH']);
        $importer = app(PharmacyAdministrationImportService::class);

        // No candidate: the row dead-letters into reconciliation, no spine
        // order is fabricated, and no satellite fact exists.
        $ghost = $importer->import($warehouse->source_key, $this->batch([
            'administrations' => [$this->row(['order' => ['source_order_key' => 'ACC-RX-GHOST']])],
        ]));
        $this->assertSame(1, $ghost['dead_lettered']);
        $this->assertSame(['unmatched_administration_order' => 1], $ghost['reasons']);
        $this->assertDatabaseHas('raw.dead_letters', ['reason_code' => 'unmatched_administration_order', 'status' => 'open']);
        $this->assertSame(0, AncillaryOrder::query()->count());
        $this->assertSame(0, Administration::query()->count());

        // Two candidates: attachment is never guessed.
        AncillaryOrder::factory()->pharmacy()->create(['source_order_key' => 'ACC-RX-DUP']);
        AncillaryOrder::factory()->pharmacy()->create(['source_order_key' => 'ACC-RX-DUP']);
        $ambiguous = $importer->import($warehouse->source_key, $this->batch([
            'administrations' => [$this->row(['administration_id' => 'MAR-2002', 'order' => ['source_order_key' => 'ACC-RX-DUP']])],
        ]));
        $this->assertSame(['ambiguous_administration_order' => 1], $ambiguous['reasons']);
        $this->assertSame(0, Administration::query()->count());
        $this->assertSame(0, DB::table('prod.ancillary_milestones')->count());

        // Exactly one candidate attaches to THAT order and no other.
        $solo = AncillaryOrder::factory()->pharmacy()->create(['source_order_key' => 'ACC-RX-SOLO']);
        $matched = $importer->import($warehouse->source_key, $this->batch([
            'administrations' => [$this->row(['administration_id' => 'MAR-3003', 'order' => ['source_order_key' => 'ACC-RX-SOLO']])],
        ]));
        $this->assertSame(1, $matched['projected']);
        $administration = Administration::query()->firstOrFail();
        $medication = MedicationOrder::query()->firstOrFail();
        $this->assertSame((int) $solo->ancillary_order_id, (int) $medication->ancillary_order_id);
        $this->assertSame((int) $medication->rx_order_id, (int) $administration->rx_order_id);
        $this->assertSame(1, DB::table('prod.ancillary_milestones')->where('ancillary_order_id', $solo->ancillary_order_id)->where('milestone_code', 'RX_ADMINISTERED')->count());
    }

    public function test_fail_closed_validation_dead_letters_each_reason_code_without_silent_coercion(): void
    {
        $warehouse = $this->source('rx.warehouse.quality', ['RX_ADMIN_BATCH']);
        $importer = app(PharmacyAdministrationImportService::class);

        $cases = [
            [['source_cutoff_at' => null], 'missing_source_cutoff'],
            [['source_cutoff_at' => 'not-a-timestamp'], 'malformed_timestamp'],
            [['source_cutoff_at' => '2026-07-14T05:00:00'], 'malformed_timestamp'],
            [['extract_id' => null], 'missing_extract_identity'],
            [['envelope_version' => 2], 'unsupported_envelope_version'],
            [['source_class' => 'diversion_ai'], 'invalid_source_class'],
            [['administrations' => [$this->row(['administration_id' => null])]], 'missing_administration_identity'],
            [['administrations' => [$this->row(['administration_id' => 'MAR-DQ-NO-ORDER', 'order' => null])]], 'missing_order_identity'],
            [['administrations' => [$this->row(['administration_id' => 'MAR-DQ-PLACER-ONLY', 'order' => ['placer_order_key' => 'ONLY-PLACER']])]], 'missing_order_identity'],
            [['administrations' => [$this->row(['administration_id' => 'MAR-DQ-NO-TIME', 'administered_at' => null])]], 'missing_administered_at'],
            [['administrations' => [$this->row(['administration_id' => 'MAR-DQ-AFTER-CUTOFF', 'administered_at' => '2026-07-14T06:00:00-04:00'])]], 'administration_after_cutoff'],
            [['administrations' => [$this->row(['administration_id' => 'MAR-DQ-BAD-TIME', 'administered_at' => 'yesterday-ish'])]], 'malformed_timestamp'],
            [['administrations' => [$this->row(['administration_id' => 'MAR-DQ-BAD-STATUS', 'status' => 'wasted'])]], 'invalid_administration_status'],
        ];
        foreach ($cases as $index => [$overrides, $reason]) {
            $overrides['administrations'] ??= [$this->row(['administration_id' => "MAR-DQ-{$index}"])];
            $summary = $importer->import($warehouse->source_key, $this->batch($overrides));
            $this->assertSame(1, $summary['dead_lettered'], "case {$index} must dead-letter");
            $this->assertSame([$reason => 1], $summary['reasons'], "case {$index} expected [{$reason}]");
        }

        $this->assertSame(0, Administration::query()->count());
        $this->assertSame(0, DB::table('prod.ancillary_milestones')->count());
        $this->assertSame(count($cases), DB::table('raw.dead_letters')->count());
    }

    public function test_freshness_seam_labels_as_of_cutoff_and_refuses_real_time_claims(): void
    {
        $warehouse = $this->orderedScenario();
        app(PharmacyAdministrationImportService::class)->import($warehouse->source_key, $this->batch());
        $freshness = app(PharmacyAdministrationFreshnessService::class);
        $sourceId = (int) $warehouse->source_id;
        $cutoff = CarbonImmutable::parse(self::CUTOFF)->utc();

        // The seam answers "latest administration cutoff per source".
        $latest = $freshness->latestCutoffPerSource();
        $this->assertCount(1, $latest);
        $this->assertSame($sourceId, (int) $latest->first()->source_id);
        $this->assertSame('bcma_warehouse', $latest->first()->administration_source_class);
        $this->assertSame('BCMA-EXTRACT-20260714-0500', $latest->first()->import_batch_key);
        $this->assertSame($cutoff->toIso8601String(), CarbonImmutable::parse($latest->first()->source_cutoff_at)->utc()->toIso8601String());

        // Warehouse evidence within cadence is 'batch' — cutoff-qualified and
        // NEVER 'fresh', even minutes after the extract landed.
        $recent = $freshness->envelopeForSource($sourceId, $cutoff->addMinutes(120));
        $this->assertSame('batch', $recent->status);
        $this->assertSame(120, $recent->lagMinutes);
        $this->assertSame($cutoff->getTimestamp(), $recent->sourceCutoffAt?->getTimestamp());
        $this->assertStringContainsString('never real-time', (string) $recent->explanation);

        // A stale cutoff demotes to 'stale' so no compliance claim survives.
        $stale = $freshness->envelopeForSource($sourceId, $cutoff->addMinutes(2000));
        $this->assertSame('stale', $stale->status);
        $this->assertStringContainsString('cannot claim compliance', (string) $stale->explanation);

        // No evidence classifies 'unknown' — unknown is not compliant.
        $this->assertSame('unknown', $freshness->envelopeForSource($sourceId + 999)->status);

        // RAS is a supported FUTURE real-time class without changing the
        // warehouse baseline: it may classify 'fresh' inside the operational
        // window and demotes to 'stale' outside it.
        $ras = $this->source('rx.ras', ['RX_ADMIN_BATCH']);
        AncillaryOrder::factory()->pharmacy()->create(['source_order_key' => 'ACC-RX-RAS']);
        app(PharmacyAdministrationImportService::class)->import($ras->source_key, $this->batch([
            'extract_id' => 'RAS-STREAM-CHECKPOINT-1',
            'source_class' => 'ras',
            'administrations' => [$this->row(['administration_id' => 'MAR-RAS-1', 'order' => ['source_order_key' => 'ACC-RX-RAS']])],
        ]));
        $this->assertSame('fresh', $freshness->envelopeForSource((int) $ras->source_id, $cutoff->addMinutes(5))->status);
        $this->assertSame('stale', $freshness->envelopeForSource((int) $ras->source_id, $cutoff->addMinutes(180))->status);

        // The overall envelope is the most severe classification, so a fresh
        // RAS stream can never mask the warehouse-qualified tail.
        $this->assertSame('batch', $freshness->overallEnvelope($cutoff->addMinutes(5))->status);
    }

    public function test_non_given_statuses_persist_as_satellite_facts_without_milestones_or_status_movement(): void
    {
        $warehouse = $this->orderedScenario();
        $summary = app(PharmacyAdministrationImportService::class)->import($warehouse->source_key, $this->batch([
            'administrations' => [$this->row(['administration_id' => 'MAR-HELD-1', 'status' => 'held'])],
        ]));
        $this->assertSame(1, $summary['projected']);

        $administration = Administration::query()->firstOrFail();
        $this->assertSame('held', $administration->administration_status);
        $this->assertSame(0, DB::table('prod.ancillary_milestones')->where('milestone_code', 'RX_ADMINISTERED')->count());
        $this->assertSame('ordered', MedicationOrder::query()->firstOrFail()->order_status);
        $this->assertSame(1, DB::table('integration.canonical_events')->where('event_type', 'ancillary.pharmacy.administration_record')->count());
        $this->assertDatabaseHas('integration.provenance_records', [
            'target_schema' => 'prod', 'target_table' => 'rx_administrations', 'target_pk' => (string) $administration->rx_administration_id,
        ]);
    }

    public function test_contract_defines_no_administering_user_identity_or_clinical_dose_values(): void
    {
        $prohibited = '/user|actor|staff|witness|badge|employee|nurse|admin_by|dose_|amount|strength|rate|risk|score|rank|diversion/i';
        foreach ([...PharmacyAdministrationImportNormalizer::ENVELOPE_KEYS, ...PharmacyAdministrationImportNormalizer::ROW_KEYS] as $key) {
            $this->assertDoesNotMatchRegularExpression($prohibited, $key, 'Envelope v1 must not define user/actor/dose fields');
        }

        // Vendor attribution or dose columns that leak past the adapter edge
        // never cross the canonical boundary.
        $warehouse = $this->orderedScenario();
        app(AncillaryMessageIngestPipeline::class)->ingest($warehouse->source_key, new SourceMessage('RX_ADMIN_BATCH', [
            'envelope_version' => 1,
            'extract_id' => 'BCMA-EXTRACT-LEAK',
            'source_cutoff_at' => self::CUTOFF,
            'administration' => [
                ...$this->row(),
                'administering_user' => 'U-SECRET-42',
                'dose_amount' => 500,
            ],
        ]));
        $canonical = DB::table('integration.canonical_events')->pluck('payload')->implode('\n');
        foreach (['U-SECRET-42', 'administering_user', 'dose_amount'] as $needle) {
            $this->assertStringNotContainsString($needle, $canonical);
        }
        $row = json_encode(Administration::query()->firstOrFail()->getAttributes(), JSON_THROW_ON_ERROR);
        foreach (['U-SECRET-42', 'dose_amount'] as $needle) {
            $this->assertStringNotContainsString($needle, $row);
        }
        // Raw identifiers are pseudonymized before the canonical boundary.
        $this->assertStringNotContainsString('PATIENT-RX-SEPSIS', $canonical);
    }

    public function test_artisan_command_ingests_a_batch_file_and_signals_reconciliation_failures(): void
    {
        $warehouse = $this->orderedScenario();
        $path = base_path('tests/Fixtures/pharmacy/administration-import-v1.json');

        $this->artisan('ancillary:import-administrations', ['path' => $path, '--source' => $warehouse->source_key, '--dry-run' => true])
            ->assertExitCode(0);
        $this->assertSame(0, Administration::query()->count());

        $this->artisan('ancillary:import-administrations', ['path' => $path, '--source' => $warehouse->source_key])
            ->assertExitCode(0);
        $this->assertSame(1, Administration::query()->count());
        $this->assertSame('BCMA-EXTRACT-20260714-0500', Administration::query()->firstOrFail()->import_batch_key);

        // A batch with an unmatched row exits non-zero so operators see the
        // reconciliation queue, while good rows still land.
        $mixed = tempnam(sys_get_temp_dir(), 'rxadmin').'.json';
        file_put_contents($mixed, json_encode($this->batch([
            'extract_id' => 'BCMA-EXTRACT-20260714-0500-M1',
            'administrations' => [
                $this->row(['administration_id' => 'MAR-GOOD-2', 'row_version' => '2']),
                $this->row(['administration_id' => 'MAR-BAD-1', 'order' => ['source_order_key' => 'ACC-RX-GHOST']]),
            ],
        ]), JSON_THROW_ON_ERROR));
        $this->artisan('ancillary:import-administrations', ['path' => $mixed, '--source' => $warehouse->source_key])
            ->assertExitCode(1);
        $this->assertSame(2, Administration::query()->count());
        $this->assertDatabaseHas('raw.dead_letters', ['reason_code' => 'unmatched_administration_order']);

        $this->artisan('ancillary:import-administrations', ['path' => '/nonexistent/batch.json', '--source' => $warehouse->source_key])
            ->assertExitCode(1);
    }

    /**
     * Seeds the RDE-created medication order the golden batch links to and
     * returns the separate governed warehouse source.
     */
    private function orderedScenario(): Source
    {
        $ehr = $this->source('rx.ehr', ['RDE'], systemClass: 'pharmacy');
        app(AncillaryMessageIngestPipeline::class)->ingest($ehr->source_key, new SourceMessage('HL7V2', [
            'raw_hl7' => (string) file_get_contents(base_path('tests/Fixtures/hl7/rx/stat-sepsis-ceftriaxone.hl7')),
        ]));

        return $this->source('rx.warehouse', ['RX_ADMIN_BATCH']);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function batch(array $overrides = []): array
    {
        $batch = [
            'envelope_version' => 1,
            'extract_id' => 'BCMA-EXTRACT-20260714-0500',
            'source_cutoff_at' => self::CUTOFF,
            'administrations' => [$this->row()],
        ];
        foreach ($overrides as $key => $value) {
            $batch[$key] = $value;
        }

        return array_filter($batch, fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function row(array $overrides = []): array
    {
        $row = [
            'administration_id' => 'MAR-1001',
            'row_version' => '1',
            'order' => ['source_order_key' => 'ACC-RX-CEF', 'placer_order_key' => 'PLACER-RX-CEF'],
            'administered_at' => '2026-07-13T09:15:00-04:00',
            'status' => 'given',
            'medication' => ['local_code' => 'CEFTRIAXONE_1G_IV', 'label' => 'Ceftriaxone 1 g intravenous'],
            'route' => 'IV',
        ];
        foreach ($overrides as $key => $value) {
            $row[$key] = $value;
        }

        return array_filter($row, fn (mixed $value): bool => $value !== null);
    }

    /** @param list<string> $families @param list<string> $departments */
    private function source(string $key, array $families, array $departments = ['rx'], string $systemClass = 'clinical_warehouse'): Source
    {
        return app(SourceRegistryService::class)->ensureSource([
            'source_key' => $key,
            'source_name' => $key,
            'system_class' => $systemClass,
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
}
