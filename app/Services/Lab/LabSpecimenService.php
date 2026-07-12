<?php

namespace App\Services\Lab;

use App\Data\Ancillary\FreshnessEnvelope;
use App\Services\Ancillary\AncillaryContractSerializer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LabSpecimenService
{
    public const STATUSES = ['collection_pending', 'collected', 'in_transit', 'received', 'processing', 'rejected', 'recollect_requested', 'cancelled'];

    public const REJECTION_FILTERS = ['all', 'rejected', 'recollect', 'none'];

    public const AGE_BANDS = ['all', '0_29', '30_59', '60_119', '120_plus'];

    public function __construct(private readonly AncillaryContractSerializer $contracts) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function build(array $filters = [], bool $canViewPatientDetail = true): array
    {
        $filters = $this->filters($filters);
        $page = $this->query($filters)->cursorPaginate($filters['perPage'], ['*'], 'cursor', $this->cursor($filters['cursor']));
        $rows = collect($page->items());
        $coverage = $this->coverage();
        $freshness = $this->freshness($rows->max('source_cutoff_at'));
        $registeredStatus = strtolower((string) (DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->value('status') ?? ''));
        $state = match (true) {
            in_array($registeredStatus, ['error', 'failed', 'unavailable'], true) => 'source_error',
            $rows->isEmpty() => 'no_data',
            $freshness['status'] === 'stale' => 'stale',
            $coverage['transport']['status'] === 'missing' => 'degraded',
            default => 'normal',
        };

        return [
            'generatedAt' => now()->toAtomString(),
            'state' => $state,
            'stateMessage' => match ($state) {
                'source_error' => 'Laboratory source health reports an error. Last known specimen facts remain visible.',
                'no_data' => 'No Laboratory specimens match the selected filters.',
                'stale' => 'Laboratory specimen facts are stale and qualified by the last selected source cutoff.',
                'degraded' => 'Transport evidence is unavailable. Collection and receipt remain visible without a fabricated transit segment.',
                default => 'Laboratory specimen facts are current and transport-segmented.',
            },
            'freshness' => $freshness,
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'coverage' => $coverage,
            'data' => $this->serialize($rows, $coverage, $canViewPatientDetail),
            'privacy' => [
                'patientContextIncluded' => $canViewPatientDetail,
                'directPatientIdentifiersIncluded' => false,
                'resultContentIncluded' => false,
                'identifierPolicy' => $canViewPatientDetail
                    ? 'Only source-scoped pseudonymous patient and operational accession references are included.'
                    : 'Patient context is redacted; accession and specimen identities remain operationally scoped.',
            ],
            'meta' => [
                'perPage' => $page->perPage(), 'count' => $page->count(), 'hasMore' => $page->hasMorePages(),
                'nextCursor' => $page->nextCursor()?->encode(), 'previousCursor' => $page->previousCursor()?->encode(),
            ],
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function filters(array $input): array
    {
        $status = is_string($input['status'] ?? null) && in_array($input['status'], self::STATUSES, true) ? $input['status'] : null;
        $family = is_string($input['testFamily'] ?? null) && preg_match('/^[a-z0-9_]{1,80}$/', $input['testFamily']) ? $input['testFamily'] : null;
        $unitId = filter_var($input['unitId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $priority = is_string($input['priority'] ?? null) && in_array($input['priority'], LabFlowBoardService::PRIORITIES, true) ? $input['priority'] : null;
        $rejection = is_string($input['rejection'] ?? null) && in_array($input['rejection'], self::REJECTION_FILTERS, true) ? $input['rejection'] : 'all';
        $age = is_string($input['age'] ?? null) && in_array($input['age'], self::AGE_BANDS, true) ? $input['age'] : 'all';

        return [
            'status' => $status, 'testFamily' => $family, 'unitId' => $unitId === false ? null : $unitId,
            'priority' => $priority, 'rejection' => $rejection, 'age' => $age,
            'perPage' => min(50, max(1, (int) ($input['perPage'] ?? 25))),
            'cursor' => is_string($input['cursor'] ?? null) && $input['cursor'] !== '' ? $input['cursor'] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function filterOptions(): array
    {
        return [
            'statuses' => self::STATUSES,
            'testFamilies' => DB::table('hosp_ref.lab_test_catalog')->whereNotIn('department', ['pathology', 'blood_bank'])->where('is_active', true)->distinct()->orderBy('test_family')->pluck('test_family')->all(),
            'units' => DB::table('prod.units as u')->join('prod.ancillary_orders as o', 'o.unit_id', '=', 'u.unit_id')->where('o.department', 'lab')->where('u.is_deleted', false)->distinct()->orderBy('u.name')->get(['u.unit_id', 'u.name'])->map(fn (object $row): array => ['unitId' => (int) $row->unit_id, 'label' => $row->name])->all(),
            'priorities' => LabFlowBoardService::PRIORITIES,
            'rejections' => self::REJECTION_FILTERS,
            'ageBands' => self::AGE_BANDS,
        ];
    }

    private function query(array $filters): Builder
    {
        $query = DB::table('prod.lab_specimens as s')
            ->join('prod.ancillary_orders as o', 'o.ancillary_order_id', '=', 's.ancillary_order_id')
            ->join('integration.sources as src', 'src.source_id', '=', 's.source_id')
            ->leftJoin('prod.units as u', 'u.unit_id', '=', 'o.unit_id')
            ->where('o.department', 'lab')
            ->where('o.ordered_at', '>=', now()->subDay())
            ->whereRaw("COALESCE(o.metadata->>'operational_window', 'current') <> 'historical_study_only'")
            ->select([
                's.lab_specimen_id', 's.specimen_uuid', 's.source_specimen_key', 's.source_accession_key', 's.parent_specimen_id',
                's.specimen_type', 's.container_type', 's.collector_role', 's.collection_method', 's.status', 's.rejection_reason_code',
                's.collected_at', 's.in_transit_at', 's.received_at', 's.rejected_at', 's.recollect_ordered_at', 's.cancelled_at',
                's.metadata as specimen_metadata', 'src.source_key',
                'o.ancillary_order_id', 'o.order_uuid', 'o.source_order_key', 'o.encounter_id', 'o.patient_ref', 'o.patient_class',
                'o.priority', 'o.ordered_at', 'o.source_cutoff_at', 'o.metadata as order_metadata', 'u.name as unit_name',
            ])
            ->selectRaw('COALESCE(s.collected_at, o.ordered_at) AS sort_at')
            ->selectRaw('EXTRACT(EPOCH FROM (?::timestamptz - COALESCE(s.collected_at, o.ordered_at))) / 60 AS age_minutes', [now()]);

        if ($filters['status'] !== null) {
            $query->where('s.status', $filters['status']);
        }
        if ($filters['testFamily'] !== null) {
            $query->whereRaw("o.metadata->>'test_family' = ?", [$filters['testFamily']]);
        }
        if ($filters['unitId'] !== null) {
            $query->where('o.unit_id', $filters['unitId']);
        }
        if ($filters['priority'] !== null) {
            $query->where('o.priority', $filters['priority']);
        }
        match ($filters['rejection']) {
            'rejected' => $query->whereIn('s.status', ['rejected', 'recollect_requested']),
            'recollect' => $query->where(fn (Builder $q): Builder => $q->whereNotNull('s.parent_specimen_id')->orWhere('s.status', 'recollect_requested')),
            'none' => $query->whereNull('s.parent_specimen_id')->whereNull('s.rejection_reason_code'),
            default => null,
        };
        match ($filters['age']) {
            '0_29' => $query->whereRaw('COALESCE(s.collected_at, o.ordered_at) > ?', [now()->subMinutes(30)]),
            '30_59' => $query->whereRaw('COALESCE(s.collected_at, o.ordered_at) <= ? AND COALESCE(s.collected_at, o.ordered_at) > ?', [now()->subMinutes(30), now()->subMinutes(60)]),
            '60_119' => $query->whereRaw('COALESCE(s.collected_at, o.ordered_at) <= ? AND COALESCE(s.collected_at, o.ordered_at) > ?', [now()->subMinutes(60), now()->subMinutes(120)]),
            '120_plus' => $query->whereRaw('COALESCE(s.collected_at, o.ordered_at) <= ?', [now()->subMinutes(120)]),
            default => null,
        };

        return $query->orderBy('sort_at')->orderBy('s.lab_specimen_id');
    }

    private function cursor(?string $encoded): ?Cursor
    {
        if ($encoded === null) {
            return null;
        }
        $cursor = Cursor::fromEncoded($encoded);
        $keys = array_keys($cursor?->toArray() ?? []);
        if (! in_array($keys, [
            ['sort_at', 's.lab_specimen_id', '_pointsToNextItems'],
            ['sort_at', 'lab_specimen_id', '_pointsToNextItems'],
        ], true)) {
            throw ValidationException::withMessages(['cursor' => 'The Laboratory specimen cursor is invalid.']);
        }

        return $cursor;
    }

    /** @return array<string, mixed> */
    private function coverage(): array
    {
        $transport = DB::table('prod.lab_specimens as s')->join('prod.ancillary_orders as o', 'o.ancillary_order_id', '=', 's.ancillary_order_id')
            ->where('o.department', 'lab')->where('o.ordered_at', '>=', now()->subDay())->whereNotNull('s.in_transit_at')->exists();

        return ['transport' => [
            'status' => $transport ? 'available' : 'missing',
            'columnVisible' => $transport,
            'explanation' => $transport
                ? 'Transport timestamps are evidenced for the current Laboratory feed.'
                : 'Transport feed is unavailable; the tracker hides the transit column and does not infer a zero-minute segment.',
        ]];
    }

    /** @return array<string, mixed> */
    private function freshness(mixed $cutoff): array
    {
        $registered = DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->first();
        if ($cutoff === null) {
            return $this->contracts->freshness(new FreshnessEnvelope(
                status: 'unknown', asOf: new \DateTimeImmutable(now()->toAtomString()), sourceCutoffAt: null,
                lagMinutes: null, sourceLabel: 'Laboratory specimen feeds', explanation: 'No specimen observations match the selected filters.',
            ));
        }
        $at = CarbonImmutable::parse($cutoff);
        $lag = max(0, (int) floor($at->diffInSeconds(now(), false) / 60));
        $registeredStatus = strtolower((string) ($registered->status ?? 'current'));
        $stale = in_array($registeredStatus, ['stale', 'error', 'failed', 'unavailable'], true)
            || $lag > max(1, (int) ($registered->warning_lag_minutes ?? 60));

        return $this->contracts->freshness(new FreshnessEnvelope(
            status: $stale ? 'stale' : 'fresh', asOf: new \DateTimeImmutable(now()->toAtomString()),
            sourceCutoffAt: new \DateTimeImmutable($at->toAtomString()), lagMinutes: $lag,
            sourceLabel: (string) ($registered->source_label ?? 'Laboratory specimen feeds'),
            explanation: $stale ? 'The selected specimen assertions exceed the registered freshness tolerance.' : null,
        ));
    }

    /** @param Collection<int, object> $rows @param array<string, mixed> $coverage @return list<array<string, mixed>> */
    private function serialize(Collection $rows, array $coverage, bool $canViewPatientDetail): array
    {
        if ($rows->isEmpty()) {
            return [];
        }
        $orderIds = $rows->pluck('ancillary_order_id')->map(fn ($id): int => (int) $id)->unique()->all();
        $allSpecimens = DB::table('prod.lab_specimens')->whereIn('ancillary_order_id', $orderIds)->orderBy('lab_specimen_id')->get([
            'lab_specimen_id', 'specimen_uuid', 'ancillary_order_id', 'parent_specimen_id', 'status', 'source_specimen_key',
        ]);
        $specimenIds = $allSpecimens->pluck('lab_specimen_id')->map(fn ($id): int => (int) $id)->all();
        $byId = $allSpecimens->keyBy('lab_specimen_id');
        $roots = $allSpecimens->mapWithKeys(fn (object $specimen): array => [$specimen->lab_specimen_id => $this->rootId($specimen, $byId)]);
        $depths = $allSpecimens->mapWithKeys(fn (object $specimen): array => [$specimen->lab_specimen_id => $this->depth($specimen, $byId)]);
        $byRoot = $allSpecimens->groupBy(fn (object $specimen): int => (int) $roots[$specimen->lab_specimen_id]);
        $representatives = $byRoot->map(fn (Collection $chain): object => $chain->sortByDesc(
            fn (object $specimen): string => sprintf('%05d-%010d', (int) $depths[$specimen->lab_specimen_id], (int) $specimen->lab_specimen_id),
        )->first());
        $results = DB::table('prod.lab_results as r')->join('hosp_ref.lab_test_catalog as c', 'c.lab_test_catalog_id', '=', 'r.lab_test_catalog_id')
            ->whereIn('r.lab_specimen_id', $specimenIds)->orderBy('r.lab_result_id')->get([
                'r.lab_result_id', 'r.result_uuid', 'r.lab_specimen_id', 'r.result_status', 'r.result_stage', 'r.abnormal_flag',
                'r.auto_verified', 'r.is_critical', 'r.resulted_at', 'r.verified_at', 'r.corrected_at', 'c.label as test_label', 'c.decision_class', 'r.metadata',
            ])->groupBy('lab_specimen_id');
        $decisions = DB::table('prod.lab_results as r')->join('hosp_ref.lab_test_catalog as c', 'c.lab_test_catalog_id', '=', 'r.lab_test_catalog_id')
            ->whereIn('r.ancillary_order_id', $orderIds)->where('c.decision_class', '!=', 'none')->whereNull('r.verified_at')
            ->orderByDesc('r.lab_result_id')->get(['r.ancillary_order_id', 'r.lab_specimen_id', 'r.metadata'])->unique('ancillary_order_id')->keyBy('ancillary_order_id');

        return $rows->map(function (object $row) use ($coverage, $canViewPatientDetail, $byId, $byRoot, $roots, $depths, $representatives, $results, $decisions): array {
            $rootId = (int) $roots[$row->lab_specimen_id];
            $chain = $byRoot[$rootId]->sortBy(fn (object $item): array => [(int) $depths[$item->lab_specimen_id], (int) $item->lab_specimen_id])->values();
            $representative = $representatives[$rootId];
            $decision = $decisions->get($row->ancillary_order_id);
            $decisionRoot = $decision?->lab_specimen_id === null ? null : ($roots[$decision->lab_specimen_id] ?? null);
            $decisionRepresentative = $decisionRoot !== null ? $representatives[(int) $decisionRoot] : null;
            $decisionMetadata = $decision === null ? [] : (json_decode($decision->metadata ?? '{}', true) ?: []);
            $specimenResults = $results->get($row->lab_specimen_id, collect());
            $latest = $specimenResults->last();
            $orderMetadata = json_decode($row->order_metadata ?? '{}', true) ?: [];

            return [
                'specimenUuid' => $row->specimen_uuid,
                'orderUuid' => $row->order_uuid,
                'accessionIdentity' => ['sourceSpecimenKey' => $row->source_specimen_key, 'sourceAccessionKey' => $row->source_accession_key, 'sourceKey' => $row->source_key],
                'patientRef' => $canViewPatientDetail ? ($row->patient_ref ?: 'Pseudonymous patient unavailable') : 'Patient context restricted',
                'patientClass' => $row->patient_class, 'priority' => $row->priority,
                'testFamily' => $orderMetadata['test_family'] ?? null, 'unitLabel' => $row->unit_name,
                'specimenType' => $row->specimen_type, 'containerType' => $row->container_type,
                'collectorRole' => $row->collector_role, 'collectionMethod' => $row->collection_method,
                'status' => $row->status, 'rejectionReasonCode' => $row->rejection_reason_code,
                'ageMinutes' => max(0, (int) floor((float) $row->age_minutes)),
                'timeline' => $this->timeline($row, $latest, $coverage['transport']['columnVisible']),
                'result' => $latest === null ? null : [
                    'resultUuid' => $latest->result_uuid, 'testLabel' => $latest->test_label,
                    'status' => $latest->result_status, 'stage' => $latest->result_stage, 'abnormalFlag' => $latest->abnormal_flag,
                    'autoVerified' => (bool) $latest->auto_verified, 'critical' => (bool) $latest->is_critical,
                    'resultedAt' => $this->iso($latest->resulted_at), 'verifiedAt' => $this->iso($latest->verified_at),
                    'correctedAt' => $this->iso($latest->corrected_at), 'versionCount' => $specimenResults->count(),
                ],
                'chain' => [
                    'rootSpecimenUuid' => $byId[$rootId]->specimen_uuid,
                    'depth' => (int) $depths[$row->lab_specimen_id],
                    'position' => $chain->search(fn (object $item): bool => (int) $item->lab_specimen_id === (int) $row->lab_specimen_id) + 1,
                    'length' => $chain->count(),
                    'parentSpecimenUuid' => $row->parent_specimen_id === null ? null : $byId->get($row->parent_specimen_id)?->specimen_uuid,
                    'childSpecimenUuids' => $chain->where('parent_specimen_id', $row->lab_specimen_id)->pluck('specimen_uuid')->values()->all(),
                    'representativeSpecimenUuid' => $representative->specimen_uuid,
                ],
                'downstreamImpact' => $decisionRepresentative !== null && (int) $decisionRepresentative->lab_specimen_id === (int) $row->lab_specimen_id
                    ? ($decisionMetadata['decision_context'] ?? null) : null,
                'decisionRepresentedBySpecimenUuid' => $decisionRepresentative?->specimen_uuid,
                'sourceCutoffAt' => $this->iso($row->source_cutoff_at),
            ];
        })->all();
    }

    /** @param Collection<int, object> $byId */
    private function rootId(object $specimen, Collection $byId): int
    {
        $current = $specimen;
        $seen = [];
        while ($current->parent_specimen_id !== null && isset($byId[$current->parent_specimen_id]) && ! isset($seen[$current->parent_specimen_id])) {
            $seen[$current->lab_specimen_id] = true;
            $current = $byId[$current->parent_specimen_id];
        }

        return (int) $current->lab_specimen_id;
    }

    /** @param Collection<int, object> $byId */
    private function depth(object $specimen, Collection $byId): int
    {
        $depth = 0;
        $current = $specimen;
        $seen = [];
        while ($current->parent_specimen_id !== null && isset($byId[$current->parent_specimen_id]) && ! isset($seen[$current->parent_specimen_id])) {
            $seen[$current->lab_specimen_id] = true;
            $depth++;
            $current = $byId[$current->parent_specimen_id];
        }

        return $depth;
    }

    /** @return list<array<string, mixed>> */
    private function timeline(object $row, ?object $latest, bool $transportVisible): array
    {
        $stages = [
            ['code' => 'ordered', 'label' => 'Ordered', 'at' => $row->ordered_at, 'state' => 'complete'],
            ['code' => 'collected', 'label' => 'Collected', 'at' => $row->collected_at, 'state' => $row->collected_at === null ? 'pending' : 'complete'],
        ];
        if ($transportVisible) {
            $stages[] = ['code' => 'in_transit', 'label' => 'In transit', 'at' => $row->in_transit_at, 'state' => $row->in_transit_at === null ? 'not_asserted' : 'complete'];
        }
        $stages[] = ['code' => 'received', 'label' => 'Received', 'at' => $row->received_at, 'state' => $row->received_at === null ? 'pending' : 'complete'];
        if ($row->rejected_at !== null) {
            $stages[] = ['code' => 'rejected', 'label' => 'Rejected', 'at' => $row->rejected_at, 'state' => 'exception'];
        }
        if ($row->recollect_ordered_at !== null) {
            $stages[] = ['code' => 'recollect_ordered', 'label' => 'Recollect ordered', 'at' => $row->recollect_ordered_at, 'state' => 'exception'];
        }
        if ($latest !== null) {
            $stages[] = ['code' => 'resulted', 'label' => 'Resulted', 'at' => $latest->resulted_at, 'state' => $latest->resulted_at === null ? 'pending' : 'complete'];
            $stages[] = ['code' => 'verified', 'label' => 'Verified', 'at' => $latest->verified_at, 'state' => $latest->verified_at === null ? 'pending' : 'complete'];
        }

        return array_map(fn (array $stage): array => [...$stage, 'at' => $this->iso($stage['at'])], $stages);
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toAtomString();
    }
}
