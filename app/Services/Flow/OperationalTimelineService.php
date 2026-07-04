<?php

namespace App\Services\Flow;

use App\Models\Barrier;
use App\Models\BedPlacementDecision;
use App\Models\BedRequest;
use App\Models\EdVisit;
use App\Models\Evs\EvsEvent;
use App\Models\OperationalEvent;
use App\Models\Staffing\StaffingEvent;
use App\Models\Transport\TransportEvent;
use App\Models\Unit;
use App\Services\Mobile\MobilePatientContextService;
use App\Support\Hospital\HospitalManifest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The review half of the Flow Window — FLOW-WINDOW-PLAN §6.2 (W2, G4).
 *
 * Merges the five per-domain event stores plus ED milestones, bed-request /
 * placement decisions, and barrier open/resolve into ONE normalized event
 * shape ordered by time:
 *
 *   { t, kind, entity{type, ref}, patient_context_ref?, from_space, to_space,
 *     unit_id, label, tier, provenance{source} }
 *
 * Kinds: admit, transfer, discharge, bed_status, acuity_changed, ed_arrival,
 * ed_admit_decision, bed_request, placement, transport_status, evs_status,
 * barrier_opened, barrier_resolved, staffing_fill, or_milestone.
 *
 * Patient refs are ALWAYS re-tokenized through MobilePatientContextService
 * before serialization (the same rule the ops ledger follows); raw
 * patient_ref never leaves this service. The ops.operational_events ledger
 * is deliberately NOT merged: every ledger entry mirrors a domain-table
 * transition already represented here, and double-sourcing would render
 * every mobile write twice on the timeline.
 *
 * Tier follows earned urgency: 'info' unless a stat/priority signal says
 * 'warning'. Coral-tier ('critical') is reserved for real breaches, which
 * the review half does not fabricate.
 */
class OperationalTimelineService
{
    /** @var array<int, string> */
    private array $unitLabels = [];

    /** @var array<int, int> */
    private array $unitFloors = [];

    public function __construct(
        private readonly MobilePatientContextService $patientContext,
        private readonly HospitalManifest $manifest,
    ) {}

    /**
     * Normalized events in [$from, $to] for a resolved scope, filtered to
     * $kinds, ordered ascending by t. $limit caps the payload (server cap).
     *
     * @param  array{type: string, floor: ?int, unit_id: ?int, patient_ref: ?string}  $scope
     * @param  list<string>  $kinds
     * @return list<array<string, mixed>>
     */
    public function events(CarbonImmutable $from, CarbonImmutable $to, array $scope, array $kinds, int $limit = 5000): array
    {
        $this->primeUnitIndex();

        $streams = collect()
            ->concat($this->censusEvents($from, $to, $kinds))
            ->concat($this->edEvents($from, $to, $kinds))
            ->concat($this->bedRequestEvents($from, $to, $kinds))
            ->concat($this->transportEvents($from, $to, $kinds))
            ->concat($this->evsEvents($from, $to, $kinds))
            ->concat($this->staffingEvents($from, $to, $kinds))
            ->concat($this->barrierEvents($from, $to, $kinds))
            ->concat($this->orEvents($from, $to, $kinds));

        return $streams
            ->filter(fn (array $event): bool => $this->inScope($event, $scope))
            ->sortBy('t')
            ->take($limit)
            ->values()
            ->all();
    }

    // -------------------------------------------------------------------
    // prod.operational_events → admit / transfer / discharge / bed_status /
    // acuity_changed
    // -------------------------------------------------------------------

