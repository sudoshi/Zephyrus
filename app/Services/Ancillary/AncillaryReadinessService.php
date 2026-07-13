<?php

declare(strict_types=1);

namespace App\Services\Ancillary;

use App\Data\Ancillary\FreshnessEnvelope;
use App\Data\Ancillary\ReadinessAxis;
use App\Services\Lab\LabDecisionPendingService;
use App\Services\Lab\LabFlowBoardService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Produces the governed ancillary readiness axes shared by patient-facing and
 * department-facing operational surfaces. All public methods are batched so a
 * board never adds one query per patient.
 */
final class AncillaryReadinessService
{
    public const DRILL_SOURCES = ['flow_board', 'ancillary_services', 'ed', 'rtdc', 'periop', 'cockpit'];

    public function __construct(
        private readonly LabDecisionPendingService $labDecisions,
        private readonly LabFlowBoardService $labFlow,
    ) {}

    /** @param list<int> $encounterIds @return Collection<int, array<string, mixed>> */
    public function imagingForEncounters(array $encounterIds, string $source = 'rtdc'): Collection
    {
        $ids = $this->positiveIds($encounterIds);
        if ($ids === []) {
            return collect();
        }

        $rows = $this->openImagingOrders()
            ->whereIn('o.encounter_id', $ids)
            ->addSelect(DB::raw('o.encounter_id AS scope_key'))
            ->get();

        return $this->axes($ids, $rows, $source);
    }

