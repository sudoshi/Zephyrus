<?php

namespace App\Services\Pharmacy;

use App\Models\Pharmacy\AdcStation;
use App\Models\Pharmacy\AdcTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Station/unit-level ADC operational rollups. Every aggregate is grouped by
 * station or unit only — no user, actor, or individual dimension exists in
 * any query, and none may ever be added (§13: unit/station aggregates only,
 * no individual diversion scoring).
 */
final class AdcStationSignalService
{
    /**
     * Transaction counts per station and type inside the window, with the
     * controlled-substance subtotal alongside.
     *
     * @return Collection<int, object{adc_station_id: int, transaction_type: string, transaction_count: int, controlled_count: int}>
     */
    public function stationRollup(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->rollup('adc_station_id', $from, $to);
    }

    /**
     * Transaction counts per unit and type inside the window.
     *
     * @return Collection<int, object{unit_id: int, transaction_type: string, transaction_count: int, controlled_count: int}>
     */
    public function unitRollup(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->rollup('unit_id', $from, $to);
    }

    /**
     * Controlled discrepancies opened without a matching resolve, per station.
     *
     * @return Collection<int, object{adc_station_id: int, open_count: int, oldest_opened_at: string}>
     */
    public function openDiscrepancies(): Collection
    {
        return AdcTransaction::query()
            ->openDiscrepancies()
            ->groupBy('adc_station_id')
            ->orderBy('adc_station_id')
            ->get([
                'adc_station_id',
                DB::raw('count(*)::int as open_count'),
                DB::raw('min(occurred_at)::text as oldest_opened_at'),
            ]);
    }

    /**
     * Stations carrying at least one open stockout, with the per-medication
     * open map maintained by the projector.
     *
     * @return Collection<int, AdcStation>
     */
    public function activeStockouts(): Collection
    {
        return AdcStation::query()
            ->whereRaw("coalesce(metadata->'open_stockouts', '{}'::jsonb) NOT IN ('{}'::jsonb, '[]'::jsonb)")
            ->orderBy('adc_station_id')
            ->get();
    }

    /** @return Collection<int, object> */
    private function rollup(string $dimension, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return AdcTransaction::query()
            ->whereNotNull($dimension)
            ->whereBetween('occurred_at', [$from, $to])
            ->groupBy($dimension, 'transaction_type')
            ->orderBy($dimension)
            ->orderBy('transaction_type')
            ->get([
                $dimension,
                'transaction_type',
                DB::raw('count(*)::int as transaction_count'),
                DB::raw('(count(*) filter (where is_controlled))::int as controlled_count'),
            ]);
    }
}
