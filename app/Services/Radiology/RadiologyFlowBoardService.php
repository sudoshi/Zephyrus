<?php

namespace App\Services\Radiology;

use App\Data\Ancillary\FreshnessEnvelope;
use App\Models\Ancillary\AncillarySlaDefinition;
use App\Services\Ancillary\AncillaryContractSerializer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class RadiologyFlowBoardService
{
    public const LENSES = ['all', 'ed', 'inpatient', 'discharge', 'degraded'];

    public function __construct(private readonly AncillaryContractSerializer $contracts) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function build(array $filters = [], bool $canAnnotateBarriers = false): array
    {
        $filters = $this->filters($filters);
        $thresholds = $this->thresholds();
        $summary = $this->summary($filters);
        $freshness = $this->freshness($summary['sourceCutoffAt']);
        $state = $this->boardState($summary, $freshness);

        return [
            'generatedAt' => now()->toAtomString(),
            'sourceCutoffAt' => $summary['sourceCutoffAt']?->toAtomString(),
            'state' => $state,
            'stateMessage' => $this->stateMessage($state),
            'freshness' => $freshness,
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'summary' => [
                'openOrders' => $summary['openOrders'],
                'openBreaches' => $summary['openBreaches'],
                'dischargeBlocking' => $summary['dischargeBlocking'],
                'degradedOrders' => $summary['degradedOrders'],
            ],
            'thresholds' => $thresholds,
            'heatmap' => $this->heatmap($filters, $state, $thresholds),
            'oldestItems' => $this->oldestItems($filters, $thresholds),
            'worklistHref' => '/radiology/worklist?'.http_build_query(array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== 'all')),
            'barrierPareto' => $this->barrierPareto($filters),
            'barrierReasons' => $this->barrierReasons(),
            'scanners' => $this->scanners(),
            'canAnnotateBarriers' => $canAnnotateBarriers,
        ];
    }

    /**
     * Aggregate-only contract for Cockpit consumers. Item detail remains in
     * the Radiology workspace; this method reuses the same summary, freshness,
     * and scanner-state calculations as the Flow Board.
     *
     * @return array<string, mixed>
     */
    public function cockpitHealth(): array
    {
        $summary = $this->summary($this->filters([]));
        $freshness = $this->freshness($summary['sourceCutoffAt']);
        $boardState = $this->boardState($summary, $freshness);
        $scanners = $this->scannerHealth();

        return [
            'openBreaches' => $summary['openBreaches'],
            'scannersDown' => $scanners['down'],
            'scannerTotal' => $scanners['total'],
            'sourceState' => match ($boardState) {
                'source_error' => 'error',
                'stale' => 'stale',
                'degraded' => 'degraded',
                'no_data' => 'missing',
                default => 'fresh',
            },
            'sourceCutoffAt' => $summary['sourceCutoffAt']?->toAtomString(),
            'scannerSourceCutoffAt' => $scanners['sourceCutoffAt'],
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function filters(array $filters): array
    {
        $lens = is_string($filters['lens'] ?? null) && in_array($filters['lens'], self::LENSES, true)
            ? $filters['lens'] : 'all';
        $priority = is_string($filters['priority'] ?? null) && in_array($filters['priority'], ['stat', 'urgent', 'routine', 'discharge'], true)
            ? $filters['priority'] : null;
        $modality = is_string($filters['modality'] ?? null) && preg_match('/^[A-Z0-9_]{1,16}$/', $filters['modality'])
            ? $filters['modality'] : null;
        $unitId = filter_var($filters['unitId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return [
            'lens' => $lens,
            'priority' => $priority,
            'modality' => $modality,
            'unitId' => $unitId === false ? null : $unitId,
        ];
    }

    /** @return array<string, mixed> */
    private function filterOptions(): array
    {
        return [
            'lenses' => self::LENSES,
            'priorities' => DB::table('prod.ancillary_orders')->where('department', 'rad')->whereNull('terminal_at')->distinct()->orderBy('priority')->pluck('priority')->all(),
            'modalities' => DB::table('hosp_ref.rad_modalities')->where('is_active', true)->orderBy('code')->get(['code', 'label'])->map(fn (object $row): array => ['code' => $row->code, 'label' => $row->label])->all(),
            'units' => DB::table('prod.units as u')->join('prod.ancillary_orders as o', 'o.unit_id', '=', 'u.unit_id')->where('o.department', 'rad')->whereNull('o.terminal_at')->where('u.is_deleted', false)->distinct()->orderBy('u.name')->get(['u.unit_id', 'u.name'])->map(fn (object $row): array => ['unitId' => (int) $row->unit_id, 'label' => $row->name])->all(),
        ];
    }

    /** @param array<string, mixed> $filters @return array{openOrders:int,openBreaches:int,dischargeBlocking:int,degradedOrders:int,sourceCutoffAt:?CarbonImmutable} */
    private function summary(array $filters): array
    {
        $query = $this->baseQuery($filters);
        $row = $query->selectRaw("count(DISTINCT o.ancillary_order_id) AS open_orders,
                count(DISTINCT o.ancillary_order_id) FILTER (WHERE EXISTS (
                    SELECT 1 FROM prod.ancillary_breaches b WHERE b.ancillary_order_id = o.ancillary_order_id AND b.status = 'open'
                )) AS open_breaches,
                count(DISTINCT o.ancillary_order_id) FILTER (WHERE o.priority = 'discharge' OR COALESCE((o.metadata->>'discharge_blocking')::boolean, false)) AS discharge_blocking,
                count(DISTINCT o.ancillary_order_id) FILTER (WHERE x.rad_exam_id IS NULL OR x.modality_code IS NULL OR jsonb_array_length(COALESCE(x.metadata->'degraded_fields', '[]'::jsonb)) > 0 OR NOT EXISTS (
                    SELECT 1 FROM prod.ancillary_current_assertions a WHERE a.ancillary_order_id = o.ancillary_order_id AND a.milestone_code = 'RAD_EXAM_END'
                )) AS degraded_orders,
                max(o.source_cutoff_at) AS source_cutoff_at")
            ->first();

        return [
            'openOrders' => (int) ($row->open_orders ?? 0),
            'openBreaches' => (int) ($row->open_breaches ?? 0),
            'dischargeBlocking' => (int) ($row->discharge_blocking ?? 0),
            'degradedOrders' => (int) ($row->degraded_orders ?? 0),
            'sourceCutoffAt' => isset($row->source_cutoff_at) ? CarbonImmutable::parse($row->source_cutoff_at) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function freshness(?CarbonImmutable $sourceCutoffAt): array
    {
        $registered = DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->first();
        if ($sourceCutoffAt === null) {
            return $this->contracts->freshness(new FreshnessEnvelope(
                status: 'unknown', asOf: new \DateTimeImmutable(now()->toAtomString()), sourceCutoffAt: null,
                lagMinutes: null, sourceLabel: 'Radiology operational feeds', explanation: 'No Radiology order observations are available.',
            ));
        }

        $lag = max(0, (int) floor($sourceCutoffAt->diffInSeconds(now(), false) / 60));
        $warning = max(1, (int) ($registered->warning_lag_minutes ?? 60));
        $registeredStatus = strtolower((string) ($registered->status ?? 'current'));
        $sourceError = in_array($registeredStatus, ['error', 'failed', 'unavailable'], true);
        $stale = $sourceError || $lag > $warning || $registeredStatus === 'stale';

        return $this->contracts->freshness(new FreshnessEnvelope(
            status: $stale ? 'stale' : 'fresh',
            asOf: new \DateTimeImmutable(now()->toAtomString()),
            sourceCutoffAt: new \DateTimeImmutable($sourceCutoffAt->toAtomString()),
            lagMinutes: $lag,
            sourceLabel: (string) ($registered->source_label ?? 'Radiology operational feeds'),
            explanation: $sourceError ? 'The registered ancillary source reports an error.' : ($stale ? 'The latest selected Radiology assertion exceeds its freshness tolerance.' : null),
        ));
    }

    /** @param array<string, mixed> $summary @param array<string, mixed> $freshness */
    private function boardState(array $summary, array $freshness): string
    {
        $registeredStatus = strtolower((string) (DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->value('status') ?? ''));

        return match (true) {
            in_array($registeredStatus, ['error', 'failed', 'unavailable'], true) => 'source_error',
            $summary['openOrders'] === 0 => 'no_data',
            $freshness['status'] === 'stale' => 'stale',
            $summary['degradedOrders'] > 0 => 'degraded',
            default => 'normal',
        };
    }

    private function stateMessage(string $state): string
    {
        return match ($state) {
            'source_error' => 'Radiology source health reports an error. Last known operational facts remain visible.',
            'no_data' => 'No open Radiology orders match the selected lens.',
            'stale' => 'Radiology facts are stale. Ages are shown from the last source cutoff.',
            'degraded' => 'Some Radiology orders lack optional modality or milestone evidence.',
            default => 'Radiology operational facts are current.',
        };
    }

    /** @return array<string, mixed> */
    private function thresholds(): array
    {
        $definitions = AncillarySlaDefinition::query()->activeAt(now())->where('department', 'rad')
            ->where(fn ($query) => $query->whereNotNull('warning_minutes')->orWhereNotNull('breach_minutes'))
            ->orderBy('metric_key')->get();
        $warning = $definitions->whereNotNull('warning_minutes')->min('warning_minutes');
        $breach = $definitions->whereNotNull('breach_minutes')->min('breach_minutes');

        return [
            'warningMinutes' => $warning === null ? null : (int) $warning,
            'breachMinutes' => $breach === null ? null : (int) $breach,
            'definitions' => $definitions->map(fn (AncillarySlaDefinition $definition): array => $this->contracts->slaDefinition($definition))->values()->all(),
        ];
    }

    /** @param array<string, mixed> $filters @param array<string, mixed> $thresholds @return list<array<string, mixed>> */
    private function heatmap(array $filters, string $state, array $thresholds): array
    {
        if ($state === 'no_data') {
            return [];
        }

        $rows = $this->baseQuery($filters)
            ->selectRaw("COALESCE(x.modality_code, 'UNKNOWN') AS modality,
                CASE
                    WHEN EXTRACT(EPOCH FROM (?::timestamptz - o.ordered_at)) / 60 < 30 THEN '0-29'
                    WHEN EXTRACT(EPOCH FROM (?::timestamptz - o.ordered_at)) / 60 < 60 THEN '30-59'
                    WHEN EXTRACT(EPOCH FROM (?::timestamptz - o.ordered_at)) / 60 < 120 THEN '60-119'
                    ELSE '120+'
                END AS age_bucket,
                count(DISTINCT o.ancillary_order_id) AS aggregate_count,
                bool_or(EXISTS (SELECT 1 FROM prod.ancillary_breaches b WHERE b.ancillary_order_id = o.ancillary_order_id AND b.status = 'open')) AS breached",
                [now(), now(), now()])
            ->groupByRaw("COALESCE(x.modality_code, 'UNKNOWN'), age_bucket")
            ->orderBy('modality')->orderBy('age_bucket')->get();

        $labels = ['0-29' => '0–29 min', '30-59' => '30–59 min', '60-119' => '60–119 min', '120+' => '120+ min'];
        $warning = $thresholds['warningMinutes'];
        $breach = $thresholds['breachMinutes'];

        return $rows->map(function (object $row) use ($labels, $state, $warning, $breach): array {
            $bucketFloor = ['0-29' => 0, '30-59' => 30, '60-119' => 60, '120+' => 120][$row->age_bucket];
            $cellState = match (true) {
                $state === 'source_error' => 'no_data',
                (bool) $row->breached || ($breach !== null && $bucketFloor >= $breach) => 'breach',
                $warning !== null && $bucketFloor >= $warning => 'warning',
                default => 'normal',
            };

            return [
                'key' => strtolower($row->modality).'-'.$row->age_bucket,
                'rowLabel' => $row->modality === 'UNKNOWN' ? 'Modality unavailable' : $row->modality,
                'columnLabel' => $labels[$row->age_bucket],
                'count' => $state === 'source_error' ? null : (int) $row->aggregate_count,
                'state' => $cellState,
            ];
        })->all();
    }

    /** @param array<string, mixed> $filters @param array<string, mixed> $thresholds @return list<array<string, mixed>> */
    private function oldestItems(array $filters, array $thresholds): array
    {
        $rows = $this->baseQuery($filters)
            ->select(['o.ancillary_order_id', 'o.order_uuid', 'o.encounter_id', 'o.patient_ref', 'o.patient_class', 'o.priority', 'o.ordered_at', 'o.source_cutoff_at', 'o.current_state', 'o.current_milestone_code', 'u.name as unit_name', 'x.modality_code', 'x.procedure_label'])
            ->selectRaw('EXTRACT(EPOCH FROM (?::timestamptz - o.ordered_at)) / 60 AS age_minutes', [now()])
            ->selectRaw("EXISTS (SELECT 1 FROM prod.ancillary_breaches b WHERE b.ancillary_order_id = o.ancillary_order_id AND b.status = 'open') AS breached")
            ->selectRaw("(SELECT count(*) FROM prod.barriers br WHERE br.status = 'open' AND br.is_deleted = false AND br.encounter_id = o.encounter_id) AS barrier_count")
            ->orderBy('o.ordered_at')->orderBy('o.ancillary_order_id')->limit(5)->get();

        return $rows->map(function (object $row) use ($thresholds): array {
            $age = max(0, (int) floor((float) $row->age_minutes));
            $status = match (true) {
                (bool) $row->breached || ($thresholds['breachMinutes'] !== null && $age >= $thresholds['breachMinutes']) => 'breach',
                $thresholds['warningMinutes'] !== null && $age >= $thresholds['warningMinutes'] => 'warning',
                $row->modality_code === null => 'degraded',
                default => 'normal',
            };

            return [
                'orderId' => (int) $row->ancillary_order_id,
                'orderUuid' => (string) $row->order_uuid,
                'label' => $row->procedure_label ?: (($row->modality_code ?: 'Unknown modality').' imaging'),
                'patientRef' => $row->patient_ref ?: 'Pseudonymous patient unavailable',
                'patientClass' => (string) $row->patient_class,
                'priority' => (string) $row->priority,
                'modality' => $row->modality_code,
                'locationLabel' => $row->unit_name,
                'currentState' => (string) $row->current_state,
                'currentMilestoneCode' => $row->current_milestone_code,
                'ageMinutes' => $age,
                'status' => $status,
                'barrierCount' => (int) $row->barrier_count,
                'encounterLinked' => $row->encounter_id !== null,
                'sourceCutoffAt' => CarbonImmutable::parse($row->source_cutoff_at)->toAtomString(),
            ];
        })->all();
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    private function barrierPareto(array $filters): array
    {
        return $this->baseQuery($filters)
            ->join('prod.barriers as br', function ($join): void {
                $join->on('br.encounter_id', '=', 'o.encounter_id')->where('br.status', 'open')->where('br.is_deleted', false);
            })
            ->leftJoin('hosp_ref.ancillary_barrier_reasons as rr', 'rr.reason_code', '=', 'br.reason_code')
            ->selectRaw("COALESCE(br.reason_code, 'UNSPECIFIED') AS reason_code, COALESCE(rr.label, 'Unspecified barrier') AS label, count(DISTINCT br.barrier_id) AS aggregate_count")
            ->groupBy('br.reason_code', 'rr.label')->orderByDesc('aggregate_count')->orderBy('label')->limit(5)->get()
            ->map(fn (object $row): array => ['reasonCode' => $row->reason_code, 'label' => $row->label, 'count' => (int) $row->aggregate_count])->all();
    }

    /** @return list<array<string, mixed>> */
    private function barrierReasons(): array
    {
        return DB::table('hosp_ref.ancillary_barrier_reasons')->where('department', 'rad')->where('is_active', true)->orderBy('label')
            ->get(['reason_code', 'category', 'label'])->map(fn (object $row): array => ['reasonCode' => $row->reason_code, 'category' => $row->category, 'label' => $row->label])->all();
    }

    /** @return array<string, mixed> */
    private function scanners(): array
    {
        $currentDowntime = DB::table('prod.rad_scanner_downtimes')
            ->whereIn('status', ['scheduled', 'active'])
            ->where('starts_at', '<=', now())
            ->where(fn ($window) => $window->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->selectRaw('DISTINCT ON (rad_scanner_id) rad_scanner_id, status, reason_code, ends_at')
            ->orderBy('rad_scanner_id')
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('starts_at');
        $rows = DB::table('prod.rad_scanners as s')
            ->leftJoinSub($currentDowntime, 'd', 'd.rad_scanner_id', '=', 's.rad_scanner_id')
            ->where('s.status', '!=', 'retired')
            ->select(['s.scanner_uuid', 's.label', 's.modality_code', 's.status', 's.capacity', 'd.status as downtime_status', 'd.reason_code', 'd.ends_at'])
            ->orderBy('s.modality_code')->orderBy('s.label')->limit(25)->get();

        $items = $rows->map(fn (object $row): array => [
            'scannerUuid' => (string) $row->scanner_uuid,
            'label' => (string) $row->label,
            'modality' => (string) $row->modality_code,
            'capacity' => (int) $row->capacity,
            'state' => $row->downtime_status !== null ? 'downtime' : (string) $row->status,
            'reasonCode' => $row->reason_code,
            'downtimeEndsAt' => $row->ends_at === null ? null : CarbonImmutable::parse($row->ends_at)->toAtomString(),
        ])->all();

        return [
            'total' => count($items),
            'operational' => count(array_filter($items, fn (array $item): bool => $item['state'] === 'operational')),
            'downtime' => count(array_filter($items, fn (array $item): bool => $item['state'] === 'downtime')),
            'items' => $items,
        ];
    }

    /** @return array{total:int,down:int,sourceCutoffAt:?string} */
    private function scannerHealth(): array
    {
        $row = DB::table('prod.rad_scanners as s')
            ->where('s.status', '!=', 'retired')
            ->selectRaw("count(DISTINCT s.rad_scanner_id) AS scanner_total,
                count(DISTINCT s.rad_scanner_id) FILTER (WHERE s.status = 'downtime' OR EXISTS (
                    SELECT 1 FROM prod.rad_scanner_downtimes d
                    WHERE d.rad_scanner_id = s.rad_scanner_id
                      AND d.status IN ('scheduled', 'active')
                      AND d.starts_at <= ?
                      AND (d.ends_at IS NULL OR d.ends_at > ?)
                )) AS scanners_down,
                max(GREATEST(s.updated_at, COALESCE((
                    SELECT max(d.updated_at) FROM prod.rad_scanner_downtimes d
                    WHERE d.rad_scanner_id = s.rad_scanner_id
                      AND d.status IN ('scheduled', 'active')
                      AND d.starts_at <= ?
                      AND (d.ends_at IS NULL OR d.ends_at > ?)
                ), s.updated_at))) AS scanner_source_cutoff_at", [now(), now(), now(), now()])
            ->first();

        return [
            'total' => (int) ($row->scanner_total ?? 0),
            'down' => (int) ($row->scanners_down ?? 0),
            'sourceCutoffAt' => isset($row->scanner_source_cutoff_at)
                ? CarbonImmutable::parse($row->scanner_source_cutoff_at)->toAtomString()
                : null,
        ];
    }

    /** @param array<string, mixed> $filters */
    private function baseQuery(array $filters): Builder
    {
        $query = DB::table('prod.ancillary_orders as o')
            ->leftJoin('prod.rad_exams as x', 'x.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->leftJoin('prod.units as u', 'u.unit_id', '=', 'o.unit_id')
            ->where('o.department', 'rad')->whereNull('o.terminal_at');

        match ($filters['lens']) {
            'ed' => $query->where('o.patient_class', 'emergency'),
            'inpatient' => $query->where('o.patient_class', 'inpatient'),
            'discharge' => $query->where(fn ($lens) => $lens->where('o.priority', 'discharge')->orWhereRaw("COALESCE((o.metadata->>'discharge_blocking')::boolean, false) = true")),
            'degraded' => $query->where(fn ($lens) => $lens->whereNull('x.rad_exam_id')->orWhereNull('x.modality_code')->orWhereRaw("jsonb_array_length(COALESCE(x.metadata->'degraded_fields', '[]'::jsonb)) > 0")->orWhereNotExists(fn ($assertion) => $assertion->selectRaw('1')->from('prod.ancillary_current_assertions as a')->whereColumn('a.ancillary_order_id', 'o.ancillary_order_id')->where('a.milestone_code', 'RAD_EXAM_END'))),
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

        return $query;
    }
}
