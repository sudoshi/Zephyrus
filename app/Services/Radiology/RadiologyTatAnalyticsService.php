<?php

declare(strict_types=1);

namespace App\Services\Radiology;

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

final class RadiologyTatAnalyticsService
{
    public const PRIORITIES = ['stat', 'urgent', 'routine', 'discharge'];

    public const PATIENT_CLASSES = ['emergency', 'inpatient', 'outpatient', 'observation', 'perioperative', 'unknown'];

    public const SHIFTS = ['day', 'evening', 'night', 'weekend'];

    public const MAX_RANGE_DAYS = 90;

    public const MAX_LIMIT = 2000;

    private const PRIMARY_METRIC = 'rad.study.order_final';

    private const LINEAGE_LIMIT = 100;

    public function __construct(
        private readonly AncillaryContractSerializer $contracts,
        private readonly AncillaryStatistics $statistics,
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
        $assertions = $this->selectedAssertions($cohort['rows'], $studyDefinitions);
        $intervalResult = $this->intervals($cohort['rows'], $studyDefinitions, $assertions);
        $intervals = $intervalResult['intervals'];
        $primary = $intervals->where('metricKey', self::PRIMARY_METRIC)->values();
        $source = $this->sourceContext($assertions);
        $freshness = $this->freshness($source);
        $coverage = $this->coverage($cohort, $intervalResult, $primary);
        $state = $this->state($cohort['total'], $source['state'], $coverage);
        $primaryDefinition = $studyDefinitions->firstWhere('metric_key', self::PRIMARY_METRIC);
        $chartContext = $this->chartContext($primaryDefinition, $primary, $source['sourceCutoffAt']);

        return [
            'generatedAt' => now()->toAtomString(),
            'sourceCutoffAt' => $source['sourceCutoffAt']?->toAtomString(),
            'state' => $state,
            'stateMessage' => $this->stateMessage($state),
            'freshness' => $freshness,
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'summary' => [
                ...$this->statistics->distribution($primary->pluck('minutes')->all()),
                'meanMinutes' => $this->mean($primary->pluck('minutes')->all()),
                'candidateExamCount' => $cohort['total'],
                'includedExamCount' => $primary->pluck('orderId')->unique()->count(),
            ],
            'waterfall' => $this->waterfall($studyDefinitions, $intervals, $intervalResult['byDefinition'], $source['sourceCutoffAt']),
            'dailyTrend' => [
                ...$chartContext,
                'label' => 'Daily order-to-final trend',
                'points' => $this->distributionGroups($primary, 'date'),
            ],
            'breakdowns' => [
                'priority' => $this->breakdown('Priority', 'priority', $primary, $chartContext),
                'modality' => $this->breakdown('Modality', 'modality', $primary, $chartContext),
                'patientClass' => $this->breakdown('Patient class', 'patientClass', $primary, $chartContext),
                'shift' => $this->breakdown('Shift', 'shift', $primary, $chartContext),
            ],
            'nightWeekendComparison' => [
                ...$chartContext,
                'label' => 'Weekday day/evening versus night and weekend',
                'definition' => 'Shift is assigned from order time in the facility timezone: weekday day 07:00–14:59, evening 15:00–18:59, night 19:00–06:59, and weekend all day Saturday/Sunday.',
                'points' => $this->distributionGroups($primary, 'shift'),
            ],
            'breachPareto' => [
                'cohortCount' => $cohort['rows']->count(),
                'sourceCutoffAt' => $source['sourceCutoffAt']?->toAtomString(),
                'definition' => 'Persisted Radiology SLA breach lifecycle rows for the bounded cohort, grouped by governed barrier reason when linked and otherwise by SLA definition.',
                'points' => $this->breachPareto($cohort['rows']),
            ],
            'benchmarkLines' => $this->benchmarkLines($definitions),
            'coverage' => $coverage,
            'lineage' => [
                'count' => $intervals->count(),
                'items' => $intervals->take(self::LINEAGE_LIMIT)->map(fn (array $interval): array => $this->publicInterval($interval))->values()->all(),
                'truncated' => $intervals->count() > self::LINEAGE_LIMIT,
                'definition' => 'Every included interval references the exact effective SLA definition and selected start/stop milestone assertions. The browser receives only a bounded audit sample.',
            ],
            'privacy' => [
                'patientIdentifiersIncluded' => false,
                'clinicalReportTextIncluded' => false,
                'identifierPolicy' => 'Only operational order/exam UUIDs and selected assertion UUIDs are returned; patient references and report narrative are excluded.',
            ],
        ];
    }

