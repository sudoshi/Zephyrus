<?php

namespace Tests\Feature\Cockpit;

use App\Services\Cockpit\SnapshotBuilder;
use App\Services\Evs\EvsOperationsService;
use App\Services\Transport\TransportOperationsService;
use Database\Seeders\CockpitKpiDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Zephyrus 2.0 P7 (Flow) — the last Flow mock retired. Discharge-lounge
 * census computes from prod.discharge_lounge_stays; bed turnaround is the
 * EVS service's avg/p90 over today's completed turns; the transport wait is
 * the precomputed end-to-end request→pickup measure over a bounded window.
 */
class FlowLiveSourcesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(SnapshotBuilder::CACHE_KEY);
        $this->seed(CockpitKpiDefinitionSeeder::class);
    }

    public function test_discharge_lounge_census_is_live_from_stays(): void
    {
        $insert = fn (array $overrides) => DB::table('prod.discharge_lounge_stays')->insert($overrides + [
            'stay_uuid' => (string) Str::uuid(),
            'patient_ref' => 'test-lounge-'.Str::random(6),
            'arrived_at' => now()->subMinutes(40),
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $insert([]);
        $insert([]);
        $insert(['departed_at' => now()->subMinutes(5)]); // picked up — not census
        $insert(['is_deleted' => true]);                  // deleted — not census

        $payload = app(SnapshotBuilder::class)->build();
        $lounge = collect($payload['domains']['flow']['tiles'])->firstWhere('key', 'flow.discharge_lounge');

        $this->assertNotNull($lounge);
        $this->assertSame(2.0, $lounge['value']);
        $this->assertSame('of 10 lounge chairs', $lounge['sub']);
        // The point of P7: no demo provenance on the lounge tile.
        $this->assertNull($lounge['metadata']['provenance'] ?? null);
    }

    public function test_evs_turnaround_stats_compute_avg_and_p90_over_todays_completed_turns(): void
    {
        $completedAt = Carbon::today()->addHours(12);
        $turn = fn (int $spanMinutes, array $overrides = []) => DB::table('prod.evs_requests')->insert($overrides + [
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'bed_clean',
            'priority' => 'routine',
            'status' => 'completed',
            'location_label' => 'TEST-01',
            'requested_at' => $completedAt->copy()->subMinutes($spanMinutes),
            'completed_at' => $completedAt,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $turn(30);
        $turn(60);
        $turn(300, ['completed_at' => now()->subDays(2)]);      // yesterday — excluded
        $turn(500, ['request_type' => 'terminal_clean']);       // not a bed turn — excluded
        $turn(45, ['status' => 'in_progress', 'completed_at' => null]); // not completed

        $stats = app(EvsOperationsService::class)->turnaroundStats();

        $this->assertSame(45.0, $stats['avg_min']);
        $this->assertSame(57.0, $stats['p90_min']); // percentile_cont(0.9) of [30, 60]
        $this->assertSame(2, $stats['completed']);
    }

    public function test_transport_measures_are_window_bounded_and_carry_end_to_end_wait(): void
    {
        $request = function (int $daysAgo, int $waitMinutes): void {
            $requestedAt = now()->subDays($daysAgo);
            $id = DB::table('prod.transport_requests')->insertGetId([
                'request_uuid' => (string) Str::uuid(),
                'request_type' => 'inpatient',
                'priority' => 'routine',
                'status' => 'completed',
                'patient_ref' => 'test-tx-'.Str::random(6),
                'origin' => 'A',
                'destination' => 'B',
                'requested_at' => $requestedAt,
                'is_deleted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ], 'transport_request_id');

            foreach ([
                ['transport.requested', 0],
                ['transport.assigned', (int) round($waitMinutes / 3)],
                ['transport.en_route', (int) round($waitMinutes / 2)],
                ['transport.arrived', $waitMinutes],
                ['transport.completed', $waitMinutes + 12],
            ] as [$type, $offset]) {
                DB::table('prod.transport_events')->insert([
                    'event_uuid' => (string) Str::uuid(),
                    'transport_request_id' => $id,
                    'event_type' => $type,
                    'occurred_at' => $requestedAt->copy()->addMinutes($offset),
                    'created_at' => now(),
                ]);
            }
        };

        $request(0, 24);   // inside the 7-day window
        $request(30, 240); // ancient — must NOT drag the average

        $measures = collect(app(TransportOperationsService::class)->measures())->keyBy('key');
        $endToEnd = $measures->get('request_to_pickup_min');

        $this->assertNotNull($endToEnd);
        $this->assertSame(24.0, (float) $endToEnd['value']);
        $this->assertSame('1 picked up', $endToEnd['caption']);
    }

    public function test_transport_wait_average_preserves_whole_second_precision(): void
    {
        foreach ([3690, 3691] as $index => $waitSeconds) {
            $requestedAt = now()->subHours(3)->addMinutes($index);
            $id = DB::table('prod.transport_requests')->insertGetId([
                'request_uuid' => (string) Str::uuid(),
                'request_type' => 'inpatient',
                'priority' => 'routine',
                'status' => 'completed',
                'patient_ref' => 'test-second-precision-'.$index,
                'origin' => 'A',
                'destination' => 'B',
                'requested_at' => $requestedAt,
                'is_deleted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ], 'transport_request_id');

            foreach ([
                ['transport.requested', 0],
                ['transport.arrived', $waitSeconds],
                ['transport.completed', $waitSeconds + 60],
            ] as [$type, $offsetSeconds]) {
                DB::table('prod.transport_events')->insert([
                    'event_uuid' => (string) Str::uuid(),
                    'transport_request_id' => $id,
                    'event_type' => $type,
                    'occurred_at' => $requestedAt->copy()->addSeconds($offsetSeconds),
                    'created_at' => now(),
                ]);
            }
        }

        $measure = collect(app(TransportOperationsService::class)->measures())
            ->firstWhere('key', 'request_to_pickup_min');

        $this->assertEqualsWithDelta(61.508333333333, $measure['value'], 0.0000001);
        $this->assertSame(
            '1 hr 1 min 31 sec',
            \App\Support\Operations\DurationFormatter::minutes($measure['value']),
        );
    }
}
