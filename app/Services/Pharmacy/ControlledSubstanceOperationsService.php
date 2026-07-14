<?php

declare(strict_types=1);

namespace App\Services\Pharmacy;

use App\Data\Ancillary\FreshnessEnvelope;
use App\Services\Ancillary\AncillaryContractSerializer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Controlled Substances OPERATIONAL query service (§X-10). Owns, entirely
 * server-side (§5.1), the open controlled-discrepancy count, each open
 * discrepancy's AGE against the local SHIFT-END reconciliation policy, and the
 * override/discrepancy PATTERNS aggregated by UNIT and STATION only. It reuses
 * AdcStationSignalService for the open-discrepancy pairing (open matched to
 * resolve on discrepancy_key) and the controlled override/transaction rollups.
 *
 * ── The single hard rule ──────────────────────────────────────────────────
 * This is a diversion-ADJACENT view. There is NO individual, user, staff,
 * person, verifier, actor, or performed-by dimension ANYWHERE — not in a query,
 * a DTO field, a rate, a rank, or a list. adc_transactions carries no user
 * columns and never will. This service NEVER computes a diversion score, a
 * per-person risk score, or a ranked staff list. Diversion investigation and
 * individual scoring are OUT OF SCOPE by design: the contract declares it in a
 * dedicated `scope` block, and the page states it in visible text. The only
 * dimensions that exist are station and unit.
 *
 * ── Policy vs. measurement ────────────────────────────────────────────────
 * The shift-end times, reconciliation grace, and override target rate are LOCAL
 * POLICY (config values), kept strictly separate from measured event
 * timestamps. An open discrepancy's age is measured (opened_at vs the applicable
 * shift-end); "past policy" is a POLICY fold, surfaced as a server-provided
 * status string the view renders without any raw-minute comparison. A rate is
 * only ever count ÷ a DECLARED controlled-vend denominator; with no controlled
 * vends the rate is null ("no data"), never a fabricated 0%.
 */
final class ControlledSubstanceOperationsService
{
    public function __construct(
        private readonly AncillaryContractSerializer $contracts,
        private readonly AdcStationSignalService $signals,
    ) {}

