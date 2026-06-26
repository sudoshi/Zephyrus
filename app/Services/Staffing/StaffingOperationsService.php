<?php

namespace App\Services\Staffing;

use App\Models\Staffing\StaffingEvent;
use App\Models\Staffing\StaffingPlan;
use App\Models\Staffing\StaffingRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StaffingOperationsService
{
    public const ACTIVE_REQUEST_STATUSES = [
        'requested',
        'open',
        'sourcing',
        'assigned',
        'escalated',
    ];

    public const ROLE_LABELS = [
        'rn' => 'Registered Nurse',
        'lpn' => 'Licensed Practical Nurse',
        'tech' => 'Patient Care Tech',
        'charge' => 'Charge Nurse',
        'provider' => 'Provider',
        'respiratory' => 'Respiratory Therapist',
        'unit_secretary' => 'Unit Secretary',
    ];

    public function list(array $filters = []): LengthAwarePaginator
    {
        return StaffingRequest::query()
            ->where('is_deleted', false)
            ->forRole($filters['role'] ?? null)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['priority'] ?? null, fn ($query, $priority) => $query->where('priority', $priority))
            ->when($filters['unit_id'] ?? null, fn ($query, $unitId) => $query->where('unit_id', $unitId))
            ->orderByRaw("CASE priority WHEN 'stat' THEN 0 WHEN 'urgent' THEN 1 ELSE 2 END")
            ->orderByRaw('needed_by NULLS LAST')
            ->orderByDesc('staffing_request_id')
            ->paginate(50);
    }

    /** @return array<string,mixed> */
    public function overview(): array
    {
        $plans = $this->todaysPlans();
        $active = StaffingRequest::active()->get();

        $coverage = $this->coverageSummary($plans);
        $atRiskUnits = $this->unitsAtRisk($plans);

        return [
            'metrics' => [
                'open_requests' => $active->count(),
                'at_risk_units' => count($atRiskUnits),
                'critical_gaps' => $plans->filter(fn (StaffingPlan $plan): bool => $plan->status === 'critical_gap')->count(),
                'unfilled_requests' => StaffingRequest::query()
                    ->where('is_deleted', false)
                    ->where('status', 'unfilled')
                    ->count(),
                'total_gap_headcount' => $coverage['total_gap_headcount'],
                'coverage_pct' => $coverage['coverage_pct'],
                'stat_requests' => $active->where('priority', 'stat')->count(),
            ],
            'coverage' => $coverage,
            'units_at_risk' => $atRiskUnits,
            'by_role' => $this->gapByRole($plans),
            'queue' => $active
                ->sort(function (StaffingRequest $a, StaffingRequest $b): int {
                    $priority = $this->priorityRank($a->priority) <=> $this->priorityRank($b->priority);
                    if ($priority !== 0) {
                        return $priority;
                    }

                    return ($a->needed_by?->timestamp ?? PHP_INT_MAX) <=> ($b->needed_by?->timestamp ?? PHP_INT_MAX);
                })
                ->take(12)
                ->values()
                ->map(fn (StaffingRequest $request): array => $this->serializeRequest($request))
                ->all(),
            'resource_options' => $this->resourceOptions(),
        ];
    }

    public function create(array $data, ?int $actorUserId): StaffingRequest
    {
        return DB::transaction(function () use ($data, $actorUserId): StaffingRequest {
            $request = StaffingRequest::create(array_merge($data, [
                'request_uuid' => (string) Str::uuid(),
                'status' => 'requested',
                'shift_date' => $data['shift_date'] ?? now()->toDateString(),
                'created_by_user_id' => $actorUserId,
                'updated_by_user_id' => $actorUserId,
            ]));

            $this->recordEvent($request, 'staffing.requested', null, 'requested', [
                'unit_label' => $request->unit_label,
                'role' => $request->role,
                'shift' => $request->shift,
                'priority' => $request->priority,
                'request_type' => $request->request_type,
                'headcount_needed' => $request->headcount_needed,
            ], $actorUserId);

            return $request->refresh();
        });
    }

    public function assign(StaffingRequest $request, array $data, ?int $actorUserId): StaffingRequest
    {
        return DB::transaction(function () use ($request, $data, $actorUserId): StaffingRequest {
            $from = $request->status;
            $request->update([
                'status' => 'assigned',
                'assigned_source' => $data['assigned_source'] ?? $request->assigned_source,
                'assigned_staff_ref' => $data['assigned_staff_ref'] ?? $request->assigned_staff_ref,
                'owner_name' => $data['owner_name'] ?? $request->owner_name,
                'assigned_at' => now(),
                'updated_by_user_id' => $actorUserId,
            ]);

            $this->recordEvent($request, 'staffing.assigned', $from, 'assigned', $data, $actorUserId);

            return $request->refresh();
        });
    }

    public function transition(StaffingRequest $request, string $status, array $payload, ?int $actorUserId): StaffingRequest
    {
        return DB::transaction(function () use ($request, $status, $payload, $actorUserId): StaffingRequest {
            $from = $request->status;
            $updates = [
                'status' => $status,
                'updated_by_user_id' => $actorUserId,
            ];

            if ($status === 'filled' && $request->filled_at === null) {
                $updates['filled_at'] = now();
            }

            if (in_array($status, ['completed', 'canceled', 'unfilled'], true) && $request->completed_at === null) {
                $updates['completed_at'] = now();
            }

            if (in_array($status, ['filled', 'completed'], true)) {
                $updates['resolution_payload'] = $payload;
            }

            $request->update($updates);
            $this->recordEvent($request, "staffing.{$status}", $from, $status, $payload, $actorUserId);

            return $request->refresh();
        });
    }

    /** @return Collection<int,StaffingPlan> */
    public function todaysPlans(): Collection
    {
        return StaffingPlan::query()
            ->where('is_deleted', false)
            ->whereDate('shift_date', Carbon::today())
            ->orderBy('unit_label')
            ->orderBy('role')
            ->get();
    }

    /**
     * @param  Collection<int,StaffingPlan>  $plans
     * @return array<string,mixed>
     */
    public function coverageSummary(Collection $plans): array
    {
        $required = (int) $plans->sum('required_count');
        $available = (int) $plans->sum(fn (StaffingPlan $plan): int => max($plan->scheduled_count, $plan->actual_count));
        $gap = (int) $plans->sum(fn (StaffingPlan $plan): int => $plan->gap());

        return [
            'required_count' => $required,
            'available_count' => $available,
            'total_gap_headcount' => $gap,
            'coverage_pct' => $required > 0 ? (int) round($available / $required * 100) : 100,
            'below_minimum_safe' => $plans->filter(fn (StaffingPlan $plan): bool => $plan->belowMinimumSafe())->count(),
        ];
    }

    /**
     * Units with an active coverage gap, sorted worst-first. This is the signal
     * behind "staffing is tight on two units" in the operational demo.
     *
     * @param  Collection<int,StaffingPlan>  $plans
     * @return array<int,array<string,mixed>>
     */
    public function unitsAtRisk(Collection $plans): array
    {
        return $plans
            ->filter(fn (StaffingPlan $plan): bool => $plan->gap() > 0 || $plan->belowMinimumSafe())
            ->groupBy(fn (StaffingPlan $plan): string => $plan->unit_label)
            ->map(function (Collection $unitPlans, string $unitLabel): array {
                $worst = $unitPlans->sortByDesc(fn (StaffingPlan $plan): int => $plan->gap())->first();

                return [
                    'unit_id' => $worst->unit_id,
                    'unit_label' => $unitLabel,
                    'gap_headcount' => (int) $unitPlans->sum(fn (StaffingPlan $plan): int => $plan->gap()),
                    'worst_role' => $worst->role,
                    'worst_role_label' => self::ROLE_LABELS[$worst->role] ?? $worst->role,
                    'status' => $unitPlans->contains(fn (StaffingPlan $plan): bool => $plan->status === 'critical_gap') ? 'critical_gap' : 'gap',
                    'below_minimum_safe' => $unitPlans->contains(fn (StaffingPlan $plan): bool => $plan->belowMinimumSafe()),
                    'roles' => $unitPlans
                        ->filter(fn (StaffingPlan $plan): bool => $plan->gap() > 0)
                        ->map(fn (StaffingPlan $plan): array => $this->serializePlan($plan))
                        ->values()
                        ->all(),
                ];
            })
            ->sortByDesc('gap_headcount')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,StaffingPlan>  $plans
     * @return array<int,array<string,mixed>>
     */
    public function gapByRole(Collection $plans): array
    {
        return $plans
            ->groupBy('role')
            ->map(fn (Collection $rolePlans, string $role): array => [
                'role' => $role,
                'role_label' => self::ROLE_LABELS[$role] ?? $role,
                'gap_headcount' => (int) $rolePlans->sum(fn (StaffingPlan $plan): int => $plan->gap()),
                'required_count' => (int) $rolePlans->sum('required_count'),
                'available_count' => (int) $rolePlans->sum(fn (StaffingPlan $plan): int => max($plan->scheduled_count, $plan->actual_count)),
            ])
            ->filter(fn (array $row): bool => $row['gap_headcount'] > 0)
            ->sortByDesc('gap_headcount')
            ->values()
            ->all();
    }

    public function serializePlan(StaffingPlan $plan): array
    {
        return [
            'staffing_plan_id' => $plan->staffing_plan_id,
            'plan_uuid' => $plan->plan_uuid,
            'unit_id' => $plan->unit_id,
            'unit_label' => $plan->unit_label,
            'role' => $plan->role,
            'role_label' => self::ROLE_LABELS[$plan->role] ?? $plan->role,
            'shift_date' => $plan->shift_date?->toDateString(),
            'shift' => $plan->shift,
            'required_count' => $plan->required_count,
            'scheduled_count' => $plan->scheduled_count,
            'actual_count' => $plan->actual_count,
            'minimum_safe_count' => $plan->minimum_safe_count,
            'census' => $plan->census,
            'ratio_target' => $plan->ratio_target,
            'gap_headcount' => $plan->gap(),
            'below_minimum_safe' => $plan->belowMinimumSafe(),
            'status' => $plan->status,
            'notes' => $plan->notes,
            'constraints' => $plan->constraints ?? [],
        ];
    }

    public function serializeRequest(StaffingRequest $request): array
    {
        return [
            'staffing_request_id' => $request->staffing_request_id,
            'request_uuid' => $request->request_uuid,
            'unit_id' => $request->unit_id,
            'unit_label' => $request->unit_label,
            'staffing_plan_id' => $request->staffing_plan_id,
            'role' => $request->role,
            'role_label' => self::ROLE_LABELS[$request->role] ?? $request->role,
            'shift_date' => $request->shift_date?->toDateString(),
            'shift' => $request->shift,
            'request_type' => $request->request_type,
            'priority' => $request->priority,
            'status' => $request->status,
            'headcount_needed' => $request->headcount_needed,
            'hours_needed' => $request->hours_needed,
            'requested_by' => $request->requested_by,
            'needed_by' => $request->needed_by?->toISOString(),
            'assigned_at' => $request->assigned_at?->toISOString(),
            'filled_at' => $request->filled_at?->toISOString(),
            'completed_at' => $request->completed_at?->toISOString(),
            'assigned_source' => $request->assigned_source,
            'assigned_staff_ref' => $request->assigned_staff_ref,
            'owner_name' => $request->owner_name,
            'risk_flags' => $request->risk_flags ?? [],
            'resolution_payload' => $request->resolution_payload ?? [],
            'metadata' => $request->metadata ?? [],
            'sla' => $this->sla($request),
        ];
    }

    public function resourceOptions(): array
    {
        return [
            ['key' => 'float_pool', 'name' => 'Float Pool', 'type' => 'internal_pool', 'available' => 6],
            ['key' => 'overtime', 'name' => 'Overtime Offer', 'type' => 'incentive', 'available' => 12],
            ['key' => 'agency', 'name' => 'Agency / Traveler', 'type' => 'external', 'available' => 4],
            ['key' => 'on_call', 'name' => 'On-Call Activation', 'type' => 'standby', 'available' => 3],
        ];
    }

    private function recordEvent(StaffingRequest $request, string $eventType, ?string $from, ?string $to, array $payload, ?int $actorUserId): StaffingEvent
    {
        return StaffingEvent::create([
            'event_uuid' => (string) Str::uuid(),
            'staffing_request_id' => $request->staffing_request_id,
            'event_type' => $eventType,
            'from_status' => $from,
            'to_status' => $to,
            'payload' => $payload,
            'actor_user_id' => $actorUserId,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function priorityRank(string $priority): int
    {
        return match ($priority) {
            'stat' => 0,
            'urgent' => 1,
            default => 2,
        };
    }

    private function isAtRisk(StaffingRequest $request): bool
    {
        if ($request->priority === 'stat') {
            return true;
        }

        if ($request->needed_by === null) {
            return false;
        }

        return $request->needed_by->isPast() && in_array($request->status, self::ACTIVE_REQUEST_STATUSES, true);
    }

    private function sla(StaffingRequest $request): array
    {
        $minutesUntilDue = $request->needed_by ? now()->diffInMinutes($request->needed_by, false) : null;

        return [
            'minutes_until_due' => $minutesUntilDue,
            'at_risk' => $this->isAtRisk($request),
            'label' => $minutesUntilDue === null ? 'No target' : ($minutesUntilDue < 0 ? abs($minutesUntilDue).'m overdue' : $minutesUntilDue.'m remaining'),
        ];
    }
}
