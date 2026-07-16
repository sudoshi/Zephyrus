<?php

namespace Tests\Feature\Ancillary;

use App\Models\Lab\Result;
use App\Models\User;
use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use App\Services\Lab\LabDecisionPendingService;
use App\Services\Lab\LabSpecimenService;
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

final class LabDecisionPendingServiceTest extends TestCase
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

    public function test_three_live_decision_classes_rank_deterministically_and_reconcile_to_destination_aggregates(): void
    {
        $payload = app(LabDecisionPendingService::class)->build([], true, true);
        $items = collect($payload['data']);

        $this->assertSame('normal', $payload['state']);
        $this->assertSame(3, $payload['summary']['visible']);
        $this->assertSame(1, $payload['summary']['orGates']);
        $this->assertSame(1, $payload['summary']['dischargeGates']);
        $this->assertSame(1, $payload['summary']['edDispositions']);
        $this->assertSame(0, $payload['summary']['unresolvedDestinations']);
        $this->assertSame(['or_gate', 'discharge_gate', 'ed_disposition'], $items->pluck('decisionClass')->all());
        $this->assertSame([1, 2, 3], $items->pluck('ranking.position')->all());
        $this->assertTrue($items->every(fn (array $item): bool => $item['gateEvidence']['validated'] && $item['destination']['active']));
        $this->assertTrue($items->every(fn (array $item): bool => count($item['ranking']['reasons']) === 3));

        $or = $items->firstWhere('decisionClass', 'or_gate');
        $discharge = $items->firstWhere('decisionClass', 'discharge_gate');
        $ed = $items->firstWhere('decisionClass', 'ed_disposition');
        $this->assertSame('lab.stat_tat', $or['sla']['definition']['metricKey']);
        $this->assertSame('warning', $or['sla']['urgency']);
        $this->assertNull($discharge['sla']['definition']);
        $this->assertSame('unconfigured', $discharge['sla']['urgency']);
        $this->assertSame(1, $discharge['destination']['bedImpact']);
        $this->assertSame('lab.troponin_order_result', $ed['sla']['definition']['metricKey']);
        $this->assertSame('breach', $ed['sla']['urgency']);
        $this->assertSame(1, $payload['summary']['breached']);

        $aggregates = collect($payload['destinationAggregates']);
        $this->assertCount(3, $aggregates);
        foreach ($items as $item) {
            $destination = $aggregates->first(fn (array $aggregate): bool => $aggregate['decisionClass'] === $item['decisionClass'] && $aggregate['destinationId'] === $item['destination']['id']);
            $this->assertNotNull($destination);
            $this->assertContains($item['resultUuid'], $destination['resultUuids']);
            $this->assertSame($item['orderUuid'], $destination['topOrderUuid']);
        }

        $this->assertFalse($payload['privacy']['directPatientIdentifiersIncluded']);
        $this->assertFalse($payload['privacy']['resultContentIncluded']);
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('patient_name', $json);
        $this->assertStringNotContainsString('result_value', $json);
        $this->assertStringNotContainsString('narrative', $json);
    }

    public function test_catalog_no_gate_completed_results_filters_and_exact_specimen_drill_are_enforced(): void
    {
        $service = app(LabDecisionPendingService::class);
        $payload = $service->build();
        $this->assertGreaterThan(0, $payload['exclusions']['noGateCatalog']);
        $this->assertGreaterThanOrEqual(2, $payload['exclusions']['completedOrCancelled']);

        $this->assertSame(['or_gate'], array_column($service->build(['decisionClass' => 'or_gate'])['data'], 'decisionClass'));
        $this->assertSame(['ed_disposition'], array_column($service->build(['priority' => 'stat', 'urgency' => 'breach'])['data'], 'decisionClass'));
        $unitId = (int) DB::table('prod.ancillary_orders')->where('order_uuid', $payload['data'][0]['orderUuid'])->value('unit_id');
        $this->assertNotEmpty($service->build(['unitId' => $unitId])['data']);
        $this->assertCount(1, $service->build(['limit' => 1])['data']);

        $first = $payload['data'][0];
        $specimens = app(LabSpecimenService::class)->build(['orderUuid' => $first['orderUuid'], 'perPage' => 50]);
        $this->assertNotEmpty($specimens['data']);
        $this->assertTrue(collect($specimens['data'])->every(fn (array $row): bool => $row['orderUuid'] === $first['orderUuid']));
        $this->assertStringContainsString('orderUuid='.$first['orderUuid'], $first['drill']['specimenHref']);
        $exact = $service->build(['orderUuid' => $first['orderUuid']]);
        $this->assertSame([$first['orderUuid']], array_column($exact['data'], 'orderUuid'));
        $this->assertSame($first['orderUuid'], $exact['filters']['orderUuid']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->build(['limit' => 1]);
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();
        $service->build(['limit' => 50]);
        $fifty = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($one, $fifty);
        $this->assertLessThanOrEqual(20, $fifty);

        $result = Result::query()->where('result_uuid', $first['resultUuid'])->firstOrFail();
        $result->update(['verified_at' => $this->anchor]);
        $after = $service->build();
        $this->assertCount(2, $after['data']);
        $this->assertNotContains($first['resultUuid'], array_column($after['data'], 'resultUuid'));
    }

    public function test_stale_source_unresolved_destination_and_test_name_non_inference_are_explicit(): void
    {
        $service = app(LabDecisionPendingService::class);
        $baseline = $service->build();
        $pendingOrderUuids = array_column($baseline['data'], 'orderUuid');

        $nonGate = DB::table('prod.ancillary_orders')->where('department', 'lab')
            ->whereRaw("metadata->>'test_code' = 'CBC'")->whereNotIn('order_uuid', $pendingOrderUuids)->first();
        $metadata = json_decode($nonGate->metadata, true);
        $metadata['decision_class'] = 'or_gate';
        $metadata['test_label'] = 'Troponin result that must not infer a gate';
        $metadata['or_case_id'] = DB::table('prod.or_cases')->value('case_id');
        DB::table('prod.ancillary_orders')->where('ancillary_order_id', $nonGate->ancillary_order_id)->update(['metadata' => json_encode($metadata)]);
        $this->assertNotContains($nonGate->order_uuid, array_column($service->build()['data'], 'orderUuid'));

        $ed = collect($baseline['data'])->firstWhere('decisionClass', 'ed_disposition');
        $result = Result::query()->where('result_uuid', $ed['resultUuid'])->firstOrFail();
        $resultMetadata = $result->metadata;
        $resultMetadata['decision_context']['blocked_object_id'] = 999999999;
        $result->update(['metadata' => $resultMetadata]);
        $degraded = $service->build();
        $this->assertSame('degraded', $degraded['state']);
        $this->assertSame(1, $degraded['summary']['unresolvedDestinations']);
        $this->assertCount(2, $degraded['data']);
        $this->assertSame($ed['orderUuid'], $degraded['exclusions']['unresolved'][0]['orderUuid']);
        $this->assertSame(999999999, $degraded['exclusions']['unresolved'][0]['destinationId']);

        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'stale']);
        $stale = $service->build();
        $this->assertSame('stale', $stale['state']);
        $this->assertSame('stale', $stale['freshness']['status']);
        $this->assertTrue(collect($stale['data'])->every(fn (array $item): bool => $item['sla']['urgency'] === 'stale'));
        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'error']);
        $this->assertSame('source_error', $service->build()['state']);
    }

    public function test_routes_share_validated_redacted_contract_and_expose_no_result_mutation(): void
    {
        $manager = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
        $query = 'decisionClass=discharge_gate&limit=10';
        $expected = app(LabDecisionPendingService::class)->build(['decisionClass' => 'discharge_gate', 'limit' => 10], true, true);
        $this->actingAs($manager)->get('/lab/pending-decisions?'.$query)->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Lab/PendingDecisions')->where('pendingDecisions', $expected),
        );
        $this->actingAs($manager)->getJson('/api/lab/pending-decisions?'.$query)->assertOk()
            ->assertHeader('Cache-Control', 'no-cache, private')->assertExactJson($expected);

        $frontline = User::factory()->create(['role' => 'user', 'must_change_password' => false]);
        $redacted = $this->actingAs($frontline)->getJson('/api/lab/pending-decisions')->assertOk()
            ->assertJsonPath('privacy.patientContextIncluded', false)->assertJsonPath('canAnnotateBarriers', false)->json();
        $this->assertTrue(collect($redacted['data'])->every(fn (array $item): bool => $item['patientRef'] === 'Patient context restricted'));
        $this->actingAs($manager)->getJson('/api/lab/pending-decisions?decisionClass=bad')->assertUnprocessable();
        $this->actingAs($manager)->getJson('/api/lab/pending-decisions?urgency=urgent')->assertUnprocessable();
        $this->actingAs($manager)->getJson('/api/lab/pending-decisions?orderUuid=not-a-uuid')->assertUnprocessable();
        $this->actingAs($manager)->getJson('/api/lab/pending-decisions?source=untrusted')->assertUnprocessable();
        $validUnitId = (int) DB::table('prod.units')->where('is_deleted', false)->value('unit_id');
        $this->actingAs($manager)->getJson('/api/lab/pending-decisions?unitId='.$validUnitId.'&source=ancillary_services')->assertOk()
            ->assertJsonPath('filters.unitId', $validUnitId)->assertJsonPath('filters.source', 'ancillary_services');
        $this->actingAs($manager)->postJson('/api/lab/pending-decisions', [])->assertMethodNotAllowed();
        auth()->logout();
        $this->getJson('/api/lab/pending-decisions')->assertUnauthorized();

        $this->assertSame('lab/pending-decisions', Route::getRoutes()->getByName('lab.pending-decisions')?->uri());
        $this->assertSame('api/lab/pending-decisions', Route::getRoutes()->getByName('api.lab.pending-decisions')?->uri());
    }
}
