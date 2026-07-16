<?php

declare(strict_types=1);

namespace App\Services\Pharmacy;

use App\Data\Ancillary\FreshnessEnvelope;
use App\Services\Ancillary\AncillaryContractSerializer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The IV Room and Batches query service (§X-8). Owns every batch grouping, BUD
 * window, TPN cutoff comparison, chemo timeline, active-work queue, waste
 * measure, and the IVWMS-absent degraded verify-to-dispense view server-side
 * (§5.1: React never recomputes authoritative status). The application clock is
 * bound into SQL as a parameter — never PG now() against a frozen test clock.
 *
 * Two honesty rules hold throughout:
 *   1. POLICY/CONFIGURATION (the TPN daily cutoff time, BUD policy windows) is a
 *      declared field, never presented as an observed event. Measured
 *      timestamps (started_at/completed_at/checked_at, bud_expires_at) come from
 *      the satellite rows only.
 *   2. When IVWMS is absent (an iv_room order with no rx_preps rows and no
 *      RX_PREP_STARTED milestone) the interior is a coarse verify-to-dispense
 *      clock with an explicit coverage statement — never fabricated prep stages
 *      and never a zero duration.
 *
 * Operational fields only: batch identity, counts, timing windows, and state.
 * No clinical compounding recipe, dose instruction, ingredient, or actionable
 * preparation instruction is ever read or exposed. No pharmacist, technician,
 * verifier, or user-level dimension exists anywhere in this contract (§13).
 */
final class PharmacyIvRoomService
{
    /** Governed prep types carried on prod.rx_preps (§7). */
    public const PREP_TYPES = ['iv_batch', 'chemo', 'tpn', 'compound', 'repack', 'other'];

    /** Active (not-yet-complete) preparation states. */
    public const ACTIVE_STATES = ['pending', 'in_progress'];

    /**
     * TPN daily production cutoff (local wall-clock hour). This is a POLICY
     * value — the configured deadline a TPN batch must be started/completed by,
     * NOT an observed event. Surfaced separately from measured timestamps.
     */
    public const TPN_CUTOFF_HOUR = 18;

    /**
     * BUD warning window: a batch whose beyond-use date expires within this many
     * minutes of the app clock is flagged expiring. This is a POLICY threshold,
     * applied to the measured bud_expires_at — never a measured value itself.
     */
    public const BUD_WARNING_MINUTES = 120;

    public function __construct(private readonly AncillaryContractSerializer $contracts) {}

