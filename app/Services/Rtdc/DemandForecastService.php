<?php

namespace App\Services\Rtdc;

use Illuminate\Support\Facades\DB;

/**
 * Builds the RTDC › Predictions › Demand Forecast payload from the live `prod`
 * schema (DB_SCHEMA=prod).
 *
 * The page (resources/js/Pages/RTDC/Predictions/DemandForecast.jsx) renders
 * predicted demand vs. available capacity for the upcoming service date across
 * the two seeded planning horizons (`by_2pm` intraday, `by_midnight` end of
 * day). prod.rtdc_predictions is the authoritative forecast source: it carries
 * `demand_expected`, `capacity_now`, `bed_need`, the demand breakdown by source
 * (ED / OR / transfer / direct) and the weighted expected-discharge volume per
 * non-ED unit. The latest census snapshot anchors the predicted-census walk
 * (occupied now + expected admits − expected discharges).
 *
 * Returned shape (all keys always present; safe / zeroed on empty tables):
 *   serviceDate:  string|null            // ISO date the forecast targets
 *   horizons:     list<HorizonSummary>   // one per seeded horizon
 *   kpis:         array<string,mixed>    // headline tiles for the default horizon
 *   units:        list<UnitForecast>     // per-unit rows for the default horizon
 *   capacityChart:array{labels, demand, capacity, deficit}  // per-unit chart series
 *   demandBySource: list<{source,value}> // house-wide demand mix (default horizon)
 *
 * Computation is deterministic and never throws.
 */
class DemandForecastService
{
    /** Default headline horizon (the intraday bed-meeting horizon). */
    private const DEFAULT_HORIZON = 'by_2pm';

    /** Human-readable horizon labels (matches the prod CHECK constraint values). */
    private const HORIZON_LABELS = [
        'by_2pm' => 'By 2 PM',
        'by_midnight' => 'By Midnight',
    ];

    /** Human-readable label per unit `type`. */
    private const TYPE_LABELS = [
        'ed' => 'Emergency',
        'icu' => 'ICU',
        'step_down' => 'Step-down',
        'med_surg' => 'Med/Surg',
        'or' => 'Operating Room',
    ];

    /**
     * Full Demand Forecast payload.
     *
     * @return array{
     *     serviceDate: string|null,
     *     horizons: list<array<string,mixed>>,
     *     kpis: array<string,mixed>,
     *     units: list<array<string,mixed>>,
     *     capacityChart: array{labels:list<string>,demand:list<int>,capacity:list<int>,deficit:list<int>},
     *     demandBySource: list<array{source:string,value:int}>
     * }
     */
    public function build(): array
    {
        $serviceDate = $this->resolveServiceDate();

        if ($serviceDate === null) {
            return $this->emptyPayload();
        }

        $predictions = $this->predictionsForDate($serviceDate);
        $occupiedNow = $this->occupiedNowByUnit();

        $horizons = $this->horizonSummaries($predictions, $occupiedNow);
        $defaultRows = $this->unitRowsForHorizon($predictions, $occupiedNow, self::DEFAULT_HORIZON);

        return [
            'serviceDate' => $serviceDate,
            'horizons' => $horizons,
            'kpis' => $this->kpis($horizons, $defaultRows),
            'units' => $defaultRows,
            'capacityChart' => $this->capacityChart($defaultRows),
            'demandBySource' => $this->demandBySource($predictions, self::DEFAULT_HORIZON),
        ];
    }

    // -----------------------------------------------------------------------
    // Source queries
    // -----------------------------------------------------------------------

    /**
     * The service date the forecast targets: the nearest upcoming seeded date
     * (>= today), falling back to the most recent seeded date if none upcoming.
     */
    private function resolveServiceDate(): ?string
    {
        $upcoming = DB::table('prod.rtdc_predictions')
            ->where('is_deleted', false)
            ->whereDate('service_date', '>=', DB::raw('CURRENT_DATE'))
            ->min('service_date');

        if ($upcoming !== null) {
            return (string) $upcoming;
        }

        $latest = DB::table('prod.rtdc_predictions')
            ->where('is_deleted', false)
            ->max('service_date');

        return $latest !== null ? (string) $latest : null;
    }

    /**
     * All non-deleted, non-ED unit predictions for the given service date.
     *
     * @return list<object>
     */
    private function predictionsForDate(string $serviceDate): array
    {
        return DB::select(
            "SELECT
                p.unit_id, p.horizon,
                p.demand_expected, p.capacity_now, p.bed_need,
                p.discharges_weighted, p.discharges_definite,
                p.discharges_probable, p.discharges_possible,
                p.demand_ed, p.demand_or, p.demand_transfer, p.demand_direct,
                u.name AS unit_name, u.abbreviation, u.type AS unit_type,
                u.staffed_bed_count
             FROM prod.rtdc_predictions p
             JOIN prod.units u ON u.unit_id = p.unit_id
             WHERE p.is_deleted = false
               AND u.is_deleted = false
               AND u.type <> 'ed'
               AND p.service_date = ?
             ORDER BY u.type, u.name",
            [$serviceDate]
        );
    }

