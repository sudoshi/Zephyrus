<?php

namespace Tests\Feature\Ancillary;

use App\Models\Lab\LabTestCatalog;
use App\Models\Lab\Result;
use App\Models\Lab\Specimen;
use App\Models\User;
use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use App\Services\Lab\LabSpecimenService;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Database\Seeders\CaseManagementSeeder;
use Database\Seeders\CommandCenterDemoSeeder;
use Database\Seeders\RtdcSeeder;
use Database\Seeders\StaffingReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LabSpecimenServiceTest extends TestCase
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

    public function test_tracker_represents_every_current_specimen_timeline_result_chain_and_impact_once(): void
    {
        $payload = app(LabSpecimenService::class)->build(['perPage' => 50]);
        $rows = collect($payload['data']);

        $this->assertSame('normal', $payload['state']);
        $this->assertSame('available', $payload['coverage']['transport']['status']);
        $this->assertTrue($payload['coverage']['transport']['columnVisible']);
        $this->assertCount(15, $rows);
        $this->assertCount(4, $rows->where('chain.length', 2));
        $this->assertCount(3, $rows->whereNotNull('downstreamImpact'));
        $this->assertCount(3, $rows->whereNotNull('decisionRepresentedBySpecimenUuid')->whereNotNull('downstreamImpact'));
        $this->assertTrue($rows->where('chain.length', 2)->every(fn (array $row): bool => $row['chain']['position'] >= 1 && $row['chain']['position'] <= 2));
        $this->assertTrue($rows->every(fn (array $row): bool => $row['accessionIdentity']['sourceSpecimenKey'] !== ''));
        $this->assertTrue($rows->filter(fn (array $row): bool => $row['result'] !== null)->every(fn (array $row): bool => array_keys($row['result']) === [
            'resultUuid', 'testLabel', 'status', 'stage', 'abnormalFlag', 'autoVerified', 'critical', 'resultedAt', 'verifiedAt', 'correctedAt', 'versionCount',
        ]));

        $multiSpecimenOrders = $rows->groupBy('orderUuid')->filter(fn ($group): bool => $group->count() > 1);
        $this->assertCount(2, $multiSpecimenOrders);
        $this->assertTrue($multiSpecimenOrders->every(fn ($group): bool => $group->count() === 2));
        $this->assertFalse($payload['privacy']['directPatientIdentifiersIncluded']);
        $this->assertFalse($payload['privacy']['resultContentIncluded']);
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('patient_name', $json);
        $this->assertStringNotContainsString('"value"', $json);
        $this->assertStringNotContainsString('conclusion', $json);
    }

    public function test_long_recollect_chain_reconciles_and_moves_one_pending_decision_to_active_leaf(): void
    {
        $root = Specimen::query()->where('demo_owner', AncillaryDemoScenarioService::OWNER)->whereNull('parent_specimen_id')->where('status', 'collection_pending')->firstOrFail();
        $root->update([
            'status' => 'recollect_requested', 'collected_at' => $this->anchor->subMinutes(40),
            'rejected_at' => $this->anchor->subMinutes(30), 'rejection_reason_code' => 'HEMOLYZED',
            'recollect_ordered_at' => $this->anchor->subMinutes(25),
        ]);
        $child = Specimen::factory()->recollectOf($root)->create([
            'ancillary_order_id' => $root->ancillary_order_id, 'source_id' => $root->source_id, 'encounter_id' => $root->encounter_id,
            'source_specimen_key' => 'long-chain-child', 'demo_owner' => AncillaryDemoScenarioService::OWNER,
            'status' => 'recollect_requested', 'collected_at' => $this->anchor->subMinutes(20),
            'rejected_at' => $this->anchor->subMinutes(15), 'rejection_reason_code' => 'CLOTTED',
            'recollect_ordered_at' => $this->anchor->subMinutes(10),
        ]);
        $leaf = Specimen::factory()->recollectOf($child)->create([
            'ancillary_order_id' => $root->ancillary_order_id, 'source_id' => $root->source_id, 'encounter_id' => $root->encounter_id,
            'source_specimen_key' => 'long-chain-leaf', 'demo_owner' => AncillaryDemoScenarioService::OWNER,
        ]);
        $catalog = LabTestCatalog::query()->where('catalog_key', 'lab.troponin_i')->firstOrFail();
        Result::factory()->preliminary()->create([
            'lab_specimen_id' => $root->lab_specimen_id, 'ancillary_order_id' => $root->ancillary_order_id,
            'source_id' => $root->source_id, 'lab_test_catalog_id' => $catalog->lab_test_catalog_id,
            'local_code' => $catalog->local_code, 'loinc_code' => $catalog->loinc_code,
            'source_result_key' => 'long-chain-decision', 'result_uuid' => (string) Str::uuid(),
            'metadata' => ['decision_context' => ['decision_class' => 'ed_disposition', 'blocked_object_type' => 'ed_visit', 'blocked_object_id' => 1, 'explanation' => 'Disposition waits for the viable recollect result.']],
        ]);

        $rows = collect(app(LabSpecimenService::class)->build(['rejection' => 'recollect', 'perPage' => 50])['data'])
            ->where('chain.rootSpecimenUuid', $root->specimen_uuid)->values();
        $this->assertCount(3, $rows);
        $this->assertSame([1, 2, 3], $rows->sortBy('chain.position')->pluck('chain.position')->all());
        $this->assertSame([0, 1, 2], $rows->sortBy('chain.position')->pluck('chain.depth')->all());
        $this->assertCount(1, $rows->whereNotNull('downstreamImpact'));
        $this->assertSame($leaf->specimen_uuid, $rows->firstWhere('downstreamImpact.explanation', 'Disposition waits for the viable recollect result.')['specimenUuid']);
        $this->assertTrue($rows->every(fn (array $row): bool => $row['decisionRepresentedBySpecimenUuid'] === $leaf->specimen_uuid));
    }

    public function test_filters_cursor_pagination_constant_query_shape_and_index_plan_are_proven(): void
    {
        $service = app(LabSpecimenService::class);
        $rejected = $service->build(['rejection' => 'rejected', 'perPage' => 50]);
        $this->assertCount(2, $rejected['data']);
        $this->assertTrue(collect($rejected['data'])->every(fn (array $row): bool => in_array($row['status'], ['rejected', 'recollect_requested'], true)));
        $unitId = $service->build()['filterOptions']['units'][0]['unitId'];
        $this->assertTrue(
            $service->build(['status' => 'in_transit'])['data'] !== []
            && $service->build(['unitId' => $unitId])['data'] !== []
        );
        $this->assertNotEmpty($service->build(['testFamily' => 'troponin', 'priority' => 'stat'])['data']);
        $this->assertNotEmpty($service->build(['age' => '120_plus'])['data']);

        $first = $service->build(['perPage' => 4]);
        $second = $service->build(['perPage' => 4, 'cursor' => $first['meta']['nextCursor']]);
        $this->assertTrue($first['meta']['hasMore']);
        $this->assertSame([], array_values(array_intersect(array_column($first['data'], 'specimenUuid'), array_column($second['data'], 'specimenUuid'))));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->build(['perPage' => 5]);
        $five = count(DB::getQueryLog());
        DB::flushQueryLog();
        $service->build(['perPage' => 15]);
        $fifteen = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($five, $fifteen);
        $this->assertLessThanOrEqual(30, $fifteen);

        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = collect(DB::select("EXPLAIN SELECT lab_specimen_id FROM prod.lab_specimens WHERE status = 'in_transit' ORDER BY collected_at, lab_specimen_id LIMIT 25"))->pluck('QUERY PLAN')->implode("\n");
        $this->assertMatchesRegularExpression('/Index (Only )?Scan.*lab_specimens_[a-z_]+_idx/s', $plan);
    }

    public function test_missing_transport_hides_column_and_routes_share_redacted_validated_contract(): void
    {
        DB::table('prod.lab_specimens')->where('status', 'in_transit')->update(['status' => 'collected']);
        DB::table('prod.lab_specimens')->update(['in_transit_at' => null]);
        $degraded = app(LabSpecimenService::class)->build(['perPage' => 50]);
        $this->assertSame('degraded', $degraded['state']);
        $this->assertFalse($degraded['coverage']['transport']['columnVisible']);
        $this->assertStringContainsString('does not infer a zero-minute segment', $degraded['coverage']['transport']['explanation']);
        $this->assertTrue(collect($degraded['data'])->every(fn (array $row): bool => ! in_array('in_transit', array_column($row['timeline'], 'code'), true)));

        $manager = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
        $expected = app(LabSpecimenService::class)->build(['status' => 'collection_pending', 'perPage' => 5]);
        $query = 'status=collection_pending&perPage=5';
        $this->actingAs($manager)->get('/lab/specimens?'.$query)->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Lab/Specimens')->where('specimens', $expected),
        );
        $this->actingAs($manager)->getJson('/api/lab/specimens?'.$query)->assertOk()->assertExactJson($expected);

        $frontline = User::factory()->create(['role' => 'user', 'must_change_password' => false]);
        $redacted = $this->actingAs($frontline)->getJson('/api/lab/specimens?perPage=50')->assertOk()->assertJsonPath('privacy.patientContextIncluded', false)->json();
        $this->assertTrue(collect($redacted['data'])->every(fn (array $row): bool => $row['patientRef'] === 'Patient context restricted'));
        $this->actingAs($manager)->getJson('/api/lab/specimens?status=lost')->assertUnprocessable();
        $this->actingAs($manager)->getJson('/api/lab/specimens?cursor=not-a-cursor')->assertUnprocessable();
        $validUnitId = (int) DB::table('prod.units')->where('is_deleted', false)->value('unit_id');
        $this->actingAs($manager)->getJson('/api/lab/specimens?unitId='.$validUnitId)->assertOk()
            ->assertJsonPath('filters.unitId', $validUnitId);
        auth()->logout();
        $this->getJson('/api/lab/specimens')->assertUnauthorized();
    }
}
