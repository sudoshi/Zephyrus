<?php

namespace Tests\Feature\Demo;

use App\Models\Lab\AnatomicPathologyCase;
use App\Models\Lab\BloodBankReadiness;
use App\Models\Lab\CriticalValue;
use App\Models\Lab\Result;
use App\Models\Lab\Specimen;
use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use App\Services\Demo\DemoInvariantService;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Database\Seeders\CaseManagementSeeder;
use Database\Seeders\CommandCenterDemoSeeder;
use Database\Seeders\RtdcSeeder;
use Database\Seeders\StaffingReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LabDemoGeneratorTest extends TestCase
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

    public function test_fixed_cohort_is_coherent_idempotent_and_owner_safe(): void
    {
        $clock = new DemoClock($this->anchor);
        $service = app(AncillaryDemoScenarioService::class);
        $first = $service->refresh($clock);
        $owner = AncillaryDemoScenarioService::OWNER;
        $departments = collect($first['departments'])->keyBy('department');

        $this->assertSame(14, $departments['lab']['orders']);
        $this->assertSame(16, $departments['lab']['specimens']);
        $this->assertSame(14, $departments['lab']['results']);
        $this->assertSame(2, $departments['lab']['criticalValues']);
        $this->assertSame(3, $departments['lab']['decisionPending']);
        $this->assertSame(6, $departments['pathology']['apCases']);
        $this->assertSame(2, $departments['pathology']['frozenSections']);
        $this->assertSame(6, $departments['blood_bank']['readinessRequests']);
        $this->assertSame(1, $departments['blood_bank']['activeMtp']);

        $this->assertSame(6, DB::table('prod.ancillary_orders')->where('demo_owner', $owner)->whereRaw("metadata->>'demo_shift' = 'am_draw'")->count());
        $this->assertSame(2, Specimen::query()->where('demo_owner', $owner)->where('status', 'recollect_requested')->count());
        $this->assertSame(2, Specimen::query()->where('demo_owner', $owner)->whereNotNull('parent_specimen_id')->count());
        $this->assertSame(1, Specimen::query()->where('demo_owner', $owner)->where('status', 'in_transit')->count());
        $this->assertSame(2, Result::query()->where('demo_owner', $owner)->where('auto_verified', true)->count());
        $this->assertSame(['acknowledged', 'pending_notification'], CriticalValue::query()->where('demo_owner', $owner)->orderBy('callback_state')->pluck('callback_state')->all());

        $distribution = DB::selectOne(
            "SELECT count(*) AS n,
                    percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (r.resulted_at - s.received_at)) / 60) AS median
             FROM prod.lab_results r
             JOIN prod.lab_specimens s ON s.lab_specimen_id = r.lab_specimen_id
             JOIN prod.ancillary_orders o ON o.ancillary_order_id = r.ancillary_order_id
             WHERE r.demo_owner = ? AND o.metadata->>'demo_shift' = 'am_draw' AND r.result_status = 'final'",
            [$owner],
        );
        $this->assertSame(5, (int) $distribution->n);
        $this->assertSame(40.0, (float) $distribution->median);
        $this->assertSame(1, Result::query()->where('demo_owner', $owner)->whereRaw("metadata->>'analyzer_operational_state' = 'rerouted_during_downtime'")->count());

        $micro = Result::query()->where('demo_owner', $owner)->microbiology()->orderBy('source_result_version')->get();
        $this->assertSame(['preliminary', 'organism_identification', 'susceptibility', 'final'], $micro->pluck('result_stage')->all());
        $this->assertTrue($micro->every(fn (Result $result): bool => $result->resulted_at->lessThan($clock->windowStart())));
        $this->assertTrue($micro->every(fn (Result $result): bool => $result->metadata['operational_window'] === 'historical_study_only'));

        $pending = Result::query()->with('testCatalog')->where('demo_owner', $owner)->whereNull('verified_at')
            ->whereHas('testCatalog', fn ($query) => $query->where('decision_class', '!=', 'none'))->get();
        $this->assertSame(['discharge_gate', 'ed_disposition', 'or_gate'], $pending->pluck('testCatalog.decision_class')->sort()->values()->all());
        $this->assertTrue($pending->every(fn (Result $result): bool => filled($result->metadata['decision_context']['explanation'] ?? null)));
        $this->assertTrue($pending->every(fn (Result $result): bool => (int) ($result->metadata['decision_context']['blocked_object_id'] ?? 0) > 0));

        $this->assertSame(['diagnosed', 'processing', 'received', 'signed_out', 'slides_ready'], AnatomicPathologyCase::query()->where('demo_owner', $owner)->distinct()->orderBy('stage')->pluck('stage')->all());
        $this->assertSame(1, AnatomicPathologyCase::query()->where('demo_owner', $owner)->where('frozen_status', 'in_progress')->whereRaw("metadata->'decision_context'->>'explanation' <> ''")->count());
        $this->assertSame(0, AnatomicPathologyCase::query()->where('demo_owner', $owner)->whereDoesntHave('operatingCase')->count());
        $this->assertSame(0, BloodBankReadiness::query()->where('demo_owner', $owner)->whereDoesntHave('operatingCase')->count());
        $this->assertSame(['crossmatch_ready', 'issued', 'ordered', 'testing', 'type_screen_ready'], BloodBankReadiness::query()->where('demo_owner', $owner)->distinct()->orderBy('readiness_state')->pluck('readiness_state')->all());
        $this->assertSame(0, BloodBankReadiness::query()->where('demo_owner', $owner)
            ->whereNotIn('readiness_state', ['crossmatch_ready', 'allocated', 'issued', 'complete', 'cancelled'])
            ->whereRaw("COALESCE(metadata->'decision_context'->>'explanation', '') = ''")->count());

        $semanticBefore = $this->semanticSnapshot($owner);
        $foreignSpecimen = Specimen::factory()->create(['demo_owner' => null]);
        $foreignAp = AnatomicPathologyCase::factory()->create(['demo_owner' => null]);
        $foreignBb = BloodBankReadiness::factory()->create(['demo_owner' => null]);
        $second = $service->refresh($clock);
        $this->assertSame($first, $second);
        $this->assertSame($semanticBefore, $this->semanticSnapshot($owner));
        $this->assertDatabaseHas('prod.lab_specimens', ['lab_specimen_id' => $foreignSpecimen->lab_specimen_id, 'demo_owner' => null]);
        $this->assertDatabaseHas('prod.ap_cases', ['ap_case_id' => $foreignAp->ap_case_id, 'demo_owner' => null]);
        $this->assertDatabaseHas('prod.bb_readiness', ['bb_readiness_id' => $foreignBb->bb_readiness_id, 'demo_owner' => null]);

        $findings = collect(app(DemoInvariantService::class)->run($clock))->where('category', 'ancillary');
        $this->assertSame([], $findings->where('severity', 'critical')->where('passed', false)->pluck('key')->all());
        $this->assertTrue($findings->firstWhere('key', 'ancillary.lab_distribution_plausible')['passed']);
        $this->assertTrue($findings->firstWhere('key', 'ancillary.microbiology_study_window_honest')['passed']);
    }

    /** @return array<string, mixed> */
    private function semanticSnapshot(string $owner): array
    {
        return [
            'orders' => DB::table('prod.ancillary_orders')->where('demo_owner', $owner)->orderBy('source_order_key')->pluck('source_order_key')->all(),
            'specimens' => Specimen::query()->where('demo_owner', $owner)->orderBy('source_specimen_key')->get()->map(fn (Specimen $row): array => [$row->source_specimen_key, $row->status])->all(),
            'results' => Result::query()->where('demo_owner', $owner)->orderBy('source_result_key')->orderBy('source_result_version')->get()->map(fn (Result $row): array => [$row->source_result_key, $row->source_result_version, $row->result_stage, $row->verified_at?->toIso8601String()])->all(),
            'ap' => AnatomicPathologyCase::query()->where('demo_owner', $owner)->orderBy('source_case_key')->get()->map(fn (AnatomicPathologyCase $row): array => [$row->source_case_key, $row->stage, $row->frozen_status, $row->case_id])->all(),
            'bb' => BloodBankReadiness::query()->where('demo_owner', $owner)->orderBy('source_request_key')->get()->map(fn (BloodBankReadiness $row): array => [$row->source_request_key, $row->readiness_state, $row->case_id])->all(),
        ];
    }
}