    /**
     * Full Controlled Substances operational page payload (§9 envelope).
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $config = $this->config();
        $now = CarbonImmutable::now();
        $windowStart = $now->subHours($config['pattern_window_hours']);

        $openDiscrepancies = $this->openDiscrepancies($now, $config);
        $stationMeta = $this->stationMeta();
        $unitMeta = $this->unitMeta();

        $stationRollup = $this->signals->controlledStationRollup($windowStart, $now);
        $unitRollup = $this->signals->controlledUnitRollup($windowStart, $now);
        $stations = $this->stationPatterns($stationRollup, $stationMeta, $openDiscrepancies['byStation'], $config);
        $units = $this->unitPatterns($unitRollup, $unitMeta, $openDiscrepancies['byUnit'], $config);

        $freshness = $this->freshness();
        $state = $this->state($openDiscrepancies['items'], $stations, $freshness);

        return [
            'generatedAt' => $now->toAtomString(),
            'sourceCutoffAt' => $freshness->sourceCutoffAt?->format(DATE_ATOM),
            'freshnessStatus' => $freshness->status,
            'degradedMode' => $state === 'degraded',
            'state' => $state,
            'stateMessage' => $this->stateMessage($state),
            'freshness' => $this->contracts->freshness($freshness),
            // Controlled discrepancy aging is policy-clock, not an §8 SLA clock.
            'appliedSlaDefinitions' => [],
            'policy' => $this->policyContract($config),
            'window' => [
                'hours' => $config['pattern_window_hours'],
                'startAt' => $windowStart->toAtomString(),
                'endAt' => $now->toAtomString(),
            ],
            'data' => [
                'summary' => $this->summary($openDiscrepancies['items'], $stations),
                'openDiscrepancies' => [
                    'count' => count($openDiscrepancies['items']),
                    'items' => $openDiscrepancies['items'],
                    'basis' => 'Controlled discrepancies opened without a matching resolve on the same discrepancy key. Each is aged against the applicable shift-end reconciliation policy. A discrepancy is an operational reconciliation item attributed to a station and unit, never to any individual.',
                ],
                'stationPatterns' => [
                    'stations' => $stations,
                    'basis' => 'Controlled override and discrepancy patterns by station over the pattern window. Counts and the override rate over a declared controlled-vend denominator; station aggregates only.',
                ],
                'unitPatterns' => [
                    'units' => $units,
                    'basis' => 'The same controlled override and discrepancy patterns aggregated to the unit dimension.',
                ],
            ],
            // The out-of-scope statement is a first-class contract field AND is
            // rendered as visible page text. It is a disclaimer, not a data
            // surface — the individual-output scan targets `data`, never this.
            'scope' => $this->scopeStatement(),
        ];
    }

    /**
     * Declared shift-end + policy configuration, defensively normalized. Every
     * value is LOCAL POLICY, never a measured event.
     *
     * @return array{shift_times: list<string>, shift_timezone: string, shift_label: string, grace_minutes: int, override_target_rate: float, pattern_window_hours: int, export_enabled: bool}
     */
    private function config(): array
    {
        /** @var array<string, mixed> $raw */
        $raw = config('pharmacy.controlled', []);
        $shift = is_array($raw['shift_end'] ?? null) ? $raw['shift_end'] : [];

        $times = collect(is_array($shift['times'] ?? null) ? $shift['times'] : ['07:00', '19:00'])
            ->map(fn (mixed $value): string => (string) $value)
            ->filter(fn (string $value): bool => (bool) preg_match('/^\d{2}:\d{2}$/', $value))
            ->unique()
            ->sort()
            ->values()
            ->all();
        if ($times === []) {
            $times = ['07:00', '19:00'];
        }

        return [
            'shift_times' => $times,
            'shift_timezone' => is_string($shift['timezone'] ?? null) && $shift['timezone'] !== '' ? $shift['timezone'] : 'America/New_York',
            'shift_label' => is_string($shift['label'] ?? null) && $shift['label'] !== '' ? $shift['label'] : 'Shift-end reconciliation policy',
            'grace_minutes' => max(0, (int) ($raw['reconciliation_grace_minutes'] ?? 0)),
            'override_target_rate' => max(0.0, (float) ($raw['override_target_rate'] ?? 5.0)),
            'pattern_window_hours' => max(1, (int) ($raw['pattern_window_hours'] ?? 24)),
            'export_enabled' => (bool) ($raw['export_enabled'] ?? false),
        ];
    }

    /**
     * The most recent shift-end boundary at or before $reference, per the
     * configured local shift-end wall-clock times. Pure policy computation —
     * derived from configuration and the application clock, never from a
     * measured event stamp.
     */
    private function applicableShiftEnd(CarbonImmutable $reference, array $config): CarbonImmutable
    {
        $tz = $config['shift_timezone'];
        $local = $reference->setTimezone($tz);

        $candidates = [];
        foreach ([$local, $local->subDay()] as $day) {
            foreach ($config['shift_times'] as $time) {
                [$hour, $minute] = array_map('intval', explode(':', $time));
                $candidates[] = $day->startOfDay()->setTime($hour, $minute);
            }
        }

        $applicable = null;
        foreach ($candidates as $candidate) {
            if ($candidate->lessThanOrEqualTo($local) && ($applicable === null || $candidate->greaterThan($applicable))) {
                $applicable = $candidate;
            }
        }

        // Fall back to the earliest candidate if (impossibly) none precede now.
        $applicable ??= collect($candidates)->sort()->first();

        return CarbonImmutable::parse($applicable->toAtomString())->utc();
    }

