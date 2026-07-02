<?php

namespace App\Services\Mobile;

use App\Models\BedRequest;
use App\Models\EdVisit;
use App\Models\Evs\EvsRequest;
use App\Models\Transport\TransportRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MobilePatientContextService
{
    public function __construct(
        private readonly OperationalActivityLedger $ledger,
        private readonly MobilePersonaCatalog $personas,
    ) {}

    public function contextRefFor(?string $patientRef): ?string
    {
        if (! $patientRef) {
            return null;
        }

        return 'ptok_'.substr(hash_hmac('sha256', $patientRef, (string) config('app.key', 'zephyrus')), 0, 24);
    }

    /** @return array<string, mixed> */
    public function build(string $patientRefOrContextRef, ?User $user = null, ?string $roleId = null): array
    {
        $patientRef = $this->resolvePatientRef($patientRefOrContextRef);
        $roleId = $this->personas->normalize($roleId, $user);

        if (! $patientRef || ! $this->canAccessPatientContext($patientRefOrContextRef, $patientRef, $user, $roleId)) {
            throw new AuthorizationException('This patient operational context is not available to the current mobile persona.');
        }

        $contextRef = $this->contextRefFor($patientRef);

        $bedRequests = BedRequest::query()
            ->where('patient_ref', $patientRef)
            ->where('is_deleted', false)
            ->orderByDesc('bed_request_id')
            ->get();

        $transport = TransportRequest::query()
            ->where('patient_ref', $patientRef)
            ->where('is_deleted', false)
            ->orderByDesc('transport_request_id')
            ->get();

        $evs = EvsRequest::query()
            ->where('patient_ref', $patientRef)
            ->where('is_deleted', false)
            ->orderByDesc('evs_request_id')
            ->get();

        $edVisits = EdVisit::query()
            ->where('patient_ref', $patientRef)
            ->where('is_deleted', false)
            ->with('unit')
            ->orderByDesc('ed_visit_id')
            ->get();

        $timeline = $this->timeline($bedRequests, $transport, $evs, $edVisits, $patientRef);
        $dependencies = $this->dependencies($bedRequests, $transport, $evs);
        $activity = $this->ledger->forPatient($patientRef, 25);

        $context = [
            'altitude' => 'A2P',
            'persona' => $this->personas->describe($roleId),
            'patient' => [
                'patient_context_ref' => $contextRef,
                'display' => 'Authorized operational patient context',
                'detail_authorized' => true,
                'phi_minimized' => true,
            ],
            'header' => [
                'current_location' => $this->currentLocation($edVisits, $transport, $evs),
                'target_location' => $this->targetLocation($bedRequests, $transport),
                'service' => $bedRequests->first()?->service ?? $transport->first()?->clinical_service,
                'isolation_required' => $bedRequests->first()?->isolation_required ?? (bool) $evs->first()?->isolation_required,
                'responsible_team' => $this->responsibleTeam($roleId),
                'as_of' => now()->toISOString(),
            ],
            'status_spine' => $this->statusSpine($bedRequests, $transport, $evs, $edVisits),
            'timeline' => $timeline,
            'dependencies' => $dependencies,
            'recommendations' => $this->recommendations($patientRef),
            'actions' => $this->allowedActions($roleId, $dependencies),
            'relay' => [
                'will_notify_roles' => $dependencies->pluck('owner_role')->filter()->unique()->values(),
                'activity_roles' => ['capacity_lead', 'house_supervisor', 'pi_lead'],
            ],
            'activity' => $activity,
            'web' => [
                'href' => url('/rtdc/bed-tracking'),
                'label' => 'Open in Zephyrus',
                'altitude' => 'A3',
            ],
            'phi_policy' => [
                'list_safe' => false,
                'push_safe' => false,
                'requires_detail_auth' => true,
            ],
        ];

        $this->cache($contextRef, $patientRef, $context);

        return $context;
    }

    public function resolvePatientRef(string $patientRefOrContextRef): ?string
    {
        if (! str_starts_with($patientRefOrContextRef, 'ptok_')) {
            return $patientRefOrContextRef;
        }

        return $this->candidatePatientRefs()
            ->first(fn (string $candidate): bool => $this->contextRefFor($candidate) === $patientRefOrContextRef);
    }

    public function hasPatientContext(string $patientRefOrContextRef): bool
    {
        $patientRef = $this->resolvePatientRef($patientRefOrContextRef);

        if (! $patientRef) {
            return false;
        }

        return BedRequest::query()->where('patient_ref', $patientRef)->where('is_deleted', false)->exists()
            || TransportRequest::query()->where('patient_ref', $patientRef)->where('is_deleted', false)->exists()
            || EvsRequest::query()->where('patient_ref', $patientRef)->where('is_deleted', false)->exists()
            || EdVisit::query()->where('patient_ref', $patientRef)->where('is_deleted', false)->exists();
    }

    /** @return Collection<int, string> */
    private function candidatePatientRefs(): Collection
    {
        return collect()
            ->merge(BedRequest::query()->whereNotNull('patient_ref')->where('is_deleted', false)->limit(500)->pluck('patient_ref'))
            ->merge(TransportRequest::query()->whereNotNull('patient_ref')->where('is_deleted', false)->limit(500)->pluck('patient_ref'))
            ->merge(EvsRequest::query()->whereNotNull('patient_ref')->where('is_deleted', false)->limit(500)->pluck('patient_ref'))
            ->merge(EdVisit::query()->whereNotNull('patient_ref')->where('is_deleted', false)->limit(500)->pluck('patient_ref'))
            ->filter()
            ->unique()
            ->values();
    }

    private function canAccessPatientContext(string $requestedRef, string $patientRef, ?User $user, string $roleId): bool
    {
        if (! $user || ! $this->hasPatientContext($patientRef)) {
            return false;
        }

        if (! str_starts_with($requestedRef, 'ptok_')) {
            return false;
        }

        if ($this->personas->isBroadAccessUser($user)) {
            return true;
        }

        if (in_array($roleId, ['bed_manager', 'house_supervisor', 'capacity_lead'], true)) {
            return true;
        }

        if ($roleId === 'transport') {
            return TransportRequest::query()
                ->where('patient_ref', $patientRef)
                ->where('is_deleted', false)
                ->exists();
        }

        if ($roleId === 'evs') {
            return EvsRequest::query()
                ->where('patient_ref', $patientRef)
                ->where('is_deleted', false)
                ->exists();
        }

        if (in_array($roleId, ['charge_nurse', 'bedside_nurse', 'hospitalist', 'intensivist'], true)) {
            return $this->userSharesPatientUnit($user, $patientRef);
        }

        return false;
    }

    private function userSharesPatientUnit(User $user, string $patientRef): bool
    {
        $patientUnitIds = collect()
            ->merge(EdVisit::query()
                ->where('patient_ref', $patientRef)
                ->where('is_deleted', false)
                ->whereNotNull('unit_id')
                ->pluck('unit_id'))
            ->merge(EvsRequest::query()
                ->where('patient_ref', $patientRef)
                ->where('is_deleted', false)
                ->whereNotNull('unit_id')
                ->pluck('unit_id'))
            ->filter()
            ->map(fn ($unitId): int => (int) $unitId)
            ->unique()
            ->values();

        if ($patientUnitIds->isEmpty() || ! Schema::hasTable('prod.user_unit')) {
            return false;
        }

        return $user->units()
            ->wherePivotIn('unit_id', $patientUnitIds->all())
            ->exists();
    }

    private function statusSpine(Collection $bedRequests, Collection $transport, Collection $evs, Collection $edVisits): array
    {
        return collect([
            $edVisits->isNotEmpty() ? [
                'domain' => 'ed',
                'label' => 'ED visit',
                'status' => $edVisits->first()->departed_at ? 'completed' : ($edVisits->first()->admit_decision_at ? 'boarding' : 'active'),
                'at' => $edVisits->first()->arrived_at?->toISOString(),
            ] : null,
            $bedRequests->isNotEmpty() ? [
                'domain' => 'rtdc',
                'label' => 'Bed request',
                'status' => $bedRequests->first()->status,
                'at' => $bedRequests->first()->created_at?->toISOString(),
            ] : null,
            $transport->isNotEmpty() ? [
                'domain' => 'transport',
                'label' => 'Transport',
                'status' => $transport->first()->status,
                'at' => $transport->first()->requested_at?->toISOString(),
            ] : null,
            $evs->isNotEmpty() ? [
                'domain' => 'evs',
                'label' => 'EVS dependency',
                'status' => $evs->first()->status,
                'at' => $evs->first()->requested_at?->toISOString(),
            ] : null,
        ])->filter()->values()->all();
    }

    private function dependencies(Collection $bedRequests, Collection $transport, Collection $evs): Collection
    {
        return collect()
            ->merge($bedRequests->where('status', 'pending')->map(fn (BedRequest $request): array => [
                'dependency_type' => 'bed_request',
                'owner_role' => 'bed_manager',
                'status' => $request->status,
                'label' => 'Pending bed placement',
                'entity_ref' => (string) $request->bed_request_id,
            ]))
            ->merge($transport->reject(fn (TransportRequest $request): bool => in_array($request->status, ['completed', 'canceled', 'failed'], true))->map(fn (TransportRequest $request): array => [
                'dependency_type' => 'transport',
                'owner_role' => 'transport',
                'status' => $request->status,
                'label' => trim(($request->origin ?: 'Origin').' to '.($request->destination ?: 'destination')),
                'entity_ref' => (string) $request->transport_request_id,
            ]))
            ->merge($evs->reject(fn (EvsRequest $request): bool => in_array($request->status, ['completed', 'canceled', 'failed'], true))->map(fn (EvsRequest $request): array => [
                'dependency_type' => 'evs',
                'owner_role' => 'evs',
                'status' => $request->status,
                'label' => $request->location_label ?: 'Bed turn',
                'entity_ref' => (string) $request->evs_request_id,
            ]))
            ->values();
    }

    private function timeline(Collection $bedRequests, Collection $transport, Collection $evs, Collection $edVisits, string $patientRef): array
    {
        return collect()
            ->merge($edVisits->map(fn (EdVisit $visit): array => [
                'event_type' => 'ed.visit_observed',
                'domain' => 'ed',
                'actor_role' => null,
                'status_after' => $visit->departed_at ? 'departed' : ($visit->admit_decision_at ? 'boarding' : 'active'),
                'occurred_at' => $visit->arrived_at?->toISOString(),
                'patient_context_ref' => $this->contextRefFor($patientRef),
            ]))
            ->merge($bedRequests->map(fn (BedRequest $request): array => [
                'event_type' => $request->status === 'placed' ? 'bed_request.placed' : 'bed_request.created',
                'domain' => 'rtdc',
                'actor_role' => 'bed_manager',
                'status_after' => $request->status,
                'occurred_at' => $request->created_at?->toISOString(),
                'patient_context_ref' => $this->contextRefFor($patientRef),
            ]))
            ->merge($transport->map(fn (TransportRequest $request): array => [
                'event_type' => 'transport.progressed',
                'domain' => 'transport',
                'actor_role' => 'transport',
                'status_after' => $request->status,
                'occurred_at' => ($request->completed_at ?? $request->requested_at)?->toISOString(),
                'patient_context_ref' => $this->contextRefFor($patientRef),
            ]))
            ->merge($evs->map(fn (EvsRequest $request): array => [
                'event_type' => 'evs.'.$request->status,
                'domain' => 'evs',
                'actor_role' => 'evs',
                'status_after' => $request->status,
                'occurred_at' => ($request->completed_at ?? $request->requested_at)?->toISOString(),
                'patient_context_ref' => $this->contextRefFor($patientRef),
            ]))
            ->sortBy('occurred_at')
            ->values()
            ->all();
    }

    private function currentLocation(Collection $edVisits, Collection $transport, Collection $evs): ?string
    {
        return $transport->first()?->origin
            ?? $evs->first()?->location_label
            ?? $edVisits->first()?->unit?->name;
    }

    private function targetLocation(Collection $bedRequests, Collection $transport): ?string
    {
        return $transport->first()?->destination
            ?? $bedRequests->first()?->required_unit_type;
    }

    private function responsibleTeam(string $roleId): string
    {
        return match ($roleId) {
            'transport' => 'Transport',
            'evs' => 'EVS',
            'hospitalist', 'intensivist' => 'Medical service',
            'bed_manager', 'capacity_lead' => 'Capacity',
            default => 'Unit care team',
        };
    }

    private function recommendations(string $patientRef): array
    {
        if (! Schema::hasTable('ops.recommendations')) {
            return [];
        }

        return DB::table('ops.recommendations')
            ->where('scope_key', $patientRef)
            ->orderByDesc('recommendation_id')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => [
                'recommendation_uuid' => $row->recommendation_uuid,
                'source' => $row->created_by_source,
                'title' => $row->title,
                'status' => $row->status,
                'risk_level' => $row->risk_level,
                'rationale' => $row->rationale,
            ])
            ->values()
            ->all();
    }

    private function allowedActions(string $roleId, Collection $dependencies): array
    {
        $base = [['kind' => 'acknowledge', 'label' => 'Acknowledge', 'requires_online' => true]];

        return match ($roleId) {
            'bed_manager' => [...$base, ['kind' => 'place', 'label' => 'Place bed', 'requires_online' => true]],
            'charge_nurse' => [...$base, ['kind' => 'mark_ready', 'label' => 'Mark ready', 'requires_online' => true], ['kind' => 'barrier', 'label' => 'Update barrier', 'requires_online' => true]],
            'transport' => [...$base, ['kind' => 'progress_transport', 'label' => 'Progress trip', 'requires_online' => true]],
            'evs' => [...$base, ['kind' => 'progress_turn', 'label' => 'Progress turn', 'requires_online' => true]],
            'hospitalist', 'intensivist' => [...$base, ['kind' => 'resolve_barrier', 'label' => 'Resolve barrier', 'requires_online' => true]],
            default => $base,
        };
    }

    private function cache(?string $contextRef, string $patientRef, array $context): void
    {
        if (! $contextRef || ! Schema::hasTable('ops.patient_operational_context_cache')) {
            return;
        }

        DB::table('ops.patient_operational_context_cache')->updateOrInsert(
            ['patient_context_ref' => $contextRef],
            [
                'patient_ref' => $patientRef,
                'encounter_ref' => $context['header']['encounter_ref'] ?? null,
                'generated_at' => now(),
                'expires_at' => now()->addMinutes(10),
                'context_payload' => json_encode($context),
                'phi_policy' => json_encode($context['phi_policy']),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }
}