    /** @return Collection<int, array<string, mixed>> */
    private function censusEvents(CarbonImmutable $from, CarbonImmutable $to, array $kinds): Collection
    {
        $kindByType = [
            'EncounterStarted' => 'admit',
            'EncounterTransferred' => 'transfer',
            'EncounterDischarged' => 'discharge',
            'BedStatusChanged' => 'bed_status',
            'AcuityChanged' => 'acuity_changed',
        ];
        $wanted = array_keys(array_intersect($kindByType, $kinds));
        if ($wanted === []) {
            return collect();
        }

        return OperationalEvent::query()
            ->whereIn('type', $wanted)
            ->whereBetween('occurred_at', [$from, $to])
            ->orderBy('occurred_at')
            ->limit(20000)
            ->get()
            ->map(function (OperationalEvent $event) use ($kindByType): array {
                $kind = $kindByType[$event->type];
                $payload = $event->payload ?? [];
                $unitId = $payload['unit_id'] ?? $payload['to_unit_id'] ?? null;
                $unitId = $unitId !== null ? (int) $unitId : null;

                return $this->normalized(
                    t: $event->occurred_at,
                    kind: $kind,
                    entity: $kind === 'bed_status'
                        ? ['type' => 'bed', 'ref' => (string) ($payload['bed_id'] ?? '')]
                        : ['type' => 'patient', 'ref' => null],
                    patientRef: $event->encounter_ref,
                    fromSpace: null,
                    toSpace: $unitId !== null ? $this->unitLabel($unitId) : null,
                    unitId: $unitId,
                    label: match ($kind) {
                        'admit' => 'Admitted to '.($this->unitLabel($unitId) ?? 'unit'),
                        'transfer' => 'Transferred to '.($this->unitLabel($unitId) ?? 'unit'),
                        'discharge' => 'Discharged',
                        'bed_status' => 'Bed → '.($payload['status'] ?? 'changed'),
                        'acuity_changed' => 'Acuity → tier '.($payload['acuity_tier'] ?? '?'),
                        default => $kind,
                    },
                    tier: 'info',
                    source: 'prod.operational_events',
                );
            });
    }

    // -------------------------------------------------------------------
    // prod.ed_visits → ed_arrival / ed_admit_decision
    // -------------------------------------------------------------------

    /** @return Collection<int, array<string, mixed>> */
    private function edEvents(CarbonImmutable $from, CarbonImmutable $to, array $kinds): Collection
    {
        $wantArrival = in_array('ed_arrival', $kinds, true);
        $wantDecision = in_array('ed_admit_decision', $kinds, true);
        if (! $wantArrival && ! $wantDecision) {
            return collect();
        }

        $events = collect();
        $visits = EdVisit::query()
            ->where('is_deleted', false)
            ->where(function ($query) use ($from, $to): void {
                $query->whereBetween('arrived_at', [$from, $to])
                    ->orWhereBetween('admit_decision_at', [$from, $to]);
            })
            ->limit(20000)
            ->get();

        foreach ($visits as $visit) {
            $unitId = $visit->unit_id !== null ? (int) $visit->unit_id : null;
            if ($wantArrival && $visit->arrived_at?->betweenIncluded($from, $to)) {
                $events->push($this->normalized(
                    t: $visit->arrived_at,
                    kind: 'ed_arrival',
                    entity: ['type' => 'patient', 'ref' => null],
                    patientRef: $visit->patient_ref,
                    fromSpace: null,
                    toSpace: 'ED',
                    unitId: $unitId,
                    label: 'ED arrival'.($visit->esi_level ? " · ESI {$visit->esi_level}" : ''),
                    tier: $visit->esi_level !== null && $visit->esi_level <= 2 ? 'warning' : 'info',
                    source: 'prod.ed_visits',
                ));
            }
            if ($wantDecision && $visit->admit_decision_at?->betweenIncluded($from, $to)) {
                $events->push($this->normalized(
                    t: $visit->admit_decision_at,
                    kind: 'ed_admit_decision',
                    entity: ['type' => 'patient', 'ref' => null],
                    patientRef: $visit->patient_ref,
                    fromSpace: 'ED',
                    toSpace: null,
                    unitId: $unitId,
                    label: 'Decision to admit',
                    tier: $visit->bed_assigned_at === null ? 'warning' : 'info', // boarding until a bed exists
                    source: 'prod.ed_visits',
                ));
            }
        }

        return $events;
    }

    // -------------------------------------------------------------------
    // prod.bed_requests + prod.bed_placement_decisions → bed_request / placement
    // -------------------------------------------------------------------