    /**
     * Open controlled discrepancies, each aged against its applicable
     * shift-end. Returns the item list plus station/unit open-count indexes.
     *
     * @return array{items: list<array<string, mixed>>, byStation: array<int, array{open: int, oldestOpenedAt: ?string, pastPolicy: int}>, byUnit: array<int, array{open: int, pastPolicy: int}>}
     */
    private function openDiscrepancies(CarbonImmutable $now, array $config): array
    {
        $details = $this->signals->openDiscrepancyDetails()
            ->filter(fn (object $row): bool => (bool) $row->is_controlled);

        $items = [];
        $byStation = [];
        $byUnit = [];

        foreach ($details as $row) {
            $stationId = (int) $row->adc_station_id;
            $unitId = $row->unit_id === null ? null : (int) $row->unit_id;
            $openedAt = CarbonImmutable::parse($row->opened_at)->utc();
            $shiftEnd = $this->applicableShiftEnd($openedAt, $config);

            // Age since opening (elapsed) and the age relative to the shift-end
            // by which reconciliation was expected. Only openedAt is measured;
            // shiftEnd is policy. A negative "minutes past shift-end" means the
            // discrepancy is still within the shift in which it was opened.
            $minutesOpen = max(0, (int) round($openedAt->diffInMinutes($now, false)));
            $minutesPastShiftEnd = (int) round($shiftEnd->diffInMinutes($now, false));
            $agingStatus = $this->agingStatus($minutesPastShiftEnd, $config['grace_minutes']);
            $pastPolicy = $agingStatus === 'past_policy';

            $items[] = [
                // Pseudonymous discrepancy key — an operational correlation id,
                // never an individual reference.
                'discrepancyKey' => (string) $row->discrepancy_key,
                'stationId' => $stationId,
                'unitId' => $unitId,
                'medicationLabel' => is_string($row->medication_label) && $row->medication_label !== '' ? $row->medication_label : 'Controlled medication',
                'openedAt' => $openedAt->toAtomString(),
                'applicableShiftEndAt' => $shiftEnd->toAtomString(),
                'minutesOpen' => $minutesOpen,
                'minutesPastShiftEnd' => $minutesPastShiftEnd,
                'agingStatus' => $agingStatus,
            ];

            $byStation[$stationId] ??= ['open' => 0, 'oldestOpenedAt' => null, 'pastPolicy' => 0];
            $byStation[$stationId]['open']++;
            $byStation[$stationId]['pastPolicy'] += $pastPolicy ? 1 : 0;
            if ($byStation[$stationId]['oldestOpenedAt'] === null || $openedAt->toAtomString() < $byStation[$stationId]['oldestOpenedAt']) {
                $byStation[$stationId]['oldestOpenedAt'] = $openedAt->toAtomString();
            }

            if ($unitId !== null) {
                $byUnit[$unitId] ??= ['open' => 0, 'pastPolicy' => 0];
                $byUnit[$unitId]['open']++;
                $byUnit[$unitId]['pastPolicy'] += $pastPolicy ? 1 : 0;
            }
        }

        // Oldest-open discrepancies first — the operational priority order.
        usort($items, fn (array $a, array $b): int => $b['minutesOpen'] <=> $a['minutesOpen']);

        return ['items' => $items, 'byStation' => $byStation, 'byUnit' => $byUnit];
    }

    /**
     * POLICY fold of an open discrepancy's age against the shift-end policy.
     * 'past_policy' once it is unreconciled beyond the shift-end plus the
     * configured grace; 'due_this_shift' while still within the shift boundary.
     * A server-provided status the view renders without any raw-minute compare.
     */
    private function agingStatus(int $minutesPastShiftEnd, int $graceMinutes): string
    {
        if ($minutesPastShiftEnd > $graceMinutes) {
            return 'past_policy';
        }
        if ($minutesPastShiftEnd >= 0) {
            return 'at_shift_end';
        }

        return 'due_this_shift';
    }

    /**
     * Per-station controlled patterns: controlled vend/override/discrepancy
     * counts, the override rate over the declared controlled-vend denominator,
     * and the open-discrepancy overlay. Station aggregates only.
     *
     * @param  Collection<int, object>  $rollup
     * @param  Collection<int, object>  $meta
     * @param  array<int, array{open: int, oldestOpenedAt: ?string, pastPolicy: int}>  $openByStation
     * @return list<array<string, mixed>>
     */
    private function stationPatterns(Collection $rollup, Collection $meta, array $openByStation, array $config): array
    {
        // Union of stations that had controlled transactions in the window and
        // stations carrying an open controlled discrepancy — either is worth a row.
        $stationIds = $rollup->pluck('adc_station_id')->map(fn ($id): int => (int) $id)
            ->merge(array_keys($openByStation))
            ->unique()
            ->values();

        $grouped = $rollup->groupBy('adc_station_id');

        return $stationIds->map(function (int $stationId) use ($grouped, $meta, $openByStation, $config): array {
            $counts = $this->countsByType($grouped->get($stationId) ?? collect());
            $vends = $counts['vend'];
            $overrides = $counts['override'];
            $overrideRate = $this->rate($overrides, $vends);
            $station = $meta->get($stationId);
            $open = $openByStation[$stationId] ?? ['open' => 0, 'oldestOpenedAt' => null, 'pastPolicy' => 0];

            return [
                'stationId' => $stationId,
                'label' => $station?->label ?? 'Unknown station',
                'stationType' => $station?->station_type ?? 'other',
                'unitName' => $station?->unit_name,
                'controlledVends' => $vends,
                'controlledOverrides' => $overrides,
                'controlledDiscrepancies' => $counts['discrepancy_open'],
                'controlledWaste' => $counts['waste'],
                'hasDenominator' => $vends > 0,
                'denominatorCount' => $vends,
                'overrideRatePercent' => $overrideRate,
                'overrideStatus' => $this->rateStatus($overrideRate, $config['override_target_rate']),
                'openDiscrepancies' => (int) $open['open'],
                'openDiscrepanciesPastPolicy' => (int) $open['pastPolicy'],
                'oldestOpenDiscrepancyAt' => $open['oldestOpenedAt'],
                'transactionCounts' => $counts['all'],
            ];
        })
            ->sortByDesc(fn (array $row): array => [$row['openDiscrepancies'], $row['controlledVends']])
            ->values()
            ->all();
    }

