<?php

namespace App\Services\Transport;

use App\Models\Transport\TransportEvent;
use App\Models\Transport\TransportRequest;
use App\Models\User;
use App\Support\Api\JsonMap;
use App\Support\Operations\DurationFormatter;
use App\Support\Operations\SourceFreshness;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class TransportOperationsService
{
    public function __construct(private readonly TransportLifecycleService $lifecycle) {}

    /** Trailing window for measures() — operational averages, not archives. */
    public const MEASURE_WINDOW_DAYS = 7;

    public const ACTIVE_STATUSES = [
        'requested',
        'accepted',
        'queued',
        'assigned',
        'dispatched',
        'arrived_pickup',
        'patient_ready',
        'patient_not_ready',
        'picked_up',
        'en_route',
        'arrived_destination',
        'handoff_started',
        'handoff_complete',
        'escalated',
    ];

    public const DISPATCH_STATUSES = [
        'requested',
        'accepted',
        'queued',
        'assigned',
        'dispatched',
        'arrived_pickup',
        'patient_ready',
        'patient_not_ready',
        'escalated',
    ];

    public const TERMINAL_STATUSES = ['completed', 'canceled', 'failed'];

    public function list(array $filters = []): CursorPaginator
    {
        $perPage = min(
            max(1, (int) ($filters['per_page'] ?? config('transport.pagination.default_per_page', 25))),
            (int) config('transport.pagination.max_per_page', 100),
        );
        $cursor = null;
        if (filled($filters['cursor'] ?? null)) {
            $cursor = $this->decodeCursor((string) $filters['cursor']);
        }

        return TransportRequest::query()
            ->with('activeAssignment.resource', 'handoffEvidence')
            ->where('is_deleted', false)
            ->forType($filters['request_type'] ?? null)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['priority'] ?? null, fn ($query, $priority) => $query->where('priority', $priority))
            ->when(($filters['scope'] ?? null) === 'active', fn ($query) => $query->whereIn('status', self::ACTIVE_STATUSES))
            ->when(($filters['scope'] ?? null) === 'dispatch', fn ($query) => $query->whereIn('status', self::DISPATCH_STATUSES))
            ->when(($filters['scope'] ?? null) === 'history', fn ($query) => $query->whereIn('status', self::TERMINAL_STATUSES))
            ->when(isset($filters['mobile_actor_user_id']), function ($query) use ($filters): void {
                $actorUserId = (int) $filters['mobile_actor_user_id'];
                $query->where(function ($scope) use ($actorUserId): void {
                    $scope->whereDoesntHave('activeAssignment')
                        ->orWhereHas('activeAssignment.resource', fn ($resource) => $resource
                            ->where('actor_user_id', $actorUserId));
                });
            })
            ->orderBy('priority_rank')
            ->orderBy('needed_at_sort')
            ->orderByDesc('transport_request_id')
            ->cursorPaginate($perPage, ['*'], 'cursor', $cursor);
    }

    private function decodeCursor(string $encoded): Cursor
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        $parameters = $decoded === false ? null : json_decode($decoded, true);
        $expectedKeys = ['_pointsToNextItems', 'needed_at_sort', 'priority_rank', 'transport_request_id'];
        $actualKeys = is_array($parameters) ? array_keys($parameters) : [];
        sort($actualKeys);

        $valid = is_array($parameters)
            && $actualKeys === $expectedKeys
            && is_bool($parameters['_pointsToNextItems'])
            && $this->isCursorInteger($parameters['priority_rank'])
            && (int) $parameters['priority_rank'] >= 0
            && (int) $parameters['priority_rank'] <= 2
            && is_string($parameters['needed_at_sort'])
            && $parameters['needed_at_sort'] !== ''
            && $this->isCursorTimestamp($parameters['needed_at_sort'])
            && $this->isCursorInteger($parameters['transport_request_id'])
            && (int) $parameters['transport_request_id'] > 0;
        if (! $valid) {
            throw ValidationException::withMessages(['cursor' => 'The transport cursor is invalid.']);
        }

        $pointsToNextItems = $parameters['_pointsToNextItems'];
        unset($parameters['_pointsToNextItems']);

        return new Cursor($parameters, $pointsToNextItems);
    }

    private function isCursorInteger(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value) === 1);
    }

    private function isCursorTimestamp(string $value): bool
    {
        if ($value === 'infinity') {
            return true;
        }

        try {
            Carbon::parse($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function overview(?User $actor = null): array
    {
        $active = TransportRequest::active()->get();
        $completedToday = TransportEvent::query()
            ->where('event_type', 'transport.completed')
            ->whereDate('occurred_at', Carbon::today())
            ->distinct('transport_request_id')
            ->count('transport_request_id');
        $atRisk = $active->filter(fn (TransportRequest $request) => $this->isAtRisk($request))->count();

        return [
            'metrics' => [
                'active' => $active->count(),
                'at_risk' => $atRisk,
                'completed_today' => $completedToday,
                'stat' => $active->where('priority', 'stat')->count(),
                'transfer_backlog' => $active->where('request_type', 'transfer')->count(),
                'discharge_rides' => $active->where('request_type', 'discharge')->count(),
                'ems_inbound' => $active->where('request_type', 'ems')->count(),
            ],
            'source' => $this->sourceFreshness(),
            'by_type' => JsonMap::from($this->countsBy($active, 'request_type')),
            'by_status' => JsonMap::from($this->countsBy($active, 'status')),
            'queue' => $active
                ->sort(function (TransportRequest $a, TransportRequest $b) {
                    $priority = $this->priorityRank($a->priority) <=> $this->priorityRank($b->priority);
                    if ($priority !== 0) {
                        return $priority;
                    }

                    return ($a->needed_at?->timestamp ?? PHP_INT_MAX) <=> ($b->needed_at?->timestamp ?? PHP_INT_MAX);
                })
                ->take(12)
                ->values()
                ->map(fn (TransportRequest $request) => $this->serializeRequest($request, $actor))
                ->all(),
            'vendor_options' => $this->vendorOptions(),
            'resource_options' => $this->resourceOptions(),
            'measures' => $this->measures(),
        ];
    }

    /**
     * Operational throughput + delay measures computed from the transport_events
     * lifecycle, pivoted per transport_request_id. Deterministic and safe on
     * empty tables (every aggregate guards against division by zero / nulls).
     *
     * P7: bounded to the trailing MEASURE_WINDOW_DAYS — these are operational
     * "how are we running" measures, and an unbounded scan re-averaged the
     * entire event history on every call (cost grows forever, and month-old
     * transports have no business moving today's wait number).
     *
     * @return list<array{key: string, label: string, value: float|int|null, unit: string, caption: string}>
     */
    public function measures(): array
    {
        $windowStart = Carbon::now()->subDays(self::MEASURE_WINDOW_DAYS);

        $events = TransportEvent::query()
            ->whereIn('transport_request_id', TransportRequest::query()
                ->where('is_deleted', false)
                ->where('requested_at', '>=', $windowStart)
                ->select('transport_request_id'))
            ->orderBy('occurred_at')
            ->get(['transport_request_id', 'event_type', 'payload', 'occurred_at']);

        $byRequest = $events->groupBy('transport_request_id');

        $requestToAssign = [];
        $dispatchToPickup = [];
        $requestToPickup = [];
        $pickupToDestination = [];
        $completedCount = 0;
        $completedWithNotReady = 0;
        $avoidableDelayMinutes = 0.0;

        foreach ($byRequest as $requestEvents) {
            $requestedAt = $this->firstOccurrence($requestEvents, 'transport.requested');
            $assignedAt = $this->firstOccurrence($requestEvents, 'transport.assigned');
            $completedAt = $this->firstOccurrence($requestEvents, 'transport.completed');
            // Canonical lifecycle events distinguish dispatch, pickup arrival,
            // patient pickup, and destination arrival. The fallbacks retain
            // equivalent calculations for the pre-governance compact timeline.
            $dispatchedAt = $this->firstOccurrence($requestEvents, 'transport.dispatched')
                ?? $this->firstOccurrence($requestEvents, 'transport.en_route');
            $arrivedPickupAt = $this->firstOccurrence($requestEvents, 'transport.arrived_pickup')
                ?? $this->firstOccurrence($requestEvents, 'transport.arrived');
            $pickedUpAt = $this->firstOccurrence($requestEvents, 'transport.picked_up')
                ?? $arrivedPickupAt;
            $arrivedDestinationAt = $this->firstOccurrence($requestEvents, 'transport.arrived_destination')
                ?? $completedAt;
            $notReady = $requestEvents->where('event_type', 'transport.not_ready');

            if ($requestedAt !== null && $assignedAt !== null && $assignedAt->gte($requestedAt)) {
                $requestToAssign[] = $requestedAt->diffInMinutes($assignedAt);
            }
            if ($dispatchedAt !== null && $arrivedPickupAt !== null && $arrivedPickupAt->gte($dispatchedAt)) {
                $dispatchToPickup[] = $dispatchedAt->diffInMinutes($arrivedPickupAt);
            }
            // P7: the single end-to-end patient wait (request → porter at
            // bedside), measured per request — NOT the sum of the two stage
            // averages, which double-counts nothing but averages different
            // request populations.
            if ($requestedAt !== null && $arrivedPickupAt !== null && $arrivedPickupAt->gte($requestedAt)) {
                $requestToPickup[] = $requestedAt->diffInMinutes($arrivedPickupAt);
            }
            if ($pickedUpAt !== null && $arrivedDestinationAt !== null && $arrivedDestinationAt->gte($pickedUpAt)) {
                $pickupToDestination[] = $pickedUpAt->diffInMinutes($arrivedDestinationAt);
            }

            if ($completedAt !== null) {
                $completedCount++;
                if ($notReady->isNotEmpty()) {
                    $completedWithNotReady++;
                }
            }

            foreach ($notReady as $event) {
                $avoidableDelayMinutes += (float) (($event->payload['not_ready_delay_min'] ?? 0));
            }
        }

        $nonDeleted = TransportRequest::query()
            ->where('is_deleted', false)
            ->where('requested_at', '>=', $windowStart)
            ->get(['assigned_team', 'assigned_vendor', 'status']);
        $totalRequests = $nonDeleted->count();
        $vendorAssigned = $nonDeleted->filter(fn (TransportRequest $request) => filled($request->assigned_vendor))->count();
        $canceled = $nonDeleted->where('status', 'canceled')->count();
        $vendorShare = $totalRequests > 0 ? round(($vendorAssigned / $totalRequests) * 100, 1) : null;
        $cancellationRate = $totalRequests > 0 ? round(($canceled / $totalRequests) * 100, 1) : null;
        $notReadyRate = $completedCount > 0 ? round(($completedWithNotReady / $completedCount) * 100, 1) : null;

        return [
            [
                'key' => 'request_to_assign_min',
                'label' => 'Request-to-assign minutes',
                'value' => $this->avgMinutes($requestToAssign),
                'unit' => 'min',
                'caption' => count($requestToAssign).' assigned',
            ],
            [
                'key' => 'dispatch_to_pickup_min',
                'label' => 'Dispatch-to-pickup minutes',
                'value' => $this->avgMinutes($dispatchToPickup),
                'unit' => 'min',
                'caption' => count($dispatchToPickup).' en route',
            ],
            [
                'key' => 'request_to_pickup_min',
                'label' => 'Request-to-pickup minutes',
                'value' => $this->avgMinutes($requestToPickup),
                'unit' => 'min',
                'caption' => count($requestToPickup).' picked up',
            ],
            [
                'key' => 'pickup_to_destination_min',
                'label' => 'Pickup-to-destination minutes',
                'value' => $this->avgMinutes($pickupToDestination),
                'unit' => 'min',
                'caption' => count($pickupToDestination).' delivered',
            ],
            [
                'key' => 'patient_not_ready_rate',
                'label' => 'Patient-not-ready delay rate',
                'value' => $notReadyRate,
                'unit' => '%',
                'caption' => $completedWithNotReady.' of '.$completedCount.' completed',
            ],
            [
                'key' => 'avoidable_bed_hours',
                'label' => 'Avoidable bed-hours attributed to transport',
                'value' => round($avoidableDelayMinutes / 60, 1),
                'unit' => 'hrs',
                'caption' => DurationFormatter::minutes($avoidableDelayMinutes).' attributable delay',
            ],
            [
                'key' => 'vendor_acceptance_cancellation',
                'label' => 'Vendor acceptance and cancellation rate',
                'value' => $vendorShare,
                'unit' => '% vendor',
                'caption' => ($cancellationRate ?? 0).'% canceled ('.$canceled.' of '.$totalRequests.')',
            ],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, TransportEvent>  $events
     */
    private function firstOccurrence($events, string $eventType): ?Carbon
    {
        return $events->firstWhere('event_type', $eventType)?->occurred_at;
    }

    /**
     * @param  list<int|float>  $values
     */
    private function avgMinutes(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return array_sum($values) / count($values);
    }

    public function create(array $data, User $actor, string $idempotencyKey, string $source = 'web'): TransportRequest
    {
        return $this->lifecycle->create($data, $actor, $idempotencyKey, $source);
    }

    public function assign(
        TransportRequest $request,
        array $data,
        User $actor,
        string $idempotencyKey,
        string $source = 'web',
        bool $claim = false,
    ): TransportRequest {
        return $this->lifecycle->assign($request, $data, $actor, $idempotencyKey, $source, $claim);
    }

    public function transition(
        TransportRequest $request,
        string $status,
        array $payload,
        User $actor,
        string $idempotencyKey,
        string $source = 'web',
    ): TransportRequest {
        return $this->lifecycle->transition($request, $status, $payload, $actor, $idempotencyKey, $source);
    }

    public function completeHandoff(
        TransportRequest $request,
        array $data,
        User $actor,
        string $idempotencyKey,
        string $source = 'web',
    ): TransportRequest {
        return $this->lifecycle->completeHandoff($request, $data, $actor, $idempotencyKey, $source);
    }

    public function serializeRequest(TransportRequest $request, ?User $actor = null): array
    {
        $request->loadMissing('activeAssignment.resource', 'handoffEvidence');
        $assignment = $request->activeAssignment;

        return [
            'transport_request_id' => $request->transport_request_id,
            'request_uuid' => $request->request_uuid,
            'request_type' => $request->request_type,
            'priority' => $request->priority,
            'status' => $request->status,
            'patient_ref' => $request->patient_ref,
            'encounter_ref' => $request->encounter_ref,
            'origin' => $request->origin,
            'destination' => $request->destination,
            'transport_mode' => $request->transport_mode,
            'clinical_service' => $request->clinical_service,
            'requested_by' => $request->requested_by,
            'requested_at' => $request->requested_at?->toISOString(),
            'needed_at' => $request->needed_at?->toISOString(),
            'assigned_at' => $request->assigned_at?->toISOString(),
            'dispatched_at' => $request->dispatched_at?->toISOString(),
            'completed_at' => $request->completed_at?->toISOString(),
            'assigned_team' => $request->assigned_team,
            'assigned_vendor' => $request->assigned_vendor,
            'external_system' => $request->external_system,
            'external_id' => $request->external_id,
            'segments' => $request->segments ?? [],
            'risk_flags' => $request->risk_flags ?? [],
            'handoff' => JsonMap::from($request->handoff),
            'handoff_required' => (bool) $request->handoff_required,
            'handoff_evidence' => $request->handoffEvidence ? [
                'evidence_uuid' => $request->handoffEvidence->evidence_uuid,
                'handoff_to' => $request->handoffEvidence->handoff_to,
                'receiver_role' => $request->handoffEvidence->receiver_role,
                'acceptance_status' => $request->handoffEvidence->acceptance_status,
                'accepted_at' => $request->handoffEvidence->accepted_at?->toISOString(),
                'handoff_summary' => $request->handoffEvidence->handoff_summary,
                'documents' => $request->handoffEvidence->documents ?? [],
                'outstanding_risks' => $request->handoffEvidence->outstanding_risks ?? [],
            ] : null,
            'active_assignment' => $assignment ? [
                'assignment_uuid' => $assignment->assignment_uuid,
                'resource_key' => $assignment->resource?->resource_key,
                'resource_type' => $assignment->resource?->resource_type,
                'resource_name' => $assignment->resource?->display_name,
                'capacity_units' => $assignment->capacity_units,
                'reserved_from' => $assignment->reserved_from?->toISOString(),
            ] : null,
            'lifecycle_version' => (int) $request->lifecycle_version,
            'allowed_transitions' => $actor ? $this->lifecycle->allowedTransitions($request, $actor) : [],
            'permissions' => [
                'can_assign' => $actor ? $this->lifecycle->canAssign($request, $actor) : false,
                'can_handoff' => $actor ? $this->lifecycle->canCompleteHandoff($request, $actor) : false,
            ],
            'metadata' => JsonMap::from($request->metadata),
            'sla' => $this->sla($request),
        ];
    }

    public function vendorOptions(): array
    {
        return $this->lifecycle->resourceOptions('vendor');
    }

    public function resourceOptions(): array
    {
        return array_values(array_filter(
            $this->lifecycle->resourceOptions(),
            fn (array $resource): bool => $resource['type'] !== 'vendor',
        ));
    }

    /** @return array<string,mixed> */
    private function sourceFreshness(): array
    {
        $latestEvent = TransportEvent::query()->orderByDesc('occurred_at')->first(['occurred_at']);
        $latestRequest = TransportRequest::query()
            ->where('is_deleted', false)
            ->orderByDesc('updated_at')
            ->first(['updated_at', 'metadata', 'requested_by']);
        $lastObservedAt = collect([$latestEvent?->occurred_at, $latestRequest?->updated_at])
            ->filter()
            ->sortByDesc(fn (Carbon $value): int => $value->getTimestamp())
            ->first();

        return SourceFreshness::make(
            key: 'prod.transport_operations',
            label: 'Transport operations data',
            lastObservedAt: $lastObservedAt,
            expectedCadenceMinutes: 15,
            staleAfterMinutes: 60,
            synthetic: (bool) data_get($latestRequest?->metadata, 'synthetic', false)
                || data_get($latestRequest?->metadata, 'data_origin') === 'synthetic'
                || $latestRequest?->requested_by === 'demo-seeder'
                || str_starts_with((string) $latestRequest?->requested_by, 'operations-demo:'),
        );
    }

    private function countsBy($collection, string $field): array
    {
        return $collection
            ->groupBy($field)
            ->map(fn ($items) => $items->count())
            ->sortKeys()
            ->all();
    }

    private function priorityRank(string $priority): int
    {
        return match ($priority) {
            'stat' => 0,
            'urgent' => 1,
            default => 2,
        };
    }

    private function isAtRisk(TransportRequest $request): bool
    {
        if ($request->priority === 'stat') {
            return true;
        }

        if ($request->needed_at === null) {
            return false;
        }

        return $request->needed_at->isPast() && ! in_array($request->status, self::TERMINAL_STATUSES, true);
    }

    private function sla(TransportRequest $request): array
    {
        $minutesUntilDue = $request->needed_at
            ? ((int) round(now()->diffInSeconds($request->needed_at, false))) / 60
            : null;

        return [
            'minutes_until_due' => $minutesUntilDue,
            'at_risk' => $this->isAtRisk($request),
            'label' => DurationFormatter::relativeMinutes($minutesUntilDue),
        ];
    }
}
