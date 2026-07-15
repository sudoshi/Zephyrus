<?php

namespace Tests\Feature\Ancillary;

use App\Models\User;
use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use App\Services\Lab\AnatomicPathologyService;
use App\Services\Operations\CaseManagementService;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Database\Seeders\CaseManagementSeeder;
use Database\Seeders\CommandCenterDemoSeeder;
use Database\Seeders\RtdcSeeder;
use Database\Seeders\StaffingReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AnatomicPathologyServiceTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $anchor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anchor = CarbonImmutable::parse('2026-07-11T14:00:00Z');
        CarbonImmutable::setTestNow($this->anchor);
        $this->seed([RtdcSeeder::class, CaseManagementSeeder::class, StaffingReferenceSeeder::class, CommandCenterDemoSeeder::class, AncillaryReferenceSeeder::class]);
        app(AncillaryDemoScenarioService::class)->refresh(new DemoClock($this->anchor));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_stage_aging_cohorts_structural_batches_benchmarks_and_perioperative_timer_reconcile(): void
    {
        $payload = app(AnatomicPathologyService::class)->build();
        $items = collect($payload['data']);

        $this->assertSame('normal', $payload['state']);
        $this->assertCount(6, $items);
        $this->assertSame(['complex' => 1, 'consult_send_out' => 1, 'frozen_section' => 2, 'routine' => 2], $items->countBy('cohort')->sortKeys()->all());
        $this->assertSame(['diagnosed' => 2, 'processing' => 1, 'received' => 1, 'signed_out' => 1, 'slides_ready' => 1], $items->countBy('stage')->sortKeys()->all());
        $this->assertSame(1, $payload['summary']['activeFrozen']);
        $nonFrozen = $items->where('cohort', '<>', 'frozen_section');
        $this->assertCount(4, $nonFrozen);
        $this->assertSame(3, $nonFrozen->where('structuralStage.kind', 'overnight_batch')->count());
        $this->assertTrue($nonFrozen->where('structuralStage.kind', 'overnight_batch')->every(fn (array $item): bool => str_contains($item['structuralStage']['explanation'], 'structural workflow stage')));
        $this->assertSame('send_out', $nonFrozen->firstWhere('cohort', 'consult_send_out')['structuralStage']['kind']);
        $this->assertTrue($items->every(fn (array $item): bool => count($item['timeline']) === 6));
        $this->assertSame(['complex', 'frozen_single_block', 'routine'], collect($payload['benchmarkLines'])->pluck('key')->sort()->values()->all());
        $this->assertTrue(collect($payload['benchmarkLines'])->every(fn (array $line): bool => str_contains($line['evidenceLabel'], 'Established CAP guidance') && str_contains($line['evidenceLabel'], 'not universal or local policy')));
        $this->assertSame('not_configured', $payload['coverage']['backfill']['status']);

        $activeFrozen = $items->firstWhere('frozen.status', 'in_progress');
        $resultedFrozen = $items->firstWhere('frozen.status', 'resulted');
        $this->assertTrue($activeFrozen['frozen']['timerActive']);
        $this->assertNotNull($activeFrozen['frozen']['timer']);
        $this->assertFalse($resultedFrozen['frozen']['timerActive']);
        $this->assertNull($resultedFrozen['frozen']['timer']);

        $procedures = collect(app(CaseManagementService::class)->getData()['mockProcedures']);
        $activeProcedure = $procedures->firstWhere('id', $activeFrozen['caseId']);
        $resultedProcedure = $procedures->firstWhere('id', $resultedFrozen['caseId']);
        $this->assertSame('Procedure', $activeProcedure['phase']);
        $this->assertSame($activeFrozen['frozen']['timer'], $activeProcedure['frozenSectionTimer']);
        $this->assertNull($resultedProcedure['frozenSectionTimer']);

        DB::table('prod.ap_cases')->where('ap_case_uuid', $activeFrozen['apCaseUuid'])->update(['case_id' => null]);
        $unlinked = collect(app(AnatomicPathologyService::class)->build()['data'])->firstWhere('apCaseUuid', $activeFrozen['apCaseUuid']);
        $this->assertFalse($unlinked['frozen']['timerActive']);
        $this->assertNull($unlinked['frozen']['timer']);
        DB::table('prod.ap_cases')->where('ap_case_uuid', $activeFrozen['apCaseUuid'])->update([
            'case_id' => $activeFrozen['caseId'],
            'frozen_status' => 'resulted',
            'frozen_resulted_at' => $this->anchor,
        ]);
        $afterResult = collect(app(CaseManagementService::class)->getData()['mockProcedures'])->firstWhere('id', $activeFrozen['caseId']);
        $this->assertNull($afterResult['frozenSectionTimer']);
        $this->assertFalse($payload['privacy']['directPatientIdentifiersIncluded']);
        $this->assertFalse($payload['privacy']['diagnosisOrNarrativeIncluded']);
        $this->assertFalse($payload['privacy']['writebackIncluded']);
    }

    public function test_age_bucket_boundaries_filters_and_terminal_stage_are_demo_clock_deterministic(): void
    {
        $service = app(AnatomicPathologyService::class);
        $routine = collect($service->build()['data'])->firstWhere('cohort', 'routine');
        DB::table('prod.ap_cases')->where('ap_case_uuid', $routine['apCaseUuid'])->update([
            'stage' => 'processing',
            'current_stage_at' => $this->anchor->subMinutes(239),
            'processing_batch_at' => $this->anchor->subMinutes(239),
        ]);

        $before = $service->build(['caseId' => $routine['caseId']]);
        $this->assertSame(239, $before['data'][0]['stageAgeMinutes']);
        $this->assertSame('under_4h', $before['data'][0]['ageBand']);
        CarbonImmutable::setTestNow($this->anchor->addMinutes(2));
        $after = $service->build(['caseId' => $routine['caseId'], 'stage' => 'processing', 'cohort' => 'routine', 'status' => 'open', 'ageBand' => '4_to_8h']);
        $this->assertSame(241, $after['data'][0]['stageAgeMinutes']);
        $this->assertSame('4_to_8h', $after['data'][0]['ageBand']);

        $completed = $service->build(['status' => 'completed']);
        $this->assertSame(1, $completed['summary']['visible']);
        $this->assertSame('signed_out', $completed['data'][0]['stage']);
        $this->assertSame('complete', $completed['data'][0]['ageBand']);
        $this->assertNull($completed['data'][0]['stageAgeMinutes']);
        $this->assertSame('complete', collect($completed['data'][0]['timeline'])->firstWhere('stage', 'signed_out')['state']);
        $this->assertSame(1, $service->build(['cohort' => 'consult_send_out'])['summary']['visible']);
        $this->assertSame(2, $service->build(['cohort' => 'frozen_section'])['summary']['visible']);
        $this->assertSame(1, $service->build(['stage' => 'slides_ready'])['summary']['visible']);
    }

    public function test_freshness_ap_lis_backfill_query_shape_and_aging_index_are_explicit(): void
    {
        $service = app(AnatomicPathologyService::class);
        $sourceIds = DB::table('prod.ap_cases')->pluck('source_id')->unique();
        DB::table('integration.sources')->whereIn('source_id', $sourceIds)->update(['bulk_supported' => true]);
        $missing = $service->build();
        $this->assertSame('degraded', $missing['state']);
        $this->assertSame('missing', $missing['coverage']['backfill']['status']);

        foreach ($sourceIds as $sourceId) {
            DB::table('integration.connector_watermarks')->insert([
                'source_id' => $sourceId,
                'connector_key' => 'ap-history',
                'scope_type' => 'bulk_backfill',
                'scope_key' => 'ap-cases',
                'watermark_kind' => 'opaque_cursor',
                'watermark_value' => 'complete',
                'last_success_at' => $this->anchor->subMinutes(5),
                'metadata' => '{}',
                'created_at' => $this->anchor,
                'updated_at' => $this->anchor,
            ]);
        }
        $available = $service->build();
        $this->assertSame('normal', $available['state']);
        $this->assertSame('available', $available['coverage']['backfill']['status']);
        $this->assertNotNull($available['coverage']['backfill']['lastSuccessAt']);

        DB::table('integration.sources')->whereIn('source_id', $sourceIds)->update(['system_class' => 'generic_lis']);
        $wrongSource = $service->build();
        $this->assertSame('degraded', $wrongSource['state']);
        $this->assertSame('missing', $wrongSource['coverage']['apLis']['status']);
        DB::table('integration.sources')->whereIn('source_id', $sourceIds)->update(['system_class' => 'ap_lis']);

        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'stale']);
        $stale = $service->build();
        $this->assertSame('stale', $stale['state']);
        $this->assertTrue(collect($stale['data'])->every(fn (array $item): bool => $item['frozen']['timer'] === null));
        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'error']);
        $this->assertSame('source_error', $service->build()['state']);
        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'current']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->build(['limit' => 1]);
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();
        $service->build(['limit' => 50]);
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($one, $many);
        $this->assertLessThanOrEqual(5, $many);

        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = collect(DB::select("EXPLAIN SELECT ap_case_id FROM prod.ap_cases WHERE stage = 'processing' AND current_stage_at >= now() - interval '7 days' ORDER BY current_stage_at, ap_case_id"))->pluck('QUERY PLAN')->implode("\n");
        $this->assertStringContainsString('ap_cases_stage_aging_idx', $plan);
    }

    public function test_web_api_auth_validation_read_only_contract_and_named_routes_are_stable(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
        $caseId = app(AnatomicPathologyService::class)->build()['data'][0]['caseId'];
        $expected = app(AnatomicPathologyService::class)->build(['caseId' => $caseId]);
        $this->actingAs($admin)->get('/lab/anatomic-path?caseId='.$caseId)->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Lab/AnatomicPathology')->where('pathology', $expected),
        );
        $this->actingAs($admin)->getJson('/api/lab/anatomic-path?caseId='.$caseId)->assertOk()
            ->assertHeader('Cache-Control', 'no-cache, private')->assertExactJson($expected);
        $this->actingAs($admin)->getJson('/api/lab/anatomic-path?cohort=molecular')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/api/lab/anatomic-path?ageBand=overdue')->assertUnprocessable();
        $this->actingAs($admin)->postJson('/api/lab/anatomic-path', ['caseId' => $caseId])->assertMethodNotAllowed();
        auth()->logout();
        $this->getJson('/api/lab/anatomic-path')->assertUnauthorized();

        $this->assertSame('lab/anatomic-path', Route::getRoutes()->getByName('lab.anatomic-path')?->uri());
        $this->assertSame('api/lab/anatomic-path', Route::getRoutes()->getByName('api.lab.anatomic-path')?->uri());
    }
}
