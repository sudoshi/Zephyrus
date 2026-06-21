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
        $this->seed(\Database\Seeders\RtdcSeeder::class);
        $dispatcher = app(EventDispatcher::class);
        $source = new SyntheticEventSource(SimulatorConfig::default(), seed: 42);

        foreach ($source->pull() as $event) {
            $dispatcher->dispatch($event);
        }

        $firstRunActive = Encounter::active()->count();
        $this->assertGreaterThan(0, $firstRunActive);
    }
}