    /** @return Collection<int, array<string, mixed>> */
    private function bedRequestEvents(CarbonImmutable $from, CarbonImmutable $to, array $kinds): Collection
    {
        $events = collect();

        if (in_array('bed_request', $kinds, true)) {
            $events = $events->concat(BedRequest::query()
                ->where('is_deleted', false)
                ->whereBetween('created_at', [$from, $to])
                ->limit(10000)
                ->get()
                ->map(fn (BedRequest $request): array => $this->normalized(
                    t: $request->created_at,
                    kind: 'bed_request',
                    entity: ['type' => 'bed_request', 'ref' => (string) $request->bed_request_id],
                    patientRef: $request->patient_ref,
                    fromSpace: $request->source,
                    toSpace: $request->required_unit_type,
                    unitId: null,
                    label: 'Bed requested · '.($request->service ?? $request->required_unit_type ?? 'placement'),
                    tier: $request->acuity_tier !== null && $request->acuity_tier <= 2 ? 'warning' : 'info',
                    source: 'prod.bed_requests',
                )));
        }

        if (in_array('placement', $kinds, true)) {
            $events = $events->concat(BedPlacementDecision::query()
                ->with('bedRequest')
                ->whereBetween('created_at', [$from, $to])
                ->limit(10000)
                ->get()
                ->map(function (BedPlacementDecision $decision): array {
                    $bed = $decision->chosen_bed_id !== null
                        ? \App\Models\Bed::find($decision->chosen_bed_id)
                        : null;

                    return $this->normalized(
                        t: $decision->created_at,
                        kind: 'placement',
                        entity: ['type' => 'bed_request', 'ref' => (string) $decision->bed_request_id],
                        patientRef: $decision->bedRequest?->patient_ref,
                        fromSpace: $decision->bedRequest?->source,
                        toSpace: $bed?->label,
                        unitId: $bed?->unit_id !== null ? (int) $bed->unit_id : null,
                        label: $decision->action === 'place'
                            ? 'Placed → '.($bed?->label ?? 'bed')
                            : ucfirst((string) $decision->action),
                        tier: 'info',
                        source: 'prod.bed_placement_decisions',
                    );
                }));
        }

        return $events;
    }

    // -------------------------------------------------------------------
    // prod.transport_events / prod.evs_events / prod.staffing_events
    // -------------------------------------------------------------------

