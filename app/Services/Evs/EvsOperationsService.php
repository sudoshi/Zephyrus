<?php

namespace App\Services\Evs;

use App\Models\Evs\EvsEvent;
use App\Models\Evs\EvsRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EvsOperationsService
{
    public const ACTIVE_STATUSES = [
        'requested',
        'queued',
        'assigned',
        'in_progress',
        'escalated',
    ];

    public function list(array $filters = []): LengthAwarePaginator
    {
        return EvsRequest::query()
            ->where('is_deleted', false)
            ->forType($filters['request_type'] ?? null)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['priority'] ?? null, fn ($query, $priority) => $query->where('priority', $priority))
            ->orderByRaw("CASE priority WHEN 'stat' THEN 0 WHEN 'urgent' THEN 1 ELSE 2 END")
            ->orderByRaw('needed_at NULLS LAST')
            ->orderByDesc('evs_request_id')
            ->paginate(50);
    }

    public function overview(): array
    {
        $active = EvsRequest::active()->get();
        $allToday = EvsRequest::query()
            ->where('is_deleted', false)
            ->whereDate('requested_at', Carbon::today())
            ->get();

        $completedToday = $allToday->where('status', 'completed')->count();
        $atRisk = $active->filter(fn (EvsRequest $request): bool => $this->isAtRisk($request))->count();

        return [
            'metrics' => [
                'active' => $active->count(),
                'at_risk' => $atRisk,
                'completed_today' => $completedToday,
                'stat' => $active->where('priority', 'stat')->count(),
                'dirty_bed_turnovers' => $active->whereIn('request_type', ['bed_clean', 'discharge_turnover'])->count(),
                'isolation_cleans' => $active->where('isolation_required', true)->count(),
            ],
            'by_type' => $this->countsBy($active, 'request_type'),
            'by_status' => $this->countsBy($active, 'status'),
            'queue' => $active
                ->sort(function (EvsRequest $a, EvsRequest $b): int {
                    $priority = $this->priorityRank($a->priority) <=> $this->priorityRank($b->priority);
                    if ($priority !== 0) {
                        return $priority;
                    }

                    return ($a->needed_at?->timestamp ?? PHP_INT_MAX) <=> ($b->needed_at?->timestamp ?? PHP_INT_MAX);
                })
                ->take(12)
                ->values()
                ->map(fn (EvsRequest $request): array => $this->serializeRequest($request))
                ->all(),
            'resource_options' => $this->resourceOptions(),
        ];
    }

    public function create(array $data, ?int $actorUserId): EvsRequest
    {
        return DB::transaction(function () use ($data, $actorUserId): EvsRequest {
            $request = EvsRequest::create(array_merge($data, [
                'request_uuid' => (string) Str::uuid(),
                'status' => 'requested',
                'requested_at' => $data['requested_at'] ?? now(),
                'created_by_user_id' => $actorUserId,
                'updated_by_user_id' => $actorUserId,
            ]));

            $this->recordEvent($request, 'evs.requested', null, 'requested', [
                'location_label' => $request->location_label,
                'priority' => $request->priority,
                'request_type' => $request->request_type,
                'turn_type' => $request->turn_type,
            ], $actorUserId);

            return $request->refresh();
        });
    }

    public function assign(EvsRequest $request, array $data, ?int $actorUserId): EvsRequest
    {
        return DB::transaction(function () use ($request, $data, $actorUserId): EvsRequest {
            $from = $request->status;
            $request->update([
                'status' => 'assigned',
                'assigned_team' => $data['assigned_team'] ?? $request->assigned_team,
                'assigned_user_ref' => $data['assigned_user_ref'] ?? $request->assigned_user_ref,
                'assigned_at' => now(),
                'updated_by_user_id' => $actorUserId,
            ]);

            $this->recordEvent($request, 'evs.assigned', $from, 'assigned', $data, $actorUserId);

            return $request->refresh();
        });
    }

    public function transition(EvsRequest $request, string $status, array $payload, ?int $actorUserId): EvsRequest
    {
        return DB::transaction(function () use ($request, $status, $payload, $actorUserId): EvsRequest {
            $from = $request->status;
            $updates = [
                'status' => $status,
                'updated_by_user_id' => $actorUserId,
            ];

            if ($status === 'in_progress' && $request->started_at === null) {
                $updates['started_at'] = now();
            }

            if (in_array($status, ['completed', 'canceled', 'failed'], true) && $request->completed_at === null) {
                $updates['completed_at'] = now();
            }

            if ($status === 'completed') {
                $updates['completion_payload'] = $payload;
            }

            $request->update($updates);
            $this->recordEvent($request, "evs.{$status}", $from, $status, $payload, $actorUserId);

            return $request->refresh();
        });
    }

    public function serializeRequest(EvsRequest $request): array
    {
        return [
            'evs_request_id' => $request->evs_request_id,
            'request_uuid' => $request->request_uuid,
            'request_type' => $request->request_type,
            'priority' => $request->priority,
            'status' => $request->status,
            'room_id' => $request->room_id,
            'bed_id' => $request->bed_id,
            'unit_id' => $request->unit_id,
            'patient_ref' => $request->patient_ref,
            'encounter_ref' => $request->encounter_ref,
            'location_label' => $request->location_label,
            'turn_type' => $request->turn_type,
            'isolation_required' => $request->isolation_required,
            'requested_by' => $request->requested_by,
            'requested_at' => $request->requested_at?->toISOString(),
            'needed_at' => $request->needed_at?->toISOString(),
            'assigned_at' => $request->assigned_at?->toISOString(),
            'started_at' => $request->started_at?->toISOString(),
            'completed_at' => $request->completed_at?->toISOString(),
            'assigned_team' => $request->assigned_team,
            'assigned_user_ref' => $request->assigned_user_ref,
            'external_system' => $request->external_system,
            'external_id' => $request->external_id,
            'risk_flags' => $request->risk_flags ?? [],
            'completion_payload' => $request->completion_payload ?? [],
            'metadata' => $request->metadata ?? [],
            'sla' => $this->sla($request),
        ];
    }

    public function resourceOptions(): array
    {
        return [
            ['key' => 'evs_core_team', 'name' => 'EVS Core Team', 'type' => 'internal_team', 'available' => 8],
            ['key' => 'terminal_clean_team', 'name' => 'Terminal Clean Team', 'type' => 'specialty_team', 'available' => 3],
            ['key' => 'isolation_clean_cart', 'name' => 'Isolation Clean Cart', 'type' => 'equipment', 'available' => 5],
            ['key' => 'spill_response', 'name' => 'Spill Response', 'type' => 'rapid_response', 'available' => 2],
        ];
    }

    private function recordEvent(EvsRequest $request, string $eventType, ?string $from, ?string $to, array $payload, ?int $actorUserId): EvsEvent
    {
        return EvsEvent::create([
            'event_uuid' => (string) Str::uuid(),
            'evs_request_id' => $request->evs_request_id,
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

    private function isAtRisk(EvsRequest $request): bool
    {
        if ($request->priority === 'stat') {
            return true;
        }

        if ($request->needed_at === null) {
            return false;
        }

        return $request->needed_at->isPast() && ! in_array($request->status, ['completed', 'canceled', 'failed'], true);
    }

    private function sla(EvsRequest $request): array
    {
        $minutesUntilDue = $request->needed_at ? now()->diffInMinutes($request->needed_at, false) : null;

        return [
            'minutes_until_due' => $minutesUntilDue,
            'at_risk' => $this->isAtRisk($request),
            'label' => $minutesUntilDue === null ? 'No target' : ($minutesUntilDue < 0 ? abs($minutesUntilDue).'m overdue' : $minutesUntilDue.'m remaining'),
        ];
    }
}
