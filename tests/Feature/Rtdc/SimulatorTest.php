<?php

namespace Tests\Feature\Rtdc;

use App\Models\Encounter;
use App\Models\Unit;
use App\Rtdc\EventDispatcher;
use App\Rtdc\Simulator\SimulatorConfig;
use App\Rtdc\Simulator\SyntheticEventSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimulatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_default_unit_mix_creates_units_and_beds(): void
    {
        $this->seed(\Database\Seeders\RtdcSeeder::class);
        $this->assertEquals(25, Unit::count()); // full Summit Regional roster: 23 inpatient + ED + perioperative
        $this->assertGreaterThan(0, \App\Models\Bed::count());
    }

    public function test_simulator_with_fixed_seed_is_deterministic(): void
    {
        // Same seed twice (DB re-seeded between runs) must yield the IDENTICAL
        // operational_events type sequence — this is the key reproducibility property.
        $typesA = $this->runSimulator(seed: 42);
        $fingerprint42 = $this->lastFingerprint; // run-42 selection fingerprint (captured from run A)
        $typesB = $this->runSimulator(seed: 42);

        $this->assertNotEmpty($typesA);
        $this->assertSame($typesA, $typesB, 'Same seed must produce an identical event-type stream');

        // Seed sensitivity shows up in WHICH bed/encounter is selected — a different seed
        // must drive different selections than the run-42 fingerprint captured above.
        $this->runSimulator(seed: 7);
        $this->assertNotSame($fingerprint42, $this->lastFingerprint, 'A different seed should drive different selections');
    }

    /** @var list<string> the selection fingerprint (type|bed_id) of the last run */
    private array $lastFingerprint = [];

    /**
     * Run the simulator end-to-end against a freshly seeded DB. Returns the
     * operational_events `type` sequence ordered by operational_event_id, and
     * records a seed-sensitive fingerprint (type|bed_id) in $this->lastFingerprint.
     *
     * @return list<string>
     */
    private function runSimulator(int $seed): array
    {
        // Light reset between runs (no migrate:fresh): RtdcSeeder is idempotent, so this
        // ensures the full Summit roster exists, then clears the simulator's only output
        // surface (operational_events / encounters / census_snapshots) and restores bed
        // availability. Avoids accumulating migrate:fresh DDL locks inside RefreshDatabase's
        // transaction — which overflowed max_locks_per_transaction at the 25-unit/692-bed
        // scale — and the schema re-bootstrap fragility of repeated migrate:fresh on the test DB.
        $this->seed(\Database\Seeders\RtdcSeeder::class);
        \App\Models\OperationalEvent::query()->delete();
        \App\Models\CensusSnapshot::query()->delete();
        Encounter::query()->delete();
        \App\Models\Bed::query()->update(['status' => 'available']);

        $dispatcher = app(EventDispatcher::class);
        // Small config: determinism is scale-independent, so we avoid seeding 70% of all
        // 692 Summit beds (~485 encounters/run). A light scenario exercises the same code
        // paths in a fraction of the time.
        $config = new SimulatorConfig(initialOccupancyPercent: 12, admitsPerTick: 2, dischargesPerTick: 1, ticks: 6);
        $source = new SyntheticEventSource($config, seed: $seed);

        foreach ($source->pull() as $event) {
            $dispatcher->dispatch($event);
        }

        $rows = \App\Models\OperationalEvent::orderBy('operational_event_id')->get(['type', 'payload']);

        $this->lastFingerprint = $rows
            ->map(fn ($r) => $r->type.'|'.($r->payload['bed_id'] ?? ''))
            ->all();

        return $rows->pluck('type')->all();
    }
}
