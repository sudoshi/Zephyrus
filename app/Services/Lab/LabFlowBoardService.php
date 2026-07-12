<?php

namespace App\Services\Lab;

use App\Data\Ancillary\FreshnessEnvelope;
use App\Models\Ancillary\AncillarySlaDefinition;
use App\Services\Ancillary\AncillaryContractSerializer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class LabFlowBoardService
{
    public const LENSES = ['all', 'ed', 'inpatient', 'discharge_gate', 'or_gate', 'degraded'];

    public const PRIORITIES = ['stat', 'urgent', 'routine', 'timed', 'discharge'];

    public function __construct(private readonly AncillaryContractSerializer $contracts) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function build(array $filters = [], bool $canAnnotateBarriers = false, bool $canViewPatientDetail = true): array
    {
        $filters = $this->filters($filters);
        $orderIds = $this->baseQuery($filters)->pluck('o.ancillary_order_id');
        $sourceCutoff = $this->baseQuery($filters)->max('o.source_cutoff_at');
        $freshness = $this->freshness($sourceCutoff === null ? null : CarbonImmutable::parse($sourceCutoff));
        $coverage = $this->coverage($orderIds->all());
        $summary = $this->summary($orderIds->all());
        $state = $this->state($summary, $freshness, $coverage);

        return [
            'generatedAt' => now()->toAtomString(),
            'sourceCutoffAt' => $sourceCutoff === null ? null : CarbonImmutable::parse($sourceCutoff)->toAtomString(),
            'state' => $state,
            'stateMessage' => $this->stateMessage($state),
            'freshness' => $freshness,
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'summary' => $summary,
            'coverage' => $coverage,
            'stageDistribution' => $this->stageDistribution($orderIds->all()),
            'tat' => $this->tat($orderIds->all(), $coverage),
            'criticalCallbacks' => $this->criticalCallbacks($orderIds->all()),
            'qualityStrip' => $this->qualityStrip($orderIds->all()),
            'oldestItems' => $this->oldestItems($orderIds->all(), $canViewPatientDetail),
            'barrierPareto' => $this->barrierPareto($orderIds->all()),
            'barrierReasons' => $this->barrierReasons(),
            'definitions' => AncillarySlaDefinition::query()->activeAt(now())->where('department', 'lab')->orderBy('metric_key')
                ->get()->map(fn (AncillarySlaDefinition $definition): array => $this->contracts->slaDefinition($definition))->values()->all(),
            'canAnnotateBarriers' => $canAnnotateBarriers,
            'canViewPatientDetail' => $canViewPatientDetail,
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function filters(array $input): array
    {
        $lens = is_string($input['lens'] ?? null) && in_array($input['lens'], self::LENSES, true) ? $input['lens'] : 'all';
        $priority = is_string($input['priority'] ?? null) && in_array($input['priority'], self::PRIORITIES, true) ? $input['priority'] : null;
        $testFamily = is_string($input['testFamily'] ?? null) && preg_match('/^[a-z0-9_]{1,80}$/', $input['testFamily']) ? $input['testFamily'] : null;
        $unitId = filter_var($input['unitId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $shift = is_string($input['shift'] ?? null) && in_array($input['shift'], ['am_draw', 'day', 'evening', 'night'], true) ? $input['shift'] : null;

        return ['lens' => $lens, 'priority' => $priority, 'testFamily' => $testFamily, 'unitId' => $unitId === false ? null : $unitId, 'shift' => $shift];
    }

    /** @return array<string, mixed> */
    private function filterOptions(): array
    {
        return [
            'lenses' => self::LENSES,
            'priorities' => DB::table('prod.ancillary_orders')->where('department', 'lab')->distinct()->orderBy('priority')->pluck('priority')->all(),
            'testFamilies' => DB::table('hosp_ref.lab_test_catalog')->whereNotIn('department', ['pathology', 'blood_bank'])->where('is_active', true)->distinct()->orderBy('test_family')->pluck('test_family')->all(),
            'units' => DB::table('prod.units as u')->join('prod.ancillary_orders as o', 'o.unit_id', '=', 'u.unit_id')->where('o.department', 'lab')->where('u.is_deleted', false)->distinct()->orderBy('u.name')->get(['u.unit_id', 'u.name'])->map(fn (object $row): array => ['unitId' => (int) $row->unit_id, 'label' => $row->name])->all(),
            'shifts' => ['am_draw', 'day', 'evening', 'night'],
        ];
    }

    private function baseQuery(array $filters): Builder
    {
        return DB::table('prod.ancillary_orders as o')
            ->where('o.department', 'lab')
            ->where('o.ordered_at', '>=', now()->subDay())
            ->whereRaw("COALESCE(o.metadata->>'operational_window', 'current') <> 'historical_study_only'")
            ->when($filters['priority'], fn (Builder $query, string $priority): Builder => $query->where('o.priority', $priority))
            ->when($filters['testFamily'], fn (Builder $query, string $family): Builder => $query->whereRaw("o.metadata->>'test_family' = ?", [$family]))
            ->when($filters['unitId'], fn (Builder $query, int $unitId): Builder => $query->where('o.unit_id', $unitId))
            ->when($filters['shift'], fn (Builder $query, string $shift): Builder => $query->whereRaw("o.metadata->>'demo_shift' = ?", [$shift]))
            ->when($filters['lens'] === 'ed', fn (Builder $query): Builder => $query->where('o.patient_class', 'emergency'))
            ->when($filters['lens'] === 'inpatient', fn (Builder $query): Builder => $query->where('o.patient_class', 'inpatient'))
            ->when($filters['lens'] === 'discharge_gate', fn (Builder $query): Builder => $query->whereExists(fn ($pending) => $this->pendingDecisionExists($pending, 'discharge_gate')))
            ->when($filters['lens'] === 'or_gate', fn (Builder $query): Builder => $query->whereExists(fn ($pending) => $this->pendingDecisionExists($pending, 'or_gate')))
            ->when($filters['lens'] === 'degraded', fn (Builder $query): Builder => $query->where(function (Builder $degraded): void {
                $degraded->whereNotExists(fn ($q) => $q->selectRaw('1')->from('prod.lab_specimens as ds')->whereColumn('ds.ancillary_order_id', 'o.ancillary_order_id'))
                    ->orWhereNotExists(fn ($q) => $q->selectRaw('1')->from('prod.ancillary_milestones as dt')->whereColumn('dt.ancillary_order_id', 'o.ancillary_order_id')->where('dt.milestone_code', 'LAB_IN_TRANSIT'))
                    ->orWhereNotExists(fn ($q) => $q->selectRaw('1')->from('prod.ancillary_milestones as dm')->whereColumn('dm.ancillary_order_id', 'o.ancillary_order_id')->where('dm.milestone_code', 'LAB_ANALYSIS_STARTED'));
            }));
    }

    private function pendingDecisionExists(Builder $query, string $decisionClass): Builder
    {
        return $query->selectRaw('1')->from('prod.lab_results as dr')->join('hosp_ref.lab_test_catalog as dc', 'dc.lab_test_catalog_id', '=', 'dr.lab_test_catalog_id')
            ->whereColumn('dr.ancillary_order_id', 'o.ancillary_order_id')->where('dc.decision_class', $decisionClass)->whereNull('dr.verified_at');
    }

    /** @param list<int> $ids @return array<string, mixed> */
    private function summary(array $ids): array
    {
        if ($ids === []) {
            return ['currentOrders' => 0, 'openOrders' => 0, 'statOrders' => 0, 'statCompliant' => 0, 'statCompliancePercent' => null, 'pendingDecisions' => 0, 'openCriticalCallbacks' => 0, 'degradedOrders' => 0];
        }
        $row = DB::table('prod.ancillary_orders as o')->whereIn('o.ancillary_order_id', $ids)->selectRaw("count(*) AS current_orders,
            count(*) FILTER (WHERE o.current_milestone_code NOT IN ('LAB_VERIFIED', 'LAB_CANCELLED')) AS open_orders,
            count(*) FILTER (WHERE o.priority = 'stat') AS stat_orders,
            count(*) FILTER (WHERE o.priority = 'stat' AND EXISTS (SELECT 1 FROM prod.ancillary_current_assertions v WHERE v.ancillary_order_id = o.ancillary_order_id AND v.milestone_code = 'LAB_VERIFIED' AND EXTRACT(EPOCH FROM (v.occurred_at - o.ordered_at)) / 60 <= 60)) AS stat_compliant,
            count(*) FILTER (WHERE NOT EXISTS (SELECT 1 FROM prod.lab_specimens s WHERE s.ancillary_order_id = o.ancillary_order_id)
              OR NOT EXISTS (SELECT 1 FROM prod.ancillary_milestones t WHERE t.ancillary_order_id = o.ancillary_order_id AND t.milestone_code = 'LAB_IN_TRANSIT')
              OR NOT EXISTS (SELECT 1 FROM prod.ancillary_milestones m WHERE m.ancillary_order_id = o.ancillary_order_id AND m.milestone_code = 'LAB_ANALYSIS_STARTED')) AS degraded_orders")->first();
        $statOrders = (int) $row->stat_orders;

        return [
            'currentOrders' => (int) $row->current_orders,
            'openOrders' => (int) $row->open_orders,
            'statOrders' => $statOrders,
            'statCompliant' => (int) $row->stat_compliant,
            'statCompliancePercent' => $statOrders === 0 ? null : round(((int) $row->stat_compliant / $statOrders) * 100, 1),
            'pendingDecisions' => DB::table('prod.lab_results as r')->join('hosp_ref.lab_test_catalog as c', 'c.lab_test_catalog_id', '=', 'r.lab_test_catalog_id')->whereIn('r.ancillary_order_id', $ids)->where('c.decision_class', '!=', 'none')->whereNull('r.verified_at')->count(),
            'openCriticalCallbacks' => DB::table('prod.lab_critical_values as c')->join('prod.lab_results as r', 'r.lab_result_id', '=', 'c.lab_result_id')->whereIn('r.ancillary_order_id', $ids)->whereNotIn('c.callback_state', ['acknowledged', 'closed'])->count(),
            'degradedOrders' => (int) $row->degraded_orders,
        ];
    }

    /** @param list<int> $ids @return array<string, mixed> */
    private function coverage(array $ids): array
    {
        $transport = $ids !== [] && DB::table('prod.ancillary_milestones')->whereIn('ancillary_order_id', $ids)->where('milestone_code', 'LAB_IN_TRANSIT')->exists();
        $middleware = $ids !== [] && DB::table('prod.ancillary_milestones')->whereIn('ancillary_order_id', $ids)->where('milestone_code', 'LAB_ANALYSIS_STARTED')->exists();

        return [
            'transport' => ['status' => $transport ? 'available' : 'missing', 'granularity' => $transport ? 'segmented' : 'coarse', 'explanation' => $transport ? 'Collection, transit, and receipt evidence are available.' : 'Transport feed is unavailable; collection-to-receipt remains visible as a coarse clock and transit duration is not reported as zero.'],
            'middleware' => ['status' => $middleware ? 'available' : 'missing', 'granularity' => $middleware ? 'segmented' : 'coarse', 'explanation' => $middleware ? 'Analysis-start middleware evidence is available.' : 'Middleware feed is unavailable; receipt-to-result remains visible as a coarse clock and analysis duration is not reported as zero.'],
        ];
    }

    /** @return array<string, mixed> */
    private function freshness(?CarbonImmutable $cutoff): array
    {
        $registered = DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->first();
        if ($cutoff === null) {
            return $this->contracts->freshness(new FreshnessEnvelope('unknown', new \DateTimeImmutable(now()->toAtomString()), null, null, 'Laboratory operational feeds', 'No Laboratory observations match the selected filters.'));
        }
        $lag = max(0, (int) floor($cutoff->diffInSeconds(now(), false) / 60));
        $status = strtolower((string) ($registered->status ?? 'current'));
        $stale = in_array($status, ['stale', 'error', 'failed', 'unavailable'], true) || $lag > max(1, (int) ($registered->warning_lag_minutes ?? 60));

        return $this->contracts->freshness(new FreshnessEnvelope($stale ? 'stale' : 'fresh', new \DateTimeImmutable(now()->toAtomString()), new \DateTimeImmutable($cutoff->toAtomString()), $lag, (string) ($registered->source_label ?? 'Laboratory operational feeds'), $stale ? 'The selected Laboratory assertions exceed the registered freshness tolerance.' : null));
    }

    /** @param array<string, mixed> $summary @param array<string, mixed> $freshness @param array<string, mixed> $coverage */
    private function state(array $summary, array $freshness, array $coverage): string
    {
        $registered = strtolower((string) (DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->value('status') ?? ''));

        return match (true) {
            in_array($registered, ['error', 'failed', 'unavailable'], true) => 'source_error',
            $summary['currentOrders'] === 0 => 'no_data',
            $freshness['status'] === 'stale' => 'stale',
            $coverage['transport']['status'] === 'missing' || $coverage['middleware']['status'] === 'missing' || $summary['degradedOrders'] > 0 => 'degraded',
            default => 'normal',
        };
    }

    private function stateMessage(string $state): string
    {
        return match ($state) {
            'source_error' => 'Laboratory source health reports an error. Last known operational facts remain visible.',
            'no_data' => 'No current Laboratory orders match the selected filters.',
            'stale' => 'Laboratory facts are stale. Ages remain qualified by the last source cutoff.',
            'degraded' => 'Laboratory coverage is partial; coarse clocks remain visible without fabricated zero-duration segments.',
            default => 'Laboratory operational facts are current.',
        };
    }

    /** @param list<int> $ids @return list<array<string, mixed>> */
    private function stageDistribution(array $ids): array
    {
        return $ids === [] ? [] : DB::table('prod.ancillary_orders')->whereIn('ancillary_order_id', $ids)
            ->selectRaw("COALESCE(current_milestone_code, 'LAB_ORDERED') AS stage, count(*) AS aggregate_count")
            ->groupBy('stage')->orderByDesc('aggregate_count')->orderBy('stage')->get()
            ->map(fn (object $row): array => ['stage' => $row->stage, 'label' => str($row->stage)->after('LAB_')->replace('_', ' ')->title()->toString(), 'count' => (int) $row->aggregate_count])->all();
    }

    /** @param list<int> $ids @param array<string, mixed> $coverage @return array<string, mixed> */
    private function tat(array $ids, array $coverage): array
    {
        $interval = function (string $start, string $end) use ($ids): array {
            if ($ids === []) {
                return ['count' => 0, 'medianMinutes' => null, 'p90Minutes' => null];
            }
            $row = DB::table('prod.lab_specimens as s')->join('prod.lab_results as r', 'r.lab_specimen_id', '=', 's.lab_specimen_id')->whereIn('s.ancillary_order_id', $ids)
                ->whereNotNull($start)->whereNotNull($end)->selectRaw("count(*) AS n, percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM ({$end} - {$start})) / 60) AS median, percentile_cont(0.9) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM ({$end} - {$start})) / 60) AS p90")->first();

            return ['count' => (int) ($row->n ?? 0), 'medianMinutes' => isset($row->median) ? round((float) $row->median, 1) : null, 'p90Minutes' => isset($row->p90) ? round((float) $row->p90, 1) : null];
        };

        return [
            'collectToReceive' => [...$interval('s.collected_at', 's.received_at'), 'granularity' => $coverage['transport']['granularity'], 'definition' => 'First specimen collection to accession receipt. Transit is shown only when transport evidence exists.'],
            'receiveToResult' => [...$interval('s.received_at', 'r.resulted_at'), 'granularity' => $coverage['middleware']['granularity'], 'definition' => 'Specimen receipt to operational result. Analysis duration is shown only when middleware evidence exists.'],
        ];
    }

    /** @param list<int> $ids @return array<string, mixed> */
    private function criticalCallbacks(array $ids): array
    {
        if ($ids === []) {
            return ['total' => 0, 'open' => 0, 'oldestOpenAgeMinutes' => null, 'byState' => []];
        }
        $base = DB::table('prod.lab_critical_values as c')->join('prod.lab_results as r', 'r.lab_result_id', '=', 'c.lab_result_id')->whereIn('r.ancillary_order_id', $ids);
        $oldest = (clone $base)->whereNotIn('c.callback_state', ['acknowledged', 'closed'])->min('c.identified_at');

        return [
            'total' => (clone $base)->count(),
            'open' => (clone $base)->whereNotIn('c.callback_state', ['acknowledged', 'closed'])->count(),
            'oldestOpenAgeMinutes' => $oldest === null ? null : max(0, (int) floor(CarbonImmutable::parse($oldest)->diffInSeconds(now(), false) / 60)),
            'byState' => (clone $base)->selectRaw('c.callback_state AS state, count(*) AS aggregate_count')->groupBy('c.callback_state')->orderBy('c.callback_state')->get()->map(fn (object $row): array => ['state' => $row->state, 'count' => (int) $row->aggregate_count])->all(),
        ];
    }

    /** @param list<int> $ids @return list<array<string, mixed>> */
    private function qualityStrip(array $ids): array
    {
        $denominator = $ids === [] ? 0 : DB::table('prod.lab_specimens')->whereIn('ancillary_order_id', $ids)->whereNotNull('collected_at')->count();
        $metric = function (string $key, string $label, array $reasons, string $kind, string $referenceLabel) use ($ids, $denominator): array {
            $count = $ids === [] ? 0 : DB::table('prod.lab_specimens')->whereIn('ancillary_order_id', $ids)->whereIn('rejection_reason_code', $reasons)->count();

            return ['key' => $key, 'label' => $label, 'count' => $count, 'denominator' => $denominator, 'ratePercent' => $denominator === 0 ? null : round(($count / $denominator) * 100, 1), 'reference' => ['kind' => $kind, 'label' => $referenceLabel, 'valuePercent' => null, 'source' => 'Not configured; observed values are not judged against an invented threshold.']];
        };

        return [
            $metric('rejection', 'Specimen rejection', ['CLOTTED', 'HEMOLYZED', 'CONTAMINATED'], 'benchmark', 'External benchmark not configured'),
            $metric('hemolysis', 'Hemolysis', ['HEMOLYZED'], 'benchmark', 'External benchmark not configured'),
            $metric('contamination', 'Contamination', ['CONTAMINATED'], 'local_policy', 'Site policy not configured'),
        ];
    }

    /** @param list<int> $ids @return list<array<string, mixed>> */
    private function oldestItems(array $ids, bool $canViewPatientDetail): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('prod.ancillary_orders as o')->leftJoin('prod.units as u', 'u.unit_id', '=', 'o.unit_id')->whereIn('o.ancillary_order_id', $ids)
            ->whereNotIn('o.current_milestone_code', ['LAB_VERIFIED', 'LAB_CANCELLED'])->orderBy('o.ordered_at')->limit(8)
            ->select(['o.ancillary_order_id', 'o.order_uuid', 'o.encounter_id', 'o.patient_ref', 'o.patient_class', 'o.priority', 'o.ordered_at', 'o.current_milestone_code', 'o.metadata', 'u.name as unit_name'])
            ->selectSub(function ($query): void {
                $query->from('prod.lab_results as r')->join('hosp_ref.lab_test_catalog as c', 'c.lab_test_catalog_id', '=', 'r.lab_test_catalog_id')
                    ->whereColumn('r.ancillary_order_id', 'o.ancillary_order_id')->where('c.decision_class', '!=', 'none')->whereNull('r.verified_at')
                    ->orderByDesc('r.lab_result_id')->limit(1)->select('r.metadata');
            }, 'decision_metadata')
            ->selectSub(function ($query): void {
                $query->from('prod.barriers as b')->whereColumn('b.encounter_id', 'o.encounter_id')->where('b.status', 'open')->where('b.is_deleted', false)->selectRaw('count(*)');
            }, 'barrier_count')
            ->get()
            ->map(function (object $row) use ($canViewPatientDetail): array {
                $metadata = json_decode($row->metadata ?? '{}', true) ?: [];
                $decisionMetadata = is_string($row->decision_metadata) ? json_decode($row->decision_metadata, true) : (array) $row->decision_metadata;

                return [
                    'orderUuid' => $row->order_uuid,
                    'label' => str((string) ($metadata['test_family'] ?? 'laboratory'))->replace('_', ' ')->title()->append(' order')->toString(),
                    'patientRef' => $canViewPatientDetail ? ($row->patient_ref ?: 'Pseudonymous patient unavailable') : 'Patient context restricted',
                    'patientClass' => $row->patient_class, 'priority' => $row->priority, 'testFamily' => $metadata['test_family'] ?? null,
                    'locationLabel' => $row->unit_name, 'currentStage' => $row->current_milestone_code,
                    'ageMinutes' => max(0, (int) floor(CarbonImmutable::parse($row->ordered_at)->diffInSeconds(now(), false) / 60)),
                    'encounterLinked' => $row->encounter_id !== null,
                    'decisionContext' => $decisionMetadata['decision_context'] ?? null,
                    'barrierCount' => (int) $row->barrier_count,
                ];
            })->all();
    }

    /** @param list<int> $ids @return list<array<string, mixed>> */
    private function barrierPareto(array $ids): array
    {
        return $ids === [] ? [] : DB::table('prod.ancillary_orders as o')->join('prod.barriers as b', fn ($join) => $join->on('b.encounter_id', '=', 'o.encounter_id')->where('b.status', 'open')->where('b.is_deleted', false))->leftJoin('hosp_ref.ancillary_barrier_reasons as r', 'r.reason_code', '=', 'b.reason_code')->whereIn('o.ancillary_order_id', $ids)->selectRaw("COALESCE(b.reason_code, 'UNSPECIFIED') AS reason_code, COALESCE(r.label, 'Unspecified barrier') AS label, count(DISTINCT b.barrier_id) AS aggregate_count")->groupBy('b.reason_code', 'r.label')->orderByDesc('aggregate_count')->limit(5)->get()->map(fn (object $row): array => ['reasonCode' => $row->reason_code, 'label' => $row->label, 'count' => (int) $row->aggregate_count])->all();
    }

    /** @return list<array<string, mixed>> */
    private function barrierReasons(): array
    {
        return DB::table('hosp_ref.ancillary_barrier_reasons')->where('department', 'lab')->where('is_active', true)->orderBy('label')->get(['reason_code', 'category', 'label'])->map(fn (object $row): array => ['reasonCode' => $row->reason_code, 'category' => $row->category, 'label' => $row->label])->all();
    }
}
