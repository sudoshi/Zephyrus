<?php

namespace App\Domain\Ocel;

use Carbon\Carbon;

/**
 * The declarative emission map (Part X §X.3.2): one transformer per source row →
 * an OCEL event that references as many objects as it truly touches, through
 * QUALIFIED E2O and O2O relationships. Keeping the mapping explicit and thin is
 * what keeps the projector honest (a wrong qualifier or missed O2O corrupts every
 * downstream map — §X.3 risk X-R1), and keeping these methods PURE + DB-free is
 * what lets them be unit-tested against fixture rows with asserted OCEL output.
 *
 * PHI safety by construction (§X.3.4): patient and encounter identifiers are
 * hashed here at projection time (patient-<hash>, enc-<hash>) — the same
 * deterministic stable-hash the flow normaliser/seeder use — so no name, MRN, or
 * note ever reaches ocel.*. Space/surgical identifiers (bed, unit, OR suite) are
 * operational, not clinical, so they stay human-readable.
 */
final class EmissionMap
{
    /** De-identify a clinical reference — deterministic, one-way, PHI-safe. */
    public static function hashRef(?string $ref): ?string
    {
        $ref = $ref !== null ? trim($ref) : '';
        if ($ref === '') {
            return null;
        }

        return substr(hash('sha256', $ref), 0, 12);
    }

    /** Slugify an operational (non-PHI) code for a stable, readable object id. */
    private static function slug(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($value, '-');
    }

    /**
     * flow_core.flow_events → one OCEL event. This is the bulk of the log: ED,
     * RTDC placement, and the seeded sepsis/stroke clinical pathways all flow
     * through here. Activity prefers the granular pathway verb
     * (metadata.activity) over the coarse event_type.
     *
     * @param  object  $row  a flow_core.flow_events row (stdClass)
     */
    public static function forFlowEvent(object $row): ?EmittedEvent
    {
        if (empty($row->occurred_at) || empty($row->flow_event_id)) {
            return null;
        }

        $metadata = self::decodeJson($row->metadata ?? null);
        $activity = $metadata['activity'] ?? ($row->event_type ?? 'unknown');

        $objects = [];
        $o2o = [];

        $encId = null;
        if (! empty($row->encounter_ref)) {
            $encId = 'enc-'.self::hashRef($row->encounter_ref);
            $objects[] = ['id' => $encId, 'type' => 'Encounter', 'qualifier' => 'subject', 'attrs' => array_filter([
                'service_line' => $row->service_line ?? null,
                'patient_class' => $row->patient_class ?? null,
            ], fn ($v) => $v !== null)];
        }

        $patId = null;
        if (! empty($row->patient_ref)) {
            $patId = 'patient-'.self::hashRef($row->patient_ref);
            $objects[] = ['id' => $patId, 'type' => 'Patient', 'qualifier' => 'patient'];
        }

        $unitCode = $row->to_source_location_code ?? $row->point_of_care ?? null;
        $unitId = null;
        if (! empty($unitCode)) {
            $unitId = 'unit-'.self::slug($unitCode);
            $objects[] = ['id' => $unitId, 'type' => 'Unit', 'qualifier' => 'location'];
        }

        $bedId = null;
        if (! empty($row->bed)) {
            // Qualify a bare bed label with its unit, but don't re-prefix a bed
            // whose code already carries the unit (real feeds hand back the
            // fully-qualified code in both columns).
            $bedKey = self::slug($row->bed);
            $unitSlug = $unitCode ? self::slug($unitCode) : null;
            if ($unitSlug && $bedKey !== '' && ! str_starts_with($bedKey, $unitSlug)) {
                $bedKey = $unitSlug.'-'.$bedKey;
            }
            $bedId = 'bed-'.($bedKey !== '' ? $bedKey : 'x');
            $objects[] = ['id' => $bedId, 'type' => 'Bed', 'qualifier' => 'resource'];
        }

        // Qualified O2O bindings — only when both endpoints exist.
        if ($encId && $patId) {
            $o2o[] = ['from' => $encId, 'to' => $patId, 'qualifier' => 'of'];
        }
        if ($encId && $bedId) {
            $o2o[] = ['from' => $encId, 'to' => $bedId, 'qualifier' => 'occupies'];
        }
        if ($bedId && $unitId) {
            $o2o[] = ['from' => $bedId, 'to' => $unitId, 'qualifier' => 'in'];
        }

        // Time-varying Bed status — the raw material for bed-turnaround analytics
        // (§X.2.1 / §X.6). A placement occupies the bed; a discharge vacates it.
        $changes = [];
        if ($bedId) {
            $bedStatus = match (true) {
                in_array($activity, ['admit', 'transfer', 'place', 'register', 'assign-bed'], true) => 'occupied',
                $activity === 'discharge' => 'vacated',
                default => null,
            };
            if ($bedStatus !== null) {
                $changes[] = ['object_id' => $bedId, 'attr' => 'status', 'value' => $bedStatus, 'at' => Carbon::parse($row->occurred_at)];
            }
        }

        $attrs = array_filter([
            'pathway' => $metadata['pathway'] ?? null,
            'granular_activity' => $metadata['activity'] ?? null,
            'within_3hr' => $metadata['within_3hr'] ?? null,
            'minutes_from_recognition' => $metadata['minutes_from_recognition'] ?? null,
            'deviation' => $metadata['deviation'] ?? null,
            'event_category' => $row->event_category ?? null,
            'event_type' => $row->event_type ?? null,
            'service_line' => $row->service_line ?? null,
            'priority' => $row->priority ?? null,
            'patient_class' => $row->patient_class ?? null,
            // Standardised clinical CODES only (LOINC/RxNorm/ICD-10) — coded
            // flags, never free text (§X.3.4). These are what conformance (X.7)
            // reads to answer "was lactate ordered / antibiotic given".
            'diagnosis_codes' => self::decodeJson($row->diagnosis_codes ?? null) ?: null,
            'order_codes' => self::decodeJson($row->order_codes ?? null) ?: null,
            'observation_codes' => self::decodeJson($row->observation_codes ?? null) ?: null,
            'medication_codes' => self::decodeJson($row->medication_codes ?? null) ?: null,
        ], fn ($v) => $v !== null);

        return new EmittedEvent(
            id: 'fe-'.$row->flow_event_id,
            activity: (string) $activity,
            timestamp: Carbon::parse($row->occurred_at),
            sourceSystem: 'flow_core.flow_events',
            sourceRef: (string) $row->flow_event_id,
            objects: $objects,
            o2o: $o2o,
            attrs: $attrs,
            changes: $changes,
        );
    }

