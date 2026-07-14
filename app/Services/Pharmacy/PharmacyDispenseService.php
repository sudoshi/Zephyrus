<?php

declare(strict_types=1);

namespace App\Services\Pharmacy;

use App\Data\Ancillary\FreshnessEnvelope;
use App\Services\Ancillary\AncillaryContractSerializer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Dispense and Delivery query service (§X-9). Owns every station/unit
 * operational rollup, the override/stockout RATE (count over a declared
 * denominator — never a bare count masquerading as a rate), shortage-flag
 * context, vend-to-refill durations, the missing-dose/re-request chain count,
 * and the OPTIONAL delivery segments server-side (§5.1: React never recomputes
 * authoritative status). The application clock is bound into SQL as a parameter.
 *
 * Two honesty rules hold throughout:
 *   1. A rate is only ever count ÷ a DECLARED denominator. A station with zero
 *      vends has NO override/stockout rate — the field is null and renders as
 *      "no data", never a fabricated 0%. The measured observed rate is carried
 *      separately from the LOCAL POLICY target (a config value, not an event).
 *   2. Delivery tracking is OPTIONAL. When rx_dispenses carries no delivered_at
 *      evidence the delivery segment degrades to an explicit "absent" coverage
 *      statement — never a fabricated delivery interval and never a zero.
 *
 * This is the diversion-adjacent boundary (§13): every aggregate is grouped by
 * station or unit only. No user, actor, staff, verifier, risk, or rank
 * dimension exists in any query, DTO, or field, and none may ever be added.
 * adc_transactions carries no user columns; keep it that way. Order-level drill
 * (event-linked context) is gated behind viewAncillaryPatientDetail; the
 * aggregate station/unit view is the default for everyone.
 */
final class PharmacyDispenseService
{
    /** Window (hours) for station/unit transaction rollups and rate denominators. */
    public const WINDOW_HOURS = 24;

    /**
     * LOCAL POLICY reference lines. These are CONFIGURED operational targets —
     * the maximum override and stockout rate a station is expected to hold — NOT
     * measured events. They are surfaced separately from the observed rates and
     * are never conflated with them. A deployment tunes these to local policy.
     */
    public const OVERRIDE_TARGET_RATE = 5.0;

    public const STOCKOUT_TARGET_RATE = 2.0;

    public function __construct(
        private readonly AncillaryContractSerializer $contracts,
        private readonly AdcStationSignalService $signals,
    ) {}

