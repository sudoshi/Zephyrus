<?php

namespace Tests\Feature\Ancillary;

use App\Models\Ancillary\AncillaryOrder;
use App\Models\User;
use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use App\Services\Radiology\RadiologyWorklistService;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Database\Seeders\CaseManagementSeeder;
use Database\Seeders\CommandCenterDemoSeeder;
use Database\Seeders\RtdcSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RadiologyWorklistTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $anchor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anchor = CarbonImmutable::parse('2026-07-11T14:00:00Z');
        CarbonImmutable::setTestNow($this->anchor);
        $this->seed([RtdcSeeder::class, CaseManagementSeeder::class, CommandCenterDemoSeeder::class, AncillaryReferenceSeeder::class]);
        app(AncillaryDemoScenarioService::class)->refresh(new DemoClock($this->anchor));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_filters_search_sorts_and_cursor_pages_are_deterministic(): void
    {
        $service = app(RadiologyWorklistService::class);
        $discharge = $service->build(['lens' => 'discharge', 'source' => 'rtdc']);
        $this->assertCount(1, $discharge['data']);
        $this->assertSame('CT', $discharge['data'][0]['modality']);
        $this->assertTrue($discharge['data'][0]['downstreamImpact']['dischargeBlocking']);
        $this->assertSame('rtdc', $discharge['filters']['source']);

        $first = $service->build(['sort' => 'priority', 'perPage' => 3]);
        $this->assertCount(3, $first['data']);
        $this->assertTrue($first['meta']['hasMore']);
        $this->assertNotNull($first['meta']['nextCursor']);
        $second = $service->build(['sort' => 'priority', 'perPage' => 3, 'cursor' => $first['meta']['nextCursor']]);
        $this->assertSame([], array_values(array_intersect(
            array_column($first['data'], 'orderUuid'),
            array_column($second['data'], 'orderUuid'),
        )));

        $key = AncillaryOrder::query()->where('department', 'rad')->whereNull('terminal_at')->orderBy('ancillary_order_id')->value('source_order_key');
        $searched = $service->build(['search' => substr($key, 0, 18)]);
        $this->assertNotEmpty($searched['data']);
        $this->assertFalse($searched['predictiveSort']['enabled']);
        $this->assertTrue($searched['predictiveSort']['available']);
    }

    public function test_full_degraded_conflict_and_transport_timelines_are_honest(): void
    {
        $service = app(RadiologyWorklistService::class);
        $all = collect($service->build(['perPage' => 50])['data']);

        $discharge = $all->firstWhere('label', 'Discharge-pending chest CT');
        $this->assertNotNull($discharge['transportSegment']);
        $this->assertSame(['RAD_TRANSPORT_REQUESTED', 'RAD_TRANSPORT_COMPLETE'], array_column($discharge['transportSegment'], 'code'));
        $portable = $all->first(fn (array $row): bool => $row['label'] === 'Portable chest');
        $this->assertNull($portable['transportSegment']);
        $this->assertNotContains('RAD_TRANSPORT_REQUESTED', array_column($portable['timeline']['milestones'], 'code'));

        $degraded = $all->firstWhere('label', 'HIDA scan');
        $this->assertTrue($degraded['timeline']['degradedMode']);
        $this->assertSame('degraded', $degraded['status']);
        $this->assertContains('pending_required', array_column($degraded['timeline']['milestones'], 'state'));

        $conflict = $all->firstWhere('label', 'CT abdomen with contrast');
        $examEnd = collect($conflict['timeline']['milestones'])->firstWhere('code', 'RAD_EXAM_END');
        $this->assertTrue($examEnd['conflict']);
        $this->assertGreaterThan(1, $examEnd['assertionCount']);
        $this->assertGreaterThan(1, collect($conflict['sourceAssertions'])->where('code', 'RAD_EXAM_END')->count());
        $this->assertCount(1, collect($conflict['sourceAssertions'])->where('code', 'RAD_EXAM_END')->where('selected', true));
    }

    public function test_inertia_and_api_share_contract_and_deep_links_are_allowlisted(): void
    {
        $user = User::factory()->create(['role' => 'radiology_manager', 'must_change_password' => false]);
        $filters = ['lens' => 'ed', 'modality' => 'CT', 'source' => 'cockpit', 'sort' => 'oldest', 'perPage' => 5];
        $expected = app(RadiologyWorklistService::class)->build($filters);
        $query = http_build_query($filters);

        $this->actingAs($user)->get('/radiology/worklist?'.$query)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Radiology/Worklist')->where('worklist', $expected));
        $this->actingAs($user)->getJson('/api/radiology/worklist?'.$query)->assertOk()->assertExactJson($expected);

        $this->actingAs($user)->getJson('/api/radiology/worklist?source=unknown')->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/radiology/worklist?search=%25drop')->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/radiology/worklist?cursor=not-a-cursor')->assertUnprocessable();
    }

    public function test_large_fixture_query_count_is_constant_and_open_index_is_usable(): void
    {
        $sourceId = DB::table('integration.sources')->where('source_key', 'demo.ancillary.rad.primary')->value('source_id');
        AncillaryOrder::factory()->count(150)->radiology()->create(['source_id' => $sourceId]);
        $service = app(RadiologyWorklistService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->build(['perPage' => 10]);
        $tenQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $service->build(['perPage' => 50]);
        $fiftyQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($tenQueries, $fiftyQueries);
        $this->assertLessThanOrEqual(30, $fiftyQueries);

        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = collect(DB::select("EXPLAIN SELECT ancillary_order_id FROM prod.ancillary_orders WHERE department = 'rad' AND terminal_at IS NULL ORDER BY priority, current_milestone_at, ancillary_order_id LIMIT 25"))
            ->pluck('QUERY PLAN')->implode("\n");
        $this->assertMatchesRegularExpression('/Index (Only )?Scan.*ancillary_orders_(open|live_worklist|unit_worklist|department_ordered)_idx/s', $plan);
    }
}
