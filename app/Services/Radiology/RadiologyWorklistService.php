<?php

namespace App\Services\Radiology;

use App\Services\Ancillary\AncillaryReadinessService;
use App\Services\Radiology\BreachRisk\RadiologyBreachRiskService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RadiologyWorklistService
{
    public const SORTS = ['oldest', 'newest', 'priority', 'breach_risk'];

    public const DEEP_LINK_SOURCES = AncillaryReadinessService::DRILL_SOURCES;

    public function __construct(
        private readonly RadiologyFlowBoardService $flowBoard,
        private readonly AncillaryReadinessService $readiness,
        private readonly RadiologyBreachRiskService $breachRisk,
    ) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function build(array $filters = [], bool $canViewPatientDetail = true): array
    {
        $filters = $this->filters($filters);
        $context = $this->flowBoard->build($filters, false, $canViewPatientDetail);
        $query = $this->query($filters);
        $cursor = $this->cursor($filters['cursor']);
        $page = $query->cursorPaginate($filters['perPage'], ['*'], 'cursor', $cursor);
        $rows = collect($page->items());
        $riskScores = $this->riskScores($filters, $rows);

        return [
            'generatedAt' => now()->toAtomString(),
            'freshness' => $context['freshness'],
            'filters' => $filters,
            'filterOptions' => [
                ...$context['filterOptions'],
                'sorts' => self::SORTS,
                'deepLinkSources' => self::DEEP_LINK_SOURCES,
            ],
            'predictiveSort' => $this->predictiveSort($filters, $riskScores !== null),
            'data' => $this->serializeRows($rows, $context, $canViewPatientDetail, $riskScores),
            'privacy' => [
                'patientContextIncluded' => $canViewPatientDetail,
                'identifierPolicy' => $canViewPatientDetail
                    ? 'Minimum-necessary source-scoped pseudonymous patient context is included for an authorized operational role.'
                    : 'Patient context is redacted because the current role lacks the ancillary patient-detail capability.',
            ],
            'meta' => [
                'perPage' => $page->perPage(),
                'count' => $page->count(),
                'hasMore' => $page->hasMorePages(),
                'nextCursor' => $page->nextCursor()?->encode(),
                'previousCursor' => $page->previousCursor()?->encode(),
            ],
        ];
    }

    /**
     * Server-side breach-risk scoring for the visible page, only when the opt-in
     * `risk` flag is set and the model artifact is loadable. Returns null when
     * scoring is not requested/available so the row serializer omits the column.
     *
     * @param  array<string, mixed>  $filters
     * @param  Collection<int, object>  $rows
     * @return array<int, array<string, mixed>>|null
     */
    private function riskScores(array $filters, Collection $rows): ?array
    {
        if (! $filters['risk'] || $rows->isEmpty() || ! $this->breachRisk->isAvailable()) {
            return null;
        }
        $orderIds = $rows->pluck('ancillary_order_id')->map(fn (mixed $id): int => (int) $id)->all();

        return $this->breachRisk->scoreOrders($orderIds);
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function predictiveSort(array $filters, bool $scored): array
    {
        $provenance = $this->breachRisk->provenance();
        if ($provenance === null) {
            return [
                'available' => false,
                'enabled' => false,
                'requested' => (bool) $filters['risk'],
                'model' => null,
                'explanation' => 'The Radiology breach-risk planning model artifact is not installed.',
            ];
        }

        return [
            'available' => true,
            'enabled' => $scored,
            'requested' => (bool) $filters['risk'],
            'model' => $provenance,
            'explanation' => $scored
                ? 'Optional planning risk is a calibrated, synthetic-demo sort aid computed server-side from current operational load — it is not an alarm, a breach status, or a clinical recommendation. Missing or stale signals show as low-confidence or unavailable.'
                : 'Breach-risk scoring is available but off. Add risk=on to compute the optional synthetic planning score for the visible page.',
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function filters(array $filters): array
    {
        $lens = is_string($filters['lens'] ?? null) && in_array($filters['lens'], RadiologyFlowBoardService::LENSES, true) ? $filters['lens'] : 'all';
        $priority = is_string($filters['priority'] ?? null) && in_array($filters['priority'], ['stat', 'urgent', 'routine', 'discharge'], true) ? $filters['priority'] : null;
        $modality = is_string($filters['modality'] ?? null) && preg_match('/^[A-Z0-9_]{1,16}$/', $filters['modality']) ? $filters['modality'] : null;
        $unitId = filter_var($filters['unitId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $state = is_string($filters['state'] ?? null) && in_array($filters['state'], ['normal', 'warning', 'breach', 'degraded'], true) ? $filters['state'] : null;
        $sort = is_string($filters['sort'] ?? null) && in_array($filters['sort'], self::SORTS, true) ? $filters['sort'] : 'oldest';
        $search = is_string($filters['search'] ?? null) ? trim($filters['search']) : null;
        $source = is_string($filters['source'] ?? null) && in_array($filters['source'], self::DEEP_LINK_SOURCES, true) ? $filters['source'] : null;
        $risk = filter_var($filters['risk'] ?? false, FILTER_VALIDATE_BOOL);

        return [
            'lens' => $lens,
            'priority' => $priority,
            'modality' => $modality,
            'unitId' => $unitId === false ? null : $unitId,
            'state' => $state,
            'sort' => $sort,
            'search' => $search === '' ? null : $search,
            'source' => $source,
            'risk' => $risk,
            'perPage' => min(50, max(1, (int) ($filters['perPage'] ?? 25))),
            'cursor' => is_string($filters['cursor'] ?? null) && $filters['cursor'] !== '' ? $filters['cursor'] : null,
        ];
    }

    /** @param array<string, mixed> $filters */
    private function query(array $filters): Builder
    {
        $query = DB::table('prod.ancillary_orders as o')
            ->leftJoin('prod.rad_exams as x', 'x.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->leftJoin('prod.units as u', 'u.unit_id', '=', 'o.unit_id')
            ->where('o.department', 'rad')->whereNull('o.terminal_at')
            ->select([
                'o.ancillary_order_id', 'o.order_uuid', 'o.source_order_key', 'o.encounter_id', 'o.patient_ref',
                'o.patient_class', 'o.priority', 'o.ordered_at', 'o.current_state', 'o.current_milestone_code',
                'o.current_milestone_at', 'o.source_cutoff_at', 'o.metadata as order_metadata',
                'u.name as unit_name', 'x.exam_uuid', 'x.modality_code', 'x.procedure_label', 'x.is_portable',
                'x.is_ir', 'x.status as exam_status', 'x.metadata as exam_metadata',
            ])
            ->selectRaw("EXISTS (SELECT 1 FROM prod.ancillary_breaches b WHERE b.ancillary_order_id = o.ancillary_order_id AND b.status = 'open') AS breached")
            ->selectRaw('EXTRACT(EPOCH FROM (?::timestamptz - o.ordered_at)) / 60 AS age_minutes', [now()]);

        $this->applyFilters($query, $filters);

        $sortRank = match ($filters['sort']) {
            'priority' => "CASE o.priority WHEN 'stat' THEN 0 WHEN 'urgent' THEN 1 WHEN 'discharge' THEN 2 WHEN 'routine' THEN 3 ELSE 4 END",
            'breach_risk' => "CASE WHEN EXISTS (SELECT 1 FROM prod.ancillary_breaches b WHERE b.ancillary_order_id = o.ancillary_order_id AND b.status = 'open') THEN 0 ELSE 1 END",
            default => '0',
        };
        $query->selectRaw("({$sortRank}) AS sort_rank")->orderBy('sort_rank');
        if ($filters['sort'] === 'newest') {
            $query->orderByDesc('o.ordered_at')->orderByDesc('o.ancillary_order_id');
        } else {
            $query->orderBy('o.ordered_at')->orderBy('o.ancillary_order_id');
        }

        return $query;
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        match ($filters['lens']) {
            'ed' => $query->where('o.patient_class', 'emergency'),
            'inpatient' => $query->where('o.patient_class', 'inpatient'),
            'discharge' => $query->where(fn ($lens) => $lens->where('o.priority', 'discharge')->orWhereRaw("COALESCE((o.metadata->>'discharge_blocking')::boolean, false) = true")),
            'degraded' => $this->applyDegraded($query),
            default => null,
        };
        if ($filters['priority'] !== null) {
            $query->where('o.priority', $filters['priority']);
        }
        if ($filters['modality'] !== null) {
            $query->where('x.modality_code', $filters['modality']);
        }
        if ($filters['unitId'] !== null) {
            $query->where('o.unit_id', $filters['unitId']);
        }
        match ($filters['state']) {
            'breach' => $query->whereExists(fn ($breach) => $breach->selectRaw('1')->from('prod.ancillary_breaches as b')->whereColumn('b.ancillary_order_id', 'o.ancillary_order_id')->where('b.status', 'open')),
            'degraded' => $this->applyDegraded($query),
            'warning' => $query->whereNotExists(fn ($breach) => $breach->selectRaw('1')->from('prod.ancillary_breaches as b')->whereColumn('b.ancillary_order_id', 'o.ancillary_order_id')->where('b.status', 'open'))->where('o.ordered_at', '<=', now()->subMinutes(30)),
            'normal' => $query->whereNotExists(fn ($breach) => $breach->selectRaw('1')->from('prod.ancillary_breaches as b')->whereColumn('b.ancillary_order_id', 'o.ancillary_order_id')->where('b.status', 'open'))->where('o.ordered_at', '>', now()->subMinutes(30)),
            default => null,
        };
        if ($filters['search'] !== null) {
            $search = $filters['search'];
            $query->where(function ($bounded) use ($search): void {
                $bounded->where('o.source_order_key', 'ilike', $search.'%')
                    ->orWhere('o.patient_ref', $search);
                if (preg_match('/^[0-9a-f-]{36}$/i', $search)) {
                    $bounded->orWhere('o.order_uuid', strtolower($search));
                }
            });
        }
    }

    private function applyDegraded(Builder $query): Builder
    {
        return $query->where(fn ($degraded) => $degraded->whereNull('x.rad_exam_id')->orWhereNull('x.modality_code')->orWhereNotExists(fn ($assertion) => $assertion->selectRaw('1')->from('prod.ancillary_current_assertions as a')->whereColumn('a.ancillary_order_id', 'o.ancillary_order_id')->where('a.milestone_code', 'RAD_EXAM_END')));
    }

    private function cursor(?string $encoded): ?Cursor
    {
        if ($encoded === null) {
            return null;
        }
        $cursor = Cursor::fromEncoded($encoded);
        $values = $cursor?->toArray();
        $keys = is_array($values) ? array_keys($values) : [];
        $validKeys = [
            ['sort_rank', 'o.ordered_at', 'o.ancillary_order_id', '_pointsToNextItems'],
            ['sort_rank', 'ordered_at', 'ancillary_order_id', '_pointsToNextItems'],
        ];
        if (! in_array($keys, $validKeys, true)) {
            throw ValidationException::withMessages(['cursor' => 'The Radiology worklist cursor is invalid.']);
        }

        return $cursor;
    }

    /** @param Collection<int, object> $rows @param array<string, mixed> $context @param array<int, array<string, mixed>>|null $riskScores @return list<array<string, mixed>> */
    private function serializeRows(Collection $rows, array $context, bool $canViewPatientDetail, ?array $riskScores = null): array
    {
        if ($rows->isEmpty()) {
            return [];
        }
        $orderIds = $rows->pluck('ancillary_order_id')->map(fn (mixed $id): int => (int) $id)->all();
        $imagingByOrder = $this->readiness->imagingForOrders($orderIds, 'flow_board');
        $encounterIds = $rows->pluck('encounter_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->all();
        $catalog = DB::table('hosp_ref.ancillary_milestone_types')->where('department', 'rad')->orderBy('ordinal')->get()->keyBy('code');
        $selected = DB::table('prod.ancillary_current_assertions')->whereIn('ancillary_order_id', $orderIds)->get()->keyBy(fn (object $row): string => $row->ancillary_order_id.'|'.$row->milestone_code);
        $assertions = DB::table('prod.ancillary_milestones as m')
            ->join('integration.sources as s', 's.source_id', '=', 'm.source_id')
            ->whereIn('m.ancillary_order_id', $orderIds)->orderBy('m.occurred_at')->orderBy('m.ancillary_milestone_id')
            ->get(['m.ancillary_milestone_id', 'm.ancillary_order_id', 'm.milestone_code', 'm.milestone_uuid', 'm.occurred_at', 'm.received_at', 'm.source_rank', 's.source_key'])
            ->groupBy('ancillary_order_id');
        $barriers = DB::table('prod.barriers as b')->leftJoin('hosp_ref.ancillary_barrier_reasons as r', 'r.reason_code', '=', 'b.reason_code')
            ->whereIn('b.encounter_id', $encounterIds)->where('b.status', 'open')->where('b.is_deleted', false)
            ->get(['b.barrier_id', 'b.encounter_id', 'b.reason_code', 'b.owner', 'b.opened_at', 'r.label'])->groupBy('encounter_id');

        $serialized = $rows->map(function (object $row) use ($catalog, $selected, $assertions, $barriers, $context, $imagingByOrder, $canViewPatientDetail, $riskScores): array {
            $orderAssertions = $assertions->get($row->ancillary_order_id, collect());
            $selectedByCode = $orderAssertions->groupBy('milestone_code')->map(function (Collection $group) use ($row, $selected): ?object {
                $current = $selected->get($row->ancillary_order_id.'|'.$group->first()->milestone_code);

                return $current === null ? null : $group->firstWhere('ancillary_milestone_id', $current->ancillary_milestone_id);
            });
            $transportPresent = $orderAssertions->contains(fn (object $assertion): bool => in_array($assertion->milestone_code, ['RAD_TRANSPORT_REQUESTED', 'RAD_TRANSPORT_COMPLETE'], true));
            $timelineMilestones = $catalog->filter(fn (object $milestone): bool => $milestone->phase !== 'transport' || $transportPresent)
                ->map(function (object $milestone) use ($selectedByCode, $selected, $row): array {
                    $assertion = $selectedByCode->get($milestone->code);
                    $view = $selected->get($row->ancillary_order_id.'|'.$milestone->code);
                    $state = $assertion === null
                        ? ($milestone->is_minimum_feed ? 'pending_required' : 'missing_optional')
                        : ($milestone->is_terminal ? 'terminal' : ($row->current_milestone_code === $milestone->code ? 'current' : 'done'));

                    return [
                        'code' => $milestone->code,
                        'label' => $milestone->label,
                        'state' => $state,
                        'required' => (bool) $milestone->is_minimum_feed,
                        'occurredAt' => $assertion === null ? null : CarbonImmutable::parse($assertion->occurred_at)->toAtomString(),
                        'selectedSource' => $assertion?->source_key,
                        'assertionCount' => (int) ($view->assertion_count ?? 0),
                        'conflict' => (int) ($view->assertion_count ?? 0) > 1,
                    ];
                })->values()->all();
            $degraded = collect($timelineMilestones)->contains(fn (array $milestone): bool => $milestone['state'] === 'pending_required');
            $examMetadata = json_decode((string) $row->exam_metadata, true) ?: [];
            $age = max(0, (int) floor((float) $row->age_minutes));
            $status = match (true) {
                (bool) $row->breached => 'breach',
                $degraded => 'degraded',
                $age >= 30 => 'warning',
                default => 'normal',
            };
            $clock = $this->clock($row, $selectedByCode, $context['thresholds']['definitions']);
            $imaging = $imagingByOrder->get((int) $row->ancillary_order_id);

            return [
                'orderId' => (int) $row->ancillary_order_id,
                'orderUuid' => (string) $row->order_uuid,
                'label' => $row->procedure_label ?: (($row->modality_code ?: 'Unknown modality').' imaging'),
                'patientRef' => $canViewPatientDetail
                    ? ($row->patient_ref ?: 'Pseudonymous patient unavailable')
                    : 'Patient context restricted',
                'patientClass' => (string) $row->patient_class,
                'priority' => (string) $row->priority,
                'modality' => $row->modality_code,
                'locationLabel' => $row->unit_name,
                'ageMinutes' => $age,
                'status' => $status,
                'currentState' => (string) $row->current_state,
                'downstreamImpact' => [
                    'edDecision' => $row->patient_class === 'emergency',
                    'dischargeBlocking' => (bool) ($imaging['blocking'] ?? false),
                    'orCaseId' => isset($examMetadata['or_case_id']) ? (int) $examMetadata['or_case_id'] : null,
                ],
                'readiness' => $imaging === null ? [] : [$imaging],
                'barriers' => collect($barriers->get($row->encounter_id, collect()))->map(fn (object $barrier): array => [
                    'barrierId' => (int) $barrier->barrier_id,
                    'reasonCode' => $barrier->reason_code,
                    'label' => $barrier->label ?? 'Operational barrier',
                    'owner' => $barrier->owner,
                    'openedAt' => CarbonImmutable::parse($barrier->opened_at)->toAtomString(),
                ])->values()->all(),
                'sourceAssertions' => $orderAssertions->map(function (object $assertion) use ($selected): array {
                    $current = $selected->get($assertion->ancillary_order_id.'|'.$assertion->milestone_code);

                    return [
                        'milestoneUuid' => (string) $assertion->milestone_uuid,
                        'code' => (string) $assertion->milestone_code,
                        'occurredAt' => CarbonImmutable::parse($assertion->occurred_at)->toAtomString(),
                        'receivedAt' => CarbonImmutable::parse($assertion->received_at)->toAtomString(),
                        'sourceKey' => (string) $assertion->source_key,
                        'sourceRank' => (int) $assertion->source_rank,
                        'selected' => (int) ($current->ancillary_milestone_id ?? 0) === (int) $assertion->ancillary_milestone_id,
                    ];
                })->values()->all(),
                'transportSegment' => $transportPresent ? collect($timelineMilestones)->whereIn('code', ['RAD_TRANSPORT_REQUESTED', 'RAD_TRANSPORT_COMPLETE'])->values()->all() : null,
                'risk' => $riskScores === null ? null : ($riskScores[(int) $row->ancillary_order_id] ?? [
                    'availability' => 'unavailable',
                    'probability' => null,
                    'band' => null,
                    'factors' => [],
                    'missingSignals' => ['queue_depth'],
                    'explanation' => 'No operational features were resolvable for this order; no score is produced.',
                ]),
                'timeline' => [
                    'orderUuid' => (string) $row->order_uuid,
                    'label' => $row->procedure_label ?: (($row->modality_code ?: 'Unknown modality').' imaging'),
                    'milestones' => $timelineMilestones,
                    'clock' => $clock,
                    'freshness' => $context['freshness'],
                    'degradedMode' => $degraded,
                    'degradedExplanation' => $degraded ? 'One or more minimum-feed milestones are unavailable.' : null,
                ],
            ];
        })->all();

        // Page-local planning re-rank: when risk sorting is opted in, order the
        // already-paginated page by the model score so the deterministic DB
        // cursor keeps pagination stable while the visible ordering reflects risk.
        if ($riskScores !== null) {
            usort($serialized, function (array $a, array $b): int {
                $rankA = $this->riskRank($a['risk'] ?? null);
                $rankB = $this->riskRank($b['risk'] ?? null);

                return $rankB <=> $rankA;
            });
        }

        return $serialized;
    }

    /** Sort key for the page-local risk re-rank; unavailable sinks to the bottom. */
    private function riskRank(?array $risk): float
    {
        if ($risk === null || $risk['availability'] === 'unavailable' || $risk['probability'] === null) {
            return -1.0;
        }

        return (float) $risk['probability'];
    }

    /** @param Collection<string, object|null> $selectedByCode @param list<array<string, mixed>> $definitions @return array<string, mixed>|null */
    private function clock(object $row, Collection $selectedByCode, array $definitions): ?array
    {
        $definition = collect($definitions)
            ->filter(fn (array $definition): bool => ($definition['priority'] === null || $definition['priority'] === $row->priority)
                && ($definition['patientClass'] === null || $definition['patientClass'] === $row->patient_class))
            ->sortByDesc(fn (array $definition): int => ($definition['priority'] !== null ? 1 : 0) + ($definition['patientClass'] !== null ? 1 : 0))
            ->first();
        if ($definition === null) {
            return null;
        }
        $start = $selectedByCode->get($definition['startMilestoneCode']);
        $stop = $selectedByCode->get($definition['stopMilestoneCode']);
        $elapsed = $start === null ? null : round(CarbonImmutable::parse($start->occurred_at)->diffInSeconds($stop === null ? now() : CarbonImmutable::parse($stop->occurred_at), false) / 60, 2);
        if (is_float($elapsed) && floor($elapsed) === $elapsed) {
            $elapsed = (int) $elapsed;
        }
        $state = match (true) {
            $start === null || ($elapsed !== null && $elapsed < 0) => 'unknown',
            $stop !== null => 'complete',
            $definition['breachMinutes'] !== null && $elapsed >= $definition['breachMinutes'] => 'breached',
            $definition['warningMinutes'] !== null && $elapsed >= $definition['warningMinutes'] => 'warning',
            default => 'running',
        };

        return [
            'metricKey' => $definition['metricKey'],
            'label' => $definition['label'],
            'state' => $state,
            'startMilestoneCode' => $definition['startMilestoneCode'],
            'stopMilestoneCode' => $definition['stopMilestoneCode'],
            'startedAt' => $start === null ? null : CarbonImmutable::parse($start->occurred_at)->toAtomString(),
            'stoppedAt' => $stop === null ? null : CarbonImmutable::parse($stop->occurred_at)->toAtomString(),
            'elapsedMinutes' => $elapsed === null ? null : max(0, $elapsed),
            'warningMinutes' => $definition['warningMinutes'],
            'breachMinutes' => $definition['breachMinutes'],
            'definitionUuid' => $definition['definitionUuid'],
        ];
    }
}