    /**
     * Full Dispense and Delivery page payload (§9 envelope).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters = [], bool $canViewPatientDetail = true): array
    {
        $filters = $this->filters($filters);
        $now = CarbonImmutable::now();
        $windowStart = $now->subHours(self::WINDOW_HOURS);

        $stationRollup = $this->signals->stationRollup($windowStart, $now);
        $unitRollup = $this->signals->unitRollup($windowStart, $now);
        $stationMeta = $this->stationMeta();
        $unitMeta = $this->unitMeta();
        $activeStockouts = $this->signals->activeStockouts();
        $stations = $this->stations($stationRollup, $stationMeta, $activeStockouts, $filters);
        $units = $this->units($unitRollup, $unitMeta);

        $freshness = $this->freshness();
        $state = $this->state($stations, $freshness);

        return [
            'generatedAt' => $now->toAtomString(),
            'sourceCutoffAt' => $freshness->sourceCutoffAt?->format(DATE_ATOM),
            'freshnessStatus' => $freshness->status,
            'degradedMode' => $state === 'degraded',
            'state' => $state,
            'stateMessage' => $this->stateMessage($state),
            'freshness' => $this->contracts->freshness($freshness),
            'filters' => $filters,
            'filterOptions' => ['stationType' => $this->stationTypes($stationMeta)],
            // Dispense/delivery rollups are operational signals, not §8 SLA clocks.
            'appliedSlaDefinitions' => [],
            'policy' => $this->policy(),
            'window' => [
                'hours' => self::WINDOW_HOURS,
                'startAt' => $windowStart->toAtomString(),
                'endAt' => $now->toAtomString(),
            ],
            'data' => [
                'summary' => $this->summary($stations, $now),
                'stations' => $stations,
                'units' => $units,
                'shortages' => $this->shortages($now, $canViewPatientDetail),
                'vendToRefill' => $this->vendToRefill($windowStart, $now),
                'missingDose' => $this->missingDose($now, $canViewPatientDetail),
                'delivery' => $this->delivery($now, $canViewPatientDetail),
            ],
            'privacy' => [
                'directPatientIdentifiersIncluded' => false,
                'individualPerformanceIncluded' => false,
                'diversionScoringIncluded' => false,
                'userLevelDimensionIncluded' => false,
                'identifierPolicy' => 'Station and unit aggregates only. Override and stockout rates are computed over a declared vend denominator; no user, actor, staff, verifier, individual risk score, or ranked list is computed or exposed anywhere. Order-linked context is pseudonymous and gated behind explicit patient-detail authorization.',
            ],
            'canViewPatientDetail' => $canViewPatientDetail,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function filters(array $input): array
    {
        $stationType = is_string($input['stationType'] ?? null) && $input['stationType'] !== '' ? $input['stationType'] : null;

        return ['stationType' => $stationType];
    }

    /**
     * Declared LOCAL POLICY surface. Every value here is a CONFIGURED target
     * rate — never an observed event. The frontend renders these as policy
     * reference lines, visually distinct from the measured observed rates.
     *
     * @return array<string, mixed>
     */
    private function policy(): array
    {
        return [
            'kind' => 'local_policy',
            'overrideTargetRate' => [
                'label' => 'Override rate target',
                'ratePercent' => self::OVERRIDE_TARGET_RATE,
                'denominatorLabel' => 'ADC vend transactions in the window',
                'description' => 'Locally configured maximum override rate (overrides per hundred vends). This is a policy reference line, not a measured value; a station over target is flagged for operational review, never for individual attribution.',
            ],
            'stockoutTargetRate' => [
                'label' => 'Stockout rate target',
                'ratePercent' => self::STOCKOUT_TARGET_RATE,
                'denominatorLabel' => 'ADC vend transactions in the window',
                'description' => 'Locally configured maximum stockout rate (stockout events per hundred vends). Policy reference line applied to the measured rate; it is configuration, not an observed event.',
            ],
        ];
    }

    /**
     * Station identity/label/unit map for the demo/operational station registry.
     * Operational fields only — a station has a label, a type, a status, and an
     * owning unit; it never carries a user dimension.
     *
     * @return Collection<int, object>
     */
    private function stationMeta(): Collection
    {
        return DB::table('prod.adc_stations as s')
            ->leftJoin('prod.units as u', 'u.unit_id', '=', 's.unit_id')
            ->orderBy('s.adc_station_id')
            ->get([
                's.adc_station_id',
                's.label',
                's.station_type',
                's.status',
                's.unit_id',
                'u.name as unit_name',
            ])
            ->keyBy('adc_station_id');
    }

    /** @return Collection<int, object> */
    private function unitMeta(): Collection
    {
        return DB::table('prod.units')
            ->whereNotNull('name')
            ->get(['unit_id', 'name'])
            ->keyBy('unit_id');
    }

    /**
     * @param  Collection<int, object>  $meta
     * @return list<string>
     */
    private function stationTypes(Collection $meta): array
    {
        return $meta->pluck('station_type')->filter()->unique()->sort()->values()->all();
    }

