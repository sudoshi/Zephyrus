<?php

namespace Tests\Feature\Demo;

use App\Services\Demo\DemoClock;
use App\Services\Demo\DemoInvariantService;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoTuningSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rolling demo-refresh transport SLA drift (2026-07-20). The refresh re-seeds
 * via CommandCenterDemoSeeder, never OperationalDemoDataService::rollForward,
 * so transport `needed_at` values were frozen at their seed time and every
 * active request eventually drifted past SLA — prod showed 100% overdue. The
 * DemoTuningSeeder SLA refresh now resets them deterministically to a ~20%
 * overdue cohort, matching plausibility_targets.transport_overdue_share_max.
 */
class TransportSlaRefreshTest extends TestCase
{
    use RefreshDatabase;

    private function seedActiveTransport(int $count, CarbonImmutable $anchor): void
    {
        // Routine-dominant mix (7 routine / 2 urgent / 1 stat per 10) so the
        // priority-mix invariant is not the thing under test; every row is
        // ACTIVE (completed_at null) and starts OVERDUE (needed_at in the past),
        // reproducing the drift.
        $priorities = ['routine', 'routine', 'urgent', 'routine', 'stat', 'routine', 'urgent', 'routine', 'routine', 'routine'];

        for ($i = 0; $i < $count; $i++) {
            DB::table('prod.transport_requests')->insert([
                'request_uuid' => (string) Str::uuid(),
                'request_type' => 'inpatient',
                'priority' => $priorities[$i % count($priorities)],
                'status' => 'requested',
                'patient_ref' => 'sla-drift-'.$i,
                'origin' => 'Unit A',
                'destination' => 'CT Scanner 2',
                'transport_mode' => 'stretcher',
                'clinical_service' => 'Medicine',
                'requested_by' => 'demo-seeder',
                'requested_at' => $anchor->subHours(2),
                'needed_at' => $anchor->subMinutes(30 + $i), // all overdue
                'completed_at' => null,
                'is_deleted' => false,
                'created_at' => $anchor->subHours(2),
                'updated_at' => $anchor->subHours(2),
            ]);
        }
    }

    private function overdueFinding(CarbonImmutable $anchor): array
    {
        return collect(app(DemoInvariantService::class)->run(new DemoClock($anchor)))
            ->firstWhere('key', 'plausibility.transport_overdue_share');
    }

    public function test_tuning_refresh_recovers_a_fully_overdue_transport_queue(): void
    {
        $anchor = CarbonImmutable::now();
        $this->seedActiveTransport(20, $anchor);

        // Drifted state: the whole active queue has breached SLA.
        $before = $this->overdueFinding($anchor);
        $this->assertNotNull($before);
        $this->assertFalse($before['passed'], 'a fully overdue queue must fail the invariant');
        $this->assertStringContainsString('100%', $before['observed']);

        // The rolling refresh's SLA reset runs here.
        $this->seed(DemoTuningSeeder::class);

        $after = $this->overdueFinding(CarbonImmutable::now());
        $this->assertTrue($after['passed'], "overdue share must fall back within SLA — got {$after['observed']}");
        // Deterministic every-fifth cohort → 4 of 20.
        $this->assertStringContainsString('4/20 active overdue', $after['observed']);
    }
}
