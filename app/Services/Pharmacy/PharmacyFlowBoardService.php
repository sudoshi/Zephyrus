<?php

namespace App\Services\Pharmacy;

use App\Data\Ancillary\FreshnessEnvelope;
use App\Models\Ancillary\AncillarySlaDefinition;
use App\Services\Ancillary\AncillaryContractSerializer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Medication Flow Board query service. Owns filters, aggregates, clock
 * segments, breach summaries, and freshness qualification server-side (§5.1:
 * React never recomputes authoritative status). Real-time order-to-dispense
 * evidence renders separately from the WAREHOUSE-QUALIFIED dispense-to-admin
 * tail, which always carries the as-of cutoff from
 * PharmacyAdministrationFreshnessService and can never render as real-time.
 * No pharmacist, verifier, or user-level dimension exists anywhere in this
 * contract (§13: unit/station aggregates only).
 */
final class PharmacyFlowBoardService
{
    public const LENSES = ['all', 'stat', 'first_dose', 'sepsis', 'shortage', 'discharge', 'degraded'];

    public const CLOCK_CLASSES = ['stat', 'first_dose', 'sepsis', 'routine', 'timed', 'discharge'];

    public const BRANCHES = ['adc', 'iv_room', 'central', 'unknown'];

    public const STATUSES = ['ordered', 'queued', 'verified', 'preparing', 'ready', 'dispensed', 'delivered', 'administered', 'held', 'discontinued', 'cancelled', 'completed'];

    public const DRILL_SOURCES = ['flow_board', 'ancillary_services', 'ed', 'rtdc', 'periop', 'cockpit'];

    /** SLA clock classes rendered on the board, keyed to their governed definitions (§8). */
    public const CLOCK_METRICS = ['stat' => 'rx.stat_dispense', 'first_dose' => 'rx.first_dose_admin', 'sepsis' => 'rx.sepsis_abx'];

    private const OPEN_STATUS_PREDICATE = "x.order_status NOT IN ('administered', 'discontinued', 'cancelled', 'completed')";

    public function __construct(
        private readonly AncillaryContractSerializer $contracts,
        private readonly PharmacyAdministrationFreshnessService $administrationFreshness,
        private readonly PharmacyForecastService $forecasts,
    ) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function build(array $filters = [], bool $canAnnotateBarriers = false, bool $canViewPatientDetail = true): array
    {
        $filters = $this->filters($filters);
        $ids = $this->baseQuery($filters)->pluck('o.ancillary_order_id')->map(fn (mixed $id): int => (int) $id)->all();
        $sourceCutoff = $this->baseQuery($filters)->max('o.source_cutoff_at');
        $freshness = $this->freshness($sourceCutoff === null ? null : CarbonImmutable::parse($sourceCutoff));
        $adminEnvelope = $this->administrationTailEnvelope();
        $definitions = $this->definitions();
        $summary = $this->summary($ids, $definitions);
        $state = $this->state($summary, $freshness, $adminEnvelope);

        return [
            'generatedAt' => now()->toAtomString(),
            'sourceCutoffAt' => $sourceCutoff === null ? null : CarbonImmutable::parse($sourceCutoff)->toAtomString(),
            'freshnessStatus' => $freshness['status'],
            'degradedMode' => $state === 'degraded',
            'state' => $state,
            'stateMessage' => $this->stateMessage($state),
            'freshness' => $freshness,
            'administrationFreshness' => $this->contracts->freshness($adminEnvelope),
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'appliedSlaDefinitions' => $definitions->map(fn (AncillarySlaDefinition $definition): array => $this->contracts->slaDefinition($definition))->values()->all(),
            'planningForecast' => [
                'requested' => $filters['forecast'],
                'enabled' => $filters['forecast'],
                'queue' => $filters['forecast'] ? $this->forecasts->queueForecast() : null,
                'explanation' => $filters['forecast']
                    ? 'Synthetic planning forecast requested. It is isolated from observed queue state and governed SLA evaluation.'
                    : 'Planning forecasts are off by default. Add forecast=1 to request the separate synthetic queue projection.',
            ],
            'data' => [
                'summary' => $summary,
                'verificationQueue' => $this->verificationQueue($ids),
                'clockClasses' => $this->clockClasses($ids, $definitions, $adminEnvelope),
                'segments' => $this->segments($ids, $freshness, $adminEnvelope),
                'preparationBranches' => $this->preparationBranches($ids),
                'sepsisTimers' => $this->sepsisTimers($ids, $definitions, $adminEnvelope, $canViewPatientDetail),
                'oldestItems' => $this->oldestItems($ids, $definitions, $adminEnvelope, $canViewPatientDetail),
                'barrierPareto' => $this->barrierPareto($ids),
            ],
            'barrierReasons' => $this->barrierReasons(),
            'privacy' => [
                'directPatientIdentifiersIncluded' => false,
                'doseInstructionsIncluded' => false,
                'individualPerformanceIncluded' => false,
                'identifierPolicy' => 'Pseudonymous patient and encounter display references only. No pharmacist, nurse, verifier, or any user-level performance dimension is computed or exposed.',
            ],
            'canAnnotateBarriers' => $canAnnotateBarriers,
            'canViewPatientDetail' => $canViewPatientDetail,
        ];
    }

