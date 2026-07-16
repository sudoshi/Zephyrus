<?php

declare(strict_types=1);

namespace App\Services\Ancillary;

use App\Data\Ancillary\FreshnessEnvelope;
use App\Data\Ancillary\ReadinessAxis;
use App\Services\Lab\LabDecisionPendingService;
use App\Services\Lab\LabFlowBoardService;
use App\Services\Pharmacy\PharmacyDischargeReadinessService;
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
        private readonly PharmacyDischargeReadinessService $pharmacyDischarge,
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

    /**
     * The ED boarder medication-delay axis, keyed by ED visit. Boarded ED
     * patients have an encounter, but the medication order carries its ED
     * linkage on ancillary_orders.metadata->>'ed_visit_id' (mirroring imaging),
     * so this joins the correct boarded encounter's open home-medication and
     * antibiotic orders to the ED visit without an encounter round-trip. A STAT,
     * first-dose, or sepsis clock class makes the axis blocking (a delayed dose
     * for a boarded patient); routine open orders are pending, not blocking.
     *
     * @param  list<int>  $edVisitIds
     * @return Collection<int, array<string, mixed>>
     */
    public function medicationForEdVisits(array $edVisitIds, string $source = 'ed'): Collection
    {
        $ids = $this->positiveIds($edVisitIds);
        if ($ids === []) {
            return collect();
        }

        $rows = $this->openMedicationOrders()
            ->whereRaw("COALESCE(o.metadata->>'ed_visit_id', '') ~ '^[0-9]+$'")
            ->whereIn(DB::raw("(o.metadata->>'ed_visit_id')::bigint"), $ids)
            ->addSelect(DB::raw("(o.metadata->>'ed_visit_id')::bigint AS scope_key"))
            ->get();

        return $this->medicationAxes($ids, $rows, $source);
    }

    /** @param list<int> $encounterIds @return Collection<int, array<string, mixed>> */
    public function medicationForEncounters(array $encounterIds, string $source = 'rtdc'): Collection
    {
        $ids = $this->positiveIds($encounterIds);
        if ($ids === []) {
            return collect();
        }

        $source = in_array($source, self::DRILL_SOURCES, true) ? $source : 'ancillary_services';
        $snapshot = $this->pharmacyDischarge->readinessSnapshot();
        $freshness = $snapshot['freshness'];
        /** @var Collection<int, array<string, mixed>> $byEncounter */
        $byEncounter = $snapshot['byEncounter'];

        return collect($ids)->mapWithKeys(function (int $scopeId) use ($byEncounter, $freshness, $source): array {
            $aggregate = $byEncounter->get($scopeId);
            // No discharge medication work for this encounter -> not applicable.
            if ($aggregate === null) {
                return [$scopeId => (new ReadinessAxis(
                    key: 'medication',
                    label: 'Medication',
                    status: 'not_applicable',
                    pendingCount: 0,
                    oldestAgeMinutes: null,
                    blocking: false,
                    freshness: $freshness,
                    drillTarget: null,
                    explanation: 'No discharge medication is queued for this encounter; the medication axis is not applicable.',
                ))->toArray()];
            }

            $pendingCount = (int) $aggregate['pendingCount'];
            $blocking = (bool) $aggregate['blocking'];
            $status = match (true) {
                $freshness->status !== 'fresh', $aggregate['unknown'] => 'unknown',
                $blocking => 'blocked',
                default => 'ready',
            };
            $href = '/pharmacy?'.http_build_query(['lens' => 'discharge', 'source' => $source]);
            $explanation = match (true) {
                $freshness->status !== 'fresh' => 'Pharmacy discharge evidence is not current; medication readiness is unknown until a fresh source cutoff arrives.',
                $aggregate['unknown'] => 'A discharge medication row reports an unknown pipeline state; medication readiness is unknown.',
                $blocking => sprintf('%d discharge medication step(s) remain before this patient is medication-ready.', $pendingCount),
                default => 'All discharge medications for this encounter are ready or delivered.',
            };

            return [$scopeId => (new ReadinessAxis(
                key: 'medication',
                label: 'Medication',
                status: $status,
                pendingCount: $pendingCount,
                oldestAgeMinutes: $aggregate['oldestAgeMinutes'] === null ? null : (int) $aggregate['oldestAgeMinutes'],
                blocking: $blocking,
                freshness: $freshness,
                drillTarget: $href,
                topOrderUuid: $aggregate['topQueueUuid'],
                drillHref: $href,
                explanation: $explanation,
            ))->toArray()];
        });
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

    private function openMedicationOrders(): Builder
    {
        return DB::table('prod.ancillary_orders as o')
            ->join('prod.rx_orders as x', 'x.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->where('o.department', 'rx')
            ->whereRaw("x.order_status NOT IN ('administered', 'discontinued', 'cancelled', 'completed')")
            ->select([
                'o.ancillary_order_id',
                'o.order_uuid',
                'o.priority',
                'o.ordered_at',
                'o.source_cutoff_at',
                'o.metadata',
                'x.clock_class',
            ]);
    }

    /**
     * @param  list<int>  $scopeIds
     * @param  Collection<int, object>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function medicationAxes(array $scopeIds, Collection $rows, string $source): Collection
    {
        $source = in_array($source, self::DRILL_SOURCES, true) ? $source : 'ancillary_services';
        $registry = DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->first();
        $groups = $rows->groupBy(fn (object $row): int => (int) $row->scope_key);

        return collect($scopeIds)->mapWithKeys(function (int $scopeId) use ($groups, $registry, $source): array {
            /** @var Collection<int, object> $orders */
            $orders = $groups->get($scopeId, collect());
            $freshness = $this->medicationFreshness($orders, $registry);
            $oldest = $orders->sortBy(fn (object $row): string => sprintf('%s|%020d', $row->ordered_at, $row->ancillary_order_id))->first();
            $blocking = $orders->contains(fn (object $row): bool => $this->isTimeCriticalMedication($row));
            $top = $orders->sortBy(fn (object $row): string => sprintf(
                '%d|%s|%020d',
                $this->isTimeCriticalMedication($row) ? 0 : 1,
                $row->ordered_at,
                $row->ancillary_order_id,
            ))->first();
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
            $href = $top === null ? null : '/pharmacy?'.http_build_query([
                'lens' => 'all',
                'source' => $source,
            ]);
            $explanation = match (true) {
                $freshness->status !== 'fresh' => 'Pharmacy order evidence is not current; medication readiness is unknown until a fresh source cutoff arrives.',
                $blocking => sprintf('%d open medication order(s) include a time-critical dose (STAT, first dose, or sepsis antibiotic) for this boarded ED patient.', $pendingCount),
                $pendingCount > 0 => sprintf('%d routine medication order(s) remain open for this boarded ED patient; none is time-critical.', $pendingCount),
                default => 'No open medication order is pending for this boarded ED patient.',
            };

            return [$scopeId => (new ReadinessAxis(
                key: 'medication',
                label: 'Medication',
                status: $status,
                pendingCount: $pendingCount,
                oldestAgeMinutes: $oldestAge,
                blocking: $blocking,
                freshness: $freshness,
                drillTarget: $href,
                topOrderUuid: $top?->order_uuid,
                drillHref: $href,
                explanation: $explanation,
            ))->toArray()];
        });
    }

    /** A dose whose clock class makes an open order a boarder medication delay. */
    private function isTimeCriticalMedication(object $row): bool
    {
        return in_array((string) ($row->clock_class ?? 'routine'), ['stat', 'first_dose', 'sepsis'], true);
    }

    /**
     * Medication order freshness for the ED boarder axis. Mirrors the imaging
     * freshness envelope but labels the Pharmacy operational feed; the
     * administration-warehouse tail is not consulted here because this axis is
     * an order-side delay signal, not an administration-completion claim.
     *
     * @param  Collection<int, object>  $orders
     */
    private function medicationFreshness(Collection $orders, ?object $registry): FreshnessEnvelope
    {
        $cutoffValue = $orders->pluck('source_cutoff_at')->filter()->max()
            ?? $registry?->latest_observed_at;
        $asOf = CarbonImmutable::now();
        $sourceLabel = (string) ($registry?->source_label ?? 'Pharmacy operational feeds');

        if ($cutoffValue === null) {
            return new FreshnessEnvelope(
                status: 'unknown',
                asOf: new \DateTimeImmutable($asOf->toAtomString()),
                sourceCutoffAt: null,
                lagMinutes: null,
                sourceLabel: $sourceLabel,
                explanation: 'No Pharmacy order source cutoff is available.',
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
                : ($stale ? 'The latest Pharmacy order evidence exceeds its freshness tolerance.' : null),
        );
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