    /**
     * prod.care_journey_milestones (joined to prod.or_cases) → one OCEL event.
     * This surfaces the WHO surgical-safety checklist (Sign-In / Time-Out /
     * Sign-Out as Safety_Check milestones) plus the periop readiness milestones,
     * binding each to its OR Case and OR Suite — the surgical-safety conformance
     * corpus for X.7.
     *
     * @param  object  $row  a milestone row LEFT JOINed to its or_case
     */
    public static function forMilestone(object $row): ?EmittedEvent
    {
        $when = $row->completed_at ?? $row->created_at ?? null;
        if (empty($when) || empty($row->milestone_id)) {
            return null;
        }

        $objects = [];
        $o2o = [];

        $caseId = 'orcase-'.$row->case_id;
        $objects[] = ['id' => $caseId, 'type' => 'OR Case', 'qualifier' => 'subject', 'attrs' => array_filter([
            'safety_status' => $row->safety_status ?? null,
            'journey_progress' => isset($row->journey_progress) ? (int) $row->journey_progress : null,
        ], fn ($v) => $v !== null)];

        $suiteId = null;
        if (! empty($row->room_id)) {
            $suiteId = 'orsuite-'.$row->room_id;
            $objects[] = ['id' => $suiteId, 'type' => 'OR Suite', 'qualifier' => 'resource'];
        }

        $patId = null;
        if (! empty($row->patient_id)) {
            $patId = 'patient-'.self::hashRef((string) $row->patient_id);
            $objects[] = ['id' => $patId, 'type' => 'Patient', 'qualifier' => 'patient'];
        }

        if ($suiteId) {
            $o2o[] = ['from' => $caseId, 'to' => $suiteId, 'qualifier' => 'in'];
        }
        if ($patId) {
            $o2o[] = ['from' => $caseId, 'to' => $patId, 'qualifier' => 'of'];
        }

        $attrs = array_filter([
            'milestone_type' => $row->milestone_type ?? null,
            'status' => $row->status ?? null,
            'required' => isset($row->required) ? (bool) $row->required : null,
            'surgery_date' => isset($row->surgery_date) ? (string) $row->surgery_date : null,
        ], fn ($v) => $v !== null);

        return new EmittedEvent(
            id: 'mil-'.$row->milestone_id,
            activity: (string) ($row->milestone_type ?? 'Milestone'),
            timestamp: Carbon::parse($when),
            sourceSystem: 'prod.care_journey_milestones',
            sourceRef: (string) $row->milestone_id,
            objects: $objects,
            o2o: $o2o,
            attrs: $attrs,
        );
    }

