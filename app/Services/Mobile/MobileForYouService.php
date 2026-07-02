<?php

namespace App\Services\Mobile;

use App\Models\Barrier;
use App\Models\BedRequest;
use App\Models\Evs\EvsRequest;
use App\Models\Transport\TransportRequest;
use App\Models\Unit;
use App\Services\AcuityService;
use Illuminate\Support\Collection;

class MobileForYouService
{
    public function __construct(
        private readonly AcuityService $acuity,
        private readonly MobilePatientContextService $patients,
    ) {}

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
                'subtitle' => trim(($request->service ?: 'Unassigned').' · needs '.($request->required_unit_type ?: 'any unit')
                    .($request->isolation_required && $request->isolation_required !== 'none' ? ' · '.$request->isolation_required.' isolation' : '')),
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
                'subtitle' => $barrier->reason_code ?: 'Open barrier to clear',
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
                'subtitle' => trim(($request->origin ?: '-').' -> '.($request->destination ?: '-')),
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
                'subtitle' => trim(($request->location_label ?: 'Bed').' · '.str_replace('_', ' ', (string) ($request->turn_type ?: $request->request_type))),
                'unit' => null,
                'at' => optional($request->needed_at ?? $request->requested_at)->toIso8601String(),
                'patient_context_ref' => $this->patients->contextRefFor($request->patient_ref),
                'dependencies' => [['type' => 'evs', 'owner_role' => 'evs', 'status' => $request->status]],
                'provenance' => ['source_service' => 'EvsRequest', 'metric_key' => 'evs.at_risk_turn', 'stale' => false],
            ]));
        }

        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2, 'success' => 3];

        return $items
            ->sortBy(fn (array $item): string => sprintf('%d-%s', $rank[$item['tier']] ?? 9, $item['at'] ?? ''))
            ->values();
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function item(array $item): array
    {
        $tier = $item['tier'] ?? 'info';

        return array_merge([
            'altitude' => 'A2',
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
