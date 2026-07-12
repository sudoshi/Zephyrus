<?php

declare(strict_types=1);

namespace App\Services\Radiology;

use App\Data\Ancillary\FreshnessEnvelope;
use App\Models\Radiology\Scanner;
use App\Services\Analytics\OperatingWindowResolver;
use App\Services\Analytics\SuiteMetricCalculator;
use App\Services\Ancillary\AncillaryStatistics;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class IrSuiteAnalyticsService
{
    public const MAX_RANGE_DAYS = 31;

    public const MAX_LIMIT = 1000;

    public const PATIENT_CLASSES = ['emergency', 'inpatient', 'outpatient', 'observation', 'perioperative', 'unknown'];

    private const GATES = [
        ['key' => 'preparation', 'label' => 'Order to preparation complete', 'start' => 'RAD_ORDERED', 'stop' => 'RAD_PREP_COMPLETE'],
        ['key' => 'transport', 'label' => 'Transport request to patient arrival', 'start' => 'RAD_TRANSPORT_REQUESTED', 'stop' => 'RAD_TRANSPORT_COMPLETE'],
        ['key' => 'read', 'label' => 'Images available to final report', 'start' => 'RAD_IMAGES_AVAILABLE', 'stop' => 'RAD_FINAL'],
    ];

    private const ASSERTION_CODES = [
        'RAD_ORDERED', 'RAD_PREP_COMPLETE', 'RAD_TRANSPORT_REQUESTED', 'RAD_TRANSPORT_COMPLETE',
        'RAD_EXAM_START', 'RAD_EXAM_END', 'RAD_IMAGES_AVAILABLE', 'RAD_FINAL',
    ];

    public function __construct(
        private readonly SuiteMetricCalculator $suiteMetrics,
        private readonly OperatingWindowResolver $operatingWindows,
        private readonly AncillaryStatistics $statistics,
    ) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function build(array $input = []): array
    {
        $filters = $this->filters($input);
        [$windowStart, $windowEnd] = $this->queryWindow($filters);
        $rooms = $this->declaredRooms($filters);
        $cohort = $this->cohort($filters, $rooms, $windowStart, $windowEnd);
        $assertions = $this->assertions($cohort['rows']);
        $roomRows = $rooms->map(fn (Scanner $room): array => $this->roomRow(
            $room,
            $cohort['rows']->where('rad_scanner_id', $room->rad_scanner_id)->values(),
            $assertions,
            $filters,
        ))->values();
        $sourceCutoff = $this->sourceCutoff($cohort['rows'], $assertions);
        $gates = $this->gates($cohort['rows'], $assertions, $sourceCutoff);
        $summary = $this->summary($roomRows, $cohort);
        $roomRunning = $this->roomRunning($roomRows, $filters);
        $coverage = $this->coverage($roomRows, $cohort, $gates);
        $freshness = $this->freshness($sourceCutoff);
        $state = match (true) {
            $rooms->isEmpty() => 'no_data',
            $cohort['total'] === 0 => 'no_data',
            $coverage['status'] !== 'complete' || $freshness['status'] !== 'fresh' => 'degraded',
            default => 'normal',
        };

        return [
            'generatedAt' => now()->toAtomString(),
            'sourceCutoffAt' => $sourceCutoff?->toAtomString(),
            'freshnessStatus' => $freshness['status'],
            'degradedMode' => $state === 'degraded',
            'state' => $state,
            'stateMessage' => match ($state) {
                'no_data' => $rooms->isEmpty()
                    ? 'No resources are explicitly declared as IR suites for this deployment.'
                    : 'No IR cases match the bounded Study cohort.',
                'degraded' => 'IR Study results are partial or stale; inspect declared windows, MPPS interval evidence, exclusions, and source cutoff.',
                default => 'IR room denominators, suite intervals, and imaging gates are fully covered for the bounded cohort.',
            },
            'freshness' => $freshness,
            'filters' => $filters,
            'filterOptions' => [
                'rooms' => $rooms->map(fn (Scanner $room): array => ['roomUuid' => (string) $room->scanner_uuid, 'label' => (string) $room->label])->all(),
                'patientClasses' => self::PATIENT_CLASSES,
                'maxRangeDays' => self::MAX_RANGE_DAYS,
                'maxLimit' => self::MAX_LIMIT,
            ],
            'ownership' => [
                'operationalOwner' => 'Radiology Workspace',
                'studyOwner' => 'Analytics',
                'definitionAuthority' => SuiteMetricCalculator::class,
                'radiologyHref' => '/radiology/worklist?modality=IR',
                'perioperativeHref' => '/operations/room-status',
                'perioperativeStudyHref' => '/analytics/or-utilization',
                'statement' => 'Radiology owns the live IR queue. Study reuses the Perioperative suite calculations and adds imaging preparation, transport, and read gates.',
            ],
            'definitions' => [
                'shared' => array_values($this->suiteMetrics->definitions()),
                'denominator' => 'Per-room union of deployment-declared staffed_operating_hours windows inside the selected dates. No 24-hour or generic OR-day denominator is inferred.',
                'cohort' => 'Only prod.rad_exams rows with is_ir=true, a declared IR-suite scanner, and scheduled_start_at inside the bounded date range.',
                'cutoff' => 'Newest selected milestone receipt or ancillary order source cutoff in the bounded cohort.',
            ],
            'summary' => $summary,
            'roomRunning' => $roomRunning,
            'gates' => $gates,
            'coverage' => $coverage,
            'rooms' => $roomRows->map(fn (array $row): array => $this->publicRoom($row))->all(),
            'lineage' => [
                'count' => $roomRows->sum(fn (array $row): int => count($row['lineage'])),
                'truncated' => $cohort['truncated'],
                'definition' => 'Bounded IR case audit retaining room, scheduled/actual suite clocks, FCOTS/turnover classification, and selected MPPS assertion provenance. Patient identifiers and report narrative are excluded.',
                'items' => $roomRows->flatMap(fn (array $row): array => $row['lineage'])->take(100)->values()->all(),
            ],
            'privacy' => [
                'patientIdentifiersIncluded' => false,
                'clinicalReportTextIncluded' => false,
                'identifierPolicy' => 'Only operational exam/room UUIDs and selected milestone assertion UUIDs are returned.',
            ],
        ];
    }

    /** @param array<string,mixed> $input @return array{dateFrom:string,dateTo:string,roomUuid:?string,patientClass:?string,limit:int} */
    private function filters(array $input): array
    {
        $dateTo = is_string($input['dateTo'] ?? null) ? $input['dateTo'] : now()->toDateString();
        $dateFrom = is_string($input['dateFrom'] ?? null) ? $input['dateFrom'] : CarbonImmutable::parse($dateTo)->subDays(6)->toDateString();
        try {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $dateFrom);
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $dateTo);
            if ($from === false || $to === false || $to->lessThan($from) || $from->diffInDays($to) >= self::MAX_RANGE_DAYS) {
                $dateFrom = CarbonImmutable::parse($dateTo)->subDays(6)->toDateString();
            }
        } catch (\Throwable) {
            $dateFrom = now()->subDays(6)->toDateString();
            $dateTo = now()->toDateString();
        }

        return [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'roomUuid' => is_string($input['roomUuid'] ?? null) && $input['roomUuid'] !== '' ? $input['roomUuid'] : null,
            'patientClass' => is_string($input['patientClass'] ?? null) && $input['patientClass'] !== '' ? $input['patientClass'] : null,
            'limit' => min(self::MAX_LIMIT, max(1, (int) ($input['limit'] ?? 500))),
        ];
    }

    /** @param array{dateFrom:string,dateTo:string} $filters @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function queryWindow(array $filters): array
    {
        $timezone = (string) config('app.timezone', 'UTC');

        return [
            CarbonImmutable::parse($filters['dateFrom'].' 00:00:00', $timezone)->utc(),
            CarbonImmutable::parse($filters['dateTo'].' 23:59:59.999999', $timezone)->utc(),
        ];
    }

    /** @param array{roomUuid:?string} $filters @return Collection<int,Scanner> */
    private function declaredRooms(array $filters): Collection
    {
        return Scanner::query()
            ->with(['downtimes' => fn ($query) => $query->where('status', '!=', 'cancelled')->orderBy('starts_at')])
            ->where('modality_code', 'IR')
            ->where('status', '!=', 'retired')
            ->whereRaw("COALESCE(metadata->>'ir_suite_declared', 'false') = 'true'")
            ->when($filters['roomUuid'] !== null, fn ($query) => $query->where('scanner_uuid', $filters['roomUuid']))
            ->orderBy('label')
            ->get();
    }

    /**
     * @param  Collection<int,Scanner>  $rooms
     * @return array{total:int,rows:Collection<int,object>,truncated:bool}
     */
    private function cohort(array $filters, Collection $rooms, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($rooms->isEmpty()) {
            return ['total' => 0, 'rows' => collect(), 'truncated' => false];
        }
        $query = DB::table('prod.rad_exams as exam')
            ->join('prod.ancillary_orders as orders', 'orders.ancillary_order_id', '=', 'exam.ancillary_order_id')
            ->where('exam.is_ir', true)
            ->whereIn('exam.rad_scanner_id', $rooms->modelKeys())
            ->whereBetween('exam.scheduled_start_at', [$start, $end])
            ->when($filters['patientClass'] !== null, fn (Builder $query) => $query->where('orders.patient_class', $filters['patientClass']))
            ->select([
                'exam.rad_exam_id', 'exam.exam_uuid', 'exam.ancillary_order_id', 'exam.rad_scanner_id',
                'exam.scheduled_start_at', 'exam.scheduled_end_at', 'exam.started_at', 'exam.completed_at',
                'exam.status', 'exam.procedure_code', 'exam.procedure_label', 'orders.patient_class',
                'orders.source_cutoff_at',
            ]);
        $total = (clone $query)->count();
        $rows = $query->orderBy('exam.scheduled_start_at')->orderBy('exam.rad_exam_id')->limit($filters['limit'])->get();

        return ['total' => $total, 'rows' => $rows, 'truncated' => $total > $rows->count()];
    }

    /** @param Collection<int,object> $rows @return array<int,array<string,object>> */
    private function assertions(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }
        $result = [];
        $selected = DB::table('prod.ancillary_current_assertions as assertion')
            ->join('integration.sources as source', 'source.source_id', '=', 'assertion.source_id')
            ->whereIn('assertion.ancillary_order_id', $rows->pluck('ancillary_order_id')->all())
            ->whereIn('assertion.milestone_code', self::ASSERTION_CODES)
            ->get([
                'assertion.ancillary_order_id', 'assertion.milestone_code', 'assertion.milestone_uuid',
                'assertion.occurred_at', 'assertion.received_at', 'assertion.source_rank',
                'assertion.assertion_count', 'assertion.disagreement_seconds', 'source.source_key',
            ]);
        foreach ($selected as $assertion) {
            $result[(int) $assertion->ancillary_order_id][(string) $assertion->milestone_code] = $assertion;
        }

        return $result;
    }

    /** @param Collection<int,object> $cases @param array<int,array<string,object>> $assertions @return array<string,mixed> */
    private function roomRow(Scanner $room, Collection $cases, array $assertions, array $filters): array
    {
        $contract = $room->metadata['staffed_operating_hours'] ?? null;
        $available = $this->operatingWindows->resolve(is_array($contract) ? $contract : null, $filters['dateFrom'], $filters['dateTo']);
        $timezone = is_string($contract['timezone'] ?? null) ? $contract['timezone'] : (string) config('app.timezone', 'UTC');
        $completedCandidates = $cases->where('status', 'complete')->values();
        $occupied = [];
        $invalidIntervals = 0;
        $missingIntervals = 0;
        $lineage = [];

        foreach ($completedCandidates as $case) {
            $selected = $assertions[(int) $case->ancillary_order_id] ?? [];
            $start = $selected['RAD_EXAM_START'] ?? null;
            $end = $selected['RAD_EXAM_END'] ?? null;
            if ($start === null || $end === null) {
                $missingIntervals++;

                continue;
            }
            $minutes = $this->suiteMetrics->turnoverMinutes($start->occurred_at, $end->occurred_at);
            if ($minutes === null) {
                $invalidIntervals++;

                continue;
            }
            $occupied[] = ['start' => $start->occurred_at, 'end' => $end->occurred_at];
        }

        $planned = [];
        $unplanned = [];
        foreach ($room->downtimes as $downtime) {
            $end = $downtime->ends_at ?? now();
            if (! $end->greaterThan($downtime->starts_at)) {
                continue;
            }
            $target = $this->suiteMetrics->isPlannedDowntime((string) $downtime->status, (string) $downtime->reason_code, $downtime->metadata)
                ? 'planned'
                : 'unplanned';
            ${$target}[] = ['start' => $downtime->starts_at, 'end' => $end];
        }
        $calculation = $this->suiteMetrics->utilization($available, $occupied, $planned, $unplanned);
        $coverageStatus = match (true) {
            $available === [] => 'missing_schedule',
            $completedCandidates->isEmpty() => 'no_cases',
            $missingIntervals > 0 || $invalidIntervals > 0 => 'partial',
            default => 'complete',
        };
        $complete = $coverageStatus === 'complete';

        $fcotsRows = [];
        foreach ($cases->groupBy(fn (object $case): string => CarbonImmutable::parse($case->scheduled_start_at)->setTimezone($timezone)->toDateString()) as $dayCases) {
            $first = $dayCases->sortBy('scheduled_start_at')->first();
            if ($first === null) {
                continue;
            }
            $actual = $assertions[(int) $first->ancillary_order_id]['RAD_EXAM_START'] ?? null;
            $onTime = $this->suiteMetrics->firstCaseOnTime($first->scheduled_start_at, $actual?->occurred_at);
            $fcotsRows[] = ['examUuid' => (string) $first->exam_uuid, 'onTime' => $onTime];
        }
        $eligibleFcots = collect($fcotsRows)->whereNotNull('onTime');

        $validCases = $cases->map(function (object $case) use ($assertions): ?array {
            $selected = $assertions[(int) $case->ancillary_order_id] ?? [];
            $start = $selected['RAD_EXAM_START'] ?? null;
            $end = $selected['RAD_EXAM_END'] ?? null;
            if ($start === null || $end === null || $this->suiteMetrics->turnoverMinutes($start->occurred_at, $end->occurred_at) === null) {
                return null;
            }

            return ['case' => $case, 'start' => $start, 'end' => $end];
        })->filter()->sortBy(fn (array $row): string => (string) $row['start']->occurred_at)->values();
        $turnovers = [];
        $turnoverByExam = [];
        $invalidTurnovers = 0;
        for ($index = 1; $index < $validCases->count(); $index++) {
            $previous = $validCases[$index - 1];
            $next = $validCases[$index];
            $sameDay = CarbonImmutable::parse($previous['end']->occurred_at)->setTimezone($timezone)->toDateString()
                === CarbonImmutable::parse($next['start']->occurred_at)->setTimezone($timezone)->toDateString();
            if (! $sameDay || (int) $room->capacity !== 1) {
                continue;
            }
            $minutes = $this->suiteMetrics->turnoverMinutes($previous['end']->occurred_at, $next['start']->occurred_at);
            if ($minutes === null) {
                $invalidTurnovers++;
            } else {
                $turnovers[] = $minutes;
                $turnoverByExam[(string) $next['case']->exam_uuid] = $minutes;
            }
        }

        $firstUuids = collect($fcotsRows)->pluck('examUuid')->all();
        foreach ($cases as $case) {
            $selected = $assertions[(int) $case->ancillary_order_id] ?? [];
            $start = $selected['RAD_EXAM_START'] ?? null;
            $end = $selected['RAD_EXAM_END'] ?? null;
            $isFirst = in_array((string) $case->exam_uuid, $firstUuids, true);
            $lineage[] = [
                'examUuid' => (string) $case->exam_uuid,
                'roomUuid' => (string) $room->scanner_uuid,
                'roomLabel' => (string) $room->label,
                'procedureCode' => (string) ($case->procedure_code ?? 'unknown'),
                'scheduledStartAt' => CarbonImmutable::parse($case->scheduled_start_at)->toAtomString(),
                'actualStartAt' => $start === null ? null : CarbonImmutable::parse($start->occurred_at)->toAtomString(),
                'actualEndAt' => $end === null ? null : CarbonImmutable::parse($end->occurred_at)->toAtomString(),
                'isFirstCase' => $isFirst,
                'fcotsOnTime' => $isFirst ? $this->suiteMetrics->firstCaseOnTime($case->scheduled_start_at, $start?->occurred_at) : null,
                'turnoverFromPriorMinutes' => $turnoverByExam[(string) $case->exam_uuid] ?? null,
                'startAssertion' => $this->publicAssertion($start),
                'endAssertion' => $this->publicAssertion($end),
            ];
        }
        $turnoverDistribution = $this->distribution($turnovers);

        return [
            'roomUuid' => (string) $room->scanner_uuid,
            'label' => (string) $room->label,
            'capacity' => (int) $room->capacity,
            'timezone' => $timezone,
            'operatingWindows' => array_map(fn (array $window): array => [
                'startAt' => $window['start']->setTimezone($timezone)->toAtomString(),
                'endAt' => $window['end']->setTimezone($timezone)->toAtomString(),
            ], $available),
            'caseCount' => $cases->count(),
            'completedCaseCount' => $completedCandidates->count(),
            'availableMinutes' => $calculation['availableMinutes'],
            'occupiedMinutes' => $complete ? $calculation['examMinutes'] : null,
            'plannedDowntimeMinutes' => $calculation['plannedDowntimeMinutes'],
            'unplannedDowntimeMinutes' => $calculation['unplannedDowntimeMinutes'],
            'idleMinutes' => $complete ? $calculation['idleMinutes'] : null,
            'utilizationPercent' => $complete ? $calculation['utilizationPercent'] : null,
            'reconciliationDeltaMinutes' => $complete ? $calculation['reconciliationDeltaMinutes'] : null,
            'fcots' => [
                'firstCaseCount' => count($fcotsRows),
                'eligibleCount' => $eligibleFcots->count(),
                'onTimeCount' => $eligibleFcots->where('onTime', true)->count(),
                'percent' => $eligibleFcots->isEmpty() ? null : round(100 * $eligibleFcots->where('onTime', true)->count() / $eligibleFcots->count(), 1),
                'missingActualStartCount' => collect($fcotsRows)->whereNull('onTime')->count(),
            ],
            'turnover' => [...$turnoverDistribution, 'invalidCount' => $invalidTurnovers],
            'coverage' => [
                'status' => $coverageStatus,
                'candidateIntervalCount' => $completedCandidates->count(),
                'coveredIntervalCount' => count($occupied),
                'missingIntervalCount' => $missingIntervals,
                'invalidIntervalCount' => $invalidIntervals,
                'warning' => match ($coverageStatus) {
                    'missing_schedule' => 'No staffed operating-hours contract is declared for this IR suite.',
                    'no_cases' => 'No completed cases prove interval coverage in this room and period.',
                    'partial' => 'One or more completed IR cases lack valid selected MPPS start/end assertions.',
                    default => null,
                },
            ],
            'segments' => array_map(fn (array $segment): array => [
                'startAt' => $segment['start']->setTimezone($timezone)->toAtomString(),
                'endAt' => $segment['end']->setTimezone($timezone)->toAtomString(),
                'type' => ! $complete && $segment['type'] === 'idle' ? 'unknown' : $segment['type'],
                'minutes' => $segment['minutes'],
            ], $calculation['segments']),
            'lineage' => $lineage,
            '_occupied' => $occupied,
            '_turnovers' => $turnovers,
        ];
    }

    /** @param Collection<int,object> $cases @param array<int,array<string,object>> $assertions @return list<array<string,mixed>> */
    private function gates(Collection $cases, array $assertions, ?CarbonImmutable $cutoff): array
    {
        return array_map(function (array $gate) use ($cases, $assertions, $cutoff): array {
            $values = [];
            $missing = 0;
            $invalid = 0;
            foreach ($cases as $case) {
                $selected = $assertions[(int) $case->ancillary_order_id] ?? [];
                $start = $selected[$gate['start']] ?? null;
                $stop = $selected[$gate['stop']] ?? null;
                if ($start === null && $stop === null) {
                    continue;
                }
                if ($start === null || $stop === null) {
                    $missing++;

                    continue;
                }
                $minutes = $this->statistics->intervalMinutes($start->occurred_at, $stop->occurred_at);
                if ($minutes === null) {
                    $invalid++;

                    continue;
                }
                $values[] = $minutes;
            }

            return [
                'key' => $gate['key'],
                'label' => $gate['label'],
                'startMilestoneCode' => $gate['start'],
                'stopMilestoneCode' => $gate['stop'],
                ...$this->distribution($values),
                'missingCount' => $missing,
                'invalidCount' => $invalid,
                'sourceCutoffAt' => $cutoff?->toAtomString(),
                'definition' => $gate['start'].' to '.$gate['stop'].' using the selected ancillary assertion for each milestone; absent pairs and negative intervals are excluded visibly.',
            ];
        }, self::GATES);
    }

    /** @param Collection<int,array<string,mixed>> $rooms @param array{total:int,rows:Collection<int,object>,truncated:bool} $cohort @return array<string,mixed> */
    private function summary(Collection $rooms, array $cohort): array
    {
        $complete = $rooms->isNotEmpty() && $rooms->every(fn (array $room): bool => $room['coverage']['status'] === 'complete');
        $available = $this->number($rooms->sum('availableMinutes'));
        $occupied = $complete ? $this->number($rooms->sum('occupiedMinutes')) : null;
        $planned = $this->number($rooms->sum('plannedDowntimeMinutes'));
        $unplanned = $this->number($rooms->sum('unplannedDowntimeMinutes'));
        $idle = $complete ? $this->number($rooms->sum('idleMinutes')) : null;
        $fcotsEligible = (int) $rooms->sum('fcots.eligibleCount');
        $fcotsOnTime = (int) $rooms->sum('fcots.onTimeCount');
        $turnovers = $rooms->flatMap(fn (array $room): array => $room['_turnovers'])->all();

        return [
            'declaredRoomCount' => $rooms->count(),
            'candidateCaseCount' => $cohort['total'],
            'analyzedCaseCount' => $cohort['rows']->count(),
            'completedCaseCount' => (int) $rooms->sum('completedCaseCount'),
            'availableMinutes' => $available,
            'occupiedMinutes' => $occupied,
            'plannedDowntimeMinutes' => $planned,
            'unplannedDowntimeMinutes' => $unplanned,
            'idleMinutes' => $idle,
            'utilizationPercent' => $complete ? $this->suiteMetrics->utilizationPercent($occupied, $available) : null,
            'reconciliationDeltaMinutes' => $complete ? $this->number($available - $occupied - $planned - $unplanned - $idle) : null,
            'fcots' => [
                'eligibleCount' => $fcotsEligible,
                'onTimeCount' => $fcotsOnTime,
                'percent' => $fcotsEligible > 0 ? $this->number(100 * $fcotsOnTime / $fcotsEligible, 1) : null,
                'graceMinutes' => SuiteMetricCalculator::FCOTS_GRACE_MINUTES,
            ],
            'turnover' => $this->distribution($turnovers),
        ];
    }

    /** @param Collection<int,array<string,mixed>> $rooms @return array<string,mixed> */
    private function roomRunning(Collection $rooms, array $filters): array
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $from = CarbonImmutable::parse($filters['dateFrom'].' 00:00:00', $timezone);
        $to = CarbonImmutable::parse($filters['dateTo'].' 00:00:00', $timezone);
        $byHour = [];
        $sampleCount = 0;
        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            for ($hour = SuiteMetricCalculator::ROOM_RUNNING_START_HOUR; $hour <= SuiteMetricCalculator::ROOM_RUNNING_END_HOUR; $hour++) {
                $sample = $date->setTime($hour, 0)->utc();
                $running = $rooms->sum(fn (array $room): int => $this->suiteMetrics->roomsRunningAt($room['_occupied'], $sample) > 0 ? 1 : 0);
                $byHour[$hour][] = $running;
                $sampleCount++;
            }
        }
        $points = collect($byHour)->map(fn (array $values, int $hour): array => [
            'hour' => sprintf('%02d:00', $hour),
            'averageRoomsRunning' => $this->number(array_sum($values) / max(1, count($values)), 1),
            'maxRoomsRunning' => max($values),
            'sampleDays' => count($values),
        ])->values();

        return [
            'sampleCount' => $sampleCount,
            'averageRoomsRunning' => $points->isEmpty() ? null : $this->number($points->avg('averageRoomsRunning'), 1),
            'maxRoomsRunning' => $points->max('maxRoomsRunning') ?? 0,
            'points' => $points->all(),
            'definition' => $this->suiteMetrics->definitions()['room_running']['definition'].' Hourly samples use '.sprintf('%02d:00–%02d:00', SuiteMetricCalculator::ROOM_RUNNING_START_HOUR, SuiteMetricCalculator::ROOM_RUNNING_END_HOUR).' facility time.',
        ];
    }

    /** @param Collection<int,array<string,mixed>> $rooms @param array<string,mixed> $cohort @param list<array<string,mixed>> $gates @return array<string,mixed> */
    private function coverage(Collection $rooms, array $cohort, array $gates): array
    {
        $candidate = (int) $rooms->sum('coverage.candidateIntervalCount');
        $covered = (int) $rooms->sum('coverage.coveredIntervalCount');
        $missing = (int) $rooms->sum('coverage.missingIntervalCount');
        $invalid = (int) $rooms->sum('coverage.invalidIntervalCount');
        $missingSchedules = $rooms->where('coverage.status', 'missing_schedule')->count();
        $uncoveredRooms = $rooms->filter(fn (array $room): bool => $room['coverage']['status'] !== 'complete')->count();
        $gateMissing = array_sum(array_column($gates, 'missingCount'));
        $gateInvalid = array_sum(array_column($gates, 'invalidCount'));
        $status = match (true) {
            $rooms->isEmpty() || $cohort['total'] === 0 => 'no_data',
            $uncoveredRooms > 0 || $missing > 0 || $invalid > 0 || $gateInvalid > 0 || $cohort['truncated'] => 'partial',
            default => 'complete',
        };

        return [
            'status' => $status,
            'candidateIntervalCount' => $candidate,
            'coveredIntervalCount' => $covered,
            'percent' => $candidate > 0 ? $this->number(100 * $covered / $candidate, 1) : 0,
            'missingIntervalCount' => $missing,
            'invalidIntervalCount' => $invalid,
            'missingOperatingWindowRoomCount' => $missingSchedules,
            'uncoveredRoomCount' => $uncoveredRooms,
            'missingGatePairCount' => $gateMissing,
            'invalidGateIntervalCount' => $gateInvalid,
            'truncated' => $cohort['truncated'],
            'unanalyzedCandidateCount' => max(0, $cohort['total'] - $cohort['rows']->count()),
            'definition' => 'Coverage compares completed IR cases with selected MPPS start/end assertions, declared operating windows, and valid imaging-gate pairs. Missing optional gate pairs are counted but do not by themselves invalidate core suite utilization.',
        ];
    }

    /** @param Collection<int,object> $rows @param array<int,array<string,object>> $assertions */
    private function sourceCutoff(Collection $rows, array $assertions): ?CarbonImmutable
    {
        $values = $rows->pluck('source_cutoff_at')->filter()->all();
        foreach ($assertions as $byCode) {
            foreach ($byCode as $assertion) {
                $values[] = $assertion->received_at;
            }
        }

        return collect($values)->map(fn (mixed $value): CarbonImmutable => CarbonImmutable::parse($value))->sortByDesc(fn (CarbonImmutable $value): int => $value->getTimestamp())->first();
    }

    /** @return array<string,mixed> */
    private function freshness(?CarbonImmutable $cutoff): array
    {
        if ($cutoff === null) {
            return (new FreshnessEnvelope('unknown', new DateTimeImmutable(now()->toAtomString()), null, null, 'IR selected milestone feeds', 'No selected source cutoff is available for this cohort.'))->toArray();
        }
        $lag = max(0, (int) floor((now()->getTimestamp() - $cutoff->getTimestamp()) / 60));
        $fresh = $lag <= (int) config('integrations.fresh_after_minutes', 15);

        return (new FreshnessEnvelope(
            $fresh ? 'fresh' : 'stale',
            new DateTimeImmutable(now()->toAtomString()),
            new DateTimeImmutable($cutoff->toAtomString()),
            $lag,
            'IR selected milestone feeds',
            $fresh ? null : 'The newest selected IR assertion is older than the configured freshness window.',
        ))->toArray();
    }

    /** @param array<array-key,int|float|null> $values @return array{count:int,median:int|float|null,p90:int|float|null,meanMinutes:int|float|null} */
    private function distribution(array $values): array
    {
        $valid = array_values(array_filter($values, fn (mixed $value): bool => is_numeric($value) && (float) $value >= 0));
        $distribution = $this->statistics->distribution($valid);

        return [
            'count' => $distribution['count'],
            'median' => $this->number($distribution['median']),
            'p90' => $this->number($distribution['p90']),
            'meanMinutes' => $valid === [] ? null : $this->number(array_sum($valid) / count($valid)),
        ];
    }

    /** @return array<string,mixed>|null */
    private function publicAssertion(?object $assertion): ?array
    {
        return $assertion === null ? null : [
            'milestoneUuid' => (string) $assertion->milestone_uuid,
            'code' => (string) $assertion->milestone_code,
            'occurredAt' => CarbonImmutable::parse($assertion->occurred_at)->toAtomString(),
            'receivedAt' => CarbonImmutable::parse($assertion->received_at)->toAtomString(),
            'sourceKey' => (string) $assertion->source_key,
            'sourceRank' => (int) $assertion->source_rank,
            'assertionCount' => (int) $assertion->assertion_count,
        ];
    }

    /** @param array<string,mixed> $room @return array<string,mixed> */
    private function publicRoom(array $room): array
    {
        unset($room['_occupied'], $room['_turnovers'], $room['lineage']);

        return $room;
    }

    private function number(float|int|null $value, int $precision = 2): float|int|null
    {
        if ($value === null) {
            return null;
        }
        $rounded = round((float) $value, $precision);

        return floor($rounded) === $rounded ? (int) $rounded : $rounded;
    }
}
