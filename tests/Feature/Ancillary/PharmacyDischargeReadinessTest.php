<?php

declare(strict_types=1);

namespace Tests\Feature\Ancillary;

use App\Services\Ancillary\AncillaryReadinessService;
use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use App\Services\Pharmacy\PharmacyDischargeReadinessService;
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

final class PharmacyDischargeReadinessTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $anchor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anchor = CarbonImmutable::parse('2026-07-11T14:00:00Z');
        CarbonImmutable::setTestNow($this->anchor);
        $this->seed([
            RtdcSeeder::class,
            CaseManagementSeeder::class,
            StaffingReferenceSeeder::class,
            CommandCenterDemoSeeder::class,
            AncillaryReferenceSeeder::class,
        ]);
        app(AncillaryDemoScenarioService::class)->refresh(new DemoClock($this->anchor));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_build_reports_the_full_discharge_pipeline_with_target_compliance(): void
    {
        $payload = app(PharmacyDischargeReadinessService::class)->build();

        $this->assertSame('normal', $payload['state']);
        $this->assertFalse($payload['degradedMode']);
        $this->assertNotEmpty($payload['data']['items']);

        // Every governed pipeline stage is present and stage counts sum to the queue rows.
        $stageStatuses = array_column($payload['data']['pipeline'], 'status');
        $this->assertSame(PharmacyDischargeReadinessService::PIPELINE, $stageStatuses);
        $this->assertSame(
            (int) $payload['data']['summary']['queueRows'],
            array_sum(array_column($payload['data']['pipeline'], 'count')),
        );

        // Ready-by-target compliance is a bounded percentage when the source is fresh.
        $this->assertIsInt($payload['data']['summary']['readyByTargetPercent']);
        $this->assertGreaterThanOrEqual(0, $payload['data']['summary']['readyByTargetPercent']);
        $this->assertLessThanOrEqual(100, $payload['data']['summary']['readyByTargetPercent']);

        // A prior-authorization pending row is a governed workflow state, not display text.
        $priorAuth = collect($payload['data']['items'])->firstWhere('pipelineStatus', 'prior_auth_pending');
        $this->assertNotNull($priorAuth);
        $this->assertTrue($priorAuth['priorAuthPending']);
        $this->assertTrue($priorAuth['blocking']);
        $this->assertContains($priorAuth['targetState'], ['on_track', 'overdue']);
    }

    public function test_medication_axis_reconciles_with_the_department_service(): void
    {
        $service = app(PharmacyDischargeReadinessService::class);
        $readiness = app(AncillaryReadinessService::class);

        $blockingEncounterId = (int) DB::table('prod.rx_discharge_queue')
            ->whereIn('pipeline_status', PharmacyDischargeReadinessService::BLOCKING)
            ->whereNotNull('encounter_id')
            ->value('encounter_id');
        $this->assertGreaterThan(0, $blockingEncounterId, 'The demo cohort must contain a blocking discharge medication row.');

        $axis = $readiness->medicationForEncounters([$blockingEncounterId], 'rtdc')->get($blockingEncounterId);
        $this->assertNotNull($axis);
        $this->assertSame('medication', $axis['key']);
        $this->assertSame('blocked', $axis['status']);
        $this->assertTrue($axis['blocking']);

        // The axis pending count equals what the department service reports for the same encounter.
        $snapshot = $service->readinessSnapshot();
        $expected = $snapshot['byEncounter']->get($blockingEncounterId);
        $this->assertNotNull($expected);
        $this->assertSame((int) $expected['pendingCount'], (int) $axis['pendingCount']);
        $this->assertSame((int) $expected['pendingCount'], DB::table('prod.rx_discharge_queue')
            ->where('encounter_id', $blockingEncounterId)
            ->whereIn('pipeline_status', PharmacyDischargeReadinessService::BLOCKING)
            ->count());
    }

    public function test_encounter_without_discharge_medication_is_not_applicable(): void
    {
        $readiness = app(AncillaryReadinessService::class);

        // An active non-ED inpatient encounter that has no discharge medication queue row.
        $bareEncounterId = (int) DB::table('prod.encounters as e')
            ->join('prod.units as u', 'u.unit_id', '=', 'e.unit_id')
            ->where('e.status', 'active')
            ->where('e.is_deleted', false)
            ->where('u.type', '<>', 'ed')
            ->whereNotNull('e.expected_discharge_date')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('prod.rx_discharge_queue as q')
                ->whereColumn('q.encounter_id', 'e.encounter_id'))
            ->value('e.encounter_id');
        $this->assertGreaterThan(0, $bareEncounterId);

        $axis = $readiness->medicationForEncounters([$bareEncounterId], 'rtdc')->get($bareEncounterId);
        $this->assertNotNull($axis);
        $this->assertSame('not_applicable', $axis['status']);
        $this->assertFalse($axis['blocking']);
        $this->assertSame(0, $axis['pendingCount']);
    }

    public function test_stale_source_renders_medication_readiness_unknown(): void
    {
        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'stale']);

        $service = app(PharmacyDischargeReadinessService::class);
        $readiness = app(AncillaryReadinessService::class);

        $encounterId = (int) DB::table('prod.rx_discharge_queue')
            ->whereIn('pipeline_status', PharmacyDischargeReadinessService::BLOCKING)
            ->whereNotNull('encounter_id')
            ->value('encounter_id');

        $axis = $readiness->medicationForEncounters([$encounterId], 'rtdc')->get($encounterId);
        $this->assertSame('unknown', $axis['status']);

        // The page state and ready-by-target compliance both degrade honestly.
        $payload = $service->build();
        $this->assertSame('stale', $payload['state']);
        $this->assertNull($payload['data']['summary']['readyByTargetPercent']);
    }

    public function test_discharge_priorities_surfaces_all_three_readiness_axes(): void
    {
        $payload = app(DischargePrioritiesService::class)->build();
        $patients = collect($payload)
            ->only(['priority1', 'priority2', 'priority3', 'priority4'])
            ->flatMap(fn (array $tier): array => $tier);

        // Every listed patient carries the three-axis readiness vector.
        $withMedication = $patients->filter(fn (array $patient): bool => collect($patient['readiness'])->contains(fn (array $axis): bool => $axis['key'] === 'medication'));
        $this->assertNotEmpty($withMedication, 'At least one discharge candidate must expose the medication axis.');

        $sample = $withMedication->first();
        $keys = collect($sample['readiness'])->pluck('key')->all();
        $this->assertContains('imaging', $keys);
        $this->assertContains('lab', $keys);
        $this->assertContains('medication', $keys);
    }

    public function test_payload_carries_no_individual_performance_dimension(): void
    {
        $payload = app(PharmacyDischargeReadinessService::class)->build();

        $this->assertFalse($payload['privacy']['individualPerformanceIncluded']);
        $this->assertFalse($payload['privacy']['directPatientIdentifiersIncluded']);

        // Scan the data surface (not the intentional privacy disclaimer prose) for any
        // user-level dimension: no pharmacist, verifier, or diversion field may reach the browser.
        $flat = json_encode(['data' => $payload['data'], 'filters' => $payload['filters']]);
        foreach (['pharmacist', 'verifier', 'technician', 'employee', 'badge', 'risk_score', 'diversion'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, (string) $flat, "Forbidden fragment leaked: {$forbidden}");
        }
    }
}
