<?php

namespace App\Support;

/**
 * The 24h surge-probability heuristic — ONE definition shared by the
 * Command Center forecast band and the Flow Window projection stream so the
 * two surfaces can never disagree about surge risk for the same state.
 *
 * A documented heuristic, not a trained model: four additive pressure terms
 * clamped to 0–95%.
 *
 *   occupancy   (occupancy% − 80) × 4        strain above the 80% line
 *   bed         −netBedsNow × 5               current net bed deficit
 *                                             (netBedsNow = available − pending admits)
 *   demand      (predicted admits − weighted discharges) × 2
 *   reliability (1 − avg reliability) × 20    distrust of the forecast itself
 */
final class SurgeHeuristic
{
    /**
     * @return array{surge_pct: int, occupancy_pressure: int, bed_pressure: int, demand_pressure: int, reliability_pressure: int}
     */
    public static function pressures(
        int $occupancyPct,
        int $netBedsNow,
        int $predAdmissions,
        float $weightedDischarges,
        float $avgReliability,
    ): array {
        $occupancyPressure = max(0, (int) round(($occupancyPct - 80) * 4));
        $bedPressure = max(0, -$netBedsNow * 5);
        $demandPressure = max(0, (int) round(max(0, $predAdmissions - $weightedDischarges) * 2));
        $reliabilityPressure = max(0, (int) round((1 - $avgReliability) * 20));

        return [
            'surge_pct' => (int) max(0, min(95,
                $occupancyPressure + $bedPressure + $demandPressure + $reliabilityPressure
            )),
            'occupancy_pressure' => $occupancyPressure,
            'bed_pressure' => $bedPressure,
            'demand_pressure' => $demandPressure,
            'reliability_pressure' => $reliabilityPressure,
        ];
    }
}
