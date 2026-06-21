<?php

namespace App\Services;

use App\Models\Encounter;
use App\Models\RtdcPrediction;
use App\Models\RtdcReconciliation;
use Carbon\CarbonInterface;

/**
 * RTDC Step 4 — evaluate yesterday's plan. Reconciles predicted vs actual
 * discharges and computes per-unit prediction reliability (research §2: the
 * learning loop; reliability is a headline KPI).
 */
class ReconciliationService
{
    public function reconcile(int $unitId, CarbonInterface|string $serviceDate): RtdcReconciliation
    {
        $date = \Illuminate\Support\Carbon::parse($serviceDate)->toDateString();

        $predictedDischarges = (float) RtdcPrediction::where('unit_id', $unitId)
            ->whereDate('service_date', $date)
            ->max('discharges_weighted') ?? 0.0;

        $predictedAdmissions = (int) RtdcPrediction::where('unit_id', $unitId)
            ->whereDate('service_date', $date)
            ->max('demand_expected') ?? 0;

        $actualDischarges = Encounter::where('unit_id', $unitId)
            ->where('status', 'discharged')
            ->whereDate('discharged_at', $date)
            ->count();

        $actualAdmissions = Encounter::where('unit_id', $unitId)
            ->whereDate('admitted_at', $date)
            ->count();

        return RtdcReconciliation::updateOrCreate(
            ['unit_id' => $unitId, 'service_date' => $date],
            [
                'predicted_discharges' => $predictedDischarges,
                'actual_discharges' => $actualDischarges,
                'predicted_admissions' => $predictedAdmissions,
                'actual_admissions' => $actualAdmissions,
                'reliability_score' => $this->reliability($predictedDischarges, $actualDischarges),
            ],
        );
    }

    private function reliability(float $predicted, int $actual): ?float
    {
        $max = max($predicted, $actual);
        if ($max <= 0) {
            return null; // nothing predicted or happened — undefined
        }

        return round(1 - abs($predicted - $actual) / $max, 4);
    }
}