    /**
     * Full IV Room and Batches page payload (§9 envelope).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters = [], bool $canViewPatientDetail = true): array
    {
        $filters = $this->filters($filters);
        $now = CarbonImmutable::now();
        $preps = $this->preps($filters);
        $degraded = $this->degradedOrders($filters);
        $freshness = $this->freshness($preps, $degraded);
        $state = $this->state($preps, $degraded, $freshness);

        return [
            'generatedAt' => $now->toAtomString(),
            'sourceCutoffAt' => $freshness->sourceCutoffAt?->format(DATE_ATOM),
            'freshnessStatus' => $freshness->status,
            'degradedMode' => $state === 'degraded' || $degraded->isNotEmpty(),
            'state' => $state,
            'stateMessage' => $this->stateMessage($state),
            'freshness' => $this->contracts->freshness($freshness),
            'filters' => $filters,
            'filterOptions' => ['prepType' => self::PREP_TYPES],
            // No governed SLA clock stops on a batch; the IV room surfaces
            // operational timing windows, not §8 clock breaches.
            'appliedSlaDefinitions' => [],
            'policy' => $this->policy($now),
            'data' => [
                'summary' => $this->summary($preps, $degraded, $now),
                'batches' => $this->batches($preps, $now),
                'chemoTimeline' => $this->chemoTimeline($preps, $now, $canViewPatientDetail),
                'activeWork' => $this->activeWork($preps, $now, $canViewPatientDetail),
                'waste' => $this->waste($now),
                'degradedOrders' => $this->degradedView($degraded, $now, $canViewPatientDetail),
            ],
            'privacy' => [
                'directPatientIdentifiersIncluded' => false,
                'doseInstructionsIncluded' => false,
                'compoundingRecipeIncluded' => false,
                'individualPerformanceIncluded' => false,
                'identifierPolicy' => 'Pseudonymous patient and encounter display references only. Batch identity, counts, timing windows, and state are operational fields; no compounding recipe, ingredient, dose instruction, or user-level performance dimension is computed or exposed.',
            ],
            'canViewPatientDetail' => $canViewPatientDetail,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function filters(array $input): array
    {
        $prepType = is_string($input['prepType'] ?? null) && in_array($input['prepType'], self::PREP_TYPES, true) ? $input['prepType'] : null;

        return ['prepType' => $prepType];
    }

    /**
     * IV-room preparation satellite rows for the current operational window,
     * joined to the medication order for label and pseudonymous references. No
     * ingredient, recipe, or dose field is selected — operational fields only.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    private function preps(array $filters): Collection
    {
        return DB::table('prod.rx_preps as p')
            ->join('prod.rx_orders as x', 'x.rx_order_id', '=', 'p.rx_order_id')
            ->join('prod.ancillary_orders as o', 'o.ancillary_order_id', '=', 'x.ancillary_order_id')
            ->leftJoin('prod.units as u', 'u.unit_id', '=', 'o.unit_id')
            ->where('o.department', 'rx')
            ->where('o.ordered_at', '>=', now()->subDay())
            ->whereRaw("COALESCE(o.metadata->>'operational_window', 'current') <> 'historical_study_only'")
            ->when($filters['prepType'], fn ($query, string $prepType) => $query->where('p.prep_type', $prepType))
            ->orderByRaw('p.batch_ref NULLS LAST')
            ->orderBy('p.started_at')
            ->orderBy('p.rx_prep_id')
            ->select([
                'p.rx_prep_id',
                'p.prep_uuid',
                'p.prep_type',
                'p.prep_branch',
                'p.batch_ref',
                'p.prep_state',
                'p.started_at',
                'p.completed_at',
                'p.checked_at',
                'p.cancelled_at',
                'p.bud_expires_at',
                'o.order_uuid',
                'o.patient_ref',
                'o.patient_class',
                'o.source_cutoff_at',
                'x.medication_label',
                'u.name as unit_name',
            ])
            ->get();
    }

    /**
     * The IVWMS-absent degraded cohort: iv_room orders with neither rx_preps
     * rows nor an RX_PREP_STARTED milestone. These get a coarse verify-to-
     * dispense clock and an explicit coverage statement — never fabricated prep
     * stages. Mirrors PharmacyFlowBoardService's degraded predicate exactly.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    private function degradedOrders(array $filters): Collection
    {
        // The degraded cohort has no rx_preps rows, so a prep-type filter that
        // is set can never match it — return empty rather than a false subset.
        if ($filters['prepType'] !== null) {
            return collect();
        }

        return DB::table('prod.ancillary_orders as o')
            ->join('prod.rx_orders as x', 'x.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->leftJoin('prod.units as u', 'u.unit_id', '=', 'o.unit_id')
            ->leftJoin('prod.ancillary_current_assertions as v', function ($join): void {
                $join->on('v.ancillary_order_id', '=', 'o.ancillary_order_id')->where('v.milestone_code', 'RX_VERIFIED');
            })
            ->leftJoin('prod.ancillary_current_assertions as d', function ($join): void {
                $join->on('d.ancillary_order_id', '=', 'o.ancillary_order_id')->where('d.milestone_code', 'RX_DISPENSED');
            })
            ->where('o.department', 'rx')
            ->where('o.ordered_at', '>=', now()->subDay())
            ->whereRaw("COALESCE(o.metadata->>'operational_window', 'current') <> 'historical_study_only'")
            ->where('x.preparation_branch', 'iv_room')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('prod.rx_preps as dp')->whereColumn('dp.rx_order_id', 'x.rx_order_id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('prod.ancillary_current_assertions as dm')->whereColumn('dm.ancillary_order_id', 'o.ancillary_order_id')->where('dm.milestone_code', 'RX_PREP_STARTED'))
            ->orderBy('o.ordered_at')
            ->select([
                'o.order_uuid',
                'o.patient_ref',
                'o.patient_class',
                'o.source_cutoff_at',
                'x.medication_label',
                'x.order_status',
                'u.name as unit_name',
                'v.occurred_at as verified_at',
                'd.occurred_at as dispensed_at',
            ])
            ->get();
    }

    /**
     * Declared policy/configuration surface. Every value here is a CONFIGURED
     * deadline or window — never an observed event. The frontend renders these
     * as policy reference lines, visually distinct from measured timing.
     *
     * @return array<string, mixed>
     */
    private function policy(CarbonImmutable $now): array
    {
        $tz = (string) config('app.timezone', 'UTC');
        $local = $now->setTimezone($tz);
        $cutoffToday = $local->startOfDay()->addHours(self::TPN_CUTOFF_HOUR);
        // The next enforceable TPN cutoff relative to the app clock.
        $nextCutoff = $local->lessThanOrEqualTo($cutoffToday) ? $cutoffToday : $cutoffToday->addDay();

        return [
            'kind' => 'configuration',
            'tpnCutoff' => [
                'label' => 'TPN daily production cutoff',
                'localHour' => self::TPN_CUTOFF_HOUR,
                'timezone' => $tz,
                'nextCutoffAt' => $nextCutoff->utc()->toAtomString(),
                'description' => 'Configured local production deadline for the daily TPN batch. This is a policy time, not an observed event.',
            ],
            'budWarningWindow' => [
                'label' => 'BUD warning window',
                'minutes' => self::BUD_WARNING_MINUTES,
                'description' => 'A batch whose beyond-use date expires within this configured window of the current time is flagged expiring. Policy threshold applied to the measured beyond-use date.',
            ],
        ];
    }

