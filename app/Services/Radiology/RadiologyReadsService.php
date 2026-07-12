<?php

namespace App\Services\Radiology;

use App\Data\Ancillary\FreshnessEnvelope;
use App\Models\Ancillary\AncillarySlaDefinition;
use App\Models\Integration\Source;
use App\Services\Ancillary\AncillaryContractSerializer;
use App\Services\Ancillary\AncillaryStatistics;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class RadiologyReadsService
{
    public const STATES = ['unread', 'no_report', 'preliminary', 'final', 'corrected'];

    public const WINDOW_HOURS = [6, 12, 24, 48];

    public function __construct(
        private readonly AncillaryContractSerializer $contracts,
        private readonly AncillaryStatistics $statistics,
    ) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function build(array $input = [], bool $canViewPatientDetail = true): array
    {
        $filters = $this->filters($input);
        $rows = $this->cohort($filters);
        $source = $this->sourceContext();
        $freshness = $this->freshness($source);
        $unread = $this->unreadSummary($rows);
        $critical = $this->criticalLoops($filters);
        $backlog = $this->backlog($rows, $filters['windowHours']);
        $aging = $this->preliminaryAging($rows);
        $health = $this->health($unread, $critical, $source);
        $state = $this->pageState($rows, $source, $backlog);

        return [
            'generatedAt' => now()->toAtomString(),
            'sourceCutoffAt' => $source['sourceCutoffAt']?->toAtomString(),
            'state' => $state,
            'stateMessage' => $this->stateMessage($state),
            'freshness' => $freshness,
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'health' => $health,
            'unread' => $unread,
            'reportStates' => $this->reportStateCounts($rows),
            'backlog' => $backlog,
            'preliminaryToFinal' => $aging,
            'criticalLoops' => $critical,
            'items' => $this->items($rows, $filters, $source['state'], $canViewPatientDetail),
            'privacy' => [
                'clinicalReportTextIncluded' => false,
                'patientContextIncluded' => $canViewPatientDetail,
                'identifierPolicy' => $canViewPatientDetail
                    ? 'Only source-scoped pseudonymous operational identifiers and UUIDs are returned.'
                    : 'Patient context is redacted because the current role lacks the ancillary patient-detail capability.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function cockpitHealth(): array
    {
        $filters = $this->filters([]);
        $rows = $this->cohort($filters);
        $source = $this->sourceContext();

        return $this->health($this->unreadSummary($rows), $this->criticalLoops($filters), $source);
    }

    /** @param array<string, mixed> $input @return array{state:string,priority:?string,subspecialty:?string,modality:?string,windowHours:int,limit:int} */
    private function filters(array $input): array
    {
        return [
            'state' => is_string($input['state'] ?? null) && in_array($input['state'], self::STATES, true) ? $input['state'] : 'unread',
            'priority' => is_string($input['priority'] ?? null) && in_array($input['priority'], ['stat', 'urgent', 'routine', 'discharge'], true) ? $input['priority'] : null,
            'subspecialty' => is_string($input['subspecialty'] ?? null) && $input['subspecialty'] !== '' ? $input['subspecialty'] : null,
            'modality' => is_string($input['modality'] ?? null) && $input['modality'] !== '' ? $input['modality'] : null,
            'windowHours' => in_array((int) ($input['windowHours'] ?? 12), self::WINDOW_HOURS, true) ? (int) ($input['windowHours'] ?? 12) : 12,
            'limit' => min(50, max(1, (int) ($input['limit'] ?? 25))),
        ];
    }

    /** @param array<string, mixed> $filters @return Collection<int, object> */
    private function cohort(array $filters): Collection
    {
        $latestIds = DB::table('prod.rad_reads')->where('status', '!=', 'cancelled')
            ->selectRaw('rad_exam_id, max(rad_read_id) AS latest_read_id')->groupBy('rad_exam_id');
        $readTimes = DB::table('prod.rad_reads')->where('status', '!=', 'cancelled')
            ->selectRaw("rad_exam_id,
                min(preliminary_at) FILTER (WHERE preliminary_at IS NOT NULL) AS first_preliminary_at,
                min(final_at) FILTER (WHERE final_at IS NOT NULL) AS first_final_at,
                max(corrected_at) FILTER (WHERE corrected_at IS NOT NULL) AS latest_corrected_at,
                count(*) FILTER (WHERE status IN ('corrected', 'addendum')) AS correction_count")
            ->groupBy('rad_exam_id');
        $windowStart = now()->subHours($filters['windowHours']);

        return DB::table('prod.rad_exams as x')
            ->join('prod.ancillary_orders as o', 'o.ancillary_order_id', '=', 'x.ancillary_order_id')
            ->leftJoinSub($latestIds, 'latest_ids', 'latest_ids.rad_exam_id', '=', 'x.rad_exam_id')
            ->leftJoin('prod.rad_reads as latest', 'latest.rad_read_id', '=', 'latest_ids.latest_read_id')
            ->leftJoinSub($readTimes, 'read_times', 'read_times.rad_exam_id', '=', 'x.rad_exam_id')
            ->leftJoin('hosp_ref.rad_subspecialties as ss', function ($join): void {
                $join->on('ss.code', '=', DB::raw('COALESCE(latest.subspecialty_code, x.subspecialty_code)'));
            })
            ->leftJoin('prod.ancillary_current_assertions as images', function ($join): void {
                $join->on('images.ancillary_order_id', '=', 'o.ancillary_order_id')->where('images.milestone_code', 'RAD_IMAGES_AVAILABLE');
            })
            ->where('o.department', 'rad')
            ->whereNotIn('x.status', ['cancelled', 'discontinued'])
            ->where(fn (Builder $query) => $query->whereNotNull('x.completed_at')->orWhereNotNull('latest_ids.latest_read_id'))
            ->where(function (Builder $query) use ($windowStart): void {
                $query->where('x.completed_at', '>=', $windowStart)
                    ->orWhereNull('read_times.first_final_at')
                    ->orWhere('read_times.first_final_at', '>=', $windowStart);
            })
            ->when($filters['priority'] !== null, fn (Builder $query) => $query->where('o.priority', $filters['priority']))
            ->when($filters['modality'] !== null, fn (Builder $query) => $query->where('x.modality_code', $filters['modality']))
            ->when($filters['subspecialty'] !== null, fn (Builder $query) => $query->whereRaw('COALESCE(latest.subspecialty_code, x.subspecialty_code) = ?', [$filters['subspecialty']]))
            ->select([
                'x.rad_exam_id', 'x.exam_uuid', 'x.status as exam_status', 'x.completed_at', 'x.modality_code', 'x.procedure_label',
                'o.ancillary_order_id', 'o.order_uuid', 'o.patient_ref', 'o.patient_class', 'o.priority', 'o.ordered_at', 'o.source_cutoff_at',
                'latest.rad_read_id as latest_read_id', 'latest.read_uuid as latest_read_uuid', 'latest.status as latest_read_status',
                'latest.source_report_version', 'latest.subspecialty_code as read_subspecialty_code', 'latest.is_teleradiology',
                'latest.preliminary_at as latest_preliminary_at', 'latest.final_at as latest_final_at', 'latest.corrected_at as latest_corrected_at',
                'read_times.first_preliminary_at', 'read_times.first_final_at', 'read_times.latest_corrected_at as lineage_corrected_at',
                'read_times.correction_count', 'images.occurred_at as images_available_at',
                'ss.code as subspecialty_code', 'ss.label as subspecialty_label',
            ])
            ->selectRaw("EXISTS (SELECT 1 FROM prod.ancillary_breaches b WHERE b.ancillary_order_id = o.ancillary_order_id AND b.status = 'open') AS breached")
            ->orderBy('x.rad_exam_id')
            ->get();
    }

    /** @return array<string, mixed> */
    private function sourceContext(): array
    {
        $evidence = DB::table('prod.ancillary_milestones')
            ->whereIn('milestone_code', ['RAD_PRELIM', 'RAD_FINAL']);
        $sourceCutoff = (clone $evidence)->max('received_at');
        $evidenceSourceIds = (clone $evidence)->distinct()->pluck('source_id')->map(fn (mixed $id): int => (int) $id)->all();
        $candidates = Source::query()->whereIn('system_class', ['radiology_reporting', 'reporting', 'ris', 'pacs'])
            ->orWhereIn('source_id', $evidenceSourceIds)->get();
        $configured = $candidates->filter(function (Source $source) use ($evidenceSourceIds): bool {
            $ingest = is_array($source->metadata['ancillary_ingest'] ?? null) ? $source->metadata['ancillary_ingest'] : [];
            $families = is_array($ingest['message_families'] ?? null) ? array_map('strtoupper', $ingest['message_families']) : [];
            $departments = is_array($ingest['departments'] ?? null) ? array_map('strtolower', $ingest['departments']) : [];

            return in_array((int) $source->source_id, $evidenceSourceIds, true)
                || in_array($source->system_class, ['radiology_reporting', 'reporting'], true)
                || (in_array('ORU', $families, true) && ($departments === [] || in_array('rad', $departments, true)));
        });
        $registered = DB::table('ops.source_freshness')->where('source_key', 'ancillary_milestones')->first();
        $registeredStatus = strtolower((string) ($registered->status ?? ''));
        $protocolFailures = $configured->filter(fn (Source $source): bool => $source->protocol_health_checked_at !== null && $source->protocol_health_status === 'failed');
        $sourceError = in_array($registeredStatus, ['error', 'failed', 'unavailable'], true)
            || ($configured->isNotEmpty() && $protocolFailures->count() === $configured->count());
        $cutoff = $sourceCutoff === null ? null : CarbonImmutable::parse($sourceCutoff);
        $lag = $cutoff === null ? null : max(0, (int) floor($cutoff->diffInSeconds(now(), false) / 60));
        $warning = max(1, (int) ($registered->warning_lag_minutes ?? 60));
        $state = match (true) {
            $sourceError => 'error',
            $cutoff === null => 'missing',
            $lag > $warning || $registeredStatus === 'stale' => 'stale',
            default => 'fresh',
        };

        return [
            'state' => $state,
            'feedConfigured' => $configured->isNotEmpty(),
            'sourceCutoffAt' => $cutoff,
            'lagMinutes' => $lag,
            'warningLagMinutes' => $warning,
            'sourceLabel' => (string) ($registered->source_label ?? 'Radiology reporting feeds'),
        ];
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function freshness(array $source): array
    {
        $status = match ($source['state']) {
            'fresh' => 'fresh', 'stale', 'error' => 'stale', default => 'unknown',
        };
        $explanation = match ($source['state']) {
            'error' => 'The governed Radiology reporting source reports an error.',
            'stale' => 'The latest preliminary/final report evidence exceeds its freshness tolerance.',
            'missing' => 'No observed governed Radiology reporting feed is available.',
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

    /** @param Collection<int, object> $rows @return array<string, mixed> */
    private function unreadSummary(Collection $rows): array
    {
        $unread = $rows->filter(fn (object $row): bool => $row->completed_at !== null && in_array($this->reportState($row), ['no_report', 'preliminary'], true));

        return [
            'total' => $unread->count(),
            'oldestAgeMinutes' => $unread->isEmpty() ? null : $unread->max(fn (object $row): int => $this->queueAge($row)),
            'byPriority' => $unread->groupBy('priority')->map(function (Collection $group, string $priority): array {
                return ['priority' => $priority, 'count' => $group->count(), 'oldestAgeMinutes' => $group->max(fn (object $row): int => $this->queueAge($row))];
            })->sortBy(fn (array $row): int => match ($row['priority']) {
                'stat' => 0, 'urgent' => 1, 'discharge' => 2, 'routine' => 3, default => 99,
            })->values()->all(),
            'bySubspecialty' => $unread->groupBy(fn (object $row): string => (string) ($row->subspecialty_code ?? 'unassigned'))
                ->map(function (Collection $group, string $code): array {
                    $first = $group->first();

                    return ['code' => $code, 'label' => $first->subspecialty_label ?? 'Unassigned', 'count' => $group->count(), 'oldestAgeMinutes' => $group->max(fn (object $row): int => $this->queueAge($row))];
                })->sortByDesc('count')->values()->all(),
        ];
    }

    /** @param Collection<int, object> $rows @return list<array{state:string,count:int}> */
    private function reportStateCounts(Collection $rows): array
    {
        return collect(['no_report', 'preliminary', 'final', 'corrected'])->map(fn (string $state): array => [
            'state' => $state,
            'count' => $rows->filter(fn (object $row): bool => $this->reportState($row) === $state)->count(),
        ])->all();
    }

    /** @param Collection<int, object> $rows @return array<string, mixed> */
    private function backlog(Collection $rows, int $windowHours): array
    {
        $windowEnd = CarbonImmutable::now()->startOfHour();
        $windowStart = $windowEnd->subHours($windowHours);
        $points = [];
        for ($index = 0; $index < $windowHours; $index++) {
            $start = $windowStart->addHours($index);
            $end = $start->addHour();
            $open = $rows->filter(function (object $row) use ($end): bool {
                if ($row->completed_at === null || CarbonImmutable::parse($row->completed_at)->greaterThan($end)) {
                    return false;
                }
                $final = $row->first_final_at === null ? null : CarbonImmutable::parse($row->first_final_at);

                return $final === null || $final->greaterThan($end);
            })->count();
            $entered = $rows->filter(fn (object $row): bool => $this->within($row->completed_at, $start, $end))->count();
            $finalized = $rows->filter(fn (object $row): bool => $this->within($row->first_final_at, $start, $end))->count();
            $points[] = [
                'bucketStart' => $start->toAtomString(), 'bucketEnd' => $end->toAtomString(),
                'openAtEnd' => $open, 'entered' => $entered, 'finalized' => $finalized, 'netChange' => $entered - $finalized,
            ];
        }

        return [
            'bucketMinutes' => 60,
            'windowStart' => $windowStart->toAtomString(),
            'windowEnd' => $windowEnd->toAtomString(),
            'comparable' => true,
            'points' => $points,
            'missing' => [
                'completionTimestampCount' => $rows->whereNull('completed_at')->count(),
                'finalTimestampCount' => $rows->filter(fn (object $row): bool => in_array($this->reportState($row), ['final', 'corrected'], true) && $row->first_final_at === null)->count(),
            ],
            'definition' => 'Full 60-minute buckets ending at the current hour. Open-at-end uses acquisition completion and the first final report; the current partial hour is excluded.',
        ];
    }

    /** @param Collection<int, object> $rows @return array<string, mixed> */
    private function preliminaryAging(Collection $rows): array
    {
        $values = [];
        $negative = 0;
        foreach ($rows as $row) {
            if ($row->first_preliminary_at === null || $row->first_final_at === null) {
                continue;
            }
            $minutes = CarbonImmutable::parse($row->first_preliminary_at)->diffInSeconds(CarbonImmutable::parse($row->first_final_at), false) / 60;
            if ($minutes < 0) {
                $negative++;

                continue;
            }
            $values[] = $minutes;
        }
        $distribution = $this->statistics->distribution($values);

        return [
            'count' => $distribution['count'],
            'medianMinutes' => $distribution['median'],
            'p90Minutes' => $distribution['p90'],
            'maxMinutes' => $values === [] ? null : $this->number(max($values)),
            'missingPreliminaryCount' => $rows->filter(fn (object $row): bool => $row->first_final_at !== null && $row->first_preliminary_at === null)->count(),
            'excludedNegativeCount' => $negative,
            'definition' => 'First preliminary timestamp to first final timestamp per exam. Corrections and addenda do not move the original final clock.',
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function criticalLoops(array $filters): array
    {
        $windowStart = now()->subHours($filters['windowHours']);
        $query = DB::table('prod.rad_critical_results as c')
            ->join('prod.rad_exams as x', 'x.rad_exam_id', '=', 'c.rad_exam_id')
            ->join('prod.ancillary_orders as o', 'o.ancillary_order_id', '=', 'x.ancillary_order_id')
            ->leftJoin('prod.rad_reads as r', 'r.rad_read_id', '=', 'c.rad_read_id')
            ->where(fn (Builder $scope) => $scope->where('c.identified_at', '>=', $windowStart)->orWhereNotIn('c.policy_state', ['acknowledged', 'closed']))
            ->when($filters['priority'] !== null, fn (Builder $scope) => $scope->where('o.priority', $filters['priority']))
            ->when($filters['modality'] !== null, fn (Builder $scope) => $scope->where('x.modality_code', $filters['modality']))
            ->when($filters['subspecialty'] !== null, fn (Builder $scope) => $scope->whereRaw('COALESCE(r.subspecialty_code, x.subspecialty_code) = ?', [$filters['subspecialty']]))
            ->get([
                'c.critical_result_uuid', 'c.finding_class', 'c.policy_state', 'c.identified_at', 'c.notified_at', 'c.acknowledged_at',
                'c.escalated_at', 'c.closed_at', 'c.recipient_role', 'x.exam_uuid', 'o.order_uuid', 'o.priority', 'x.modality_code',
            ]);
        $open = $query->whereNotIn('policy_state', ['acknowledged', 'closed']);
        $notifyMinutes = $query->filter(fn (object $row): bool => $row->notified_at !== null)
            ->map(fn (object $row): float => CarbonImmutable::parse($row->identified_at)->diffInSeconds(CarbonImmutable::parse($row->notified_at), false) / 60)
            ->filter(fn (float $value): bool => $value >= 0)->values()->all();
        $ackMinutes = $query->filter(fn (object $row): bool => $row->notified_at !== null && $row->acknowledged_at !== null)
            ->map(fn (object $row): float => CarbonImmutable::parse($row->notified_at)->diffInSeconds(CarbonImmutable::parse($row->acknowledged_at), false) / 60)
            ->filter(fn (float $value): bool => $value >= 0)->values()->all();

        return [
            'summary' => [
                'total' => $query->count(),
                'open' => $open->count(),
                'oldestOpenAgeMinutes' => $open->isEmpty() ? null : $open->max(fn (object $row): int => max(0, (int) floor(CarbonImmutable::parse($row->identified_at)->diffInSeconds(now(), false) / 60))),
                'byState' => collect(['pending_notification', 'notified', 'acknowledged', 'escalated', 'closed'])->map(fn (string $state): array => ['state' => $state, 'count' => $query->where('policy_state', $state)->count()])->all(),
            ],
            'timings' => [
                'identifiedToNotified' => $this->distribution($notifyMinutes),
                'notifiedToAcknowledged' => $this->distribution($ackMinutes),
            ],
            'openItems' => $open->sortBy('identified_at')->take(10)->map(fn (object $row): array => [
                'criticalResultUuid' => (string) $row->critical_result_uuid,
                'examUuid' => (string) $row->exam_uuid,
                'findingClass' => (string) $row->finding_class,
                'state' => (string) $row->policy_state,
                'priority' => (string) $row->priority,
                'modality' => $row->modality_code,
                'identifiedAt' => CarbonImmutable::parse($row->identified_at)->toAtomString(),
                'ageMinutes' => max(0, (int) floor(CarbonImmutable::parse($row->identified_at)->diffInSeconds(now(), false) / 60)),
                'recipientRole' => $row->recipient_role,
                'drillHref' => '/radiology/worklist?search='.$row->order_uuid,
            ])->values()->all(),
        ];
    }

    /** @param array<string, mixed> $unread @param array<string, mixed> $critical @param array<string, mixed> $source @return array<string, mixed> */
    private function health(array $unread, array $critical, array $source): array
    {
        return [
            'unreadCount' => $unread['total'],
            'oldestUnreadAgeMinutes' => $unread['oldestAgeMinutes'],
            'unreadByPriority' => $unread['byPriority'],
            'openCriticalLoopCount' => $critical['summary']['open'],
            'oldestCriticalLoopAgeMinutes' => $critical['summary']['oldestOpenAgeMinutes'],
            'sourceState' => $source['state'],
            'sourceCutoffAt' => $source['sourceCutoffAt']?->toAtomString(),
        ];
    }

    /** @param Collection<int, object> $rows @param array<string, mixed> $backlog */
    private function pageState(Collection $rows, array $source, array $backlog): string
    {
        return match (true) {
            $source['state'] === 'error' => 'source_error',
            $source['state'] === 'missing' => 'missing_feed',
            $source['state'] === 'stale' => 'stale',
            $rows->isEmpty() => 'no_data',
            $backlog['missing']['completionTimestampCount'] > 0 || $backlog['missing']['finalTimestampCount'] > 0 => 'degraded',
            default => 'normal',
        };
    }

    private function stateMessage(string $state): string
    {
        return match ($state) {
            'source_error' => 'Radiology reporting source health reports an error; last-known operational facts remain visible.',
            'missing_feed' => 'No observed governed Radiology reporting feed is available; unread health cannot be called current.',
            'stale' => 'Radiology reporting facts are stale and remain anchored to the displayed source cutoff.',
            'no_data' => 'No completed or recently reported Radiology exams match the selected filters.',
            'degraded' => 'Some report-bearing exams lack timestamps required for comparable backlog calculations.',
            default => 'Radiology read and result facts are current.',
        };
    }

    /** @param Collection<int, object> $rows @param array<string, mixed> $filters @return list<array<string, mixed>> */
    private function items(Collection $rows, array $filters, string $sourceState, bool $canViewPatientDetail): array
    {
        $definitions = AncillarySlaDefinition::query()->activeAt(now())->where('department', 'rad')
            ->where('stop_milestone_code', 'RAD_FINAL')->where('statistic', 'item_clock')->get();
        $items = $rows->filter(function (object $row) use ($filters): bool {
            $state = $this->reportState($row);

            return $filters['state'] === 'unread' ? in_array($state, ['no_report', 'preliminary'], true) : $state === $filters['state'];
        })->map(function (object $row) use ($definitions, $sourceState, $canViewPatientDetail): array {
            $reportState = $this->reportState($row);
            $definition = $this->definitionFor($definitions, $row);
            $clockStart = match ($definition?->start_milestone_code) {
                'RAD_ORDERED' => $row->ordered_at,
                'RAD_IMAGES_AVAILABLE' => $row->images_available_at,
                default => $row->images_available_at ?? $row->completed_at,
            };
            $elapsed = $clockStart === null ? null : max(0, (int) floor(CarbonImmutable::parse($clockStart)->diffInSeconds(now(), false) / 60));
            $urgency = match (true) {
                (bool) $row->breached => 'breach',
                in_array($reportState, ['final', 'corrected'], true) => $sourceState === 'fresh' ? 'normal' : 'stale',
                $sourceState !== 'fresh' => 'stale',
                $definition === null => 'unconfigured',
                $elapsed === null => 'degraded',
                $definition->breach_minutes !== null && $elapsed >= $definition->breach_minutes => 'breach',
                $definition->warning_minutes !== null && $elapsed >= $definition->warning_minutes => 'warning',
                default => 'normal',
            };

            return [
                'examUuid' => (string) $row->exam_uuid,
                'orderUuid' => (string) $row->order_uuid,
                'patientRef' => $canViewPatientDetail
                    ? ($row->patient_ref ?: 'Pseudonymous patient unavailable')
                    : 'Patient context restricted',
                'label' => $row->procedure_label ?: (($row->modality_code ?: 'Unknown modality').' imaging'),
                'priority' => (string) $row->priority,
                'patientClass' => (string) $row->patient_class,
                'modality' => $row->modality_code,
                'subspecialtyCode' => $row->subspecialty_code,
                'subspecialtyLabel' => $row->subspecialty_label,
                'reportState' => $reportState,
                'urgency' => $urgency,
                'ageMinutes' => $this->queueAge($row),
                'completedAt' => $row->completed_at === null ? null : CarbonImmutable::parse($row->completed_at)->toAtomString(),
                'firstPreliminaryAt' => $row->first_preliminary_at === null ? null : CarbonImmutable::parse($row->first_preliminary_at)->toAtomString(),
                'firstFinalAt' => $row->first_final_at === null ? null : CarbonImmutable::parse($row->first_final_at)->toAtomString(),
                'latestCorrectedAt' => $row->lineage_corrected_at === null ? null : CarbonImmutable::parse($row->lineage_corrected_at)->toAtomString(),
                'latestReadUuid' => $row->latest_read_uuid,
                'sourceReportVersion' => $row->source_report_version,
                'correctionCount' => (int) ($row->correction_count ?? 0),
                'isTeleradiology' => (bool) ($row->is_teleradiology ?? false),
                'definition' => $definition === null ? null : [
                    'definitionUuid' => (string) $definition->definition_uuid,
                    'label' => (string) $definition->label,
                    'startMilestoneCode' => (string) $definition->start_milestone_code,
                    'stopMilestoneCode' => (string) $definition->stop_milestone_code,
                    'warningMinutes' => $definition->warning_minutes,
                    'breachMinutes' => $definition->breach_minutes,
                ],
                'drillHref' => '/radiology/worklist?search='.$row->order_uuid,
            ];
        })->sortBy(function (array $item): string {
            $rank = ['breach' => 0, 'warning' => 1, 'stale' => 2, 'degraded' => 3, 'unconfigured' => 4, 'normal' => 5][$item['urgency']];

            return sprintf('%d|%010d', $rank, 9999999999 - $item['ageMinutes']);
        })->take($filters['limit'])->values()->all();

        return $items;
    }

    /** @param Collection<int, AncillarySlaDefinition> $definitions */
    private function definitionFor(Collection $definitions, object $row): ?AncillarySlaDefinition
    {
        return $definitions->filter(function (AncillarySlaDefinition $definition) use ($row): bool {
            $scope = is_array($definition->scope) ? $definition->scope : [];

            return ($definition->priority === null || $definition->priority === $row->priority)
                && ($definition->patient_class === null || $definition->patient_class === $row->patient_class)
                && (! isset($scope['modality']) || $scope['modality'] === $row->modality_code);
        })->sortByDesc(function (AncillarySlaDefinition $definition): int {
            return ($definition->priority !== null ? 2 : 0) + ($definition->patient_class !== null ? 2 : 0) + ($definition->scope !== [] ? 1 : 0);
        })->first();
    }

    /** @return array<string, mixed> */
    private function filterOptions(): array
    {
        return [
            'states' => self::STATES,
            'priorities' => ['stat', 'urgent', 'routine', 'discharge'],
            'subspecialties' => DB::table('hosp_ref.rad_subspecialties')->where('is_active', true)->orderBy('label')->get(['code', 'label'])
                ->map(fn (object $row): array => ['code' => $row->code, 'label' => $row->label])->all(),
            'modalities' => DB::table('hosp_ref.rad_modalities')->where('is_active', true)->orderBy('label')->get(['code', 'label'])
                ->map(fn (object $row): array => ['code' => $row->code, 'label' => $row->label])->all(),
            'windowHours' => self::WINDOW_HOURS,
        ];
    }

    private function reportState(object $row): string
    {
        return match ($row->latest_read_status) {
            'preliminary' => 'preliminary',
            'final' => 'final',
            'corrected', 'addendum' => 'corrected',
            default => 'no_report',
        };
    }

    private function queueAge(object $row): int
    {
        $start = $row->images_available_at ?? $row->completed_at ?? $row->ordered_at;
        $stop = in_array($this->reportState($row), ['final', 'corrected'], true) && $row->first_final_at !== null ? $row->first_final_at : now();

        return max(0, (int) floor(CarbonImmutable::parse($start)->diffInSeconds(CarbonImmutable::parse($stop), false) / 60));
    }

    private function within(mixed $value, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        if ($value === null) {
            return false;
        }
        $at = CarbonImmutable::parse($value);

        return $at->greaterThan($start) && $at->lessThanOrEqualTo($end);
    }

    /** @param list<float|int> $values @return array{count:int,medianMinutes:?float,p90Minutes:?float} */
    private function distribution(array $values): array
    {
        $distribution = $this->statistics->distribution($values);

        return ['count' => $distribution['count'], 'medianMinutes' => $distribution['median'], 'p90Minutes' => $distribution['p90']];
    }

    private function number(float|int $value): float|int
    {
        $rounded = round($value, 2);

        return floor($rounded) === $rounded ? (int) $rounded : $rounded;
    }
}
