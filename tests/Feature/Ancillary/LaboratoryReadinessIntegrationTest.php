<?php

declare(strict_types=1);

namespace Tests\Feature\Ancillary;

use App\Models\Ancillary\AncillaryOrder;
use App\Models\Lab\LabTestCatalog;
use App\Models\Lab\Result;
use App\Models\Lab\Specimen;
use App\Services\Ancillary\AncillaryReadinessService;
use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use App\Services\Ed\TreatmentService;
use App\Services\Lab\LabDecisionPendingService;
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

final class LaboratoryReadinessIntegrationTest extends TestCase
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

    public function test_multiple_explicit_gates_reconcile_to_discharge_ed_and_exact_department_drills(): void
    {
        $queue = app(LabDecisionPendingService::class)->build();
        $discharge = collect($queue['data'])->firstWhere('decisionClass', 'discharge_gate');
        $ed = collect($queue['data'])->firstWhere('decisionClass', 'ed_disposition');
        $encounterId = (int) $discharge['destination']['id'];
        $edVisitId = (int) $ed['destination']['id'];

        $second = $this->createPendingDischargeGate($discharge['orderUuid'], 125);
        $imaging = AncillaryOrder::factory()->radiology()->dischargeBlocking()->create([
            'encounter_id' => $encounterId,
            'patient_ref' => DB::table('prod.encounters')->where('encounter_id', $encounterId)->value('patient_ref'),
            'patient_class' => 'inpatient',
            'ordered_at' => $this->anchor->subMinutes(52),
            'source_cutoff_at' => $this->anchor->subMinutes(2),
        ]);
        DB::table('prod.encounters')->where('encounter_id', $encounterId)->update([
            'admitted_at' => $this->anchor->subDays(30),
            'expected_discharge_date' => $this->anchor->toDateString(),
        ]);

        $pending = app(LabDecisionPendingService::class)->build();
        $aggregate = collect($pending['destinationAggregates'])->first(
            fn (array $row): bool => $row['decisionClass'] === 'discharge_gate' && $row['destinationId'] === $encounterId,
        );
        $readiness = app(AncillaryReadinessService::class);
        $lab = $readiness->laboratoryForEncounters([$encounterId], 'rtdc')->get($encounterId);

        $this->assertSame(2, $aggregate['pendingCount']);
        $this->assertSame(125, $aggregate['oldestAgeMinutes']);
        $this->assertSame($second->order_uuid, $aggregate['topOrderUuid']);
        $this->assertSame('blocked', $lab['state']);
        $this->assertSame(2, $lab['pendingCount']);
        $this->assertSame(125, $lab['oldestAgeMinutes']);
        $this->assertTrue($lab['blocking']);
        $this->assertSame($aggregate['topOrderUuid'], $lab['topOrderUuid']);
        $this->assertStringContainsString('decisionClass=discharge_gate', $lab['drillHref']);
        $this->assertStringContainsString('orderUuid='.$second->order_uuid, $lab['drillHref']);
        $this->assertStringEndsWith('source=rtdc', $lab['drillHref']);
        $this->assertSame([$second->order_uuid], array_column(app(LabDecisionPendingService::class)->build([
            'decisionClass' => 'discharge_gate',
            'orderUuid' => $second->order_uuid,
        ])['data'], 'orderUuid'));

        $dischargePatient = $this->findPatient(app(DischargePrioritiesService::class)->build(), $encounterId);
        $this->assertSame($lab, $dischargePatient['lab']);
        $this->assertSame(['imaging', 'lab'], array_column($dischargePatient['readiness'], 'key'));
        $this->assertSame($imaging->order_uuid, $dischargePatient['imaging']['topOrderUuid']);

        $edAggregate = collect($pending['destinationAggregates'])->first(
            fn (array $row): bool => $row['decisionClass'] === 'ed_disposition' && $row['destinationId'] === $edVisitId,
        );
        $edAxis = $readiness->laboratoryForEdVisits([$edVisitId], 'ed')->get($edVisitId);
        $edPatient = collect(app(TreatmentService::class)->build()['board'])->firstWhere('edVisitId', $edVisitId);
        $this->assertSame($edAggregate['pendingCount'], $edAxis['pendingCount']);
        $this->assertSame($edAggregate['oldestAgeMinutes'], $edAxis['oldestAgeMinutes']);
        $this->assertSame($edAggregate['topOrderUuid'], $edAxis['topOrderUuid']);
        $this->assertStringContainsString('decisionClass=ed_disposition', $edAxis['drillHref']);
        $this->assertStringEndsWith('source=ed', $edAxis['drillHref']);
        $this->assertSame($edAxis, $edPatient['lab']);

        $serialized = json_encode([$lab, $edAxis], JSON_THROW_ON_ERROR);
        foreach (['resultUuid', 'specimenUuid', 'patientRef', 'resultContent', 'sourceResultKey'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $readiness->laboratoryForEncounters([$encounterId]);
        $oneScopeQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $readiness->laboratoryForEncounters(
            DB::table('prod.encounters')->where('status', 'active')->limit(25)->pluck('encounter_id')->map(fn (mixed $id): int => (int) $id)->all(),
        );
        $manyScopeQueries = count(DB::getQueryLog()) - 1; // Exclude the ID-list fixture query.
        DB::disableQueryLog();
        $this->assertSame($oneScopeQueries, $manyScopeQueries);
        $this->assertLessThanOrEqual(20, $manyScopeQueries);
    }

    public function test_routine_non_gating_result_is_ready_and_does_not_block_discharge(): void
    {
        $encounterId = $this->createActiveEncounter('l11-routine-non-gate');
        $order = AncillaryOrder::factory()->lab()->create([
            'encounter_id' => $encounterId,
            'patient_ref' => 'l11-routine-non-gate',
            'patient_class' => 'inpatient',
            'priority' => 'routine',
            'ordered_at' => $this->anchor->subMinutes(36),
            'source_cutoff_at' => $this->anchor->subMinutes(2),
            'metadata' => ['test_code' => 'CBC', 'decision_class' => 'none'],
        ]);
        $specimen = Specimen::factory()->create([
            'ancillary_order_id' => $order->ancillary_order_id,
            'source_id' => $order->source_id,
            'encounter_id' => $encounterId,
        ]);
        Result::factory()->preliminary()->create([
            'lab_specimen_id' => $specimen->lab_specimen_id,
            'ancillary_order_id' => $order->ancillary_order_id,
            'source_id' => $order->source_id,
            'lab_test_catalog_id' => LabTestCatalog::query()->where('catalog_key', 'lab.cbc')->value('lab_test_catalog_id'),
        ]);

        $axis = app(AncillaryReadinessService::class)->laboratoryForEncounters([$encounterId])->get($encounterId);
        $this->assertSame('ready', $axis['state']);
        $this->assertSame(0, $axis['pendingCount']);
        $this->assertFalse($axis['blocking']);
        $this->assertNull($axis['topOrderUuid']);
        $this->assertNull($axis['drillHref']);
        $this->assertStringContainsString('routine and non-gating results do not block', $axis['explanation']);

        $pendingResultUuids = array_filter(array_column(app(LabDecisionPendingService::class)->build()['data'], 'resultUuid'));
        Result::query()->whereIn('result_uuid', $pendingResultUuids)->update(['verified_at' => $this->anchor]);
        $verifiedEmpty = app(AncillaryReadinessService::class)->laboratoryForEncounters([$encounterId])->get($encounterId);
        $this->assertSame('ready', $verifiedEmpty['state']);
        $this->assertSame('fresh', $verifiedEmpty['freshness']['status']);
        $this->assertSame(0, $verifiedEmpty['pendingCount']);
    }

    public function test_latest_unverified_correction_remains_blocked_and_verification_clears_the_axis(): void
    {
        $discharge = collect(app(LabDecisionPendingService::class)->build()['data'])->firstWhere('decisionClass', 'discharge_gate');
        $encounterId = (int) $discharge['destination']['id'];
        $parent = Result::query()->where('result_uuid', $discharge['resultUuid'])->firstOrFail();
        $correction = Result::factory()->corrected($parent)->create([
            'observed_at' => $parent->observed_at,
            'resulted_at' => $parent->resulted_at,
            'verified_at' => null,
            'metadata' => $parent->metadata,
        ]);
        $readiness = app(AncillaryReadinessService::class);

        $corrected = $readiness->laboratoryForEncounters([$encounterId])->get($encounterId);
        $this->assertSame('blocked', $corrected['state']);
        $this->assertSame(1, $corrected['pendingCount']);
        $this->assertSame($discharge['orderUuid'], $corrected['topOrderUuid']);

        $correction->update(['verified_at' => $this->anchor, 'result_status' => 'corrected', 'result_stage' => 'corrected']);
        $verified = $readiness->laboratoryForEncounters([$encounterId])->get($encounterId);
        $this->assertSame('ready', $verified['state']);
        $this->assertSame(0, $verified['pendingCount']);
        $this->assertFalse($verified['blocking']);
        $this->assertNull($verified['topOrderUuid']);
    }

    public function test_stale_and_unresolved_evidence_are_unknown_for_populated_and_empty_scopes(): void
    {
        $pending = app(LabDecisionPendingService::class)->build();
        $discharge = collect($pending['data'])->firstWhere('decisionClass', 'discharge_gate');
        $ed = collect($pending['data'])->firstWhere('decisionClass', 'ed_disposition');
        $encounterId = (int) $discharge['destination']['id'];
        $edVisitId = (int) $ed['destination']['id'];
        $emptyEncounterId = $this->createActiveEncounter('l11-empty-stale');
        $readiness = app(AncillaryReadinessService::class);

        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'stale']);
        $encounters = $readiness->laboratoryForEncounters([$encounterId, $emptyEncounterId]);
        $edAxis = $readiness->laboratoryForEdVisits([$edVisitId])->get($edVisitId);
        $this->assertSame('unknown', $encounters->get($encounterId)['state']);
        $this->assertGreaterThan(0, $encounters->get($encounterId)['pendingCount']);
        $this->assertSame('stale', $encounters->get($encounterId)['freshness']['status']);
        $this->assertSame('unknown', $encounters->get($emptyEncounterId)['state']);
        $this->assertSame(0, $encounters->get($emptyEncounterId)['pendingCount']);
        $this->assertSame('unknown', $edAxis['state']);

        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'current']);
        DB::table('prod.encounters')->where('encounter_id', $encounterId)->update([
            'status' => 'discharged',
            'discharged_at' => $this->anchor,
        ]);
        $localized = $readiness->laboratoryForEncounters([$encounterId, $emptyEncounterId]);
        $unresolved = $localized->get($encounterId);
        $this->assertSame('unknown', $unresolved['state']);
        $this->assertSame(0, $unresolved['pendingCount']);
        $this->assertFalse($unresolved['blocking']);
        $this->assertStringContainsString('unresolved destination evidence', $unresolved['explanation']);
        $this->assertSame('ready', $localized->get($emptyEncounterId)['state']);
    }

    private function createPendingDischargeGate(string $templateOrderUuid, int $minutesAgo): AncillaryOrder
    {
        $template = AncillaryOrder::query()->where('order_uuid', $templateOrderUuid)->firstOrFail();
        $order = AncillaryOrder::factory()->lab()->create([
            'source_id' => $template->source_id,
            'encounter_id' => $template->encounter_id,
            'encounter_ref' => $template->encounter_ref,
            'patient_ref' => $template->patient_ref,
            'patient_class' => $template->patient_class,
            'priority' => 'urgent',
            'unit_id' => $template->unit_id,
            'ordered_at' => $this->anchor->subMinutes($minutesAgo),
            'source_cutoff_at' => $this->anchor->subMinutes(2),
            'metadata' => [
                'test_code' => 'BMP',
                'decision_class' => 'discharge_gate',
                'discharge_blocking' => true,
            ],
        ]);
        $specimen = Specimen::factory()->create([
            'ancillary_order_id' => $order->ancillary_order_id,
            'source_id' => $order->source_id,
            'encounter_id' => $order->encounter_id,
        ]);
        Result::factory()->preliminary()->create([
            'lab_specimen_id' => $specimen->lab_specimen_id,
            'ancillary_order_id' => $order->ancillary_order_id,
            'source_id' => $order->source_id,
            'lab_test_catalog_id' => LabTestCatalog::query()->where('catalog_key', 'lab.bmp')->value('lab_test_catalog_id'),
        ]);

        return $order;
    }

    private function createActiveEncounter(string $patientRef): int
    {
        $unitId = (int) DB::table('prod.units')->where('type', '<>', 'ed')->value('unit_id');

        return (int) DB::table('prod.encounters')->insertGetId([
            'patient_ref' => $patientRef,
            'unit_id' => $unitId,
            'admitted_at' => $this->anchor->subDays(5),
            'expected_discharge_date' => $this->anchor->toDateString(),
            'acuity_tier' => 2,
            'status' => 'active',
            'created_at' => $this->anchor,
            'updated_at' => $this->anchor,
            'is_deleted' => false,
        ], 'encounter_id');
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function findPatient(array $payload, int $encounterId): array
    {
        return collect(['priority1', 'priority2', 'priority3', 'priority4'])
            ->flatMap(fn (string $key): array => $payload[$key])
            ->firstWhere('id', $encounterId);
    }
}
