<?php

namespace App\Services\PatientFlow;

use App\Models\Barrier;
use App\Models\BedRequest;
use App\Models\EdVisit;
use App\Models\Encounter;
use App\Models\Evs\EvsRequest;
use App\Models\Transport\TransportRequest;
use App\Services\Flow\FlowLensService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One patient's ordered operational story — the journey spine behind the 4D
 * Navigator's Patient Journey Drawer (FLOW-4D-PATIENT-JOURNEY-AND-CONFORMANCE-
 * PLAN §7.1 / §8 Phase A1, closing finding PJ-1).
 *
 * Authorization is NOT this service's job: the endpoint requires
 * `scope=patient:{ptok_…}`, and FlowLensService::patientScope() has already
 * run the full A2P authorization + disclosure audit (it delegates to
 * MobilePatientContextService::build()) before the controller executes. This
 * service consumes the resolved scope's patient_ref and applies the SAME
 * identity discipline every other flow surface uses: every flow-event row
 * passes through FlowLensService::redactRow(), and every hand-built row
 * carries opaque context only — no raw patient/encounter refs, ever.
 *
 * A2P grant supersedes unit-scope row filtering by design (the precedent is
 * MobilePatientContextService::build(), which returns the full cross-unit
 * timeline once the per-patient gate passes): a journey inherently spans
 * units, so rows are redacted for identity, never dropped for scope.
 *
 * Ancillary milestones are deliberately absent from v1 — they are order-linked
 * (prod.ancillary_milestones → ancillary_orders), and the patient's order /
 * observation flow events already carry that story at journey granularity.
 */
class PatientJourneyService
{
    private const DEFAULT_LOOKBACK_HOURS = 72;

    private const DEFAULT_LOOKAHEAD_HOURS = 24;

    private const MAX_EVENTS = 5000;

    public function __construct(
        private readonly FlowEventRepository $events,
        private readonly FlowLensService $lens,
        private readonly FlowEpochService $epoch,
    ) {}

    /**
     * @param  array{lens: array<string, mixed>, scope: array<string, mixed>, depth: string, task_refs: list<string>, visible_unit_ids: list<int>}  $context
     * @return array<string, mixed>
     */
    public function build(array $context, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $scope = $context['scope'];
        $patientRef = (string) $scope['patient_ref'];
        $contextRef = (string) $scope['patient_context_ref'];

        $now = CarbonImmutable::now();
        $encounter = $this->activeEncounter($patientRef);

        $from ??= $encounter?->admitted_at
            ? CarbonImmutable::parse((string) $encounter->admitted_at)->subHours(6)
            : $now->subHours(self::DEFAULT_LOOKBACK_HOURS);
        $to ??= $now->addHours(self::DEFAULT_LOOKAHEAD_HOURS);

        $events = $this->journeyEvents($context, $patientRef, $from, $to);
        $segments = $this->locationSegments($events, $now);

        $edVisits = EdVisit::query()
            ->where('patient_ref', $patientRef)
            ->where('is_deleted', false)
            ->orderByDesc('ed_visit_id')
            ->get();

        $transport = TransportRequest::query()
            ->where('patient_ref', $patientRef)
            ->where('is_deleted', false)
            ->orderByDesc('transport_request_id')
            ->get();

        $bedRequests = BedRequest::query()
            ->where('patient_ref', $patientRef)
            ->where('is_deleted', false)
            ->orderByDesc('bed_request_id')
            ->get();

        $evs = EvsRequest::query()
            ->where('patient_ref', $patientRef)
            ->where('is_deleted', false)
            ->orderByDesc('evs_request_id')
            ->get();

        return [
            'patient' => [
                'patient_context_ref' => $contextRef,
                // Mirrors FlowLensService::redactRow()'s display derivation —
                // a stable, identity-free label the drawer can title itself with.
                'display_label' => 'Patient '.strtoupper(substr($contextRef, -6)),
                'detail_authorized' => true,
                'phi_minimized' => true,
            ],
            'lens' => [
                'role_id' => (string) ($context['lens']['role_id'] ?? ''),
                'patient_dots' => $context['depth'],
            ],
            'window' => ['from' => $from->toJSON(), 'to' => $to->toJSON()],
            'header' => $this->header($events, $encounter, $now),
            'events' => $events,
            'segments' => $segments,
            'phases' => [
                'ed' => $this->edPhases($edVisits),
                'periop' => $this->periopPhases($patientRef),
            ],
            'milestones' => $this->caseMilestones($patientRef),
            'logistics' => $this->logistics($transport, $bedRequests, $evs),
            'home' => $this->homeEpisodes($patientRef),
            'unit_context_barriers' => $this->openBarriersOnStayUnits($events),
            'next' => $this->next($encounter, $transport, $bedRequests),
            'epoch' => $this->epoch->current(),
            'as_of' => $now->toJSON(),
            'generated_at' => $now->toJSON(),
        ];
    }

