<?php

namespace Tests\Feature\Ocel;

use App\Domain\Arena\ArenaService;
use App\Domain\Arena\Copilot\ArenaQueryCatalog;
use App\Domain\Ocel\HospitalProcessCatalog;
use App\Domain\Ocel\OcelJsonExporter;
use App\Domain\Ocel\OcelProjector;
use App\Models\Ancillary\AncillaryMilestone;
use App\Models\Ancillary\AncillaryOrder;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OcelAncillaryProjectionTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $anchor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AncillaryReferenceSeeder::class);
        $this->anchor = CarbonImmutable::parse('2026-07-11T12:00:00Z');
    }

    public function test_ancillary_milestones_emit_expected_events_objects_and_qualified_relations(): void
    {
        $rad = AncillaryOrder::factory()->radiology()->create([
            'encounter_ref' => 'SENSITIVE-ENCOUNTER-RAD',
            'metadata' => ['modality' => 'CT', 'scanner_ref' => 'SCANNER-SENSITIVE-1'],
            'ordered_at' => $this->anchor,
        ]);
        $this->milestones($rad, ['RAD_ORDERED', 'RAD_EXAM_END', 'RAD_PRELIM', 'RAD_FINAL', 'RAD_CRITICAL_NOTIFIED', 'RAD_CRITICAL_ACKED']);

        $lab = AncillaryOrder::factory()->lab()->create([
            'encounter_ref' => 'SENSITIVE-ENCOUNTER-LAB',
            'metadata' => ['test_family' => 'troponin', 'analyzer_ref' => 'ANALYZER-SENSITIVE-1'],
            'ordered_at' => $this->anchor,
        ]);
        $this->milestones($lab, ['LAB_ORDERED', 'LAB_COLLECTED', 'LAB_RECEIVED', 'LAB_RESULTED', 'LAB_VERIFIED']);

        $pathology = AncillaryOrder::factory()->pathology()->create(['ordered_at' => $this->anchor]);
        $this->milestones($pathology, ['AP_RECEIVED', 'AP_GROSSED', 'AP_SLIDES_READY', 'AP_DIAGNOSED', 'AP_SIGNED_OUT']);

        $pharmacy = AncillaryOrder::factory()->pharmacy()->create(['ordered_at' => $this->anchor]);
        $this->milestones($pharmacy, ['RX_ORDERED', 'RX_VERIFIED', 'RX_PREP_COMPLETE', 'RX_DISPENSED', 'RX_DELIVERED', 'RX_ADMINISTERED']);

        $result = app(OcelProjector::class)->project($this->anchor->subMinute(), $this->anchor->addHour());

        $this->assertSame(22, $result['source_rows']['prod.ancillary_milestones']);
        $this->assertSame(22, DB::table('ocel.events')->where('source_system', 'prod.ancillary_milestones')->count());
        foreach (['imaging-ordered', 'images-acquired', 'study-interpreted', 'report-finalized', 'responsible-team-contacted', 'finding-acknowledged', 'test-ordered', 'specimen-collected', 'specimen-accessioned', 'analysis-completed', 'result-validated', 'specimen-received', 'blocks-prepared', 'slides-ready', 'diagnosis-recorded', 'medication-ordered', 'order-verified', 'dose-prepared', 'dose-dispensed', 'dose-delivered', 'dose-administered'] as $activity) {
            $this->assertDatabaseHas('ocel.events', ['activity' => $activity, 'source_system' => 'prod.ancillary_milestones']);
            $this->assertDatabaseHas('ocel.activities', ['activity' => $activity]);
        }

        foreach (['Ancillary Order', 'Imaging Study', 'Scanner', 'Imaging Read', 'Critical Result', 'Communication Task', 'Laboratory Test', 'Laboratory Specimen', 'Analyzer', 'Laboratory Result', 'AP Case', 'Pathology Specimen', 'Pathology Slide / Block', 'Diagnostic Report', 'Medication Order', 'Pharmacy Work', 'Medication Dose'] as $type) {
            $this->assertDatabaseHas('ocel.objects', ['type' => $type]);
            $this->assertDatabaseHas('ocel.object_types', ['type' => $type]);
        }

        $radOrderId = 'anc-order-'.$rad->order_uuid;
        $studyId = 'imaging-study-'.$rad->order_uuid;
        $labOrderId = 'anc-order-'.$lab->order_uuid;
        $testId = 'laboratory-test-'.$lab->order_uuid;
        $specimenId = 'laboratory-specimen-'.$lab->order_uuid;
        $this->assertDatabaseHas('ocel.object_object', ['from_id' => $studyId, 'to_id' => $radOrderId, 'qualifier' => 'fulfills']);
        $this->assertDatabaseHas('ocel.object_object', ['from_id' => $specimenId, 'to_id' => $testId, 'qualifier' => 'supports']);
        $this->assertDatabaseHas('ocel.object_object', ['from_id' => $testId, 'to_id' => $labOrderId, 'qualifier' => 'fulfills']);
        $this->assertGreaterThan(22, DB::table('ocel.event_object')->whereIn(
            'event_id',
            DB::table('ocel.events')->where('source_system', 'prod.ancillary_milestones')->pluck('id'),
        )->count());

        $serialized = json_encode([
            'objects' => DB::table('ocel.objects')->get(),
            'events' => DB::table('ocel.events')->where('source_system', 'prod.ancillary_milestones')->get(),
            'relations' => DB::table('ocel.object_object')->get(),
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('SENSITIVE-ENCOUNTER', $serialized);
        $this->assertStringNotContainsString('SCANNER-SENSITIVE', $serialized);
        $this->assertStringNotContainsString('ANALYZER-SENSITIVE', $serialized);
        $this->assertSame([], app(OcelJsonExporter::class)->validate(app(OcelJsonExporter::class)->export()));
    }

    public function test_reprojection_and_scoped_backfill_are_idempotent(): void
    {
        $included = AncillaryOrder::factory()->lab()->create(['ordered_at' => $this->anchor]);
        $excluded = AncillaryOrder::factory()->radiology()->create(['ordered_at' => $this->anchor]);
        $this->milestones($included, ['LAB_ORDERED', 'LAB_RESULTED']);
        $this->milestones($excluded, ['RAD_ORDERED', 'RAD_FINAL']);
        $projector = app(OcelProjector::class);

        $first = $projector->projectAncillary($this->anchor->subMinute(), $this->anchor->addHour(), [$included->ancillary_order_id]);
        $second = $projector->projectAncillary($this->anchor->subMinute(), $this->anchor->addHour(), [$included->ancillary_order_id]);

        $this->assertSame(2, $first['events']);
        $this->assertSame($first, $second);
        $this->assertSame(2, DB::table('ocel.events')->where('source_system', 'prod.ancillary_milestones')->count());
        $this->assertDatabaseMissing('ocel.events', ['activity' => 'imaging-ordered']);
        $this->assertSame(2, DB::table('ocel.object_changes')->where('object_id', 'anc-order-'.$included->order_uuid)->count());

        $this->artisan('ocel:project-ancillary', [
            '--order-id' => [$excluded->ancillary_order_id],
            '--since' => $this->anchor->subMinute()->toIso8601String(),
            '--until' => $this->anchor->addHour()->toIso8601String(),
            '--json' => true,
        ])
            ->expectsOutputToContain('"events":2')
            ->assertSuccessful();

        $this->assertDatabaseHas('ocel.events', ['activity' => 'imaging-ordered']);
        $this->assertSame(4, DB::table('ocel.events')->where('source_system', 'prod.ancillary_milestones')->count());
        $reconciliation = collect($projector->reconcile($this->anchor->subMinute(), $this->anchor->addHour()))
            ->firstWhere('source', 'prod.ancillary_milestones');
        $this->assertSame(4, $reconciliation['source_rows']);
        $this->assertSame(4, $reconciliation['distinct_source_refs']);
        $this->assertSame(4, $reconciliation['projected_events']);
    }

    public function test_existing_arena_queries_and_sidecar_paths_consume_d1_d5_and_d7_rows(): void
    {
        $rad = AncillaryOrder::factory()->radiology()->create(['ordered_at' => $this->anchor]);
        $lab = AncillaryOrder::factory()->lab()->create(['ordered_at' => $this->anchor]);
        $pathology = AncillaryOrder::factory()->pathology()->create(['ordered_at' => $this->anchor]);
        $this->milestones($rad, ['RAD_ORDERED', 'RAD_FINAL']);
        $this->milestones($lab, ['LAB_ORDERED', 'LAB_VERIFIED']);
        $this->milestones($pathology, ['AP_RECEIVED', 'AP_GROSSED', 'AP_SLIDES_READY', 'AP_DIAGNOSED', 'AP_SIGNED_OUT']);
        app(OcelProjector::class)->projectAncillary($this->anchor->subMinute(), $this->anchor->addHour());

        $queries = app(ArenaQueryCatalog::class);
        $labRows = $queries->run('activities_for_object_type', ['object_type' => 'Laboratory Test'])['rows'];
        $imagingRows = $queries->run('activities_for_object_type', ['object_type' => 'Imaging Study'])['rows'];
        $pathologyRows = $queries->run('activities_for_object_type', ['object_type' => 'AP Case'])['rows'];
        $this->assertEqualsCanonicalizing(['test-ordered', 'result-validated'], array_column($labRows, 'activity'));
        $this->assertEqualsCanonicalizing(['imaging-ordered', 'report-finalized'], array_column($imagingRows, 'activity'));
        $this->assertEqualsCanonicalizing(
            ['specimen-received', 'blocks-prepared', 'slides-ready', 'diagnosis-recorded', 'report-finalized'],
            array_column($pathologyRows, 'activity'),
        );

        foreach ([
            'result-validated' => 'D1',
            'specimen-received' => 'D7',
            'blocks-prepared' => 'D7',
            'slides-ready' => 'D7',
            'diagnosis-recorded' => 'D7',
            'report-finalized' => 'D7',
        ] as $activity => $processId) {
            $event = DB::table('ocel.events')
                ->where('source_system', 'prod.ancillary_milestones')
                ->where('activity', $activity)
                ->whereRaw("attrs->'process_ids' @> ?::jsonb", [json_encode([$processId], JSON_THROW_ON_ERROR)])
                ->first();
            $this->assertNotNull($event, "{$activity} must retain governed {$processId} process lineage");
        }

        config(['services.arena.url' => 'http://arena-ancillary:8100']);
        Http::fake([
            'arena-ancillary:8100/discover' => Http::response(['object_types' => ['Laboratory Test', 'Imaging Study', 'AP Case'], 'nodes' => [], 'edges' => [], 'stats' => []]),
            'arena-ancillary:8100/performance' => Http::response(['lifecycles' => [['object_type' => 'Laboratory Test'], ['object_type' => 'AP Case']], 'synchronization' => []]),
            'arena-ancillary:8100/conformance' => Http::response([['pathway' => 'D1', 'cases' => 1, 'conformance_rate' => 1.0]]),
        ]);
        $arena = app(ArenaService::class);

        $this->assertTrue($arena->map(['Laboratory Test', 'Imaging Study', 'AP Case'], 1, 'ancillary-phase0', true)['available']);
        $this->assertTrue($arena->performance(['Laboratory Test', 'Imaging Study', 'AP Case'])['available']);
        $this->assertTrue($arena->conformance('D1')['available']);
        $this->assertTrue($arena->conformance('D5')['available']);
        $this->assertTrue($arena->conformance('D7')['available']);

        Http::assertSent(function ($request): bool {
            if (! in_array($request->url(), [
                'http://arena-ancillary:8100/discover',
                'http://arena-ancillary:8100/performance',
                'http://arena-ancillary:8100/conformance',
            ], true)) {
                return false;
            }

            $document = $request['ocel'];
            $activities = array_column($document['events'], 'type');
            $types = array_column($document['objects'], 'type');

            return in_array('test-ordered', $activities, true)
                && in_array('imaging-ordered', $activities, true)
                && in_array('specimen-received', $activities, true)
                && in_array('Laboratory Test', $types, true)
                && in_array('Imaging Study', $types, true)
                && in_array('AP Case', $types, true);
        });
        Http::assertSentCount(5);
    }

    public function test_d11_and_d12_pharmacy_lineage_is_projected_and_consumable_by_arena(): void
    {
        // X-14 gate: the medication order-to-administration (D11) and discharge
        // medication readiness (D12) processes must carry real object/event
        // lineage that the existing Arena consumers can read — not merely a label
        // in process_models. Mirrors the D1/D5/D7 consumer proof above.
        $pharmacy = AncillaryOrder::factory()->pharmacy()->create([
            'encounter_ref' => 'SENSITIVE-ENCOUNTER-RX',
            'ordered_at' => $this->anchor,
        ]);
        $this->milestones($pharmacy, [
            'RX_ORDERED', 'RX_VERIFIED', 'RX_PREP_COMPLETE', 'RX_DISPENSED', 'RX_DELIVERED', 'RX_ADMINISTERED',
        ]);
        app(OcelProjector::class)->projectAncillary($this->anchor->subMinute(), $this->anchor->addHour());

        // The medication process families are attached to the emitted events.
        // D11 (order-to-administration) spans the full chain; D12 (discharge
        // medication readiness) covers the readiness slice through delivery.
        $lineage = [
            ['medication-ordered', 'D11'], ['order-verified', 'D11'], ['dose-prepared', 'D11'],
            ['dose-dispensed', 'D11'], ['dose-delivered', 'D11'], ['dose-administered', 'D11'],
            ['medication-ordered', 'D12'], ['order-verified', 'D12'], ['dose-prepared', 'D12'],
            ['dose-delivered', 'D12'],
        ];
        foreach ($lineage as [$activity, $processId]) {
            $event = DB::table('ocel.events')
                ->where('source_system', 'prod.ancillary_milestones')
                ->where('activity', $activity)
                ->whereRaw("attrs->'process_ids' @> ?::jsonb", [json_encode([$processId], JSON_THROW_ON_ERROR)])
                ->first();
            $this->assertNotNull($event, "{$activity} must retain governed {$processId} process lineage");
        }

        // The pharmacy object/event relations exist for Arena to walk.
        $medicationOrderRows = app(ArenaQueryCatalog::class)
            ->run('activities_for_object_type', ['object_type' => 'Medication Order'])['rows'];
        $this->assertContains('medication-ordered', array_column($medicationOrderRows, 'activity'));
        $this->assertContains('order-verified', array_column($medicationOrderRows, 'activity'));
        $this->assertContains('dose-administered', array_column($medicationOrderRows, 'activity'));

        // The existing conformance consumer accepts D11 and D12 against this OCEL.
        config(['services.arena.url' => 'http://arena-rx:8100']);
        Http::fake([
            'arena-rx:8100/conformance' => Http::response([['pathway' => 'D11', 'cases' => 1, 'conformance_rate' => 1.0]]),
        ]);
        $arena = app(ArenaService::class);
        $this->assertTrue($arena->conformance('D11')['available']);
        $this->assertTrue($arena->conformance('D12')['available']);

        // No sensitive encounter reference leaked into the projected document.
        $serialized = json_encode([
            'events' => DB::table('ocel.events')->where('source_system', 'prod.ancillary_milestones')->get(),
            'objects' => DB::table('ocel.objects')->get(),
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('SENSITIVE-ENCOUNTER', $serialized);
    }

    public function test_process_landscape_readiness_advances_only_to_honest_partial_projection(): void
    {
        foreach (['D1', 'D5', 'D6', 'D7', 'D11', 'D12'] as $processId) {
            $model = HospitalProcessCatalog::find($processId);
            $this->assertSame('partial_projection', $model['current_readiness'], $processId);
            $this->assertStringContainsString('shared', strtolower($model['readiness_note']), $processId);
            $this->assertMatchesRegularExpression('/await|remain|incomplete|outside/', $model['readiness_note'], $processId);
        }
    }

    /** @param list<string> $codes */
    private function milestones(AncillaryOrder $order, array $codes): void
    {
        foreach (array_values($codes) as $index => $code) {
            AncillaryMilestone::factory()->create([
                'milestone_uuid' => (string) Str::uuid(),
                'ancillary_order_id' => $order->ancillary_order_id,
                'milestone_code' => $code,
                'occurred_at' => $this->anchor->addMinutes($index * 5),
                'received_at' => $this->anchor->addMinutes($index * 5)->addSecond(),
                'source_id' => $order->source_id,
                'assertion_key' => hash('sha256', $order->order_uuid.'|'.$code.'|'.$index),
                'source_rank' => 10,
                'metadata' => [],
            ]);
        }
    }
}