    /**
     * Latest-census occupied-bed count per non-ED unit (DISTINCT ON captured_at).
     *
     * @return array<int,int>
     */
    private function occupiedNowByUnit(): array
    {
        $rows = DB::select(
            "SELECT DISTINCT ON (cs.unit_id) cs.unit_id, cs.occupied
             FROM prod.census_snapshots cs
             JOIN prod.units u ON u.unit_id = cs.unit_id
             WHERE u.is_deleted = false AND u.type <> 'ed'
             ORDER BY cs.unit_id, cs.captured_at DESC"
        );

        $byUnit = [];
        foreach ($rows as $row) {
            $byUnit[(int) $row->unit_id] = (int) $row->occupied;
        }

        return $byUnit;
    }

    // -----------------------------------------------------------------------
    // Horizon roll-ups
    // -----------------------------------------------------------------------

    /**
     * House-wide summary for each seeded horizon present in the predictions.
     *
     * @param  list<object>  $predictions
     * @param  array<int,int>  $occupiedNow
     * @return list<array<string,mixed>>
     */
    private function horizonSummaries(array $predictions, array $occupiedNow): array
    {
        $byHorizon = [];
        foreach ($predictions as $row) {
            $h = (string) $row->horizon;
            $byHorizon[$h] ??= [
                'demand' => 0,
                'capacity' => 0,
                'deficit' => 0,
                'surplus' => 0,
                'expectedDischarges' => 0,
                'occupiedNow' => 0,
                'unitsShort' => 0,
            ];

            $demand = (int) $row->demand_expected;
            $capacity = (int) $row->capacity_now;
            $bedNeed = (int) $row->bed_need;

            $byHorizon[$h]['demand'] += $demand;
            $byHorizon[$h]['capacity'] += $capacity;
            $byHorizon[$h]['deficit'] += max(0, $bedNeed);
            $byHorizon[$h]['surplus'] += max(0, -$bedNeed);
            $byHorizon[$h]['expectedDischarges'] += (int) round((float) $row->discharges_weighted);
            $byHorizon[$h]['occupiedNow'] += (int) ($occupiedNow[(int) $row->unit_id] ?? 0);
            if ($bedNeed > 0) {
                $byHorizon[$h]['unitsShort']++;
            }
        }

        // Stable ordering: default horizon first, then the rest alphabetically.
        $order = array_keys(self::HORIZON_LABELS);
        $out = [];
        foreach ($order as $h) {
            if (! isset($byHorizon[$h])) {
                continue;
            }
            $agg = $byHorizon[$h];
            // Predicted census = where we are now + admits in − discharges out.
            $predictedCensus = max(0, $agg['occupiedNow'] + $agg['demand'] - $agg['expectedDischarges']);
            $netBedNeed = $agg['deficit'] - $agg['surplus'];

            $out[] = [
                'horizon' => $h,
                'label' => self::HORIZON_LABELS[$h] ?? ucfirst(str_replace('_', ' ', $h)),
                'demand' => $agg['demand'],
                'capacity' => $agg['capacity'],
                'deficit' => $agg['deficit'],
                'surplus' => $agg['surplus'],
                'netBedNeed' => $netBedNeed,
                'expectedDischarges' => $agg['expectedDischarges'],
                'predictedCensus' => $predictedCensus,
                'unitsShort' => $agg['unitsShort'],
                'status' => $this->bedNeedStatus($agg['deficit']),
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // KPI tiles (headline horizon)
    // -----------------------------------------------------------------------

    /**
     * @param  list<array<string,mixed>>  $horizons
     * @param  list<array<string,mixed>>  $defaultRows
     * @return array<string,mixed>
     */
    private function kpis(array $horizons, array $defaultRows): array
    {
        $default = null;
        foreach ($horizons as $h) {
            if ($h['horizon'] === self::DEFAULT_HORIZON) {
                $default = $h;
                break;
            }
        }
        $default ??= $horizons[0] ?? null;

        if ($default === null) {
            return [
                'predictedCensus' => 0,
                'predictedDemand' => 0,
                'availableCapacity' => 0,
                'netBedNeed' => 0,
                'expectedDischarges' => 0,
                'unitsShort' => 0,
                'unitsTotal' => count($defaultRows),
                'horizonLabel' => self::HORIZON_LABELS[self::DEFAULT_HORIZON],
                'netBedNeedStatus' => 'success',
            ];
        }

        return [
            'predictedCensus' => (int) $default['predictedCensus'],
            'predictedDemand' => (int) $default['demand'],
            'availableCapacity' => (int) $default['capacity'],
            'netBedNeed' => (int) $default['netBedNeed'],
            'expectedDischarges' => (int) $default['expectedDischarges'],
            'unitsShort' => (int) $default['unitsShort'],
            'unitsTotal' => count($defaultRows),
            'horizonLabel' => (string) $default['label'],
            'netBedNeedStatus' => $default['netBedNeed'] > 0 ? 'critical' : 'success',
        ];
    }

    // -----------------------------------------------------------------------
    // Per-unit rows (headline horizon)
    // -----------------------------------------------------------------------

    /**
     * @param  list<object>  $predictions
     * @param  array<int,int>  $occupiedNow
     * @return list<array<string,mixed>>
     */
    private function unitRowsForHorizon(array $predictions, array $occupiedNow, string $horizon): array
    {
        $rows = [];
        foreach ($predictions as $row) {
            if ((string) $row->horizon !== $horizon) {
                continue;
            }

            $unitId = (int) $row->unit_id;
            $demand = (int) $row->demand_expected;
            $capacity = (int) $row->capacity_now;
            $bedNeed = (int) $row->bed_need;
            $discharges = (int) round((float) $row->discharges_weighted);
            $occupied = (int) ($occupiedNow[$unitId] ?? 0);
            $predictedCensus = max(0, $occupied + $demand - $discharges);

            $rows[] = [
                'unitId' => $unitId,
                'name' => (string) ($row->abbreviation ?: $row->unit_name),
                'fullName' => (string) $row->unit_name,
                'type' => self::TYPE_LABELS[(string) $row->unit_type] ?? ucfirst(str_replace('_', '-', (string) $row->unit_type)),
                'demand' => $demand,
                'capacity' => $capacity,
                'bedNeed' => $bedNeed,
                'expectedDischarges' => $discharges,
                'occupiedNow' => $occupied,
                'predictedCensus' => $predictedCensus,
                'status' => $this->bedNeedStatus($bedNeed),
            ];
        }

        // Most constrained units first.
        usort($rows, fn ($a, $b): int => $b['bedNeed'] <=> $a['bedNeed']);

        return $rows;
    }

    // -----------------------------------------------------------------------
    // Chart series
    // -----------------------------------------------------------------------

    /**
     * Per-unit predicted demand vs. available capacity (grouped-bar series).
     *
     * @param  list<array<string,mixed>>  $rows
     * @return array{labels:list<string>,demand:list<int>,capacity:list<int>,deficit:list<int>}
     */
    private function capacityChart(array $rows): array
    {
        $labels = [];
        $demand = [];
        $capacity = [];
        $deficit = [];

        foreach ($rows as $row) {
            $labels[] = (string) $row['name'];
            $demand[] = (int) $row['demand'];
            $capacity[] = (int) $row['capacity'];
            $deficit[] = (int) max(0, $row['bedNeed']);
        }

        return [
            'labels' => $labels,
            'demand' => $demand,
            'capacity' => $capacity,
            'deficit' => $deficit,
        ];
    }

    /**
     * House-wide expected-admission demand broken down by source for a horizon.
     *
     * @param  list<object>  $predictions
     * @return list<array{source:string,value:int}>
     */
    private function demandBySource(array $predictions, string $horizon): array
    {
        $totals = ['ED' => 0, 'OR' => 0, 'Transfer' => 0, 'Direct' => 0];
        foreach ($predictions as $row) {
            if ((string) $row->horizon !== $horizon) {
                continue;
            }
            $totals['ED'] += (int) $row->demand_ed;
            $totals['OR'] += (int) $row->demand_or;
            $totals['Transfer'] += (int) $row->demand_transfer;
            $totals['Direct'] += (int) $row->demand_direct;
        }

        $out = [];
        foreach ($totals as $source => $value) {
            $out[] = ['source' => $source, 'value' => $value];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** Map an aggregate bed deficit to the status vocabulary (critical|warning|success). */
    private function bedNeedStatus(int $deficit): string
    {
        if ($deficit >= 4) {
            return 'critical';
        }
        if ($deficit >= 1) {
            return 'warning';
        }

        return 'success';
    }

    /**
     * @return array{
     *     serviceDate: null,
     *     horizons: list<array<string,mixed>>,
     *     kpis: array<string,mixed>,
     *     units: list<array<string,mixed>>,
     *     capacityChart: array{labels:list<string>,demand:list<int>,capacity:list<int>,deficit:list<int>},
     *     demandBySource: list<array{source:string,value:int}>
     * }
     */
    private function emptyPayload(): array
    {
        return [
            'serviceDate' => null,
            'horizons' => [],
            'kpis' => [
                'predictedCensus' => 0,
                'predictedDemand' => 0,
                'availableCapacity' => 0,
                'netBedNeed' => 0,
                'expectedDischarges' => 0,
                'unitsShort' => 0,
                'unitsTotal' => 0,
                'horizonLabel' => self::HORIZON_LABELS[self::DEFAULT_HORIZON],
                'netBedNeedStatus' => 'success',
            ],
            'units' => [],
            'capacityChart' => ['labels' => [], 'demand' => [], 'capacity' => [], 'deficit' => []],
            'demandBySource' => [],
        ];
    }
}
