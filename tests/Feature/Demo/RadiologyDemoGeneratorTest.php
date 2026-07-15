<?php

namespace Tests\Feature\Demo;

use App\Models\Radiology\Exam;
use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use App\Services\Demo\DemoInvariantService;
use App\Services\Radiology\IrSuiteAnalyticsService;
use App\Services\Radiology\ModalityUtilizationService;
use App\Services\Radiology\RadiologyReadsService;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Database\Seeders\CaseManagementSeeder;
use Database\Seeders\CommandCenterDemoSeeder;
use Database\Seeders\RtdcSeeder;
use Database\Seeders\StaffingReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RadiologyDemoGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $anchor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anchor = CarbonImmutable::parse('2026-07-11T14:00:00Z');
        CarbonImmutable::setTestNow($this->anchor);
        $this->seed([RtdcSeeder::class, CaseManagementSeeder::class, StaffingReferenceSeeder::class, CommandCenterDemoSeeder::class, AncillaryReferenceSeeder::class]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_fixed_cohort_is_context_linked_plausible_idempotent_and_owner_safe(): void
    {
        $service = app(AncillaryDemoScenarioService::class);
        $clock = new DemoClock($this->anchor);
        $first = $service->refresh($clock);
        $owner = AncillaryDemoScenarioService::OWNER;
        $radiology = collect($first['departments'])->firstWhere('department', 'rad');

        $this->assertSame(16, $radiology['orders']);
        $this->assertSame(16, $radiology['exams']);
        $this->assertGreaterThanOrEqual(14, $radiology['reads']);
        $this->assertGreaterThanOrEqual(6, $radiology['scanners']);
        $this->assertSame(1, $radiology['downtimes']);
        $this->assertSame(1, $radiology['criticalResults']);
        $this->assertSame(2, $radiology['barriers']);

        $distribution = DB::selectOne(
            "SELECT count(*) AS n,
                    percentile_cont(0.25) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (v.occurred_at - o.ordered_at)) / 60) AS q1,
                    percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (v.occurred_at - o.ordered_at)) / 60) AS median,
                    percentile_cont(0.75) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (v.occurred_at - o.ordered_at)) / 60) AS q3
             FROM prod.rad_exams e JOIN prod.ancillary_orders o ON o.ancillary_order_id = e.ancillary_order_id
             JOIN prod.ancillary_current_assertions v ON v.ancillary_order_id = o.ancillary_order_id AND v.milestone_code = 'RAD_EXAM_END'
             WHERE e.demo_owner = ? AND e.modality_code = 'CT' AND o.patient_class = 'emergency'",
            [$owner],
        );
        $this->assertSame(9, (int) $distribution->n);
        $this->assertSame(57.0, (float) $distribution->q1);
        $this->assertSame(108.0, (float) $distribution->median);
        $this->assertSame(182.0, (float) $distribution->q3);
        $this->assertSame(['CT', 'IR', 'MRI', 'NM', 'US', 'XR'], DB::table('prod.rad_exams')->where('demo_owner', $owner)->distinct()->orderBy('modality_code')->pluck('modality_code')->all());
        $this->assertSame(['emergency', 'inpatient', 'outpatient'], DB::table('prod.ancillary_orders')->where('demo_owner', $owner)->where('department', 'rad')->distinct()->orderBy('patient_class')->pluck('patient_class')->all());
        $this->assertSame(1, DB::table('prod.rad_exams')->where('demo_owner', $owner)->where('is_portable', true)->count());
        $this->assertSame(1, DB::table('prod.rad_exams')->where('demo_owner', $owner)->where('is_ir', true)->count());
        $this->assertGreaterThan(0, DB::table('prod.rad_exams as x')->join('prod.ancillary_orders as o', 'o.ancillary_order_id', '=', 'x.ancillary_order_id')->join('prod.ed_visits as v', 'v.ed_visit_id', '=', DB::raw("NULLIF(x.metadata->>'ed_visit_id', '')::bigint"))->whereColumn('v.patient_ref', 'o.patient_ref')->where('x.demo_owner', $owner)->where('v.is_deleted', false)->whereRaw("x.metadata->>'demo_context' = 'ed'")->count());
        $this->assertGreaterThan(0, DB::table('prod.rad_exams as x')->join('prod.ancillary_orders as o', 'o.ancillary_order_id', '=', 'x.ancillary_order_id')->join('prod.encounters as e', 'e.encounter_id', '=', 'o.encounter_id')->where('x.demo_owner', $owner)->whereNotNull('e.expected_discharge_date')->whereRaw("o.metadata->>'discharge_blocking' = 'true'")->count());
        $this->assertSame(1, DB::table('prod.rad_exams as x')->join('prod.or_cases as c', 'c.case_id', '=', DB::raw("NULLIF(x.metadata->>'or_case_id', '')::bigint"))->where('x.demo_owner', $owner)->where('x.is_ir', true)->count());
        $this->assertGreaterThan(0, DB::table('prod.rad_exams')->where('demo_owner', $owner)->whereRaw("metadata->>'demo_shift' = 'night_weekend'")->count());
        $this->assertDatabaseHas('prod.rad_reads', ['demo_owner' => $owner, 'status' => 'corrected']);
        $this->assertDatabaseHas('prod.rad_critical_results', ['demo_owner' => $owner, 'policy_state' => 'acknowledged']);
        $this->assertSame(0, DB::table('prod.rad_scanners')->where('demo_owner', $owner)->whereRaw("metadata->'staffed_operating_hours' IS NULL")->count());
        $this->assertSame(0, DB::table('prod.rad_scanners')->where('demo_owner', $owner)->whereRaw("metadata->>'mpps_source_key' IS NULL")->count());
        $this->assertSame(1, DB::table('prod.rad_scanners')->where('demo_owner', $owner)->whereRaw("metadata->>'ir_suite_declared' = 'true'")->count());
        $this->assertSame(1, DB::table('prod.rad_exams')->where('demo_owner', $owner)->where('is_ir', true)->whereNotNull('scheduled_start_at')->count());

        $utilization = app(ModalityUtilizationService::class)->build(['date' => $this->anchor->toDateString()]);
        $this->assertSame('normal', $utilization['state']);
        $this->assertSame('complete', $utilization['coverage']['status']);
        $this->assertNotNull($utilization['summary']['utilizationPercent']);
        $this->assertSame(0, $utilization['summary']['reconciliationDeltaMinutes']);
        $this->assertGreaterThan(0, $utilization['summary']['unplannedDowntimeMinutes']);

        $irSuite = app(IrSuiteAnalyticsService::class)->build(['dateFrom' => $this->anchor->toDateString(), 'dateTo' => $this->anchor->toDateString()]);
        $this->assertSame(1, $irSuite['summary']['declaredRoomCount']);
        $this->assertSame(1, $irSuite['summary']['candidateCaseCount']);
        $this->assertSame(1, $irSuite['summary']['completedCaseCount']);
        $this->assertNotNull($irSuite['summary']['utilizationPercent']);
        $this->assertSame(100, $irSuite['summary']['fcots']['percent']);
        $this->assertSame('Radiology Workspace', $irSuite['ownership']['operationalOwner']);

        $reads = app(RadiologyReadsService::class)->build();
        $this->assertSame('degraded', $reads['state']);
        $this->assertGreaterThan(0, $reads['backlog']['missing']['completionTimestampCount']);
        $this->assertGreaterThan(0, $reads['preliminaryToFinal']['count']);
        $this->assertGreaterThan(0, collect($reads['reportStates'])->firstWhere('state', 'no_report')['count']);
        $this->assertGreaterThan(0, collect($reads['reportStates'])->firstWhere('state', 'corrected')['count']);
        $this->assertSame($reads['health'], app(RadiologyReadsService::class)->cockpitHealth());

        $firstKeys = DB::table('prod.ancillary_orders')->where('demo_owner', $owner)->orderBy('source_order_key')->pluck('source_order_key')->all();
        $firstCounts = $this->ownedCounts($owner);
        $second = $service->refresh($clock);
        $this->assertSame($first, $second);
        $this->assertSame($firstKeys, DB::table('prod.ancillary_orders')->where('demo_owner', $owner)->orderBy('source_order_key')->pluck('source_order_key')->all());
        $this->assertSame($firstCounts, $this->ownedCounts($owner));

        $foreign = Exam::factory()->create(['demo_owner' => null]);
        CarbonImmutable::setTestNow($this->anchor->addDay());
        $service->refresh(new DemoClock($this->anchor->addDay()));
        $this->assertDatabaseHas('prod.rad_exams', ['rad_exam_id' => $foreign->rad_exam_id, 'demo_owner' => null]);
        $this->assertSame([], array_values(array_intersect($firstKeys, DB::table('prod.ancillary_orders')->where('demo_owner', $owner)->pluck('source_order_key')->all())));

        $findings = collect(app(DemoInvariantService::class)->run(new DemoClock($this->anchor->addDay())))->where('category', 'ancillary');
        $this->assertSame([], $findings->where('severity', 'critical')->where('passed', false)->pluck('key')->all());
        $this->assertTrue($findings->firstWhere('key', 'ancillary.radiology_distribution_plausible')['passed']);
    }

    /** @return array<string, int> */
    private function ownedCounts(string $owner): array
    {
        return [
            'orders' => DB::table('prod.ancillary_orders')->where('demo_owner', $owner)->count(),
            'exams' => DB::table('prod.rad_exams')->where('demo_owner', $owner)->count(),
            'reads' => DB::table('prod.rad_reads')->where('demo_owner', $owner)->count(),
            'critical' => DB::table('prod.rad_critical_results')->where('demo_owner', $owner)->count(),
            'scanners' => DB::table('prod.rad_scanners')->where('demo_owner', $owner)->count(),
            'downtimes' => DB::table('prod.rad_scanner_downtimes')->where('demo_owner', $owner)->count(),
            'barriers' => DB::table('prod.barriers')->where('demo_owner', $owner)->count(),
        ];
    }
}