    private function activeEncounter(string $patientRef): ?Encounter
    {
        return Encounter::query()
            ->where('patient_ref', $patientRef)
            ->where('status', 'active')
            ->where('is_deleted', false)
            ->orderByDesc('encounter_id')
            ->with(['unit', 'bed'])
            ->first();
    }

    /**
     * The patient's flow events, redacted to opaque context and compacted to
     * drawer granularity. Rows keep chronological order (FlowEventRepository
     * restores it after the newest-first SQL limit).
     *
     * @param  array{lens: array<string, mixed>, scope: array<string, mixed>, depth: string, task_refs: list<string>, visible_unit_ids: list<int>}  $context
     * @return list<array<string, mixed>>
     */
    private function journeyEvents(array $context, string $patientRef, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->events->serializeEvents($this->events->filteredEvents([
            'patient' => $patientRef,
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
            'limit' => self::MAX_EVENTS,
        ]));

        $compact = [];
        foreach ($rows as $row) {
            $redacted = $this->lens->redactRow(
                $row,
                $context['depth'],
                $context['scope'],
                $context['task_refs'],
                $context['visible_unit_ids'],
            );

            $metadata = is_array($redacted['metadata'] ?? null) ? $redacted['metadata'] : [];

            $compact[] = [
                'event_id' => $redacted['event_id'] ?? null,
                'event_category' => $redacted['event_category'] ?? null,
                'event_type' => $redacted['event_type'] ?? null,
                'trigger_event' => $redacted['trigger_event'] ?? null,
                'activity' => is_string($metadata['activity'] ?? null) ? $metadata['activity'] : null,
                'occurred_at' => $redacted['occurred_at'] ?? null,
                'from_location' => $redacted['from_location'] ?? null,
                'to_location' => $redacted['to_location'] ?? null,
                'location_name' => $redacted['location_name'] ?? null,
                'location_floor' => $redacted['location_floor'] ?? null,
                'unit_code' => $redacted['unit_code'] ?? null,
                'unit_id' => $redacted['unit_id'] ?? null,
                'bed' => $redacted['bed'] ?? null,
                'service_line' => $redacted['service_line'] ?? null,
                'patient_class' => $redacted['patient_class'] ?? null,
                'code_counts' => [
                    'diagnosis' => count((array) ($redacted['diagnosis_codes'] ?? [])),
                    'order' => count((array) ($redacted['order_codes'] ?? [])),
                    'observation' => count((array) ($redacted['observation_codes'] ?? [])),
                    'medication' => count((array) ($redacted['medication_codes'] ?? [])),
                ],
            ];
        }

        return $compact;
    }

    /**
     * Fold movement events into location dwell segments — the interval framing
     * (finding PJ-2) that makes boarding/stagnation a first-class object
     * instead of an inference. The trailing segment stays open (ended_at null)
     * unless a discharge event closes it.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function locationSegments(array $events, CarbonImmutable $now): array
    {
        $segments = [];
        $open = null;

        foreach ($events as $event) {
            if (($event['event_category'] ?? null) !== 'movement' || empty($event['occurred_at'])) {
                continue;
            }

            $at = CarbonImmutable::parse((string) $event['occurred_at']);
            $isDischarge = strtoupper((string) ($event['trigger_event'] ?? '')) === 'A03'
                || str_contains(strtoupper((string) ($event['event_type'] ?? '')), 'DISCHARGE');

            if ($open !== null) {
                $open['ended_at'] = $at->toJSON();
                $open['dwell_minutes'] = max(0, (int) round(CarbonImmutable::parse((string) $open['started_at'])->diffInMinutes($at)));
                $open['open'] = false;
                $segments[] = $open;
                $open = null;
            }

            if ($isDischarge) {
                continue;
            }

            if (! empty($event['to_location'])) {
                $open = [
                    'kind' => 'location',
                    'location' => $event['to_location'],
                    'location_name' => $event['location_name'] ?? null,
                    'floor' => $event['location_floor'] ?? null,
                    'unit_code' => $event['unit_code'] ?? null,
                    'unit_id' => $event['unit_id'] ?? null,
                    'started_at' => $at->toJSON(),
                    'ended_at' => null,
                    'dwell_minutes' => null,
                    'open' => true,
                ];
            }
        }

        if ($open !== null) {
            $open['dwell_minutes'] = max(0, (int) round(CarbonImmutable::parse((string) $open['started_at'])->diffInMinutes($now)));
            $segments[] = $open;
        }

        return $segments;
    }

    /** @return array<string, mixed> */
    private function header(array $events, ?Encounter $encounter, CarbonImmutable $now): array
    {
        $lastLocated = null;
        foreach (array_reverse($events) as $event) {
            if (! empty($event['to_location'])) {
                $lastLocated = $event;
                break;
            }
        }

        $admittedAt = $encounter?->admitted_at
            ? CarbonImmutable::parse((string) $encounter->admitted_at)
            : null;

        return [
            'current_location' => $lastLocated['to_location'] ?? null,
            'current_location_name' => $lastLocated['location_name'] ?? null,
            'current_unit_code' => $lastLocated['unit_code'] ?? null,
            'current_floor' => $lastLocated['location_floor'] ?? null,
            'service_line' => $lastLocated['service_line'] ?? null,
            'patient_class' => $lastLocated['patient_class'] ?? null,
            'admitted_at' => $admittedAt?->toJSON(),
            'los_minutes' => $admittedAt !== null ? max(0, (int) round($admittedAt->diffInMinutes($now))) : null,
            'as_of' => $now->toJSON(),
        ];
    }

