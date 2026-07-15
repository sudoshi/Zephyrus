<?php

declare(strict_types=1);

namespace App\Services\Pharmacy;

use App\Data\Ancillary\FreshnessEnvelope;
use App\Models\Ancillary\AncillarySlaDefinition;
use App\Services\Ancillary\AncillaryContractSerializer;
use App\Services\Ancillary\AncillaryStatistics;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The Pharmacy TAT Study service (§X-12). Retrospective, read-only, and
 * governed: it renders the verification → prepare → dispense → deliver → admin
 * waterfall (median AND P90 together per §8 — mean is secondary, never alone),
 * median/P90 by priority/shift/unit/branch, a queue-depth heatmap, the
 * missing-dose Pareto, the discharge-readiness trend, and shortage impact.
 *
 * The honesty split is structural: real-time order-to-dispense segments and
 * the WAREHOUSE-FED administration tail (any segment stopping on
 * RX_ADMINISTERED) are separated. Administration-dependent charts always carry
 * the as-of cutoff from PharmacyAdministrationFreshnessService, are labeled
 * `warehouse_as_of`, and can never claim a real-time basis. Every chart states
 * its denominator, cohort, source cutoff, mapping coverage (mapped vs
 * unmapped_local), and benchmark/reference classification. Descriptive only —
 * no causal claim about shortages or staffing, and no user-level dimension
 * anywhere (§13: unit/station aggregates only).
 */
final class PharmacyTatAnalyticsService
{
    public const PRIORITIES = ['stat', 'urgent', 'routine', 'timed', 'first_dose', 'sepsis', 'discharge'];

    public const PATIENT_CLASSES = ['emergency', 'inpatient', 'outpatient', 'observation', 'perioperative', 'unknown'];

    public const SHIFTS = ['day', 'evening', 'night', 'weekend'];

    public const BRANCHES = ['adc', 'iv_room', 'central', 'unknown'];

    public const MAX_RANGE_DAYS = 90;

    public const MAX_LIMIT = 2000;

    private const PRIMARY_METRIC = 'rx.study.order_admin';

    private const ADMIN_STOP_CODE = 'RX_ADMINISTERED';

    private const LINEAGE_LIMIT = 100;

    public function __construct(
        private readonly AncillaryContractSerializer $contracts,
        private readonly AncillaryStatistics $statistics,
        private readonly PharmacyAdministrationFreshnessService $administrationFreshness,
    ) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function build(array $input = []): array
    {
        $filters = $this->filters($input);
        [$windowStart, $windowEnd] = $this->window($filters);
        $definitions = $this->definitions($windowStart, $windowEnd);
        $studyDefinitions = $definitions
            ->filter(fn (AncillarySlaDefinition $definition): bool => (bool) ($definition->scope['study_segment'] ?? false))
            ->sortBy(fn (AncillarySlaDefinition $definition): string => sprintf(
                '%05d|%s|%010d',
                (int) ($definition->scope['sequence'] ?? 99999),
                $definition->metric_key,
                $definition->version,
            ))
            ->values();
        $cohort = $this->cohort($filters, $windowStart, $windowEnd);
        $administrations = $this->administrations($cohort['rows']);
        $assertions = $this->selectedAssertions($cohort['rows'], $studyDefinitions);
        $intervalResult = $this->intervals($cohort['rows'], $studyDefinitions, $assertions, $administrations);
        $intervals = $intervalResult['intervals'];
        $primary = $intervals->where('metricKey', self::PRIMARY_METRIC)->values();
        $source = $this->sourceContext($assertions, $windowStart, $windowEnd);
        $adminEnvelope = $this->administrationFreshness->overallEnvelope(CarbonImmutable::instance(now()->toImmutable())->utc());
        $administrationCutoff = $adminEnvelope->sourceCutoffAt === null ? null : CarbonImmutable::instance($adminEnvelope->sourceCutoffAt);
        $freshness = $this->freshness($source);
        $coverage = $this->coverage($cohort, $intervalResult, $primary);
        $primaryDefinition = $studyDefinitions->firstWhere('metric_key', self::PRIMARY_METRIC);
        $chartContext = $this->chartContext($primaryDefinition, $primary, $administrationCutoff);
        $orderIds = $cohort['rows']->pluck('ancillary_order_id')->map(fn (mixed $id): int => (int) $id);
        $mappingCoverage = $this->mappingCoverage($cohort['rows']);
        $state = $this->state($cohort['total'], $source['state'], $coverage, $adminEnvelope->status);

        return [
            'generatedAt' => now()->toAtomString(),
            'sourceCutoffAt' => $source['sourceCutoffAt']?->toAtomString(),
            'administrationCutoffAt' => $administrationCutoff?->toAtomString(),
            'freshnessStatus' => $freshness['status'],
            'degradedMode' => $state !== 'normal',
            'state' => $state,
            'stateMessage' => $this->stateMessage($state),
            'freshness' => $freshness,
            'administrationFreshness' => $this->contracts->freshness($adminEnvelope),
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'appliedSlaDefinitions' => $definitions->map(fn (AncillarySlaDefinition $definition): array => $this->contracts->slaDefinition($definition))->values()->all(),
            'summary' => [
                ...$this->distribution($primary->pluck('minutes')->all()),
                'candidateOrderCount' => $cohort['total'],
                'includedOrderCount' => $primary->pluck('orderId')->unique()->count(),
                'clockDefinition' => $primaryDefinition === null ? null : $this->contracts->slaDefinition($primaryDefinition),
                'basis' => 'warehouse_as_of',
                'administrationCutoffAt' => $administrationCutoff?->toAtomString(),
            ],
            'waterfall' => $this->waterfall($studyDefinitions, $intervals, $intervalResult['byDefinition'], $source['sourceCutoffAt'], $administrationCutoff),
            'dailyTrend' => [
                ...$chartContext,
                'label' => 'Daily order-to-administration trend',
                'basis' => 'warehouse_as_of',
                'points' => $this->distributionGroups($primary, 'date'),
            ],
            'breakdowns' => [
                'priority' => $this->breakdown('Priority', 'priority', $primary, $chartContext),
                'shift' => $this->breakdown('Shift', 'shift', $primary, $chartContext),
                'unit' => $this->breakdown('Unit', 'unitLabel', $primary, $chartContext),
                'branch' => $this->breakdown('Preparation branch', 'branch', $primary, $chartContext),
            ],
            'queueDepthHeatmap' => $this->queueDepthHeatmap($orderIds, $windowStart, $windowEnd, $source['sourceCutoffAt']),
            'missingDosePareto' => $this->missingDosePareto($orderIds, $source['sourceCutoffAt']),
            'dischargeReadinessTrend' => $this->dischargeReadinessTrend($orderIds, $source['sourceCutoffAt']),
            'shortageImpact' => $this->shortageImpact($cohort['rows'], $intervals, $administrationCutoff),
            'mappingCoverage' => $mappingCoverage,
            'benchmarkReferences' => $this->benchmarkReferences($definitions),
            'coverage' => $coverage,
            'lineage' => [
                'count' => $intervals->count(),
                'items' => $intervals->take(self::LINEAGE_LIMIT)->map(fn (array $interval): array => $this->publicInterval($interval))->values()->all(),
                'truncated' => $intervals->count() > self::LINEAGE_LIMIT,
                'definition' => 'Every included Pharmacy interval references its effective SLA definition and the selected start/stop milestone assertions. Administration-stop intervals additionally carry the warehouse basis and the batch cutoff. The browser receives a bounded, patient-free audit sample.',
            ],
            'privacy' => [
                'patientIdentifiersIncluded' => false,
                'doseInstructionsIncluded' => false,
                'individualPerformanceIncluded' => false,
                'identifierPolicy' => 'Only operational order and milestone UUIDs are returned in the bounded lineage sample. No pharmacist, nurse, verifier, or any user-level performance dimension is computed or exposed; patient references, medication instructions, and source keys are excluded.',
            ],
        ];
    }