    /** @param array<string, mixed> $input @return array{dateFrom:string,dateTo:string,priority:?string,modality:?string,patientClass:?string,shift:?string,limit:int} */
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
            'modality' => is_string($input['modality'] ?? null) && $input['modality'] !== '' ? $input['modality'] : null,
            'patientClass' => is_string($input['patientClass'] ?? null) && in_array($input['patientClass'], self::PATIENT_CLASSES, true) ? $input['patientClass'] : null,
            'shift' => is_string($input['shift'] ?? null) && in_array($input['shift'], self::SHIFTS, true) ? $input['shift'] : null,
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
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $filters['dateFrom'], $this->timezone())->startOfDay()->utc();
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $filters['dateTo'], $this->timezone())->addDay()->startOfDay()->utc();

        return [$start, $end];
    }

    /** @return Collection<int, AncillarySlaDefinition> */
    private function definitions(CarbonImmutable $windowStart, CarbonImmutable $windowEnd): Collection
    {
        return AncillarySlaDefinition::query()
            ->where('department', 'rad')
            ->where('effective_from', '<', $windowEnd)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $windowStart))
            ->orderBy('metric_key')->orderBy('effective_from')->get();
    }

    /**
     * @param  array{priority:?string,modality:?string,patientClass:?string,shift:?string,limit:int}  $filters
     * @return array{total:int,rows:Collection<int,object>,truncated:bool}
     */
    private function cohort(array $filters, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        $corrections = DB::table('prod.rad_reads')
            ->selectRaw("rad_exam_id, count(*) FILTER (WHERE status IN ('corrected', 'addendum')) AS correction_count")
            ->groupBy('rad_exam_id');
        $query = DB::table('prod.ancillary_orders as o')
            ->join('prod.rad_exams as x', 'x.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->leftJoinSub($corrections, 'corrections', 'corrections.rad_exam_id', '=', 'x.rad_exam_id')
            ->where('o.department', 'rad')
            ->where('o.ordered_at', '>=', $windowStart)
            ->where('o.ordered_at', '<', $windowEnd)
            ->whereNotIn('x.status', ['cancelled', 'discontinued'])
            ->when($filters['priority'] !== null, fn (Builder $query) => $query->where('o.priority', $filters['priority']))
            ->when($filters['modality'] !== null, fn (Builder $query) => $query->where('x.modality_code', $filters['modality']))
            ->when($filters['patientClass'] !== null, fn (Builder $query) => $query->where('o.patient_class', $filters['patientClass']));
        if ($filters['shift'] !== null) {
            $this->applyShiftFilter($query, $filters['shift']);
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('o.ordered_at')->orderBy('o.ancillary_order_id')
            ->limit($filters['limit'])
            ->get([
                'o.ancillary_order_id', 'o.order_uuid', 'o.priority', 'o.patient_class', 'o.ordered_at',
                'o.source_cutoff_at', 'x.rad_exam_id', 'x.exam_uuid', 'x.modality_code',
                DB::raw('COALESCE(corrections.correction_count, 0) AS correction_count'),
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

    /** @param Collection<int, object> $rows @param Collection<int, AncillarySlaDefinition> $definitions @return Collection<string, object> */
    private function selectedAssertions(Collection $rows, Collection $definitions): Collection
    {
        if ($rows->isEmpty() || $definitions->isEmpty()) {
            return collect();
        }
        $codes = $definitions->flatMap(fn (AncillarySlaDefinition $definition): array => [
            $definition->start_milestone_code,
            $definition->stop_milestone_code,
        ])->unique()->values()->all();

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
     * @return array{intervals:Collection<int,array<string,mixed>>,missing:int,negative:int,invalid:int,corrected:int,possible:int,conflicts:int,byDefinition:array<string,array{missing:int,negative:int,invalid:int}>}
     */
    private function intervals(Collection $rows, Collection $definitions, Collection $assertions): array
    {
        $intervals = collect();
        $missing = $negative = $invalid = $corrected = $possible = 0;
        $conflictIds = [];
        $byDefinition = [];
        foreach ($definitions as $definition) {
            $byDefinition[$definition->definition_uuid] = ['missing' => 0, 'negative' => 0, 'invalid' => 0];
        }

        foreach ($rows as $row) {
            if ((int) $row->correction_count > 0) {
                $corrected++;

                continue;
            }
            foreach ($definitions as $definition) {
                if (! $this->definitionApplies($definition, $row)) {
                    continue;
                }
                $possible++;
                $start = $assertions->get($row->ancillary_order_id.'|'.$definition->start_milestone_code);
                $stop = $assertions->get($row->ancillary_order_id.'|'.$definition->stop_milestone_code);
                if ($start === null || $stop === null) {
                    $missing++;
                    $byDefinition[$definition->definition_uuid]['missing']++;

                    continue;
                }
                foreach ([$start, $stop] as $assertion) {
                    if ((int) $assertion->assertion_count > 1) {
                        $conflictIds[(int) $assertion->ancillary_milestone_id] = true;
                    }
                }
                try {
                    $minutes = CarbonImmutable::parse($start->occurred_at)
                        ->diffInSeconds(CarbonImmutable::parse($stop->occurred_at), false) / 60;
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
                $intervals->push([
                    'orderId' => (int) $row->ancillary_order_id,
                    'orderUuid' => (string) $row->order_uuid,
                    'examUuid' => (string) $row->exam_uuid,
                    'definitionUuid' => (string) $definition->definition_uuid,
                    'metricKey' => (string) $definition->metric_key,
                    'minutes' => $this->number($minutes),
                    'date' => $orderedAt->toDateString(),
                    'priority' => (string) $row->priority,
                    'modality' => (string) ($row->modality_code ?? 'unknown'),
                    'patientClass' => (string) $row->patient_class,
                    'shift' => $this->shift($orderedAt),
                    'sourceCutoffAt' => max(CarbonImmutable::parse($start->received_at), CarbonImmutable::parse($stop->received_at))->toAtomString(),
                    'startAssertion' => $this->assertion($start),
                    'stopAssertion' => $this->assertion($stop),
                ]);
            }
        }

        return [
            'intervals' => $intervals,
            'missing' => $missing,
            'negative' => $negative,
            'invalid' => $invalid,
            'corrected' => $corrected,
            'possible' => $possible,
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
        if ($definition->patient_class !== null && $definition->patient_class !== $row->patient_class) {
            return false;
        }
        $scope = $definition->scope;

        return ! isset($scope['modality']) || $scope['modality'] === $row->modality_code;
    }

    /** @return array<string, mixed> */
    private function assertion(object $row): array
    {
        return [
            'milestoneUuid' => (string) $row->milestone_uuid,
            'code' => (string) $row->milestone_code,
            'occurredAt' => CarbonImmutable::parse($row->occurred_at)->toAtomString(),
            'receivedAt' => CarbonImmutable::parse($row->received_at)->toAtomString(),
            'sourceKey' => (string) $row->source_key,
            'sourceRank' => (int) $row->source_rank,
            'assertionCount' => (int) $row->assertion_count,
        ];
    }

    /** @param Collection<string, object> $assertions @return array{state:string,sourceCutoffAt:?CarbonImmutable,lagMinutes:?int,sourceLabel:string} */
    private function sourceContext(Collection $assertions): array
    {
        $registered = DB::table('ops.source_freshness')->where('source_key', 'ancillary_milestones')->first();
        $cutoffValue = collect([
            $assertions->pluck('received_at')->filter()->max(),
            $registered?->latest_observed_at,
        ])->filter()->max();
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

        return [
            'state' => $state,
            'sourceCutoffAt' => $cutoff,
            'lagMinutes' => $lag,
            'sourceLabel' => (string) ($registered?->source_label ?? 'Radiology milestone feeds'),
        ];
    }

    /** @param array{state:string,sourceCutoffAt:?CarbonImmutable,lagMinutes:?int,sourceLabel:string} $source @return array<string, mixed> */
    private function freshness(array $source): array
    {
        $status = match ($source['state']) {
            'fresh' => 'fresh', 'stale', 'error' => 'stale', default => 'unknown',
        };
        $explanation = match ($source['state']) {
            'error' => 'The governed ancillary milestone source reports an error.',
            'stale' => 'The latest selected Radiology assertions exceed the registered freshness tolerance.',
            'missing' => 'No selected Radiology assertions or registered source cutoff are available.',
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

    /** @param Collection<int, AncillarySlaDefinition> $definitions @param Collection<int, array<string,mixed>> $intervals @param array<string,array{missing:int,negative:int,invalid:int}> $quality @return list<array<string,mixed>> */
    private function waterfall(Collection $definitions, Collection $intervals, array $quality, ?CarbonImmutable $sourceCutoff): array
    {
        return $definitions->map(function (AncillarySlaDefinition $definition) use ($intervals, $quality, $sourceCutoff): array {
            $values = $intervals->where('definitionUuid', $definition->definition_uuid)->pluck('minutes')->all();
            $distribution = $this->statistics->distribution($values);

            return [
                'definition' => $this->contracts->slaDefinition($definition),
                'cohortCount' => $distribution['count'],
                'medianMinutes' => $this->number($distribution['median']),
                'p90Minutes' => $this->number($distribution['p90']),
                'meanMinutes' => $this->mean($values),
                'missingIntervalCount' => $quality[$definition->definition_uuid]['missing'] ?? 0,
                'excludedNegativeCount' => $quality[$definition->definition_uuid]['negative'] ?? 0,
                'invalidTimestampCount' => $quality[$definition->definition_uuid]['invalid'] ?? 0,
                'sourceCutoffAt' => $sourceCutoff?->toAtomString(),
                'benchmarkSourceLabel' => $this->benchmarkSource($definition),
                'benchmarkLines' => $this->definitionBenchmarkLines($definition),
            ];
        })->values()->all();
    }

    /** @param Collection<int,array<string,mixed>> $intervals @return list<array<string,mixed>> */
    private function distributionGroups(Collection $intervals, string $field): array
    {
        return $intervals->groupBy($field)->map(function (Collection $group, string $key): array {
            $distribution = $this->statistics->distribution($group->pluck('minutes')->all());

            return [
                'key' => $key,
                'label' => $this->groupLabel($key),
                'count' => $distribution['count'],
                'medianMinutes' => $this->number($distribution['median']),
                'p90Minutes' => $this->number($distribution['p90']),
                'meanMinutes' => $this->mean($group->pluck('minutes')->all()),
            ];
        })->sortKeys()->values()->all();
    }

    /** @param Collection<int,array<string,mixed>> $intervals @param array<string,mixed> $context @return array<string,mixed> */
    private function breakdown(string $label, string $field, Collection $intervals, array $context): array
    {
        return [
            ...$context,
            'label' => "Order-to-final by {$label}",
            'dimension' => $field,
            'points' => $this->distributionGroups($intervals, $field),
        ];
    }

    /** @param Collection<int,array<string,mixed>> $primary @return array<string,mixed> */
    private function chartContext(?AncillarySlaDefinition $definition, Collection $primary, ?CarbonImmutable $sourceCutoff): array
    {
        return [
            'clockDefinition' => $definition === null ? null : $this->contracts->slaDefinition($definition),
            'cohortCount' => $primary->count(),
            'sourceCutoffAt' => $sourceCutoff?->toAtomString(),
            'benchmarkSourceLabel' => $definition === null ? 'No effective study clock is configured.' : $this->benchmarkSource($definition),
        ];
    }

    /** @param array{total:int,rows:Collection<int,object>,truncated:bool} $cohort @param array<string,mixed> $result @param Collection<int,array<string,mixed>> $primary @return array<string,mixed> */
    private function coverage(array $cohort, array $result, Collection $primary): array
    {
        $possible = (int) $result['possible'];
        $included = $result['intervals']->count();

        return [
            'candidateExamCount' => $cohort['total'],
            'analyzedExamCount' => $cohort['rows']->count(),
            'includedExamCount' => $primary->pluck('orderId')->unique()->count(),
            'possibleIntervalCount' => $possible,
            'includedIntervalCount' => $included,
            'percent' => $possible > 0 ? round($included / $possible * 100, 1) : 0.0,
            'missingAssertionIntervalCount' => (int) $result['missing'],
            'excludedNegativeIntervalCount' => (int) $result['negative'],
            'invalidTimestampIntervalCount' => (int) $result['invalid'],
            'excludedCorrectedExamCount' => (int) $result['corrected'],
            'selectedAssertionConflictCount' => (int) $result['conflicts'],
            'truncated' => $cohort['truncated'],
            'unanalyzedCandidateCount' => max(0, $cohort['total'] - $cohort['rows']->count()),
            'definition' => 'Coverage is included valid selected-assertion intervals divided by applicable study-clock intervals among analyzed non-corrected exams. Missing, negative, invalid, corrected, and limit-truncated records remain explicit.',
        ];
    }

    /** @param Collection<int, object> $rows @return list<array<string,mixed>> */
    private function breachPareto(Collection $rows): array
    {
        $orderIds = $rows->where('correction_count', 0)->pluck('ancillary_order_id');
        if ($orderIds->isEmpty()) {
            return [];
        }
        $raw = DB::table('prod.ancillary_breaches as b')
            ->join('prod.ancillary_sla_definitions as d', 'd.ancillary_sla_definition_id', '=', 'b.ancillary_sla_definition_id')
            ->leftJoin('prod.barriers as barrier', 'barrier.barrier_id', '=', 'b.barrier_id')
            ->leftJoin('hosp_ref.ancillary_barrier_reasons as reason', 'reason.reason_code', '=', 'barrier.reason_code')
            ->whereIn('b.ancillary_order_id', $orderIds)
            ->where('d.department', 'rad')
            ->selectRaw('COALESCE(reason.reason_code, d.metric_key) AS key,
                COALESCE(reason.label, d.label) AS label,
                count(*) AS total')
            ->groupByRaw('COALESCE(reason.reason_code, d.metric_key), COALESCE(reason.label, d.label)')
            ->orderByDesc('total')->orderBy('label')->get();
        $total = max(1, (int) $raw->sum('total'));
        $cumulative = 0.0;

        return $raw->map(function (object $row) use ($total, &$cumulative): array {
            $percent = round((int) $row->total / $total * 100, 1);
            $cumulative = min(100.0, round($cumulative + $percent, 1));

            return [
                'key' => (string) $row->key,
                'label' => (string) $row->label,
                'count' => (int) $row->total,
                'percent' => $percent,
                'cumulativePercent' => $cumulative,
            ];
        })->all();
    }

    /** @param Collection<int, AncillarySlaDefinition> $definitions @return list<array<string,mixed>> */
    private function benchmarkLines(Collection $definitions): array
    {
        return $definitions->flatMap(fn (AncillarySlaDefinition $definition): array => $this->definitionBenchmarkLines($definition))->values()->all();
    }

    /** @return list<array<string,mixed>> */
    private function definitionBenchmarkLines(AncillarySlaDefinition $definition): array
    {
        if ($definition->unit !== 'minutes') {
            return [];
        }
        $values = [
            'warning' => $definition->warning_minutes,
            'breach' => $definition->breach_minutes,
            'target' => $definition->target_value === null ? null : (float) $definition->target_value,
        ];

        return collect($values)->filter(fn (mixed $value): bool => $value !== null)->map(fn (mixed $value, string $kind): array => [
            'definitionUuid' => (string) $definition->definition_uuid,
            'metricKey' => (string) $definition->metric_key,
            'label' => $definition->label.' '.ucfirst($kind),
            'lineKind' => $kind,
            'valueMinutes' => $this->number((float) $value),
            'scopeLabel' => $this->scopeLabel($definition),
            'sourceLabel' => $this->benchmarkSource($definition),
            'sourceReferenceId' => $definition->source_reference_id,
        ])->values()->all();
    }

    private function benchmarkSource(AncillarySlaDefinition $definition): string
    {
        return match ($definition->source_reference_id) {
            'demo_local_policy' => 'Local demo policy; not an external benchmark',
            'reference_only_not_policy' => 'Reference-only clock; not an active policy',
            'study_clock_no_numeric_benchmark' => 'Reference clock only; no governed numeric benchmark',
            'site_policy_required' => 'Site policy required before numeric comparison',
            null => 'No governed benchmark source registered',
            default => 'Governed source: '.$definition->source_reference_id,
        };
    }

    private function scopeLabel(AncillarySlaDefinition $definition): string
    {
        $parts = [];
        if ($definition->priority !== null) {
            $parts[] = strtoupper($definition->priority);
        }
        if ($definition->patient_class !== null) {
            $parts[] = ucfirst($definition->patient_class);
        }
        if (isset($definition->scope['modality'])) {
            $parts[] = (string) $definition->scope['modality'];
        }

        return $parts === [] ? 'All applicable Radiology exams' : implode(' · ', $parts);
    }

    /** @return array<string, mixed> */
    private function publicInterval(array $interval): array
    {
        unset($interval['orderId']);

        return $interval;
    }

    private function state(int $total, string $sourceState, array $coverage): string
    {
        return match (true) {
            $sourceState === 'error' => 'source_error',
            $total === 0 => 'no_data',
            $sourceState === 'stale' => 'stale',
            $sourceState === 'missing' || $coverage['percent'] < 100
                || $coverage['excludedCorrectedExamCount'] > 0
                || $coverage['excludedNegativeIntervalCount'] > 0
                || $coverage['invalidTimestampIntervalCount'] > 0
                || $coverage['truncated'] => 'degraded',
            default => 'normal',
        };
    }

    private function stateMessage(string $state): string
    {
        return match ($state) {
            'source_error' => 'Radiology milestone source health reports an error; last-known study facts remain qualified by cutoff.',
            'no_data' => 'No Radiology exams match the bounded study filters.',
            'stale' => 'Radiology study facts are stale and must not be interpreted as current operational performance.',
            'degraded' => 'Study results are partial; inspect coverage, exclusions, conflicts, and row-limit evidence before interpretation.',
            default => 'Radiology study clocks are current and fully covered for the bounded cohort.',
        };
    }

    /** @return array<string, mixed> */
    private function filterOptions(): array
    {
        return [
            'priorities' => self::PRIORITIES,
            'modalities' => DB::table('hosp_ref.rad_modalities')->where('is_active', true)->orderBy('label')->get(['code', 'label'])->map(fn (object $row): array => ['code' => $row->code, 'label' => $row->label])->all(),
            'patientClasses' => self::PATIENT_CLASSES,
            'shifts' => self::SHIFTS,
            'maxRangeDays' => self::MAX_RANGE_DAYS,
            'maxLimit' => self::MAX_LIMIT,
        ];
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
        return str_contains($key, '-') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)
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
