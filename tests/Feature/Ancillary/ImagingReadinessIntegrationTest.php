<?php

declare(strict_types=1);

namespace Tests\Feature\Ancillary;

use App\Models\Ancillary\AncillaryOrder;
use App\Models\Radiology\Exam;
use App\Services\Ancillary\AncillaryReadinessService;
use App\Services\Ed\TreatmentService;
use App\Services\Radiology\RadiologyWorklistService;
use App\Services\Rtdc\DischargePrioritiesService;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Database\Seeders\RtdcSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ImagingReadinessIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = CarbonImmutable::parse('2026-07-12T14:00:00Z');
        CarbonImmutable::setTestNow($this->now);
        $this->seed([RtdcSeeder::class, AncillaryReferenceSeeder::class]);
        DB::table('ops.source_freshness')->updateOrInsert(
            ['source_key' => 'ancillary_orders'],
            [
                'source_label' => 'Radiology operational feeds',
                'source_schema' => 'prod',
                'source_table' => 'ancillary_orders',
                'freshness_column' => 'source_cutoff_at',
                'latest_observed_at' => $this->now->subMinutes(2),
                'expected_lag_minutes' => 15,
                'warning_lag_minutes' => 60,
                'record_count' => 1,
                'status' => 'current',
                'checked_at' => $this->now,
                'metadata' => '{}',
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ],
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_one_ct_reconciles_across_radiology_ed_and_rtdc_without_false_discharge_gates(): void
    {
        $unitId = (int) DB::table('prod.units')->where('type', '<>', 'ed')->value('unit_id');
        $patientRef = 'r11-cross-surface-patient';
        $encounterId = (int) DB::table('prod.encounters')->insertGetId([
            'patient_ref' => $patientRef,
            'unit_id' => $unitId,
            'admitted_at' => $this->now->subDays(5),
            'expected_discharge_date' => $this->now->toDateString(),
            'acuity_tier' => 2,
            'status' => 'active',
            'created_at' => $this->now,
            'updated_at' => $this->now,
            'is_deleted' => false,
        ], 'encounter_id');
        $edVisitId = (int) DB::table('prod.ed_visits')->insertGetId([
            'patient_ref' => $patientRef,
            'arrived_at' => $this->now->subHours(3),
            'triaged_at' => $this->now->subHours(2)->subMinutes(50),
            'esi_level' => 3,
            'provider_seen_at' => $this->now->subHours(2),
            'created_at' => $this->now,
            'updated_at' => $this->now,
            'is_deleted' => false,
        ], 'ed_visit_id');

        $ct = AncillaryOrder::factory()->radiology()->dischargeBlocking()->create([
            'encounter_id' => $encounterId,
            'patient_ref' => $patientRef,
            'patient_class' => 'inpatient',
            'ordered_at' => $this->now->subMinutes(47),
            'source_cutoff_at' => $this->now->subMinutes(2),
            'current_state' => 'scheduled',
        ]);
        Exam::factory()->ct()->create([
            'ancillary_order_id' => $ct->ancillary_order_id,
            'source_id' => $ct->source_id,
            'encounter_id' => $encounterId,
            'procedure_label' => 'Demo discharge CT',
            'metadata' => ['ed_visit_id' => $edVisitId],
        ]);

        $completed = AncillaryOrder::factory()->radiology()->dischargeBlocking()->terminal()->create([
            'encounter_id' => $encounterId,
            'patient_ref' => $patientRef,
            'ordered_at' => $this->now->subMinutes(90),
            'source_cutoff_at' => $this->now->subMinute(),
        ]);
        $outpatient = AncillaryOrder::factory()->radiology()->create([
            'patient_ref' => 'unrelated-outpatient',
            'patient_class' => 'outpatient',
            'priority' => 'routine',
            'ordered_at' => $this->now->subMinutes(75),
            'source_cutoff_at' => $this->now->subMinutes(2),
            'metadata' => [],
        ]);

        $readiness = app(AncillaryReadinessService::class);
        $encounterAxis = $readiness->imagingForEncounters([$encounterId])->get($encounterId);
        $edAxis = $readiness->imagingForEdVisits([$edVisitId])->get($edVisitId);
        $orderAxis = $readiness->imagingForOrders([(int) $ct->ancillary_order_id])->get((int) $ct->ancillary_order_id);

        foreach ([$encounterAxis, $edAxis, $orderAxis] as $axis) {
            $this->assertSame('blocked', $axis['state']);
            $this->assertSame(47, $axis['oldestAgeMinutes']);
            $this->assertTrue($axis['blocking']);
            $this->assertSame($ct->order_uuid, $axis['topOrderUuid']);
            $this->assertStringContainsString('search='.$ct->order_uuid, $axis['drillHref']);
        }
        $this->assertSame(1, $encounterAxis['pendingCount']);
        $this->assertFalse($readiness->imagingForOrders([(int) $outpatient->ancillary_order_id])->first()['blocking']);
        $this->assertSame(0, $readiness->imagingForOrders([(int) $completed->ancillary_order_id])->first()['pendingCount']);

        $rtdcPatient = $this->findPatient(app(DischargePrioritiesService::class)->build(), $encounterId);
        $edPatient = collect(app(TreatmentService::class)->build()['board'])->firstWhere('edVisitId', $edVisitId);
        $radiologyRow = collect(app(RadiologyWorklistService::class)->build([
            'search' => $ct->order_uuid,
            'source' => 'rtdc',
        ])['data'])->first();

        $this->assertSame($encounterAxis, $rtdcPatient['imaging']);
        $this->assertSame($edAxis, $edPatient['imaging']);
        $this->assertSame($orderAxis, $radiologyRow['readiness'][0]);
        $this->assertTrue($radiologyRow['downstreamImpact']['dischargeBlocking']);
        $this->assertStringEndsWith('source=rtdc', $rtdcPatient['imaging']['drillHref']);
        $this->assertStringEndsWith('source=ed', $edPatient['imaging']['drillHref']);

        $ct->update(['terminal_at' => $this->now, 'current_state' => 'complete']);
        $completedAxis = $readiness->imagingForEncounters([$encounterId])->get($encounterId);
        $this->assertSame('ready', $completedAxis['state']);
        $this->assertFalse($completedAxis['blocking']);
        $this->assertSame(0, $completedAxis['pendingCount']);
    }

    public function test_stale_source_demotes_open_and_empty_scopes_to_unknown(): void
    {
        $order = AncillaryOrder::factory()->radiology()->dischargeBlocking()->create([
            'ordered_at' => $this->now->subMinutes(30),
            'source_cutoff_at' => $this->now->subMinutes(2),
        ]);
        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'stale']);

        $axes = app(AncillaryReadinessService::class)->imagingForOrders([(int) $order->ancillary_order_id, 999999]);

        $this->assertSame('unknown', $axes->get((int) $order->ancillary_order_id)['state']);
        $this->assertSame('stale', $axes->get((int) $order->ancillary_order_id)['freshness']['status']);
        $this->assertSame('unknown', $axes->get(999999)['state']);
        $this->assertNotSame('ready', $axes->get(999999)['state']);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function findPatient(array $payload, int $encounterId): array
    {
        return collect(['priority1', 'priority2', 'priority3', 'priority4'])
            ->flatMap(fn (string $key): array => $payload[$key])
            ->firstWhere('id', $encounterId);
    }
}