    /** @param array<string, mixed> $input @return array{dateFrom:string,dateTo:string,priority:?string,patientClass:?string,shift:?string,branch:?string,limit:int} */
    private function filters(array $input): array
    {
        $dateTo = $this->date($input['dateTo'] ?? null, CarbonImmutable::now()->toDateString());
        $dateFrom = $this->date($input['dateFrom'] ?? null, CarbonImmutable::parse($dateTo)->subDays(29)->toDateString());
        $from = CarbonImmutable::parse($dateFrom);
        $to = CarbonImmutable::parse($dateTo);
        if ($from->greaterThan($to) || $from->diffInDays($to) >= self::MAX_RANGE_DAYS) {
            $dateFrom = $to->subDays(29)->toDateString();
        }

        return [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'priority' => is_string($input['priority'] ?? null) && in_array($input['priority'], self::PRIORITIES, true) ? $input['priority'] : null,
            'patientClass' => is_string($input['patientClass'] ?? null) && in_array($input['patientClass'], self::PATIENT_CLASSES, true) ? $input['patientClass'] : null,
            'shift' => is_string($input['shift'] ?? null) && in_array($input['shift'], self::SHIFTS, true) ? $input['shift'] : null,
            'branch' => is_string($input['branch'] ?? null) && in_array($input['branch'], self::BRANCHES, true) ? $input['branch'] : null,
            'limit' => min(self::MAX_LIMIT, max(1, (int) ($input['limit'] ?? 1000))),
        ];
    }

