<?php

namespace App\Rtdc\Simulator;

use App\Models\Bed;
use App\Models\Encounter;
use App\Models\Unit;
use App\Rtdc\Contracts\EventSource;
use App\Rtdc\Events\CanonicalEvent;
use Illuminate\Support\Str;

/**
 * Generates a realistic-enough operational event stream for demo/CI.
 * Deterministic given a fixed seed. Implements the same EventSource contract
 * the HL7v2/FHIR adapters will implement in S1/S8.
 */
class SyntheticEventSource implements EventSource
{
    private int $patientCounter = 0;

    public function __construct(
        private readonly SimulatorConfig $config,
        private readonly int $seed = 0,
    ) {}

    public function pull(): iterable
    {
        mt_srand($this->seed);
        $now = now()->startOfDay()->addHours(6);

        // Seed initial occupancy.
        foreach (Unit::all() as $unit) {
            $target = (int) floor($unit->staffed_bed_count * $this->config->initialOccupancyPercent / 100);
            $freeBeds = Bed::where('unit_id', $unit->unit_id)->where('status', 'available')->limit($target)->pluck('bed_id');
            foreach ($freeBeds as $bedId) {
                yield CanonicalEvent::encounterStarted($this->nextPatient(), $unit->unit_id, $this->randomAcuity($unit->type), $now, $bedId);
            }
        }

        // Diurnal-ish flow across ticks.
        for ($t = 0; $t < $this->config->ticks; $t++) {
            $tickTime = $now->copy()->addHours($t);

            for ($d = 0; $d < $this->config->dischargesPerTick; $d++) {
                $enc = Encounter::active()->inRandomOrder()->first();
                if ($enc) {
                    yield CanonicalEvent::encounterDischarged($enc->patient_ref, $tickTime);
                }
            }

            for ($a = 0; $a < $this->config->admitsPerTick; $a++) {
                $bed = Bed::available()->inRandomOrder()->first();
                if ($bed) {
                    yield CanonicalEvent::encounterStarted($this->nextPatient(), $bed->unit_id, $this->randomAcuity($bed->unit->type), $tickTime, $bed->bed_id);
                }
            }
        }
    }

    private function nextPatient(): string
    {
        return 'sim-'.(++$this->patientCounter).'-'.Str::random(4);
    }

    private function randomAcuity(string $unitType): int
    {
        return match ($unitType) {
            'icu' => mt_rand(3, 4),
            'step_down' => mt_rand(2, 3),
            'ed' => mt_rand(2, 4),
            default => mt_rand(1, 3),
        };
    }
}
