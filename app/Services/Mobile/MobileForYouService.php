<?php

namespace App\Services\Mobile;

use App\Authorization\Capability;
use App\Models\Barrier;
use App\Models\BedRequest;
use App\Models\Evs\EvsRequest;
use App\Models\Ops\Approval;
use App\Models\PatientCommunication\PoolMembership;
use App\Models\PatientCommunication\ThreadWorkItem;
use App\Models\Staffing\StaffingRequest;
use App\Models\Transport\TransportRequest;
use App\Models\Unit;
use App\Models\User;
use App\Services\AcuityService;
use App\Services\Authorization\RoleCapabilityService;
use App\Services\Patient\Messaging\StaffPatientCommunicationFailure;
use App\Services\Staffing\StaffingOperationsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class MobileForYouService
{
    public function __construct(
        private readonly AcuityService $acuity,
        private readonly MobilePatientContextService $patients,
        private readonly RoleCapabilityService $authorization,
    ) {}

    /**
     * User-authorized For You projection. Existing altitude callers retain the
     * operational-only items() projection; only the dedicated /for-you route
     * opts into the restricted patient-communication attention class.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function itemsForUser(User $user): Collection
    {
        return $this->sortItems(
            $this->items()->concat($this->patientCommunicationItems($user)),
        );
    }

    /** @return Collection<int, array<string, mixed>> */
    public function items(): Collection
    {
        $items = new Collection;

        foreach (BedRequest::pending()->orderBy('created_at')->get() as $request) {
            $tier = match (true) {
                $request->acuity_tier !== null && $request->acuity_tier <= 1 => 'critical',
                $request->acuity_tier !== null && $request->acuity_tier <= 2 => 'warning',
                default => 'info',
            };

            $items->push($this->item([
                'id' => 'bedreq-'.$request->bed_request_id,
                'type' => 'bed_request',
                'domain' => 'rtdc',
                'tier' => $tier,
                'title' => 'Bed placement needed',
                'subtitle' => trim(($request->service ?: 'Unassigned').' · needs '.($this->humanize($request->required_unit_type, capitalize: false) ?: 'any unit')
                    .($request->isolation_required && $request->isolation_required !== 'none' ? ' · '.$this->humanize($request->isolation_required, capitalize: false).' isolation' : '')),
                'unit' => null,
                'at' => optional($request->created_at)->toIso8601String(),
                'patient_context_ref' => $this->patients->contextRefFor($request->patient_ref),
                'dependencies' => [['type' => 'bed_request', 'owner_role' => 'bed_manager', 'status' => $request->status]],
                'provenance' => ['source_service' => 'BedRequest', 'metric_key' => 'rtdc.pending_bed_request', 'stale' => false],
            ]));
        }

        foreach (Barrier::open()->with('unit')->orderBy('opened_at')->get() as $barrier) {
            $items->push($this->item([
                'id' => 'barrier-'.$barrier->barrier_id,
                'type' => 'barrier',
                'domain' => 'rtdc',
                'tier' => in_array($barrier->category, ['placement', 'medical'], true) ? 'warning' : 'info',
                'title' => ucfirst($barrier->category ?: 'Discharge').' barrier',
                'subtitle' => $this->humanize($barrier->reason_code) ?: 'Open barrier to clear',
                'unit' => $barrier->unit?->name,
                'at' => optional($barrier->opened_at)->toIso8601String(),
                'dependencies' => [['type' => 'barrier', 'owner_role' => $barrier->owner ?: 'charge_nurse', 'status' => $barrier->status]],
                'provenance' => ['source_service' => 'Barrier', 'metric_key' => 'rtdc.open_barrier', 'stale' => false],
            ]));
        }

        foreach (Unit::with('beds')->where('is_deleted', false)->get() as $unit) {
            $occupied = $unit->beds->where('status', 'occupied')->count();
            $available = $unit->beds->where('status', 'available')->count();
            $staffed = (int) $unit->staffed_bed_count;
            $canAdmit = max(0, min((int) $this->acuity->adjustedCapacity($unit->unit_id), $available));

            if ($occupied > 0 && $canAdmit <= 0) {
                $items->push($this->item([
                    'id' => 'cap-'.$unit->unit_id,
                    'type' => 'capacity',
                    'domain' => 'rtdc',
                    'tier' => 'critical',
                    'title' => $unit->name.' at capacity',
                    'subtitle' => $occupied.' / '.$staffed.' beds · no safe admit capacity',
                    'unit' => $unit->name,
                    'at' => now()->toIso8601String(),
                    'dependencies' => [['type' => 'capacity', 'owner_role' => 'charge_nurse', 'status' => 'blocked']],
                    'provenance' => ['source_service' => 'AcuityService', 'metric_key' => 'rtdc.safe_admit_capacity', 'stale' => false],
                ]));
            }
        }

        foreach (TransportRequest::active()->get() as $request) {
            $atRisk = $request->priority === 'stat' || ($request->needed_at !== null && $request->needed_at->isPast());
            if (! $atRisk) {
                continue;
            }

            $items->push($this->item([
                'id' => 'transport-'.$request->transport_request_id,
                'type' => 'transport',
                'domain' => 'transport',
                'tier' => $request->priority === 'stat' ? 'critical' : 'warning',
                'title' => $request->priority === 'stat' ? 'STAT transport' : 'Transport past due',
                'subtitle' => trim(($request->origin ?: '-').' → '.($request->destination ?: '-')),
                'unit' => null,
                'at' => optional($request->needed_at ?? $request->requested_at)->toIso8601String(),
                'patient_context_ref' => $this->patients->contextRefFor($request->patient_ref),
                'dependencies' => [['type' => 'transport', 'owner_role' => 'transport', 'status' => $request->status]],
                'provenance' => ['source_service' => 'TransportRequest', 'metric_key' => 'transport.at_risk_job', 'stale' => false],
            ]));
        }

        foreach (EvsRequest::active()->get() as $request) {
            $overdue = $request->needed_at !== null && $request->needed_at->isPast();
            if (! $overdue && ! $request->isolation_required) {
                continue;
            }

            $items->push($this->item([
                'id' => 'evs-'.$request->evs_request_id,
                'type' => 'evs',
                'domain' => 'evs',
                'tier' => $overdue ? 'warning' : 'info',
                'title' => $request->isolation_required ? 'Isolation bed-turn' : 'Bed-turn past due',
                'subtitle' => trim(($request->location_label ?: 'Bed').' · '.$this->humanize((string) ($request->turn_type ?: $request->request_type), capitalize: false)),
                'unit' => null,
                'at' => optional($request->needed_at ?? $request->requested_at)->toIso8601String(),
                'patient_context_ref' => $this->patients->contextRefFor($request->patient_ref),
                'dependencies' => [['type' => 'evs', 'owner_role' => 'evs', 'status' => $request->status]],
                'provenance' => ['source_service' => 'EvsRequest', 'metric_key' => 'evs.at_risk_turn', 'stale' => false],
            ]));
        }

        foreach (Approval::query()->where('status', 'pending')->with('action.recommendation')->orderByDesc('requested_at')->get() as $approval) {
            $recommendation = $approval->action?->recommendation;
            $tier = match ($recommendation?->risk_level) {
                'critical' => 'critical',
                'high' => 'warning',
                default => 'info',
            };

            $items->push($this->item([
                'id' => 'ops-approval-'.$approval->approval_uuid,
                'type' => 'ops_approval',
                'domain' => 'ops',
                'tier' => $tier,
                'title' => $recommendation?->title ?? 'Operational approval',
                'subtitle' => collect([
                    $this->humanize($recommendation?->recommendation_type),
                    $approval->action?->owner_name,
                    $recommendation?->risk_level ? ucfirst($recommendation->risk_level).' risk' : null,
                ])->filter()->join(' · '),
                'unit' => null,
                'at' => optional($approval->requested_at)->toIso8601String(),
                'dependencies' => [['type' => 'ops_approval', 'owner_role' => 'capacity_lead', 'status' => $approval->status]],
                'provenance' => ['source_service' => 'Approval', 'metric_key' => 'ops.pending_approval', 'stale' => false],
            ]));
        }

        foreach (StaffingRequest::active()->orderByRaw("CASE priority WHEN 'stat' THEN 0 WHEN 'urgent' THEN 1 ELSE 2 END")->orderByRaw('needed_by NULLS LAST')->get() as $request) {
            $tier = match ($request->priority) {
                'stat' => 'critical',
                'urgent' => 'warning',
                default => 'info',
            };
            $roleLabel = StaffingOperationsService::ROLE_LABELS[$request->role] ?? $this->humanize($request->role);

            $items->push($this->item([
                'id' => 'staffing-'.$request->staffing_request_id,
                'type' => 'staffing_request',
                'domain' => 'staffing',
                'tier' => $tier,
                'title' => 'Staffing gap: '.$roleLabel,
                'subtitle' => collect([
                    $request->unit_label,
                    $request->headcount_needed ? $request->headcount_needed.' needed' : null,
                    $this->humanize($request->shift),
                ])->filter()->join(' · '),
                'unit' => $request->unit_label,
                'at' => optional($request->needed_by)->toIso8601String(),
                'dependencies' => [['type' => 'staffing_request', 'owner_role' => 'staffing_coordinator', 'status' => $request->status]],
                'provenance' => ['source_service' => 'StaffingRequest', 'metric_key' => 'staffing.active_request', 'stale' => false],
            ]));
        }

        return $this->sortItems($items);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function patientCommunicationItems(User $user): Collection
    {
        if (! $this->patientCommunicationsEnabled()) {
            return collect();
        }

        if (! $this->authorization->allows($user, Capability::ViewPatientCommunications)) {
            return collect();
        }

        foreach ([
            'patient_communications.responsibility_pools',
            'patient_communications.pool_memberships',
            'patient_communications.thread_work_items',
        ] as $requiredTable) {
            if (! Schema::hasTable($requiredTable)) {
                throw StaffPatientCommunicationFailure::unavailable();
            }
        }

        $poolIds = PoolMembership::query()
            ->effective()
            ->where('staff_user_id', $user->getKey())
            ->whereHas('pool', fn (Builder $pool): Builder => $pool->where('status', 'active'))
            ->pluck('responsibility_pool_id');
        if ($poolIds->isEmpty()) {
            return collect();
        }

        $asOf = now();
        $workItems = ThreadWorkItem::query()
            ->with('pool.unit')
            ->where('status', 'open')
            ->whereIn('responsibility_pool_id', $poolIds)
            ->where(function (Builder $eligible) use ($user): void {
                $eligible
                    ->where(function (Builder $poolOwned): void {
                        $poolOwned
                            ->whereNull('assigned_user_id')
                            ->whereIn('ownership_state', ['pool_owned', 'rerouted', 'escalated']);
                    })
                    ->orWhere(function (Builder $personallyAssigned) use ($user): void {
                        $personallyAssigned
                            ->where('assigned_user_id', $user->getKey())
                            ->whereIn('ownership_state', ['assigned', 'acknowledged']);
                    });
            })
            ->orderByRaw(
                "CASE WHEN ownership_state = 'escalated' OR escalate_at <= ? THEN 0 WHEN ownership_state = 'rerouted' OR due_at <= ? THEN 1 ELSE 2 END",
                [$asOf, $asOf],
            )
            ->orderBy('escalate_at')
            ->orderBy('last_message_at')
            ->limit(50)
            ->get();

        return $workItems->map(function (ThreadWorkItem $workItem) use ($asOf): array {
            $tier = match (true) {
                $workItem->ownership_state === 'escalated'
                    || ($workItem->escalate_at?->lessThanOrEqualTo($asOf) ?? false) => 'critical',
                $workItem->ownership_state === 'rerouted'
                    || ($workItem->due_at?->lessThanOrEqualTo($asOf) ?? false) => 'warning',
                default => 'info',
            };
            $personallyAssigned = $workItem->assigned_user_id !== null;

            $item = $this->item([
                'id' => 'patient-communication-'.$workItem->work_item_uuid,
                'type' => 'patient_communication',
                'domain' => 'communications',
                'tier' => $tier,
                'title' => match ($tier) {
                    'critical' => 'Escalated patient communication',
                    'warning' => 'Patient communication response due',
                    default => $personallyAssigned
                        ? 'Assigned patient communication'
                        : 'New patient communication',
                },
                'subtitle' => $personallyAssigned
                    ? 'Assigned to you · response pending'
                    : 'Responsibility pool · response pending',
                // A unit label is allowed only through the governed unit-scoped
                // responsibility pool. Encounter/grant context is never joined.
                'unit' => $workItem->pool?->scope_type === 'unit'
                    ? $workItem->pool->unit?->name
                    : null,
                'at' => $workItem->last_message_at?->toIso8601String(),
                'primary_action' => [
                    'kind' => 'view',
                    'label' => 'Open communication',
                    'endpoint' => "/api/mobile/v1/patient-communications/threads/{$workItem->work_item_uuid}",
                    'method' => 'GET',
                    'requires_online' => true,
                ],
                'provenance' => [
                    'source_service' => 'PatientCommunicationAttentionProjection',
                    'metric_key' => 'patient_communications.attention',
                    'stale' => false,
                ],
            ]);

            // Communication attention cards intentionally carry no patient
            // context reference, even as a null placeholder. Opening the
            // online detail route performs its own fresh authorization.
            unset($item['patient_context_ref']);

            return $item;
        });
    }

    private function patientCommunicationsEnabled(): bool
    {
        return (bool) config('hummingbird-patient.enabled', false)
            && (bool) config('hummingbird-patient.features.messaging', false)
            && (bool) config('hummingbird-patient.staff_messaging.enabled', false)
            && config('hummingbird-patient.staff_messaging.governance_status') === 'approved';
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function sortItems(Collection $items): Collection
    {
        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2, 'success' => 3];

        return $items
            ->sortBy(fn (array $item): string => sprintf(
                '%d-%s-%s',
                $rank[$item['tier']] ?? 9,
                $item['at'] ?? '',
                $item['id'] ?? '',
            ))
            ->values();
    }

    /**
     * Machine keys ("blocked_beds", "med_surg") must never reach a worker's screen raw —
     * subtitles are prose the clients render verbatim, so they get worker language here.
     */
    private function humanize(?string $key, bool $capitalize = true): ?string
    {
        if ($key === null || trim($key) === '') {
            return $key;
        }

        $acronyms = ['evs' => 'EVS', 'ed' => 'ED', 'or' => 'OR', 'icu' => 'ICU', 'rtdc' => 'RTDC', 'stat' => 'STAT', 'pacu' => 'PACU'];

        $sentence = collect(preg_split('/[_.\s]+/', trim($key)) ?: [])
            ->filter()
            ->map(fn (string $word): string => $acronyms[strtolower($word)] ?? strtolower($word))
            ->join(' ');

        return $capitalize ? ucfirst($sentence) : $sentence;
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function item(array $item): array
    {
        $tier = $item['tier'] ?? 'info';

        return array_merge([
            'altitude' => 'A2',
            'tier' => $tier,
            'visual_status' => $tier,
            'status' => $tier,
            'status_detail' => [
                'value' => $tier,
                'glyph' => match ($tier) {
                    'critical' => 'octagon',
                    'warning' => 'triangle',
                    'success' => 'check',
                    default => 'circle',
                },
                'label' => ucfirst($tier),
                'generated_at' => now()->toISOString(),
            ],
            'recommended_actions' => [],
            'dependencies' => [],
            'activity' => [],
            'subscriptions' => [],
            'patient_context_ref' => null,
        ], $item);
    }
}