    /** @return Collection<int, array<string, mixed>> */
    private function transportEvents(CarbonImmutable $from, CarbonImmutable $to, array $kinds): Collection
    {
        if (! in_array('transport_status', $kinds, true)) {
            return collect();
        }

        return TransportEvent::query()
            ->with('request')
            ->whereBetween('occurred_at', [$from, $to])
            ->limit(20000)
            ->get()
            ->map(fn (TransportEvent $event): array => $this->normalized(
                t: $event->occurred_at,
                kind: 'transport_status',
                entity: ['type' => 'transport', 'ref' => (string) $event->transport_request_id],
                patientRef: $event->request?->patient_ref,
                fromSpace: $event->request?->origin,
                toSpace: $event->request?->destination,
                unitId: null,
                label: 'Transport '.str_replace('_', ' ', (string) ($event->to_status ?? $event->event_type)),
                tier: ($event->request?->priority ?? 'routine') === 'stat' ? 'warning' : 'info',
                source: 'prod.transport_events',
            ));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function evsEvents(CarbonImmutable $from, CarbonImmutable $to, array $kinds): Collection
    {
        if (! in_array('evs_status', $kinds, true)) {
            return collect();
        }

        return EvsEvent::query()
            ->with('request')
            ->whereBetween('occurred_at', [$from, $to])
            ->limit(20000)
            ->get()
            ->map(fn (EvsEvent $event): array => $this->normalized(
                t: $event->occurred_at,
                kind: 'evs_status',
                entity: ['type' => 'evs', 'ref' => (string) $event->evs_request_id],
                patientRef: $event->request?->patient_ref,
                fromSpace: null,
                toSpace: $event->request?->location_label,
                unitId: $event->request?->unit_id !== null ? (int) $event->request->unit_id : null,
                label: 'Turn '.str_replace('_', ' ', (string) ($event->to_status ?? $event->event_type)),
                tier: ($event->request?->priority ?? 'routine') === 'stat' ? 'warning' : 'info',
                source: 'prod.evs_events',
            ));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function staffingEvents(CarbonImmutable $from, CarbonImmutable $to, array $kinds): Collection
    {
        if (! in_array('staffing_fill', $kinds, true)) {
            return collect();
        }

        return StaffingEvent::query()
            ->with('request')
            ->whereBetween('occurred_at', [$from, $to])
            ->limit(10000)
            ->get()
            ->map(fn (StaffingEvent $event): array => $this->normalized(
                t: $event->occurred_at,
                kind: 'staffing_fill',
                entity: ['type' => 'staffing', 'ref' => (string) $event->staffing_request_id],
                patientRef: null,
                fromSpace: null,
                toSpace: $event->request?->unit_label,
                unitId: $event->request?->unit_id !== null ? (int) $event->request->unit_id : null,
                label: ucfirst((string) ($event->request?->role ?? 'staff')).' '.str_replace('_', ' ', (string) ($event->to_status ?? $event->event_type)),
                tier: ($event->request?->priority ?? 'routine') === 'stat' ? 'warning' : 'info',
                source: 'prod.staffing_events',
            ));
    }

    // -------------------------------------------------------------------
    // prod.barriers → barrier_opened / barrier_resolved
    // -------------------------------------------------------------------

    /** @return Collection<int, array<string, mixed>> */
    private function barrierEvents(CarbonImmutable $from, CarbonImmutable $to, array $kinds): Collection
    {
        $wantOpen = in_array('barrier_opened', $kinds, true);
        $wantResolve = in_array('barrier_resolved', $kinds, true);
        if (! $wantOpen && ! $wantResolve) {
            return collect();
        }

        $events = collect();
        $barriers = Barrier::query()
            ->where('is_deleted', false)
            ->where(function ($query) use ($from, $to): void {
                $query->whereBetween('opened_at', [$from, $to])
                    ->orWhereBetween('resolved_at', [$from, $to]);
            })
            ->limit(10000)
            ->get();

        foreach ($barriers as $barrier) {
            $unitId = $barrier->unit_id !== null ? (int) $barrier->unit_id : null;
            if ($wantOpen && $barrier->opened_at?->betweenIncluded($from, $to)) {
                $events->push($this->normalized(
                    t: $barrier->opened_at,
                    kind: 'barrier_opened',
                    entity: ['type' => 'barrier', 'ref' => (string) $barrier->barrier_id],
                    patientRef: null,
                    fromSpace: null,
                    toSpace: $this->unitLabel($unitId),
                    unitId: $unitId,
                    label: 'Barrier · '.($barrier->description ?? $barrier->category ?? 'opened'),
                    tier: 'warning',
                    source: 'prod.barriers',
                ));
            }
            if ($wantResolve && $barrier->resolved_at?->betweenIncluded($from, $to)) {
                $events->push($this->normalized(
                    t: $barrier->resolved_at,
                    kind: 'barrier_resolved',
                    entity: ['type' => 'barrier', 'ref' => (string) $barrier->barrier_id],
                    patientRef: null,
                    fromSpace: null,
                    toSpace: $this->unitLabel($unitId),
                    unitId: $unitId,
                    label: 'Barrier resolved',
                    tier: 'info',
                    source: 'prod.barriers',
                ));
            }
        }

        return $events;
    }

    // -------------------------------------------------------------------
    // prod.orlog → or_milestone
    // -------------------------------------------------------------------

    /** @return Collection<int, array<string, mixed>> */
    private function orEvents(CarbonImmutable $from, CarbonImmutable $to, array $kinds): Collection
    {
        $table = $this->orlogTable();
        if (! in_array('or_milestone', $kinds, true) || $table === null) {
            return collect(); // periop import is optional; degrade to an empty lane
        }

        $milestones = [
            'or_in_time' => 'In room',
            'procedure_start_time' => 'Procedure start',
            'procedure_end_time' => 'Procedure end',
            'or_out_time' => 'Out of room',
            'pacu_in_time' => 'PACU in',
            'pacu_out_time' => 'PACU out',
        ];

        $events = collect();
        $logs = \App\Models\ORLog::query()
            ->from($table)
            ->with('case.room') // ORLog→case→room: OR-side milestones land in a named room
            ->where('is_deleted', false)
            ->whereBetween('tracking_date', [$from->toDateString(), $to->toDateString()])
            ->limit(5000)
            ->get();

        foreach ($logs as $log) {
            $room = $log->case?->room?->name;
            foreach ($milestones as $column => $label) {
                $at = $this->orMilestoneAt($log, $column);
                if ($at === null || ! $at->betweenIncluded($from, $to)) {
                    continue;
                }
                $events->push($this->normalized(
                    t: $at,
                    kind: 'or_milestone',
                    entity: ['type' => 'or_case', 'ref' => (string) $log->case_id],
                    patientRef: null,
                    fromSpace: null,
                    toSpace: str_starts_with($column, 'pacu') ? 'PACU' : ($room ?? 'OR'),
                    unitId: null,
                    label: $label.($log->primary_procedure ? ' · '.$log->primary_procedure : ''),
                    tier: 'info',
                    source: $table,
                ));
            }
        }

        return $events;
    }

    /**
     * Legacy ETL deployments carry the milestone log as prod.orlog; the
     * migration path (and CommandCenterDemoSeeder + every periop SQL surface)
     * uses prod.or_logs. Read whichever exists so or_milestone works on both.
     */
    private function orlogTable(): ?string
    {
        foreach (['prod.orlog', 'prod.or_logs'] as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                return $table;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------
    // Shared plumbing
    // -------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function normalized(
        mixed $t,
        string $kind,
        array $entity,
        ?string $patientRef,
        ?string $fromSpace,
        ?string $toSpace,
        ?int $unitId,
        string $label,
        string $tier,
        string $source,
    ): array {
        // The ONLY place a patient becomes visible: as an opaque context ref.
        $ptok = $patientRef !== null ? $this->patientContext->contextRefFor($patientRef) : null;
        if (($entity['type'] ?? null) === 'patient') {
            $entity['ref'] = $ptok;
        }

        return [
            't' => CarbonImmutable::parse($t)->toIso8601String(),
            'kind' => $kind,
            'entity' => $entity,
            'patient_context_ref' => $ptok,
            // Internal only — stripped by the controller before serialization,
            // used for task/unit patient-depth decisions.
            '_patient_ref' => $patientRef,
            'from_space' => $fromSpace,
            'to_space' => $toSpace,
            'unit_id' => $unitId,
            'label' => $label,
            'tier' => $tier,
            'provenance' => ['source' => $source],
        ];
    }

    /** @param array{type: string, floor: ?int, unit_id: ?int, patient_ref: ?string} $scope */
    private function inScope(array $event, array $scope): bool
    {
        return match ($scope['type']) {
            'house' => true,
            'floor' => $event['unit_id'] !== null
                && ($this->unitFloors[$event['unit_id']] ?? null) === $scope['floor'],
            'unit' => $event['unit_id'] === $scope['unit_id'],
            'patient' => $event['_patient_ref'] !== null && $event['_patient_ref'] === $scope['patient_ref'],
            default => false,
        };
    }

    private function primeUnitIndex(): void
    {
        if ($this->unitLabels !== []) {
            return;
        }

        foreach (Unit::where('is_deleted', false)->get(['unit_id', 'abbreviation', 'name']) as $unit) {
            $this->unitLabels[(int) $unit->unit_id] = (string) ($unit->abbreviation ?? $unit->name);
            $entry = $unit->abbreviation ? $this->manifest->unit($unit->abbreviation) : null;
            if (isset($entry['floor'])) {
                $this->unitFloors[(int) $unit->unit_id] = (int) $entry['floor'];
            }
        }
    }

    private function unitLabel(?int $unitId): ?string
    {
        return $unitId !== null ? ($this->unitLabels[$unitId] ?? null) : null;
    }

    private function orMilestoneAt(\App\Models\ORLog $log, string $column): ?CarbonImmutable
    {
        $value = $log->{$column};
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $raw = (string) $value;

            // Time-only milestones ride on tracking_date.
            if (preg_match('/^\d{1,2}:\d{2}/', $raw) === 1) {
                return CarbonImmutable::parse($log->tracking_date.' '.$raw);
            }

            return CarbonImmutable::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
