<?php

namespace Tests\Feature\Ancillary;

use App\Models\User;
use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use App\Services\Lab\BloodBankReadinessService;
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

final class BloodBankReadinessServiceTest extends TestCase
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

    public function test_operating_day_matrix_and_perioperative_board_share_required_ready_blocked_and_not_applicable_gates(): void
    {
        $service = app(BloodBankReadinessService::class);
        $noRequirementCaseId = $this->addNoRequirementCase($service->activeOperatingDate());
        $payload = $service->build();
        $gates = collect($payload['data']);
        $required = $gates->where('required', true);

        $this->assertSame('normal', $payload['state']);
        $this->assertSame($service->activeOperatingDate(), $payload['operatingDate']);
        $this->assertSame('latest_operating_day', $payload['operatingDateMode']);
        $this->assertSame(6, $required->sum('requestCount'));
        $this->assertSame(3, $required->where('state', 'blocked')->count());
        $this->assertSame(2, $required->where('state', 'ready')->count());
        $this->assertSame(1, $required->where('state', 'mtp_active')->count());
        $this->assertSame(1, $payload['summary']['mtpActive']);
        $this->assertSame(4, $payload['summary']['blocked']);
        $this->assertTrue($required->every(fn (array $gate): bool => $gate['neededByAligned'] === true && $gate['coverage']['status'] === 'complete'));
        $this->assertTrue($required->every(fn (array $gate): bool => $gate['sourceCutoffAt'] !== null && $gate['freshness']['status'] === 'fresh'));

        $mtp = $required->firstWhere('state', 'mtp_active');
        $issued = $required->firstWhere('issueState', 'partial');
        $notApplicable = $gates->firstWhere('caseId', $noRequirementCaseId);
        $this->assertTrue($mtp['mtpActive']);
        $this->assertSame(6, $mtp['units']['requested']);
        $this->assertStringContainsString('massive-transfusion', $mtp['explanation']);
        $this->assertSame('ready', $issued['state']);
        $this->assertSame('not_applicable', $notApplicable['state']);
        $this->assertFalse($notApplicable['required']);
        $this->assertFalse($notApplicable['blocking']);
        $this->assertSame([], $notApplicable['requests']);

        $byCase = $service->forCases($gates->pluck('caseId')->all());
        foreach ($gates as $gate) {
            $this->assertSame($gate, $byCase->get($gate['caseId']));
        }
        $periop = collect(app(CaseManagementService::class)->getData()['mockProcedures']);
        $this->assertContains($noRequirementCaseId, $periop->pluck('id')->all());
        $this->assertTrue($periop->every(fn (array $case): bool => isset($case['bloodBankGate']) && $case['bloodBankGate'] === $byCase->get($case['id'])));
        $requestCaseIds = DB::table('prod.bb_readiness')->pluck('case_id')->unique()->all();
        $this->assertEqualsCanonicalizing($requestCaseIds, array_values(array_intersect($requestCaseIds, $periop->pluck('id')->all())));

        $this->assertFalse($payload['privacy']['directPatientIdentifiersIncluded']);
        $this->assertFalse($payload['privacy']['bloodProductAllocationControlIncluded']);
        $this->assertFalse($payload['privacy']['writebackIncluded']);
    }

    public function test_time_to_start_freshness_and_needed_by_alignment_are_correct_across_midnight(): void
    {
        $service = app(BloodBankReadinessService::class);
        $case = collect($service->build()['data'])->firstWhere('required', true);
        $caseId = $case['caseId'];
        $beforeMidnight = CarbonImmutable::parse('2026-07-11T23:45:00Z');
        $planned = CarbonImmutable::parse('2026-07-12T00:15:00Z');
        CarbonImmutable::setTestNow($beforeMidnight);
        DB::table('prod.or_cases')->where('case_id', $caseId)->update(['surgery_date' => $planned->toDateString(), 'scheduled_start_time' => $planned]);
        DB::table('prod.bb_readiness')->where('case_id', $caseId)->update(['needed_by' => $planned]);
        $orderIds = DB::table('prod.bb_readiness')->where('case_id', $caseId)->pluck('ancillary_order_id');
        DB::table('prod.ancillary_orders')->whereIn('ancillary_order_id', $orderIds)->update(['source_cutoff_at' => $beforeMidnight->subMinute()]);
        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'current', 'latest_observed_at' => $beforeMidnight->subMinute()]);

        $upcoming = $service->build(['caseId' => $caseId]);
        $this->assertSame('2026-07-12', $upcoming['operatingDate']);
        $this->assertSame('exact_case', $upcoming['operatingDateMode']);
        $this->assertSame(30, $upcoming['data'][0]['minutesToStart']);
        $this->assertSame('upcoming', $upcoming['data'][0]['startTiming']);
        $this->assertTrue($upcoming['data'][0]['neededByAligned']);
        $this->assertSame('fresh', $upcoming['data'][0]['freshness']['status']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-12T00:16:00Z'));
        $past = $service->build(['caseId' => $caseId]);
        $this->assertSame(-1, $past['data'][0]['minutesToStart']);
        $this->assertSame('past_due', $past['data'][0]['startTiming']);
        $this->assertSame('fresh', $past['freshness']['status']);

        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'stale']);
        $stale = $service->build(['caseId' => $caseId]);
        $this->assertSame('stale', $stale['state']);
        $this->assertSame('unknown', $stale['data'][0]['state']);
        $this->assertFalse($stale['data'][0]['blocking']);

        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'current']);
        DB::table('prod.bb_readiness')->where('case_id', $caseId)->update(['needed_by' => $planned->subMinutes(10)]);
        $degraded = $service->build(['caseId' => $caseId]);
        $this->assertSame('degraded', $degraded['state']);
        $this->assertFalse($degraded['data'][0]['neededByAligned']);
        $this->assertSame('degraded', $degraded['data'][0]['coverage']['status']);
    }

    public function test_filters_mtp_lifecycle_constant_query_shape_and_pending_index_are_proven(): void
    {
        $service = app(BloodBankReadinessService::class);
        $noRequirementCaseId = $this->addNoRequirementCase($service->activeOperatingDate());
        $payload = $service->build();
        $mtp = collect($payload['data'])->firstWhere('state', 'mtp_active');
        $ready = collect($payload['data'])->firstWhere('state', 'ready');
        $notApplicable = $service->build(['state' => 'not_applicable']);
        $this->assertContains($noRequirementCaseId, array_column($notApplicable['data'], 'caseId'));
        $this->assertTrue(collect($service->build(['productClass' => 'mixed'])['data'])->every(fn (array $gate): bool => in_array('mixed', $gate['productClasses'], true)));
        $this->assertSame([$ready['caseId']], array_column($service->build(['caseId' => $ready['caseId'], 'service' => $ready['serviceLabel'], 'room' => $ready['roomLabel']])['data'], 'caseId'));

        DB::table('prod.bb_readiness')->where('case_id', $mtp['caseId'])->update(['mtp_closed_at' => $this->anchor]);
        $closed = $service->forCases([$mtp['caseId']])->first();
        $this->assertFalse($closed['mtpActive']);
        $this->assertSame('blocked', $closed['state']);

        $blocked = collect($payload['data'])->firstWhere('state', 'blocked');
        DB::table('prod.bb_readiness')->where('case_id', $blocked['caseId'])->update(['readiness_state' => 'cancelled', 'cancelled_at' => $this->anchor]);
        $cancelled = $service->forCases([$blocked['caseId']])->first();
        $this->assertSame('not_applicable', $cancelled['state']);
        $this->assertFalse($cancelled['required']);

        $caseIds = collect($payload['data'])->pluck('caseId')->all();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->forCases([$caseIds[0]]);
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();
        $service->forCases($caseIds);
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($one, $many);
        $this->assertLessThanOrEqual(4, $many);

        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = collect(DB::select("EXPLAIN SELECT bb_readiness_id FROM prod.bb_readiness WHERE case_id = {$caseIds[0]} AND readiness_state NOT IN ('issued', 'cancelled', 'complete') ORDER BY needed_by, bb_readiness_id"))->pluck('QUERY PLAN')->implode("\n");
        $this->assertStringContainsString('bb_readiness_pending_or_idx', $plan);
    }

    public function test_web_api_auth_validation_read_only_contract_and_named_routes_are_stable(): void
    {
        $manager = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
        $caseId = app(BloodBankReadinessService::class)->build()['data'][0]['caseId'];
        $expected = app(BloodBankReadinessService::class)->build(['caseId' => $caseId]);
        $this->actingAs($manager)->get('/lab/blood-bank?caseId='.$caseId)->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Lab/BloodBank')->where('bloodBank', $expected),
        );
        $this->actingAs($manager)->getJson('/api/lab/blood-bank?caseId='.$caseId)->assertOk()
            ->assertHeader('Cache-Control', 'no-cache, private')->assertExactJson($expected);
        $this->actingAs($manager)->getJson('/api/lab/blood-bank?state=allocated')->assertUnprocessable();
        $this->actingAs($manager)->getJson('/api/lab/blood-bank?productClass=medication')->assertUnprocessable();
        $this->actingAs($manager)->postJson('/api/lab/blood-bank', ['caseId' => $caseId])->assertMethodNotAllowed();
        auth()->logout();
        $this->getJson('/api/lab/blood-bank')->assertUnauthorized();

        $this->assertSame('lab/blood-bank', Route::getRoutes()->getByName('lab.blood-bank')?->uri());
        $this->assertSame('api/lab/blood-bank', Route::getRoutes()->getByName('api.lab.blood-bank')?->uri());
    }

    private function addNoRequirementCase(?string $operatingDate): int
    {
        $template = (array) DB::table('prod.or_cases')->where('is_deleted', false)->whereDate('surgery_date', $operatingDate)->first();
        unset($template['case_id']);
        $template['patient_id'] = 'bb-no-requirement-case';
        $template['scheduled_start_time'] = CarbonImmutable::parse($template['scheduled_start_time'])->addMinutes(7);
        $template['record_create_date'] = $this->anchor;
        $template['created_at'] = $this->anchor;
        $template['updated_at'] = $this->anchor;

        return (int) DB::table('prod.or_cases')->insertGetId($template, 'case_id');
    }
}
