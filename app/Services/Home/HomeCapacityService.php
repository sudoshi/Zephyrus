<?php

namespace App\Services\Home;

use App\Models\Bed;
use App\Models\Home\HomeEpisode;
use App\Models\RtdcPrediction;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * The decant valve, quantified (ACUM-PRD-HAH-001 §6.2): how many current ED
 * and inpatient patients are home-eligible, how many home slots are free now
 * and projected at 24/48h, and the avoided-bed-day ledger — the one-sentence
 * story no HaH vendor can tell ("14 boarders, 9 home-eligible, 6 slots free
 * tonight").
 *
 * writePredictions() lands the home slot forecast in prod.rtdc_predictions
 * alongside physical capacity (same unit/service_date/horizon upsert as
 * RtdcService), so the bed-meeting machinery sees the virtual ward without
 * modification.
 */
class HomeCapacityService
{
    public function __construct(private readonly HomeReferralService $referrals) {}

    /** @return array<string, mixed> */
    public function decant(): array
    {
        $ward = Unit::query()->where('type', 'virtual_home')->where('is_deleted', false)->orderBy('unit_id')->first();

        if ($ward === null) {
            return [
                'available' => false, 'freeSlotsNow' => 0, 'freeSlots24h' => 0, 'freeSlots48h' => 0,
                'edEligibleNow' => 0, 'stepDownEligibleNow' => 0, 'activeEpisodes' => 0, 'avoidedBedDaysMtd' => 0.0,
            ];
        }

        $freeNow = Bed::query()
            ->where('unit_id', $ward->unit_id)
            ->where('status', 'available')
            ->where('is_deleted', false)
            ->count();

        $acute = HomeEpisode::query()
            ->active()
            ->whereHas('program', fn ($q) => $q->where('program_type', 'ahcah_acute'));

        $discharging24h = (clone $acute)->where('expected_discharge_date', '<=', now()->addDay()->toDateString())->count();
        $discharging48h = (clone $acute)->where('expected_discharge_date', '<=', now()->addDays(2)->toDateString())->count();

        return [
            'available' => true,
            'freeSlotsNow' => $freeNow,
            'freeSlots24h' => $freeNow + $discharging24h,
            'freeSlots48h' => $freeNow + $discharging48h,
            'edEligibleNow' => count($this->referrals->edCandidates()),
            'stepDownEligibleNow' => count($this->referrals->stepDownCandidates()),
            'activeEpisodes' => (clone $acute)->count(),
            'avoidedBedDaysMtd' => $this->avoidedBedDaysMtd(),
        ];
    }

    /** Upsert the home forecast alongside physical capacity (deterministic, idempotent). */
    public function writePredictions(): void
    {
        $ward = Unit::query()->where('type', 'virtual_home')->where('is_deleted', false)->orderBy('unit_id')->first();
        if ($ward === null) {
            return;
        }

        $decant = $this->decant();
        $today = now()->toDateString();

        foreach (['by_2pm' => 'freeSlots24h', 'by_midnight' => 'freeSlots48h'] as $horizon => $key) {
            RtdcPrediction::updateOrCreate(
                ['unit_id' => $ward->unit_id, 'service_date' => $today, 'horizon' => $horizon],
                [
                    'discharges_probable' => max(0, $decant[$key] - $decant['freeSlotsNow']),
                    'discharges_weighted' => max(0, $decant[$key] - $decant['freeSlotsNow']),
                    'demand_ed' => $decant['edEligibleNow'],
                    'demand_transfer' => $decant['stepDownEligibleNow'],
                    'demand_expected' => $decant['edEligibleNow'] + $decant['stepDownEligibleNow'],
                    'capacity_now' => $decant['freeSlotsNow'],
                    // Negative bed_need = free decant capacity offered to the house.
                    'bed_need' => ($decant['edEligibleNow'] + $decant['stepDownEligibleNow']) - $decant[$key],
                ],
            );
        }
    }

    private function avoidedBedDaysMtd(): float
    {
        $row = DB::selectOne("
            SELECT COALESCE(SUM(
                EXTRACT(EPOCH FROM (LEAST(COALESCE(e.ended_at, now()), now()) - GREATEST(e.started_at, date_trunc('month', now())))) / 86400.0
            ), 0) AS days
            FROM prod.home_episodes e
            JOIN prod.home_programs p ON p.home_program_id = e.home_program_id
            WHERE e.is_deleted = false AND e.started_at IS NOT NULL AND e.started_at <= now()
              AND COALESCE(e.ended_at, now()) >= date_trunc('month', now())
              AND p.program_type = 'ahcah_acute'
        ");

        return round((float) ($row->days ?? 0), 1);
    }
}
