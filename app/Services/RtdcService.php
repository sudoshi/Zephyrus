<?php

namespace App\Services;

use App\Models\Bed;
use App\Models\RtdcPrediction;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * The IHI Real-Time Demand Capacity four-step engine.
 * Step 1 predict capacity, Step 2 predict demand, Step 3 develop plan,
 * Step 4 (evaluate) lives in ReconciliationService.
 */
class RtdcService
{
    /** Confidence weights for predicted discharges (research §2). */
    private const WEIGHT_DEFINITE = 1.0;

    private const WEIGHT_PROBABLE = 0.6;

    private const WEIGHT_POSSIBLE = 0.3;

    public function activateWorkflow(Request $request): void
    {
        $request->session()->put('workflow', 'rtdc');
    }

    /** Step 1 — predict capacity (clinician-entered discharge tiers). */
    public function upsertCapacity(int $unitId, CarbonInterface|string $serviceDate, string $horizon, int $definite, int $probable, int $possible): RtdcPrediction
    {
        $weighted = $definite * self::WEIGHT_DEFINITE
            + $probable * self::WEIGHT_PROBABLE
            + $possible * self::WEIGHT_POSSIBLE;

        return $this->prediction($unitId, $serviceDate, $horizon, [
            'discharges_definite' => $definite,
            'discharges_probable' => $probable,
            'discharges_possible' => $possible,
            'discharges_weighted' => round($weighted, 2),
        ]);
    }

    /** Step 2 — predict demand by source. */
    public function upsertDemand(int $unitId, CarbonInterface|string $serviceDate, string $horizon, int $ed, int $or, int $transfer, int $direct): RtdcPrediction
    {
        return $this->prediction($unitId, $serviceDate, $horizon, [
            'demand_ed' => $ed,
            'demand_or' => $or,
            'demand_transfer' => $transfer,
            'demand_direct' => $direct,
            'demand_expected' => $ed + $or + $transfer + $direct,
        ]);
    }

    /** Step 3 — develop plan: compute the signed bed-need integer. */
    public function developPlan(int $unitId, CarbonInterface|string $serviceDate, string $horizon): RtdcPrediction
    {
        $pred = $this->prediction($unitId, $serviceDate, $horizon, []);

        $available = Bed::where('unit_id', $unitId)->where('status', 'available')->where('is_deleted', false)->count();
        $effectiveCapacity = $available + (int) floor($pred->discharges_weighted);
        $bedNeed = $pred->demand_expected - $effectiveCapacity;

        $pred->update([
            'capacity_now' => $available,
            'bed_need' => $bedNeed,
        ]);

        return $pred->fresh();
    }

    private function prediction(int $unitId, CarbonInterface|string $serviceDate, string $horizon, array $attrs): RtdcPrediction
    {
        return RtdcPrediction::updateOrCreate(
            ['unit_id' => $unitId, 'service_date' => $serviceDate, 'horizon' => $horizon],
            $attrs,
        );
    }
}
