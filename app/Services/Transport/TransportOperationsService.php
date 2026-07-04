<?php

namespace App\Services\Transport;

use App\Models\Transport\TransportEvent;
use App\Models\Transport\TransportRequest;
use App\Support\Hospital\HospitalManifest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransportOperationsService
{
    public function __construct(private readonly HospitalManifest $hospital) {}

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

    public function list(array $filters = []): LengthAwarePaginator
    {
        return TransportRequest::query()
            ->where('is_deleted', false)
            ->forType($filters['request_type'] ?? null)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['priority'] ?? null, fn ($query, $priority) => $query->where('priority', $priority))
            ->orderByRaw("CASE priority WHEN 'stat' THEN 0 WHEN 'urgent' THEN 1 ELSE 2 END")
            ->orderByRaw('needed_at NULLS LAST')
            ->orderByDesc('transport_request_id')
            ->paginate(50);
    }

    public function overview(): array
    {
        $active = TransportRequest::active()->get();
        $allToday = TransportRequest::query()
            ->where('is_deleted', false)
            ->whereDate('requested_at', Carbon::today())
            ->get();

        $completedToday = $allToday->where('status', 'completed')->count();
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
            'by_type' => $this->countsBy($active, 'request_type'),
            'by_status' => $this->countsBy($active, 'status'),
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
                ->map(fn (TransportRequest $request) => $this->serializeRequest($request))
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
            $enRouteAt = $this->firstOccurrence($requestEvents, 'transport.en_route');
            $arrivedAt = $this->firstOccurrence($requestEvents, 'transport.arrived');
            $completedAt = $this->firstOccurrence($requestEvents, 'transport.completed');
            $notReady = $requestEvents->where('event_type', 'transport.not_ready');

            if ($requestedAt !== null && $assignedAt !== null && $assignedAt->gte($requestedAt)) {
                $requestToAssign[] = $requestedAt->diffInMinutes($assignedAt);
            }
            if ($enRouteAt !== null && $arrivedAt !== null && $arrivedAt->gte($enRouteAt)) {
                $dispatchToPickup[] = $enRouteAt->diffInMinutes($arrivedAt);
            }
            // P7: the single end-to-end patient wait (request → porter at
            // bedside), measured per request — NOT the sum of the two stage
            // averages, which double-counts nothing but averages different
            // request populations.
            if ($requestedAt !== null && $arrivedAt !== null && $arrivedAt->gte($requestedAt)) {
                $requestToPickup[] = $requestedAt->diffInMinutes($arrivedAt);
            }
            if ($arrivedAt !== null && $completedAt !== null && $completedAt->gte($arrivedAt)) {
                $pickupToDestination[] = $arrivedAt->diffInMinutes($completedAt);
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
            ->get(['assigned_team', 'status']);
        $totalRequests = $nonDeleted->count();
        $vendorAssigned = $nonDeleted->filter(fn (TransportRequest $request) => $this->isVendorTeam($request->assigned_team))->count();
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
                'caption' => round($avoidableDelayMinutes).' delay min',
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

        return round(array_sum($values) / count($values), 1);
    }

    private function isVendorTeam(?string $team): bool
    {
        if ($team === null || $team === '') {
            return false;
        }

        $needle = strtolower($team);

        foreach (['partner', 'transport', 'vendor', 'ambulance', 'ems', 'rideshare', 'uber', 'lyft'] as $token) {
            if (str_contains($needle, $token)) {
                return true;
            }
        }

        return false;
    }

    public function create(array $data, ?int $actorUserId): TransportRequest
    {
        return DB::transaction(function () use ($data, $actorUserId) {
            $request = TransportRequest::create(array_merge($data, [
                'request_uuid' => (string) Str::uuid(),
                'status' => 'requested',
                'requested_at' => $data['requested_at'] ?? now(),
                'created_by_user_id' => $actorUserId,
                'updated_by_user_id' => $actorUserId,
            ]));

            $this->recordEvent($request, 'transport.requested', null, 'requested', [
                'origin' => $request->origin,
                'destination' => $request->destination,
                'priority' => $request->priority,
                'request_type' => $request->request_type,
            ], $actorUserId);

            return $request->refresh();
        });
    }

    public function assign(TransportRequest $request, array $data, ?int $actorUserId): TransportRequest
    {
        return DB::transaction(function () use ($request, $data, $actorUserId) {
            $from = $request->status;
            $request->update([
                'status' => 'assigned',
                'assigned_team' => $data['assigned_team'] ?? $request->assigned_team,
                'assigned_vendor' => $data['assigned_vendor'] ?? $request->assigned_vendor,
                'assigned_at' => now(),
                'updated_by_user_id' => $actorUserId,
            ]);

            $this->recordEvent($request, 'transport.assigned', $from, 'assigned', $data, $actorUserId);

            return $request->refresh();
        });
    }

    public function transition(TransportRequest $request, string $status, array $payload, ?int $actorUserId): TransportRequest
    {
        return DB::transaction(function () use ($request, $status, $payload, $actorUserId) {
            $from = $request->status;
            $updates = [
                'status' => $status,
                'updated_by_user_id' => $actorUserId,
            ];

            if ($status === 'dispatched' && $request->dispatched_at === null) {
                $updates['dispatched_at'] = now();
            }
            if (in_array($status, ['completed', 'canceled', 'failed'], true) && $request->completed_at === null) {
                $updates['completed_at'] = now();
            }

            $request->update($updates);
            $this->recordEvent($request, "transport.{$status}", $from, $status, $payload, $actorUserId);

            return $request->refresh();
        });
    }

    public function completeHandoff(TransportRequest $request, array $data, ?int $actorUserId): TransportRequest
    {
        return DB::transaction(function () use ($request, $data, $actorUserId) {
            $from = $request->status;
            $request->update([
                'status' => 'handoff_complete',
                'handoff' => array_merge($request->handoff ?? [], [
                    'handoff_to' => $data['handoff_to'],
                    'handoff_summary' => $data['handoff_summary'] ?? null,
                    'documents' => $data['documents'] ?? [],
                    'outstanding_risks' => $data['outstanding_risks'] ?? [],
                    'completed_at' => now()->toISOString(),
                ]),
                'updated_by_user_id' => $actorUserId,
            ]);

            $this->recordEvent($request, 'transport.handoff_complete', $from, 'handoff_complete', $data, $actorUserId);

            return $request->refresh();
        });
    }

    public function serializeRequest(TransportRequest $request): array
    {
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
            'handoff' => $request->handoff ?? [],
            'metadata' => $request->metadata ?? [],
            'sla' => $this->sla($request),
        ];
    }

    public function vendorOptions(): array
    {
        return [
            ['key' => 'ride_health', 'name' => 'Ride Health', 'capabilities' => ['nemt', 'wheelchair', 'stretcher', 'eligibility', 'webhooks']],
            ['key' => 'uber_health', 'name' => 'Uber Health', 'capabilities' => ['rideshare', 'scheduled_rides', 'api_dispatch']],
            ['key' => 'lyft_healthcare', 'name' => 'Lyft Healthcare', 'capabilities' => ['rideshare', 'concierge_api', 'ride_status']],
            ['key' => 'contracted_ambulance', 'name' => 'Contracted Ambulance', 'capabilities' => ['bls', 'als', 'critical_care', 'manual_tender']],
            ['key' => 'careport', 'name' => 'CarePort / WellSky', 'capabilities' => ['post_acute_referral', 'adt_notifications', 'transition_packet']],
            ['key' => 'aidin', 'name' => 'Aidin', 'capabilities' => ['post_acute_referral', 'authorization', 'provider_network']],
            ['key' => 'pulsara', 'name' => 'Pulsara', 'capabilities' => ['ems_eta', 'prehospital_handoff', 'team_activation']],
        ];
    }

    public function resourceOptions(): array
    {
        return [
            ['key' => 'porter_pool', 'name' => $this->hospital->transport()['internal_team']['name'], 'type' => 'internal_team', 'available' => 7],
            ['key' => 'discharge_lounge', 'name' => 'Discharge Lounge', 'type' => 'handoff_area', 'available' => 5],
            ['key' => 'wheelchair_bank', 'name' => 'Wheelchair Bank', 'type' => 'equipment', 'available' => 18],
            ['key' => 'stretcher_pool', 'name' => 'Stretcher Pool', 'type' => 'equipment', 'available' => 9],
            ['key' => 'critical_care_team', 'name' => 'Critical Care Transport', 'type' => 'specialty_team', 'available' => 2],
        ];
    }

    private function recordEvent(TransportRequest $request, string $eventType, ?string $from, ?string $to, array $payload, ?int $actorUserId): TransportEvent
    {
        return TransportEvent::create([
            'event_uuid' => (string) Str::uuid(),
            'transport_request_id' => $request->transport_request_id,
            'event_type' => $eventType,
            'from_status' => $from,
            'to_status' => $to,
            'payload' => $payload,
            'actor_user_id' => $actorUserId,
            'occurred_at' => now(),
        ]);
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

        return $request->needed_at->isPast() && ! in_array($request->status, ['completed', 'canceled', 'failed'], true);
    }

    private function sla(TransportRequest $request): array
    {
        $minutesUntilDue = $request->needed_at ? now()->diffInMinutes($request->needed_at, false) : null;

        return [
            'minutes_until_due' => $minutesUntilDue,
            'at_risk' => $this->isAtRisk($request),
            'label' => $minutesUntilDue === null ? 'No target' : ($minutesUntilDue < 0 ? abs($minutesUntilDue).'m overdue' : $minutesUntilDue.'m remaining'),
        ];
    }
}
