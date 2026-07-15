<?php

declare(strict_types=1);

namespace App\Services\Lab;

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

final class LabTatAnalyticsService
{
    public const PRIORITIES = ['stat', 'urgent', 'routine', 'timed', 'discharge'];

    public const PATIENT_CLASSES = ['emergency', 'inpatient', 'outpatient', 'observation', 'perioperative', 'unknown'];

    public const SHIFTS = ['day', 'evening', 'night', 'weekend'];

    public const MAX_RANGE_DAYS = 90;

    public const MAX_LIMIT = 2000;

    private const PRIMARY_METRIC = 'lab.study.order_verify';

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
        $studyDefinitions = $definitions->where('department', 'lab')
            ->filter(fn (AncillarySlaDefinition $definition): bool => (bool) ($definition->scope['study_segment'] ?? false))
            ->sortBy(fn (AncillarySlaDefinition $definition): string => sprintf(
                '%05d|%s|%010d',
                (int) ($definition->scope['sequence'] ?? 99999),
                $definition->metric_key,
                $definition->version,
            ))->values();
        $cohort = $this->clinicalCohort($filters, $windowStart, $windowEnd);
        $assertions = $this->selectedAssertions($cohort['rows'], $studyDefinitions);
        $intervalResult = $this->intervals($cohort['rows'], $studyDefinitions, $assertions);
        $intervals = $intervalResult['intervals'];
        $primary = $intervals->where('metricKey', self::PRIMARY_METRIC)->values();
        $source = $this->sourceContext($assertions, $windowStart, $windowEnd);
        $freshness = $this->freshness($source);
        $coverage = $this->coverage($cohort, $intervalResult, $primary);
        $primaryDefinition = $studyDefinitions->firstWhere('metric_key', self::PRIMARY_METRIC);
        $chartContext = $this->chartContext($primaryDefinition, $primary, $source['sourceCutoffAt']);
        $orderIds = $cohort['rows']->pluck('ancillary_order_id')->map(fn (mixed $id): int => (int) $id);
        $specimenQuality = $this->specimenQuality($orderIds);
        $criticalCallbacks = $this->criticalCallbacks(
            $orderIds,
            $definitions->firstWhere('metric_key', 'lab.critical_notify'),
            $source['sourceCutoffAt'],
        );
        $auxiliaryInvalid = (int) $criticalCallbacks['invalidIntervalCount'];
        $state = $this->state($cohort['total'], $source['state'], $coverage, $auxiliaryInvalid);

