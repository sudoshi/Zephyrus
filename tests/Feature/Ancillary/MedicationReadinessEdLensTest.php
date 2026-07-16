<?php

declare(strict_types=1);

namespace Tests\Feature\Ancillary;

use App\Models\Ancillary\AncillaryOrder;
use App\Models\Pharmacy\MedicationOrder;
use App\Services\Ancillary\AncillaryReadinessService;
use App\Services\Ed\TreatmentService;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Database\Seeders\RtdcSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MedicationReadinessEdLensTest extends TestCase
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
                'source_label' => 'Pharmacy operational feeds',
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

    public function test_medication_axis_joins_the_correct_boarded_ed_visit_and_blocks_on_a_time_critical_dose(): void
    {
        $patientRef = 'x11-boarded-ed-patient';
        $boardedEdVisitId = $this->edVisit($patientRef, 'admitted');
        $otherEdVisitId = $this->edVisit('x11-other-ed-patient', null);

        // A boarded ED patient's open sepsis antibiotic — a time-critical dose.
        // The ED linkage lives on ancillary_orders.metadata->>'ed_visit_id'.
        $order = AncillaryOrder::factory()->pharmacy()->create([
            'patient_ref' => $patientRef,
            'patient_class' => 'emergency',
            'priority' => 'sepsis',
            'ordered_at' => $this->now->subMinutes(55),
            'source_cutoff_at' => $this->now->subMinutes(2),
            'current_state' => 'verified',
            'metadata' => ['ed_visit_id' => $boardedEdVisitId],
        ]);
        MedicationOrder::factory()->sepsis()->create([
            'ancillary_order_id' => $order->ancillary_order_id,
            'source_id' => $order->source_id,
            'order_status' => 'verified',
        ]);

        // An unrelated open routine order tied to a DIFFERENT ED visit must not
        // leak into the boarded visit's axis (correct-encounter join proof).
        $otherOrder = AncillaryOrder::factory()->pharmacy()->create([
            'patient_ref' => 'x11-other-ed-patient',
            'patient_class' => 'emergency',
            'priority' => 'routine',
            'ordered_at' => $this->now->subMinutes(20),
            'source_cutoff_at' => $this->now->subMinutes(2),
            'metadata' => ['ed_visit_id' => $otherEdVisitId],
        ]);
        MedicationOrder::factory()->create([
            'ancillary_order_id' => $otherOrder->ancillary_order_id,
            'source_id' => $otherOrder->source_id,
            'order_status' => 'ordered',
        ]);

        $readiness = app(AncillaryReadinessService::class);
        $axes = $readiness->medicationForEdVisits([$boardedEdVisitId, $otherEdVisitId]);
        $boarded = $axes->get($boardedEdVisitId);
        $other = $axes->get($otherEdVisitId);

        $this->assertSame('medication', $boarded['key']);
        $this->assertSame('blocked', $boarded['state']);
        $this->assertTrue($boarded['blocking']);
        $this->assertSame(1, $boarded['pendingCount']);
        $this->assertSame(55, $boarded['oldestAgeMinutes']);
        $this->assertSame($order->order_uuid, $boarded['topOrderUuid']);
        $this->assertStringEndsWith('source=ed', $boarded['drillHref']);

        // The other ED visit gets ONLY its own routine order — not the sepsis one.
        $this->assertSame('pending', $other['state']);
        $this->assertFalse($other['blocking']);
        $this->assertSame(1, $other['pendingCount']);
        $this->assertSame(20, $other['oldestAgeMinutes']);

        // The ED Treatment board surfaces the same axis on the same board row.
        $board = collect(app(TreatmentService::class)->build()['board']);
        $boardedRow = $board->firstWhere('edVisitId', $boardedEdVisitId);
        $this->assertSame($boarded, $boardedRow['medication']);
    }

    public function test_administered_or_wrong_visit_orders_do_not_block_and_stale_source_is_unknown(): void
    {
        $edVisitId = $this->edVisit('x11-ready-ed-patient', 'admitted');

        // An administered order is terminal — it is not an open delay.
        $administered = AncillaryOrder::factory()->pharmacy()->create([
            'ordered_at' => $this->now->subMinutes(120),
            'source_cutoff_at' => $this->now->subMinutes(2),
            'metadata' => ['ed_visit_id' => $edVisitId],
        ]);
        MedicationOrder::factory()->create([
            'ancillary_order_id' => $administered->ancillary_order_id,
            'source_id' => $administered->source_id,
            'order_status' => 'administered',
        ]);

        $readiness = app(AncillaryReadinessService::class);
        $ready = $readiness->medicationForEdVisits([$edVisitId])->get($edVisitId);
        $this->assertSame('ready', $ready['state']);
        $this->assertSame(0, $ready['pendingCount']);
        $this->assertFalse($ready['blocking']);

        // A stale source demotes every scope (open or empty) to unknown; nothing
        // can render ready off stale evidence.
        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'stale']);
        $open = AncillaryOrder::factory()->pharmacy()->create([
            'ordered_at' => $this->now->subMinutes(30),
            'source_cutoff_at' => $this->now->subMinutes(2),
            'metadata' => ['ed_visit_id' => $edVisitId],
        ]);
        MedicationOrder::factory()->stat()->create([
            'ancillary_order_id' => $open->ancillary_order_id,
            'source_id' => $open->source_id,
            'order_status' => 'ordered',
        ]);

        $staleAxes = $readiness->medicationForEdVisits([$edVisitId, 999999]);
        $this->assertSame('unknown', $staleAxes->get($edVisitId)['state']);
        $this->assertSame('stale', $staleAxes->get($edVisitId)['freshness']['status']);
        $this->assertSame('unknown', $staleAxes->get(999999)['state']);
        $this->assertNotSame('ready', $staleAxes->get(999999)['state']);
    }

    private function edVisit(string $patientRef, ?string $disposition): int
    {
        return (int) DB::table('prod.ed_visits')->insertGetId([
            'patient_ref' => $patientRef,
            'arrived_at' => $this->now->subHours(4),
            'triaged_at' => $this->now->subHours(3)->subMinutes(50),
            'esi_level' => 2,
            'provider_seen_at' => $this->now->subHours(3),
            'disposition' => $disposition,
            'admit_decision_at' => $disposition === 'admitted' ? $this->now->subHours(2) : null,
            'created_at' => $this->now,
            'updated_at' => $this->now,
            'is_deleted' => false,
        ], 'ed_visit_id');
    }
}
