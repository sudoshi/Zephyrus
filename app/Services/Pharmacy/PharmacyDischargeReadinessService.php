<?php

declare(strict_types=1);

namespace App\Services\Pharmacy;

use App\Data\Ancillary\FreshnessEnvelope;
use App\Services\Ancillary\AncillaryContractSerializer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Discharge Medication Readiness query service (§X-7). Owns the discharge
 * medication pipeline, target-relative aging, ready-by-target compliance, and
 * source freshness server-side (§5.1: React never recomputes authoritative
 * status). It is self-contained — it computes its own discharge-candidate
 * cohort with the same predicate DischargePrioritiesService uses so the shared
 * readiness axis can be produced without a circular dependency.
 *
 * Prior-authorization pending is a workflow state that may be barrier-annotated
 * (PharmacyBarrierService); it is NEVER a payer writeback. No pharmacist, nurse,
 * or user-level dimension exists anywhere in this contract (§13).
 */
final class PharmacyDischargeReadinessService
{
    /** Pipeline states in workflow order (governed field, not display text). */
    public const PIPELINE = ['not_started', 'prior_auth_pending', 'verification', 'filling', 'ready', 'delivered', 'unknown'];

    /** States that still block discharge (medication work is not yet in hand). */
    public const BLOCKING = ['not_started', 'prior_auth_pending', 'verification', 'filling'];

    /** States that satisfy the axis (medication is ready or already handed off). */
    public const SATISFIED = ['ready', 'delivered'];

    public const DRILL_SOURCES = ['flow_board', 'ancillary_services', 'ed', 'rtdc', 'periop', 'cockpit'];

    private const PIPELINE_LABELS = [
        'not_started' => 'Not started',
        'prior_auth_pending' => 'Prior authorization pending',
        'verification' => 'Verification',
        'filling' => 'Filling / preparing',
        'ready' => 'Ready',
        'delivered' => 'Delivered',
        'unknown' => 'Unknown',
    ];

    public function __construct(private readonly AncillaryContractSerializer $contracts) {}

