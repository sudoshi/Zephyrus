<?php

namespace App\Services\Flow;

use App\Models\Barrier;
use App\Models\Bed;
use App\Models\BedRequest;
use App\Models\Encounter;
use App\Models\Evs\EvsRequest;
use App\Models\Facility\FacilitySpace;
use App\Models\Ops\Approval;
use App\Models\Staffing\StaffingRequest;
use App\Models\Transport\TransportRequest;
use App\Models\Unit;
use App\Services\Mobile\MobilePatientContextService;
use Carbon\CarbonImmutable;

/**
 * The per-persona DUTIES layer of the 48h Flow Window (NATIVE-4D-VIEWER-PLAN §4 W1).
 *
 * Turns each persona's live worklist (transport / EVS / placement / barrier /
 * staffing / approval / discharge-leverage) into ONE spatially-anchored,
 * due-dated stream the native 3D viewer can place: every duty carries a 3D
 * centroid (from hosp_space.facility_spaces, feet→metres), a due_at, a
 * window_status (overdue|due|upcoming), and the governed BFF endpoint that
 * actions it.
 *
 * The stream is clamped to the caller's lens `duty_kinds`; patient identity is
 * carried ONLY as the internal `_patient_ref` (for FlowLensService::redactRow
 * to gate) plus an opaque ptok — the controller redacts before serialization,
 * exactly as it does for events and projections, so patient_dots:none personas
 * never receive a patient ref.
 */
class DutyProjectionService
{
    private const FEET_TO_M = 0.3048;

    private const DUE_SOON_MINUTES = 120;

    /** Governed BFF action per duty kind. {id}/{uuid} are substituted per row. */
    private const ACTIONS = [
        'transport_run' => ['endpoint' => '/api/mobile/v1/transport/requests/{id}/status', 'method' => 'POST', 'label' => 'Update run'],
        'bed_turn' => ['endpoint' => '/api/mobile/v1/evs/requests/{id}/status', 'method' => 'POST', 'label' => 'Update turn'],
        'placement' => ['endpoint' => '/api/mobile/v1/rtdc/bed-requests/{id}/decision', 'method' => 'POST', 'label' => 'Place'],
        'barrier_resolve' => ['endpoint' => '/api/mobile/v1/rtdc/barriers/{id}/resolve', 'method' => 'POST', 'label' => 'Resolve'],
        'staffing_fill' => ['endpoint' => '/api/mobile/v1/staffing/requests/{id}/fill', 'method' => 'POST', 'label' => 'Fill'],
        'approval' => ['endpoint' => '/api/mobile/v1/ops/approvals/{uuid}/decision', 'method' => 'POST', 'label' => 'Decide'],
        // discharge_leverage / or_case_milestone have no write endpoint yet.
    ];

    /** @var array{unit: array<int, array>, bed: array<int, array>, label: array<string, array>}|null */
    private ?array $anchors = null;

    public function __construct(private readonly MobilePatientContextService $patients) {}