    /** @param list<int> $edVisitIds @return Collection<int, array<string, mixed>> */
    public function imagingForEdVisits(array $edVisitIds, string $source = 'ed'): Collection
    {
        $ids = $this->positiveIds($edVisitIds);
        if ($ids === []) {
            return collect();
        }

        $rows = $this->openImagingOrders()
            ->join('prod.rad_exams as x', 'x.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->whereRaw("COALESCE(x.metadata->>'ed_visit_id', '') ~ '^[0-9]+$'")
            ->whereIn(DB::raw("(x.metadata->>'ed_visit_id')::bigint"), $ids)
            ->addSelect(DB::raw("(x.metadata->>'ed_visit_id')::bigint AS scope_key"))
            ->get();

        return $this->axes($ids, $rows, $source);
    }

    /** @param list<int> $orderIds @return Collection<int, array<string, mixed>> */
    public function imagingForOrders(array $orderIds, string $source = 'flow_board'): Collection
    {
        $ids = $this->positiveIds($orderIds);
        if ($ids === []) {
            return collect();
        }

        $rows = $this->openImagingOrders()
            ->whereIn('o.ancillary_order_id', $ids)
            ->addSelect(DB::raw('o.ancillary_order_id AS scope_key'))
            ->get();

        return $this->axes($ids, $rows, $source);
    }

    /** @param list<int> $encounterIds @return Collection<int, array<string, mixed>> */
    public function laboratoryForEncounters(array $encounterIds, string $source = 'rtdc'): Collection
    {
        return $this->laboratoryAxes($encounterIds, 'discharge_gate', $source);
    }

    /** @param list<int> $edVisitIds @return Collection<int, array<string, mixed>> */
    public function laboratoryForEdVisits(array $edVisitIds, string $source = 'ed'): Collection
    {
        return $this->laboratoryAxes($edVisitIds, 'ed_disposition', $source);
    }

    private function openImagingOrders(): Builder
    {
        return DB::table('prod.ancillary_orders as o')
            ->where('o.department', 'rad')
            ->whereNull('o.terminal_at')
            ->select([
                'o.ancillary_order_id',
                'o.order_uuid',
                'o.priority',
                'o.ordered_at',
                'o.source_cutoff_at',
                'o.metadata',
            ]);
    }

    /**
     * @param  list<int>  $scopeIds
     * @param  Collection<int, object>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function axes(array $scopeIds, Collection $rows, string $source): Collection
    {
        $source = in_array($source, self::DRILL_SOURCES, true) ? $source : 'ancillary_services';
        $registry = DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->first();
        $groups = $rows->groupBy(fn (object $row): int => (int) $row->scope_key);

        return collect($scopeIds)->mapWithKeys(function (int $scopeId) use ($groups, $registry, $source): array {
            /** @var Collection<int, object> $orders */
            $orders = $groups->get($scopeId, collect());
            $freshness = $this->freshness($orders, $registry);
            $oldest = $orders->sortBy(fn (object $row): string => sprintf('%s|%020d', $row->ordered_at, $row->ancillary_order_id))->first();
            $top = $orders->sortBy(fn (object $row): string => sprintf(
                '%d|%s|%020d',
                $this->isBlocking($row) ? 0 : 1,
                $row->ordered_at,
                $row->ancillary_order_id,
            ))->first();
            $blocking = $orders->contains(fn (object $row): bool => $this->isBlocking($row));
            $pendingCount = $orders->count();
            $oldestAge = $oldest === null
                ? null
                : max(0, (int) floor(CarbonImmutable::parse($oldest->ordered_at)->diffInSeconds(now(), false) / 60));
            $status = match (true) {
                $freshness->status !== 'fresh' => 'unknown',
                $blocking => 'blocked',
                $pendingCount > 0 => 'pending',
                default => 'ready',
            };
            $href = $top === null ? null : '/radiology/worklist?'.http_build_query([
                'search' => (string) $top->order_uuid,
                'source' => $source,
            ]);

            return [$scopeId => (new ReadinessAxis(
                key: 'imaging',
                label: 'Imaging',
                status: $status,
                pendingCount: $pendingCount,
                oldestAgeMinutes: $oldestAge,
                blocking: $blocking,
                freshness: $freshness,
                drillTarget: $href,
                topOrderUuid: $top?->order_uuid,
                drillHref: $href,
            ))->toArray()];
        });
    }

    /** @param Collection<int, object> $orders */
    private function freshness(Collection $orders, ?object $registry): FreshnessEnvelope
    {
        $cutoffValue = $orders->pluck('source_cutoff_at')->filter()->max()
            ?? $registry?->latest_observed_at;
        $asOf = CarbonImmutable::now();
        $sourceLabel = (string) ($registry?->source_label ?? 'Radiology operational feeds');

        if ($cutoffValue === null) {
            return new FreshnessEnvelope(
                status: 'unknown',
                asOf: new \DateTimeImmutable($asOf->toAtomString()),
                sourceCutoffAt: null,
                lagMinutes: null,
                sourceLabel: $sourceLabel,
                explanation: 'No Radiology order source cutoff is available.',
            );
        }

        $cutoff = CarbonImmutable::parse($cutoffValue);
        $lag = max(0, (int) floor($cutoff->diffInSeconds($asOf, false) / 60));
        $warning = max(1, (int) ($registry?->warning_lag_minutes ?? 60));
        $registeredStatus = strtolower((string) ($registry?->status ?? 'current'));
        $sourceError = in_array($registeredStatus, ['error', 'failed', 'unavailable'], true);
        $stale = $sourceError || $registeredStatus === 'stale' || $lag > $warning;

        return new FreshnessEnvelope(
            status: $stale ? 'stale' : 'fresh',
            asOf: new \DateTimeImmutable($asOf->toAtomString()),
            sourceCutoffAt: new \DateTimeImmutable($cutoff->toAtomString()),
            lagMinutes: $lag,
            sourceLabel: $sourceLabel,
            explanation: $sourceError
                ? 'The registered ancillary source reports an error.'
                : ($stale ? 'The latest Radiology order evidence exceeds its freshness tolerance.' : null),
        );
    }

    private function isBlocking(object $row): bool
    {
        $metadata = is_array($row->metadata)
            ? $row->metadata
            : (json_decode((string) $row->metadata, true) ?: []);

        return $row->priority === 'discharge' || filter_var($metadata['discharge_blocking'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * @param  list<int>  $scopeIds
     * @return Collection<int, array<string, mixed>>
     */
    private function laboratoryAxes(array $scopeIds, string $decisionClass, string $source): Collection
    {
        $ids = $this->positiveIds($scopeIds);
        if ($ids === []) {
            return collect();
        }

        $source = in_array($source, self::DRILL_SOURCES, true) ? $source : 'ancillary_services';
        $snapshot = $this->labDecisions->readinessSnapshot();
        $freshness = $this->laboratoryFreshness($snapshot);
        $aggregates = collect($snapshot['destinationAggregates'])
            ->where('decisionClass', $decisionClass)
            ->keyBy(fn (array $row): int => (int) $row['destinationId']);
        $unresolved = collect($snapshot['unresolved'])
            ->where('decisionClass', $decisionClass)
            ->filter(fn (array $row): bool => $row['destinationId'] !== null)
            ->groupBy(fn (array $row): int => (int) $row['destinationId']);

        return collect($ids)->mapWithKeys(function (int $scopeId) use ($aggregates, $unresolved, $freshness, $decisionClass, $source): array {
            $aggregate = $aggregates->get($scopeId);
            $unresolvedCount = $unresolved->get($scopeId, collect())->count();
            $pendingCount = (int) ($aggregate['pendingCount'] ?? 0);
            $blocking = $pendingCount > 0;
            $status = match (true) {
                $freshness->status !== 'fresh', $unresolvedCount > 0 => 'unknown',
                $blocking => 'blocked',
                default => 'ready',
            };
            $topOrderUuid = $aggregate['topOrderUuid'] ?? null;
            $href = $topOrderUuid === null ? null : '/lab/pending-decisions?'.http_build_query([
                'decisionClass' => $decisionClass,
                'orderUuid' => $topOrderUuid,
                'source' => $source,
            ]);
            $label = $decisionClass === 'ed_disposition' ? 'ED disposition' : 'discharge';
            $explanation = match (true) {
                $freshness->status !== 'fresh' => 'Laboratory decision evidence is not current; readiness is unknown until a fresh source cutoff arrives.',
                $unresolvedCount > 0 => sprintf('%d explicit Laboratory %s gate(s) have unresolved destination evidence; readiness is unknown.', $unresolvedCount, $label),
                $blocking => sprintf('%d validated explicit Laboratory %s gate(s) remain pending verification.', $pendingCount, $label),
                default => sprintf('No validated explicit Laboratory %s gate is pending; routine and non-gating results do not block this axis.', $label),
            };

            return [$scopeId => (new ReadinessAxis(
                key: 'lab',
                label: 'Lab',
                status: $status,
                pendingCount: $pendingCount,
                oldestAgeMinutes: $aggregate === null ? null : (int) $aggregate['oldestAgeMinutes'],
                blocking: $blocking,
                freshness: $freshness,
                drillTarget: $href,
                topOrderUuid: $topOrderUuid,
                drillHref: $href,
                explanation: $explanation,
            ))->toArray()];
        });
    }

    /** @param array<string, mixed> $snapshot */
    private function laboratoryFreshness(array $snapshot): FreshnessEnvelope
    {
        $queue = $snapshot['freshness'];
        if ($queue['sourceCutoffAt'] !== null) {
            return new FreshnessEnvelope(
                status: $queue['status'],
                asOf: new \DateTimeImmutable($queue['asOf']),
                sourceCutoffAt: new \DateTimeImmutable($queue['sourceCutoffAt']),
                lagMinutes: $queue['lagMinutes'],
                sourceLabel: $queue['sourceLabel'],
                explanation: $queue['explanation'],
            );
        }

        $operations = $this->labFlow->cockpitHealth();
        $cutoffValue = $operations['sourceCutoffAt'];
        if ($cutoffValue === null || $operations['sourceState'] === 'missing') {
            return new FreshnessEnvelope(
                status: 'unknown',
                asOf: new \DateTimeImmutable(now()->toAtomString()),
                sourceCutoffAt: null,
                lagMinutes: null,
                sourceLabel: (string) $operations['sourceLabel'],
                explanation: 'No current Laboratory operational cutoff is available to verify an empty decision-gate cohort.',
            );
        }

        $cutoff = CarbonImmutable::parse($cutoffValue);
        $stale = in_array($operations['sourceState'], ['stale', 'error'], true);

        return new FreshnessEnvelope(
            status: $stale ? 'stale' : 'fresh',
            asOf: new \DateTimeImmutable(now()->toAtomString()),
            sourceCutoffAt: new \DateTimeImmutable($cutoff->toAtomString()),
            lagMinutes: max(0, (int) floor($cutoff->diffInSeconds(now(), false) / 60)),
            sourceLabel: (string) $operations['sourceLabel'],
            explanation: $stale ? 'The current Laboratory operational feed is stale or reports an error.' : null,
        );
    }

    /** @param list<int> $ids @return list<int> */
    private function positiveIds(array $ids): array
    {
        return collect($ids)->map(fn (mixed $id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->unique()->values()->all();
    }
}