    /**
     * Full Discharge Medication Readiness page payload.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters = [], bool $canViewPatientDetail = true): array
    {
        $filters = $this->filters($filters);
        $now = CarbonImmutable::now();
        $rows = $this->cohort($filters);
        $freshness = $this->freshness($rows);
        $state = $this->state($rows, $freshness);

        return [
            'generatedAt' => $now->toAtomString(),
            'sourceCutoffAt' => $freshness->sourceCutoffAt?->format(DATE_ATOM),
            'freshnessStatus' => $freshness->status,
            'degradedMode' => $state === 'degraded',
            'state' => $state,
            'stateMessage' => $this->stateMessage($state),
            'freshness' => $this->contracts->freshness($freshness),
            'filters' => $filters,
            'filterOptions' => ['pipeline' => self::PIPELINE, 'sources' => self::DRILL_SOURCES],
            'cohortDefinition' => 'Today\'s planned discharges: active non-ED inpatient encounters with an expected discharge date that carry at least one discharge medication queue row. Encounters with no discharge medication are not-applicable, never falsely ready or blocked.',
            'data' => [
                'summary' => $this->summary($rows, $freshness, $now),
                'pipeline' => $this->pipeline($rows, $now),
                'items' => $this->items($rows, $now, $canViewPatientDetail),
            ],
            'privacy' => [
                'directPatientIdentifiersIncluded' => false,
                'doseInstructionsIncluded' => false,
                'individualPerformanceIncluded' => false,
                'identifierPolicy' => 'Pseudonymous patient and encounter display references only. No pharmacist, nurse, or user-level performance dimension is computed or exposed.',
            ],
            'canViewPatientDetail' => $canViewPatientDetail,
        ];
    }

    /**
     * Per-encounter readiness aggregates consumed by AncillaryReadinessService
     * to build the shared medication axis. Keyed by encounter_id.
     *
     * @return array{freshness: FreshnessEnvelope, byEncounter: Collection<int, array<string, mixed>>}
     */
    public function readinessSnapshot(): array
    {
        $rows = $this->cohort($this->filters([]));
        $freshness = $this->freshness($rows);
        $now = CarbonImmutable::now();

        $byEncounter = $rows
            ->filter(fn (object $row): bool => $row->encounter_id !== null)
            ->groupBy(fn (object $row): int => (int) $row->encounter_id)
            ->map(function (Collection $group) use ($now): array {
                $blocking = $group->filter(fn (object $row): bool => in_array($row->pipeline_status, self::BLOCKING, true));
                $unknown = $group->contains(fn (object $row): bool => $row->pipeline_status === 'unknown');
                $oldest = $blocking
                    ->map(fn (object $row): int => $this->ageMinutes($row->status_changed_at, $now))
                    ->max();
                $top = $blocking->sortByDesc(fn (object $row): int => $this->ageMinutes($row->status_changed_at, $now))->first()
                    ?? $group->first();

                return [
                    'pendingCount' => $blocking->count(),
                    'blocking' => $blocking->isNotEmpty(),
                    'unknown' => $unknown,
                    'oldestAgeMinutes' => $oldest,
                    'topQueueUuid' => $top?->discharge_queue_uuid,
                ];
            });

        return ['freshness' => $freshness, 'byEncounter' => $byEncounter];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function filters(array $input): array
    {
        $pipeline = is_string($input['pipeline'] ?? null) && in_array($input['pipeline'], self::PIPELINE, true) ? $input['pipeline'] : null;
        $encounterId = filter_var($input['encounterId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $source = is_string($input['source'] ?? null) && in_array($input['source'], self::DRILL_SOURCES, true) ? $input['source'] : null;

        return ['pipeline' => $pipeline, 'encounterId' => $encounterId === false ? null : $encounterId, 'source' => $source];
    }

    /**
     * Discharge queue rows for the active non-ED inpatient discharge cohort.
     * The join to encounters/units mirrors DischargePrioritiesService's cohort
     * predicate so the axis reconciles without a circular dependency.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    private function cohort(array $filters): Collection
    {
        $query = DB::table('prod.rx_discharge_queue as q')
            ->join('prod.encounters as e', 'e.encounter_id', '=', 'q.encounter_id')
            ->join('prod.units as u', 'u.unit_id', '=', 'e.unit_id')
            ->leftJoin('prod.rx_orders as x', 'x.rx_order_id', '=', 'q.rx_order_id')
            ->leftJoin('prod.ancillary_orders as o', 'o.ancillary_order_id', '=', 'x.ancillary_order_id')
            ->where('e.status', 'active')
            ->where('e.is_deleted', false)
            ->where('u.is_deleted', false)
            ->where('u.type', '<>', 'ed')
            ->whereNotNull('e.expected_discharge_date')
            ->select([
                'q.rx_discharge_queue_id',
                'q.discharge_queue_uuid',
                'q.encounter_id',
                'q.pipeline_status',
                'q.status_changed_at',
                'q.prior_auth_pending_at',
                'q.ready_at',
                'q.delivered_at',
                'q.planned_discharge_at',
                'e.patient_ref',
                'e.unit_id',
                'u.name as unit_name',
                'o.order_uuid',
                'o.source_cutoff_at',
                DB::raw("COALESCE(x.medication_label, 'Discharge medication') as medication_label"),
            ]);

        if ($filters['pipeline'] !== null) {
            $query->where('q.pipeline_status', $filters['pipeline']);
        }
        if ($filters['encounterId'] !== null) {
            $query->where('q.encounter_id', $filters['encounterId']);
        }

        return $query->orderBy('q.planned_discharge_at')->orderBy('q.rx_discharge_queue_id')->get();
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<string, mixed>
     */
    private function summary(Collection $rows, FreshnessEnvelope $freshness, CarbonImmutable $now): array
    {
        $total = $rows->count();
        $blocking = $rows->filter(fn (object $row): bool => in_array($row->pipeline_status, self::BLOCKING, true));
        $satisfied = $rows->filter(fn (object $row): bool => in_array($row->pipeline_status, self::SATISFIED, true));
        $readyByTarget = $rows->filter(fn (object $row): bool => $this->reachedReadyByTarget($row));
        $overdue = $blocking->filter(fn (object $row): bool => $this->targetRelativeMinutes($row->planned_discharge_at, $now) > 0);

        return [
            'candidates' => $rows->pluck('encounter_id')->filter()->unique()->count(),
            'queueRows' => $total,
            'blocking' => $blocking->count(),
            'satisfied' => $satisfied->count(),
            'overdueAgainstTarget' => $overdue->count(),
            'priorAuthPending' => $rows->where('pipeline_status', 'prior_auth_pending')->count(),
            'readyByTargetPercent' => $total === 0 || $freshness->status !== 'fresh' ? null : (int) round($readyByTarget->count() / $total * 100),
        ];
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private function pipeline(Collection $rows, CarbonImmutable $now): array
    {
        return collect(self::PIPELINE)->map(function (string $status) use ($rows, $now): array {
            $group = $rows->where('pipeline_status', $status);
            $oldest = $group->map(fn (object $row): int => $this->ageMinutes($row->status_changed_at, $now))->max();

            return [
                'status' => $status,
                'label' => self::PIPELINE_LABELS[$status],
                'blocking' => in_array($status, self::BLOCKING, true),
                'count' => $group->count(),
                'oldestAgeMinutes' => $oldest,
            ];
        })->all();
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private function items(Collection $rows, CarbonImmutable $now, bool $canViewPatientDetail): array
    {
        return $rows->map(function (object $row) use ($now, $canViewPatientDetail): array {
            $targetRelative = $this->targetRelativeMinutes($row->planned_discharge_at, $now);

            return [
                'queueUuid' => $row->discharge_queue_uuid,
                'orderUuid' => $row->order_uuid,
                'encounterId' => (int) $row->encounter_id,
                'patientRef' => $canViewPatientDetail ? (string) $row->patient_ref : 'redacted',
                'medicationLabel' => (string) $row->medication_label,
                'unitLabel' => (string) $row->unit_name,
                'pipelineStatus' => $row->pipeline_status,
                'pipelineLabel' => self::PIPELINE_LABELS[$row->pipeline_status] ?? $row->pipeline_status,
                'blocking' => in_array($row->pipeline_status, self::BLOCKING, true),
                'ageMinutes' => $this->ageMinutes($row->status_changed_at, $now),
                'plannedDischargeAt' => $row->planned_discharge_at === null ? null : CarbonImmutable::parse($row->planned_discharge_at)->toAtomString(),
                'targetRelativeMinutes' => $targetRelative,
                'targetState' => $this->targetState($row, $targetRelative),
                'priorAuthPending' => $row->pipeline_status === 'prior_auth_pending',
                'drillHref' => $row->order_uuid === null ? null : '/pharmacy?'.http_build_query(['lens' => 'discharge', 'source' => 'rtdc']),
                'rtdcHref' => $row->encounter_id === null ? null : '/rtdc/discharge-priorities',
            ];
        })->all();
    }

    private function targetState(object $row, int $targetRelative): string
    {
        if (in_array($row->pipeline_status, self::SATISFIED, true)) {
            return $this->reachedReadyByTarget($row) ? 'met' : 'late';
        }
        if ($row->pipeline_status === 'unknown') {
            return 'unknown';
        }

        return $targetRelative > 0 ? 'overdue' : 'on_track';
    }

    /** True when the row reached ready/delivered at or before its planned discharge target. */
    private function reachedReadyByTarget(object $row): bool
    {
        if (! in_array($row->pipeline_status, self::SATISFIED, true) || $row->planned_discharge_at === null) {
            return false;
        }
        $reachedAt = $row->delivered_at ?? $row->ready_at;
        if ($reachedAt === null) {
            return false;
        }

        return CarbonImmutable::parse($reachedAt)->lessThanOrEqualTo(CarbonImmutable::parse($row->planned_discharge_at));
    }

    /** Minutes relative to the planned discharge target (negative = ahead, positive = overdue). */
    private function targetRelativeMinutes(?string $plannedDischargeAt, CarbonImmutable $now): int
    {
        if ($plannedDischargeAt === null) {
            return 0;
        }

        return (int) round(CarbonImmutable::parse($plannedDischargeAt)->diffInMinutes($now, false));
    }

    private function ageMinutes(?string $since, CarbonImmutable $now): int
    {
        if ($since === null) {
            return 0;
        }

        return max(0, (int) floor(CarbonImmutable::parse($since)->diffInSeconds($now, false) / 60));
    }

    /** @param Collection<int, object> $rows */
    private function freshness(Collection $rows): FreshnessEnvelope
    {
        $registry = DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->first();
        $cutoffValue = $rows->pluck('source_cutoff_at')->filter()->max() ?? $registry?->latest_observed_at;
        $asOf = CarbonImmutable::now();
        $sourceLabel = (string) ($registry?->source_label ?? 'Pharmacy discharge feeds');

        if ($cutoffValue === null) {
            return new FreshnessEnvelope(
                status: 'unknown',
                asOf: new \DateTimeImmutable($asOf->toAtomString()),
                sourceCutoffAt: null,
                lagMinutes: null,
                sourceLabel: $sourceLabel,
                explanation: 'No Pharmacy discharge source cutoff is available; readiness is unknown until a fresh source cutoff arrives.',
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
                : ($stale ? 'The latest Pharmacy discharge evidence exceeds its freshness tolerance.' : null),
        );
    }

    /** @param Collection<int, object> $rows */
    private function state(Collection $rows, FreshnessEnvelope $freshness): string
    {
        if (in_array($freshness->status, ['stale', 'unknown'], true)) {
            return $freshness->status === 'unknown' ? 'degraded' : 'stale';
        }
        if ($rows->isEmpty()) {
            return 'no_data';
        }

        return 'normal';
    }

    private function stateMessage(string $state): string
    {
        return match ($state) {
            'no_data' => 'No discharge medication work is queued for today\'s planned discharges.',
            'stale' => 'The Pharmacy discharge feed is stale; readiness is shown as-of the last source cutoff.',
            'degraded' => 'No current Pharmacy discharge source cutoff is available; readiness is unknown.',
            default => 'Discharge medication readiness is current.',
        };
    }
}