        return [
            'generatedAt' => now()->toAtomString(),
            'sourceCutoffAt' => $source['sourceCutoffAt']?->toAtomString(),
            'freshnessStatus' => $freshness['status'],
            'degradedMode' => $state !== 'normal',
            'state' => $state,
            'stateMessage' => $this->stateMessage($state),
            'freshness' => $freshness,
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'appliedSlaDefinitions' => $definitions->map(fn (AncillarySlaDefinition $definition): array => $this->contracts->slaDefinition($definition))->values()->all(),
            'summary' => [
                ...$this->distribution($primary->pluck('minutes')->all()),
                'candidateOrderCount' => $cohort['total'],
                'includedOrderCount' => $primary->pluck('orderId')->unique()->count(),
                'clockDefinition' => $primaryDefinition === null ? null : $this->contracts->slaDefinition($primaryDefinition),
            ],
            'waterfall' => $this->waterfall($studyDefinitions, $intervals, $intervalResult['byDefinition'], $source['sourceCutoffAt']),
            'dailyTrend' => [
                ...$chartContext,
                'label' => 'Daily order-to-verification trend',
                'points' => $this->distributionGroups($primary, 'date'),
            ],
            'breakdowns' => [
                'test' => $this->breakdown('Test family', 'testFamily', $primary, $chartContext),
                'priority' => $this->breakdown('Priority', 'priority', $primary, $chartContext),
                'patientClass' => $this->breakdown('Patient class', 'patientClass', $primary, $chartContext),
                'shift' => $this->breakdown('Shift', 'shift', $primary, $chartContext),
            ],
            'amReadiness' => $this->amReadiness($cohort['rows'], $assertions, $primaryDefinition, $source['sourceCutoffAt']),
            'autoVerification' => $this->autoVerification($cohort['rows'], $source['sourceCutoffAt']),
            'specimenQuality' => $specimenQuality,
            'criticalCallbacks' => $criticalCallbacks,
            'barrierPareto' => [
                'cohortCount' => $cohort['rows']->count(),
                'sourceCutoffAt' => $source['sourceCutoffAt']?->toAtomString(),
                'clockDefinition' => 'Persisted Laboratory SLA breach lifecycle grouped by governed barrier reason, with unlinked breaches grouped by definition.',
                'points' => $this->barrierPareto($orderIds),
            ],
            'cohorts' => [
                'clinicalLab' => [
                    'label' => 'Clinical Laboratory',
                    'windowClass' => 'current_operational',
                    'populationDefinition' => 'Clinical chemistry, hematology, coagulation, molecular, and other Laboratory orders in the bounded window; microbiology, AP, and blood bank are excluded.',
                    'candidateCount' => $cohort['total'],
                    'includedCount' => $primary->pluck('orderId')->unique()->count(),
                    'primaryClockMetricKey' => self::PRIMARY_METRIC,
                ],
                'microbiology' => $this->microbiologyCohort($filters, $windowStart, $windowEnd),
                'anatomicPathology' => $this->pathologyCohort($filters, $windowStart, $windowEnd, $definitions),
                'bloodBank' => $this->bloodBankCohort($filters, $windowStart, $windowEnd),
            ],
            'benchmarkReferences' => $this->benchmarkReferences($definitions),
            'coverage' => [...$coverage, 'auxiliaryInvalidIntervalCount' => $auxiliaryInvalid],
            'lineage' => [
                'count' => $intervals->count(),
                'items' => $intervals->take(self::LINEAGE_LIMIT)->map(fn (array $interval): array => $this->publicInterval($interval))->values()->all(),
                'truncated' => $intervals->count() > self::LINEAGE_LIMIT,
                'definition' => 'Every included clinical-Laboratory interval references an effective SLA definition and the selected start/stop milestone assertions. The browser receives a bounded, patient-free audit sample.',
            ],
            'privacy' => [
                'patientIdentifiersIncluded' => false,
                'clinicalResultContentIncluded' => false,
                'sourceResultKeysIncluded' => false,
                'identifierPolicy' => 'Only operational order and milestone UUIDs are returned in the bounded lineage sample; patient references, specimen/accession identifiers, result UUIDs, source result keys, values, and narratives are excluded.',
            ],
        ];
    }

    /** @param array<string, mixed> $input @return array{dateFrom:string,dateTo:string,priority:?string,testFamily:?string,patientClass:?string,shift:?string,limit:int} */
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
            'testFamily' => is_string($input['testFamily'] ?? null) && $input['testFamily'] !== '' ? $input['testFamily'] : null,
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
        return [
            CarbonImmutable::createFromFormat('!Y-m-d', $filters['dateFrom'], $this->timezone())->startOfDay()->utc(),
            CarbonImmutable::createFromFormat('!Y-m-d', $filters['dateTo'], $this->timezone())->addDay()->startOfDay()->utc(),
        ];
    }

    /** @return Collection<int, AncillarySlaDefinition> */
    private function definitions(CarbonImmutable $windowStart, CarbonImmutable $windowEnd): Collection
    {
        return AncillarySlaDefinition::query()
            ->whereIn('department', ['lab', 'pathology', 'blood_bank'])
            ->where('effective_from', '<', $windowEnd)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $windowStart))
            ->orderBy('department')->orderBy('metric_key')->orderBy('effective_from')->get();
    }

    private function latestResults(): Builder
    {
        return DB::table('prod.lab_results as latest')
            ->join('hosp_ref.lab_test_catalog as catalog', 'catalog.lab_test_catalog_id', '=', 'latest.lab_test_catalog_id')
            ->selectRaw('DISTINCT ON (latest.ancillary_order_id)
                latest.ancillary_order_id, latest.lab_result_id, latest.auto_verified, latest.result_status,
                latest.resulted_at, latest.verified_at, latest.corrected_at,
                catalog.test_family, catalog.label AS test_label, catalog.department AS catalog_department')
            ->orderBy('latest.ancillary_order_id')
            ->orderByRaw('COALESCE(latest.corrected_at, latest.verified_at, latest.resulted_at, latest.created_at) DESC')
            ->orderByDesc('latest.lab_result_id');
    }

    /**
     * @param  array{priority:?string,testFamily:?string,patientClass:?string,shift:?string,limit:int}  $filters
     * @return array{total:int,rows:Collection<int,object>,truncated:bool}
     */
    private function clinicalCohort(array $filters, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        $query = DB::table('prod.ancillary_orders as o')
            ->leftJoinSub($this->latestResults(), 'latest_result', 'latest_result.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->where('o.department', 'lab')
            ->where('o.ordered_at', '>=', $windowStart)->where('o.ordered_at', '<', $windowEnd)
            ->whereRaw("COALESCE(o.metadata->>'operational_window', 'current') <> 'historical_study_only'")
            ->whereRaw("COALESCE(latest_result.catalog_department, '') <> 'microbiology'")
            ->when($filters['priority'] !== null, fn (Builder $query) => $query->where('o.priority', $filters['priority']))
            ->when($filters['testFamily'] !== null, fn (Builder $query) => $query->whereRaw("COALESCE(latest_result.test_family, o.metadata->>'test_family') = ?", [$filters['testFamily']]))
            ->when($filters['patientClass'] !== null, fn (Builder $query) => $query->where('o.patient_class', $filters['patientClass']));
        if ($filters['shift'] !== null) {
            $this->applyShiftFilter($query, $filters['shift']);
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('o.ordered_at')->orderBy('o.ancillary_order_id')->limit($filters['limit'])->get([
            'o.ancillary_order_id', 'o.order_uuid', 'o.priority', 'o.patient_class', 'o.ordered_at',
            'o.source_cutoff_at', 'o.metadata', 'latest_result.lab_result_id', 'latest_result.auto_verified',
            'latest_result.result_status', 'latest_result.resulted_at', 'latest_result.verified_at',
            DB::raw("COALESCE(latest_result.test_family, o.metadata->>'test_family', 'unknown') AS test_family"),
            DB::raw("COALESCE(latest_result.test_label, o.metadata->>'test_code', 'Unknown test') AS test_label"),
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
     * @return array{intervals:Collection<int,array<string,mixed>>,missing:int,negative:int,invalid:int,possible:int,conflicts:int,byDefinition:array<string,array{missing:int,negative:int,invalid:int}>}
     */
    private function intervals(Collection $rows, Collection $definitions, Collection $assertions): array
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
                    $minutes = CarbonImmutable::parse($start->occurred_at)->diffInSeconds(CarbonImmutable::parse($stop->occurred_at), false) / 60;
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
                    'definitionUuid' => (string) $definition->definition_uuid,
                    'metricKey' => (string) $definition->metric_key,
                    'minutes' => $this->number($minutes),
                    'date' => $orderedAt->toDateString(),
                    'priority' => (string) $row->priority,
                    'testFamily' => (string) $row->test_family,
                    'testLabel' => (string) $row->test_label,
                    'patientClass' => (string) $row->patient_class,
                    'shift' => $this->shift($orderedAt),
                    'sourceCutoffAt' => max(CarbonImmutable::parse($start->received_at), CarbonImmutable::parse($stop->received_at))->toAtomString(),
                    'startAssertion' => $this->assertion($start),
                    'stopAssertion' => $this->assertion($stop),
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
        if ($definition->patient_class !== null && $definition->patient_class !== $row->patient_class) {
            return false;
        }
        $scope = $definition->scope;

        return ! isset($scope['test_family']) || $scope['test_family'] === $row->test_family;
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

    /** @return array{state:string,sourceCutoffAt:?CarbonImmutable,lagMinutes:?int,sourceLabel:string} */
    private function sourceContext(Collection $assertions, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        $registered = DB::table('ops.source_freshness')->where('source_key', 'ancillary_milestones')->first();
        $orderCutoff = DB::table('prod.ancillary_orders')->whereIn('department', ['lab', 'pathology', 'blood_bank'])
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

        return ['state' => $state, 'sourceCutoffAt' => $cutoff, 'lagMinutes' => $lag, 'sourceLabel' => (string) ($registered?->source_label ?? 'Laboratory milestone feeds')];
    }

    /** @return array<string, mixed> */
    private function freshness(array $source): array
    {
        $status = match ($source['state']) {
            'fresh' => 'fresh', 'stale', 'error' => 'stale', default => 'unknown',
        };
        $explanation = match ($source['state']) {
            'error' => 'The governed ancillary milestone source reports an error.',
            'stale' => 'The latest selected Laboratory assertions exceed the registered freshness tolerance.',
            'missing' => 'No selected Laboratory assertions, order cutoff, or registered source cutoff is available.',
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

    /** @return list<array<string, mixed>> */
    private function waterfall(Collection $definitions, Collection $intervals, array $quality, ?CarbonImmutable $sourceCutoff): array
    {
        return $definitions->map(function (AncillarySlaDefinition $definition) use ($intervals, $quality, $sourceCutoff): array {
            $values = $intervals->where('definitionUuid', $definition->definition_uuid)->pluck('minutes')->all();

            return [
                'phase' => (string) ($definition->scope['phase'] ?? 'unspecified'),
                'definition' => $this->contracts->slaDefinition($definition),
                'cohortCount' => count($values),
                ...$this->distribution($values),
                'missingIntervalCount' => $quality[$definition->definition_uuid]['missing'] ?? 0,
                'excludedNegativeCount' => $quality[$definition->definition_uuid]['negative'] ?? 0,
                'invalidTimestampCount' => $quality[$definition->definition_uuid]['invalid'] ?? 0,
                'sourceCutoffAt' => $sourceCutoff?->toAtomString(),
                'benchmarkSourceLabel' => $this->benchmarkSource($definition),
            ];
        })->values()->all();
    }

    /** @return array{count:int,medianMinutes:int|float|null,p90Minutes:int|float|null,meanMinutes:int|float|null} */
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

    /** @return list<array<string, mixed>> */
    private function distributionGroups(Collection $intervals, string $field): array
    {
        return $intervals->groupBy($field)->map(function (Collection $group, string $key): array {
            return ['key' => $key, 'label' => $this->groupLabel($key), ...$this->distribution($group->pluck('minutes')->all())];
        })->sortKeys()->values()->all();
    }

    /** @return array<string, mixed> */
    private function chartContext(?AncillarySlaDefinition $definition, Collection $primary, ?CarbonImmutable $sourceCutoff): array
    {
        return [
            'clockDefinition' => $definition === null ? null : $this->contracts->slaDefinition($definition),
            'cohortCount' => $primary->count(),
            'sourceCutoffAt' => $sourceCutoff?->toAtomString(),
            'benchmarkSourceLabel' => $definition === null ? 'No effective clinical-Laboratory study clock is configured.' : $this->benchmarkSource($definition),
        ];
    }

    /** @return array<string, mixed> */
    private function breakdown(string $label, string $field, Collection $intervals, array $context): array
    {
        return [...$context, 'label' => "Order-to-verification by {$label}", 'dimension' => $field, 'points' => $this->distributionGroups($intervals, $field)];
    }

    /** @return array<string, mixed> */
    private function amReadiness(Collection $rows, Collection $assertions, ?AncillarySlaDefinition $definition, ?CarbonImmutable $sourceCutoff): array
    {
        $eligible = $rows->filter(function (object $row): bool {
            $metadata = $this->json($row->metadata);

            return ($metadata['demo_shift'] ?? $metadata['shift'] ?? null) === 'am_draw'
                || ($row->priority === 'timed' && $row->patient_class === 'inpatient');
        });
        $points = collect(range(5, 11))->map(function (int $hour) use ($eligible, $assertions): array {
            $ready = $eligible->filter(function (object $row) use ($hour, $assertions): bool {
                $verified = $assertions->get($row->ancillary_order_id.'|LAB_VERIFIED');
                if ($verified === null) {
                    return false;
                }
                $date = CarbonImmutable::parse($row->ordered_at)->setTimezone($this->timezone())->startOfDay();
                $cutoff = $date->addHours($hour);

                return CarbonImmutable::parse($verified->occurred_at)->setTimezone($this->timezone())->lessThanOrEqualTo($cutoff);
            })->count();
            $denominator = $eligible->count();

            return [
                'hour' => $hour,
                'label' => CarbonImmutable::createFromTime($hour, 0, 0, $this->timezone())->format('g A'),
                'eligibleCount' => $denominator,
                'readyCount' => $ready,
                'readyPercent' => $denominator > 0 ? round($ready / $denominator * 100, 1) : null,
            ];
        })->all();

        return [
            'clockDefinition' => $definition === null ? null : $this->contracts->slaDefinition($definition),
            'populationDefinition' => 'Clinical Laboratory orders tagged as the AM draw wave, plus timed inpatient orders, measured as verified by each local clock hour.',
            'cohortCount' => $eligible->count(),
            'sourceCutoffAt' => $sourceCutoff?->toAtomString(),
            'points' => $points,
        ];
    }

    /** @return array<string, mixed> */
    private function autoVerification(Collection $rows, ?CarbonImmutable $sourceCutoff): array
    {
        $eligible = $rows->filter(fn (object $row): bool => $row->lab_result_id !== null && $row->verified_at !== null);
        $points = $eligible->groupBy(fn (object $row): string => CarbonImmutable::parse($row->verified_at)->setTimezone($this->timezone())->toDateString())
            ->map(function (Collection $group, string $date): array {
                $auto = $group->where('auto_verified', true)->count();

                return [
                    'date' => $date,
                    'label' => CarbonImmutable::parse($date)->format('M j'),
                    'verifiedCount' => $group->count(),
                    'autoVerifiedCount' => $auto,
                    'ratePercent' => round($auto / $group->count() * 100, 1),
                ];
            })->sortKeys()->values()->all();

        return [
            'clockDefinition' => 'Latest governed result version with verified_at present; numerator is auto_verified=true and denominator is all verified latest result versions.',
            'populationDefinition' => 'Current clinical-Laboratory cohort only; microbiology progression, AP, blood bank, preliminary, and unverified results are excluded.',
            'cohortCount' => $eligible->count(),
            'sourceCutoffAt' => $sourceCutoff?->toAtomString(),
            'points' => $points,
        ];
    }

    /** @return array<string, mixed> */
    private function specimenQuality(Collection $orderIds): array
    {
        if ($orderIds->isEmpty()) {
            return $this->emptySpecimenQuality();
        }
        $rows = DB::table('prod.lab_specimens')->whereIn('ancillary_order_id', $orderIds)->get([
            'lab_specimen_id', 'parent_specimen_id', 'rejection_reason_code', 'rejected_at', 'recollect_ordered_at',
        ]);
        $primary = $rows->whereNull('parent_specimen_id');
        $rejected = $primary->filter(fn (object $row): bool => $row->rejected_at !== null || $row->rejection_reason_code !== null);
        $recollectParents = $rows->whereNotNull('parent_specimen_id')->pluck('parent_specimen_id')->unique();
        $recollectOrdered = $primary->whereNotNull('recollect_ordered_at')->pluck('lab_specimen_id');
        $recollectCount = $recollectParents->merge($recollectOrdered)->unique()->count();
        $denominator = $primary->count();

        return [
            'clockDefinition' => 'Specimen-level quality events in the bounded clinical-Laboratory order cohort; one denominator unit per original specimen.',
            'populationDefinition' => 'Original specimens only form the denominator. Recollect child specimens and recollect_ordered_at identify rework without double-counting the denominator.',
            'denominator' => $denominator,
            'rejectedCount' => $rejected->count(),
            'rejectionRatePercent' => $denominator > 0 ? round($rejected->count() / $denominator * 100, 1) : null,
            'recollectCount' => $recollectCount,
            'recollectRatePercent' => $denominator > 0 ? round($recollectCount / $denominator * 100, 1) : null,
            'reasonCounts' => $rejected->groupBy(fn (object $row): string => (string) ($row->rejection_reason_code ?? 'unspecified'))
                ->map(fn (Collection $group, string $key): array => ['key' => $key, 'label' => $this->groupLabel($key), 'count' => $group->count()])
                ->sortByDesc('count')->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function emptySpecimenQuality(): array
    {
        return [
            'clockDefinition' => 'Specimen-level quality events in the bounded clinical-Laboratory order cohort; one denominator unit per original specimen.',
            'populationDefinition' => 'Original specimens only form the denominator. Recollect child specimens and recollect_ordered_at identify rework without double-counting the denominator.',
            'denominator' => 0, 'rejectedCount' => 0, 'rejectionRatePercent' => null,
            'recollectCount' => 0, 'recollectRatePercent' => null, 'reasonCounts' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function criticalCallbacks(Collection $orderIds, ?AncillarySlaDefinition $definition, ?CarbonImmutable $sourceCutoff): array
    {
        $rows = $orderIds->isEmpty() ? collect() : DB::table('prod.lab_critical_values as c')
            ->join('prod.lab_results as r', 'r.lab_result_id', '=', 'c.lab_result_id')
            ->whereIn('r.ancillary_order_id', $orderIds)
            ->get(['c.callback_state', 'c.identified_at', 'c.acknowledged_at']);
        $minutes = [];
        $invalid = 0;
        foreach ($rows->whereNotNull('acknowledged_at') as $row) {
            try {
                $value = CarbonImmutable::parse($row->identified_at)->diffInSeconds(CarbonImmutable::parse($row->acknowledged_at), false) / 60;
                if ($value < 0) {
                    $invalid++;
                } else {
                    $minutes[] = $value;
                }
            } catch (Throwable) {
                $invalid++;
            }
        }

        return [
            'clockDefinition' => $definition === null ? null : $this->contracts->slaDefinition($definition),
            'populationDefinition' => 'Critical-value callback loops for results in the bounded clinical-Laboratory cohort. Performance uses identified_at to acknowledged_at because those are the persisted closed-loop satellite timestamps; the governed reference definition remains visible.',
            'cohortCount' => $rows->count(),
            'openCount' => $rows->whereNull('acknowledged_at')->count(),
            ...$this->distribution($minutes),
            'invalidIntervalCount' => $invalid,
            'sourceCutoffAt' => $sourceCutoff?->toAtomString(),
            'stateCounts' => $rows->groupBy('callback_state')->map(fn (Collection $group, string $key): array => ['key' => $key, 'label' => $this->groupLabel($key), 'count' => $group->count()])->sortKeys()->values()->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function barrierPareto(Collection $orderIds): array
    {
        if ($orderIds->isEmpty()) {
            return [];
        }
        $raw = DB::table('prod.ancillary_breaches as b')
            ->join('prod.ancillary_sla_definitions as d', 'd.ancillary_sla_definition_id', '=', 'b.ancillary_sla_definition_id')
            ->leftJoin('prod.barriers as barrier', 'barrier.barrier_id', '=', 'b.barrier_id')
            ->leftJoin('hosp_ref.ancillary_barrier_reasons as reason', 'reason.reason_code', '=', 'barrier.reason_code')
            ->whereIn('b.ancillary_order_id', $orderIds)->where('d.department', 'lab')
            ->selectRaw('COALESCE(reason.reason_code, d.metric_key) AS key, COALESCE(reason.label, d.label) AS label, count(*) AS total')
            ->groupByRaw('COALESCE(reason.reason_code, d.metric_key), COALESCE(reason.label, d.label)')
            ->orderByDesc('total')->orderBy('label')->get();
        $total = max(1, (int) $raw->sum('total'));
        $cumulative = 0.0;

        return $raw->map(function (object $row) use ($total, &$cumulative): array {
            $percent = round((int) $row->total / $total * 100, 1);
            $cumulative = min(100.0, round($cumulative + $percent, 1));

            return ['key' => (string) $row->key, 'label' => (string) $row->label, 'count' => (int) $row->total, 'percent' => $percent, 'cumulativePercent' => $cumulative];
        })->all();
    }

    /** @return array<string, mixed> */
    private function microbiologyCohort(array $filters, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $orders = $this->satelliteOrderQuery('lab', $filters, $start, $end)
            ->join('prod.lab_results as r', 'r.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->join('hosp_ref.lab_test_catalog as c', 'c.lab_test_catalog_id', '=', 'r.lab_test_catalog_id')
            ->where('c.department', 'microbiology')
            ->get(['o.ancillary_order_id', 'o.metadata', 'r.result_stage']);
        $unique = $orders->unique('ancillary_order_id');
        $historical = $unique->filter(fn (object $row): bool => ($this->json($row->metadata)['operational_window'] ?? null) === 'historical_study_only')->count();

        return [
            'label' => 'Microbiology progression',
            'windowClass' => $historical === $unique->count() && $unique->isNotEmpty() ? 'historical_study_only' : 'mixed_current_and_historical',
            'windowLabel' => 'Historical microbiology progression is outside the live operational window and is never mixed into clinical-Laboratory TAT percentiles.',
            'populationDefinition' => 'Microbiology catalog orders in the bounded date window, summarized by persisted result stage rather than short-cycle clinical-Laboratory clocks.',
            'candidateCount' => $unique->count(),
            'historicalCount' => $historical,
            'currentCount' => $unique->count() - $historical,
            'stageCounts' => $orders->groupBy('result_stage')->map(fn (Collection $group, string $key): array => ['key' => $key, 'label' => $this->groupLabel($key), 'count' => $group->count()])->sortKeys()->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function pathologyCohort(array $filters, CarbonImmutable $start, CarbonImmutable $end, Collection $definitions): array
    {
        $rows = $this->satelliteOrderQuery('pathology', $filters, $start, $end)
            ->join('prod.ap_cases as ap', 'ap.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->get(['o.metadata', 'ap.stage', 'ap.received_at', 'ap.signed_out_at', 'ap.frozen_started_at', 'ap.frozen_resulted_at']);
        $historical = $rows->filter(fn (object $row): bool => ($this->json($row->metadata)['operational_window'] ?? null) === 'historical_study_only')->count();
        $signOut = $this->timestampIntervals($rows, 'received_at', 'signed_out_at');
        $frozen = $this->timestampIntervals($rows, 'frozen_started_at', 'frozen_resulted_at');

        return [
            'label' => 'Anatomic Pathology',
            'windowClass' => $historical > 0 ? 'mixed_current_and_historical' : 'current_operational',
            'windowLabel' => 'Historical AP sign-out examples are labeled outside the live operational window and are never mixed into clinical-Laboratory TAT percentiles.',
            'populationDefinition' => 'AP cases ordered in the bounded window. Receipt-to-sign-out and frozen-start-to-result are reported as separate clocks.',
            'candidateCount' => $rows->count(), 'historicalCount' => $historical, 'currentCount' => $rows->count() - $historical,
            'stageCounts' => $rows->groupBy('stage')->map(fn (Collection $group, string $key): array => ['key' => $key, 'label' => $this->groupLabel($key), 'count' => $group->count()])->sortKeys()->values()->all(),
            'signOut' => ['clockDefinition' => $this->definitionContract($definitions, 'lab.ap_routine'), ...$this->distribution($signOut['minutes']), 'invalidIntervalCount' => $signOut['invalid']],
            'frozen' => ['clockDefinition' => $this->definitionContract($definitions, 'lab.frozen'), ...$this->distribution($frozen['minutes']), 'invalidIntervalCount' => $frozen['invalid']],
        ];
    }

    /** @return array<string, mixed> */
    private function bloodBankCohort(array $filters, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->satelliteOrderQuery('blood_bank', $filters, $start, $end)
            ->join('prod.bb_readiness as bb', 'bb.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->get(['bb.readiness_state', 'bb.ordered_at', 'bb.type_screen_ready_at', 'bb.crossmatch_ready_at', 'bb.issued_at']);
        $typeScreen = $this->timestampIntervals($rows, 'ordered_at', 'type_screen_ready_at');
        $crossmatch = $this->timestampIntervals($rows, 'ordered_at', 'crossmatch_ready_at');
        $issue = $this->timestampIntervals($rows, 'ordered_at', 'issued_at');

        return [
            'label' => 'Blood Bank readiness', 'windowClass' => 'current_operational',
            'populationDefinition' => 'Blood-bank readiness requests ordered in the bounded window. Type-and-screen, crossmatch, and issue clocks remain separate from clinical-Laboratory and AP turnaround.',
            'candidateCount' => $rows->count(),
            'stateCounts' => $rows->groupBy('readiness_state')->map(fn (Collection $group, string $key): array => ['key' => $key, 'label' => $this->groupLabel($key), 'count' => $group->count()])->sortKeys()->values()->all(),
            'typeScreen' => ['clockDefinition' => 'BB_ORDERED → BB_TNS_READY', ...$this->distribution($typeScreen['minutes']), 'invalidIntervalCount' => $typeScreen['invalid']],
            'crossmatch' => ['clockDefinition' => 'BB_ORDERED → BB_CROSSMATCH_READY', ...$this->distribution($crossmatch['minutes']), 'invalidIntervalCount' => $crossmatch['invalid']],
            'issue' => ['clockDefinition' => 'BB_ORDERED → BB_UNIT_ISSUED', ...$this->distribution($issue['minutes']), 'invalidIntervalCount' => $issue['invalid']],
        ];
    }

    private function satelliteOrderQuery(string $department, array $filters, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        $query = DB::table('prod.ancillary_orders as o')->where('o.department', $department)
            ->where('o.ordered_at', '>=', $start)->where('o.ordered_at', '<', $end)
            ->when($filters['priority'] !== null, fn (Builder $query) => $query->where('o.priority', $filters['priority']))
            ->when($filters['patientClass'] !== null, fn (Builder $query) => $query->where('o.patient_class', $filters['patientClass']));
        if ($filters['shift'] !== null) {
            $this->applyShiftFilter($query, $filters['shift']);
        }

        return $query;
    }

    /** @return array{minutes:list<float>,invalid:int} */
    private function timestampIntervals(Collection $rows, string $start, string $stop): array
    {
        $minutes = [];
        $invalid = 0;
        foreach ($rows as $row) {
            if ($row->{$start} === null || $row->{$stop} === null) {
                continue;
            }
            try {
                $value = CarbonImmutable::parse($row->{$start})->diffInSeconds(CarbonImmutable::parse($row->{$stop}), false) / 60;
                if ($value < 0) {
                    $invalid++;
                } else {
                    $minutes[] = $value;
                }
            } catch (Throwable) {
                $invalid++;
            }
        }

        return ['minutes' => $minutes, 'invalid' => $invalid];
    }

    /** @return list<array<string, mixed>> */
    private function benchmarkReferences(Collection $definitions): array
    {
        return $definitions->map(fn (AncillarySlaDefinition $definition): array => [
            'definitionUuid' => (string) $definition->definition_uuid,
            'metricKey' => (string) $definition->metric_key,
            'label' => (string) $definition->label,
            'sourceReferenceId' => $definition->source_reference_id,
            'sourceLabel' => $this->benchmarkSource($definition),
            'classification' => match ($definition->source_reference_id) {
                'demo_local_policy' => 'local_policy',
                'reference_only_not_policy' => 'established_reference',
                'site_policy_required' => 'site_policy_required',
                'study_clock_no_numeric_benchmark' => 'no_numeric_benchmark',
                default => 'governed_reference',
            },
            'numericLines' => $this->numericLines($definition),
        ])->values()->all();
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
            null => 'No governed benchmark source registered',
            default => 'Governed source: '.$definition->source_reference_id,
        };
    }

    /** @return array<string, mixed>|null */
    private function definitionContract(Collection $definitions, string $metricKey): ?array
    {
        $definition = $definitions->firstWhere('metric_key', $metricKey);

        return $definition === null ? null : $this->contracts->slaDefinition($definition);
    }

    /** @return array<string, mixed> */
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
            'definition' => 'Coverage is valid selected-assertion intervals divided by applicable clinical-Laboratory study-clock intervals. Missing, negative, invalid, conflicting, and row-limit-truncated evidence remains explicit.',
        ];
    }

    private function state(int $total, string $sourceState, array $coverage, int $auxiliaryInvalid): string
    {
        return match (true) {
            $sourceState === 'error' => 'source_error',
            $total === 0 => 'no_data',
            $sourceState === 'stale' => 'stale',
            $sourceState === 'missing' || $coverage['percent'] < 100
                || $coverage['excludedNegativeIntervalCount'] > 0
                || $coverage['invalidTimestampIntervalCount'] > 0
                || $coverage['truncated'] || $auxiliaryInvalid > 0 => 'degraded',
            default => 'normal',
        };
    }

    private function stateMessage(string $state): string
    {
        return match ($state) {
            'source_error' => 'Laboratory milestone source health reports an error; last-known study facts remain qualified by cutoff.',
            'no_data' => 'No current clinical-Laboratory orders match the bounded study filters.',
            'stale' => 'Laboratory study facts are stale and must not be interpreted as current operational performance.',
            'degraded' => 'Study results are partial; inspect coverage, invalid intervals, conflicts, and cohort-window labels before interpretation.',
            default => 'Clinical-Laboratory study clocks are current and fully covered for the bounded cohort.',
        };
    }

    /** @return array<string, mixed> */
    private function filterOptions(): array
    {
        return [
            'priorities' => self::PRIORITIES,
            'testFamilies' => DB::table('hosp_ref.lab_test_catalog')->where('is_active', true)
                ->whereNotIn('department', ['microbiology', 'pathology', 'blood_bank'])
                ->distinct()->orderBy('test_family')->pluck('test_family')->all(),
            'patientClasses' => self::PATIENT_CLASSES, 'shifts' => self::SHIFTS,
            'maxRangeDays' => self::MAX_RANGE_DAYS, 'maxLimit' => self::MAX_LIMIT,
        ];
    }

    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
    private function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array) $value;
        }

        return is_string($value) ? (json_decode($value, true) ?: []) : [];
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