    /**
     * @param  list<string>  $dutyKinds  the caller's lens-granted kinds
     * @param  list<int>|null  $scopeUnitIds  null = house (no spatial filter); else keep duties in these units
     * @return list<array<string, mixed>>
     */
    public function duties(CarbonImmutable $now, array $dutyKinds, ?array $scopeUnitIds = null): array
    {
        $want = fn (string $kind): bool => in_array($kind, $dutyKinds, true);
        $out = [];

        if ($want('transport_run')) {
            foreach (TransportRequest::query()->where('is_deleted', false)->whereNotIn('status', ['completed', 'cancelled', 'handoff_complete'])->get() as $r) {
                $anchor = $this->anchorForLabel($r->destination) ?? $this->anchorForLabel($r->origin);
                $due = $r->needed_at ? CarbonImmutable::parse($r->needed_at) : null;
                $tier = $r->priority === 'stat' ? 'critical' : 'warning';
                $out[] = $this->duty('transport_run', 'transport-'.$r->transport_request_id, (string) $r->transport_request_id,
                    trim(($r->origin ?: '—').' → '.($r->destination ?: '—')), $anchor, $due, $tier, $now, $r->patient_ref);
            }
        }

        if ($want('bed_turn')) {
            foreach (EvsRequest::query()->where('is_deleted', false)->whereNotIn('status', ['completed', 'cancelled'])->get() as $r) {
                $anchor = $this->anchorForBed($r->bed_id) ?? $this->anchorForUnit($r->unit_id) ?? $this->anchorForLabel($r->location_label);
                $due = $r->needed_at ? CarbonImmutable::parse($r->needed_at) : null;
                $tier = $r->isolation_required ? 'warning' : 'info';
                $label = ($r->location_label ?: 'Bed turn').($r->isolation_required ? ' · isolation' : '');
                $out[] = $this->duty('bed_turn', 'evs-'.$r->evs_request_id, (string) $r->evs_request_id,
                    $label, $anchor, $due, $tier, $now, $r->patient_ref);
            }
        }

        if ($want('placement')) {
            foreach (BedRequest::pending()->orderBy('created_at')->get() as $r) {
                $due = $r->created_at ? CarbonImmutable::parse($r->created_at) : null; // age-based; no hard due
                $tier = ($r->acuity_tier !== null && $r->acuity_tier <= 1) ? 'critical' : (($r->acuity_tier !== null && $r->acuity_tier <= 2) ? 'warning' : 'info');
                $label = ($r->service ?: 'Unassigned').' · needs '.($r->required_unit_type ?: 'any');
                // A pending request has no assigned bed → unanchored (a house-level tray item) until placed.
                $out[] = $this->duty('placement', 'bedreq-'.$r->bed_request_id, (string) $r->bed_request_id,
                    $label, null, $due, $tier, $now, $r->patient_ref, dueless: true);
            }
        }

        if ($want('barrier_resolve')) {
            foreach (Barrier::open()->orderBy('opened_at')->get() as $b) {
                $anchor = $this->anchorForUnit($b->unit_id);
                $due = $b->opened_at ? CarbonImmutable::parse($b->opened_at) : null;
                $tier = in_array($b->category, ['placement', 'medical'], true) ? 'warning' : 'info';
                // Barrier free text is never surfaced — category only, no patient identity.
                $out[] = $this->duty('barrier_resolve', 'barrier-'.$b->barrier_id, (string) $b->barrier_id,
                    ucfirst($b->category ?: 'Discharge').' barrier', $anchor, $due, $tier, $now, null, dueless: true);
            }
        }

        if ($want('staffing_fill')) {
            foreach (StaffingRequest::query()->whereNotIn('status', ['filled', 'cancelled', 'closed'])->get() as $r) {
                $anchor = $this->anchorForUnit($r->unit_id);
                $due = $r->needed_by ? CarbonImmutable::parse($r->needed_by) : null;
                $tier = $r->priority === 'stat' ? 'critical' : ($r->priority === 'urgent' ? 'warning' : 'info');
                $label = ($r->unit_label ?: 'Unit').' · '.($r->role ?: 'staff').' fill';
                $out[] = $this->duty('staffing_fill', 'staffing-'.$r->staffing_request_id, (string) $r->staffing_request_id,
                    $label, $anchor, $due, $tier, $now, null);
            }
        }

        if ($want('approval')) {
            foreach (Approval::query()->where('status', 'pending')->with('action.recommendation')->get() as $a) {
                $rec = $a->action?->recommendation;
                $due = $a->requested_at ? CarbonImmutable::parse($a->requested_at) : null;
                $tier = match ($rec?->risk_level) {
                    'critical' => 'critical', 'high' => 'warning', default => 'info'
                };
                $out[] = $this->duty('approval', 'ops-approval-'.$a->approval_uuid, (string) $a->approval_uuid,
                    $rec?->title ?? 'Operational approval', null, $due, $tier, $now, null, dueless: true);
            }
        }

        if ($want('discharge_leverage')) {
            foreach (Encounter::query()->where('status', 'active')->whereNotNull('expected_discharge_date')
                ->whereDate('expected_discharge_date', '<=', $now->toDateString())->get() as $e) {
                $anchor = $this->anchorForBed($e->bed_id) ?? $this->anchorForUnit($e->unit_id);
                $due = CarbonImmutable::parse($e->expected_discharge_date);
                $out[] = $this->duty('discharge_leverage', 'edd-'.$e->encounter_id, (string) $e->encounter_id,
                    'Expected discharge', $anchor, $due, 'info', $now, $e->patient_ref);
            }
        }

        // Scope filter: house (null) keeps everything; floor/unit keeps only duties
        // anchored in the scoped units (unanchored house-level items drop out).
        if ($scopeUnitIds !== null) {
            $set = array_flip($scopeUnitIds);
            $out = array_values(array_filter($out, fn (array $d): bool => $d['unit_id'] !== null && isset($set[$d['unit_id']])));
        }

        return $out;
    }

