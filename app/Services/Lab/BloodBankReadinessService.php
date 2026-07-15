<?php

namespace App\Services\Lab;

use App\Data\Ancillary\FreshnessEnvelope;
use App\Services\Ancillary\AncillaryContractSerializer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class BloodBankReadinessService
{
    public const GATE_STATES = ['blocked', 'ready', 'not_applicable', 'mtp_active', 'unknown'];

    public const PRODUCT_CLASSES = ['red_cells', 'plasma', 'platelets', 'cryo', 'whole_blood', 'mixed', 'other'];

    private const READY_STATES = ['crossmatch_ready', 'allocated', 'issued', 'complete'];

    public function __construct(private readonly AncillaryContractSerializer $contracts) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function build(array $input = []): array
    {
        $filters = $this->filters($input);
        $operatingDate = $filters['caseId'] === null
            ? $this->activeOperatingDate()
            : DB::table('prod.or_cases')->where('case_id', $filters['caseId'])->value('surgery_date');
        $mode = $filters['caseId'] !== null ? 'exact_case' : 'latest_operating_day';
        $cases = $operatingDate === null ? collect() : $this->caseQuery()
            ->when($filters['caseId'], fn (Builder $query, int $caseId): Builder => $query->where('oc.case_id', $caseId), fn (Builder $query): Builder => $query->whereDate('oc.surgery_date', $operatingDate))
            ->get();
        $gates = $this->gates($cases);

        $filtered = $gates->filter(function (array $gate) use ($filters): bool {
            if ($filters['state'] !== 'all' && $gate['state'] !== $filters['state']) {
                return false;
            }
            if ($filters['productClass'] !== 'all' && ! in_array($filters['productClass'], $gate['productClasses'], true)) {
                return false;
            }
            if ($filters['service'] !== null && $gate['serviceLabel'] !== $filters['service']) {
                return false;
            }

            return $filters['room'] === null || $gate['roomLabel'] === $filters['room'];
        })->values();
        $cutoff = $filtered->pluck('sourceCutoffAt')->filter()->min();
        $freshness = $this->freshness($cutoff);
        $registered = strtolower((string) (DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->value('status') ?? ''));
        $state = match (true) {
            in_array($registered, ['error', 'failed', 'unavailable'], true) => 'source_error',
            $filtered->isEmpty() => 'no_data',
            $freshness['status'] === 'stale' => 'stale',
            $filtered->contains(fn (array $gate): bool => $gate['coverage']['status'] === 'degraded') => 'degraded',
            default => 'normal',
        };

        return [
            'generatedAt' => now()->toAtomString(),
            'operatingDate' => $operatingDate === null ? null : CarbonImmutable::parse($operatingDate)->toDateString(),
            'operatingDateMode' => $mode,
            'state' => $state,
            'stateMessage' => match ($state) {
                'source_error' => 'Blood Bank source health reports an error. Last known case gates remain cutoff-qualified.',
                'no_data' => 'No Perioperative case gates match the selected operating day and filters.',
                'stale' => 'Blood Bank readiness is stale; gates are unknown until current source evidence arrives.',
                'degraded' => 'One or more required requests do not align to the selected case schedule; affected gates remain explicit.',
                default => 'Blood Bank requirements and case schedule facts are current.',
            },
            'freshness' => $freshness,
            'filters' => $filters,
            'filterOptions' => $this->filterOptions($cases),
            'summary' => [
                'cases' => $filtered->count(),
                'required' => $filtered->where('required', true)->count(),
                'blocked' => $filtered->whereIn('state', ['blocked', 'mtp_active'])->count(),
                'ready' => $filtered->where('state', 'ready')->count(),
                'notApplicable' => $filtered->where('state', 'not_applicable')->count(),
                'unknown' => $filtered->where('state', 'unknown')->count(),
                'mtpActive' => $filtered->where('mtpActive', true)->count(),
            ],
            'data' => $filtered->all(),
            'privacy' => [
                'directPatientIdentifiersIncluded' => false,
                'bloodProductAllocationControlIncluded' => false,
                'writebackIncluded' => false,
                'explanation' => 'The matrix contains case schedule and operational product-readiness facts only; no patient identity, allocation control, or source-system command is exposed.',
            ],
        ];
    }

    /** @param list<int> $caseIds @return Collection<int, array<string, mixed>> */
    public function forCases(array $caseIds): Collection
    {
        $ids = collect($caseIds)->map(fn (mixed $id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->unique()->values()->all();
        if ($ids === []) {
            return collect();
        }

        return $this->gates($this->caseQuery()->whereIn('oc.case_id', $ids)->get())->keyBy('caseId');
    }

    public function activeOperatingDate(): ?string
    {
        $date = DB::table('prod.or_cases')->where('is_deleted', false)
            ->whereDate('surgery_date', '<=', now()->toDateString())->max('surgery_date')
            ?? DB::table('prod.or_cases')->where('is_deleted', false)->min('surgery_date');

        return $date === null ? null : CarbonImmutable::parse($date)->toDateString();
    }

    /** @return array<string, mixed> */
    private function filters(array $input): array
    {
        $state = is_string($input['state'] ?? null) && in_array($input['state'], ['all', ...self::GATE_STATES], true) ? $input['state'] : 'all';
        $productClass = is_string($input['productClass'] ?? null) && in_array($input['productClass'], ['all', ...self::PRODUCT_CLASSES], true) ? $input['productClass'] : 'all';
        $service = is_string($input['service'] ?? null) && trim($input['service']) !== '' ? trim($input['service']) : null;
        $room = is_string($input['room'] ?? null) && trim($input['room']) !== '' ? trim($input['room']) : null;
        $caseId = filter_var($input['caseId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return ['state' => $state, 'productClass' => $productClass, 'service' => $service, 'room' => $room, 'caseId' => $caseId === false ? null : $caseId];
    }

    /** @param Collection<int, object> $cases @return array<string, mixed> */
    private function filterOptions(Collection $cases): array
    {
        return [
            'states' => ['all', ...self::GATE_STATES],
            'productClasses' => ['all', ...self::PRODUCT_CLASSES],
            'services' => $cases->pluck('service_name')->filter()->unique()->sort()->values()->all(),
            'rooms' => $cases->pluck('room_name')->filter()->unique()->sort()->values()->all(),
        ];
    }

    private function caseQuery(): Builder
    {
        return DB::table('prod.or_cases as oc')
            ->join('prod.rooms as room', 'room.room_id', '=', 'oc.room_id')
            ->join('prod.services as service', 'service.service_id', '=', 'oc.case_service_id')
            ->join('prod.locations as location', 'location.location_id', '=', 'oc.location_id')
            ->where('oc.is_deleted', false)
            ->select([
                'oc.case_id', 'oc.surgery_date', 'oc.scheduled_start_time', 'oc.scheduled_duration',
                'room.name as room_name', 'service.name as service_name', 'location.name as location_name',
            ])->orderBy('oc.scheduled_start_time')->orderBy('oc.case_id');
    }

    /** @param Collection<int, object> $cases @return Collection<int, array<string, mixed>> */
    private function gates(Collection $cases): Collection
    {
        if ($cases->isEmpty()) {
            return collect();
        }
        $caseIds = $cases->pluck('case_id')->map(fn (mixed $id): int => (int) $id)->all();
        $requests = DB::table('prod.bb_readiness as b')
            ->join('prod.ancillary_orders as o', 'o.ancillary_order_id', '=', 'b.ancillary_order_id')
            ->join('integration.sources as source', 'source.source_id', '=', 'b.source_id')
            ->whereIn('b.case_id', $caseIds)
            ->orderBy('b.case_id')->orderBy('b.bb_readiness_id')
            ->get([
                'b.bb_readiness_id', 'b.readiness_uuid', 'b.case_id', 'b.product_class', 'b.readiness_state',
                'b.type_screen_state', 'b.crossmatch_state', 'b.units_requested', 'b.units_allocated', 'b.units_issued',
                'b.ordered_at', 'b.needed_by', 'b.type_screen_ready_at', 'b.crossmatch_ready_at', 'b.allocated_at', 'b.issued_at',
                'b.expires_at', 'b.mtp_activated_at', 'b.mtp_closed_at', 'b.cancelled_at', 'b.metadata',
                'o.order_uuid', 'o.source_cutoff_at', 'source.source_key',
            ])->groupBy('case_id');
        $registry = DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->first();
        $batchCutoff = $requests->flatten(1)->pluck('source_cutoff_at')->filter()->min() ?? $registry?->latest_observed_at;

        return $cases->map(function (object $case) use ($requests, $registry, $batchCutoff): array {
            /** @var Collection<int, object> $all */
            $all = $requests->get($case->case_id, collect());
            $active = $all->filter(fn (object $request): bool => $request->cancelled_at === null && $request->readiness_state !== 'cancelled')->values();
            $required = $active->isNotEmpty();
            $mtpActive = $active->contains(fn (object $request): bool => $request->mtp_activated_at !== null && $request->mtp_closed_at === null);
            $ready = $required && ! $mtpActive && $active->every(fn (object $request): bool => $this->requestReady($request));
            $cutoff = $active->pluck('source_cutoff_at')->filter()->min();
            $effectiveCutoff = $cutoff ?? $batchCutoff;
            $freshness = $this->freshness($effectiveCutoff, $registry);
            $scheduled = CarbonImmutable::parse($case->scheduled_start_time);
            $minutesToStart = (int) floor(($scheduled->getTimestamp() - now()->getTimestamp()) / 60);
            $neededByAligned = $required ? $active->every(function (object $request) use ($scheduled): bool {
                return $request->needed_by !== null && abs(CarbonImmutable::parse($request->needed_by)->getTimestamp() - $scheduled->getTimestamp()) <= 60;
            }) : null;
            $state = match (true) {
                $freshness['status'] !== 'fresh' => 'unknown',
                ! $required => 'not_applicable',
                $mtpActive => 'mtp_active',
                $ready => 'ready',
                default => 'blocked',
            };
            $requested = $active->sum(fn (object $request): int => (int) $request->units_requested);
            $allocated = $active->sum(fn (object $request): int => (int) $request->units_allocated);
            $issued = $active->sum(fn (object $request): int => (int) $request->units_issued);
            $metadata = $active->map(fn (object $request): array => $this->json($request->metadata));
            $explicitExplanation = $metadata->pluck('decision_context.explanation')->filter()->first();

            return [
                'caseId' => (int) $case->case_id,
                'caseLabel' => 'OR case '.(int) $case->case_id,
                'surgeryDate' => CarbonImmutable::parse($case->surgery_date)->toDateString(),
                'scheduledStartAt' => $scheduled->toAtomString(),
                'scheduledDurationMinutes' => (int) $case->scheduled_duration,
                'minutesToStart' => $minutesToStart,
                'startTiming' => $minutesToStart >= 0 ? 'upcoming' : 'past_due',
                'roomLabel' => (string) $case->room_name,
                'serviceLabel' => (string) $case->service_name,
                'locationLabel' => (string) $case->location_name,
                'required' => $required,
                'state' => $state,
                'ready' => $state === 'ready',
                'blocking' => in_array($state, ['blocked', 'mtp_active'], true),
                'mtpActive' => $mtpActive,
                'explanation' => match ($state) {
                    'unknown' => 'Blood Bank freshness is not current; readiness cannot be asserted.',
                    'not_applicable' => 'No active blood-product requirement is recorded for this case.',
                    'ready' => 'Every active request has type-and-screen and crossmatch readiness for the requested units.',
                    'mtp_active' => $explicitExplanation ?: 'Massive transfusion response is active; continuous product allocation remains an operational gate.',
                    default => $explicitExplanation ?: 'At least one required blood-product request has not reached crossmatch readiness.',
                },
                'requestCount' => $active->count(),
                'productClasses' => $active->pluck('product_class')->unique()->values()->all(),
                'units' => ['requested' => $requested, 'allocated' => $allocated, 'issued' => $issued],
                'typeScreenState' => $this->aggregateState($active->pluck('type_screen_state')),
                'crossmatchState' => $this->aggregateState($active->pluck('crossmatch_state')),
                'issueState' => ! $required ? 'not_applicable' : ($issued === 0 ? 'not_issued' : ($issued < $requested ? 'partial' : 'issued')),
                'neededByAt' => $active->pluck('needed_by')->filter()->min() === null ? null : CarbonImmutable::parse($active->pluck('needed_by')->filter()->min())->toAtomString(),
                'neededByAligned' => $neededByAligned,
                'sourceCutoffAt' => $effectiveCutoff === null ? null : CarbonImmutable::parse($effectiveCutoff)->toAtomString(),
                'freshness' => $freshness,
                'coverage' => [
                    'status' => ! $required ? 'not_applicable' : ($neededByAligned ? 'complete' : 'degraded'),
                    'explanation' => ! $required
                        ? 'No request coverage is required.'
                        : ($neededByAligned ? 'Every request needed-by time reconciles to the selected case schedule.' : 'At least one request lacks a needed-by time aligned to the selected case schedule.'),
                ],
                'requests' => $active->map(fn (object $request): array => [
                    'readinessUuid' => (string) $request->readiness_uuid,
                    'orderUuid' => (string) $request->order_uuid,
                    'productClass' => (string) $request->product_class,
                    'readinessState' => (string) $request->readiness_state,
                    'typeScreenState' => (string) $request->type_screen_state,
                    'crossmatchState' => (string) $request->crossmatch_state,
                    'unitsRequested' => (int) $request->units_requested,
                    'unitsAllocated' => (int) $request->units_allocated,
                    'unitsIssued' => (int) $request->units_issued,
                    'orderedAt' => CarbonImmutable::parse($request->ordered_at)->toAtomString(),
                    'neededByAt' => $request->needed_by === null ? null : CarbonImmutable::parse($request->needed_by)->toAtomString(),
                    'typeScreenReadyAt' => $request->type_screen_ready_at === null ? null : CarbonImmutable::parse($request->type_screen_ready_at)->toAtomString(),
                    'crossmatchReadyAt' => $request->crossmatch_ready_at === null ? null : CarbonImmutable::parse($request->crossmatch_ready_at)->toAtomString(),
                    'allocatedAt' => $request->allocated_at === null ? null : CarbonImmutable::parse($request->allocated_at)->toAtomString(),
                    'issuedAt' => $request->issued_at === null ? null : CarbonImmutable::parse($request->issued_at)->toAtomString(),
                    'expiresAt' => $request->expires_at === null ? null : CarbonImmutable::parse($request->expires_at)->toAtomString(),
                    'mtpActivatedAt' => $request->mtp_activated_at === null ? null : CarbonImmutable::parse($request->mtp_activated_at)->toAtomString(),
                    'sourceKey' => (string) $request->source_key,
                ])->all(),
                'drillHref' => '/lab/blood-bank?'.http_build_query(['caseId' => (int) $case->case_id]),
            ];
        });
    }

    /** @param Collection<int, string> $states */
    private function aggregateState(Collection $states): string
    {
        if ($states->isEmpty()) {
            return 'not_applicable';
        }
        $rank = ['incompatible' => 0, 'expired' => 1, 'pending' => 2, 'unknown' => 3, 'ready' => 4, 'not_required' => 5];

        return (string) $states->sortBy(fn (string $state): int => $rank[$state] ?? 3)->first();
    }

    private function requestReady(object $request): bool
    {
        if ($request->readiness_state === 'complete') {
            return true;
        }

        return in_array($request->type_screen_state, ['ready', 'not_required'], true)
            && in_array($request->crossmatch_state, ['ready', 'not_required'], true)
            && (in_array($request->readiness_state, self::READY_STATES, true)
                || ($request->readiness_state === 'type_screen_ready' && $request->crossmatch_state === 'not_required'));
    }

    /** @return array<string, mixed> */
    private function freshness(mixed $cutoff, ?object $registered = null): array
    {
        $registered ??= DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->first();
        $value = $cutoff ?? $registered?->latest_observed_at;
        if ($value === null) {
            return $this->contracts->freshness(new FreshnessEnvelope(
                'unknown', new \DateTimeImmutable(now()->toAtomString()), null, null,
                (string) ($registered?->source_label ?? 'Blood Bank operational feeds'),
                'No Blood Bank source cutoff is available.',
            ));
        }
        $at = CarbonImmutable::parse($value);
        $lag = max(0, (int) floor($at->diffInSeconds(now(), false) / 60));
        $registeredStatus = strtolower((string) ($registered?->status ?? 'current'));
        $stale = in_array($registeredStatus, ['stale', 'error', 'failed', 'unavailable'], true)
            || $lag > max(1, (int) ($registered?->warning_lag_minutes ?? 60));

        return $this->contracts->freshness(new FreshnessEnvelope(
            $stale ? 'stale' : 'fresh', new \DateTimeImmutable(now()->toAtomString()), new \DateTimeImmutable($at->toAtomString()), $lag,
            (string) ($registered?->source_label ?? 'Blood Bank operational feeds'),
            $stale ? 'The selected Blood Bank assertions exceed the registered freshness tolerance.' : null,
        ));
    }

    /** @return array<string, mixed> */
    private function json(mixed $value): array
    {
        return is_array($value) ? $value : (json_decode((string) ($value ?? '{}'), true) ?: []);
    }
}
