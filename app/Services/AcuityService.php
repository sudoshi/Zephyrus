<?php

namespace App\Services;

use App\Models\Encounter;
use App\Models\Unit;

/**
 * Acuity-adjusted capacity. Research §4: capacity to admit is gated on
 * nurse-safety-to-accept, not bed availability alone. Tier weights approximate
 * relative nursing workload (tier 4 ~ double tier 1).
 */
class AcuityService
{
    private const TIER_WEIGHTS = [1 => 1.0, 2 => 1.3, 3 => 1.7, 4 => 2.2];

    public function tierWeight(int $tier): float
    {
        return self::TIER_WEIGHTS[$tier] ?? 1.0;
    }

    /**
     * The number of additional patients a unit can safely admit right now.
     */
    public function adjustedCapacity(int $unitId): int
    {
        $unit = Unit::findOrFail($unitId);

        // Nursing workload budget = staffed beds expressed as "standard (tier-1) patient equivalents".
        // A unit staffed for N beds at its ratio can carry N standard patients.
        $workloadBudget = (float) $unit->staffed_bed_count;

        $currentLoad = Encounter::active()->where('unit_id', $unitId)->get()
            ->sum(fn (Encounter $e) => $this->tierWeight($e->acuity_tier));

        $remaining = $workloadBudget - $currentLoad;

        // Convert remaining workload back to standard-patient slots, never negative.
        return max(0, (int) floor($remaining / $this->tierWeight(1)));
    }

    /**
     * Remaining nursing workload budget (in tier-1-equivalent units) on a unit.
     */
    public function remainingWorkload(int $unitId): float
    {
        $unit = \App\Models\Unit::findOrFail($unitId);
        $currentLoad = \App\Models\Encounter::active()->where('unit_id', $unitId)->get()
            ->sum(fn (\App\Models\Encounter $e) => $this->tierWeight($e->acuity_tier));

        return (float) $unit->staffed_bed_count - $currentLoad;
    }

    /**
     * Can the unit safely accept one more patient at the given acuity tier
     * without exceeding its nursing workload budget? (Nurse-safety-to-accept.)
     */
    public function canAccept(int $unitId, int $acuityTier): bool
    {
        return $this->remainingWorkload($unitId) >= $this->tierWeight($acuityTier);
    }
}