    /**
     * ED dwell phases from the visit row's timestamp spine. Boarding —
     * admit-decision to bed-assignment (or departure) — is the interval the
     * whole flow discipline exists to shorten; it gets first-class framing.
     *
     * @param  Collection<int, EdVisit>  $edVisits
     * @return list<array<string, mixed>>
     */
    private function edPhases($edVisits): array
    {
        $phases = [];

        foreach ($edVisits as $visit) {
            $spine = [
                ['arrival_to_triage', $visit->arrived_at, $visit->triaged_at],
                ['triage_to_provider', $visit->triaged_at, $visit->provider_seen_at],
                ['provider_to_decision', $visit->provider_seen_at, $visit->admit_decision_at],
                ['boarding', $visit->admit_decision_at, $visit->bed_assigned_at ?? $visit->departed_at],
            ];

            foreach ($spine as [$phase, $start, $end]) {
                if ($start === null) {
                    continue;
                }

                $startAt = CarbonImmutable::parse((string) $start);
                $endAt = $end !== null ? CarbonImmutable::parse((string) $end) : null;

                $phases[] = [
                    'phase' => $phase,
                    'started_at' => $startAt->toJSON(),
                    'ended_at' => $endAt?->toJSON(),
                    'minutes' => $endAt !== null ? max(0, (int) round($startAt->diffInMinutes($endAt))) : null,
                    'open' => $endAt === null,
                ];
            }
        }

        return $phases;
    }

    /**
     * Perioperative phases from prod.or_logs actuals (preop → OR → PACU).
     *
     * @return list<array<string, mixed>>
     */
    private function periopPhases(string $patientRef): array
    {
        if (! Schema::hasTable('prod.or_logs')) {
            return [];
        }

        $logs = DB::table('prod.or_logs as l')
            ->join('prod.or_cases as c', 'c.case_id', '=', 'l.case_id')
            ->where('c.patient_id', $patientRef)
            ->where('l.is_deleted', false)
            ->where('c.is_deleted', false)
            ->orderBy('l.log_id')
            ->get(['l.case_id', 'l.preop_in_time', 'l.preop_out_time', 'l.or_in_time', 'l.or_out_time', 'l.pacu_in_time', 'l.pacu_out_time']);

        $phases = [];
        foreach ($logs as $log) {
            foreach ([
                ['preop', $log->preop_in_time, $log->preop_out_time],
                ['or', $log->or_in_time, $log->or_out_time],
                ['pacu', $log->pacu_in_time, $log->pacu_out_time],
            ] as [$phase, $start, $end]) {
                if ($start === null) {
                    continue;
                }

                $startAt = CarbonImmutable::parse((string) $start);
                $endAt = $end !== null ? CarbonImmutable::parse((string) $end) : null;

                $phases[] = [
                    'case_id' => (int) $log->case_id,
                    'phase' => $phase,
                    'started_at' => $startAt->toJSON(),
                    'ended_at' => $endAt?->toJSON(),
                    'minutes' => $endAt !== null ? max(0, (int) round($startAt->diffInMinutes($endAt))) : null,
                    'open' => $endAt === null,
                ];
            }
        }

        return $phases;
    }