    /**
     * prod.case_timings (joined to prod.or_cases) → one OCEL event per OR phase
     * (Pre_Procedure → Procedure → Recovery → Room_Turnover), recording the
     * TIME-VARYING OR Case phase and OR Suite status as object_changes — the raw
     * material for PACU pooling / turnover analytics (§X.6). Binds each phase to
     * its OR Case (subject) and OR Suite (resource).
     *
     * @param  object  $row  a case_timings row LEFT JOINed to its or_case
     */
    public static function forCaseTiming(object $row): ?EmittedEvent
    {
        $when = $row->actual_start ?? $row->planned_start ?? null;
        if (empty($when) || empty($row->timing_id) || empty($row->phase)) {
            return null;
        }

        $at = Carbon::parse($when);
        $caseId = 'orcase-'.$row->case_id;
        $objects = [['id' => $caseId, 'type' => 'OR Case', 'qualifier' => 'subject']];
        $o2o = [];
        $changes = [['object_id' => $caseId, 'attr' => 'phase', 'value' => $row->phase, 'at' => $at]];

        if (! empty($row->room_id)) {
            $suiteId = 'orsuite-'.$row->room_id;
            $objects[] = ['id' => $suiteId, 'type' => 'OR Suite', 'qualifier' => 'resource'];
            $o2o[] = ['from' => $caseId, 'to' => $suiteId, 'qualifier' => 'in'];
            $suiteStatus = match ($row->phase) {
                'Procedure' => 'running',
                'Room_Turnover' => 'turnover',
                default => 'occupied',
            };
            $changes[] = ['object_id' => $suiteId, 'attr' => 'status', 'value' => $suiteStatus, 'at' => $at];
        }

        $attrs = array_filter([
            'phase' => $row->phase,
            'planned_duration' => isset($row->planned_duration) ? (int) $row->planned_duration : null,
            'actual_duration' => isset($row->actual_duration) ? (int) $row->actual_duration : null,
            'variance' => isset($row->variance) ? (int) $row->variance : null,
        ], fn ($v) => $v !== null);

        return new EmittedEvent(
            id: 'ct-'.$row->timing_id,
            activity: (string) $row->phase,
            timestamp: $at,
            sourceSystem: 'prod.case_timings',
            sourceRef: (string) $row->timing_id,
            objects: $objects,
            o2o: $o2o,
            attrs: $attrs,
            changes: $changes,
        );
    }

    /**
     * prod.transport_requests → up to three OCEL events (request → pickup →
     * dropoff) keyed on the row's phase timestamps, so the transport network's
     * dispatch-and-wait structure is mineable.
     *
     * @param  object  $row  a transport_requests row
     * @return array<int, EmittedEvent>
     */
    public static function forTransport(object $row): array
    {
        if (empty($row->transport_request_id)) {
            return [];
        }

        $jobId = 'transport-'.$row->transport_request_id;
        $encId = ! empty($row->encounter_ref) ? 'enc-'.self::hashRef($row->encounter_ref) : null;
        $destId = ! empty($row->destination) ? 'unit-'.self::slug($row->destination) : null;

        $baseObjects = [['id' => $jobId, 'type' => 'Transport Job', 'qualifier' => 'subject']];
        if ($encId) {
            $baseObjects[] = ['id' => $encId, 'type' => 'Encounter', 'qualifier' => 'patient'];
        }
        if ($destId) {
            $baseObjects[] = ['id' => $destId, 'type' => 'Unit', 'qualifier' => 'target'];
        }

        $o2o = $encId ? [['from' => $jobId, 'to' => $encId, 'qualifier' => 'for']] : [];

        $attrs = array_filter([
            'request_type' => $row->request_type ?? null,
            'priority' => $row->priority ?? null,
            'status' => $row->status ?? null,
            'transport_mode' => $row->transport_mode ?? null,
            'clinical_service' => $row->clinical_service ?? null,
            'origin' => $row->origin ?? null,
            'destination' => $row->destination ?? null,
        ], fn ($v) => $v !== null);

        $phases = [
            ['activity' => 'transport-request', 'at' => $row->requested_at ?? null, 'suffix' => 'request'],
            ['activity' => 'transport-pickup', 'at' => $row->dispatched_at ?? $row->assigned_at ?? null, 'suffix' => 'pickup'],
            ['activity' => 'transport-dropoff', 'at' => $row->completed_at ?? null, 'suffix' => 'dropoff'],
        ];

        $events = [];
        foreach ($phases as $phase) {
            if (empty($phase['at'])) {
                continue;
            }
            $events[] = new EmittedEvent(
                id: 'tr-'.$row->transport_request_id.'-'.$phase['suffix'],
                activity: $phase['activity'],
                timestamp: Carbon::parse($phase['at']),
                sourceSystem: 'prod.transport_requests',
                sourceRef: (string) $row->transport_request_id,
                objects: $baseObjects,
                o2o: $o2o,
                attrs: $attrs,
            );
        }

        return $events;
    }