    /**
     * Per-unit controlled patterns: the same override rate and open-discrepancy
     * overlay aggregated to the unit dimension.
     *
     * @param  Collection<int, object>  $rollup
     * @param  Collection<int, object>  $meta
     * @param  array<int, array{open: int, pastPolicy: int}>  $openByUnit
     * @return list<array<string, mixed>>
     */
    private function unitPatterns(Collection $rollup, Collection $meta, array $openByUnit, array $config): array
    {
        $unitIds = $rollup->pluck('unit_id')->map(fn ($id): int => (int) $id)
            ->merge(array_keys($openByUnit))
            ->unique()
            ->values();

        $grouped = $rollup->groupBy('unit_id');

        return $unitIds->map(function (int $unitId) use ($grouped, $meta, $openByUnit, $config): array {
            $counts = $this->countsByType($grouped->get($unitId) ?? collect());
            $vends = $counts['vend'];
            $overrideRate = $this->rate($counts['override'], $vends);
            $open = $openByUnit[$unitId] ?? ['open' => 0, 'pastPolicy' => 0];

            return [
                'unitId' => $unitId,
                'unitName' => $meta->get($unitId)?->name ?? 'Unknown unit',
                'controlledVends' => $vends,
                'controlledOverrides' => $counts['override'],
                'controlledDiscrepancies' => $counts['discrepancy_open'],
                'hasDenominator' => $vends > 0,
                'denominatorCount' => $vends,
                'overrideRatePercent' => $overrideRate,
                'overrideStatus' => $this->rateStatus($overrideRate, $config['override_target_rate']),
                'openDiscrepancies' => (int) $open['open'],
                'openDiscrepanciesPastPolicy' => (int) $open['pastPolicy'],
            ];
        })
            ->sortByDesc(fn (array $row): array => [$row['openDiscrepancies'], $row['controlledVends']])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $openItems
     * @param  list<array<string, mixed>>  $stations
     * @return array<string, mixed>
     */
    private function summary(array $openItems, array $stations): array
    {
        $pastPolicy = array_filter($openItems, fn (array $d): bool => $d['agingStatus'] === 'past_policy');
        $oldest = $openItems === [] ? null : max(array_column($openItems, 'minutesOpen'));

        return [
            'openDiscrepancyCount' => count($openItems),
            'openDiscrepanciesPastPolicy' => count($pastPolicy),
            'oldestOpenMinutes' => $oldest,
            'stationsWithOpenDiscrepancy' => count(array_filter($stations, fn (array $s): bool => $s['openDiscrepancies'] > 0)),
            'stationsOverOverrideTarget' => count(array_filter($stations, fn (array $s): bool => $s['overrideStatus'] === 'over_target')),
            'totalControlledVends' => array_sum(array_column($stations, 'controlledVends')),
            'totalControlledOverrides' => array_sum(array_column($stations, 'controlledOverrides')),
        ];
    }

    /**
     * The out-of-scope statement. Declared here as structured contract fields so
     * the frontend can render it verbatim AND a test can assert its presence.
     * The tone is operational and non-accusatory by construction.
     *
     * @return array<string, mixed>
     */
    private function scopeStatement(): array
    {
        return [
            'diversionInvestigationInScope' => false,
            'individualScoringInScope' => false,
            'individualPerformanceIncluded' => false,
            'userLevelDimensionIncluded' => false,
            'aggregationLevel' => 'unit_and_station',
            'statement' => 'This is an operational reconciliation view. Diversion investigation and individual scoring are out of scope: it reports open controlled-discrepancy reconciliation and override/discrepancy patterns by unit and station only. It does not identify, rank, or score any individual, and it is not a diversion-detection tool.',
            'exportEnabled' => (bool) config('pharmacy.controlled.export_enabled', false),
            'exportStatement' => 'Aggregate export is deferred and not enabled. Any future export must be separately capability-gated, audited, and free of individual data.',
            'tone' => 'operational_non_accusatory',
        ];
    }

    /**
     * Local policy contract: the shift-end reconciliation policy and override
     * target rate, presented as configuration distinct from measured values.
     *
     * @return array<string, mixed>
     */
    private function policyContract(array $config): array
    {
        return [
            'kind' => 'local_policy',
            'shiftEnd' => [
                'label' => $config['shift_label'],
                'timezone' => $config['shift_timezone'],
                'times' => $config['shift_times'],
                'graceMinutes' => $config['grace_minutes'],
                'description' => 'Controlled discrepancies are expected to be reconciled by the end of the shift in which they were opened. The applicable shift-end is the most recent configured shift boundary at or before now. This is a policy reference, not a measured event; a discrepancy past policy is flagged for operational reconciliation review, never for individual attribution.',
            ],
            'overrideTargetRate' => [
                'label' => 'Controlled override rate target',
                'ratePercent' => $config['override_target_rate'],
                'denominatorLabel' => 'Controlled ADC vend transactions in the window',
                'description' => 'Locally configured maximum controlled override rate (controlled overrides per hundred controlled vends). A policy reference line applied to the measured rate; configuration, not an observed event.',
            ],
        ];
    }

    /**
     * Reduce a rollup group (rows of transaction_type, transaction_count) into a
     * flat controlled-count map.
     *
     * @param  Collection<int, object>  $group
     * @return array{vend: int, override: int, discrepancy_open: int, waste: int, all: array<string, int>}
     */
    private function countsByType(Collection $group): array
    {
        $all = [];
        foreach ($group as $row) {
            $all[$row->transaction_type] = (int) $row->transaction_count;
        }

        return [
            'vend' => $all['vend'] ?? 0,
            'override' => $all['override'] ?? 0,
            'discrepancy_open' => $all['discrepancy_open'] ?? 0,
            'waste' => $all['waste'] ?? 0,
            'all' => $all,
        ];
    }

    /**
     * A rate is count ÷ declared denominator × 100, rounded. Zero denominator
     * ⇒ null (no data), never a fabricated 0%.
     */
    private function rate(int $count, int $denominator): ?float
    {
        if ($denominator <= 0) {
            return null;
        }

        return round($count / $denominator * 100, 1);
    }

    /**
     * POLICY fold of a measured controlled override rate against the configured
     * target. 'no_data' when the rate is null, never silently 'within'.
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
     * Station identity/label/unit map. Operational fields only — a station has a
     * label, a type, a status, and an owning unit; never a user dimension.
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
     * @param  list<array<string, mixed>>  $openItems
     * @param  list<array<string, mixed>>  $stations
     */
    private function state(array $openItems, array $stations, FreshnessEnvelope $freshness): string
    {
        if (in_array($freshness->status, ['stale', 'unknown'], true)) {
            return $freshness->status === 'unknown' ? 'degraded' : 'stale';
        }
        if ($openItems === [] && $stations === []) {
            return 'no_data';
        }

        return 'normal';
    }

    private function stateMessage(string $state): string
    {
        return match ($state) {
            'no_data' => 'No controlled discrepancies are open and no controlled cabinet transactions are in the current window.',
            'stale' => 'Controlled evidence is stale; open discrepancies and patterns are shown as-of the last source cutoff.',
            'degraded' => 'Controlled source coverage is partial; patterns are shown where a denominator exists.',
            default => 'Controlled substance reconciliation facts are current.',
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
                explanation: 'No automated dispensing cabinet evidence is available; controlled patterns are unknown until a fresh source cutoff arrives.',
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
                : ($stale ? 'The latest controlled evidence exceeds its freshness tolerance.' : null),
        );
    }
}