    /**
     * Aggregate-only health contract for the Flow-domain Cockpit. The Pharmacy
     * workspace remains the owner of cohort selection, queue depth, STAT aging,
     * sepsis-clock evaluation, stockout state, source cutoff, and freshness; no
     * order, medication, or patient detail crosses this seam. Real-time signals
     * (verification queue, oldest STAT) are current; the sepsis-at-risk signal
     * depends on administration evidence and is qualified by the warehouse
     * administration cutoff so a stale batch tail can never assert a false
     * success or failure (§8: freshness-qualified). No pharmacist, verifier, or
     * any user-level dimension is computed here (§13: unit/station aggregates).
     *
     * @return array<string, mixed>
     */
    public function cockpitHealth(): array
    {
        $filters = $this->filters([]);
        $ids = $this->baseQuery($filters)->pluck('o.ancillary_order_id')->map(fn (mixed $id): int => (int) $id)->all();
        $sourceCutoff = $this->baseQuery($filters)->max('o.source_cutoff_at');
        $sourceCutoffAt = $sourceCutoff === null ? null : CarbonImmutable::parse($sourceCutoff);
        $freshness = $this->freshness($sourceCutoffAt);
        $adminEnvelope = $this->administrationTailEnvelope();
        $definitions = $this->definitions();
        $summary = $this->summary($ids, $definitions);
        $verification = $this->verificationQueue($ids);
        $sepsis = $this->sepsisAtRisk($ids, $definitions, $adminEnvelope);
        $stockouts = $this->shortageStockouts($ids);
        $registered = strtolower((string) (DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->value('status') ?? ''));

        return [
            'sourceState' => match (true) {
                in_array($registered, ['error', 'failed', 'unavailable'], true) => 'error',
                $summary['currentOrders'] === 0 => 'missing',
                $freshness['status'] === 'stale' => 'stale',
                default => 'fresh',
            },
            'coverageState' => $summary['degradedOrders'] > 0 ? 'degraded' : 'complete',
            'sourceCutoffAt' => $sourceCutoffAt?->toAtomString(),
            'sourceLabel' => $freshness['sourceLabel'],
            'verificationQueue' => [
                'depth' => (int) $verification['depth'],
                'hourNormDepth' => $this->verificationHourNorm(),
                'oldestAgeMinutes' => $verification['oldestAgeMinutes'],
            ],
            'oldestStatAgeMinutes' => $this->oldestOpenStatAgeMinutes($ids),
            'sepsisAtRisk' => $sepsis,
            'shortageStockouts' => $stockouts,
        ];
    }

    /**
     * The hour-norm baseline for verification queue depth: the mean number of
     * verifications entering the queue per distinct clock hour across the
     * retained 24-hour window. It is a contextual comparison for the live
     * depth only — never authoritative status. Null when no history exists.
     */
    private function verificationHourNorm(): ?int
    {
        $row = DB::table('prod.rx_verifications')
            ->where('queued_at', '>=', now()->subDay())
            ->selectRaw("count(*) AS total, count(DISTINCT date_trunc('hour', queued_at)) AS hours")
            ->first();
        $hours = (int) ($row->hours ?? 0);

        return $hours === 0 ? null : (int) round((int) $row->total / $hours);
    }