    /**
     * Per-station rollup. Each row carries its transaction counts by type, the
     * declared vend denominator, and the override/stockout RATE computed over
     * that denominator — null when there are no vends (no data, not zero
     * percent). A station's over-target status is a POLICY fold, carried as a
     * server-provided status string. Station/unit aggregates only.
     *
     * @param  Collection<int, object>  $rollup
     * @param  Collection<int, object>  $meta
     * @param  Collection<int, object>  $activeStockouts
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function stations(Collection $rollup, Collection $meta, Collection $activeStockouts, array $filters): array
    {
        $activeStockoutIds = $activeStockouts->pluck('adc_station_id')->map(fn ($id): int => (int) $id)->all();

        return $rollup
            ->groupBy('adc_station_id')
            ->map(function (Collection $group, int|string $stationId) use ($meta, $activeStockoutIds): array {
                $stationId = (int) $stationId;
                $counts = $this->countsByType($group);
                $vends = $counts['vend'];
                $overrides = $counts['override'];
                $stockouts = $counts['stockout'];
                $station = $meta->get($stationId);
                $overrideRate = $this->rate($overrides, $vends);
                $stockoutRate = $this->rate($stockouts, $vends);

                return [
                    'stationId' => $stationId,
                    'label' => $station?->label ?? 'Unknown station',
                    'stationType' => $station?->station_type ?? 'other',
                    'unitName' => $station?->unit_name,
                    'vends' => $vends,
                    'overrides' => $overrides,
                    'stockouts' => $stockouts,
                    'controlledVends' => $counts['controlled_vend'],
                    // A null rate signals no denominator — the view shows "no data",
                    // never a fabricated 0%. hasDenominator is the explicit gate.
                    'hasDenominator' => $vends > 0,
                    'denominatorCount' => $vends,
                    'overrideRatePercent' => $overrideRate,
                    'stockoutRatePercent' => $stockoutRate,
                    'overrideStatus' => $this->rateStatus($overrideRate, self::OVERRIDE_TARGET_RATE),
                    'stockoutStatus' => $this->rateStatus($stockoutRate, self::STOCKOUT_TARGET_RATE),
                    'hasActiveStockout' => in_array($stationId, $activeStockoutIds, true),
                    'transactionCounts' => $counts['all'],
                ];
            })
            ->when($filters['stationType'] !== null, fn (Collection $rows): Collection => $rows->where('stationType', $filters['stationType']))
            ->sortByDesc(fn (array $row): int => $row['vends'])
            ->values()
            ->all();
    }

    /**
     * Per-unit rollup: the same override/stockout rates aggregated to the unit
     * dimension so a floor with several cabinets reads as one operational signal.
     *
     * @param  Collection<int, object>  $rollup
     * @param  Collection<int, object>  $meta
     * @return list<array<string, mixed>>
     */
    private function units(Collection $rollup, Collection $meta): array
    {
        return $rollup
            ->groupBy('unit_id')
            ->map(function (Collection $group, int|string $unitId) use ($meta): array {
                $unitId = (int) $unitId;
                $counts = $this->countsByType($group);
                $vends = $counts['vend'];
                $overrideRate = $this->rate($counts['override'], $vends);
                $stockoutRate = $this->rate($counts['stockout'], $vends);

                return [
                    'unitId' => $unitId,
                    'unitName' => $meta->get($unitId)?->name ?? 'Unknown unit',
                    'vends' => $vends,
                    'overrides' => $counts['override'],
                    'stockouts' => $counts['stockout'],
                    'hasDenominator' => $vends > 0,
                    'denominatorCount' => $vends,
                    'overrideRatePercent' => $overrideRate,
                    'stockoutRatePercent' => $stockoutRate,
                    'overrideStatus' => $this->rateStatus($overrideRate, self::OVERRIDE_TARGET_RATE),
                    'stockoutStatus' => $this->rateStatus($stockoutRate, self::STOCKOUT_TARGET_RATE),
                ];
            })
            ->sortByDesc(fn (array $row): int => $row['vends'])
            ->values()
            ->all();
    }

    /**
     * Reduce a station/unit rollup group (rows of transaction_type,
     * transaction_count, controlled_count) into a flat count map.
     *
     * @param  Collection<int, object>  $group
     * @return array{vend: int, override: int, stockout: int, refill: int, return: int, waste: int, controlled_vend: int, all: array<string, int>}
     */
    private function countsByType(Collection $group): array
    {
        $all = [];
        $controlledVend = 0;
        foreach ($group as $row) {
            $all[$row->transaction_type] = (int) $row->transaction_count;
            if ($row->transaction_type === 'vend') {
                $controlledVend = (int) $row->controlled_count;
            }
        }

        return [
            'vend' => $all['vend'] ?? 0,
            'override' => $all['override'] ?? 0,
            'stockout' => $all['stockout'] ?? 0,
            'refill' => $all['refill'] ?? 0,
            'return' => $all['return'] ?? 0,
            'waste' => $all['waste'] ?? 0,
            'controlled_vend' => $controlledVend,
            'all' => $all,
        ];
    }

