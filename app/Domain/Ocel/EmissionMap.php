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
