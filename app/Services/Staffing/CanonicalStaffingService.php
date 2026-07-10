<?php

namespace App\Services\Staffing;

use App\Models\Staffing\StaffingEvent;
use App\Models\Staffing\StaffingFulfillmentEvent;
use App\Models\Staffing\StaffingRequest;
use App\Models\Staffing\StaffingRequestFulfillment;
use App\Models\Staffing\StaffShiftAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CanonicalStaffingService
{
    private const ACTIVE_FULFILLMENT_STATUSES = ['offered', 'accepted', 'filled'];

    private const ACTIVE_SHIFT_STATUSES = ['offered', 'accepted', 'filled'];

    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        'offered' => ['accepted', 'canceled'],
        'accepted' => ['filled', 'released', 'canceled'],
        'filled' => ['released', 'canceled'],
        'released' => [],
        'canceled' => [],
    ];

    private ?bool $tablesAvailable = null;

    public function __construct(private readonly StaffingShiftWindowService $shiftWindows) {}

    /** @return array<string,mixed>|null */
    public function workforceState(int $staffMemberId): ?array
    {
        if (! $this->tablesAvailable()) {
            return null;
        }

        $now = now();
        $windows = DB::table('prod.staff_availability_windows')
            ->where('staff_member_id', $staffMemberId)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->orderBy('priority')
            ->get(['window_type', 'source', 'starts_at', 'ends_at']);
        $blocking = $windows->first(fn (object $window): bool => in_array($window->window_type, ['unavailable', 'leave', 'conflict'], true));
        $positive = $windows->first(fn (object $window): bool => in_array($window->window_type, ['available', 'on_call'], true));
        $qualifications = DB::table('hosp_org.staff_member_qualifications as smq')
            ->join('hosp_ref.staff_qualifications as sq', 'sq.qualification_code', '=', 'smq.qualification_code')
            ->where('smq.staff_member_id', $staffMemberId)
            ->where('smq.effective_start', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->whereNull('smq.effective_end')->orWhere('smq.effective_end', '>', $now);
            })
            ->get(['smq.qualification_code', 'sq.display_name', 'smq.status', 'smq.expires_at']);
        $credentialStatus = match (true) {
            $qualifications->contains(fn (object $row): bool => in_array($row->status, ['expired', 'revoked'], true)
                || ($row->expires_at !== null && CarbonImmutable::parse($row->expires_at)->isPast())) => 'expired',
            $qualifications->contains(fn (object $row): bool => $row->status === 'provisional') => 'attention',
            $qualifications->isNotEmpty() => 'verified',
            default => 'unverified',
        };

        return [
            'availability' => $blocking?->window_type ?? $positive?->window_type ?? 'unverified',
            'availability_source' => $blocking?->source ?? $positive?->source,
            'credential_status' => $credentialStatus,
            'qualifications' => $qualifications->map(fn (object $row): array => [
                'qualification_code' => $row->qualification_code,
                'display_name' => $row->display_name,
                'status' => $row->status,
            ])->values()->all(),
        ];
    }

    /** @return array<string,mixed> */
    public function summaryForRequest(StaffingRequest $request): array
    {
        if (! $this->tablesAvailable()) {
            return [
                'available' => false,
                'state' => 'unconfigured',
                'offered_count' => 0,
                'accepted_count' => 0,
                'filled_count' => 0,
                'remaining_count' => (int) $request->headcount_needed,
                'latest' => null,
                'active' => [],
                'actions' => ['can_offer' => false],
            ];
        }

        $fulfillments = StaffingRequestFulfillment::query()
            ->where('staffing_request_id', $request->staffing_request_id)
            ->with(['staffMember', 'shiftAssignment'])
            ->orderByDesc('offered_at')
            ->orderByDesc('staffing_request_fulfillment_id')
            ->get();
        $filled = $fulfillments->where('status', 'filled')->count();
        $remaining = max(0, (int) $request->headcount_needed - $filled);

        return [
            'available' => true,
            'state' => match (true) {
                $remaining === 0 => 'filled',
                $fulfillments->contains('status', 'accepted') => 'partially_fulfilled',
                $fulfillments->contains('status', 'offered') => 'offer_pending',
                default => 'unfilled',
            },
            'offered_count' => $fulfillments->where('status', 'offered')->count(),
            'accepted_count' => $fulfillments->where('status', 'accepted')->count(),
            'filled_count' => $filled,
            'remaining_count' => $remaining,
            'latest' => $fulfillments->first() ? $this->serializeFulfillment($fulfillments->first()) : null,
            'active' => $fulfillments
                ->whereIn('status', self::ACTIVE_FULFILLMENT_STATUSES)
                ->map(fn (StaffingRequestFulfillment $fulfillment): array => $this->serializeFulfillment($fulfillment))
                ->values()
                ->all(),
            'actions' => [
                'can_offer' => $remaining > 0 && ! in_array($request->status, ['completed', 'canceled'], true),
            ],
        ];
    }

    /** @return array{data:list<array<string,mixed>>,meta:array<string,int>,shift:array<string,string>} */
    public function candidates(StaffingRequest $request, array $filters = []): array
    {
        $this->requireTables();
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? config('staffing.candidate_page_size', 25))));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $eligibleOnly = filter_var($filters['eligible_only'] ?? false, FILTER_VALIDATE_BOOL);
        $shift = $this->shiftWindows->forRequest($request);

        $evaluated = $this->evaluateCandidates($request, $this->candidateRows($request), $shift)
            ->sortBy(fn (array $candidate): string => sprintf(
                '%d|%03d|%020d',
                $candidate['eligible'] ? 0 : 1,
                count($candidate['reason_codes']),
                $candidate['staff_assignment_id'],
            ))
            ->unique('staff_member_id')
            ->when($eligibleOnly, fn (Collection $rows): Collection => $rows->where('eligible', true))
            ->sortBy(fn (array $candidate): string => sprintf(
                '%d|%s|%020d',
                $candidate['eligible'] ? 0 : 1,
                mb_strtolower($candidate['display_name']),
                $candidate['staff_member_id'],
            ))
            ->values();
        $total = $evaluated->count();
        $lastPage = max(1, (int) ceil($total / $perPage));

        return [
            'data' => $evaluated->forPage($page, $perPage)->values()->all(),
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
            'shift' => [
                'starts_at' => $shift['starts_at']->toISOString(),
                'ends_at' => $shift['ends_at']->toISOString(),
                'timezone' => $shift['timezone'],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function fulfillments(StaffingRequest $request): array
    {
        if (! $this->tablesAvailable()) {
            return [];
        }

        return StaffingRequestFulfillment::query()
            ->where('staffing_request_id', $request->staffing_request_id)
            ->with(['staffMember', 'shiftAssignment'])
            ->orderByDesc('offered_at')
            ->orderByDesc('staffing_request_fulfillment_id')
            ->get()
            ->map(fn (StaffingRequestFulfillment $fulfillment): array => $this->serializeFulfillment($fulfillment))
            ->all();
    }

    /** @return array<string,mixed> */
    public function offer(
        StaffingRequest $request,
        int $staffMemberId,
        string $source,
        ?int $actorUserId,
        string $idempotencyKey,
    ): array {
        return $this->executeIdempotent(
            $request->staffing_request_id,
            'offer',
            $idempotencyKey,
            ['staff_member_id' => $staffMemberId, 'source' => $source],
            $actorUserId,
            function () use ($request, $staffMemberId, $source, $actorUserId): array {
                $lockedRequest = $this->lockedRequest((int) $request->staffing_request_id);
                $this->assertRequestOpen($lockedRequest);
                $requestFrom = (string) $lockedRequest->status;
                $this->lockStaffMember($staffMemberId);

                if (StaffingRequestFulfillment::query()
                    ->where('staffing_request_id', $lockedRequest->staffing_request_id)
                    ->where('staff_member_id', $staffMemberId)
                    ->whereIn('status', self::ACTIVE_FULFILLMENT_STATUSES)
                    ->exists()) {
                    throw new ConflictHttpException('This staff member already has an active fulfillment for the request.');
                }

                $candidate = $this->eligibleCandidate($lockedRequest, $staffMemberId);
                $shift = $this->shiftWindows->forRequest($lockedRequest);
                $assignment = StaffShiftAssignment::create([
                    'shift_assignment_uuid' => (string) Str::uuid(),
                    'staffing_request_id' => $lockedRequest->staffing_request_id,
                    'staff_member_id' => $staffMemberId,
                    'unit_id' => $lockedRequest->unit_id,
                    'facility_key' => $candidate['facility_key'],
                    'service_line_code' => $candidate['service_line_code'],
                    'role_code' => $candidate['role_code'],
                    'starts_at' => $shift['starts_at'],
                    'ends_at' => $shift['ends_at'],
                    'timezone' => $shift['timezone'],
                    'status' => 'offered',
                    'validation_snapshot' => $candidate,
                    'created_by_user_id' => $actorUserId,
                    'updated_by_user_id' => $actorUserId,
                ]);
                $fulfillment = StaffingRequestFulfillment::create([
                    'fulfillment_uuid' => (string) Str::uuid(),
                    'staffing_request_id' => $lockedRequest->staffing_request_id,
                    'staff_shift_assignment_id' => $assignment->staff_shift_assignment_id,
                    'staff_member_id' => $staffMemberId,
                    'status' => 'offered',
                    'source' => $source,
                    'version' => 1,
                    'offered_at' => now(),
                    'last_actor_user_id' => $actorUserId,
                    'metadata' => [],
                ]);

                $lockedRequest->update([
                    'status' => 'sourcing',
                    'assigned_source' => $source,
                    'assigned_staff_ref' => null,
                    'assigned_at' => null,
                    'filled_at' => null,
                    'updated_by_user_id' => $actorUserId,
                ]);
                $this->recordFulfillmentEvent($fulfillment, null, 'offered', [], $actorUserId);
                $this->recordLegacyEvent($lockedRequest, 'staffing.offer_created', $requestFrom, 'sourcing', [
                    'fulfillment_uuid' => $fulfillment->fulfillment_uuid,
                    'staff_member_id' => $staffMemberId,
                ], $actorUserId);

                return $this->serializeFulfillment($fulfillment->fresh(['staffMember', 'shiftAssignment']));
            },
        );
    }

    /** @return array<string,mixed> */
    public function transition(
        string $fulfillmentUuid,
        string $targetStatus,
        ?string $note,
        ?int $actorUserId,
        string $idempotencyKey,
    ): array {
        $existing = StaffingRequestFulfillment::query()->where('fulfillment_uuid', $fulfillmentUuid)->firstOrFail();

        return $this->executeIdempotent(
            (int) $existing->staffing_request_id,
            "transition:{$targetStatus}",
            $idempotencyKey,
            ['fulfillment_uuid' => $fulfillmentUuid, 'target_status' => $targetStatus, 'note' => $note],
            $actorUserId,
            function () use ($fulfillmentUuid, $targetStatus, $note, $actorUserId): array {
                $fulfillment = StaffingRequestFulfillment::query()
                    ->where('fulfillment_uuid', $fulfillmentUuid)
                    ->lockForUpdate()
                    ->firstOrFail();
                $request = $this->lockedRequest((int) $fulfillment->staffing_request_id);
                $this->assertRequestOpen($request, allowFilled: true);
                $this->lockStaffMember((int) $fulfillment->staff_member_id);
                $allowed = self::TRANSITIONS[$fulfillment->status] ?? [];
                if (! in_array($targetStatus, $allowed, true)) {
                    throw ValidationException::withMessages([
                        'status' => "Illegal staffing fulfillment transition: {$fulfillment->status} -> {$targetStatus}.",
                    ]);
                }

                $assignment = StaffShiftAssignment::query()
                    ->where('staff_shift_assignment_id', $fulfillment->staff_shift_assignment_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $validation = null;
                if (in_array($targetStatus, ['accepted', 'filled'], true)) {
                    $this->assertFulfillmentCapacity(
                        $request,
                        (int) $fulfillment->staffing_request_fulfillment_id,
                    );
                    $validation = $this->eligibleCandidate(
                        $request,
                        (int) $fulfillment->staff_member_id,
                        (int) $assignment->staff_shift_assignment_id,
                    );
                }

                $from = (string) $fulfillment->status;
                $timestampColumn = "{$targetStatus}_at";
                $updates = [
                    'status' => $targetStatus,
                    'version' => $fulfillment->version + 1,
                    'last_actor_user_id' => $actorUserId,
                ];
                if (in_array($timestampColumn, ['accepted_at', 'filled_at', 'released_at', 'canceled_at'], true)) {
                    $updates[$timestampColumn] = now();
                }
                $fulfillment->update($updates);
                $assignment->update([
                    'status' => $targetStatus,
                    'validation_snapshot' => $validation ?? $assignment->validation_snapshot,
                    'updated_by_user_id' => $actorUserId,
                ]);
                $requestFrom = (string) $request->status;
                $this->recordFulfillmentEvent($fulfillment, $from, $targetStatus, [
                    'note' => $note,
                    'validation' => $validation,
                ], $actorUserId);
                $this->syncRequestStatus($request, $actorUserId);
                $this->recordLegacyEvent($request, "staffing.fulfillment_{$targetStatus}", $requestFrom, $request->status, [
                    'fulfillment_uuid' => $fulfillmentUuid,
                    'staff_member_id' => $fulfillment->staff_member_id,
                    'note' => $note,
                ], $actorUserId);

                return $this->serializeFulfillment($fulfillment->fresh(['staffMember', 'shiftAssignment']));
            },
        );
    }

    /**
     * Mobile uses one deliberate action, but the immutable ledger still records
     * offered -> accepted -> filled so web and mobile share the same lifecycle.
     *
     * @return array<string,mixed>
     */
    public function directFill(
        StaffingRequest $request,
        int $staffMemberId,
        string $source,
        ?int $actorUserId,
        string $idempotencyKey,
    ): array {
        return $this->executeIdempotent(
            (int) $request->staffing_request_id,
            'direct_fill',
            $idempotencyKey,
            ['staff_member_id' => $staffMemberId, 'source' => $source],
            $actorUserId,
            function () use ($request, $staffMemberId, $source, $actorUserId): array {
                $lockedRequest = $this->lockedRequest((int) $request->staffing_request_id);
                $this->assertRequestOpen($lockedRequest);
                $requestFrom = (string) $lockedRequest->status;
                $this->lockStaffMember($staffMemberId);
                if (StaffingRequestFulfillment::query()
                    ->where('staffing_request_id', $lockedRequest->staffing_request_id)
                    ->where('staff_member_id', $staffMemberId)
                    ->whereIn('status', self::ACTIVE_FULFILLMENT_STATUSES)
                    ->exists()) {
                    throw new ConflictHttpException('This staff member already has an active fulfillment for the request.');
                }
                $this->assertFulfillmentCapacity($lockedRequest);
                $candidate = $this->eligibleCandidate($lockedRequest, $staffMemberId);
                $shift = $this->shiftWindows->forRequest($lockedRequest);
                $now = now();
                $assignment = StaffShiftAssignment::create([
                    'shift_assignment_uuid' => (string) Str::uuid(),
                    'staffing_request_id' => $lockedRequest->staffing_request_id,
                    'staff_member_id' => $staffMemberId,
                    'unit_id' => $lockedRequest->unit_id,
                    'facility_key' => $candidate['facility_key'],
                    'service_line_code' => $candidate['service_line_code'],
                    'role_code' => $candidate['role_code'],
                    'starts_at' => $shift['starts_at'],
                    'ends_at' => $shift['ends_at'],
                    'timezone' => $shift['timezone'],
                    'status' => 'filled',
                    'validation_snapshot' => $candidate,
                    'created_by_user_id' => $actorUserId,
                    'updated_by_user_id' => $actorUserId,
                ]);
                $fulfillment = StaffingRequestFulfillment::create([
                    'fulfillment_uuid' => (string) Str::uuid(),
                    'staffing_request_id' => $lockedRequest->staffing_request_id,
                    'staff_shift_assignment_id' => $assignment->staff_shift_assignment_id,
                    'staff_member_id' => $staffMemberId,
                    'status' => 'filled',
                    'source' => $source,
                    'version' => 3,
                    'offered_at' => $now,
                    'accepted_at' => $now,
                    'filled_at' => $now,
                    'last_actor_user_id' => $actorUserId,
                    'metadata' => ['direct_mobile_action' => true],
                ]);
                foreach ([
                    [null, 'offered'],
                    ['offered', 'accepted'],
                    ['accepted', 'filled'],
                ] as [$from, $to]) {
                    $this->recordFulfillmentEvent($fulfillment, $from, $to, [
                        'direct_mobile_action' => true,
                        'validation' => $to === 'filled' ? $candidate : null,
                    ], $actorUserId);
                }
                $this->syncRequestStatus($lockedRequest, $actorUserId);
                $this->recordLegacyEvent($lockedRequest, 'staffing.fulfillment_filled', $requestFrom, $lockedRequest->status, [
                    'fulfillment_uuid' => $fulfillment->fulfillment_uuid,
                    'staff_member_id' => $staffMemberId,
                    'direct_mobile_action' => true,
                ], $actorUserId);

                return $this->serializeFulfillment($fulfillment->fresh(['staffMember', 'shiftAssignment']));
            },
        );
    }

    /** @return array<string,mixed> */
    public function serializeFulfillment(StaffingRequestFulfillment $fulfillment): array
    {
        $fulfillment->loadMissing(['staffMember', 'shiftAssignment']);
        $assignment = $fulfillment->shiftAssignment;

        return [
            'fulfillment_uuid' => $fulfillment->fulfillment_uuid,
            'staffing_request_id' => (int) $fulfillment->staffing_request_id,
            'staff_member_id' => (int) $fulfillment->staff_member_id,
            'staff_member_name' => (string) ($fulfillment->staffMember?->display_name ?? 'Unknown staff member'),
            'status' => $fulfillment->status,
            'source' => $fulfillment->source,
            'version' => (int) $fulfillment->version,
            'role_code' => $assignment?->role_code,
            'unit_id' => $assignment?->unit_id === null ? null : (int) $assignment->unit_id,
            'starts_at' => $assignment?->starts_at?->toISOString(),
            'ends_at' => $assignment?->ends_at?->toISOString(),
            'timezone' => $assignment?->timezone,
            'validation' => $assignment?->validation_snapshot ?? [],
            'offered_at' => $fulfillment->offered_at?->toISOString(),
            'accepted_at' => $fulfillment->accepted_at?->toISOString(),
            'filled_at' => $fulfillment->filled_at?->toISOString(),
            'released_at' => $fulfillment->released_at?->toISOString(),
            'canceled_at' => $fulfillment->canceled_at?->toISOString(),
            'actions' => [
                'can_accept' => in_array('accepted', self::TRANSITIONS[$fulfillment->status] ?? [], true),
                'can_fill' => in_array('filled', self::TRANSITIONS[$fulfillment->status] ?? [], true),
                'can_release' => in_array('released', self::TRANSITIONS[$fulfillment->status] ?? [], true),
                'can_cancel' => in_array('canceled', self::TRANSITIONS[$fulfillment->status] ?? [], true),
            ],
        ];
    }

    /** @return Collection<int,object> */
    private function candidateRows(StaffingRequest $request, ?int $staffMemberId = null): Collection
    {
        $roles = $this->canonicalRolesFor((string) $request->role);
        $shiftDate = $request->shift_date?->toDateString() ?? now()->toDateString();
        $facility = (string) data_get($request->metadata, 'facility_key', config('staffing.default_facility_key'));
        $serviceLine = (string) data_get($request->metadata, 'service_line_code', '');

        return DB::table('hosp_org.staff_assignments as sa')
            ->join('hosp_org.staff_members as sm', 'sm.staff_member_id', '=', 'sa.staff_member_id')
            ->join('hosp_ref.staff_roles as sr', 'sr.role_code', '=', 'sa.role_code')
            ->where('sm.is_active', true)
            ->where('sa.is_active', true)
            ->where('sa.facility_key', $facility)
            ->when($staffMemberId !== null, fn ($query) => $query->where('sm.staff_member_id', $staffMemberId))
            ->where(function ($query): void {
                $query->whereNull('sm.employment_status')
                    ->orWhereNotIn('sm.employment_status', ['terminated', 'leave']);
            })
            ->whereIn('sa.role_code', $roles)
            ->where(function ($query) use ($shiftDate): void {
                $query->whereNull('sa.effective_start')->orWhereDate('sa.effective_start', '<=', $shiftDate);
            })
            ->where(function ($query) use ($shiftDate): void {
                $query->whereNull('sa.effective_end')->orWhereDate('sa.effective_end', '>=', $shiftDate);
            })
            ->when($serviceLine !== '', fn ($query) => $query->where('sa.service_line_code', $serviceLine))
            ->when($request->unit_id !== null, function ($query) use ($request): void {
                $query->where(function ($query) use ($request): void {
                    $query->where('sa.unit_id', $request->unit_id)
                        ->orWhere(function ($query): void {
                            $query->whereNull('sa.unit_id')
                                ->whereIn('sa.coverage_model', ['float', 'on_call', 'in_house']);
                        });
                });
            })
            ->select([
                'sm.staff_member_id', 'sm.display_name', 'sm.employee_type', 'sm.employment_status',
                'sa.staff_assignment_id', 'sa.facility_key', 'sa.service_line_code', 'sa.role_code',
                'sa.unit_id', 'sa.coverage_model', 'sr.display_name as role_label',
            ])
            ->orderByRaw('CASE WHEN sa.unit_id = ? THEN 0 ELSE 1 END', [$request->unit_id ?? 0])
            ->orderBy('sm.display_name')
            ->orderBy('sm.staff_member_id')
            ->orderBy('sa.staff_assignment_id')
            ->get()
            ->values();
    }

    /**
     * Evaluate a candidate page with four bounded set queries instead of issuing
     * qualification, availability, and conflict queries once per staff member.
     *
     * @param  Collection<int,object>  $candidates
     * @param  array{starts_at:CarbonImmutable,ends_at:CarbonImmutable,timezone:string}  $shift
     * @return Collection<int,array<string,mixed>>
     */
    private function evaluateCandidates(
        StaffingRequest $request,
        Collection $candidates,
        array $shift,
        ?int $excludeShiftAssignmentId = null,
    ): Collection {
        if ($candidates->isEmpty()) {
            return collect();
        }

        $staffMemberIds = $candidates->pluck('staff_member_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $roleCodes = $candidates->pluck('role_code')->filter()->unique()->values();
        $requirements = DB::table('hosp_ref.staff_role_qualification_requirements as srqr')
            ->join('hosp_ref.staff_qualifications as sq', 'sq.qualification_code', '=', 'srqr.qualification_code')
            ->whereIn('srqr.role_code', $roleCodes)
            ->where('srqr.is_required', true)
            ->where(function ($query) use ($shift): void {
                $query->whereNull('srqr.effective_start')->orWhereDate('srqr.effective_start', '<=', $shift['starts_at']->toDateString());
            })
            ->where(function ($query) use ($shift): void {
                $query->whereNull('srqr.effective_end')->orWhereDate('srqr.effective_end', '>=', $shift['starts_at']->toDateString());
            })
            ->get([
                'srqr.role_code', 'srqr.facility_key', 'srqr.unit_id', 'srqr.service_line_code',
                'srqr.qualification_code', 'sq.display_name',
            ])
            ->groupBy('role_code');
        $qualifications = DB::table('hosp_org.staff_member_qualifications as smq')
            ->join('hosp_ref.staff_qualifications as sq', 'sq.qualification_code', '=', 'smq.qualification_code')
            ->whereIn('smq.staff_member_id', $staffMemberIds)
            ->where('smq.status', 'verified')
            ->where('smq.effective_start', '<=', $shift['starts_at'])
            ->where(function ($query) use ($shift): void {
                $query->whereNull('smq.effective_end')->orWhere('smq.effective_end', '>=', $shift['ends_at']);
            })
            ->where(function ($query) use ($shift): void {
                $query->whereNull('smq.expires_at')->orWhere('smq.expires_at', '>=', $shift['ends_at']);
            })
            ->get(['smq.staff_member_id', 'smq.qualification_code', 'sq.display_name'])
            ->groupBy('staff_member_id');
        $availability = DB::table('prod.staff_availability_windows')
            ->whereIn('staff_member_id', $staffMemberIds)
            ->where('starts_at', '<', $shift['ends_at'])
            ->where('ends_at', '>', $shift['starts_at'])
            ->orderBy('priority')
            ->get(['staff_member_id', 'window_type', 'starts_at', 'ends_at', 'source'])
            ->groupBy('staff_member_id');
        $conflicts = DB::table('prod.staff_shift_assignments')
            ->whereIn('staff_member_id', $staffMemberIds)
            ->whereIn('status', self::ACTIVE_SHIFT_STATUSES)
            ->where('starts_at', '<', $shift['ends_at'])
            ->where('ends_at', '>', $shift['starts_at'])
            ->when($excludeShiftAssignmentId !== null, fn ($query) => $query->where('staff_shift_assignment_id', '!=', $excludeShiftAssignmentId))
            ->groupBy('staff_member_id')
            ->selectRaw('staff_member_id, COUNT(*) AS conflict_count')
            ->pluck('conflict_count', 'staff_member_id');

        return $candidates->map(function (object $candidate) use ($request, $shift, $requirements, $qualifications, $availability, $conflicts): array {
            $candidateRequirements = collect($requirements->get($candidate->role_code, []))
                ->filter(function (object $requirement) use ($candidate, $request): bool {
                    $facilityMatches = $requirement->facility_key === null || $requirement->facility_key === $candidate->facility_key;
                    $unitMatches = $requirement->unit_id === null
                        || ($request->unit_id !== null && (int) $requirement->unit_id === (int) $request->unit_id);
                    $serviceLineMatches = $requirement->service_line_code === null
                        || $requirement->service_line_code === $candidate->service_line_code;

                    return $facilityMatches && $unitMatches && $serviceLineMatches;
                })
                ->unique('qualification_code')
                ->values();
            $candidateQualifications = collect($qualifications->get($candidate->staff_member_id, []))
                ->keyBy('qualification_code');
            $candidateAvailability = collect($availability->get($candidate->staff_member_id, []));

            return $this->shapeCandidateEvaluation(
                $candidate,
                $shift,
                $candidateRequirements,
                $candidateQualifications,
                $candidateAvailability,
                (int) ($conflicts[$candidate->staff_member_id] ?? 0),
            );
        });
    }

    /**
     * @param  Collection<int,object>  $requirements
     * @param  Collection<string,object>  $qualificationRows
     * @param  Collection<int,object>  $availability
     * @param  array{starts_at:CarbonImmutable,ends_at:CarbonImmutable,timezone:string}  $shift
     * @return array<string,mixed>
     */
    private function shapeCandidateEvaluation(
        object $candidate,
        array $shift,
        Collection $requirements,
        Collection $qualificationRows,
        Collection $availability,
        int $conflicts,
    ): array {
        $missing = $requirements
            ->reject(fn (object $requirement): bool => $qualificationRows->has($requirement->qualification_code))
            ->values();
        $blocking = $availability->whereIn('window_type', ['unavailable', 'leave', 'conflict'])->values();
        $covering = $availability->filter(function (object $window) use ($shift): bool {
            return in_array($window->window_type, ['available', 'on_call'], true)
                && CarbonImmutable::parse($window->starts_at)->lessThanOrEqualTo($shift['starts_at'])
                && CarbonImmutable::parse($window->ends_at)->greaterThanOrEqualTo($shift['ends_at']);
        })->values();

        $reasons = [];
        if ($requirements->isEmpty()) {
            $reasons[] = 'qualification_requirements_unconfigured';
        } elseif ($missing->isNotEmpty()) {
            $reasons[] = 'missing_required_qualification';
        }
        if ($blocking->isNotEmpty()) {
            $reasons[] = 'unavailable_or_on_leave';
        } elseif ($covering->isEmpty()) {
            $reasons[] = 'availability_unverified';
        }
        if ($conflicts > 0) {
            $reasons[] = 'overlapping_shift_assignment';
        }

        return [
            'staff_member_id' => (int) $candidate->staff_member_id,
            'display_name' => (string) $candidate->display_name,
            'staff_assignment_id' => (int) $candidate->staff_assignment_id,
            'facility_key' => (string) $candidate->facility_key,
            'service_line_code' => (string) $candidate->service_line_code,
            'role_code' => (string) $candidate->role_code,
            'role_label' => (string) $candidate->role_label,
            'unit_id' => $candidate->unit_id === null ? null : (int) $candidate->unit_id,
            'coverage_model' => $candidate->coverage_model,
            'eligible' => $reasons === [],
            'eligibility_state' => match (true) {
                in_array('overlapping_shift_assignment', $reasons, true) => 'conflicted',
                in_array('unavailable_or_on_leave', $reasons, true), in_array('availability_unverified', $reasons, true) => 'unavailable',
                in_array('missing_required_qualification', $reasons, true), in_array('qualification_requirements_unconfigured', $reasons, true) => 'unqualified',
                default => 'eligible',
            },
            'reason_codes' => $reasons,
            'qualification_requirements' => $requirements->map(fn (object $requirement): array => [
                'qualification_code' => $requirement->qualification_code,
                'display_name' => $requirement->display_name,
                'verified' => $qualificationRows->has($requirement->qualification_code),
            ])->values()->all(),
            'availability' => [
                'covering_windows' => $covering->count(),
                'blocking_windows' => $blocking->count(),
                'timezone' => $shift['timezone'],
            ],
            'overlapping_assignments' => $conflicts,
            'shift' => [
                'starts_at' => $shift['starts_at']->toISOString(),
                'ends_at' => $shift['ends_at']->toISOString(),
                'timezone' => $shift['timezone'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function eligibleCandidate(StaffingRequest $request, int $staffMemberId, ?int $excludeShiftAssignmentId = null): array
    {
        $candidates = $this->candidateRows($request, $staffMemberId);
        if ($candidates->isEmpty()) {
            throw ValidationException::withMessages([
                'staff_member_id' => 'The selected staff member is not active in a compatible role and unit/service-line assignment.',
            ]);
        }

        $evaluations = $this->evaluateCandidates(
            $request,
            $candidates,
            $this->shiftWindows->forRequest($request),
            $excludeShiftAssignmentId,
        );
        $evaluation = $evaluations->firstWhere('eligible', true)
            ?? $evaluations->sortBy(fn (array $candidate): int => count($candidate['reason_codes']))->first();
        if (! $evaluation['eligible']) {
            throw ValidationException::withMessages([
                'staff_member_id' => 'The selected staff member is not eligible: '.implode(', ', $evaluation['reason_codes']).'.',
            ]);
        }

        return $evaluation;
    }

    /** @return list<string> */
    private function canonicalRolesFor(string $requestRole): array
    {
        $mapped = config("staffing.legacy_role_map.{$requestRole}");

        return is_array($mapped) && $mapped !== [] ? array_values($mapped) : [$requestRole];
    }

    private function syncRequestStatus(StaffingRequest $request, ?int $actorUserId): void
    {
        $rows = StaffingRequestFulfillment::query()
            ->where('staffing_request_id', $request->staffing_request_id)
            ->orderByDesc('offered_at')
            ->orderByDesc('staffing_request_fulfillment_id')
            ->get(['status', 'staff_member_id', 'source']);
        $filled = $rows->where('status', 'filled')->count();
        $accepted = $rows->where('status', 'accepted')->count();
        $offered = $rows->where('status', 'offered')->count();
        $active = $rows->firstWhere('status', 'filled')
            ?? $rows->firstWhere('status', 'accepted')
            ?? $rows->firstWhere('status', 'offered');
        $status = match (true) {
            $filled >= (int) $request->headcount_needed => 'filled',
            $accepted > 0 || $filled > 0 => 'assigned',
            $offered > 0 => 'sourcing',
            default => 'open',
        };
        $updates = [
            'status' => $status,
            'assigned_staff_ref' => $active ? "staff:{$active->staff_member_id}" : null,
            'assigned_source' => $active?->source,
            'updated_by_user_id' => $actorUserId,
            'resolution_payload' => [
                'canonical_fulfillment' => true,
                'filled_count' => $filled,
                'headcount_needed' => (int) $request->headcount_needed,
            ],
        ];
        if (in_array($status, ['assigned', 'filled'], true)) {
            $updates['assigned_at'] = $request->assigned_at ?? now();
        } else {
            $updates['assigned_at'] = null;
        }
        if ($status === 'filled') {
            $updates['filled_at'] = $request->filled_at ?? now();
        } else {
            $updates['filled_at'] = null;
        }
        $request->update($updates);
    }

    private function assertFulfillmentCapacity(StaffingRequest $request, ?int $excludeFulfillmentId = null): void
    {
        $reserved = StaffingRequestFulfillment::query()
            ->where('staffing_request_id', $request->staffing_request_id)
            ->whereIn('status', ['accepted', 'filled'])
            ->when($excludeFulfillmentId !== null, fn ($query) => $query
                ->where('staffing_request_fulfillment_id', '!=', $excludeFulfillmentId))
            ->count();
        if ($reserved >= (int) $request->headcount_needed) {
            throw ValidationException::withMessages([
                'request' => 'The staffing request already has its required accepted or filled canonical headcount.',
            ]);
        }
    }

    private function recordFulfillmentEvent(
        StaffingRequestFulfillment $fulfillment,
        ?string $from,
        string $to,
        array $payload,
        ?int $actorUserId,
    ): void {
        StaffingFulfillmentEvent::create([
            'event_uuid' => (string) Str::uuid(),
            'fulfillment_uuid' => $fulfillment->fulfillment_uuid,
            'staffing_request_id' => $fulfillment->staffing_request_id,
            'staff_member_id' => $fulfillment->staff_member_id,
            'event_type' => "staffing.fulfillment_{$to}",
            'from_status' => $from,
            'to_status' => $to,
            'payload' => $payload,
            'actor_user_id' => $actorUserId,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function recordLegacyEvent(
        StaffingRequest $request,
        string $eventType,
        ?string $from,
        ?string $to,
        array $payload,
        ?int $actorUserId,
    ): void {
        StaffingEvent::create([
            'event_uuid' => (string) Str::uuid(),
            'staffing_request_id' => $request->staffing_request_id,
            'event_type' => $eventType,
            'from_status' => $from,
            'to_status' => $to,
            'payload' => $payload,
            'source' => 'canonical-staffing',
            'actor_user_id' => $actorUserId,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function executeIdempotent(
        int $requestId,
        string $commandType,
        string $idempotencyKey,
        array $payload,
        ?int $actorUserId,
        callable $operation,
    ): array {
        $this->requireTables();
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 200) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid Idempotency-Key header is required.']);
        }
        $hash = hash('sha256', json_encode($this->canonicalize([
            'request_id' => $requestId,
            'command_type' => $commandType,
            'payload' => $payload,
        ]), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($requestId, $commandType, $idempotencyKey, $payload, $actorUserId, $operation, $hash): array {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$idempotencyKey]);
            $existing = DB::table('prod.staffing_fulfillment_commands')
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                if (! hash_equals((string) $existing->request_hash, $hash)) {
                    throw new ConflictHttpException('The idempotency key was already used for a different staffing command.');
                }

                return $this->decodeMap($existing->response_payload);
            }

            $response = $operation($payload);
            DB::table('prod.staffing_fulfillment_commands')->insert([
                'command_uuid' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'staffing_request_id' => $requestId,
                'command_type' => $commandType,
                'request_hash' => $hash,
                'response_payload' => json_encode($response, JSON_THROW_ON_ERROR),
                'actor_user_id' => $actorUserId,
                'created_at' => now(),
            ]);

            return $response;
        }, 3);
    }

    private function lockedRequest(int $requestId): StaffingRequest
    {
        return StaffingRequest::query()
            ->where('staffing_request_id', $requestId)
            ->where('is_deleted', false)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertRequestOpen(StaffingRequest $request, bool $allowFilled = false): void
    {
        $terminal = ['completed', 'canceled'];
        if (! $allowFilled) {
            $terminal[] = 'filled';
        }
        if (in_array($request->status, $terminal, true)) {
            throw ValidationException::withMessages(['request' => 'The staffing request is not open for fulfillment.']);
        }
    }

    private function lockStaffMember(int $staffMemberId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [7000000000 + $staffMemberId]);
    }

    private function tablesAvailable(): bool
    {
        return $this->tablesAvailable ??= Schema::hasTable('prod.staffing_request_fulfillments')
            && Schema::hasTable('prod.staff_shift_assignments')
            && Schema::hasTable('prod.staff_availability_windows')
            && Schema::hasTable('hosp_org.staff_member_qualifications');
    }

    private function requireTables(): void
    {
        if (! $this->tablesAvailable()) {
            throw ValidationException::withMessages(['staffing' => 'Canonical staffing fulfillment is not configured.']);
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    /** @return array<string,mixed> */
    private function decodeMap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array) $value;
        }

        return is_string($value) ? (json_decode($value, true) ?: []) : [];
    }
}
