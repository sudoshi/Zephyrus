<?php

namespace App\Domain\Ocel;

/**
 * The versioned OCEL object-type and activity catalog (Part X §X.2.2 / §X.2.3).
 *
 * This is the single modeling commitment of Part X: the set of object types the
 * hospital is decomposed into, and the flow-critical activities the emission map
 * (X.3.2) may produce. It is intentionally code (not a seeder row set) so the
 * projector is self-sufficient — `ensureCatalog()` upserts these into
 * ocel.object_types / ocel.activities before projecting, so `ocel:project` runs
 * on a freshly-migrated schema with no seed step.
 *
 * The catalog grows ADDITIVELY (§X.2 risk "over-modeling"): add a type only when
 * a concrete analytical question demands it. X0 emits the seven flow-critical
 * types the live sources on `main` actually carry; the remaining catalog entries
 * (Order, EVS Task, Staff Assignment, Alert, PDSA/Intervention) are declared here
 * so downstream phases (X2/X3/X4) extend the emission map, not the catalog.
 */
final class OcelCatalog
{
    public const VERSION = 1;

    /**
     * Object types, keyed by their OCEL `type`. `emitted` flags the seven X0
     * projects today (the rest are declared-but-not-yet-emitted).
     *
     * @return array<string, array{lens: string, source_system: string, emitted: bool}>
     */
    public static function objectTypes(): array
    {
        return [
            'Patient' => ['lens' => 'clinical', 'source_system' => 'flow_core.flow_events', 'emitted' => true],
            'Encounter' => ['lens' => 'clinical', 'source_system' => 'flow_core.flow_events', 'emitted' => true],
            'Bed' => ['lens' => 'space', 'source_system' => 'flow_core.flow_events', 'emitted' => true],
            'Unit' => ['lens' => 'space', 'source_system' => 'flow_core.flow_events', 'emitted' => true],
            'OR Case' => ['lens' => 'surgical', 'source_system' => 'prod.or_cases', 'emitted' => true],
            'OR Suite' => ['lens' => 'surgical', 'source_system' => 'prod.or_cases', 'emitted' => true],
            'Transport Job' => ['lens' => 'logistics', 'source_system' => 'prod.transport_requests', 'emitted' => true],
            // Declared for the catalog; emitted by later Arena phases.
            'Order' => ['lens' => 'clinical', 'source_system' => 'flow_core.flow_events', 'emitted' => false],
            'EVS Task' => ['lens' => 'logistics', 'source_system' => 'prod.evs_tasks', 'emitted' => false],
            'Staff Assignment' => ['lens' => 'resource', 'source_system' => 'prod.staffing', 'emitted' => false],
            'Alert' => ['lens' => 'governance', 'source_system' => 'prod.cockpit_alerts', 'emitted' => false],
            'PDSA / Intervention' => ['lens' => 'governance', 'source_system' => 'ops.interventions', 'emitted' => false],
        ];
    }

    /**
     * The activity catalog (§X.2.3) — the verbs, grouped by domain. The emission
     * map is free to produce any of these; an activity encountered that is not
     * listed here is still projected (and upserted into ocel.activities on the
     * fly, domain='uncatalogued') so projection never fails on a new verb.
     *
     * @return array<string, string> activity => domain
     */
    public static function activities(): array
    {
        return [
            // ED
            'triage' => 'ed',
            'bed-request' => 'ed',
            'provider-seen' => 'ed',
            'admit-decision' => 'ed',
            'board' => 'ed',
            'depart' => 'ed',
            // RTDC / placement
            'request-bed' => 'placement',
            'assign-bed' => 'placement',
            'place' => 'placement',
            'transfer' => 'placement',
            'register' => 'placement',
            'admit' => 'placement',
            'discharge' => 'placement',
            'update' => 'placement',
            // Clinical pathways (fed by ClinicalPathwaySeeder — the X3 conformance corpus)
            'sepsis_recognition' => 'pathway',
            'vitals_sirs' => 'pathway',
            'lactate_order' => 'pathway',
            'lactate_result' => 'pathway',
            'blood_culture_order' => 'pathway',
            'antibiotic_administration' => 'pathway',
            'fluid_bolus_30mlkg' => 'pathway',
            'vasopressor_start' => 'pathway',
            'repeat_lactate_order' => 'pathway',
            'repeat_lactate_result' => 'pathway',
            'ed_arrival' => 'pathway',
            'stroke_alert' => 'pathway',
            'nihss_assessment' => 'pathway',
            'ct_head_order' => 'pathway',
            'ct_head_performed' => 'pathway',
            'ct_head_read' => 'pathway',
            'thrombolysis_administration' => 'pathway',
            'thrombolysis_excluded' => 'pathway',
            'observation' => 'pathway',
            'order' => 'pathway',
            'medication' => 'pathway',
            'clinical_context' => 'pathway',
            // Perioperative (incl. WHO surgical-safety checklist milestones)
            'Safety_Check' => 'periop',
            'Consent' => 'periop',
            'H&P' => 'periop',
            'Labs' => 'periop',
            'Transport' => 'periop',
            // OR phase timeline (prod.case_timings) — carries OR Case phase +
            // OR Suite status as time-varying object_changes.
            'Pre_Procedure' => 'periop',
            'Procedure' => 'periop',
            'Recovery' => 'periop',
            'Room_Turnover' => 'periop',
            // Transport
            'transport-request' => 'transport',
            'transport-pickup' => 'transport',
            'transport-dropoff' => 'transport',
        ];
    }
}
