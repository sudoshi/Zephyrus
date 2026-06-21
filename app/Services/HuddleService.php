<?php

namespace App\Services;

use App\Models\Huddle;
use App\Models\RtdcPrediction;
use Carbon\CarbonInterface;

class HuddleService
{
    public function openUnitHuddle(int $unitId, CarbonInterface|string $serviceDate, ?int $facilitatorId = null): Huddle
    {
        return Huddle::firstOrCreate(
            ['type' => 'unit', 'unit_id' => $unitId, 'service_date' => $serviceDate],
            ['status' => 'open', 'facilitator_id' => $facilitatorId],
        );
    }

    public function openHospitalHuddle(CarbonInterface|string $serviceDate, ?int $facilitatorId = null): Huddle
    {
        return Huddle::firstOrCreate(
            ['type' => 'hospital', 'unit_id' => null, 'service_date' => $serviceDate],
            ['status' => 'open', 'facilitator_id' => $facilitatorId],
        );
    }

    public function close(int $huddleId): Huddle
    {
        $huddle = Huddle::findOrFail($huddleId);
        $huddle->update(['status' => 'closed', 'closed_at' => now()]);

        return $huddle;
    }

    /**
     * Aggregate every unit's signed bed-need for the hospital bed meeting.
     *
     * @return array{net_bed_need:int,total_positive_bed_need:int,units:array}
     */
    public function hospitalRollup(CarbonInterface|string $serviceDate, string $horizon): array
    {
        $preds = RtdcPrediction::with('unit')
            ->whereDate('service_date', $serviceDate)
            ->where('horizon', $horizon)
            ->get();

        return [
            'net_bed_need' => (int) $preds->sum('bed_need'),
            'total_positive_bed_need' => (int) $preds->where('bed_need', '>', 0)->sum('bed_need'),
            'units' => $preds->map(fn (RtdcPrediction $p) => [
                'unit_id' => $p->unit_id,
                'unit_name' => $p->unit?->name,
                'bed_need' => $p->bed_need,
                'capacity_now' => $p->capacity_now,
                'demand_expected' => $p->demand_expected,
            ])->values()->all(),
        ];
    }
}
