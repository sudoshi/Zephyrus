<?php

namespace App\Services\Transport;

use App\Models\Transport\TransportEvent;
use App\Models\Transport\TransportRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransportOperationsService
{
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
        ];
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
            ['key' => 'porter_pool', 'name' => 'Porter Pool', 'type' => 'internal_team', 'available' => 7],
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