    /** @return list<array<string, mixed>> */
    private function caseMilestones(string $patientRef): array
    {
        if (! Schema::hasTable('prod.care_journey_milestones')) {
            return [];
        }

        return DB::table('prod.care_journey_milestones as m')
            ->join('prod.or_cases as c', 'c.case_id', '=', 'm.case_id')
            ->where('c.patient_id', $patientRef)
            ->where('c.is_deleted', false)
            ->orderBy('m.completed_at')
            ->orderBy('m.id')
            ->get(['m.case_id', 'm.milestone_type', 'm.status', 'm.required', 'm.completed_at'])
            ->map(fn (object $row): array => [
                'source' => 'or_case',
                'case_id' => (int) $row->case_id,
                'milestone_type' => (string) $row->milestone_type,
                'status' => (string) $row->status,
                'required' => (bool) $row->required,
                'completed_at' => $row->completed_at
                    ? CarbonImmutable::parse((string) $row->completed_at)->toJSON()
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Transport / bed-request / EVS context rows — hand-built, opaque-only
     * (no patient/encounter refs survive into the payload).
     *
     * @param  Collection<int, TransportRequest>  $transport
     * @param  Collection<int, BedRequest>  $bedRequests
     * @param  Collection<int, EvsRequest>  $evs
     * @return list<array<string, mixed>>
     */
    private function logistics($transport, $bedRequests, $evs): array
    {
        $rows = [];

        foreach ($transport as $request) {
            $rows[] = [
                'domain' => 'transport',
                'status' => (string) $request->status,
                'priority' => $request->priority,
                'origin' => $request->origin,
                'destination' => $request->destination,
                'requested_at' => $request->requested_at?->toJSON(),
                'needed_at' => $request->needed_at?->toJSON(),
                'completed_at' => $request->completed_at?->toJSON(),
            ];
        }

        foreach ($bedRequests as $request) {
            $rows[] = [
                'domain' => 'bed_request',
                'status' => (string) $request->status,
                'service' => $request->service,
                'required_unit_type' => $request->required_unit_type,
                'isolation_required' => (bool) $request->isolation_required,
                'requested_at' => $request->created_at?->toJSON(),
            ];
        }

        foreach ($evs as $request) {
            $rows[] = [
                'domain' => 'evs',
                'status' => (string) $request->status,
                'location_label' => $request->location_label,
                'turn_type' => $request->turn_type,
                'requested_at' => $request->requested_at?->toJSON(),
                'completed_at' => $request->completed_at?->toJSON(),
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp(
            (string) ($a['requested_at'] ?? ''),
            (string) ($b['requested_at'] ?? ''),
        ));

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function homeEpisodes(string $patientRef): array
    {
        if (! Schema::hasTable('prod.home_episodes')) {
            return [];
        }

        return DB::table('prod.home_episodes')
            ->where('patient_ref', $patientRef)
            ->where('is_deleted', false)
            ->orderBy('started_at')
            ->get(['status', 'disposition', 'condition_label', 'acuity_tier', 'service_zone', 'started_at', 'ended_at', 'expected_discharge_date'])
            ->map(fn (object $row): array => [
                'domain' => 'home_hospital',
                'status' => (string) $row->status,
                'disposition' => $row->disposition,
                'condition_label' => $row->condition_label,
                'acuity_tier' => $row->acuity_tier,
                'service_zone' => $row->service_zone,
                'started_at' => $row->started_at ? CarbonImmutable::parse((string) $row->started_at)->toJSON() : null,
                'ended_at' => $row->ended_at ? CarbonImmutable::parse((string) $row->ended_at)->toJSON() : null,
                'expected_discharge_date' => $row->expected_discharge_date,
            ])
            ->values()
            ->all();
    }

    /**
     * Open barriers on the units this journey touches. Barriers carry no
     * patient linkage (PatientFlowController::barriers notes population is a
     * follow-up), so these are UNIT-level context, never patient-attributed —
     * the `attribution` field says so explicitly.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function openBarriersOnStayUnits(array $events): array
    {
        $unitIds = array_values(array_unique(array_filter(array_map(
            fn (array $event): ?int => isset($event['unit_id']) && is_numeric($event['unit_id'])
                ? (int) $event['unit_id']
                : null,
            $events,
        ), fn (?int $id): bool => $id !== null)));

        if ($unitIds === []) {
            return [];
        }

        return Barrier::query()
            ->open()
            ->whereIn('unit_id', $unitIds)
            ->orderBy('opened_at')
            ->get()
            ->map(fn (Barrier $barrier): array => [
                'barrier_id' => (int) $barrier->barrier_id,
                'unit_id' => $barrier->unit_id,
                'category' => (string) $barrier->category,
                'description' => $barrier->description,
                'opened_at' => optional($barrier->opened_at)->toJSON(),
                'attribution' => 'unit',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, TransportRequest>  $transport
     * @param  Collection<int, BedRequest>  $bedRequests
     * @return array<string, mixed>
     */
    private function next(?Encounter $encounter, $transport, $bedRequests): array
    {
        $activeTransport = $transport->first(
            static fn (TransportRequest $request): bool => ! in_array($request->status, ['completed', 'canceled', 'failed'], true),
        );
        $pendingBedRequest = $bedRequests->first(
            static fn (BedRequest $request): bool => $request->status === 'pending',
        );

        return [
            'target_location' => $activeTransport?->destination
                ?? $pendingBedRequest?->required_unit_type,
            'transport_needed_at' => $activeTransport?->needed_at?->toJSON(),
            'expected_discharge_date' => $encounter?->expected_discharge_date,
        ];
    }
}