    /**
     * prod.home_episodes (joined with program + kit) → home-activate /
     * home-discharge over a first-class Home Episode object, with the RPM Kit
     * as a linked resource (ACUM-PRD-HAH-001 §6.3). PHI-safe: patient travels
     * only as a hashed ref attr; program/condition/zone are operational.
     *
     * @return list<EmittedEvent>
     */
    public static function forHomeEpisode(object $row): array
    {
        if (empty($row->home_episode_id)) {
            return [];
        }

        $epId = 'home-ep-'.$row->home_episode_id;
        $objects = [['id' => $epId, 'type' => 'Home Episode', 'qualifier' => 'subject']];
        $o2o = [];

        if (! empty($row->kit_code)) {
            $kitId = 'rpm-kit-'.self::slug((string) $row->kit_code);
            $objects[] = ['id' => $kitId, 'type' => 'RPM Kit', 'qualifier' => 'device'];
            $o2o[] = ['from' => $epId, 'to' => $kitId, 'qualifier' => 'monitored_by'];
        }

        $attrs = array_filter([
            'program' => $row->program_type ?? null,
            'condition' => $row->condition_code ?? null,
            'admission_source' => $row->admission_source ?? null,
            'service_zone' => $row->service_zone ?? null,
            'disposition' => $row->disposition ?? null,
            'patient_ref' => ! empty($row->patient_ref) ? self::hashRef((string) $row->patient_ref) : null,
        ], fn ($v) => $v !== null);

        $events = [];
        if (! empty($row->started_at)) {
            $events[] = new EmittedEvent(
                id: 'home-ep-'.$row->home_episode_id.'-activate',
                activity: 'home-activate',
                timestamp: Carbon::parse($row->started_at),
                sourceSystem: 'prod.home_episodes',
                sourceRef: (string) $row->home_episode_id,
                objects: $objects,
                o2o: $o2o,
                attrs: $attrs,
            );
        }
        if (! empty($row->ended_at)) {
            $events[] = new EmittedEvent(
                id: 'home-ep-'.$row->home_episode_id.'-discharge',
                activity: 'home-discharge',
                timestamp: Carbon::parse($row->ended_at),
                sourceSystem: 'prod.home_episodes',
                sourceRef: (string) $row->home_episode_id,
                objects: $objects,
                o2o: $o2o,
                attrs: $attrs,
            );
        }

        return $events;
    }

