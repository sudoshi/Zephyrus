<?php

namespace App\Services\Lab;

use App\Data\Ancillary\FreshnessEnvelope;
use App\Models\Ancillary\AncillarySlaDefinition;
use App\Services\Ancillary\AncillaryContractSerializer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class LabDecisionPendingService
{
    public const DECISION_CLASSES = ['or_gate', 'discharge_gate', 'ed_disposition'];

    public const URGENCIES = ['all', 'breach', 'warning', 'normal', 'unconfigured', 'degraded', 'stale'];

    private const PRIORITY_RANK = ['stat' => 0, 'urgent' => 1, 'discharge' => 2, 'timed' => 3, 'routine' => 4, 'unknown' => 5];

    private const IMPACT_RANK = ['or_gate' => 0, 'discharge_gate' => 1, 'ed_disposition' => 2];

    public function __construct(private readonly AncillaryContractSerializer $contracts) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function build(array $input = [], bool $canAnnotateBarriers = false, bool $canViewPatientDetail = true): array
    {
        $filters = $this->filters($input);
        $rows = $this->pendingRows($filters);
        $freshness = $this->freshness($rows->max('source_cutoff_at'));
        $destinations = $this->destinations($rows);
        $definitions = AncillarySlaDefinition::query()
            ->activeAt(now())
            ->where('department', 'lab')
            ->where('statistic', 'item_clock')
            ->where('stop_milestone_code', 'LAB_VERIFIED')
            ->get();
        $starts = $this->clockStarts($rows, $definitions);
        $unresolved = collect();

        $items = $rows->map(function (object $row) use ($destinations, $definitions, $starts, $freshness, $canViewPatientDetail, $unresolved): ?array {
            $orderMetadata = $this->json($row->order_metadata);
            $resultMetadata = $this->json($row->result_metadata);
            $target = $this->target($row, $orderMetadata, $resultMetadata);
            $destination = $target === null ? null : $destinations->get($row->decision_class.'.'.$target['id']);

            if ($target === null || $destination === null || ! $destination['active']) {
                $unresolved->push([
                    'orderUuid' => (string) $row->order_uuid,
                    'decisionClass' => (string) $row->decision_class,
                    'reason' => $target === null
                        ? 'The governed decision class has no valid source-linked destination identity.'
                        : 'The source-linked downstream object is absent, inactive, completed, or outside the live operational cohort.',
                ]);

                return null;
            }

            $definition = $this->definitionFor($definitions, $row, $orderMetadata);
            $sla = $this->sla($row, $definition, $starts, $freshness['status']);
            $decisionContext = [
                'decision_class' => (string) $row->decision_class,
                'blocked_object_type' => (string) $destination['objectType'],
                'blocked_object_id' => (int) $destination['id'],
                'explanation' => $target['explanation'] ?? $this->defaultExplanation((string) $row->decision_class),
            ];
            $age = max(0, (int) floor(CarbonImmutable::parse($row->ordered_at)->diffInSeconds(now(), false) / 60));
            $priorityRank = self::PRIORITY_RANK[$row->priority] ?? self::PRIORITY_RANK['unknown'];
            $impactRank = self::IMPACT_RANK[$row->decision_class];

            return [
                'pendingKey' => (string) $row->order_uuid.'|'.(string) $row->catalog_uuid,
                'orderUuid' => (string) $row->order_uuid,
                'resultUuid' => $row->result_uuid,
                'specimenUuid' => $row->specimen_uuid,
                'label' => (string) $row->test_label,
                'testFamily' => (string) $row->test_family,
                'catalogKey' => (string) $row->catalog_key,
                'patientRef' => $canViewPatientDetail
                    ? ($row->patient_ref ?: 'Pseudonymous patient unavailable')
                    : 'Patient context restricted',
                'patientClass' => (string) $row->patient_class,
                'priority' => (string) $row->priority,
                'locationLabel' => $row->unit_name,
                'encounterLinked' => $row->encounter_id !== null,
                'currentStage' => (string) ($row->current_milestone_code ?: 'LAB_ORDERED'),
                'resultState' => [
                    'status' => $row->result_status ?? 'awaiting_result',
                    'stage' => $row->result_stage ?? 'pre_result',
                    'critical' => (bool) ($row->is_critical ?? false),
                    'abnormalFlag' => $row->abnormal_flag ?? 'unknown',
                ],
                'ageMinutes' => $age,
                'sourceCutoffAt' => CarbonImmutable::parse($row->source_cutoff_at)->toAtomString(),
                'decisionClass' => (string) $row->decision_class,
                'decisionContext' => $decisionContext,
                'destination' => $destination,
                'gateEvidence' => [
                    'catalogDecisionClass' => (string) $row->decision_class,
                    'identitySource' => $target['source'],
                    'validated' => true,
                    'explanation' => 'The gate is selected from the governed test catalog and a validated source-linked downstream object; the test label is not used to infer impact.',
                ],
                'sla' => $sla,
                'ranking' => [
                    'impactRank' => $impactRank,
                    'priorityRank' => $priorityRank,
                    'sortKey' => sprintf('%d|%010d|%d|%020d', $impactRank, 9999999999 - $age, $priorityRank, (int) $row->ancillary_order_id),
                    'reasons' => [
                        $destination['rankReason'],
                        sprintf('Within this impact class, older work ranks first (%d minutes), then governed priority (%s).', $age, $row->priority),
                        'The ancillary order identity is the final deterministic tie-breaker.',
                    ],
                ],
                'drill' => [
                    'specimenHref' => '/lab/specimens?'.http_build_query(['orderUuid' => $row->order_uuid]),
                    'destinationHref' => $destination['href'],
                ],
                'barrierCount' => (int) $row->barrier_count,
            ];
        })->filter();

        if ($filters['urgency'] !== 'all') {
            $items = $items->where('sla.urgency', $filters['urgency']);
        }

        $ranked = $items->sortBy('ranking.sortKey')->take($filters['limit'])->values()
            ->map(function (array $item, int $index): array {
                $item['ranking']['position'] = $index + 1;

                return $item;
            });
        $counts = $this->exclusionCounts($filters);
        $state = $this->state($ranked, $unresolved, $freshness);

        return [
            'generatedAt' => now()->toAtomString(),
            'state' => $state,
            'stateMessage' => $this->stateMessage($state, $unresolved->count()),
            'freshness' => $freshness,
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'rankingRule' => 'Live OR gate, discharge bed impact, ED disposition, then descending age, governed priority, and stable order identity.',
            'summary' => [
                'visible' => $ranked->count(),
                'resolvedBeforeLimit' => $items->count(),
                'orGates' => $items->where('decisionClass', 'or_gate')->count(),
                'dischargeGates' => $items->where('decisionClass', 'discharge_gate')->count(),
                'edDispositions' => $items->where('decisionClass', 'ed_disposition')->count(),
                'unresolvedDestinations' => $unresolved->count(),
                'breached' => $items->where('sla.urgency', 'breach')->count(),
            ],
            'exclusions' => [
                'noGateCatalog' => $counts['noGateCatalog'],
                'completedOrCancelled' => $counts['completedOrCancelled'],
                'unresolved' => $unresolved->values()->all(),
                'explanation' => 'Catalog entries with decision class none, completed/cancelled latest results, and unvalidated downstream identities never enter the decision-pending queue.',
            ],
            'data' => $ranked->all(),
            'destinationAggregates' => $this->destinationAggregates($items),
            'privacy' => [
                'patientContextIncluded' => $canViewPatientDetail,
                'directPatientIdentifiersIncluded' => false,
                'resultContentIncluded' => false,
                'identifierPolicy' => $canViewPatientDetail
                    ? 'Only source-scoped pseudonymous patient context and operational result state are included.'
                    : 'Patient context is redacted; result values, narratives, and direct identifiers are always excluded.',
            ],
            'canAnnotateBarriers' => $canAnnotateBarriers,
            'barrierReasons' => $this->barrierReasons(),
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function filters(array $input): array
    {
        $decisionClass = is_string($input['decisionClass'] ?? null) && in_array($input['decisionClass'], ['all', ...self::DECISION_CLASSES], true)
            ? $input['decisionClass'] : 'all';
        $priority = is_string($input['priority'] ?? null) && in_array($input['priority'], LabFlowBoardService::PRIORITIES, true)
            ? $input['priority'] : null;
        $unitId = filter_var($input['unitId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $urgency = is_string($input['urgency'] ?? null) && in_array($input['urgency'], self::URGENCIES, true)
            ? $input['urgency'] : 'all';

        return [
            'decisionClass' => $decisionClass,
            'priority' => $priority,
            'unitId' => $unitId === false ? null : $unitId,
            'urgency' => $urgency,
            'limit' => min(100, max(1, (int) ($input['limit'] ?? 50))),
        ];
    }

    /** @return array<string, mixed> */
    private function filterOptions(): array
    {
        return [
            'decisionClasses' => ['all', ...self::DECISION_CLASSES],
            'priorities' => LabFlowBoardService::PRIORITIES,
            'units' => DB::table('prod.units as u')
                ->join('prod.ancillary_orders as o', 'o.unit_id', '=', 'u.unit_id')
                ->where('o.department', 'lab')->where('u.is_deleted', false)
                ->distinct()->orderBy('u.name')->get(['u.unit_id', 'u.name'])
                ->map(fn (object $row): array => ['unitId' => (int) $row->unit_id, 'label' => (string) $row->name])->all(),
            'urgencies' => self::URGENCIES,
        ];
    }

    /** @param array<string, mixed> $filters @return Collection<int, object> */
    private function pendingRows(array $filters): Collection
    {
        $catalog = DB::table('hosp_ref.lab_test_catalog as selected_catalog')
            ->where('selected_catalog.is_active', true)
            ->whereNotIn('selected_catalog.department', ['pathology', 'blood_bank'])
            ->whereColumn(DB::raw('upper(selected_catalog.local_code)'), DB::raw("upper(o.metadata->>'test_code')"))
            ->where('selected_catalog.effective_from', '<=', now())
            ->where(fn (Builder $query): Builder => $query->whereNull('selected_catalog.effective_to')->orWhere('selected_catalog.effective_to', '>', now()))
            ->orderByDesc('selected_catalog.effective_from')->orderByDesc('selected_catalog.lab_test_catalog_id')->limit(1);
        $latestResult = DB::table('prod.lab_results as latest_result')
            ->whereColumn('latest_result.ancillary_order_id', 'o.ancillary_order_id')
            ->whereColumn('latest_result.lab_test_catalog_id', 'c.lab_test_catalog_id')
            ->orderByDesc('latest_result.lab_result_id')->limit(1);
        $latestSpecimen = DB::table('prod.lab_specimens as latest_specimen')
            ->whereColumn('latest_specimen.ancillary_order_id', 'o.ancillary_order_id')
            ->orderByDesc('latest_specimen.lab_specimen_id')->limit(1);

        return DB::table('prod.ancillary_orders as o')
            ->joinLateral($catalog, 'c')
            ->leftJoinLateral($latestResult, 'r')
            ->leftJoinLateral($latestSpecimen, 's')
            ->leftJoin('prod.units as u', 'u.unit_id', '=', 'o.unit_id')
            ->where('o.department', 'lab')
            ->where('o.ordered_at', '>=', now()->subDay())
            ->whereRaw("COALESCE(o.metadata->>'operational_window', 'current') <> 'historical_study_only'")
            ->whereIn('c.decision_class', self::DECISION_CLASSES)
            ->where(function (Builder $query): void {
                $query->whereRaw("o.metadata->>'decision_class' = c.decision_class")
                    ->orWhereRaw("r.metadata->'decision_context'->>'decision_class' = c.decision_class");
            })
            ->whereNull('r.verified_at')
            ->whereNull('r.cancelled_at')
            ->where(fn (Builder $query): Builder => $query->whereNull('r.result_status')->orWhere('r.result_status', '!=', 'cancelled'))
            ->when($filters['decisionClass'] !== 'all', fn (Builder $query): Builder => $query->where('c.decision_class', $filters['decisionClass']))
            ->when($filters['priority'], fn (Builder $query, string $priority): Builder => $query->where('o.priority', $priority))
            ->when($filters['unitId'], fn (Builder $query, int $unitId): Builder => $query->where('o.unit_id', $unitId))
            ->select([
                'o.ancillary_order_id', 'o.order_uuid', 'o.encounter_id', 'o.unit_id', 'o.patient_ref', 'o.patient_class', 'o.priority',
                'o.ordered_at', 'o.source_cutoff_at', 'o.current_milestone_code', 'o.metadata as order_metadata', 'u.name as unit_name',
                'c.catalog_uuid', 'c.catalog_key', 'c.local_code', 'c.label as test_label', 'c.test_family', 'c.decision_class',
                'r.lab_result_id', 'r.result_uuid', 'r.result_status', 'r.result_stage', 'r.is_critical', 'r.abnormal_flag', 'r.metadata as result_metadata',
                's.specimen_uuid',
            ])
            ->selectSub(function (Builder $query): void {
                $query->from('prod.barriers as b')->whereColumn('b.encounter_id', 'o.encounter_id')
                    ->where('b.status', 'open')->where('b.is_deleted', false)->selectRaw('count(*)');
            }, 'barrier_count')
            ->orderBy('o.ancillary_order_id')
            ->get();
    }

    /** @param Collection<int, object> $rows @return Collection<string, array<string, mixed>> */
    private function destinations(Collection $rows): Collection
    {
        $targets = $rows->map(function (object $row): ?array {
            $target = $this->target($row, $this->json($row->order_metadata), $this->json($row->result_metadata));

            return $target === null ? null : ['class' => $row->decision_class, 'id' => $target['id']];
        })->filter();
        $out = collect();

        $edIds = $targets->where('class', 'ed_disposition')->pluck('id')->unique()->all();
        if ($edIds !== []) {
            DB::table('prod.ed_visits')->whereIn('ed_visit_id', $edIds)->get([
                'ed_visit_id', 'arrived_at', 'departed_at', 'disposition', 'is_deleted',
            ])->each(function (object $row) use ($out): void {
                $active = ! $row->is_deleted && $row->departed_at === null;
                $out->put('ed_disposition.'.$row->ed_visit_id, [
                    'objectType' => 'ed_visit', 'id' => (int) $row->ed_visit_id,
                    'label' => 'ED visit '.(int) $row->ed_visit_id, 'active' => $active,
                    'href' => '/ed/operations/treatment?'.http_build_query(['edVisitId' => (int) $row->ed_visit_id]),
                    'scheduledAt' => null, 'expectedDischargeDate' => null, 'bedImpact' => 0,
                    'rankReason' => 'ED disposition is the third impact class because the active visit still awaits a downstream disposition decision.',
                ]);
            });
        }

        $encounterIds = $targets->where('class', 'discharge_gate')->pluck('id')->unique()->all();
        if ($encounterIds !== []) {
            DB::table('prod.encounters')->whereIn('encounter_id', $encounterIds)->get([
                'encounter_id', 'expected_discharge_date', 'discharged_at', 'status', 'is_deleted',
            ])->each(function (object $row) use ($out): void {
                $active = ! $row->is_deleted && $row->discharged_at === null && $row->status === 'active' && $row->expected_discharge_date !== null;
                $out->put('discharge_gate.'.$row->encounter_id, [
                    'objectType' => 'encounter_discharge', 'id' => (int) $row->encounter_id,
                    'label' => 'Encounter '.(int) $row->encounter_id.' discharge', 'active' => $active,
                    'href' => '/rtdc/predictions/discharge?'.http_build_query(['encounterId' => (int) $row->encounter_id]),
                    'scheduledAt' => null,
                    'expectedDischargeDate' => $row->expected_discharge_date === null ? null : CarbonImmutable::parse($row->expected_discharge_date)->toDateString(),
                    'bedImpact' => $active ? 1 : 0,
                    'rankReason' => 'Discharge bed impact is the second impact class because clearing this active gate may release one inpatient bed.',
                ]);
            });
        }

        $caseIds = $targets->where('class', 'or_gate')->pluck('id')->unique()->all();
        if ($caseIds !== []) {
            DB::table('prod.or_cases as oc')->leftJoin('prod.rooms as room', 'room.room_id', '=', 'oc.room_id')
                ->leftJoin('prod.services as service', 'service.service_id', '=', 'oc.case_service_id')
                ->whereIn('oc.case_id', $caseIds)->get([
                    'oc.case_id', 'oc.surgery_date', 'oc.scheduled_start_time', 'oc.is_deleted', 'room.name as room_name', 'service.name as service_name',
                ])->each(function (object $row) use ($out): void {
                    $active = ! $row->is_deleted;
                    $out->put('or_gate.'.$row->case_id, [
                        'objectType' => 'or_case', 'id' => (int) $row->case_id,
                        'label' => trim('OR case '.(int) $row->case_id.' · '.($row->room_name ?: 'room unavailable').' · '.($row->service_name ?: 'service unavailable')),
                        'active' => $active,
                        'href' => '/operations/cases?'.http_build_query(['caseId' => (int) $row->case_id]),
                        'scheduledAt' => $row->scheduled_start_time === null ? null : CarbonImmutable::parse($row->scheduled_start_time)->toAtomString(),
                        'expectedDischargeDate' => null, 'bedImpact' => 0,
                        'rankReason' => 'A live OR start gate is the highest impact class because a current Laboratory assertion explicitly blocks a valid non-deleted case.',
                    ]);
                });
        }

        return $out;
    }

    /** @param array<string, mixed> $orderMetadata @param array<string, mixed> $resultMetadata @return array<string, mixed>|null */
    private function target(object $row, array $orderMetadata, array $resultMetadata): ?array
    {
        $context = $resultMetadata['decision_context'] ?? null;
        if (is_array($context)) {
            $expectedType = ['ed_disposition' => 'ed_visit', 'discharge_gate' => 'encounter_discharge', 'or_gate' => 'or_case'][$row->decision_class];
            $id = filter_var($context['blocked_object_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (($context['decision_class'] ?? null) !== $row->decision_class || ($context['blocked_object_type'] ?? null) !== $expectedType || $id === false) {
                return null;
            }

            return ['id' => $id, 'source' => 'result_decision_context', 'explanation' => $context['explanation'] ?? null];
        }

        $id = match ($row->decision_class) {
            'ed_disposition' => $orderMetadata['ed_visit_id'] ?? null,
            'discharge_gate' => $row->encounter_id,
            'or_gate' => $orderMetadata['or_case_id'] ?? null,
            default => null,
        };
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : ['id' => $id, 'source' => 'order_linkage', 'explanation' => null];
    }

    /** @param Collection<int, object> $rows @param Collection<int, AncillarySlaDefinition> $definitions @return Collection<string, CarbonImmutable> */
    private function clockStarts(Collection $rows, Collection $definitions): Collection
    {
        $ids = $rows->pluck('ancillary_order_id')->map(fn (mixed $id): int => (int) $id)->all();
        $codes = $definitions->pluck('start_milestone_code')->unique()->values()->all();
        if ($ids === [] || $codes === []) {
            return collect();
        }

        return DB::table('prod.ancillary_current_assertions')->whereIn('ancillary_order_id', $ids)->whereIn('milestone_code', $codes)
            ->get(['ancillary_order_id', 'milestone_code', 'occurred_at'])
            ->mapWithKeys(fn (object $row): array => [$row->ancillary_order_id.'|'.$row->milestone_code => CarbonImmutable::parse($row->occurred_at)]);
    }

    /** @param Collection<int, AncillarySlaDefinition> $definitions @param array<string, mixed> $metadata */
    private function definitionFor(Collection $definitions, object $row, array $metadata): ?AncillarySlaDefinition
    {
        return $definitions->filter(function (AncillarySlaDefinition $definition) use ($row, $metadata): bool {
            if ($definition->priority !== null && $definition->priority !== $row->priority) {
                return false;
            }
            if ($definition->patient_class !== null && $definition->patient_class !== $row->patient_class) {
                return false;
            }
            $population = [
                'department' => 'lab', 'priority' => $row->priority, 'patient_class' => $row->patient_class,
                'unit_id' => $row->unit_id ?? null, ...$metadata,
            ];
            foreach ($definition->scope as $key => $expected) {
                $actual = data_get($population, (string) $key);
                if (is_array($expected) ? ! in_array($actual, $expected, true) : $actual != $expected) {
                    return false;
                }
            }

            return true;
        })->sortByDesc(fn (AncillarySlaDefinition $definition): string => sprintf(
            '%04d|%010d|%010d',
            count($definition->scope) + ($definition->priority !== null ? 1 : 0) + ($definition->patient_class !== null ? 1 : 0),
            $definition->version,
            $definition->ancillary_sla_definition_id,
        ))->first();
    }

    /** @param Collection<string, CarbonImmutable> $starts @return array<string, mixed> */
    private function sla(object $row, ?AncillarySlaDefinition $definition, Collection $starts, string $freshnessStatus): array
    {
        if ($definition === null) {
            return [
                'definition' => null, 'startAt' => null, 'elapsedMinutes' => null, 'urgency' => $freshnessStatus === 'stale' ? 'stale' : 'unconfigured',
                'explanation' => 'No active item-clock definition matches this test population; no threshold is invented.',
            ];
        }
        $start = $starts->get($row->ancillary_order_id.'|'.$definition->start_milestone_code);
        if ($start === null && $definition->start_milestone_code === 'LAB_ORDERED') {
            $start = CarbonImmutable::parse($row->ordered_at);
        }
        $elapsed = $start === null ? null : max(0, (int) floor($start->diffInSeconds(now(), false) / 60));
        $urgency = match (true) {
            $freshnessStatus === 'stale' => 'stale',
            $elapsed === null => 'degraded',
            $definition->breach_minutes !== null && $elapsed >= $definition->breach_minutes => 'breach',
            $definition->warning_minutes !== null && $elapsed >= $definition->warning_minutes => 'warning',
            default => 'normal',
        };

        return [
            'definition' => $this->contracts->slaDefinition($definition),
            'startAt' => $start?->toAtomString(), 'elapsedMinutes' => $elapsed, 'urgency' => $urgency,
            'explanation' => sprintf('%s to %s; urgency is computed from the persisted effective-dated definition.', $definition->start_milestone_code, $definition->stop_milestone_code),
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, int> */
    private function exclusionCounts(array $filters): array
    {
        $base = DB::table('prod.ancillary_orders as o')->where('o.department', 'lab')->where('o.ordered_at', '>=', now()->subDay())
            ->whereRaw("COALESCE(o.metadata->>'operational_window', 'current') <> 'historical_study_only'")
            ->when($filters['priority'], fn (Builder $query, string $priority): Builder => $query->where('o.priority', $priority))
            ->when($filters['unitId'], fn (Builder $query, int $unitId): Builder => $query->where('o.unit_id', $unitId));
        $noGate = (clone $base)->join('hosp_ref.lab_test_catalog as c', fn ($join) => $join
            ->whereRaw("upper(c.local_code) = upper(o.metadata->>'test_code')")->where('c.is_active', true))
            ->where('c.decision_class', 'none')->distinct('o.ancillary_order_id')->count('o.ancillary_order_id');
        $catalog = DB::table('hosp_ref.lab_test_catalog as completed_catalog')
            ->where('completed_catalog.is_active', true)
            ->whereNotIn('completed_catalog.department', ['pathology', 'blood_bank'])
            ->whereColumn(DB::raw('upper(completed_catalog.local_code)'), DB::raw("upper(o.metadata->>'test_code')"))
            ->where('completed_catalog.effective_from', '<=', now())
            ->where(fn (Builder $query): Builder => $query->whereNull('completed_catalog.effective_to')->orWhere('completed_catalog.effective_to', '>', now()))
            ->orderByDesc('completed_catalog.effective_from')->orderByDesc('completed_catalog.lab_test_catalog_id')->limit(1);
        $latestResult = DB::table('prod.lab_results as completed_result')
            ->whereColumn('completed_result.ancillary_order_id', 'o.ancillary_order_id')
            ->whereColumn('completed_result.lab_test_catalog_id', 'c.lab_test_catalog_id')
            ->orderByDesc('completed_result.lab_result_id')->limit(1);
        $completed = (clone $base)->joinLateral($catalog, 'c')->leftJoinLateral($latestResult, 'r')
            ->whereIn('c.decision_class', self::DECISION_CLASSES)
            ->where(function (Builder $query): void {
                $query->whereRaw("o.metadata->>'decision_class' = c.decision_class")
                    ->orWhereRaw("r.metadata->'decision_context'->>'decision_class' = c.decision_class");
            })
            ->when($filters['decisionClass'] !== 'all', fn (Builder $query): Builder => $query->where('c.decision_class', $filters['decisionClass']))
            ->where(fn (Builder $query): Builder => $query->whereNotNull('r.verified_at')->orWhereNotNull('r.cancelled_at')->orWhere('r.result_status', 'cancelled'))
            ->distinct('o.ancillary_order_id')->count('o.ancillary_order_id');

        return ['noGateCatalog' => $noGate, 'completedOrCancelled' => $completed];
    }

    /** @param Collection<int, array<string, mixed>> $items @return list<array<string, mixed>> */
    private function destinationAggregates(Collection $items): array
    {
        return $items->groupBy(fn (array $item): string => $item['decisionClass'].'.'.$item['destination']['id'])
            ->map(function (Collection $group): array {
                $ordered = $group->sortBy('ranking.sortKey')->values();
                $first = $ordered->first();

                return [
                    'decisionClass' => $first['decisionClass'],
                    'destinationId' => $first['destination']['id'],
                    'destinationHref' => $first['destination']['href'],
                    'pendingCount' => $ordered->count(),
                    'oldestAgeMinutes' => $ordered->max('ageMinutes'),
                    'topOrderUuid' => $first['orderUuid'],
                    'resultUuids' => $ordered->pluck('resultUuid')->filter()->values()->all(),
                ];
            })->sortBy(fn (array $row): string => sprintf('%d|%020d', self::IMPACT_RANK[$row['decisionClass']], $row['destinationId']))
            ->values()->all();
    }

    /** @param Collection<int, array<string, mixed>> $items @param Collection<int, array<string, mixed>> $unresolved @param array<string, mixed> $freshness */
    private function state(Collection $items, Collection $unresolved, array $freshness): string
    {
        $registered = strtolower((string) (DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->value('status') ?? ''));

        return match (true) {
            in_array($registered, ['error', 'failed', 'unavailable'], true) => 'source_error',
            $items->isEmpty() && $unresolved->isEmpty() => 'no_data',
            $freshness['status'] === 'stale' => 'stale',
            $unresolved->isNotEmpty() => 'degraded',
            default => 'normal',
        };
    }

    private function stateMessage(string $state, int $unresolved): string
    {
        return match ($state) {
            'source_error' => 'Laboratory source health reports an error. Last known decision links remain cutoff-qualified.',
            'no_data' => 'No validated decision-pending Laboratory results match the selected filters.',
            'stale' => 'Decision-pending Laboratory facts are stale; rank and urgency remain qualified by the last source cutoff.',
            'degraded' => sprintf('%d decision candidate(s) have no validated live downstream destination and are excluded from the ranked queue.', $unresolved),
            default => 'Decision-pending Laboratory facts and downstream links are current.',
        };
    }

    /** @return array<string, mixed> */
    private function freshness(mixed $cutoff): array
    {
        $registered = DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->first();
        if ($cutoff === null) {
            return $this->contracts->freshness(new FreshnessEnvelope(
                'unknown', new \DateTimeImmutable(now()->toAtomString()), null, null,
                (string) ($registered->source_label ?? 'Laboratory operational feeds'),
                'No decision-pending Laboratory source cutoff is available.',
            ));
        }
        $at = CarbonImmutable::parse($cutoff);
        $lag = max(0, (int) floor($at->diffInSeconds(now(), false) / 60));
        $registeredStatus = strtolower((string) ($registered->status ?? 'current'));
        $stale = in_array($registeredStatus, ['stale', 'error', 'failed', 'unavailable'], true)
            || $lag > max(1, (int) ($registered->warning_lag_minutes ?? 60));

        return $this->contracts->freshness(new FreshnessEnvelope(
            $stale ? 'stale' : 'fresh', new \DateTimeImmutable(now()->toAtomString()), new \DateTimeImmutable($at->toAtomString()), $lag,
            (string) ($registered->source_label ?? 'Laboratory operational feeds'),
            $stale ? 'The selected Laboratory assertions exceed the registered freshness tolerance.' : null,
        ));
    }

    /** @return list<array<string, mixed>> */
    private function barrierReasons(): array
    {
        return DB::table('hosp_ref.ancillary_barrier_reasons')->where('department', 'lab')->where('is_active', true)
            ->orderBy('label')->get(['reason_code', 'category', 'label'])
            ->map(fn (object $row): array => ['reasonCode' => $row->reason_code, 'category' => $row->category, 'label' => $row->label])->all();
    }

    /** @return array<string, mixed> */
    private function json(mixed $value): array
    {
        return is_array($value) ? $value : (json_decode((string) ($value ?? '{}'), true) ?: []);
    }

    private function defaultExplanation(string $decisionClass): string
    {
        return match ($decisionClass) {
            'or_gate' => 'Operating-room start readiness is blocked until the Laboratory result is verified.',
            'discharge_gate' => 'Discharge readiness is blocked until the Laboratory result is verified.',
            default => 'ED disposition is blocked until the Laboratory result is verified.',
        };
    }
}