    private function date(mixed $value, string $fallback): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $fallback;
        }
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, $this->timezone())->toDateString();
        } catch (Throwable) {
            return $fallback;
        }
    }

    /** @param array{dateFrom:string,dateTo:string} $filters @return array{CarbonImmutable,CarbonImmutable} */
    private function window(array $filters): array
    {
        return [
            CarbonImmutable::createFromFormat('!Y-m-d', $filters['dateFrom'], $this->timezone())->startOfDay()->utc(),
            CarbonImmutable::createFromFormat('!Y-m-d', $filters['dateTo'], $this->timezone())->addDay()->startOfDay()->utc(),
        ];
    }

    /** @return Collection<int, AncillarySlaDefinition> */
    private function definitions(CarbonImmutable $windowStart, CarbonImmutable $windowEnd): Collection
    {
        return AncillarySlaDefinition::query()
            ->where('department', 'rx')
            ->where('effective_from', '<', $windowEnd)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $windowStart))
            ->orderBy('metric_key')->orderBy('effective_from')->get();
    }

    /**
     * @param  array{priority:?string,patientClass:?string,shift:?string,branch:?string,limit:int}  $filters
     * @return array{total:int,rows:Collection<int,object>,truncated:bool}
     */
    private function cohort(array $filters, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        $query = DB::table('prod.ancillary_orders as o')
            ->join('prod.rx_orders as x', 'x.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->leftJoin('prod.units as u', 'u.unit_id', '=', 'o.unit_id')
            ->where('o.department', 'rx')
            ->where('o.ordered_at', '>=', $windowStart)->where('o.ordered_at', '<', $windowEnd)
            ->whereRaw("COALESCE(o.metadata->>'operational_window', 'current') <> 'historical_study_only'")
            ->when($filters['priority'] !== null, fn (Builder $query) => $query->where('o.priority', $filters['priority']))
            ->when($filters['patientClass'] !== null, fn (Builder $query) => $query->where('o.patient_class', $filters['patientClass']))
            ->when($filters['branch'] !== null, fn (Builder $query) => $query->where('x.preparation_branch', $filters['branch']));
        if ($filters['shift'] !== null) {
            $this->applyShiftFilter($query, $filters['shift']);
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('o.ordered_at')->orderBy('o.ancillary_order_id')->limit($filters['limit'])->get([
            'o.ancillary_order_id', 'o.order_uuid', 'o.priority', 'o.patient_class', 'o.ordered_at',
            'o.source_cutoff_at', 'o.unit_id', 'x.rx_order_id', 'x.medication_label', 'x.clock_class',
            'x.preparation_branch', 'x.terminology_status', 'x.on_shortage', 'x.is_controlled', 'x.local_code',
            DB::raw("COALESCE(u.name, 'Unassigned unit') AS unit_label"),
        ]);

        return ['total' => $total, 'rows' => $rows, 'truncated' => $total > $rows->count()];
    }

    private function applyShiftFilter(Builder $query, string $shift): void
    {
        $timezone = $this->timezone();
        $day = 'EXTRACT(ISODOW FROM o.ordered_at AT TIME ZONE ?)';
        $hour = 'EXTRACT(HOUR FROM o.ordered_at AT TIME ZONE ?)';
        match ($shift) {
            'weekend' => $query->whereRaw("{$day} IN (6, 7)", [$timezone]),
            'night' => $query->whereRaw("{$day} NOT IN (6, 7) AND ({$hour} < 7 OR {$hour} >= 19)", [$timezone, $timezone, $timezone]),
            'day' => $query->whereRaw("{$day} NOT IN (6, 7) AND {$hour} >= 7 AND {$hour} < 15", [$timezone, $timezone, $timezone]),
            'evening' => $query->whereRaw("{$day} NOT IN (6, 7) AND {$hour} >= 15 AND {$hour} < 19", [$timezone, $timezone, $timezone]),
            default => null,
        };
    }

    /**
     * Latest warehouse administration timestamp per order (the deduplicated
     * given administration). This is the RX_ADMINISTERED stop for every
     * warehouse-fed study segment; it carries the batch cutoff and never
     * renders as real-time.
     *
     * @param  Collection<int, object>  $rows
     * @return Collection<int, object>
     */
    private function administrations(Collection $rows): Collection
    {
        $rxOrderIds = $rows->pluck('rx_order_id')->filter()->map(fn (mixed $id): int => (int) $id)->all();
        if ($rxOrderIds === []) {
            return collect();
        }

        return collect(DB::select(<<<'SQL'
            SELECT DISTINCT ON (a.rx_order_id)
                a.rx_order_id, a.administered_at, a.source_cutoff_at
            FROM prod.rx_administrations AS a
            WHERE a.rx_order_id = ANY(?)
              AND a.administration_status = 'given'
              AND NOT EXISTS (
                  SELECT 1 FROM prod.rx_administrations AS nv
                  WHERE nv.source_id = a.source_id
                    AND nv.source_administration_key = a.source_administration_key
                    AND nv.rx_administration_id > a.rx_administration_id
              )
            ORDER BY a.rx_order_id, a.administered_at ASC, a.rx_administration_id ASC
        SQL, ['{'.implode(',', $rxOrderIds).'}']))->keyBy(fn (object $row): int => (int) $row->rx_order_id);
    }

    /** @param Collection<int, object> $rows @param Collection<int, AncillarySlaDefinition> $definitions @return Collection<string, object> */
    private function selectedAssertions(Collection $rows, Collection $definitions): Collection
    {
        if ($rows->isEmpty() || $definitions->isEmpty()) {
            return collect();
        }
        $codes = $definitions->flatMap(fn (AncillarySlaDefinition $definition): array => [
            $definition->start_milestone_code,
            $definition->stop_milestone_code,
        ])->unique()->reject(fn (string $code): bool => $code === self::ADMIN_STOP_CODE)->values()->all();

        return DB::table('prod.ancillary_current_assertions as a')
            ->join('integration.sources as s', 's.source_id', '=', 'a.source_id')
            ->whereIn('a.ancillary_order_id', $rows->pluck('ancillary_order_id'))
            ->whereIn('a.milestone_code', $codes)
            ->get([
                'a.ancillary_milestone_id', 'a.milestone_uuid', 'a.ancillary_order_id', 'a.milestone_code',
                'a.occurred_at', 'a.received_at', 'a.source_rank', 'a.assertion_count', 'a.disagreement_seconds',
                's.source_key',
            ])->keyBy(fn (object $row): string => $row->ancillary_order_id.'|'.$row->milestone_code);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  Collection<int, AncillarySlaDefinition>  $definitions
     * @param  Collection<string, object>  $assertions
     * @param  Collection<int, object>  $administrations
     * @return array{intervals:Collection<int,array<string,mixed>>,missing:int,negative:int,invalid:int,possible:int,conflicts:int,byDefinition:array<string,array{missing:int,negative:int,invalid:int}>}
     */
    private function intervals(Collection $rows, Collection $definitions, Collection $assertions, Collection $administrations): array
    {
        $intervals = collect();
        $missing = $negative = $invalid = $possible = 0;
        $conflictIds = [];
        $byDefinition = [];
        foreach ($definitions as $definition) {
            $byDefinition[$definition->definition_uuid] = ['missing' => 0, 'negative' => 0, 'invalid' => 0];
        }
        foreach ($rows as $row) {
            foreach ($definitions as $definition) {
                if (! $this->definitionApplies($definition, $row)) {
                    continue;
                }
                $possible++;
                $warehouseStop = $definition->stop_milestone_code === self::ADMIN_STOP_CODE;
                $start = $assertions->get($row->ancillary_order_id.'|'.$definition->start_milestone_code);
                $stopAssertion = $warehouseStop ? null : $assertions->get($row->ancillary_order_id.'|'.$definition->stop_milestone_code);
                $administration = $warehouseStop ? $administrations->get((int) $row->rx_order_id) : null;
                if ($start === null || ($warehouseStop ? $administration === null : $stopAssertion === null)) {
                    $missing++;
                    $byDefinition[$definition->definition_uuid]['missing']++;

                    continue;
                }
                foreach ([$start, $stopAssertion] as $assertion) {
                    if ($assertion !== null && (int) $assertion->assertion_count > 1) {
                        $conflictIds[(int) $assertion->ancillary_milestone_id] = true;
                    }
                }
                $stopAt = $warehouseStop ? $administration->administered_at : $stopAssertion->occurred_at;
                try {
                    $minutes = CarbonImmutable::parse($start->occurred_at)->diffInSeconds(CarbonImmutable::parse($stopAt), false) / 60;
                } catch (Throwable) {
                    $invalid++;
                    $byDefinition[$definition->definition_uuid]['invalid']++;

                    continue;
                }
                if ($minutes < 0) {
                    $negative++;
                    $byDefinition[$definition->definition_uuid]['negative']++;

                    continue;
                }
                $orderedAt = CarbonImmutable::parse($row->ordered_at)->setTimezone($this->timezone());
                $startReceived = CarbonImmutable::parse($start->received_at);
                $stopReceived = $warehouseStop
                    ? CarbonImmutable::parse($administration->source_cutoff_at)
                    : CarbonImmutable::parse($stopAssertion->received_at);
                $intervals->push([
                    'orderId' => (int) $row->ancillary_order_id,
                    'orderUuid' => (string) $row->order_uuid,
                    'definitionUuid' => (string) $definition->definition_uuid,
                    'metricKey' => (string) $definition->metric_key,
                    'basis' => $warehouseStop ? 'warehouse_as_of' : 'real_time',
                    'minutes' => $this->number($minutes),
                    'date' => $orderedAt->toDateString(),
                    'priority' => (string) $row->priority,
                    'clockClass' => (string) $row->clock_class,
                    'branch' => (string) $row->preparation_branch,
                    'medicationLabel' => (string) $row->medication_label,
                    'patientClass' => (string) $row->patient_class,
                    'unitLabel' => (string) $row->unit_label,
                    'shift' => $this->shift($orderedAt),
                    'sourceCutoffAt' => max($startReceived, $stopReceived)->toAtomString(),
                    'startAssertion' => $this->assertion($start),
                    'stopAssertion' => $warehouseStop
                        ? $this->warehouseStopAssertion($administration)
                        : $this->assertion($stopAssertion),
                ]);
            }
        }

        return compact('intervals', 'missing', 'negative', 'invalid', 'possible') + [
            'conflicts' => count($conflictIds),
            'byDefinition' => $byDefinition,
        ];
    }

    private function definitionApplies(AncillarySlaDefinition $definition, object $row): bool
    {
        $ordered = CarbonImmutable::parse($row->ordered_at);
        if ($ordered->lessThan($definition->effective_from)
            || ($definition->effective_to !== null && ! $ordered->lessThan($definition->effective_to))) {
            return false;
        }
        if ($definition->priority !== null && $definition->priority !== $row->priority) {
            return false;
        }

        return $definition->patient_class === null || $definition->patient_class === $row->patient_class;
    }

    /** @return array<string, mixed> */
    private function assertion(object $row): array
    {
        return [
            'milestoneUuid' => (string) $row->milestone_uuid,
            'code' => (string) $row->milestone_code,
            'basis' => 'real_time',
            'occurredAt' => CarbonImmutable::parse($row->occurred_at)->toAtomString(),
            'receivedAt' => CarbonImmutable::parse($row->received_at)->toAtomString(),
            'sourceKey' => (string) $row->source_key,
            'sourceRank' => (int) $row->source_rank,
            'assertionCount' => (int) $row->assertion_count,
        ];
    }

    /** The RX_ADMINISTERED stop is warehouse-derived; it carries the batch cutoff as its received basis. @return array<string, mixed> */
    private function warehouseStopAssertion(object $administration): array
    {
        return [
            'milestoneUuid' => '00000000-0000-0000-0000-000000000000',
            'code' => self::ADMIN_STOP_CODE,
            'basis' => 'warehouse_as_of',
            'occurredAt' => CarbonImmutable::parse($administration->administered_at)->toAtomString(),
            'receivedAt' => CarbonImmutable::parse($administration->source_cutoff_at)->toAtomString(),
            'sourceKey' => 'bcma_warehouse',
            'sourceRank' => 0,
            'assertionCount' => 1,
        ];
    }

    /** @return array{state:string,sourceCutoffAt:?CarbonImmutable,lagMinutes:?int,sourceLabel:string} */
    private function sourceContext(Collection $assertions, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        $registered = DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->first();
        $orderCutoff = DB::table('prod.ancillary_orders')->where('department', 'rx')
            ->where('ordered_at', '>=', $windowStart)->where('ordered_at', '<', $windowEnd)->max('source_cutoff_at');
        $cutoffValue = collect([$assertions->pluck('received_at')->filter()->max(), $orderCutoff, $registered?->latest_observed_at])->filter()->max();
        $cutoff = $cutoffValue === null ? null : CarbonImmutable::parse($cutoffValue);
        $lag = $cutoff === null ? null : max(0, (int) floor($cutoff->diffInSeconds(now(), false) / 60));
        $registeredStatus = strtolower((string) ($registered?->status ?? 'current'));
        $warning = max(1, (int) ($registered?->warning_lag_minutes ?? 60));
        $state = match (true) {
            in_array($registeredStatus, ['error', 'failed', 'unavailable'], true) => 'error',
            $cutoff === null => 'missing',
            $registeredStatus === 'stale' || $lag > $warning => 'stale',
            default => 'fresh',
        };

        return ['state' => $state, 'sourceCutoffAt' => $cutoff, 'lagMinutes' => $lag, 'sourceLabel' => (string) ($registered?->source_label ?? 'Pharmacy operational feeds')];
    }

    /** @param array{state:string,sourceCutoffAt:?CarbonImmutable,lagMinutes:?int,sourceLabel:string} $source @return array<string, mixed> */
    private function freshness(array $source): array
    {
        $status = match ($source['state']) {
            'fresh' => 'fresh', 'stale', 'error' => 'stale', default => 'unknown',
        };
        $explanation = match ($source['state']) {
            'error' => 'The governed Pharmacy operational source reports an error.',
            'stale' => 'The latest selected Pharmacy assertions exceed the registered freshness tolerance.',
            'missing' => 'No selected Pharmacy assertions, order cutoff, or registered source cutoff is available.',
            default => null,
        };

        return $this->contracts->freshness(new FreshnessEnvelope(
            status: $status,
            asOf: new DateTimeImmutable(now()->toAtomString()),
            sourceCutoffAt: $source['sourceCutoffAt'] === null ? null : new DateTimeImmutable($source['sourceCutoffAt']->toAtomString()),
            lagMinutes: $source['lagMinutes'],
            sourceLabel: $source['sourceLabel'],
            explanation: $explanation,
        ));
    }

    /** @param Collection<int, AncillarySlaDefinition> $definitions @param Collection<int, array<string,mixed>> $intervals @param array<string,array{missing:int,negative:int,invalid:int}> $quality @return list<array<string, mixed>> */
    private function waterfall(Collection $definitions, Collection $intervals, array $quality, ?CarbonImmutable $sourceCutoff, ?CarbonImmutable $administrationCutoff): array
    {
        return $definitions->map(function (AncillarySlaDefinition $definition) use ($intervals, $quality, $sourceCutoff, $administrationCutoff): array {
            $values = $intervals->where('definitionUuid', $definition->definition_uuid)->pluck('minutes')->all();
            $basis = $this->segmentBasis($definition);

            return [
                'phase' => (string) ($definition->scope['phase'] ?? 'unspecified'),
                'basis' => $basis,
                'definition' => $this->contracts->slaDefinition($definition),
                'cohortCount' => count($values),
                ...$this->distribution($values),
                'missingIntervalCount' => $quality[$definition->definition_uuid]['missing'] ?? 0,
                'excludedNegativeCount' => $quality[$definition->definition_uuid]['negative'] ?? 0,
                'invalidTimestampCount' => $quality[$definition->definition_uuid]['invalid'] ?? 0,
                'sourceCutoffAt' => $basis === 'warehouse_as_of' ? $administrationCutoff?->toAtomString() : $sourceCutoff?->toAtomString(),
                'benchmarkSourceLabel' => $this->benchmarkSource($definition),
            ];
        })->values()->all();
    }

    private function segmentBasis(AncillarySlaDefinition $definition): string
    {
        if ($definition->stop_milestone_code === self::ADMIN_STOP_CODE) {
            return 'warehouse_as_of';
        }

        return (string) ($definition->scope['basis'] ?? 'real_time');
    }

    /** @param array<array-key, mixed> $values @return array{count:int,medianMinutes:int|float|null,p90Minutes:int|float|null,meanMinutes:int|float|null} */
    private function distribution(array $values): array
    {
        $result = $this->statistics->distribution($values);

        return [
            'count' => $result['count'],
            'medianMinutes' => $this->number($result['median']),
            'p90Minutes' => $this->number($result['p90']),
            'meanMinutes' => $this->mean($values),
        ];
    }

    /** @param Collection<int,array<string,mixed>> $intervals @return list<array<string, mixed>> */
    private function distributionGroups(Collection $intervals, string $field): array
    {
        return $intervals->groupBy($field)->map(function (Collection $group, string $key): array {
            return ['key' => $key, 'label' => $this->groupLabel($key), ...$this->distribution($group->pluck('minutes')->all())];
        })->sortKeys()->values()->all();
    }

    /** @param Collection<int,array<string,mixed>> $primary @return array<string, mixed> */
    private function chartContext(?AncillarySlaDefinition $definition, Collection $primary, ?CarbonImmutable $administrationCutoff): array
    {
        return [
            'clockDefinition' => $definition === null ? null : $this->contracts->slaDefinition($definition),
            'cohortCount' => $primary->count(),
            'sourceCutoffAt' => $administrationCutoff?->toAtomString(),
            'benchmarkSourceLabel' => $definition === null ? 'No effective Pharmacy study clock is configured.' : $this->benchmarkSource($definition),
        ];
    }

    /** @param Collection<int,array<string,mixed>> $intervals @param array<string,mixed> $context @return array<string, mixed> */
    private function breakdown(string $label, string $field, Collection $intervals, array $context): array
    {
        return [...$context, 'label' => "Order-to-administration by {$label}", 'dimension' => $field, 'basis' => 'warehouse_as_of', 'points' => $this->distributionGroups($intervals, $field)];
    }

    /**
     * Queue-depth heatmap: verification-queue entries bucketed by local weekday
     * (Mon–Sun) × hour-of-day across the bounded cohort. A queued verification
     * counts in the cell of its queued_at hour. This is a real-time operational
     * signal derived from prod.rx_verifications; no user dimension.
     *
     * @param  Collection<int, int>  $orderIds
     * @return array<string, mixed>
     */
    private function queueDepthHeatmap(Collection $orderIds, CarbonImmutable $windowStart, CarbonImmutable $windowEnd, ?CarbonImmutable $sourceCutoff): array
    {
        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $cells = [];
        if ($orderIds->isNotEmpty()) {
            $local = DB::table('prod.rx_verifications as v')
                ->join('prod.rx_orders as x', 'x.rx_order_id', '=', 'v.rx_order_id')
                ->whereIn('x.ancillary_order_id', $orderIds->all())
                ->where('v.queued_at', '>=', $windowStart)->where('v.queued_at', '<', $windowEnd)
                ->selectRaw('(v.queued_at AT TIME ZONE ?) AS local_queued_at', [$this->timezone()]);
            $rows = DB::query()->fromSub($local, 'buckets')
                ->selectRaw('EXTRACT(ISODOW FROM local_queued_at)::int AS dow, EXTRACT(HOUR FROM local_queued_at)::int AS hour, count(*) AS total')
                ->groupByRaw('EXTRACT(ISODOW FROM local_queued_at), EXTRACT(HOUR FROM local_queued_at)')
                ->get();
            foreach ($rows as $row) {
                $cells[] = [
                    'day' => $days[max(1, min(7, (int) $row->dow)) - 1],
                    'dayIndex' => (int) $row->dow,
                    'hour' => (int) $row->hour,
                    'count' => (int) $row->total,
                ];
            }
        }
        $total = array_sum(array_column($cells, 'count'));

        return [
            'clockDefinition' => 'Verification-queue entries (prod.rx_verifications.queued_at) for the bounded Pharmacy cohort, bucketed by local ISO weekday and hour of day. Each queued verification counts once in its queued-hour cell.',
            'basis' => 'real_time',
            'days' => $days,
            'hours' => range(0, 23),
            'cells' => $cells,
            'totalQueued' => $total,
            'peakCount' => $cells === [] ? 0 : max(array_column($cells, 'count')),
            'sourceCutoffAt' => $sourceCutoff?->toAtomString(),
        ];
    }

    /**
     * Missing-dose Pareto: orders carrying an RX_MISSING_DOSE assertion in the
     * bounded cohort, grouped by preparation branch (an operational dimension —
     * never a person). A missing dose is an order-level event; no individual or
     * station is attributed responsibility. Descriptive only.
     *
     * @param  Collection<int, int>  $orderIds
     * @return array<string, mixed>
     */
    private function missingDosePareto(Collection $orderIds, ?CarbonImmutable $sourceCutoff): array
    {
        $points = [];
        $chainCount = 0;
        if ($orderIds->isNotEmpty()) {
            $raw = DB::table('prod.ancillary_current_assertions as m')
                ->join('prod.rx_orders as x', 'x.ancillary_order_id', '=', 'm.ancillary_order_id')
                ->whereIn('m.ancillary_order_id', $orderIds->all())
                ->where('m.milestone_code', 'RX_MISSING_DOSE')
                ->selectRaw('x.preparation_branch AS branch, count(DISTINCT m.ancillary_order_id) AS total')
                ->groupBy('x.preparation_branch')
                ->orderByDesc('total')->orderBy('x.preparation_branch')
                ->get();
            $chainCount = (int) $raw->sum('total');
            $total = max(1, $chainCount);
            $cumulative = 0.0;
            foreach ($raw as $row) {
                $percent = round((int) $row->total / $total * 100, 1);
                $cumulative = min(100.0, round($cumulative + $percent, 1));
                $points[] = [
                    'key' => (string) $row->branch,
                    'label' => $this->groupLabel((string) $row->branch),
                    'count' => (int) $row->total,
                    'percent' => $percent,
                    'cumulativePercent' => $cumulative,
                ];
            }
        }

        return [
            'clockDefinition' => 'Orders carrying an RX_MISSING_DOSE assertion in the bounded cohort, grouped by preparation branch. A missing dose is a descriptive order-level operational event; no individual or station is attributed responsibility, and no causal claim is made.',
            'basis' => 'real_time',
            'chainCount' => $chainCount,
            'sourceCutoffAt' => $sourceCutoff?->toAtomString(),
            'points' => $points,
        ];
    }

    /**
     * Discharge-readiness trend: discharge-clock orders in the bounded cohort
     * with a discharge-queue row, grouped by local day of the planned discharge,
     * reporting the ready-by-planned-time rate. Real-time operational pipeline;
     * descriptive only.
     *
     * @param  Collection<int, int>  $orderIds
     * @return array<string, mixed>
     */
    private function dischargeReadinessTrend(Collection $orderIds, ?CarbonImmutable $sourceCutoff): array
    {
        $points = [];
        $cohortCount = 0;
        if ($orderIds->isNotEmpty()) {
            $rows = DB::table('prod.rx_discharge_queue as q')
                ->join('prod.rx_orders as x', 'x.rx_order_id', '=', 'q.rx_order_id')
                ->whereIn('x.ancillary_order_id', $orderIds->all())
                ->whereNotNull('q.planned_discharge_at')
                ->get(['q.pipeline_status', 'q.ready_at', 'q.delivered_at', 'q.planned_discharge_at']);
            $cohortCount = $rows->count();
            $points = $rows->groupBy(fn (object $row): string => CarbonImmutable::parse($row->planned_discharge_at)->setTimezone($this->timezone())->toDateString())
                ->map(function (Collection $group, string $date): array {
                    $ready = $group->filter(function (object $row): bool {
                        $readyAt = $row->ready_at ?? $row->delivered_at;

                        return $readyAt !== null && CarbonImmutable::parse($readyAt)->lessThanOrEqualTo(CarbonImmutable::parse($row->planned_discharge_at));
                    })->count();
                    $denominator = $group->count();

                    return [
                        'date' => $date,
                        'label' => CarbonImmutable::parse($date)->format('M j'),
                        'cohortCount' => $denominator,
                        'readyOnTimeCount' => $ready,
                        'readyOnTimePercent' => $denominator > 0 ? round($ready / $denominator * 100, 1) : null,
                    ];
                })->sortKeys()->values()->all();
        }

        return [
            'clockDefinition' => 'Discharge-clock medication orders with a discharge-queue row, grouped by the local day of planned discharge. Ready-on-time is ready_at (or delivered_at) at or before planned_discharge_at. Descriptive operational pipeline; no causal claim about discharge delay is made.',
            'basis' => 'real_time',
            'cohortCount' => $cohortCount,
            'sourceCutoffAt' => $sourceCutoff?->toAtomString(),
            'points' => $points,
        ];
    }

    /**
     * Shortage impact: order-to-administration percentiles split by whether the
     * order is currently on a formulary shortage. Descriptive contrast only — it
     * states the observed difference without asserting shortages CAUSE the
     * difference (§ no causal claims about shortages or staffing).
     *
     * @param  Collection<int, object>  $rows
     * @param  Collection<int, array<string,mixed>>  $intervals
     * @return array<string, mixed>
     */
    private function shortageImpact(Collection $rows, Collection $intervals, ?CarbonImmutable $administrationCutoff): array
    {
        $shortageOrderIds = $rows->where('on_shortage', true)->pluck('ancillary_order_id')->map(fn (mixed $id): int => (int) $id)->flip();
        $primary = $intervals->where('metricKey', self::PRIMARY_METRIC);
        $onShortage = $primary->filter(fn (array $interval): bool => $shortageOrderIds->has($interval['orderId']))->pluck('minutes')->all();
        $notOnShortage = $primary->reject(fn (array $interval): bool => $shortageOrderIds->has($interval['orderId']))->pluck('minutes')->all();

        return [
            'clockDefinition' => 'Order-to-administration percentiles contrasted by current formulary-shortage flag on the order. This is a descriptive contrast qualified by the administration batch cutoff; it does not assert that shortages cause the observed difference.',
            'basis' => 'warehouse_as_of',
            'shortageOrderCount' => $rows->where('on_shortage', true)->count(),
            'sourceCutoffAt' => $administrationCutoff?->toAtomString(),
            'points' => [
                ['key' => 'on_shortage', 'label' => 'On shortage', ...$this->distribution($onShortage)],
                ['key' => 'not_on_shortage', 'label' => 'Not on shortage', ...$this->distribution($notOnShortage)],
            ],
        ];
    }

    /**
     * Mapping coverage: how many bounded orders are terminology-mapped (RxNorm
     * or NDC) versus unmapped_local. Missing/unmapped data is quantified, never
     * hidden.
     *
     * @param  Collection<int, object>  $rows
     * @return array<string, mixed>
     */
    private function mappingCoverage(Collection $rows): array
    {
        $total = $rows->count();
        $mapped = $rows->where('terminology_status', 'mapped')->count();
        $unmapped = $rows->where('terminology_status', 'unmapped_local')->count();

        return [
            'clockDefinition' => 'Terminology mapping of the bounded Pharmacy cohort: mapped carries an RxNorm or NDC identifier; unmapped_local carries only a local code. Unmapped orders are counted, not hidden.',
            'totalOrderCount' => $total,
            'mappedCount' => $mapped,
            'unmappedLocalCount' => $unmapped,
            'mappedPercent' => $total > 0 ? round($mapped / $total * 100, 1) : null,
            'unmappedLocalPercent' => $total > 0 ? round($unmapped / $total * 100, 1) : null,
            'points' => [
                ['key' => 'mapped', 'label' => 'Mapped (RxNorm / NDC)', 'count' => $mapped],
                ['key' => 'unmapped_local', 'label' => 'Unmapped local', 'count' => $unmapped],
            ],
        ];
    }

    /** @param Collection<int, AncillarySlaDefinition> $definitions @return list<array<string, mixed>> */
    private function benchmarkReferences(Collection $definitions): array
    {
        return $definitions->map(fn (AncillarySlaDefinition $definition): array => [
            'definitionUuid' => (string) $definition->definition_uuid,
            'metricKey' => (string) $definition->metric_key,
            'label' => (string) $definition->label,
            'basis' => $this->segmentBasis($definition),
            'sourceReferenceId' => $definition->source_reference_id,
            'sourceLabel' => $this->benchmarkSource($definition),
            'classification' => $this->classification($definition),
            'numericLines' => $this->numericLines($definition),
        ])->values()->all();
    }

    private function classification(AncillarySlaDefinition $definition): string
    {
        return match ($definition->source_reference_id) {
            'demo_local_policy' => 'local_policy',
            'reference_only_not_policy' => 'established_reference',
            'site_policy_required' => 'site_policy_required',
            'planned_discharge_policy_required' => 'site_policy_required',
            'shift_end_policy_required' => 'site_policy_required',
            'study_clock_no_numeric_benchmark' => 'no_numeric_benchmark',
            default => 'governed_reference',
        };
    }

    /** @return list<array{kind:string,value:int|float,unit:string}> */
    private function numericLines(AncillarySlaDefinition $definition): array
    {
        $lines = [];
        foreach (['warning' => $definition->warning_minutes, 'breach' => $definition->breach_minutes] as $kind => $value) {
            if ($value !== null) {
                $lines[] = ['kind' => $kind, 'value' => $this->number((float) $value), 'unit' => 'minutes'];
            }
        }
        if ($definition->target_value !== null
            && (isset($definition->scope['target_unit']) || $definition->statistic === 'compliance_rate')) {
            $lines[] = [
                'kind' => 'target',
                'value' => $this->number((float) $definition->target_value),
                'unit' => (string) ($definition->scope['target_unit'] ?? 'percent'),
            ];
        }

        return $lines;
    }

    private function benchmarkSource(AncillarySlaDefinition $definition): string
    {
        return match ($definition->source_reference_id) {
            'demo_local_policy' => 'Local demo policy; not an external benchmark',
            'reference_only_not_policy' => 'Established reference clock; reference is not local policy',
            'study_clock_no_numeric_benchmark' => 'Reference clock only; no governed numeric benchmark',
            'site_policy_required' => 'Site policy required before numeric comparison',
            'planned_discharge_policy_required' => 'Site policy required; target derived from planned discharge time',
            'shift_end_policy_required' => 'Site shift-end policy required; not an individual risk score',
            null => 'No governed benchmark source registered',
            default => 'Governed source: '.$definition->source_reference_id,
        };
    }

    /** @param array{total:int,rows:Collection<int,object>,truncated:bool} $cohort @param array<string,mixed> $result @param Collection<int,array<string,mixed>> $primary @return array<string, mixed> */
    private function coverage(array $cohort, array $result, Collection $primary): array
    {
        $possible = (int) $result['possible'];
        $included = $result['intervals']->count();

        return [
            'candidateOrderCount' => $cohort['total'], 'analyzedOrderCount' => $cohort['rows']->count(),
            'includedOrderCount' => $primary->pluck('orderId')->unique()->count(),
            'possibleIntervalCount' => $possible, 'includedIntervalCount' => $included,
            'percent' => $possible > 0 ? round($included / $possible * 100, 1) : 0.0,
            'missingAssertionIntervalCount' => (int) $result['missing'],
            'excludedNegativeIntervalCount' => (int) $result['negative'],
            'invalidTimestampIntervalCount' => (int) $result['invalid'],
            'selectedAssertionConflictCount' => (int) $result['conflicts'],
            'truncated' => $cohort['truncated'],
            'unanalyzedCandidateCount' => max(0, $cohort['total'] - $cohort['rows']->count()),
            'definition' => 'Coverage is valid selected-assertion intervals divided by applicable Pharmacy study-clock intervals. Warehouse-fed administration segments are missing when no in-cohort administration record exists as of the batch cutoff; missing, negative, invalid, conflicting, and row-limit-truncated evidence remains explicit.',
        ];
    }

    private function state(int $total, string $sourceState, array $coverage, string $administrationStatus): string
    {
        return match (true) {
            $sourceState === 'error' => 'source_error',
            $total === 0 => 'no_data',
            $sourceState === 'stale' => 'stale',
            $sourceState === 'missing' || $coverage['percent'] < 100
                || $coverage['excludedNegativeIntervalCount'] > 0
                || $coverage['invalidTimestampIntervalCount'] > 0
                || $coverage['truncated']
                || in_array($administrationStatus, ['stale', 'unknown'], true) => 'degraded',
            default => 'normal',
        };
    }

    private function stateMessage(string $state): string
    {
        return match ($state) {
            'source_error' => 'Pharmacy operational source health reports an error; last-known study facts remain qualified by cutoff.',
            'no_data' => 'No current Pharmacy orders match the bounded study filters.',
            'stale' => 'Pharmacy study facts are stale and must not be interpreted as current operational performance.',
            'degraded' => 'Study results are partial; inspect coverage, invalid intervals, conflicts, and the warehouse administration cutoff before interpretation. Administration segments are cutoff-qualified and never real-time.',
            default => 'Pharmacy real-time study clocks are current and fully covered; administration segments remain cutoff-qualified as of the warehouse batch.',
        };
    }

    /** @return array<string, mixed> */
    private function filterOptions(): array
    {
        return [
            'priorities' => self::PRIORITIES,
            'patientClasses' => self::PATIENT_CLASSES,
            'shifts' => self::SHIFTS,
            'branches' => self::BRANCHES,
            'maxRangeDays' => self::MAX_RANGE_DAYS,
            'maxLimit' => self::MAX_LIMIT,
        ];
    }

    /** @param array<string, mixed> $interval @return array<string, mixed> */
    private function publicInterval(array $interval): array
    {
        unset($interval['orderId']);

        return $interval;
    }

    private function shift(CarbonImmutable $orderedAt): string
    {
        if ($orderedAt->isWeekend()) {
            return 'weekend';
        }

        return match (true) {
            $orderedAt->hour >= 7 && $orderedAt->hour < 15 => 'day',
            $orderedAt->hour >= 15 && $orderedAt->hour < 19 => 'evening',
            default => 'night',
        };
    }

    private function groupLabel(string $key): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)
            ? CarbonImmutable::parse($key)->format('M j')
            : ucwords(str_replace('_', ' ', $key));
    }

    /** @param array<array-key, mixed> $values */
    private function mean(array $values): int|float|null
    {
        $values = array_values(array_filter($values, fn (mixed $value): bool => is_numeric($value)));

        return $values === [] ? null : $this->number(array_sum($values) / count($values));
    }

    private function number(float|int|null $value): int|float|null
    {
        if ($value === null) {
            return null;
        }
        $rounded = round((float) $value, 2);

        return floor($rounded) === $rounded ? (int) $rounded : $rounded;
    }

    private function timezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }
}