    /**
     * prod.home_visits (completed) → home-visit-complete over the Home Visit +
     * its Home Episode; waiver flag + on-time telemetry ride as attrs — the
     * raw material for the visit-cadence conformance check.
     *
     * @return list<EmittedEvent>
     */
    public static function forHomeVisit(object $row): array
    {
        if (empty($row->home_visit_id) || empty($row->completed_at)) {
            return [];
        }

        $epId = 'home-ep-'.$row->home_episode_id;
        $visitId = 'home-visit-'.$row->home_visit_id;

        return [new EmittedEvent(
            id: $visitId.'-complete',
            activity: 'home-visit-complete',
            timestamp: Carbon::parse($row->completed_at),
            sourceSystem: 'prod.home_visits',
            sourceRef: (string) $row->home_visit_id,
            objects: [
                ['id' => $visitId, 'type' => 'Home Visit', 'qualifier' => 'subject'],
                ['id' => $epId, 'type' => 'Home Episode', 'qualifier' => 'episode'],
            ],
            o2o: [['from' => $visitId, 'to' => $epId, 'qualifier' => 'for']],
            attrs: array_filter([
                'visit_type' => $row->visit_type ?? null,
                'waiver_required' => isset($row->is_waiver_required) ? (bool) $row->is_waiver_required : null,
                'on_time' => $row->on_time ?? null,
            ], fn ($v) => $v !== null),
        )];
    }

    /**
     * prod.home_escalations → home-escalation-open / home-escalation-resolve
     * over a first-class Escalation object; the timing chain + outcome ride as
     * attrs — the escalation-protocol conformance corpus.
     *
     * @return list<EmittedEvent>
     */
    public static function forHomeEscalation(object $row): array
    {
        if (empty($row->home_escalation_id)) {
            return [];
        }

        $epId = 'home-ep-'.$row->home_episode_id;
        $escId = 'home-esc-'.$row->home_escalation_id;
        $objects = [
            ['id' => $escId, 'type' => 'Escalation', 'qualifier' => 'subject'],
            ['id' => $epId, 'type' => 'Home Episode', 'qualifier' => 'episode'],
        ];
        $o2o = [['from' => $escId, 'to' => $epId, 'qualifier' => 'for']];
        $attrs = array_filter([
            'trigger_type' => $row->trigger_type ?? null,
            'response_mode' => $row->response_mode ?? null,
            'response_minutes' => $row->response_minutes ?? null,
            'outcome' => $row->outcome ?? null,
        ], fn ($v) => $v !== null);

        $events = [];
        if (! empty($row->initiated_at)) {
            $events[] = new EmittedEvent(
                id: $escId.'-open',
                activity: 'home-escalation-open',
                timestamp: Carbon::parse($row->initiated_at),
                sourceSystem: 'prod.home_escalations',
                sourceRef: (string) $row->home_escalation_id,
                objects: $objects,
                o2o: $o2o,
                attrs: $attrs,
            );
        }
        if (! empty($row->resolved_at)) {
            $events[] = new EmittedEvent(
                id: $escId.'-resolve',
                activity: 'home-escalation-resolve',
                timestamp: Carbon::parse($row->resolved_at),
                sourceSystem: 'prod.home_escalations',
                sourceRef: (string) $row->home_escalation_id,
                objects: $objects,
                o2o: $o2o,
                attrs: $attrs,
            );
        }

        return $events;
    }