    /**
     * Age of the oldest STAT medication order still open (unverified or
     * undispensed) — a real-time operational signal computed from the same
     * open-status predicate the board uses. Null when no open STAT order
     * remains in the cohort.
     *
     * @param  list<int>  $ids
     */
    private function oldestOpenStatAgeMinutes(array $ids): ?int
    {
        if ($ids === []) {
            return null;
        }
        $oldest = DB::table('prod.ancillary_orders as o')
            ->join('prod.rx_orders as x', 'x.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->whereIn('o.ancillary_order_id', $ids)
            ->where('x.clock_class', 'stat')
            ->whereRaw($this->openStatusPredicate())
            ->min('o.ordered_at');

        return $oldest === null ? null : max(0, (int) floor(CarbonImmutable::parse($oldest)->diffInSeconds(now(), false) / 60));
    }

    /**
     * Aggregate count of open sepsis-antibiotic orders at or past their
     * governed operational clock (recorded open breaches plus computed open
     * warnings). Because the sepsis clock stops on warehouse-observed
     * administration evidence, the count is qualified by the administration
     * cutoff: when the tail is stale or absent the count is deliberately null
     * and the state is `unknown` — never a false success (all-clear) or false
     * failure. Recorded breaches remain visible as historical facts.
     *
     * @param  list<int>  $ids
     * @param  Collection<int, AncillarySlaDefinition>  $definitions
     * @return array<string, mixed>
     */
    private function sepsisAtRisk(array $ids, Collection $definitions, FreshnessEnvelope $adminEnvelope): array
    {
        $definition = $definitions->firstWhere('metric_key', self::CLOCK_METRICS['sepsis']);
        $tailUnavailable = in_array($adminEnvelope->status, ['stale', 'unknown'], true);
        $openBreaches = $ids === [] || $definition === null ? 0 : $this->breachQuery($ids)->where('b.status', 'open')->where('d.metric_key', self::CLOCK_METRICS['sepsis'])->count();
        $openWarnings = $tailUnavailable || $ids === [] || $definition === null ? null : $this->openWarnings($ids, 'sepsis', $definition);

        return [
            'value' => $tailUnavailable ? null : $openBreaches + (int) ($openWarnings ?? 0),
            'openBreaches' => $openBreaches,
            'openWarnings' => $openWarnings,
            'administrationState' => $adminEnvelope->status,
            'administrationCutoffAt' => $adminEnvelope->sourceCutoffAt?->format(DATE_ATOM),
            'explanation' => $tailUnavailable
                ? 'The warehouse administration tail is not current; whether open sepsis antibiotics have been administered cannot be asserted. Recorded breaches remain visible, but no at-risk all-clear or failure is claimed.'
                : 'The sepsis antibiotic at-risk count reflects open governed breaches and computed warnings, qualified by the administration batch cutoff.',
        ];
    }

    /**
     * Count of stations carrying an active stockout that maps to a medication
     * currently flagged on_shortage in the open cohort (the shortage-drug
     * subset of all station stockouts). Station-wide (`*`) stockouts count when
     * any open shortage order exists. Aggregates by station only — no user or
     * individual dimension.
     *
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function shortageStockouts(array $ids): array
    {
        $shortageCodes = $ids === [] ? collect() : DB::table('prod.ancillary_orders as o')
            ->join('prod.rx_orders as x', 'x.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->whereIn('o.ancillary_order_id', $ids)
            ->where('x.on_shortage', true)
            ->distinct()
            ->pluck('x.local_code')
            ->filter()
            ->map(fn (mixed $code): string => (string) $code)
            ->flip();
        $hasShortageOrder = $shortageCodes->isNotEmpty();

        $stations = DB::table('prod.adc_stations')
            ->whereRaw("coalesce(metadata->'open_stockouts', '{}'::jsonb) NOT IN ('{}'::jsonb, '[]'::jsonb)")
            ->pluck('metadata');

        $affected = 0;
        foreach ($stations as $metadata) {
            $decoded = is_array($metadata) ? $metadata : (json_decode((string) $metadata, true) ?: []);
            $open = is_array($decoded['open_stockouts'] ?? null) ? array_keys($decoded['open_stockouts']) : [];
            $stationAffects = collect($open)->contains(
                fn (string $key): bool => ($key === '*' && $hasShortageOrder) || $shortageCodes->has($key),
            );
            if ($stationAffects) {
                $affected++;
            }
        }

        return ['stations' => $affected, 'shortageOrders' => $shortageCodes->count()];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function filters(array $input): array
    {
        $lens = is_string($input['lens'] ?? null) && in_array($input['lens'], self::LENSES, true) ? $input['lens'] : 'all';
        $clockClass = is_string($input['clockClass'] ?? null) && in_array($input['clockClass'], self::CLOCK_CLASSES, true) ? $input['clockClass'] : null;
        $branch = is_string($input['branch'] ?? null) && in_array($input['branch'], self::BRANCHES, true) ? $input['branch'] : null;
        $status = is_string($input['status'] ?? null) && in_array($input['status'], self::STATUSES, true) ? $input['status'] : null;
        $unitId = filter_var($input['unitId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $source = is_string($input['source'] ?? null) && in_array($input['source'], self::DRILL_SOURCES, true) ? $input['source'] : null;
        $forecast = filter_var($input['forecast'] ?? false, FILTER_VALIDATE_BOOL);

        return ['lens' => $lens, 'clockClass' => $clockClass, 'branch' => $branch, 'status' => $status, 'unitId' => $unitId === false ? null : $unitId, 'source' => $source, 'forecast' => $forecast];
    }

    /** @return array<string, mixed> */
    private function filterOptions(): array
    {
        return [
            'lenses' => self::LENSES,
            'clockClasses' => DB::table('prod.rx_orders')->distinct()->orderBy('clock_class')->pluck('clock_class')->all(),
            'branches' => DB::table('prod.rx_orders')->distinct()->orderBy('preparation_branch')->pluck('preparation_branch')->all(),
            'statuses' => DB::table('prod.rx_orders')->distinct()->orderBy('order_status')->pluck('order_status')->all(),
            'units' => DB::table('prod.units as u')->join('prod.ancillary_orders as o', 'o.unit_id', '=', 'u.unit_id')->where('o.department', 'rx')->where('u.is_deleted', false)->distinct()->orderBy('u.name')->get(['u.unit_id', 'u.name'])->map(fn (object $row): array => ['unitId' => (int) $row->unit_id, 'label' => $row->name])->all(),
        ];
    }

    /** @param array<string, mixed> $filters */
    private function baseQuery(array $filters): Builder
    {
        return DB::table('prod.ancillary_orders as o')
            ->join('prod.rx_orders as x', 'x.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->where('o.department', 'rx')
            ->where('o.ordered_at', '>=', now()->subDay())
            ->whereRaw("COALESCE(o.metadata->>'operational_window', 'current') <> 'historical_study_only'")
            ->when($filters['clockClass'], fn (Builder $query, string $clockClass): Builder => $query->where('x.clock_class', $clockClass))
            ->when($filters['branch'], fn (Builder $query, string $branch): Builder => $query->where('x.preparation_branch', $branch))
            ->when($filters['status'], fn (Builder $query, string $status): Builder => $query->where('x.order_status', $status))
            ->when($filters['unitId'], fn (Builder $query, int $unitId): Builder => $query->where('o.unit_id', $unitId))
            ->when($filters['lens'] === 'stat', fn (Builder $query): Builder => $query->where('x.clock_class', 'stat'))
            ->when($filters['lens'] === 'first_dose', fn (Builder $query): Builder => $query->where('x.clock_class', 'first_dose'))
            ->when($filters['lens'] === 'sepsis', fn (Builder $query): Builder => $query->where('x.clock_class', 'sepsis'))
            ->when($filters['lens'] === 'shortage', fn (Builder $query): Builder => $query->where('x.on_shortage', true))
            ->when($filters['lens'] === 'discharge', fn (Builder $query): Builder => $query->where('x.clock_class', 'discharge'))
            ->when($filters['lens'] === 'degraded', fn (Builder $query): Builder => $query->whereRaw($this->degradedPredicate()));
    }

    /**
     * The IVWMS-absent degraded branch: an IV-room order with neither
     * preparation satellite rows nor a preparation milestone. Its verify-to-
     * dispense interior remains a coarse clock — never a fabricated zero.
     */
    private function degradedPredicate(): string
    {
        return <<<'SQL'
            (
                x.preparation_branch = 'iv_room'
                AND NOT EXISTS (SELECT 1 FROM prod.rx_preps dp WHERE dp.rx_order_id = x.rx_order_id)
                AND NOT EXISTS (
                    SELECT 1 FROM prod.ancillary_milestones dm
                    WHERE dm.ancillary_order_id = o.ancillary_order_id AND dm.milestone_code = 'RX_PREP_STARTED'
                )
            )
        SQL;
    }

    /** @return Collection<int, AncillarySlaDefinition> */
    private function definitions(): Collection
    {
        return AncillarySlaDefinition::query()
            ->activeAt(now())
            ->where('department', 'rx')
            ->orderBy('metric_key')
            ->get();
    }

    /** @param list<int> $ids @param Collection<int, AncillarySlaDefinition> $definitions @return array<string, mixed> */
    private function summary(array $ids, Collection $definitions): array
    {
        if ($ids === []) {
            return [
                'currentOrders' => 0, 'openOrders' => 0, 'statOrders' => 0, 'statCompliant' => 0,
                'statCompliancePercent' => null, 'verificationQueueDepth' => 0, 'openBreaches' => 0,
                'shortageOrders' => 0, 'dischargeOrders' => 0, 'controlledOrders' => 0, 'degradedOrders' => 0,
            ];
        }

        $statBreachMinutes = (int) ($definitions->firstWhere('metric_key', self::CLOCK_METRICS['stat'])?->breach_minutes ?? 15);
        $row = DB::table('prod.ancillary_orders as o')
            ->join('prod.rx_orders as x', 'x.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->whereIn('o.ancillary_order_id', $ids)
            ->selectRaw(<<<SQL
                count(*) AS current_orders,
                count(*) FILTER (WHERE {$this->openStatusPredicate()}) AS open_orders,
                count(*) FILTER (WHERE x.clock_class = 'stat') AS stat_orders,
                count(*) FILTER (WHERE x.clock_class = 'stat' AND EXISTS (
                    SELECT 1 FROM prod.ancillary_current_assertions v
                    WHERE v.ancillary_order_id = o.ancillary_order_id
                      AND v.milestone_code = 'RX_DISPENSED'
                      AND EXTRACT(EPOCH FROM (v.occurred_at - o.ordered_at)) / 60 <= ?
                )) AS stat_compliant,
                count(*) FILTER (WHERE x.on_shortage) AS shortage_orders,
                count(*) FILTER (WHERE x.clock_class = 'discharge') AS discharge_orders,
                count(*) FILTER (WHERE x.is_controlled) AS controlled_orders,
                count(*) FILTER (WHERE {$this->degradedPredicate()}) AS degraded_orders
            SQL, [$statBreachMinutes])
            ->first();
        $statOrders = (int) $row->stat_orders;

        return [
            'currentOrders' => (int) $row->current_orders,
            'openOrders' => (int) $row->open_orders,
            'statOrders' => $statOrders,
            'statCompliant' => (int) $row->stat_compliant,
            'statCompliancePercent' => $statOrders === 0 ? null : round(((int) $row->stat_compliant / $statOrders) * 100, 1),
            'verificationQueueDepth' => $this->queuedVerifications($ids)->count(),
            'openBreaches' => $this->breachQuery($ids)->where('b.status', 'open')->count(),
            'shortageOrders' => (int) $row->shortage_orders,
            'dischargeOrders' => (int) $row->discharge_orders,
            'controlledOrders' => (int) $row->controlled_orders,
            'degradedOrders' => (int) $row->degraded_orders,
        ];
    }

    /** @param list<int> $ids */
    private function queuedVerifications(array $ids): Builder
    {
        return DB::table('prod.rx_verifications as v')
            ->join('prod.rx_orders as x', 'x.rx_order_id', '=', 'v.rx_order_id')
            ->whereIn('x.ancillary_order_id', $ids)
            ->where('v.verification_state', 'queued');
    }

    /** @param list<int> $ids @return array<string, mixed> */
    private function verificationQueue(array $ids): array
    {
        $buckets = [
            ['key' => 'under_15', 'label' => '< 15 min', 'count' => 0],
            ['key' => '15_to_30', 'label' => '15–30 min', 'count' => 0],
            ['key' => '30_to_60', 'label' => '30–60 min', 'count' => 0],
            ['key' => '60_plus', 'label' => '60+ min', 'count' => 0],
        ];
        if ($ids === []) {
            return ['depth' => 0, 'oldestAgeMinutes' => null, 'medianAgeMinutes' => null, 'ageDistribution' => $buckets];
        }

        // Bind the application clock: PG now() is the wall clock, not the frozen test clock.
        $row = $this->queuedVerifications($ids)
            ->selectRaw(<<<'SQL'
                count(*) AS depth,
                percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (?::timestamptz - v.queued_at)) / 60) AS median_age,
                max(EXTRACT(EPOCH FROM (?::timestamptz - v.queued_at)) / 60) AS oldest_age,
                count(*) FILTER (WHERE EXTRACT(EPOCH FROM (?::timestamptz - v.queued_at)) / 60 < 15) AS bucket_under_15,
                count(*) FILTER (WHERE EXTRACT(EPOCH FROM (?::timestamptz - v.queued_at)) / 60 >= 15 AND EXTRACT(EPOCH FROM (?::timestamptz - v.queued_at)) / 60 < 30) AS bucket_15_to_30,
                count(*) FILTER (WHERE EXTRACT(EPOCH FROM (?::timestamptz - v.queued_at)) / 60 >= 30 AND EXTRACT(EPOCH FROM (?::timestamptz - v.queued_at)) / 60 < 60) AS bucket_30_to_60,
                count(*) FILTER (WHERE EXTRACT(EPOCH FROM (?::timestamptz - v.queued_at)) / 60 >= 60) AS bucket_60_plus
            SQL, array_fill(0, 8, now()->toIso8601String()))
            ->first();

        $buckets[0]['count'] = (int) $row->bucket_under_15;
        $buckets[1]['count'] = (int) $row->bucket_15_to_30;
        $buckets[2]['count'] = (int) $row->bucket_30_to_60;
        $buckets[3]['count'] = (int) $row->bucket_60_plus;

        return [
            'depth' => (int) $row->depth,
            'oldestAgeMinutes' => $row->oldest_age === null ? null : max(0, (int) floor((float) $row->oldest_age)),
            'medianAgeMinutes' => $row->median_age === null ? null : round(max(0, (float) $row->median_age), 1),
            'ageDistribution' => $buckets,
        ];
    }

    /** @param list<int> $ids */
    private function breachQuery(array $ids): Builder
    {
        return DB::table('prod.ancillary_breaches as b')
            ->join('prod.ancillary_sla_definitions as d', 'd.ancillary_sla_definition_id', '=', 'b.ancillary_sla_definition_id')
            ->where('d.department', 'rx')
            ->whereIn('b.ancillary_order_id', $ids);
    }

    /**
     * Breach summary per SLA clock class: open breaches and cleared breaches
     * are recorded facts from prod.ancillary_breaches; open warnings are
     * computed against the same definition thresholds — and become unknown,
     * never falsely breached or compliant, when an administration-stop clock
     * has a stale warehouse tail (§8).
     *
     * @param  list<int>  $ids
     * @param  Collection<int, AncillarySlaDefinition>  $definitions
     * @return list<array<string, mixed>>
     */
    private function clockClasses(array $ids, Collection $definitions, FreshnessEnvelope $adminEnvelope): array
    {
        $rows = [];
        foreach (self::CLOCK_METRICS as $clockClass => $metricKey) {
            $definition = $definitions->firstWhere('metric_key', $metricKey);
            if ($definition === null) {
                continue;
            }

            $adminTail = $definition->stop_milestone_code === 'RX_ADMINISTERED';
            $tailUnavailable = $adminTail && in_array($adminEnvelope->status, ['stale', 'unknown'], true);
            $openBreaches = $ids === [] ? 0 : $this->breachQuery($ids)->where('b.status', 'open')->where('d.metric_key', $metricKey)->count();
            $clearedBreaches = $ids === [] ? 0 : $this->breachQuery($ids)->where('b.status', 'cleared')->where('d.metric_key', $metricKey)->count();
            $oldestOpenBreachedAt = $ids === [] ? null : $this->breachQuery($ids)->where('b.status', 'open')->where('d.metric_key', $metricKey)->min('b.breached_at');
            $openWarnings = $tailUnavailable ? null : $this->openWarnings($ids, $clockClass, $definition);
            $state = match (true) {
                $tailUnavailable => 'unknown',
                $openBreaches > 0 => 'breach',
                ($openWarnings ?? 0) > 0 => 'warning',
                default => 'normal',
            };

            $rows[] = [
                'clockClass' => $clockClass,
                'metricKey' => $metricKey,
                'label' => (string) $definition->label,
                'definition' => $this->contracts->slaDefinition($definition),
                'openOrders' => $ids === [] ? 0 : $this->openOrdersInClass($ids, $clockClass),
                'openBreaches' => $openBreaches,
                'openWarnings' => $openWarnings,
                'clearedBreaches' => $clearedBreaches,
                'oldestOpenBreachAgeMinutes' => $oldestOpenBreachedAt === null ? null : max(0, (int) floor(CarbonImmutable::parse($oldestOpenBreachedAt)->diffInSeconds(now(), false) / 60)),
                'adminTail' => $adminTail,
                'state' => $state,
                'explanation' => match (true) {
                    $tailUnavailable => 'The warehouse administration tail is not current; open warnings are unknown and neither compliance nor breach can be newly asserted for this clock. Recorded breaches remain visible as historical facts.',
                    $adminTail => 'This clock stops on warehouse-observed administration evidence and is qualified by the batch cutoff; it is never real-time.',
                    default => 'This clock runs on real-time operational feeds.',
                },
            ];
        }

        return $rows;
    }

    /** @param list<int> $ids */
    private function openOrdersInClass(array $ids, string $clockClass): int
    {
        return DB::table('prod.ancillary_orders as o')
            ->join('prod.rx_orders as x', 'x.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->whereIn('o.ancillary_order_id', $ids)
            ->where('x.clock_class', $clockClass)
            ->whereRaw($this->openStatusPredicate())
            ->count();
    }

    /**
     * Orders of the class past the warning threshold with no stop assertion
     * and no already-open breach row (the evaluator owns breach opening).
     *
     * @param  list<int>  $ids
     */
    private function openWarnings(array $ids, string $clockClass, AncillarySlaDefinition $definition): int
    {
        if ($ids === [] || $definition->warning_minutes === null) {
            return 0;
        }

        return DB::table('prod.ancillary_orders as o')
            ->join('prod.rx_orders as x', 'x.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->join('prod.ancillary_current_assertions as s', function ($join) use ($definition): void {
                $join->on('s.ancillary_order_id', '=', 'o.ancillary_order_id')->where('s.milestone_code', $definition->start_milestone_code);
            })
            ->whereIn('o.ancillary_order_id', $ids)
            ->where('x.clock_class', $clockClass)
            ->whereRaw($this->openStatusPredicate())
            ->whereNotExists(fn (Builder $stop): Builder => $stop->selectRaw('1')->from('prod.ancillary_current_assertions as t')->whereColumn('t.ancillary_order_id', 'o.ancillary_order_id')->where('t.milestone_code', $definition->stop_milestone_code))
            ->whereNotExists(fn (Builder $open): Builder => $open->selectRaw('1')->from('prod.ancillary_breaches as ob')->whereColumn('ob.ancillary_order_id', 'o.ancillary_order_id')->where('ob.ancillary_sla_definition_id', $definition->ancillary_sla_definition_id)->where('ob.status', 'open'))
            ->whereRaw('EXTRACT(EPOCH FROM (?::timestamptz - s.occurred_at)) / 60 >= ?', [now()->toIso8601String(), (int) $definition->warning_minutes])
            ->count();
    }

    /**
     * The two-segment honesty split: real-time order-to-dispense evidence and
     * the warehouse-qualified dispense-to-administration tail. The tail is
     * ALWAYS labeled with the administration as-of cutoff and never claims a
     * real-time basis.
     *
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $freshness
     * @return array<string, mixed>
     */
    private function segments(array $ids, array $freshness, FreshnessEnvelope $adminEnvelope): array
    {
        $orderToDispense = ['count' => 0, 'medianMinutes' => null, 'p90Minutes' => null];
        $dispenseToAdmin = ['count' => 0, 'medianMinutes' => null, 'p90Minutes' => null];
        if ($ids !== []) {
            $row = DB::table('prod.ancillary_current_assertions as s')
                ->join('prod.ancillary_current_assertions as t', fn ($join) => $join->on('t.ancillary_order_id', '=', 's.ancillary_order_id')->where('t.milestone_code', 'RX_DISPENSED'))
                ->whereIn('s.ancillary_order_id', $ids)
                ->where('s.milestone_code', 'RX_ORDERED')
                ->whereColumn('t.occurred_at', '>=', 's.occurred_at')
                ->selectRaw('count(*) AS n, percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (t.occurred_at - s.occurred_at)) / 60) AS median, percentile_cont(0.9) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (t.occurred_at - s.occurred_at)) / 60) AS p90')
                ->first();
            $orderToDispense = ['count' => (int) ($row->n ?? 0), 'medianMinutes' => isset($row->median) ? round((float) $row->median, 1) : null, 'p90Minutes' => isset($row->p90) ? round((float) $row->p90, 1) : null];

            $row = DB::table('prod.rx_administrations as a')
                ->join('prod.rx_orders as x', 'x.rx_order_id', '=', 'a.rx_order_id')
                ->join('prod.ancillary_current_assertions as t', fn ($join) => $join->on('t.ancillary_order_id', '=', 'x.ancillary_order_id')->where('t.milestone_code', 'RX_DISPENSED'))
                ->whereIn('x.ancillary_order_id', $ids)
                ->where('a.administration_status', 'given')
                ->whereNotExists(fn (Builder $newer): Builder => $newer->selectRaw('1')->from('prod.rx_administrations as nv')->whereColumn('nv.source_id', 'a.source_id')->whereColumn('nv.source_administration_key', 'a.source_administration_key')->whereColumn('nv.rx_administration_id', '>', 'a.rx_administration_id'))
                ->whereColumn('a.administered_at', '>=', 't.occurred_at')
                ->selectRaw('count(*) AS n, percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (a.administered_at - t.occurred_at)) / 60) AS median, percentile_cont(0.9) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (a.administered_at - t.occurred_at)) / 60) AS p90')
                ->first();
            $dispenseToAdmin = ['count' => (int) ($row->n ?? 0), 'medianMinutes' => isset($row->median) ? round((float) $row->median, 1) : null, 'p90Minutes' => isset($row->p90) ? round((float) $row->p90, 1) : null];
        }

        return [
            'orderToDispense' => [
                ...$orderToDispense,
                'basis' => 'real_time',
                'definition' => 'Selected RX_ORDERED to RX_DISPENSED assertions across the filtered cohort; sourced from real-time operational feeds.',
                'freshness' => $freshness,
            ],
            'dispenseToAdmin' => [
                ...$dispenseToAdmin,
                'basis' => 'as_of_cutoff',
                'sourceCutoffAt' => $adminEnvelope->sourceCutoffAt?->format(DATE_ATOM),
                'definition' => 'Selected RX_DISPENSED assertions to warehouse-observed administration evidence; cutoff-qualified and never real-time.',
                'freshness' => $this->contracts->freshness($adminEnvelope),
            ],
        ];
    }

    /** @param list<int> $ids @return array<string, mixed> */
    private function preparationBranches(array $ids): array
    {
        $labels = ['adc' => 'ADC cabinet', 'iv_room' => 'IV room', 'central' => 'Central pharmacy', 'unknown' => 'Unassigned branch'];
        $counts = $ids === [] ? collect() : DB::table('prod.ancillary_orders as o')
            ->join('prod.rx_orders as x', 'x.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->whereIn('o.ancillary_order_id', $ids)
            ->selectRaw("x.preparation_branch AS branch, count(*) AS total_count, count(*) FILTER (WHERE {$this->openStatusPredicate()}) AS open_count, count(*) FILTER (WHERE {$this->degradedPredicate()}) AS degraded_count")
            ->groupBy('x.preparation_branch')
            ->get()
            ->keyBy('branch');
        $degradedTotal = (int) $counts->sum('degraded_count');

        return [
            'branches' => collect(self::BRANCHES)->map(fn (string $branch): array => [
                'branch' => $branch,
                'label' => $labels[$branch],
                'orders' => (int) ($counts[$branch]->total_count ?? 0),
                'openOrders' => (int) ($counts[$branch]->open_count ?? 0),
                'degradedOrders' => (int) ($counts[$branch]->degraded_count ?? 0),
            ])->values()->all(),
            'ivwms' => [
                'status' => $degradedTotal > 0 ? 'partial' : 'available',
                'degradedOrders' => $degradedTotal,
                'explanation' => $degradedTotal > 0
                    ? 'IV workflow evidence is missing for some IV-room orders; their verify-to-dispense interior remains a coarse clock and preparation duration is not reported as zero.'
                    : 'IV workflow preparation evidence is available for the IV-room branch.',
            ],
        ];
    }

    /**
     * Sepsis antibiotic timers tied to the rx.sepsis_abx definition. Real-time
     * segments (order/verify/dispense/deliver) run on operational feeds; the
     * administration segment renders strictly as-of the warehouse cutoff and
     * becomes unknown — never success or failure — when the tail is stale.
     *
     * @param  list<int>  $ids
     * @param  Collection<int, AncillarySlaDefinition>  $definitions
     * @return list<array<string, mixed>>
     */
    private function sepsisTimers(array $ids, Collection $definitions, FreshnessEnvelope $adminEnvelope, bool $canViewPatientDetail): array
    {
        if ($ids === []) {
            return [];
        }
        $definition = $definitions->firstWhere('metric_key', self::CLOCK_METRICS['sepsis']);
        $orders = DB::table('prod.ancillary_orders as o')
            ->join('prod.rx_orders as x', 'x.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->leftJoin('prod.units as u', 'u.unit_id', '=', 'o.unit_id')
            ->whereIn('o.ancillary_order_id', $ids)
            ->where('x.clock_class', 'sepsis')
            ->orderBy('o.ordered_at')
            ->get(['o.ancillary_order_id', 'o.order_uuid', 'o.patient_ref', 'o.patient_class', 'o.ordered_at', 'x.rx_order_id', 'x.medication_label', 'x.order_status', 'u.name as unit_name']);
        if ($orders->isEmpty()) {
            return [];
        }

        $orderIds = $orders->pluck('ancillary_order_id')->map(fn (mixed $id): int => (int) $id)->all();
        $assertions = DB::table('prod.ancillary_current_assertions')
            ->whereIn('ancillary_order_id', $orderIds)
            ->whereIn('milestone_code', ['RX_ORDERED', 'RX_VERIFIED', 'RX_DISPENSED', 'RX_DELIVERED'])
            ->get(['ancillary_order_id', 'milestone_code', 'occurred_at'])
            ->groupBy('ancillary_order_id');
        $administrations = DB::table('prod.rx_administrations as a')
            ->whereIn('a.rx_order_id', $orders->pluck('rx_order_id')->all())
            ->where('a.administration_status', 'given')
            ->whereNotExists(fn (Builder $newer): Builder => $newer->selectRaw('1')->from('prod.rx_administrations as nv')->whereColumn('nv.source_id', 'a.source_id')->whereColumn('nv.source_administration_key', 'a.source_administration_key')->whereColumn('nv.rx_administration_id', '>', 'a.rx_administration_id'))
            ->orderBy('a.administered_at')
            ->get(['a.rx_order_id', 'a.administered_at', 'a.source_cutoff_at'])
            ->groupBy('rx_order_id');
        $openBreachIds = $definition === null ? collect() : DB::table('prod.ancillary_breaches')
            ->whereIn('ancillary_order_id', $orderIds)
            ->where('ancillary_sla_definition_id', $definition->ancillary_sla_definition_id)
            ->where('status', 'open')
            ->pluck('ancillary_order_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->flip();

        $stageLabels = ['RX_ORDERED' => 'Ordered', 'RX_VERIFIED' => 'Verified', 'RX_DISPENSED' => 'Dispensed', 'RX_DELIVERED' => 'Delivered'];
        $tailUnavailable = in_array($adminEnvelope->status, ['stale', 'unknown'], true);

        return $orders->map(function (object $order) use ($assertions, $administrations, $openBreachIds, $definition, $adminEnvelope, $tailUnavailable, $stageLabels, $canViewPatientDetail): array {
            $orderId = (int) $order->ancillary_order_id;
            $byCode = ($assertions[$orderId] ?? collect())->keyBy('milestone_code');
            $orderedAt = CarbonImmutable::parse($order->ordered_at);
            $administration = ($administrations[(int) $order->rx_order_id] ?? collect())->first();
            $administeredAt = $administration !== null ? CarbonImmutable::parse($administration->administered_at) : null;
            $elapsed = max(0, (int) floor($orderedAt->diffInSeconds(($administeredAt ?? now()), false) / 60));

            $adminSegment = match (true) {
                $administeredAt !== null => [
                    'state' => 'administered_as_of',
                    'administeredAt' => $administeredAt->toAtomString(),
                    'sourceCutoffAt' => CarbonImmutable::parse($administration->source_cutoff_at)->toAtomString(),
                    'elapsedMinutes' => max(0, (int) floor($orderedAt->diffInSeconds($administeredAt, false) / 60)),
                    'explanation' => 'Administration observed in the warehouse extract; the fact is current only as of the batch cutoff, never real-time.',
                ],
                $tailUnavailable => [
                    'state' => 'unknown',
                    'administeredAt' => null,
                    'sourceCutoffAt' => $adminEnvelope->sourceCutoffAt?->format(DATE_ATOM),
                    'elapsedMinutes' => null,
                    'explanation' => 'Administration evidence is not current; whether this dose has been administered cannot be asserted — this is neither a success nor a failure claim.',
                ],
                default => [
                    'state' => 'no_evidence_as_of_cutoff',
                    'administeredAt' => null,
                    'sourceCutoffAt' => $adminEnvelope->sourceCutoffAt?->format(DATE_ATOM),
                    'elapsedMinutes' => null,
                    'explanation' => 'No administration evidence as of the warehouse cutoff; absence within the batch window is not a failure claim.',
                ],
            };

            $state = match (true) {
                $administeredAt !== null => 'complete',
                $tailUnavailable => 'unknown',
                $openBreachIds->has($orderId) => 'breached',
                $definition?->warning_minutes !== null && $elapsed >= (int) $definition->warning_minutes => 'warning',
                default => 'running',
            };

            return [
                'orderUuid' => $order->order_uuid,
                'label' => (string) $order->medication_label,
                'patientRef' => $canViewPatientDetail ? ($order->patient_ref ?: 'Pseudonymous patient unavailable') : 'Patient context restricted',
                'patientClass' => $order->patient_class,
                'locationLabel' => $order->unit_name,
                'orderedAt' => $orderedAt->toAtomString(),
                'elapsedMinutes' => $elapsed,
                'metricKey' => self::CLOCK_METRICS['sepsis'],
                'state' => $state,
                'stateExplanation' => match ($state) {
                    'complete' => 'The antibiotic clock stopped on warehouse-observed administration evidence, qualified by the batch cutoff.',
                    'unknown' => 'The warehouse administration tail is not current; this clock can claim neither compliance nor breach.',
                    'breached' => 'The governed sepsis clock recorded a breach; no administration evidence had stopped the clock at the breach threshold.',
                    'warning' => 'The governed sepsis clock has passed its warning threshold without a stop assertion.',
                    default => 'The governed sepsis clock is running within its thresholds.',
                },
                'segments' => collect($stageLabels)->map(fn (string $label, string $code): array => [
                    'code' => $code,
                    'label' => $label,
                    'at' => isset($byCode[$code]) ? CarbonImmutable::parse($byCode[$code]->occurred_at)->toAtomString() : null,
                    'state' => isset($byCode[$code]) ? 'complete' : 'pending',
                ])->values()->all(),
                'adminSegment' => $adminSegment,
            ];
        })->values()->all();
    }

    /** @param list<int> $ids @param Collection<int, AncillarySlaDefinition> $definitions @return list<array<string, mixed>> */
    private function oldestItems(array $ids, Collection $definitions, FreshnessEnvelope $adminEnvelope, bool $canViewPatientDetail): array
    {
        if ($ids === []) {
            return [];
        }
        $definitionsByClass = collect(self::CLOCK_METRICS)
            ->map(fn (string $metricKey): ?AncillarySlaDefinition => $definitions->firstWhere('metric_key', $metricKey));
        $tailUnavailable = in_array($adminEnvelope->status, ['stale', 'unknown'], true);

        return DB::table('prod.ancillary_orders as o')
            ->join('prod.rx_orders as x', 'x.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->leftJoin('prod.units as u', 'u.unit_id', '=', 'o.unit_id')
            ->whereIn('o.ancillary_order_id', $ids)
            ->whereRaw($this->openStatusPredicate())
            ->orderBy('o.ordered_at')
            ->limit(8)
            ->select(['o.order_uuid', 'o.encounter_id', 'o.patient_ref', 'o.patient_class', 'o.ordered_at', 'o.current_milestone_code', 'x.medication_label', 'x.clock_class', 'x.preparation_branch', 'x.order_status', 'x.on_shortage', 'x.is_controlled', 'u.name as unit_name'])
            ->selectSub(function ($query): void {
                $query->from('prod.ancillary_breaches as b')
                    ->join('prod.ancillary_sla_definitions as d', 'd.ancillary_sla_definition_id', '=', 'b.ancillary_sla_definition_id')
                    ->whereColumn('b.ancillary_order_id', 'o.ancillary_order_id')
                    ->where('d.department', 'rx')->where('b.status', 'open')->selectRaw('count(*)');
            }, 'open_breach_count')
            ->selectSub(function ($query): void {
                $query->from('prod.barriers as b')->whereColumn('b.encounter_id', 'o.encounter_id')->where('b.status', 'open')->where('b.is_deleted', false)->selectRaw('count(*)');
            }, 'barrier_count')
            ->get()
            ->map(function (object $row) use ($definitionsByClass, $tailUnavailable, $canViewPatientDetail): array {
                $ageMinutes = max(0, (int) floor(CarbonImmutable::parse($row->ordered_at)->diffInSeconds(now(), false) / 60));
                $definition = $definitionsByClass[$row->clock_class] ?? null;
                $adminTail = $definition?->stop_milestone_code === 'RX_ADMINISTERED';
                $state = match (true) {
                    (int) $row->open_breach_count > 0 => 'breach',
                    $adminTail && $tailUnavailable => 'unknown',
                    $definition?->warning_minutes !== null && $ageMinutes >= (int) $definition->warning_minutes => 'warning',
                    default => 'normal',
                };

                return [
                    'orderUuid' => $row->order_uuid,
                    'label' => (string) $row->medication_label,
                    'patientRef' => $canViewPatientDetail ? ($row->patient_ref ?: 'Pseudonymous patient unavailable') : 'Patient context restricted',
                    'patientClass' => $row->patient_class,
                    'clockClass' => $row->clock_class,
                    'preparationBranch' => $row->preparation_branch,
                    'orderStatus' => $row->order_status,
                    'locationLabel' => $row->unit_name,
                    'currentStage' => $row->current_milestone_code,
                    'ageMinutes' => $ageMinutes,
                    'onShortage' => (bool) $row->on_shortage,
                    'isControlled' => (bool) $row->is_controlled,
                    'encounterLinked' => $row->encounter_id !== null,
                    'slaState' => $state,
                    'slaExplanation' => match ($state) {
                        'breach' => 'An open governed SLA breach is recorded for this order.',
                        'unknown' => 'This clock stops on warehouse administration evidence that is not current; its state cannot be asserted.',
                        'warning' => 'The governed clock for this class has passed its warning threshold.',
                        default => 'No governed clock threshold has been reached.',
                    },
                    'barrierCount' => (int) $row->barrier_count,
                ];
            })->all();
    }

    /** @param list<int> $ids @return list<array<string, mixed>> */
    private function barrierPareto(array $ids): array
    {
        return $ids === [] ? [] : DB::table('prod.ancillary_orders as o')
            ->join('prod.barriers as b', fn ($join) => $join->on('b.encounter_id', '=', 'o.encounter_id')->where('b.status', 'open')->where('b.is_deleted', false))
            ->leftJoin('hosp_ref.ancillary_barrier_reasons as r', 'r.reason_code', '=', 'b.reason_code')
            ->whereIn('o.ancillary_order_id', $ids)
            ->selectRaw("COALESCE(b.reason_code, 'UNSPECIFIED') AS reason_code, COALESCE(r.label, 'Unspecified barrier') AS label, count(DISTINCT b.barrier_id) AS aggregate_count")
            ->groupBy('b.reason_code', 'r.label')
            ->orderByDesc('aggregate_count')
            ->limit(5)
            ->get()
            ->map(fn (object $row): array => ['reasonCode' => $row->reason_code, 'label' => $row->label, 'count' => (int) $row->aggregate_count])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function barrierReasons(): array
    {
        return DB::table('hosp_ref.ancillary_barrier_reasons')->where('department', 'rx')->where('is_active', true)->orderBy('label')->get(['reason_code', 'category', 'label'])->map(fn (object $row): array => ['reasonCode' => $row->reason_code, 'category' => $row->category, 'label' => $row->label])->all();
    }

    /** @return array<string, mixed> */
    private function freshness(?CarbonImmutable $cutoff): array
    {
        $registered = DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->first();
        if ($cutoff === null) {
            return $this->contracts->freshness(new FreshnessEnvelope('unknown', new \DateTimeImmutable(now()->toAtomString()), null, null, 'Pharmacy operational feeds', 'No Pharmacy orders match the selected filters.'));
        }
        $lag = max(0, (int) floor($cutoff->diffInSeconds(now(), false) / 60));
        $status = strtolower((string) ($registered->status ?? 'current'));
        $stale = in_array($status, ['stale', 'error', 'failed', 'unavailable'], true) || $lag > max(1, (int) ($registered->warning_lag_minutes ?? 60));

        return $this->contracts->freshness(new FreshnessEnvelope($stale ? 'stale' : 'fresh', new \DateTimeImmutable(now()->toAtomString()), new \DateTimeImmutable($cutoff->toAtomString()), $lag, (string) ($registered->source_label ?? 'Pharmacy operational feeds'), $stale ? 'The selected Pharmacy assertions exceed the registered freshness tolerance.' : null));
    }

    /**
     * The warehouse administration tail. A structural clamp keeps the tail
     * from ever rendering as real-time on this board even if a future
     * real-time source class classifies fresh: administration facts stay
     * cutoff-qualified (batch) here by contract.
     */
    private function administrationTailEnvelope(): FreshnessEnvelope
    {
        $envelope = $this->administrationFreshness->overallEnvelope(CarbonImmutable::instance(now()->toImmutable())->utc());
        if ($envelope->status !== 'fresh') {
            return $envelope;
        }

        return new FreshnessEnvelope(
            'batch',
            $envelope->asOf,
            $envelope->sourceCutoffAt,
            $envelope->lagMinutes,
            $envelope->sourceLabel,
            'Administration facts render cutoff-qualified on the Flow Board; the dispense-to-administration tail never claims real-time.',
        );
    }

    /** @param array<string, mixed> $summary @param array<string, mixed> $freshness */
    private function state(array $summary, array $freshness, FreshnessEnvelope $adminEnvelope): string
    {
        $registered = strtolower((string) (DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->value('status') ?? ''));

        return match (true) {
            in_array($registered, ['error', 'failed', 'unavailable'], true) => 'source_error',
            $summary['currentOrders'] === 0 => 'no_data',
            $freshness['status'] === 'stale' => 'stale',
            $summary['degradedOrders'] > 0 || in_array($adminEnvelope->status, ['stale', 'unknown'], true) => 'degraded',
            default => 'normal',
        };
    }

    private function stateMessage(string $state): string
    {
        return match ($state) {
            'source_error' => 'Pharmacy source health reports an error. Last known operational facts remain visible.',
            'no_data' => 'No current Pharmacy orders match the selected filters.',
            'stale' => 'Pharmacy facts are stale. Ages remain qualified by the last source cutoff.',
            'degraded' => 'Pharmacy coverage is partial; coarse clocks and cutoff-qualified administration facts remain visible without fabricated segments.',
            default => 'Pharmacy operational facts are current; administration facts remain cutoff-qualified.',
        };
    }

    private function openStatusPredicate(): string
    {
        return self::OPEN_STATUS_PREDICATE;
    }
}