    /**
     * @param  Collection<int, object>  $preps
     * @param  Collection<int, object>  $degraded
     * @return array<string, mixed>
     */
    private function summary(Collection $preps, Collection $degraded, CarbonImmutable $now): array
    {
        $active = $preps->filter(fn (object $row): bool => in_array($row->prep_state, self::ACTIVE_STATES, true));
        $expiring = $preps->filter(fn (object $row): bool => $this->budState($row->bud_expires_at, $now) === 'expiring');
        $expired = $preps->filter(fn (object $row): bool => $this->budState($row->bud_expires_at, $now) === 'expired');
        $batchRefs = $preps->pluck('batch_ref')->filter()->unique();

        return [
            'totalPreps' => $preps->count(),
            'activePreps' => $active->count(),
            'batches' => $batchRefs->count(),
            'chemoPreps' => $preps->where('prep_type', 'chemo')->count(),
            'tpnPreps' => $preps->where('prep_type', 'tpn')->count(),
            'budExpiringSoon' => $expiring->count(),
            'budExpired' => $expired->count(),
            'degradedOrders' => $degraded->count(),
        ];
    }

    /**
     * Current and next batches, grouped by batch reference (and prep type for
     * unbatched preps). Each batch carries counts, its earliest start and
     * latest completion, and the soonest measured BUD window with a policy-
     * derived state. Batch identity and counts only — no recipe content.
     *
     * @param  Collection<int, object>  $preps
     * @return list<array<string, mixed>>
     */
    private function batches(Collection $preps, CarbonImmutable $now): array
    {
        return $preps
            ->groupBy(fn (object $row): string => $row->batch_ref ?? 'unbatched:'.$row->prep_type)
            ->map(function (Collection $group, string $key) use ($now): array {
                $first = $group->first();
                $soonestBud = $group->pluck('bud_expires_at')->filter()->map(fn (string $at): CarbonImmutable => CarbonImmutable::parse($at))->min();
                $startedValues = $group->pluck('started_at')->filter();
                $completedValues = $group->pluck('completed_at')->filter();
                $states = $group->countBy('prep_state');

                return [
                    'key' => $key,
                    'batchRef' => $first->batch_ref,
                    'batched' => $first->batch_ref !== null,
                    'prepType' => $first->prep_type,
                    'prepTypeLabel' => $this->prepTypeLabel($first->prep_type),
                    'prepCount' => $group->count(),
                    'activeCount' => $group->whereIn('prep_state', self::ACTIVE_STATES)->count(),
                    'stateCounts' => $states->all(),
                    'earliestStartedAt' => $startedValues->isEmpty() ? null : CarbonImmutable::parse($startedValues->min())->toAtomString(),
                    'latestCompletedAt' => $completedValues->isEmpty() ? null : CarbonImmutable::parse($completedValues->max())->toAtomString(),
                    'budExpiresAt' => $soonestBud?->toAtomString(),
                    'budMinutesRemaining' => $soonestBud === null ? null : (int) round($now->diffInMinutes($soonestBud, false)),
                    'budState' => $this->budState($soonestBud?->toIso8601String(), $now),
                    'budCrossesDayBoundary' => $soonestBud !== null && $soonestBud->setTimezone((string) config('app.timezone', 'UTC'))->toDateString() !== $now->setTimezone((string) config('app.timezone', 'UTC'))->toDateString(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Chemo preparation timeline: chemo-type preps with their measured state
     * progression (pending → in_progress → complete → checked). The stage list
     * is derived from real timestamps only; a missing timestamp is a pending
     * stage, never a fabricated one.
     *
     * @param  Collection<int, object>  $preps
     * @return list<array<string, mixed>>
     */
    private function chemoTimeline(Collection $preps, CarbonImmutable $now, bool $canViewPatientDetail): array
    {
        return $preps
            ->where('prep_type', 'chemo')
            ->map(fn (object $row): array => $this->prepStages($row, $now, $canViewPatientDetail))
            ->values()
            ->all();
    }

    /**
     * Active work: pending and in-progress preps ordered oldest-first, each
     * with its measured elapsed time and BUD window.
     *
     * @param  Collection<int, object>  $preps
     * @return list<array<string, mixed>>
     */
    private function activeWork(Collection $preps, CarbonImmutable $now, bool $canViewPatientDetail): array
    {
        return $preps
            ->filter(fn (object $row): bool => in_array($row->prep_state, self::ACTIVE_STATES, true))
            ->sortBy(fn (object $row): string => $row->started_at ?? $row->batch_ref ?? '')
            ->map(fn (object $row): array => $this->prepStages($row, $now, $canViewPatientDetail))
            ->values()
            ->all();
    }

    /**
     * One prep's measured stage progression. Every stage timestamp comes from
     * the satellite row; a null timestamp yields a pending stage, never a
     * fabricated time. Elapsed is measured from the first real timestamp.
     *
     * @return array<string, mixed>
     */
    private function prepStages(object $row, CarbonImmutable $now, bool $canViewPatientDetail): array
    {
        $stages = [
            ['code' => 'started', 'label' => 'Started', 'at' => $row->started_at],
            ['code' => 'completed', 'label' => 'Compounded', 'at' => $row->completed_at],
            ['code' => 'checked', 'label' => 'Checked', 'at' => $row->checked_at],
        ];
        $startedAt = $row->started_at === null ? null : CarbonImmutable::parse($row->started_at);
        $terminalAt = $row->checked_at ?? $row->completed_at ?? $row->cancelled_at;
        $endAt = $terminalAt === null ? $now : CarbonImmutable::parse($terminalAt);

        return [
            'prepUuid' => $row->prep_uuid,
            'label' => (string) $row->medication_label,
            'patientRef' => $canViewPatientDetail ? ($row->patient_ref ?: 'Pseudonymous patient unavailable') : 'Patient context restricted',
            'patientClass' => $row->patient_class,
            'locationLabel' => $row->unit_name,
            'prepType' => $row->prep_type,
            'prepTypeLabel' => $this->prepTypeLabel($row->prep_type),
            'batchRef' => $row->batch_ref,
            'prepState' => $row->prep_state,
            'prepStateLabel' => $this->prepStateLabel($row->prep_state),
            'elapsedMinutes' => $startedAt === null ? null : max(0, (int) floor($startedAt->diffInSeconds($endAt, false) / 60)),
            'elapsedIsMeasured' => $startedAt !== null,
            'budExpiresAt' => $row->bud_expires_at === null ? null : CarbonImmutable::parse($row->bud_expires_at)->toAtomString(),
            'budMinutesRemaining' => $row->bud_expires_at === null ? null : (int) round($now->diffInMinutes(CarbonImmutable::parse($row->bud_expires_at), false)),
            'budState' => $this->budState($row->bud_expires_at, $now),
            'stages' => collect($stages)->map(fn (array $stage): array => [
                'code' => $stage['code'],
                'label' => $stage['label'],
                'at' => $stage['at'] === null ? null : CarbonImmutable::parse($stage['at'])->toAtomString(),
                'state' => $stage['at'] === null ? 'pending' : 'complete',
            ])->values()->all(),
        ];
    }

    /**
     * Waste measures from prod.adc_transactions waste rows over an explicit time
     * range, with a declared denominator (vend transactions in the same window
     * from the same stations). Waste and vend counts are unit/station aggregates
     * only — never a user-level figure.
     *
     * @return array<string, mixed>
     */
    private function waste(CarbonImmutable $now): array
    {
        $windowHours = 24;
        $windowStart = $now->subHours($windowHours);
        $windowStartIso = $windowStart->toIso8601String();
        $nowIso = $now->toIso8601String();

        $row = DB::table('prod.adc_transactions')
            ->whereRaw('occurred_at >= ?::timestamptz', [$windowStartIso])
            ->whereRaw('occurred_at <= ?::timestamptz', [$nowIso])
            ->selectRaw(<<<'SQL'
                count(*) FILTER (WHERE transaction_type = 'waste') AS waste_events,
                count(*) FILTER (WHERE transaction_type = 'vend') AS vend_events,
                COALESCE(sum(quantity) FILTER (WHERE transaction_type = 'waste'), 0) AS waste_quantity
            SQL)
            ->first();

        $wasteEvents = (int) ($row->waste_events ?? 0);
        $vendEvents = (int) ($row->vend_events ?? 0);

        return [
            'wasteEvents' => $wasteEvents,
            'wasteQuantity' => round((float) ($row->waste_quantity ?? 0), 2),
            'denominatorLabel' => 'ADC vend transactions in the same station-scope window',
            'denominatorCount' => $vendEvents,
            'wastePerHundredVends' => $vendEvents === 0 ? null : round($wasteEvents / $vendEvents * 100, 1),
            'windowHours' => $windowHours,
            'windowStartAt' => $windowStart->toAtomString(),
            'windowEndAt' => $now->toAtomString(),
            'basis' => 'Waste is measured from automated dispensing cabinet waste-transaction facts; the rate is per hundred vend transactions in the same window and station scope, a unit/station aggregate with no user-level dimension.',
        ];
    }

    /**
     * The degraded verify-to-dispense view for IVWMS-absent iv_room orders. Each
     * carries a coarse verify→dispense clock (from the two real milestones) and
     * an explicit coverage statement. Never a fabricated prep stage or a zero
     * duration.
     *
     * @param  Collection<int, object>  $degraded
     * @return array<string, mixed>
     */
    private function degradedView(Collection $degraded, CarbonImmutable $now, bool $canViewPatientDetail): array
    {
        $orders = $degraded->map(function (object $row) use ($now, $canViewPatientDetail): array {
            $verifiedAt = $row->verified_at === null ? null : CarbonImmutable::parse($row->verified_at);
            $dispensedAt = $row->dispensed_at === null ? null : CarbonImmutable::parse($row->dispensed_at);
            $end = $dispensedAt ?? $now;
            // The coarse interior is only meaningful when the verify anchor is real.
            $coarseMinutes = $verifiedAt === null ? null : max(0, (int) floor($verifiedAt->diffInSeconds($end, false) / 60));

            return [
                'orderUuid' => $row->order_uuid,
                'label' => (string) $row->medication_label,
                'patientRef' => $canViewPatientDetail ? ($row->patient_ref ?: 'Pseudonymous patient unavailable') : 'Patient context restricted',
                'patientClass' => $row->patient_class,
                'locationLabel' => $row->unit_name,
                'orderStatus' => $row->order_status,
                'verifiedAt' => $verifiedAt?->toAtomString(),
                'dispensedAt' => $dispensedAt?->toAtomString(),
                'coarseVerifyToDispenseMinutes' => $coarseMinutes,
                'clockResolution' => 'coarse',
            ];
        })->values()->all();

        return [
            'coverage' => $degraded->isEmpty() ? 'available' : 'partial',
            'orders' => $orders,
            'coverageStatement' => $degraded->isEmpty()
                ? 'IV workflow preparation evidence is available for every current IV-room order; batch preparation stages are fully covered.'
                : 'IV workflow (IVWMS) preparation evidence is absent for these IV-room orders. Only a coarse verify-to-dispense interval is available; preparation stages, batch identity, and BUD windows are not reported for them, and their preparation duration is never shown as zero.',
        ];
    }

    private function prepTypeLabel(string $prepType): string
    {
        return match ($prepType) {
            'iv_batch' => 'IV batch',
            'chemo' => 'Chemotherapy',
            'tpn' => 'TPN admixture',
            'compound' => 'Compound',
            'repack' => 'Repack',
            default => 'Other',
        };
    }

    private function prepStateLabel(string $state): string
    {
        return match ($state) {
            'pending' => 'Pending',
            'in_progress' => 'In progress',
            'complete' => 'Compounded',
            'checked' => 'Checked',
            'cancelled' => 'Cancelled',
            default => ucfirst($state),
        };
    }

    /**
     * BUD state derived from the measured beyond-use date and the POLICY warning
     * window. 'none' when no BUD is recorded — never inferred.
     */
    private function budState(?string $budExpiresAt, CarbonImmutable $now): string
    {
        if ($budExpiresAt === null) {
            return 'none';
        }
        $expiresAt = CarbonImmutable::parse($budExpiresAt);
        $minutesRemaining = (int) round($now->diffInMinutes($expiresAt, false));

        return match (true) {
            $minutesRemaining <= 0 => 'expired',
            $minutesRemaining <= self::BUD_WARNING_MINUTES => 'expiring',
            default => 'within_window',
        };
    }

    /**
     * @param  Collection<int, object>  $preps
     * @param  Collection<int, object>  $degraded
     */
    private function freshness(Collection $preps, Collection $degraded): FreshnessEnvelope
    {
        $registry = DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->first();
        $asOf = CarbonImmutable::now();
        $sourceLabel = (string) ($registry?->source_label ?? 'Pharmacy IV workflow feeds');

        // Freshness reflects the order-feed source cutoff, never a prep timestamp
        // (a prep can be hours old and still current from a fresh feed).
        $cutoffValue = $preps->pluck('source_cutoff_at')->merge($degraded->pluck('source_cutoff_at'))->filter()->max()
            ?? $registry?->latest_observed_at;

        if ($cutoffValue === null) {
            return new FreshnessEnvelope(
                status: 'unknown',
                asOf: new \DateTimeImmutable($asOf->toAtomString()),
                sourceCutoffAt: null,
                lagMinutes: null,
                sourceLabel: $sourceLabel,
                explanation: 'No IV-room preparation evidence is available; batch state is unknown until a fresh source cutoff arrives.',
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
                : ($stale ? 'The latest IV-room preparation evidence exceeds its freshness tolerance.' : null),
        );
    }

    /**
     * @param  Collection<int, object>  $preps
     * @param  Collection<int, object>  $degraded
     */
    private function state(Collection $preps, Collection $degraded, FreshnessEnvelope $freshness): string
    {
        if (in_array($freshness->status, ['stale', 'unknown'], true)) {
            return $freshness->status === 'unknown' ? 'degraded' : 'stale';
        }
        if ($preps->isEmpty() && $degraded->isEmpty()) {
            return 'no_data';
        }
        if ($degraded->isNotEmpty()) {
            return 'degraded';
        }

        return 'normal';
    }

    private function stateMessage(string $state): string
    {
        return match ($state) {
            'no_data' => 'No IV-room preparation work is in the current operational window.',
            'stale' => 'IV workflow evidence is stale; batch state is shown as-of the last source cutoff.',
            'degraded' => 'IV workflow coverage is partial; degraded IV-room orders keep a coarse verify-to-dispense clock without fabricated preparation stages.',
            default => 'IV-room preparation facts are current.',
        };
    }
}