    /**
     * prod.barriers → up to two OCEL events (barrier_opened → barrier_resolved)
     * over one first-class Barrier object, carrying the barrier's status as a
     * time-varying object_change — the raw material for "how long do placement
     * barriers stay open, per unit" (the flow-reconciliation loop's re-measure).
     *
     * Identity note (§X.3.4): a barrier's encounter_id/unit_id are NUMERIC prod
     * FKs, a different identity space than the flow-lens Encounter (hashed string
     * ref) / Unit (slugged location code). We therefore do NOT mint a parallel
     * Encounter object — the encounter is carried as a de-identified `encounter_ref`
     * attr. The Unit IS linked (O2O `in`) via its abbreviation slug, which merges
     * with the flow lens' `unit-<code>` object when the codes align and otherwise
     * groups barriers per unit consistently.
     *
     * @param  object  $row  a prod.barriers row LEFT JOINed to its unit
     * @return array<int, EmittedEvent>
     */
    public static function forBarrier(object $row): array
    {
        if (empty($row->barrier_id) || empty($row->opened_at)) {
            return [];
        }

        $barrierId = 'barrier-'.$row->barrier_id;
        $barrierObj = ['id' => $barrierId, 'type' => 'Barrier', 'qualifier' => 'subject', 'attrs' => array_filter([
            'category' => $row->category ?? null,
            'reason_code' => $row->reason_code ?? null,
            'unit_id' => isset($row->unit_id) ? (int) $row->unit_id : null,
            'encounter_ref' => ! empty($row->encounter_id) ? 'enc-'.self::hashRef((string) $row->encounter_id) : null,
        ], fn ($v) => $v !== null)];

        $objects = [$barrierObj];
        $o2o = [];

        if (! empty($row->unit_abbreviation)) {
            $unitId = 'unit-'.self::slug($row->unit_abbreviation);
            $objects[] = ['id' => $unitId, 'type' => 'Unit', 'qualifier' => 'location'];
            $o2o[] = ['from' => $barrierId, 'to' => $unitId, 'qualifier' => 'in'];
        }

        $attrs = array_filter([
            'category' => $row->category ?? null,
            'reason_code' => $row->reason_code ?? null,
        ], fn ($v) => $v !== null);

        $events = [];

        $openedAt = Carbon::parse($row->opened_at);
        $events[] = new EmittedEvent(
            id: 'bar-'.$row->barrier_id.'-opened',
            activity: 'barrier_opened',
            timestamp: $openedAt,
            sourceSystem: 'prod.barriers',
            sourceRef: (string) $row->barrier_id,
            objects: $objects,
            o2o: $o2o,
            attrs: $attrs,
            changes: [['object_id' => $barrierId, 'attr' => 'status', 'value' => 'open', 'at' => $openedAt]],
        );

        // Only emit the close when the barrier has actually resolved.
        if (! empty($row->resolved_at)) {
            $resolvedAt = Carbon::parse($row->resolved_at);
            $events[] = new EmittedEvent(
                id: 'bar-'.$row->barrier_id.'-resolved',
                activity: 'barrier_resolved',
                timestamp: $resolvedAt,
                sourceSystem: 'prod.barriers',
                sourceRef: (string) $row->barrier_id,
                objects: $objects,
                o2o: $o2o,
                attrs: $attrs,
                changes: [['object_id' => $barrierId, 'attr' => 'status', 'value' => 'resolved', 'at' => $resolvedAt]],
            );
        }

        return $events;
    }

