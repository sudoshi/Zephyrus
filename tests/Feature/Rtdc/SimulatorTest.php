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
        $this->assertEquals(6, Unit::count()); // ED + 3 med/surg + ICU + step-down
        $this->assertGreaterThan(0, \App\Models\Bed::count());
    }

    public function test_simulator_with_fixed_seed_is_deterministic(): void
    {
        // Same seed twice (DB re-seeded between runs) must yield the IDENTICAL
        // operational_events type sequence — this is the key reproducibility property.
        $typesA = $this->runSimulator(seed: 42);
        $typesB = $this->runSimulator(seed: 42);

        $this->assertNotEmpty($typesA);
        $this->assertSame($typesA, $typesB, 'Same seed must produce an identical event-type stream');

        // The type sequence is structurally fixed by config, so seed sensitivity shows
        // up in WHICH bed/encounter is selected. Capture the run-42 fingerprint, then
        // assert a different seed drives different selections.
        $this->runSimulator(seed: 42);
        $fingerprint42 = $this->lastFingerprint;

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
        $this->artisan('migrate:fresh');
        $this->seed(\Database\Seeders\RtdcSeeder::class);

        $dispatcher = app(EventDispatcher::class);
        $source = new SyntheticEventSource(SimulatorConfig::default(), seed: $seed);

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
