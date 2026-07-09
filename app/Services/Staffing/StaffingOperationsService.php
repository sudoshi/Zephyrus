<?php

namespace App\Services\Staffing;

use App\Models\Staffing\StaffingEvent;
use App\Models\Staffing\StaffingPlan;
use App\Models\Staffing\StaffingRequest;
use App\Support\Api\JsonMap;
use App\Support\Operations\SourceFreshness;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $workforce = $this->workforceSummary();

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
            'source' => $this->sourceFreshness(),
            'coverage' => $coverage,
            'workforce' => $workforce,
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
            'resource_options' => $this->resourceOptions($workforce),
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
            'coverage_pct' => $required > 0 ? (int) round($available / $required * 100) : null,
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
            'constraints' => JsonMap::from($plan->constraints),
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
            'resolution_payload' => JsonMap::from($request->resolution_payload),
            'metadata' => JsonMap::from($request->metadata),
            'is_synthetic' => $this->isSyntheticRequest($request),
            'freshness_status' => $request->shift_date?->isBefore(Carbon::today())
                ? ($this->isSyntheticRequest($request) ? 'expired' : 'stale')
                : 'current',
            'sla' => $this->sla($request),
        ];
    }

    /** @return array<string,mixed> */
    public function workforceSummary(): array
    {
        if (! $this->workforceTablesAvailable()) {
            return [
                'available' => false,
                'metrics' => $this->emptyWorkforceMetrics(),
                'by_role' => [],
                'by_employment' => [],
                'by_shift' => [],
                'assumptions' => null,
            ];
        }

        $rows = $this->workforceQuery()->get();
        $activeRows = $rows->filter(fn ($row): bool => (bool) $row->member_active && (bool) $row->assignment_active);
        $activeMemberIds = $activeRows->pluck('staff_member_id')->unique();
        $totalMemberIds = $rows->pluck('staff_member_id')->unique();
        $metadataByMember = $rows
            ->unique('staff_member_id')
            ->mapWithKeys(fn ($row): array => [(int) $row->staff_member_id => $this->decodeJsonMap($row->member_metadata)]);

        $byRole = $activeRows
            ->groupBy('role_code')
            ->map(fn (Collection $roleRows, string $roleCode): array => [
                'role_code' => $roleCode,
                'role_label' => (string) $roleRows->first()->role_label,
                'role_category' => (string) $roleRows->first()->role_category,
                'active_count' => $roleRows->pluck('staff_member_id')->unique()->count(),
                'fte' => round((float) $roleRows->sum(fn ($row): float => (float) $row->fte), 1),
            ])
            ->sortByDesc('active_count')
            ->values()
            ->all();

        $byEmployment = $activeMemberIds
            ->map(fn ($id): string => (string) data_get($metadataByMember->get((int) $id), 'employment_class', 'unspecified'))
            ->countBy()
            ->map(fn (int $count, string $key): array => [
                'key' => $key,
                'label' => Str::headline($key),
                'count' => $count,
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        $byShift = collect(['day', 'evening', 'night'])->map(function (string $shift) use ($activeMemberIds, $metadataByMember): array {
            $count = $activeMemberIds->filter(
                fn ($id): bool => data_get($metadataByMember->get((int) $id), 'preferred_shift') === $shift,
            )->count();

            return ['shift' => $shift, 'label' => Str::headline($shift), 'count' => $count];
        })->all();

        $sampleMetadata = $rows
            ->map(fn ($row): array => $this->decodeJsonMap($row->member_metadata))
            ->first(fn (array $metadata): bool => data_get($metadata, 'roster_calculation') !== null);

        return [
            'available' => true,
            'metrics' => [
                'total_members' => $totalMemberIds->count(),
                'active_members' => $activeMemberIds->count(),
                'inactive_members' => $totalMemberIds->count() - $activeMemberIds->count(),
                'active_fte' => round((float) $activeRows->sum(fn ($row): float => (float) $row->fte), 1),
                'role_count' => $activeRows->pluck('role_code')->unique()->count(),
                'unit_count' => $activeRows->pluck('unit_id')->filter()->unique()->count(),
                'hospital_wide_members' => $activeRows->whereNull('unit_id')->pluck('staff_member_id')->unique()->count(),
                'synthetic_members' => $rows
                    ->filter(fn ($row): bool => data_get($this->decodeJsonMap($row->member_metadata), 'data_origin') === 'synthetic')
                    ->pluck('staff_member_id')
                    ->unique()
                    ->count(),
                'credential_attention' => $activeMemberIds->filter(function ($id) use ($metadataByMember): bool {
                    return in_array(data_get($metadataByMember->get((int) $id), 'credential_status'), ['expiring', 'expired'], true);
                })->count(),
                'unavailable_members' => $activeMemberIds->filter(function ($id) use ($metadataByMember): bool {
                    return in_array(data_get($metadataByMember->get((int) $id), 'availability'), ['leave', 'unavailable'], true);
                })->count(),
            ],
            'by_role' => $byRole,
            'by_employment' => $byEmployment,
            'by_shift' => $byShift,
            'assumptions' => $sampleMetadata === null ? null : [
                'roster_window' => data_get($sampleMetadata, 'roster_window'),
                'annual_coverage_days' => (int) config('demo_data.workforce.annual_coverage_days', 365),
                'shift_hours' => (float) config('demo_data.workforce.shift_hours', 8),
                'productive_hours_per_fte' => data_get($sampleMetadata, 'roster_calculation.productive_hours_per_fte'),
                'relief_factor' => data_get($sampleMetadata, 'roster_calculation.relief_factor'),
                'not_a_regulatory_ratio' => true,
            ],
        ];
    }

    /** @return array{data:list<array<string,mixed>>,meta:array<string,int>} */
    public function workforceDirectory(array $filters = []): array
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $pageNumber = max(1, (int) ($filters['page'] ?? 1));
        if (! $this->workforceTablesAvailable()) {
            return [
                'data' => [],
                'meta' => ['current_page' => $pageNumber, 'last_page' => 1, 'per_page' => $perPage, 'total' => 0],
            ];
        }

        $query = $this->workforceQuery()
            ->when(trim((string) ($filters['q'] ?? '')), function ($query, string $search): void {
                $like = '%'.$search.'%';
                $query->where(function ($query) use ($like): void {
                    $query->whereRaw('sm.display_name ILIKE ?', [$like])
                        ->orWhereRaw('sr.display_name ILIKE ?', [$like])
                        ->orWhereRaw("COALESCE(u.name, 'Hospital-wide') ILIKE ?", [$like])
                        ->orWhereRaw('sa.service_line_code ILIKE ?', [$like]);
                });
            })
            ->when($filters['role'] ?? null, fn ($query, string $role) => $query->where('sa.role_code', $role))
            ->when($filters['shift'] ?? null, fn ($query, string $shift) => $query->whereRaw("sm.metadata->>'preferred_shift' = ?", [$shift]))
            ->when(($filters['status'] ?? null) === 'active', fn ($query) => $query->where('sm.is_active', true)->where('sa.is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($query) => $query->where(function ($query): void {
                $query->where('sm.is_active', false)->orWhere('sa.is_active', false);
            }))
            ->orderByDesc('sm.is_active')
            ->orderBy('sr.sort_order')
            ->orderByRaw('u.name NULLS FIRST')
            ->orderBy('sm.display_name');

        $page = $query->paginate($perPage, ['*'], 'page', $pageNumber);

        return [
            'data' => collect($page->items())->map(fn ($row): array => $this->serializeWorkforceMember($row))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }

    public function resourceOptions(array $workforce = []): array
    {
        $employment = collect($workforce['by_employment'] ?? [])->keyBy('key');
        $count = static function (string $key) use ($employment, $workforce): ?int {
            if (($workforce['available'] ?? false) !== true || ! $employment->has($key)) {
                return null;
            }

            return (int) data_get($employment->get($key), 'count');
        };

        return [
            ['key' => 'float_pool', 'name' => 'Float Pool', 'type' => 'internal_pool', 'available' => $count('float_pool')],
            ['key' => 'overtime', 'name' => 'Overtime Offer', 'type' => 'incentive', 'available' => $count('per_diem')],
            ['key' => 'agency', 'name' => 'Agency / Traveler', 'type' => 'external', 'available' => $count('traveler')],
            ['key' => 'on_call', 'name' => 'On-Call Activation', 'type' => 'standby', 'available' => $count('on_call')],
        ];
    }

    private function workforceQuery()
    {
        return DB::table('hosp_org.staff_assignments as sa')
            ->join('hosp_org.staff_members as sm', 'sm.staff_member_id', '=', 'sa.staff_member_id')
            ->join('hosp_ref.staff_roles as sr', 'sr.role_code', '=', 'sa.role_code')
            ->leftJoin('prod.units as u', 'u.unit_id', '=', 'sa.unit_id')
            ->where(function ($query): void {
                $query->whereNull('sa.effective_start')->orWhereDate('sa.effective_start', '<=', Carbon::today());
            })
            ->where(function ($query): void {
                $query->whereNull('sa.effective_end')->orWhereDate('sa.effective_end', '>=', Carbon::today());
            })
            ->select([
                'sm.staff_member_id', 'sm.display_name', 'sm.email', 'sm.employee_type', 'sm.employment_status',
                'sm.is_active as member_active', 'sm.source_system', 'sm.metadata as member_metadata',
                'sa.staff_assignment_id', 'sa.facility_key', 'sa.service_line_code', 'sa.role_code', 'sa.unit_id',
                'sa.coverage_model', 'sa.fte', 'sa.is_active as assignment_active', 'sa.evidence',
                'sr.display_name as role_label', 'sr.role_category', 'sr.sort_order',
                DB::raw("COALESCE(u.name, 'Hospital-wide') as unit_label"),
            ]);
    }

    /** @return array<string,mixed> */
    private function serializeWorkforceMember(object $row): array
    {
        $metadata = $this->decodeJsonMap($row->member_metadata);

        return [
            'staff_member_id' => (int) $row->staff_member_id,
            'display_name' => (string) ($row->display_name ?? 'Unnamed staff member'),
            'role_code' => (string) $row->role_code,
            'role_label' => (string) $row->role_label,
            'role_category' => (string) $row->role_category,
            'unit_id' => $row->unit_id === null ? null : (int) $row->unit_id,
            'unit_label' => (string) $row->unit_label,
            'service_line_code' => (string) $row->service_line_code,
            'employee_type' => $row->employee_type,
            'employment_class' => (string) data_get($metadata, 'employment_class', 'unspecified'),
            'fte' => (float) $row->fte,
            'coverage_model' => $row->coverage_model,
            'preferred_shift' => data_get($metadata, 'preferred_shift'),
            'availability' => (string) data_get($metadata, 'availability', $row->member_active ? 'available' : 'inactive'),
            'credential_status' => (string) data_get($metadata, 'credential_status', 'unknown'),
            'credentials' => array_values((array) data_get($metadata, 'credentials', [])),
            'eligible_float_units' => array_values((array) data_get($metadata, 'eligible_float_units', [])),
            'is_active' => (bool) $row->member_active && (bool) $row->assignment_active,
            'is_synthetic' => data_get($metadata, 'data_origin') === 'synthetic',
        ];
    }

    /** @return array<string,mixed> */
    private function decodeJsonMap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array) $value;
        }
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function workforceTablesAvailable(): bool
    {
        return Schema::hasTable('hosp_org.staff_assignments')
            && Schema::hasTable('hosp_org.staff_members')
            && Schema::hasTable('hosp_ref.staff_roles');
    }

    /** @return array<string,int|float> */
    private function emptyWorkforceMetrics(): array
    {
        return [
            'total_members' => 0,
            'active_members' => 0,
            'inactive_members' => 0,
            'active_fte' => 0.0,
            'role_count' => 0,
            'unit_count' => 0,
            'hospital_wide_members' => 0,
            'synthetic_members' => 0,
            'credential_attention' => 0,
            'unavailable_members' => 0,
        ];
    }

    /** @return array<string,mixed> */
    private function sourceFreshness(): array
    {
        $latestPlan = StaffingPlan::query()
            ->where('is_deleted', false)
            ->orderByDesc('updated_at')
            ->first(['updated_at', 'metadata']);
        $latestRequest = StaffingRequest::query()
            ->where('is_deleted', false)
            ->orderByDesc('updated_at')
            ->first(['updated_at', 'metadata', 'requested_by']);

        $lastObservedAt = collect([$latestPlan?->updated_at, $latestRequest?->updated_at])
            ->filter()
            ->sortByDesc(fn (Carbon $value): int => $value->getTimestamp())
            ->first();
        $synthetic = (bool) data_get($latestPlan?->metadata, 'synthetic', false)
            || data_get($latestPlan?->metadata, 'data_origin') === 'synthetic'
            || data_get($latestPlan?->metadata, 'scenario_id') !== null
            || (bool) data_get($latestRequest?->metadata, 'synthetic', false)
            || data_get($latestRequest?->metadata, 'data_origin') === 'synthetic'
            || data_get($latestRequest?->metadata, 'scenario_id') !== null
            || $latestRequest?->requested_by === 'demo-seeder'
            || str_starts_with((string) $latestRequest?->requested_by, 'operations-demo:');

        return SourceFreshness::make(
            key: 'prod.staffing_operations',
            label: 'Staffing operations data',
            lastObservedAt: $lastObservedAt,
            expectedCadenceMinutes: 60,
            staleAfterMinutes: 240,
            synthetic: $synthetic,
        );
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
        if ($this->isSyntheticRequest($request) && $request->shift_date?->isBefore(Carbon::today())) {
            return [
                'minutes_until_due' => null,
                'at_risk' => false,
                'label' => 'Expired synthetic request',
            ];
        }

        $minutesUntilDue = $request->needed_by ? now()->diffInMinutes($request->needed_by, false) : null;

        return [
            'minutes_until_due' => $minutesUntilDue,
            'at_risk' => $this->isAtRisk($request),
            'label' => $minutesUntilDue === null ? 'No target' : ($minutesUntilDue < 0 ? abs($minutesUntilDue).'m overdue' : $minutesUntilDue.'m remaining'),
        ];
    }

    private function isSyntheticRequest(StaffingRequest $request): bool
    {
        return $request->requested_by === 'demo-seeder'
            || str_starts_with((string) $request->requested_by, 'operations-demo:')
            || data_get($request->metadata, 'data_origin') === 'synthetic'
            || (bool) data_get($request->metadata, 'synthetic', false);
    }
}