    /**
     * A rate is count ÷ declared denominator × 100, rounded. When the
     * denominator is zero there is NO rate — return null so the view renders
     * "no data", never a fabricated zero percent.
     */
    private function rate(int $count, int $denominator): ?float
    {
        if ($denominator <= 0) {
            return null;
        }

        return round($count / $denominator * 100, 1);
    }

    /**
     * POLICY fold of a MEASURED rate against a configured target. 'no_data' when
     * the rate is null (no denominator) — never silently 'within'. This is the
     * only place the observed rate meets the policy target, and the result is a
     * server-provided status the view renders without any raw-rate comparison.
     */
    private function rateStatus(?float $rate, float $target): string
    {
        if ($rate === null) {
            return 'no_data';
        }

        return match (true) {
            $rate > $target => 'over_target',
            $rate >= $target * 0.75 => 'near_target',
            default => 'within_target',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $stations
     * @return array<string, mixed>
     */
    private function summary(array $stations, CarbonImmutable $now): array
    {
        $measured = array_filter($stations, fn (array $s): bool => $s['hasDenominator']);
        $totalVends = array_sum(array_column($stations, 'vends'));
        $totalOverrides = array_sum(array_column($stations, 'overrides'));
        $totalStockouts = array_sum(array_column($stations, 'stockouts'));

        return [
            'stationsReporting' => count($stations),
            'stationsWithDenominator' => count($measured),
            'stationsWithoutDenominator' => count($stations) - count($measured),
            'totalVends' => $totalVends,
            'totalOverrides' => $totalOverrides,
            'totalStockouts' => $totalStockouts,
            // The aggregate rate uses the SAME declared vend denominator; null
            // when no station vended, never a fabricated zero.
            'overrideRatePercent' => $this->rate($totalOverrides, $totalVends),
            'stockoutRatePercent' => $this->rate($totalStockouts, $totalVends),
            'stationsOverOverrideTarget' => count(array_filter($stations, fn (array $s): bool => $s['overrideStatus'] === 'over_target')),
            'stationsWithActiveStockout' => count(array_filter($stations, fn (array $s): bool => $s['hasActiveStockout'])),
        ];
    }

    /**
     * Shortage-flag context: orders flagged on_shortage, tied to the station
     * that carries the matching open stockout signal where one exists. Pseudonymous
     * medication/patient references only; order-linked patient context is gated.
     *
     * @return array<string, mixed>
     */
    private function shortages(CarbonImmutable $now, bool $canViewPatientDetail): array
    {
        $orders = DB::table('prod.rx_orders as x')
            ->join('prod.ancillary_orders as o', 'o.ancillary_order_id', '=', 'x.ancillary_order_id')
            ->leftJoin('prod.units as u', 'u.unit_id', '=', 'o.unit_id')
            ->where('o.department', 'rx')
            ->where('x.on_shortage', true)
            ->where('o.ordered_at', '>=', now()->subDay())
            ->whereRaw("COALESCE(o.metadata->>'operational_window', 'current') <> 'historical_study_only'")
            ->orderBy('o.ordered_at')
            ->get([
                'o.order_uuid',
                'o.patient_ref',
                'o.patient_class',
                'x.medication_label',
                'x.order_status',
                'x.metadata as order_metadata',
                'u.name as unit_name',
            ]);

        $rows = $orders->map(function (object $row) use ($canViewPatientDetail): array {
            $metadata = $this->decodeMetadata($row->order_metadata);
            $context = is_array($metadata['shortage_context'] ?? null) ? $metadata['shortage_context'] : [];

            return [
                'orderUuid' => $canViewPatientDetail ? $row->order_uuid : null,
                'medicationLabel' => (string) $row->medication_label,
                'patientRef' => $canViewPatientDetail ? ($row->patient_ref ?: 'Pseudonymous patient unavailable') : 'Patient context restricted',
                'orderStatus' => (string) $row->order_status,
                'locationLabel' => $row->unit_name,
                'reasonCode' => is_string($context['reason_code'] ?? null) ? $context['reason_code'] : null,
                'stationKey' => is_string($context['station_key'] ?? null) ? $context['station_key'] : null,
                'notedAt' => is_string($context['noted_at'] ?? null) ? CarbonImmutable::parse($context['noted_at'])->toAtomString() : null,
            ];
        })->values()->all();

        return [
            'count' => count($rows),
            'orders' => $rows,
            'basis' => 'Orders flagged on shortage in the current operational window, joined to the station stockout signal where the shortage context names one. Shortage is an order-level workflow flag, never a payer or supply writeback.',
        ];
    }

    /**
     * Vend-to-refill duration per station: where a refill FOLLOWS a vend inside
     * the window, the elapsed minutes between the last vend before a refill and
     * that refill measure how long a location ran between restocks. Station
     * aggregate only — no user dimension, no order linkage.
     *
     * @return array<string, mixed>
     */
    private function vendToRefill(CarbonImmutable $windowStart, CarbonImmutable $now): array
    {
        // For each refill, find the most recent vend at the same station strictly
        // before it; the gap is the vend-to-refill interval. A refill with no
        // preceding vend in the window is not measurable and is excluded — never
        // reported as a zero interval.
        $rows = DB::table('prod.adc_transactions as r')
            ->join('prod.adc_stations as s', 's.adc_station_id', '=', 'r.adc_station_id')
            ->where('r.transaction_type', 'refill')
            ->whereRaw('r.occurred_at >= ?::timestamptz', [$windowStart->toIso8601String()])
            ->whereRaw('r.occurred_at <= ?::timestamptz', [$now->toIso8601String()])
            ->crossJoin(DB::raw('LATERAL (
                SELECT v.occurred_at AS vend_at
                FROM prod.adc_transactions v
                WHERE v.adc_station_id = r.adc_station_id
                  AND v.transaction_type = \'vend\'
                  AND v.occurred_at < r.occurred_at
                ORDER BY v.occurred_at DESC
                LIMIT 1
            ) AS lastvend'))
            ->selectRaw('s.adc_station_id, s.label, count(*) AS pair_count, '.
                'percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (r.occurred_at - lastvend.vend_at)) / 60) AS median_minutes, '.
                'max(EXTRACT(EPOCH FROM (r.occurred_at - lastvend.vend_at)) / 60) AS max_minutes')
            ->groupBy('s.adc_station_id', 's.label')
            ->orderBy('s.adc_station_id')
            ->get();

        $stations = $rows->map(fn (object $row): array => [
            'stationId' => (int) $row->adc_station_id,
            'label' => (string) $row->label,
            'pairCount' => (int) $row->pair_count,
            'medianMinutes' => $row->median_minutes === null ? null : (int) round((float) $row->median_minutes),
            'maxMinutes' => $row->max_minutes === null ? null : (int) round((float) $row->max_minutes),
        ])->values()->all();

        return [
            'measurableStations' => count($stations),
            'stations' => $stations,
            'basis' => 'For each refill, the interval from the most recent prior vend at the same station. A refill with no preceding vend in the window is not measurable and is excluded, never shown as zero.',
        ];
    }

    /**
     * Missing-dose / re-request chains: orders carrying an RX_MISSING_DOSE
     * assertion followed by a later RX_DISPENSED in the milestone ledger (the
     * re-dispense). Counted and surfaced. Order-level detail is gated; the count
     * is always available. No user dimension — a missing dose is an order event.
     *
     * @return array<string, mixed>
     */
    private function missingDose(CarbonImmutable $now, bool $canViewPatientDetail): array
    {
        // A chain: the order has an RX_MISSING_DOSE assertion, and the ledger
        // holds an RX_DISPENSED milestone occurring at/after that missing-dose
        // event (the re-dispense). This is the X-5 missing-dose loop.
        $base = DB::table('prod.ancillary_orders as o')
            ->join('prod.rx_orders as x', 'x.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->leftJoin('prod.units as u', 'u.unit_id', '=', 'o.unit_id')
            ->join('prod.ancillary_current_assertions as m', function ($join): void {
                $join->on('m.ancillary_order_id', '=', 'o.ancillary_order_id')->where('m.milestone_code', 'RX_MISSING_DOSE');
            })
            ->where('o.department', 'rx')
            ->where('o.ordered_at', '>=', now()->subDay())
            ->whereRaw("COALESCE(o.metadata->>'operational_window', 'current') <> 'historical_study_only'")
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('prod.ancillary_milestones as d')
                ->whereColumn('d.ancillary_order_id', 'o.ancillary_order_id')
                ->where('d.milestone_code', 'RX_DISPENSED')
                ->whereColumn('d.occurred_at', '>=', 'm.occurred_at'));

        $rows = $base->orderBy('m.occurred_at')
            ->get([
                'o.order_uuid',
                'o.patient_ref',
                'o.patient_class',
                'x.medication_label',
                'x.rx_order_id',
                'm.occurred_at as missing_dose_at',
            ]);

        $chains = $rows->map(function (object $row) use ($canViewPatientDetail): array {
            // The re-dispense channel comes from the rx_dispenses ledger (X-5
            // re-dispenses centrally). Operational field only.
            $redispenseChannel = DB::table('prod.rx_dispenses')
                ->where('rx_order_id', $row->rx_order_id)
                ->where('dispensed_at', '>=', $row->missing_dose_at)
                ->orderByDesc('dispensed_at')
                ->value('dispense_channel');

            return [
                'orderUuid' => $canViewPatientDetail ? $row->order_uuid : null,
                'medicationLabel' => (string) $row->medication_label,
                'patientRef' => $canViewPatientDetail ? ($row->patient_ref ?: 'Pseudonymous patient unavailable') : 'Patient context restricted',
                'missingDoseAt' => CarbonImmutable::parse($row->missing_dose_at)->toAtomString(),
                'reDispenseChannel' => is_string($redispenseChannel) ? $redispenseChannel : null,
            ];
        })->values()->all();

        return [
            'chainCount' => count($chains),
            'chains' => $chains,
            'basis' => 'Orders with a missing-dose event followed by a later re-dispense in the milestone ledger. A missing dose is an order-level operational event; no individual or station is attributed responsibility.',
        ];
    }

    /**
     * OPTIONAL delivery segments. Where rx_dispenses carries delivered_at, the
     * dispense-to-delivery interval is measured. When delivery tracking is
     * ABSENT (no delivered_at anywhere in the cohort) the segment degrades to an
     * explicit "absent" coverage statement — never a fabricated delivery time.
     *
     * @return array<string, mixed>
     */
    private function delivery(CarbonImmutable $now, bool $canViewPatientDetail): array
    {
        $row = DB::table('prod.rx_dispenses as d')
            ->join('prod.rx_orders as x', 'x.rx_order_id', '=', 'd.rx_order_id')
            ->join('prod.ancillary_orders as o', 'o.ancillary_order_id', '=', 'x.ancillary_order_id')
            ->where('o.department', 'rx')
            ->where('d.dispensed_at', '>=', now()->subDay())
            ->selectRaw(<<<'SQL'
                count(*) AS dispenses,
                count(*) FILTER (WHERE d.delivered_at IS NOT NULL) AS delivered,
                count(*) FILTER (WHERE d.returned_at IS NOT NULL) AS returned,
                percentile_cont(0.5) WITHIN GROUP (
                    ORDER BY EXTRACT(EPOCH FROM (d.delivered_at - d.dispensed_at)) / 60
                ) FILTER (WHERE d.delivered_at IS NOT NULL) AS median_minutes,
                percentile_cont(0.9) WITHIN GROUP (
                    ORDER BY EXTRACT(EPOCH FROM (d.delivered_at - d.dispensed_at)) / 60
                ) FILTER (WHERE d.delivered_at IS NOT NULL) AS p90_minutes
            SQL)
            ->first();

        $dispenses = (int) ($row->dispenses ?? 0);
        $delivered = (int) ($row->delivered ?? 0);
        $returned = (int) ($row->returned ?? 0);
        // Coverage is 'absent' when NO dispense carries a delivery timestamp:
        // delivery tracking is optional and we never fabricate the interval.
        $coverage = $delivered > 0 ? 'available' : 'absent';

        return [
            'coverage' => $coverage,
            'dispenses' => $dispenses,
            'delivered' => $delivered,
            'returned' => $returned,
            'medianMinutes' => $coverage === 'available' && $row->median_minutes !== null ? (int) round((float) $row->median_minutes) : null,
            'p90Minutes' => $coverage === 'available' && $row->p90_minutes !== null ? (int) round((float) $row->p90_minutes) : null,
            'coverageStatement' => $coverage === 'available'
                ? 'Delivery timestamps are recorded for dispensed medications; the dispense-to-delivery interval is measured over those with a delivery time.'
                : 'Delivery tracking is not available for the current dispense cohort. Dispense evidence is complete, but no dispense-to-delivery interval is reported — it is never shown as zero when delivery times are absent.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $stations
     */
    private function state(array $stations, FreshnessEnvelope $freshness): string
    {
        if (in_array($freshness->status, ['stale', 'unknown'], true)) {
            return $freshness->status === 'unknown' ? 'degraded' : 'stale';
        }
        if ($stations === []) {
            return 'no_data';
        }

        return 'normal';
    }

    private function stateMessage(string $state): string
    {
        return match ($state) {
            'no_data' => 'No automated dispensing cabinet transactions are in the current operational window.',
            'stale' => 'Dispense evidence is stale; station rates are shown as-of the last source cutoff.',
            'degraded' => 'Dispense source coverage is partial; station rates are shown where a denominator exists.',
            default => 'Dispense and delivery facts are current.',
        };
    }

    private function freshness(): FreshnessEnvelope
    {
        $registry = DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->first();
        $asOf = CarbonImmutable::now();
        $sourceLabel = (string) ($registry?->source_label ?? 'Pharmacy dispensing feeds');

        $cutoffValue = DB::table('prod.adc_transactions')->max('occurred_at')
            ?? $registry?->latest_observed_at;

        if ($cutoffValue === null) {
            return new FreshnessEnvelope(
                status: 'unknown',
                asOf: new \DateTimeImmutable($asOf->toAtomString()),
                sourceCutoffAt: null,
                lagMinutes: null,
                sourceLabel: $sourceLabel,
                explanation: 'No automated dispensing cabinet evidence is available; station rates are unknown until a fresh source cutoff arrives.',
            );
        }

        $cutoff = CarbonImmutable::parse($cutoffValue);
        $lag = max(0, (int) floor($cutoff->diffInSeconds($asOf, false) / 60));
        $warning = max(1, (int) ($registry?->warning_lag_minutes ?? 60));
        $registeredStatus = strtolower((string) ($registry?->status ?? 'current'));
        $sourceError = in_array($registeredStatus, ['error', 'failed', 'unavailable'], true);
        $stale = $sourceError || $registeredStatus === 'stale' || $lag > $warning;

        return new FreshnessEnvelope(
            status: $stale ? 'stale' : 'fresh',
            asOf: new \DateTimeImmutable($asOf->toAtomString()),
            sourceCutoffAt: new \DateTimeImmutable($cutoff->toAtomString()),
            lagMinutes: $lag,
            sourceLabel: $sourceLabel,
            explanation: $sourceError
                ? 'The registered ancillary source reports an error.'
                : ($stale ? 'The latest dispensing evidence exceeds its freshness tolerance.' : null),
        );
    }

    /** @return array<string, mixed> */
    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }
        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
