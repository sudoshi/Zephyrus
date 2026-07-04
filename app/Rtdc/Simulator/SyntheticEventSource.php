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
        private readonly ?\Carbon\CarbonInterface $startAt = null,
    ) {}

    public function pull(): iterable
    {
        mt_srand($this->seed);
        $now = ($this->startAt?->copy() ?? now()->startOfDay()->addHours(6));

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
                // Seed-controlled selection: order by primary key (stable) and pick an
                // index via mt_rand(), which IS governed by the mt_srand($this->seed)
                // above. inRandomOrder() uses the DB RNG and would NOT be reproducible.
                $enc = $this->pickDeterministic(
                    Encounter::active()->orderBy('encounter_id')->pluck('encounter_id'),
                    fn (int $id) => Encounter::active()->whereKey($id)->first(),
                );
                if ($enc) {
                    yield CanonicalEvent::encounterDischarged($enc->patient_ref, $tickTime);
                }
            }

            for ($a = 0; $a < $this->config->admitsPerTick; $a++) {
                $bed = $this->pickDeterministic(
                    Bed::available()->orderBy('bed_id')->pluck('bed_id'),
                    fn (int $id) => Bed::available()->whereKey($id)->first(),
                );
                if ($bed) {
                    yield CanonicalEvent::encounterStarted($this->nextPatient(), $bed->unit_id, $this->randomAcuity($bed->unit->type), $tickTime, $bed->bed_id);
                }
            }
        }
    }

    /**
     * Deterministically select one row from a collection of primary keys using
     * the seeded mt_rand(), then re-resolve it through the given scope. Returns
     * null when there are no candidates.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $ids
     * @param  callable(int): ?\Illuminate\Database\Eloquent\Model  $resolve
     */
    private function pickDeterministic($ids, callable $resolve)
    {
        if ($ids->isEmpty()) {
            return null;
        }

        $id = $ids[mt_rand(0, $ids->count() - 1)];

        return $resolve($id);
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
