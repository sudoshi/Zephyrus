<?php

namespace App\Services\Mobile;

use App\Models\Barrier;
use App\Models\BedRequest;
use App\Models\Evs\EvsRequest;
use App\Models\Ops\Approval;
use App\Models\Staffing\StaffingRequest;
use App\Models\Transport\TransportRequest;
use App\Models\Unit;
use App\Services\AcuityService;
use Illuminate\Http\Request;

class MobileAltitudeService
{
    public function __construct(
        private readonly MobilePersonaCatalog $personas,
        private readonly MobileForYouService $forYou,
        private readonly MobilePatientContextService $patients,
        private readonly OperationalActivityLedger $ledger,
        private readonly AcuityService $acuity,
    ) {}

    /** @return array<string, mixed> */
    public function home(Request $request): array
    {
        $roleId = $this->personas->fromRequest($request);
        $forYouHead = $this->forYou->items()->take(5)->values();
        $house = $this->houseMetrics();
        $status = $this->status($house['strain_status']);

        return [
            'altitude' => 'A0',
            'persona' => $this->personas->describe($roleId),
            'status' => $status,
            'generated_at' => now()->toISOString(),
            'glance_question' => $this->personas->describe($roleId)['question'],
            'tiles' => $this->tilesFor($roleId, $house),
            'for_you_head' => $forYouHead,
            'activity' => $this->ledger->feed($request->user(), $roleId, null, 10)['data'],
            'subscriptions' => [
                'activity_role' => $roleId,
                'channels' => ['hospital.beds'],
            ],
            'web' => [
                'href' => url($this->personas->describe($roleId)['web']),
                'altitude' => 'A3',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function workspace(Request $request, string $domain): array
    {
        $roleId = $this->personas->fromRequest($request);
        $domain = strtolower($domain);

        return [
            'altitude' => 'A1',
            'persona' => $this->personas->describe($roleId),
            'domain' => $domain,
            'generated_at' => now()->toISOString(),
            'status' => $this->status('info'),
            'workspace' => match ($domain) {
                'rtdc', 'capacity', 'bed-management' => $this->rtdcWorkspace(),
                'transport' => $this->transportWorkspace(),
                'evs' => $this->evsWorkspace(),
                'staffing' => $this->staffingWorkspace(),
                'ops', 'approvals' => $this->opsWorkspace(),
                default => [
                    'summary' => ['label' => 'For You', 'count' => $this->forYou->items()->count()],
                    'items' => $this->forYou->items(),
                ],
            },
            'activity' => $this->ledger->feed($request->user(), $roleId, null, 15)['data'],
            'web' => ['href' => url('/dashboard'), 'altitude' => 'A3'],
        ];
    }

    /** @return array<string, mixed> */
    public function drill(Request $request, string $itemUuid): array
    {
        $roleId = $this->personas->fromRequest($request);
        $drill = $this->resolveDrill($itemUuid);

        return [
            'altitude' => 'A2',
            'persona' => $this->personas->describe($roleId),
            'item_uuid' => $itemUuid,
            'generated_at' => now()->toISOString(),
            ...$drill,
        ];
    }

    /** @return array<string, mixed> */
    private function resolveDrill(string $itemUuid): array
    {
        if ($event = $this->ledger->findByUuid($itemUuid)) {
            return [
                'domain' => $event['domain'],
                'status' => $event['status'],
                'explanation' => 'Operational event from the shared activity ledger.',
                'dependencies' => [],
                'activity' => [$event],
                'patient_context_ref' => $event['patient_context_ref'],
                'actions' => [['kind' => 'acknowledge', 'label' => 'Acknowledge']],
            ];
        }

        if (str_starts_with($itemUuid, 'bedreq-')) {
            $request = BedRequest::query()->whereKey((int) substr($itemUuid, 7))->firstOrFail();

            return [
                'domain' => 'rtdc',
                'status' => $this->status($request->acuity_tier <= 1 ? 'critical' : ($request->acuity_tier <= 2 ? 'warning' : 'info')),
                'explanation' => 'A patient needs a bed placement that matches service, isolation, acuity, and safe admit capacity.',
                'dependencies' => [['type' => 'bed_request', 'owner_role' => 'bed_manager', 'status' => $request->status]],
                'patient_context_ref' => $this->patients->contextRefFor($request->patient_ref),
                'activity' => $this->ledger->forEntity('bed_request', (string) $request->bed_request_id),
                'actions' => [['kind' => 'place', 'label' => 'Review placement', 'endpoint' => "/api/mobile/v1/rtdc/bed-requests/{$request->bed_request_id}/decision"]],
                'web' => ['href' => url('/rtdc/bed-placement')],
            ];
        }

        if (str_starts_with($itemUuid, 'barrier-')) {
            $barrier = Barrier::query()->with('unit')->whereKey((int) substr($itemUuid, 8))->firstOrFail();

            return [
                'domain' => 'rtdc',
                'status' => $this->status(in_array($barrier->category, ['medical', 'placement'], true) ? 'warning' : 'info'),
                'explanation' => 'An open operational barrier is delaying discharge, placement, or unit throughput.',
                'dependencies' => [['type' => 'barrier', 'owner_role' => $barrier->owner ?: 'charge_nurse', 'status' => $barrier->status]],
                'patient_context_ref' => null,
                'activity' => $this->ledger->forEntity('barrier', (string) $barrier->barrier_id),
                'actions' => [['kind' => 'resolve', 'label' => 'Resolve barrier', 'endpoint' => "/api/mobile/v1/rtdc/barriers/{$barrier->barrier_id}/resolve"]],
                'web' => ['href' => url('/rtdc/bed-tracking')],
            ];
        }

        if (str_starts_with($itemUuid, 'transport-')) {
            $transport = TransportRequest::query()->whereKey((int) substr($itemUuid, 10))->firstOrFail();

            return [
                'domain' => 'transport',
                'status' => $this->status($transport->priority === 'stat' ? 'critical' : 'warning'),
                'explanation' => 'A transport request needs progress to keep the downstream unit, OR, or placement dependency moving.',
                'dependencies' => [['type' => 'transport', 'owner_role' => 'transport', 'status' => $transport->status]],
                'patient_context_ref' => $this->patients->contextRefFor($transport->patient_ref),
                'activity' => $this->ledger->forEntity('transport_request', (string) $transport->transport_request_id),
                'actions' => [['kind' => 'progress_transport', 'label' => 'Progress trip', 'endpoint' => "/api/mobile/v1/transport/requests/{$transport->transport_request_id}/status"]],
                'web' => ['href' => url('/transport/dispatch')],
            ];
        }

        if (str_starts_with($itemUuid, 'evs-')) {
            $evs = EvsRequest::query()->whereKey((int) substr($itemUuid, 4))->firstOrFail();

            return [
                'domain' => 'evs',
                'status' => $this->status($evs->priority === 'stat' ? 'critical' : 'warning'),
                'explanation' => 'A bed turn is blocking placement readiness or isolation-safe care progression.',
                'dependencies' => [['type' => 'evs', 'owner_role' => 'evs', 'status' => $evs->status]],
                'patient_context_ref' => $this->patients->contextRefFor($evs->patient_ref),
                'activity' => $this->ledger->forEntity('evs_request', (string) $evs->evs_request_id),
                'actions' => [['kind' => 'progress_turn', 'label' => 'Progress turn', 'endpoint' => "/api/mobile/v1/evs/requests/{$evs->evs_request_id}/status"]],
                'web' => ['href' => url('/rtdc/bed-tracking')],
            ];
        }

        abort(404);
    }

    /** @return array<string, mixed> */
    private function houseMetrics(): array
    {
        $units = Unit::with('beds')->where('is_deleted', false)->get();
        $occupied = 0;
        $staffed = 0;
        $fullUnits = 0;

        foreach ($units as $unit) {
            $unitOccupied = $unit->beds->where('status', 'occupied')->count();
            $occupied += $unitOccupied;
            $staffed += (int) $unit->staffed_bed_count;
            $available = $unit->beds->where('status', 'available')->count();
            $canAdmit = max(0, min((int) $this->acuity->adjustedCapacity($unit->unit_id), $available));
            if ($unitOccupied > 0 && $canAdmit <= 0) {
                $fullUnits++;
            }
        }

        $pendingPlacements = BedRequest::pending()->count();
        $activeTransport = TransportRequest::active()->count();
        $activeEvs = EvsRequest::active()->count();
        $openStaffing = StaffingRequest::active()->count();
        $pendingApprovals = Approval::query()->where('status', 'pending')->count();
        $occupancyPct = $staffed > 0 ? (int) round($occupied / $staffed * 100) : 0;

        return [
            'occupancy_pct' => $occupancyPct,
            'occupied' => $occupied,
            'staffed' => $staffed,
            'full_units' => $fullUnits,
            'pending_placements' => $pendingPlacements,
            'active_transport' => $activeTransport,
            'active_evs' => $activeEvs,
            'open_staffing' => $openStaffing,
            'pending_approvals' => $pendingApprovals,
            'strain_status' => match (true) {
                $occupancyPct >= 98 || $fullUnits > 1 || $pendingPlacements > 5 => 'critical',
                $occupancyPct >= 90 || $fullUnits > 0 || $pendingPlacements > 0 => 'warning',
                default => 'success',
            },
        ];
    }

    private function tilesFor(string $roleId, array $house): array
    {
        $tiles = [
            ['key' => 'occupancy', 'label' => 'Occupancy', 'value' => $house['occupancy_pct'].'%', 'status' => $house['strain_status'], 'provenance' => $this->provenance('Unit.beds')],
            ['key' => 'placements', 'label' => 'Pending placements', 'value' => (string) $house['pending_placements'], 'status' => $house['pending_placements'] > 0 ? 'warning' : 'success', 'provenance' => $this->provenance('BedRequest')],
            ['key' => 'transport', 'label' => 'Active transport', 'value' => (string) $house['active_transport'], 'status' => $house['active_transport'] > 0 ? 'info' : 'success', 'provenance' => $this->provenance('TransportRequest')],
            ['key' => 'evs', 'label' => 'Active EVS turns', 'value' => (string) $house['active_evs'], 'status' => $house['active_evs'] > 0 ? 'info' : 'success', 'provenance' => $this->provenance('EvsRequest')],
            ['key' => 'staffing', 'label' => 'Open staffing', 'value' => (string) $house['open_staffing'], 'status' => $house['open_staffing'] > 0 ? 'warning' : 'success', 'provenance' => $this->provenance('StaffingRequest')],
            ['key' => 'approvals', 'label' => 'Pending approvals', 'value' => (string) $house['pending_approvals'], 'status' => $house['pending_approvals'] > 0 ? 'warning' : 'success', 'provenance' => $this->provenance('OperationalActionLifecycleService')],
        ];

        $priority = match ($roleId) {
            'transport' => ['transport', 'placements', 'occupancy', 'evs'],
            'evs' => ['evs', 'placements', 'occupancy', 'transport'],
            'staffing_coordinator' => ['staffing', 'occupancy', 'placements', 'approvals'],
            'capacity_lead' => ['occupancy', 'placements', 'approvals', 'staffing'],
            'executive' => ['occupancy', 'approvals', 'placements', 'staffing'],
            default => ['occupancy', 'placements', 'transport', 'evs'],
        };

        return collect($priority)
            ->map(fn (string $key): ?array => collect($tiles)->firstWhere('key', $key))
            ->filter()
            ->values()
            ->all();
    }

    private function rtdcWorkspace(): array
    {
        return [
            'summary' => ['label' => 'RTDC placement and capacity', 'count' => BedRequest::pending()->count()],
            'items' => $this->forYou->items()->whereIn('domain', ['rtdc'])->values(),
        ];
    }

    private function transportWorkspace(): array
    {
        $jobs = TransportRequest::active()
            ->orderByDesc('transport_request_id')
            ->limit(50)
            ->get()
            ->map(fn (TransportRequest $request): array => [
                'id' => $request->transport_request_id,
                'status' => $request->status,
                'priority' => $request->priority,
                'origin' => $request->origin,
                'destination' => $request->destination,
                'patient_context_ref' => $this->patients->contextRefFor($request->patient_ref),
            ]);

        return ['summary' => ['label' => 'Transport queue', 'count' => $jobs->count()], 'items' => $jobs];
    }

    private function evsWorkspace(): array
    {
        $turns = EvsRequest::active()
            ->orderByDesc('evs_request_id')
            ->limit(50)
            ->get()
            ->map(fn (EvsRequest $request): array => [
                'id' => $request->evs_request_id,
                'status' => $request->status,
                'priority' => $request->priority,
                'location_label' => $request->location_label,
                'turn_type' => $request->turn_type,
                'patient_context_ref' => $this->patients->contextRefFor($request->patient_ref),
            ]);

        return ['summary' => ['label' => 'EVS turns', 'count' => $turns->count()], 'items' => $turns];
    }

    private function staffingWorkspace(): array
    {
        $requests = StaffingRequest::active()
            ->orderByDesc('staffing_request_id')
            ->limit(50)
            ->get()
            ->map(fn (StaffingRequest $request): array => [
                'id' => $request->staffing_request_id,
                'unit_label' => $request->unit_label,
                'role' => $request->role,
                'priority' => $request->priority,
                'status' => $request->status,
                'needed_by' => $request->needed_by?->toISOString(),
            ]);

        return ['summary' => ['label' => 'Staffing requests', 'count' => $requests->count()], 'items' => $requests];
    }

    private function opsWorkspace(): array
    {
        $approvals = Approval::query()
            ->with('action.recommendation')
            ->where('status', 'pending')
            ->orderByDesc('requested_at')
            ->limit(50)
            ->get()
            ->map(fn (Approval $approval): array => [
                'approval_uuid' => $approval->approval_uuid,
                'status' => $approval->status,
                'title' => $approval->action?->recommendation?->title ?? 'Operational approval',
                'requested_at' => $approval->requested_at?->toISOString(),
            ]);

        return ['summary' => ['label' => 'Operational approvals', 'count' => $approvals->count()], 'items' => $approvals];
    }

    /** @return array<string, mixed> */
    private function status(string $value): array
    {
        return [
            'value' => $value,
            'glyph' => match ($value) {
                'critical' => 'octagon',
                'warning' => 'triangle',
                'success' => 'check',
                default => 'circle',
            },
            'label' => ucfirst($value),
            'generated_at' => now()->toISOString(),
        ];
    }

    private function provenance(string $source): array
    {
        return [
            'source_service' => $source,
            'snapshot_version' => now()->format('YmdHi'),
            'stale' => false,
            'generated_at' => now()->toISOString(),
        ];
    }
}
