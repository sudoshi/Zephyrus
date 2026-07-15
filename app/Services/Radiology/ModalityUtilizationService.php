<?php

namespace App\Services\Radiology;

use App\Models\Integration\Source;
use App\Models\Radiology\Exam;
use App\Models\Radiology\Modality;
use App\Models\Radiology\Scanner;
use App\Services\Analytics\OperatingWindowResolver;
use App\Services\Analytics\OperationalIntervalCalculator;
use App\Services\Analytics\SuiteMetricCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ModalityUtilizationService
{
    public function __construct(
        private readonly OperationalIntervalCalculator $intervals,
        private readonly OperatingWindowResolver $operatingWindows,
        private readonly SuiteMetricCalculator $suiteMetrics,
    ) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function build(array $input = []): array
    {
        $filters = $this->filters($input);
        $scanners = Scanner::query()
            ->with(['source', 'downtimes' => fn ($query) => $query->where('status', '!=', 'cancelled')->orderBy('starts_at')])
            ->where('status', '!=', 'retired')
            ->when($filters['modality'] !== null, fn ($query) => $query->where('modality_code', $filters['modality']))
            ->orderBy('modality_code')->orderBy('label')->get();

        $availableByScanner = $scanners->mapWithKeys(fn (Scanner $scanner): array => [
            (int) $scanner->rad_scanner_id => $this->operatingWindows($scanner, $filters),
        ]);
        $queryWindow = $this->queryWindow($availableByScanner);
        $exams = $queryWindow === null
            ? collect()
            : Exam::query()->with('ancillaryOrder:ancillary_order_id,patient_class')
                ->whereIn('rad_scanner_id', $scanners->modelKeys())
                ->whereNotNull('started_at')
                ->where('started_at', '<', $queryWindow['end'])
                ->where(fn ($query) => $query->whereNull('completed_at')->orWhere('completed_at', '>', $queryWindow['start']))
                ->whereNotIn('status', ['cancelled', 'discontinued'])
                ->get();

        $mppsSources = Source::query()
            ->where(fn ($query) => $query->where('system_class', 'mpps')->orWhereRaw("metadata->>'ancillary_source_class' = ?", ['mpps']))
            ->get(['source_id', 'source_key', 'system_class', 'protocol_health_status', 'protocol_health_checked_at', 'metadata']);
        $mppsSourceIds = $mppsSources->pluck('source_id')->map(fn (mixed $id): int => (int) $id)->all();
        $evidence = $this->mppsEvidence($exams, $mppsSourceIds);
        $milestoneCutoff = $mppsSourceIds === [] ? null : DB::table('prod.ancillary_milestones')
            ->whereIn('source_id', $mppsSourceIds)->whereIn('milestone_code', ['RAD_EXAM_START', 'RAD_EXAM_END'])
            ->max('received_at');
        $milestoneSourceIds = $mppsSourceIds === [] ? collect() : DB::table('prod.ancillary_milestones')
            ->whereIn('source_id', $mppsSourceIds)->whereIn('milestone_code', ['RAD_EXAM_START', 'RAD_EXAM_END'])
            ->distinct()->pluck('source_id');
        $watermarkCutoff = $mppsSourceIds === [] ? null : DB::table('integration.connector_watermarks')
            ->whereIn('source_id', $mppsSourceIds)->max('last_success_at');
        $watermarkSourceIds = $mppsSourceIds === [] ? collect() : DB::table('integration.connector_watermarks')
            ->whereIn('source_id', $mppsSourceIds)->whereNotNull('last_success_at')->distinct()->pluck('source_id');
        $sourceCutoff = $this->latestInstant($milestoneCutoff, $watermarkCutoff);
        $observedMppsSourceIds = $milestoneSourceIds->merge($watermarkSourceIds)
            ->merge($mppsSources->where('protocol_health_status', 'healthy')->pluck('source_id'))
            ->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $observedMppsSourceKeys = $mppsSources->whereIn('source_id', $observedMppsSourceIds)->pluck('source_key')->all();
        $feedObserved = $observedMppsSourceIds !== [];

        $rows = $scanners->map(fn (Scanner $scanner): array => $this->scannerRow(
            $scanner,
            $availableByScanner->get((int) $scanner->rad_scanner_id, []),
            $exams->where('rad_scanner_id', $scanner->rad_scanner_id)->values(),
            $evidence,
            $observedMppsSourceIds,
            $observedMppsSourceKeys,
        ))->values();

        $summary = $this->summary($rows, $feedObserved);
        $allSchedulesPresent = $rows->every(fn (array $row): bool => $row['availableWindows'] !== []);
        $allCoverageComplete = $rows->every(fn (array $row): bool => $row['coverage']['status'] === 'complete');
        $state = match (true) {
            $rows->isEmpty() => 'no_data',
            ! $allSchedulesPresent || ! $allCoverageComplete => 'degraded',
            default => 'normal',
        };
        $stateMessage = match ($state) {
            'no_data' => 'No active scanners match the selected modality.',
            'degraded' => 'Utilization is withheld where staffed hours or authoritative MPPS interval coverage is incomplete.',
            default => 'All matching scanners have declared staffed hours and complete MPPS interval coverage.',
        };

        return [
            'generatedAt' => now()->toAtomString(),
            'sourceCutoffAt' => $sourceCutoff === null ? null : CarbonImmutable::parse($sourceCutoff)->toAtomString(),
            'state' => $state,
            'stateMessage' => $stateMessage,
            'filters' => $filters,
            'filterOptions' => [
                'modalities' => Modality::query()->where('is_active', true)->orderBy('label')->get(['code', 'label'])
                    ->map(fn (Modality $modality): array => ['code' => $modality->code, 'label' => $modality->label])->all(),
            ],
            'coverage' => [
                'status' => $rows->isEmpty() ? 'no_data' : ($allCoverageComplete ? 'complete' : (! $feedObserved ? 'missing' : 'partial')),
                'mppsFeedPresent' => $mppsSources->isNotEmpty(),
                'scannerCount' => $rows->count(),
                'coveredScannerCount' => $rows->where('coverage.status', 'complete')->count(),
                'candidateExamCount' => $rows->sum('coverage.candidateExamCount'),
                'coveredExamCount' => $rows->sum('coverage.coveredExamCount'),
                'percent' => $summary['dataCoveragePercent'],
                'warning' => $state === 'degraded' ? $stateMessage : null,
            ],
            'summary' => $summary,
            'definitions' => [
                'available' => 'Declared staffed operating minutes inside the selected date and time window. No 24-hour default is inferred.',
                'exam' => 'Union of scanner-linked performed intervals backed by authoritative MPPS start and end evidence. Downtime overlap is excluded.',
                'downtime' => 'Union of scanner downtime intervals clipped to staffed hours. Unplanned downtime takes precedence over planned downtime and exam activity.',
                'idle' => 'Staffed operating minutes not assigned to downtime or a covered exam interval. Idle is withheld when MPPS coverage is incomplete.',
                'utilization' => 'Covered exam minutes divided by declared staffed operating minutes. Missing staffed hours or MPPS evidence yields no percentage.',
                'referenceLine' => 'The reference line is the covered scanner portfolio average for this filter, not an external benchmark or target.',
            ],
            'referenceLines' => $summary['utilizationPercent'] === null ? [] : [[
                'key' => 'portfolio_average',
                'label' => 'Portfolio average',
                'value' => $summary['utilizationPercent'],
                'definition' => 'Derived from all fully covered scanner intervals in the selected window.',
            ]],
            'scanners' => $rows->all(),
        ];
    }

    /** @param array<string, mixed> $input @return array{date:string,startTime:string,endTime:string,modality:?string} */
    private function filters(array $input): array
    {
        return [
            'date' => is_string($input['date'] ?? null) ? $input['date'] : now()->toDateString(),
            'startTime' => is_string($input['startTime'] ?? null) ? $input['startTime'] : '00:00',
            'endTime' => is_string($input['endTime'] ?? null) ? $input['endTime'] : '23:59',
            'modality' => is_string($input['modality'] ?? null) && $input['modality'] !== '' ? $input['modality'] : null,
        ];
    }

    /**
     * @param  array{date:string,startTime:string,endTime:string,modality:?string}  $filters
     * @return list<array{start:CarbonImmutable,end:CarbonImmutable}>
     */
    private function operatingWindows(Scanner $scanner, array $filters): array
    {
        $contract = $scanner->metadata['staffed_operating_hours'] ?? null;

        return $this->operatingWindows->resolve(
            is_array($contract) ? $contract : null,
            $filters['date'],
            $filters['date'],
            $filters['startTime'],
            $filters['endTime'],
        );
    }

    /** @param Collection<int, list<array{start:CarbonImmutable,end:CarbonImmutable}>> $windows */
    private function queryWindow(Collection $windows): ?array
    {
        $flat = $windows->flatten(1);
        if ($flat->isEmpty()) {
            return null;
        }

        return [
            'start' => CarbonImmutable::createFromTimestampUTC($flat->min(fn (array $window): int => $window['start']->getTimestamp())),
            'end' => CarbonImmutable::createFromTimestampUTC($flat->max(fn (array $window): int => $window['end']->getTimestamp())),
        ];
    }

    /** @param Collection<int, Exam> $exams @param list<int> $sourceIds @return array<int, array<string, true>> */
    private function mppsEvidence(Collection $exams, array $sourceIds): array
    {
        if ($exams->isEmpty() || $sourceIds === []) {
            return [];
        }
        $orderIds = $exams->pluck('ancillary_order_id')->map(fn (mixed $id): int => (int) $id)->all();
        $evidence = [];
        foreach (DB::table('prod.ancillary_milestones')->whereIn('ancillary_order_id', $orderIds)
            ->whereIn('source_id', $sourceIds)->whereIn('milestone_code', ['RAD_EXAM_START', 'RAD_EXAM_END'])
            ->get(['ancillary_order_id', 'milestone_code']) as $row) {
            $evidence[(int) $row->ancillary_order_id][(string) $row->milestone_code] = true;
        }

        return $evidence;
    }

    /**
     * @param  list<array{start:CarbonImmutable,end:CarbonImmutable}>  $available
     * @param  Collection<int, Exam>  $exams
     * @param  array<int, array<string, true>>  $evidence
     * @param  list<int>  $observedMppsSourceIds
     * @param  list<string>  $observedMppsSourceKeys
     * @return array<string, mixed>
     */
    private function scannerRow(
        Scanner $scanner,
        array $available,
        Collection $exams,
        array $evidence,
        array $observedMppsSourceIds,
        array $observedMppsSourceKeys,
    ): array {
        $examIntervals = [];
        $candidateCount = 0;
        $coveredCount = 0;
        $mix = ['ed' => 0, 'inpatient' => 0, 'outpatient' => 0, 'other' => 0];
        foreach ($exams as $exam) {
            $end = $exam->completed_at ?? CarbonImmutable::now();
            if ($exam->started_at === null || ! $end->greaterThan($exam->started_at)
                || ! $this->overlapsAvailable($exam->started_at, $end, $available)) {
                continue;
            }
            $candidateCount++;
            $class = match ($exam->ancillaryOrder?->patient_class) {
                'emergency' => 'ed', 'inpatient' => 'inpatient', 'outpatient' => 'outpatient', default => 'other',
            };
            $mix[$class]++;
            $codes = $evidence[(int) $exam->ancillary_order_id] ?? [];
            $covered = isset($codes['RAD_EXAM_START']) && ($exam->completed_at === null || isset($codes['RAD_EXAM_END']));
            if ($covered) {
                $coveredCount++;
                $examIntervals[] = ['start' => $exam->started_at, 'end' => $end];
            }
        }

        $planned = [];
        $unplanned = [];
        foreach ($scanner->downtimes as $downtime) {
            $end = $downtime->ends_at ?? CarbonImmutable::now();
            if (! $end->greaterThan($downtime->starts_at)) {
                continue;
            }
            $target = $this->suiteMetrics->isPlannedDowntime(
                (string) $downtime->status,
                (string) $downtime->reason_code,
                $downtime->metadata,
            ) ? 'planned' : 'unplanned';
            ${$target}[] = ['start' => $downtime->starts_at, 'end' => $end];
        }

        $calculation = $this->intervals->calculate($available, $examIntervals, $planned, $unplanned);
        $mappedMppsSourceKey = $scanner->metadata['mpps_source_key'] ?? null;
        $feedObserved = in_array((int) $scanner->source_id, $observedMppsSourceIds, true)
            || (is_string($mappedMppsSourceKey) && in_array($mappedMppsSourceKey, $observedMppsSourceKeys, true))
            || $coveredCount > 0;
        $coverageStatus = match (true) {
            $available === [] => 'missing_schedule',
            ! $feedObserved => 'missing_feed',
            $candidateCount !== $coveredCount => 'partial',
            default => 'complete',
        };
        $complete = $coverageStatus === 'complete';
        $coveragePercent = $candidateCount === 0 ? ($feedObserved ? 100 : 0) : $this->number(100 * $coveredCount / $candidateCount, 1);
        $utilization = $complete
            ? $this->suiteMetrics->utilizationPercent($calculation['examMinutes'], $calculation['availableMinutes'])
            : null;
        $timezone = (string) ($scanner->metadata['staffed_operating_hours']['timezone'] ?? config('app.timezone', 'UTC'));

        return [
            'scannerUuid' => (string) $scanner->scanner_uuid,
            'label' => (string) $scanner->label,
            'modality' => (string) $scanner->modality_code,
            'capacity' => (int) $scanner->capacity,
            'timezone' => $timezone,
            'availableWindows' => array_map(fn (array $window): array => [
                'startAt' => $window['start']->setTimezone($timezone)->toAtomString(),
                'endAt' => $window['end']->setTimezone($timezone)->toAtomString(),
            ], $available),
            'availableMinutes' => $calculation['availableMinutes'],
            'examMinutes' => $complete ? $calculation['examMinutes'] : null,
            'plannedDowntimeMinutes' => $calculation['plannedDowntimeMinutes'],
            'unplannedDowntimeMinutes' => $calculation['unplannedDowntimeMinutes'],
            'idleMinutes' => $complete ? $calculation['idleMinutes'] : null,
            'utilizationPercent' => $utilization,
            'reconciliationDeltaMinutes' => $complete ? $calculation['reconciliationDeltaMinutes'] : null,
            'coverage' => [
                'status' => $coverageStatus,
                'percent' => $coveragePercent,
                'candidateExamCount' => $candidateCount,
                'coveredExamCount' => $coveredCount,
                'warning' => match ($coverageStatus) {
                    'missing_schedule' => 'No staffed operating-hours contract is declared for this scanner.',
                    'missing_feed' => 'No observed governed MPPS feed is mapped to this scanner; machine utilization is unavailable.',
                    'partial' => 'One or more performed intervals lack authoritative MPPS start/end evidence.',
                    default => null,
                },
            ],
            'patientMix' => [...$mix, 'total' => array_sum($mix)],
            'segments' => array_map(function (array $segment) use ($complete, $timezone): array {
                $type = ! $complete && $segment['type'] === 'idle' ? 'unknown' : $segment['type'];

                return [
                    'startAt' => $segment['start']->setTimezone($timezone)->toAtomString(),
                    'endAt' => $segment['end']->setTimezone($timezone)->toAtomString(),
                    'type' => $type,
                    'minutes' => $segment['minutes'],
                    'label' => match ($type) {
                        'exam' => 'Covered exam activity',
                        'planned_downtime' => 'Planned downtime',
                        'unplanned_downtime' => 'Unplanned downtime',
                        'idle' => 'Idle',
                        default => 'Unknown activity coverage',
                    },
                ];
            }, $calculation['segments']),
        ];
    }

    /** @param list<array{start:CarbonImmutable,end:CarbonImmutable}> $available */
    private function overlapsAvailable(CarbonImmutable $start, CarbonImmutable $end, array $available): bool
    {
        foreach ($available as $window) {
            if ($start->lessThan($window['end']) && $end->greaterThan($window['start'])) {
                return true;
            }
        }

        return false;
    }

    /** @param Collection<int, array<string, mixed>> $rows @return array<string, mixed> */
    private function summary(Collection $rows, bool $feedPresent): array
    {
        $complete = $rows->isNotEmpty() && $rows->every(fn (array $row): bool => $row['coverage']['status'] === 'complete');
        $available = $this->number((float) $rows->sum('availableMinutes'));
        $exam = $complete ? $this->number((float) $rows->sum('examMinutes')) : null;
        $planned = $this->number((float) $rows->sum('plannedDowntimeMinutes'));
        $unplanned = $this->number((float) $rows->sum('unplannedDowntimeMinutes'));
        $idle = $complete ? $this->number((float) $rows->sum('idleMinutes')) : null;
        $candidate = (int) $rows->sum('coverage.candidateExamCount');
        $covered = (int) $rows->sum('coverage.coveredExamCount');
        $mix = [
            'ed' => (int) $rows->sum('patientMix.ed'),
            'inpatient' => (int) $rows->sum('patientMix.inpatient'),
            'outpatient' => (int) $rows->sum('patientMix.outpatient'),
            'other' => (int) $rows->sum('patientMix.other'),
        ];

        return [
            'scannerCount' => $rows->count(),
            'availableMinutes' => $available,
            'examMinutes' => $exam,
            'plannedDowntimeMinutes' => $planned,
            'unplannedDowntimeMinutes' => $unplanned,
            'idleMinutes' => $idle,
            'utilizationPercent' => $complete ? $this->suiteMetrics->utilizationPercent($exam, $available) : null,
            'dataCoveragePercent' => $candidate === 0
                ? ($rows->isEmpty() ? ($feedPresent ? 100 : 0) : $this->number(100 * $rows->where('coverage.status', 'complete')->count() / $rows->count(), 1))
                : $this->number(100 * $covered / $candidate, 1),
            'patientMix' => [...$mix, 'total' => array_sum($mix)],
            'reconciliationDeltaMinutes' => $complete ? $this->number($available - $exam - $planned - $unplanned - $idle) : null,
        ];
    }

    private function number(float|int $value, int $precision = 2): float|int
    {
        $rounded = round($value, $precision);

        return floor($rounded) === $rounded ? (int) $rounded : $rounded;
    }

    private function latestInstant(mixed ...$values): ?CarbonImmutable
    {
        return collect($values)->filter()->map(fn (mixed $value): CarbonImmutable => CarbonImmutable::parse($value))
            ->sortByDesc(fn (CarbonImmutable $value): int => $value->getTimestamp())->first();
    }
}
