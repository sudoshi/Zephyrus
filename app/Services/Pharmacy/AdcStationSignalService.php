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
     * OPEN controlled discrepancies (opened without a matching resolve),
     * one row per open discrepancy, carrying only operational dimensions:
     * the owning station and unit, the opened-at timestamp, the pseudonymous
     * discrepancy key, and the medication label from metadata. NO user, actor,
     * staff, or individual dimension exists — adc_transactions carries none, and
     * none is projected here. Aging against the shift-end policy is computed by
     * the caller from opened_at; this service only pairs open with resolved.
     *
     * @return Collection<int, object{adc_station_id: int, unit_id: ?int, discrepancy_key: string, opened_at: string, is_controlled: bool, medication_label: ?string}>
     */
    public function openDiscrepancyDetails(): Collection
    {
        return AdcTransaction::query()
            ->openDiscrepancies()
            ->orderBy('occurred_at')
            ->get([
                'adc_station_id',
                'unit_id',
                'discrepancy_key',
                DB::raw('occurred_at::text as opened_at'),
                'is_controlled',
                DB::raw("metadata->>'medication_label' as medication_label"),
            ]);
    }

    /**
     * CONTROLLED-only transaction counts per station and type inside the
     * window. Every row is grouped by station and transaction type only —
     * this is the diversion-adjacent pattern surface (§13), and it carries no
     * user, actor, or individual dimension by construction.
     *
     * @return Collection<int, object{adc_station_id: int, unit_id: ?int, transaction_type: string, transaction_count: int}>
     */
    public function controlledStationRollup(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->controlledRollup('adc_station_id', $from, $to);
    }

    /**
     * CONTROLLED-only transaction counts per unit and type inside the window.
     *
     * @return Collection<int, object{unit_id: int, transaction_type: string, transaction_count: int}>
     */
    public function controlledUnitRollup(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->controlledRollup('unit_id', $from, $to);
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

    /**
     * CONTROLLED-only rollup by the given dimension. Mirrors rollup() but
     * filters to controlled transactions so the controlled-substance view never
     * inherits a non-controlled denominator. Station/unit grouping only.
     *
     * @return Collection<int, object>
     */
    private function controlledRollup(string $dimension, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $columns = [
            $dimension,
            'transaction_type',
            DB::raw('count(*)::int as transaction_count'),
        ];
        $groups = [$dimension, 'transaction_type'];
        if ($dimension === 'adc_station_id') {
            $columns[] = DB::raw('max(unit_id) as unit_id');
        }

        return AdcTransaction::query()
            ->controlled()
            ->whereNotNull($dimension)
            ->whereBetween('occurred_at', [$from, $to])
            ->groupBy($groups)
            ->orderBy($dimension)
            ->orderBy('transaction_type')
            ->get($columns);
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