    /**
     * prod.ancillary_milestones joined to the shared order/catalog becomes one
     * object-centric event. Object identities are UUID- or hash-derived and
     * deliberately exclude source order, patient, encounter, accession, and
     * medication identifiers.
     */
    public static function forAncillaryMilestone(object $row): ?EmittedEvent
    {
        if (empty($row->ancillary_milestone_id) || empty($row->order_uuid) || empty($row->occurred_at) || empty($row->ocel_event_type)) {
            return null;
        }

        $orderKey = (string) $row->order_uuid;
        $orderId = 'anc-order-'.$orderKey;
        $orderMetadata = self::decodeJson($row->order_metadata ?? null);
        $objects = [[
            'id' => $orderId,
            'type' => 'Ancillary Order',
            'qualifier' => 'subject',
            'attrs' => array_filter([
                'department' => $row->department ?? null,
                'work_item_type' => $row->work_item_type ?? null,
                'priority' => $row->priority ?? null,
                'patient_class' => $row->patient_class ?? null,
                'discharge_blocking' => $orderMetadata['discharge_blocking'] ?? null,
            ], fn (mixed $value): bool => $value !== null),
        ]];
        $o2o = [];

        $encounterRef = ! empty($row->encounter_ref)
            ? (string) $row->encounter_ref
            : (! empty($row->encounter_id) ? (string) $row->encounter_id : null);
        if ($encounterRef !== null) {
            $encounterId = 'enc-'.self::hashRef($encounterRef);
            $objects[] = ['id' => $encounterId, 'type' => 'Encounter', 'qualifier' => 'context'];
            $o2o[] = ['from' => $orderId, 'to' => $encounterId, 'qualifier' => 'for'];
        }

        if (! empty($row->unit_abbreviation)) {
            $unitId = 'unit-'.self::slug($row->unit_abbreviation);
            $objects[] = ['id' => $unitId, 'type' => 'Unit', 'qualifier' => 'location'];
            $o2o[] = ['from' => $orderId, 'to' => $unitId, 'qualifier' => 'in'];
        }

        [$domainObjects, $domainRelations] = self::ancillaryDomainObjects(
            (string) $row->department,
            (string) $row->milestone_code,
            $orderKey,
            $orderId,
            $orderMetadata,
        );
        $objects = array_values(array_reduce($domainObjects, function (array $carry, array $object): array {
            $carry[$object['id']] = $object;

            return $carry;
        }, array_column($objects, null, 'id')));
        $o2o = [...$o2o, ...$domainRelations];
        $at = Carbon::parse($row->occurred_at);

        return new EmittedEvent(
            id: 'anc-mil-'.$row->ancillary_milestone_id,
            activity: (string) $row->ocel_event_type,
            timestamp: $at,
            sourceSystem: 'prod.ancillary_milestones',
            sourceRef: (string) $row->ancillary_milestone_id,
            objects: $objects,
            o2o: $o2o,
            attrs: array_filter([
                'department' => $row->department ?? null,
                'milestone_code' => $row->milestone_code ?? null,
                'phase' => $row->phase ?? null,
                'priority' => $row->priority ?? null,
                'source_rank' => isset($row->source_rank) ? (int) $row->source_rank : null,
                'source_class' => $row->system_class ?? null,
                'process_ids' => self::decodeJson($row->process_ids ?? null),
            ], fn (mixed $value): bool => $value !== null),
            changes: [[
                'object_id' => $orderId,
                'attr' => 'current_milestone',
                'value' => (string) $row->milestone_code,
                'at' => $at,
            ]],
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{list<array{id:string,type:string,qualifier:string,attrs?:array<string,mixed>}>, list<array{from:string,to:string,qualifier:string}>}
     */
    private static function ancillaryDomainObjects(
        string $department,
        string $code,
        string $orderKey,
        string $orderId,
        array $metadata,
    ): array {
        $objects = [];
        $relations = [];
        $add = function (string $id, string $type, string $qualifier, string $relation = 'part-of') use (&$objects, &$relations, $orderId): void {
            $objects[] = ['id' => $id, 'type' => $type, 'qualifier' => $qualifier];
            $relations[] = ['from' => $id, 'to' => $orderId, 'qualifier' => $relation];
        };

        if ($department === 'rad') {
            $studyId = 'imaging-study-'.$orderKey;
            $add($studyId, 'Imaging Study', 'work-item', 'fulfills');
            if (in_array($code, ['RAD_PRELIM', 'RAD_FINAL', 'RAD_CRITICAL_NOTIFIED', 'RAD_CRITICAL_ACKED', 'RAD_FOLLOWUP_TRACKED'], true)) {
                $readId = 'imaging-read-'.$orderKey;
                $add($readId, 'Imaging Read', 'result');
                $relations[] = ['from' => $readId, 'to' => $studyId, 'qualifier' => 'interprets'];
            }
            if (in_array($code, ['RAD_FINAL', 'RAD_CRITICAL_NOTIFIED', 'RAD_CRITICAL_ACKED', 'RAD_FOLLOWUP_TRACKED'], true)) {
                $reportId = 'diagnostic-report-rad-'.$orderKey;
                $add($reportId, 'Diagnostic Report', 'report');
                $relations[] = ['from' => $reportId, 'to' => $studyId, 'qualifier' => 'reports'];
            }
            if (in_array($code, ['RAD_CRITICAL_NOTIFIED', 'RAD_CRITICAL_ACKED', 'RAD_FOLLOWUP_TRACKED'], true)) {
                $findingId = 'critical-result-rad-'.$orderKey;
                $communicationId = 'communication-rad-'.$orderKey;
                $add($findingId, 'Critical Result', 'result');
                $add($communicationId, 'Communication Task', 'communication');
                $relations[] = ['from' => $communicationId, 'to' => $findingId, 'qualifier' => 'communicates'];
            }
            self::addAncillaryResource($objects, $relations, $metadata['scanner_ref'] ?? null, 'scanner', 'Scanner', $orderId);
        } elseif ($department === 'lab') {
            $testId = 'laboratory-test-'.$orderKey;
            $add($testId, 'Laboratory Test', 'work-item', 'fulfills');
            if (! in_array($code, ['LAB_ORDERED', 'LAB_CANCELLED'], true)) {
                $specimenId = 'laboratory-specimen-'.$orderKey;
                $add($specimenId, 'Laboratory Specimen', 'specimen');
                $relations[] = ['from' => $specimenId, 'to' => $testId, 'qualifier' => 'supports'];
            }
            if (in_array($code, ['LAB_PRELIM', 'LAB_RESULTED', 'LAB_VERIFIED', 'LAB_CRITICAL_NOTIFIED', 'LAB_CRITICAL_ACKED', 'LAB_CORRECTED'], true)) {
                $resultId = 'laboratory-result-'.$orderKey;
                $add($resultId, 'Laboratory Result', 'result');
                $relations[] = ['from' => $resultId, 'to' => $testId, 'qualifier' => 'result-of'];
            }
            self::addAncillaryResource($objects, $relations, $metadata['analyzer_ref'] ?? null, 'analyzer', 'Analyzer', $orderId);
        } elseif ($department === 'pathology') {
            $caseId = 'ap-case-'.$orderKey;
            $specimenId = 'pathology-specimen-'.$orderKey;
            $add($caseId, 'AP Case', 'work-item', 'fulfills');
            $add($specimenId, 'Pathology Specimen', 'specimen');
            $relations[] = ['from' => $specimenId, 'to' => $caseId, 'qualifier' => 'part-of'];
            if (in_array($code, ['AP_GROSSED', 'AP_PROCESSING_BATCH', 'AP_SLIDES_READY', 'AP_DIAGNOSED', 'AP_SIGNED_OUT'], true)) {
                $slideId = 'pathology-slide-block-'.$orderKey;
                $add($slideId, 'Pathology Slide / Block', 'resource');
                $relations[] = ['from' => $slideId, 'to' => $specimenId, 'qualifier' => 'derived-from'];
            }
            if (in_array($code, ['AP_DIAGNOSED', 'AP_SIGNED_OUT', 'AP_FROZEN_RESULTED'], true)) {
                $reportId = 'diagnostic-report-ap-'.$orderKey;
                $add($reportId, 'Diagnostic Report', 'report');
                $relations[] = ['from' => $reportId, 'to' => $caseId, 'qualifier' => 'reports'];
            }
            self::addAncillaryResource($objects, $relations, $metadata['pathologist_assignment_ref'] ?? null, 'pathologist-assignment', 'Pathologist Assignment', $orderId);
        } elseif ($department === 'blood_bank') {
            $requestId = 'blood-bank-request-'.$orderKey;
            $add($requestId, 'Blood Bank Request', 'work-item', 'fulfills');
            if (in_array($code, ['BB_TNS_READY', 'BB_CROSSMATCH_READY'], true)) {
                $add('blood-bank-specimen-'.$orderKey, 'Laboratory Specimen', 'specimen');
            }
            if ($code === 'BB_UNIT_ISSUED') {
                $add('blood-product-unit-'.$orderKey, 'Blood Product Unit', 'result');
            }
        } elseif ($department === 'rx') {
            $medicationId = 'medication-order-'.$orderKey;
            $workId = 'pharmacy-work-'.$orderKey;
            $add($medicationId, 'Medication Order', 'work-item', 'fulfills');
            $add($workId, 'Pharmacy Work', 'resource');
            $relations[] = ['from' => $workId, 'to' => $medicationId, 'qualifier' => 'processes'];
            if (! in_array($code, ['RX_ORDERED', 'RX_QUEUE_IN', 'RX_VERIFIED', 'RX_DISCONTINUED'], true)) {
                $doseId = 'medication-dose-'.$orderKey;
                $add($doseId, 'Medication Dose', 'dose');
                $relations[] = ['from' => $doseId, 'to' => $medicationId, 'qualifier' => 'dose-of'];
            }
            self::addAncillaryResource(
                $objects,
                $relations,
                $metadata['adc_station_ref'] ?? $metadata['preparation_resource_ref'] ?? null,
                'medication-resource',
                'Medication Resource',
                $orderId,
            );
        }

        return [$objects, $relations];
    }

    /** @param list<array<string, mixed>> $objects @param list<array<string, string>> $relations */
    private static function addAncillaryResource(
        array &$objects,
        array &$relations,
        mixed $reference,
        string $prefix,
        string $type,
        string $orderId,
    ): void {
        if (! is_scalar($reference) || trim((string) $reference) === '') {
            return;
        }

        $id = $prefix.'-'.self::hashRef((string) $reference);
        $objects[] = ['id' => $id, 'type' => $type, 'qualifier' => 'resource'];
        $relations[] = ['from' => $id, 'to' => $orderId, 'qualifier' => 'serves'];
    }

    /**
     * Normalise a jsonb column that Postgres/PDO may hand back as a JSON string
     * or (already-decoded) array.
     *
     * @return array<mixed>
     */
    private static function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
