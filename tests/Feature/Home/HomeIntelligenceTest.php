<?php

namespace Tests\Feature\Home;

use App\Domain\Ocel\OcelProjector;
use App\Models\RtdcPrediction;
use App\Models\Unit;
use App\Models\User;
use App\Services\Eddy\EddyActionService;
use App\Services\Flow\ForwardProjectionService;
use Carbon\CarbonImmutable;
use Database\Seeders\HomeHospitalDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HomeIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('home_hospital.enabled', true);
        $this->seed(HomeHospitalDemoSeeder::class);
        $this->user = User::factory()->create();
    }

    public function test_decant_endpoint_reports_capacity_and_writes_predictions(): void
    {
        $payload = $this->actingAs($this->user)
            ->getJson('/api/home/decant')
            ->assertOk()
            ->json();

        $this->assertTrue($payload['available']);
        $this->assertSame(2, $payload['freeSlotsNow']); // 12 slots − 8 occupied − 1 blocked − 1 pending
        $this->assertGreaterThanOrEqual($payload['freeSlotsNow'], $payload['freeSlots24h']);
        $this->assertGreaterThanOrEqual($payload['freeSlots24h'], $payload['freeSlots48h']);
        $this->assertSame(8, $payload['activeEpisodes']);
        $this->assertGreaterThan(0, $payload['avoidedBedDaysMtd']);

        $ward = Unit::where('type', 'virtual_home')->sole();
        $rows = RtdcPrediction::query()
            ->where('unit_id', $ward->unit_id)
            ->where('service_date', now()->toDateString())
            ->get()
            ->keyBy('horizon');

        $this->assertArrayHasKey('by_2pm', $rows->all());
        $this->assertArrayHasKey('by_midnight', $rows->all());
        $this->assertSame($payload['freeSlotsNow'], (int) $rows['by_2pm']->capacity_now);
    }

    public function test_forward_projection_carries_home_slot_free_stream_only_when_enabled(): void
    {
        $from = CarbonImmutable::now();
        $to = $from->addHours(48);

        $service = app(ForwardProjectionService::class);
        $scope = ['type' => 'house', 'floor' => null, 'unit_id' => null, 'patient_ref' => null];

        \Illuminate\Support\Facades\Cache::flush();
        $items = $service->projections($from, $to, $scope, ['home_slot_free']);
        $this->assertNotEmpty($items);
        $this->assertSame('home_slot_free', $items[0]['kind']);
        $this->assertSame('probable', $items[0]['confidence']);
        $this->assertSame('home_hospital.expected_discharge', $items[0]['provenance']['service']);

        config()->set('home_hospital.enabled', false);
        \Illuminate\Support\Facades\Cache::flush();
        $this->assertSame([], $service->projections($from, $to, $scope, ['home_slot_free']));
    }

    public function test_all_seven_home_eddy_actions_are_draft_only(): void
    {
        $home = [
            'propose_escalation_response', 'propose_hah_enrollment', 'propose_stepdown_cohort',
            'propose_visit_reschedule', 'flag_rpm_gap', 'propose_home_discharge', 'flag_transition_barrier',
        ];

        $catalog = app(EddyActionService::class)->catalog();

        foreach ($home as $action) {
            $this->assertArrayHasKey($action, $catalog, $action);
            $this->assertTrue($catalog[$action]['requires_human_approval'], $action);
            $this->assertTrue($catalog[$action]['draft_only_for_agent_tokens'], $action);
            $this->assertFalse($catalog[$action]['execution_adapter']['direct_domain_mutation'], $action);
        }

        // Routing invariant: only the escalation response owns the home. prefix.
        $this->assertSame('propose_escalation_response', EddyActionService::actionForAlert('home.visit_compliance_today', 'crit'));
    }

    public function test_ocel_projection_includes_the_home_pathway_when_enabled(): void
    {
        $result = app(OcelProjector::class)->project(now()->subDays(14), now());

        $this->assertGreaterThan(0, $result['source_rows']['prod.home_episodes']);

        $activities = DB::table('ocel.events')
            ->whereIn('activity', ['home-activate', 'home-visit-complete', 'home-escalation-open', 'home-escalation-resolve'])
            ->selectRaw('activity, count(*) AS n')
            ->groupBy('activity')
            ->pluck('n', 'activity');

        $this->assertGreaterThanOrEqual(8, (int) ($activities['home-activate'] ?? 0));
        $this->assertGreaterThan(0, (int) ($activities['home-visit-complete'] ?? 0));
        $this->assertGreaterThan(0, (int) ($activities['home-escalation-resolve'] ?? 0));

        $objects = DB::table('ocel.objects')->whereIn('type', ['Home Episode', 'RPM Kit', 'Home Visit', 'Escalation'])->count();
        $this->assertGreaterThan(0, $objects);

        config()->set('home_hospital.enabled', false);
        $off = app(OcelProjector::class)->project(now()->subDays(14), now());
        $this->assertSame(0, $off['source_rows']['prod.home_episodes']);
    }

    public function test_cms_export_produces_the_study_variables(): void
    {
        $path = storage_path('framework/testing/cms-export-'.getmypid().'.json');

        $this->artisan('home:cms-export', [
            '--from' => now()->subDays(14)->toDateString(),
            '--output' => $path,
        ])->assertExitCode(0);

        $report = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        @unlink($path);

        $this->assertSame(8, $report['episodes']['started']);
        $this->assertNotNull($report['escalations']['response_p90_minutes']);
        $this->assertSame(100.0, (float) $report['escalations']['within_30min_floor_pct']);
        $this->assertNotNull($report['visit_compliance']['compliance_pct']);
        $this->assertArrayHasKey('decline_reasons', $report['equity_selection_bias']);
        $this->assertArrayHasKey('activation_rate_by_payer', $report['equity_selection_bias']);
        $this->assertSame(6.2, $report['benchmarks']['escalation_rate_pct']['value']);

        // Pseudonymity holds in the export too: aggregates only.
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Street', $json);
        $this->assertStringNotContainsString('HOME-DEMO-001', $json);
    }

    public function test_cms_export_refuses_when_module_disabled(): void
    {
        config()->set('home_hospital.enabled', false);

        $this->artisan('home:cms-export')->assertExitCode(1);
    }
}