    /**
     * @param  array{space_ref: ?string, unit_id: ?int, bed_id: ?int, centroid_m: ?array}|null  $anchor
     * @return array<string, mixed>
     */
    private function duty(string $kind, string $id, string $recordId, string $label, ?array $anchor,
        ?CarbonImmutable $due, string $tier, CarbonImmutable $now, ?string $patientRef, bool $dueless = false): array
    {
        $action = self::ACTIONS[$kind] ?? null;
        if ($action !== null) {
            $action['endpoint'] = str_replace(['{id}', '{uuid}'], $recordId, $action['endpoint']);
        }

        return [
            'id' => $id,
            'kind' => $kind,
            'label' => $label,
            'space_ref' => $anchor['space_ref'] ?? null,
            'unit_id' => $anchor['unit_id'] ?? null,
            'bed_id' => $anchor['bed_id'] ?? null,
            'centroid_m' => $anchor['centroid_m'] ?? null,
            'due_at' => $due?->toIso8601String(),
            'window_status' => $this->windowStatus($due, $tier, $now, $dueless),
            'tier' => $tier,
            '_patient_ref' => $patientRef,
            'patient_context_ref' => $patientRef ? $this->patients->contextRefFor($patientRef) : null,
            'provenance' => ['service' => 'duty_projection'],
            'action' => $action,
        ];
    }

    /** overdue = past due; due = within the next 2h (or dueless + urgent); else upcoming. */
    private function windowStatus(?CarbonImmutable $due, string $tier, CarbonImmutable $now, bool $dueless): ?string
    {
        if ($due === null) {
            return null;
        }
        if ($dueless) {
            // Age/urgency-based rather than a hard deadline (barriers, pending placements).
            return $tier === 'info' ? 'upcoming' : 'due';
        }

        return match (true) {
            $due->lt($now) => 'overdue',
            $due->lte($now->addMinutes(self::DUE_SOON_MINUTES)) => 'due',
            default => 'upcoming',
        };
    }

    // -----------------------------------------------------------------
    // Spatial anchoring — unit_id / bed_id / free-text → facility_space centroid
    // -----------------------------------------------------------------

    /** @return array{space_ref: string, unit_id: int, bed_id: null, centroid_m: array}|null */
    private function anchorForUnit(?int $unitId): ?array
    {
        return $unitId !== null ? ($this->anchors()['unit'][$unitId] ?? null) : null;
    }

    /** @return array{space_ref: string, unit_id: int, bed_id: int, centroid_m: array}|null */
    private function anchorForBed(?int $bedId): ?array
    {
        return $bedId !== null ? ($this->anchors()['bed'][$bedId] ?? null) : null;
    }

    private function anchorForLabel(?string $text): ?array
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        return $this->anchors()['label'][mb_strtolower(trim($text))] ?? null;
    }

    /** @return array{unit: array<int, array>, bed: array<int, array>, label: array<string, array>} */
    private function anchors(): array
    {
        if ($this->anchors !== null) {
            return $this->anchors;
        }

        $unit = [];
        $label = [];
        foreach (Unit::query()->whereNotNull('facility_space_id')->with('facilitySpace')->get() as $u) {
            $centroid = $this->centroid($u->facilitySpace);
            if ($centroid === null) {
                continue;
            }
            $anchor = ['space_ref' => $u->facilitySpace->space_code, 'unit_id' => (int) $u->unit_id, 'bed_id' => null, 'centroid_m' => $centroid];
            $unit[(int) $u->unit_id] = $anchor;
            foreach ([$u->name, $u->abbreviation] as $name) {
                if ($name) {
                    $label[mb_strtolower(trim($name))] = $anchor;
                }
            }
        }

        $bed = [];
        foreach (Bed::query()->where('is_deleted', false)->whereNotNull('facility_space_id')->with('facilitySpace')->get() as $b) {
            $centroid = $this->centroid($b->facilitySpace);
            if ($centroid === null) {
                continue;
            }
            $anchor = ['space_ref' => $b->facilitySpace->space_code, 'unit_id' => (int) $b->unit_id, 'bed_id' => (int) $b->bed_id, 'centroid_m' => $centroid];
            $bed[(int) $b->bed_id] = $anchor;
            if ($b->label) {
                $label[mb_strtolower(trim($b->label))] = $anchor;
            }
        }

        return $this->anchors = ['unit' => $unit, 'bed' => $bed, 'label' => $label];
    }

    /**
     * Center-origin CAD geometry (feet) → 3D centroid in metres {x, y, z}.
     * position_ft carries {x, level, z}; `level` is the vertical (floor) axis.
     *
     * @return array{x: float, y: float, z: float}|null
     */
    private function centroid(?FacilitySpace $space): ?array
    {
        $position = $space?->geometry['position_ft'] ?? null;
        if (! is_array($position) || ! isset($position['x'], $position['z'])) {
            return null;
        }

        return [
            'x' => round(((float) $position['x']) * self::FEET_TO_M, 3),
            'y' => round(((float) ($position['level'] ?? 0)) * self::FEET_TO_M, 3),
            'z' => round(((float) $position['z']) * self::FEET_TO_M, 3),
        ];
    }
}
