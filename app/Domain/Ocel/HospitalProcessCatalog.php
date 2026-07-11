<?php

namespace App\Domain\Ocel;

use Illuminate\Support\Str;

/**
 * Canonical, executable catalog for ACUM-OPS-OCEL-001.
 *
 * The source PDF's section 6 contains 93 rows (A1-H8), despite the original
 * implementation request referring to 88. This class preserves every row and
 * turns each one into a bounded, seeded reference flow. These are authored
 * reference models, not evidence that the live OCEL projection observed the
 * behavior. Observed/discovered maps remain a separate Arena surface.
 */
final class HospitalProcessCatalog
{
    public const DOCUMENT_ID = 'ACUM-OPS-OCEL-001';

    public const DOCUMENT_VERSION = '1.0';

    public const CATALOG_VERSION = 1;

    public const MODEL_COUNT = 93;

    public const TARGET_OBJECT_TYPE_COUNT = 52;

    /** @var array<string, string> */
    private const DOMAINS = [
        'A' => 'Access and demand',
        'B' => 'Capacity, placement, inpatient flow, and discharge',
        'C' => 'Perioperative and procedural operations',
        'D' => 'Diagnostics, consults, therapies, and medication',
        'E' => 'Logistics, workforce, assets, and facilities',
        'F' => 'Quality, safety, reliability, and clinical pathways',
        'G' => 'Administrative, financial, enterprise, and network operations',
        'H' => 'Improvement and governance of the operating system',
    ];

    /**
     * Models for which the current OCEL log contains only a semantically
     * incomplete slice. "Partial" deliberately does not mean validated.
     *
     * @var array<int, string>
     */
    private const PARTIAL_PROJECTION = [
        'A6', 'A8',
        'B1', 'B2', 'B3', 'B4',
        'C1', 'C3', 'C4', 'C5', 'C6', 'C7', 'C8', 'C12',
        'D1', 'D5',
        'E1',
        'F1', 'F2', 'F4',
    ];

    /**
     * Models with a real Zephyrus source seam or deterministic operational seed,
     * but no governed end-to-end OCEL emission/view yet.
     *
     * @var array<int, string>
     */
    private const SOURCE_PRESENT = [
        'A5', 'A9',
        'B5', 'B7', 'B9', 'B10', 'B11', 'B12',
        'C2', 'C9',
        'D11', 'D12',
        'E2', 'E3', 'E4', 'E5',
        'F13',
        'G8', 'G11',
        'H1', 'H2', 'H3', 'H4', 'H8',
    ];

    /**
     * id, name, core interaction, improvement question, evidence, priority,
     * interaction pattern, and a pipe-delimited target event sequence.
     *
     * Event names use completed past-tense facts per ACUM-OPS-OCEL-001 section
     * 8.2. They are intentionally target semantics; they are not aliases for the
     * current mixed-case OcelCatalog activity vocabulary.
     *
     * @var array<int, array{string, string, string, string, string, string, string, string}>
     */
    private const ROWS = [
        ['A1', 'Referral-to-consult', 'Referral + Patient/Episode + Appointment + Service + Facility', 'Where do referrals wait, expire, redirect, or duplicate?', 'H+A', 'P2', 'request-to-fulfillment', 'referral-received|referral-triaged|consult-accepted|appointment-scheduled|consult-completed|recommendation-issued'],
        ['A2', 'Elective waitlist and open-slot fill', 'Waitlist Entry + Appointment Slot + Patient + Service + Provider/Room', 'Why does capacity go unused while patients wait?', 'H+S+A', 'P2', 'queue-to-assignment', 'waitlist-entry-created|slot-opened|candidate-matched|offer-sent|offer-accepted|appointment-filled'],
        ['A3', 'Pre-registration and eligibility', 'Encounter/Appointment + Coverage + Verification Task + Staff Assignment', 'Which incomplete steps create check-in delay or downstream denial?', 'A', 'P3', 'request-to-fulfillment', 'pre-registration-started|identity-verified|coverage-checked|exception-routed|eligibility-confirmed|check-in-cleared'],
        ['A4', 'Prior authorization / financial clearance', 'Service Request + Authorization + Payer + Task + Appointment/Case', 'Is delay caused by missing documentation, payer response, or internal rework?', 'S+A', 'P2', 'exception-rework', 'authorization-requested|documentation-submitted|payer-reviewed|information-requested|decision-recorded|case-cleared'],
        ['A5', 'Direct-admission intake', 'Admission Request + Encounter + Accepting Service + Bed/Unit + Transport', 'How long from acceptance to an operational destination?', 'S+A', 'P1', 'movement-and-occupancy', 'admission-requested|service-accepted|placement-requested|bed-assigned|transport-completed|physical-occupancy-started'],
        ['A6', 'ED end-to-end journey', 'Encounter + Patient + Orders + Results + Staff Assignments + Locations', 'Which variants and shared resources drive ED length of stay?', 'H', 'P1', 'request-to-fulfillment', 'patient-arrived|triage-completed|provider-evaluation-completed|orders-resulted|disposition-decided|encounter-departed'],
        ['A7', 'Ambulance offload', 'EMS Run + Encounter + Ambulance + ED Bay + Staff Assignment', 'Which object is unavailable when offload stalls?', 'S+A', 'P2', 'readiness-synchronization', 'ambulance-arrived|handoff-requested|ed-bay-readied|staff-assignment-confirmed|handoff-accepted|offload-completed'],
        ['A8', 'ED admission and boarding', 'Encounter + Admit Decision + Placement Request + Bed Stay + Unit', 'Is boarding waiting on decision, bed, EVS, staffing, transport, or capability?', 'H+S+A', 'P1', 'movement-and-occupancy', 'admit-decision-recorded|placement-requested|bed-assigned|bed-ready|departed-origin|physical-occupancy-started'],
        ['A9', 'Transfer-center intake and acceptance', 'Transfer Request + Sending Facility + Receiving Facility + Service + Bed + Transport', 'Where do requests decline, queue, or wait after acceptance?', 'S+A', 'P1', 'capability-matching', 'transfer-request-received|capability-screened|clinician-contacted|transfer-accepted|bed-assigned|patient-arrived'],
        ['A10', 'Scheduled arrival/no-show', 'Appointment + Patient + Service + Slot + Reminder/Offer', 'Which access processes lead to no-show, cancellation, or refill?', 'H+A', 'P2', 'exception-rework', 'appointment-scheduled|reminder-sent|attendance-confirmed|patient-arrived|service-started|slot-disposition-recorded'],

        ['B1', 'Bed request and capability matching', 'Placement Request + Encounter + Bed + Unit + Capability + Decision', 'Which hard constraint or policy prevents timely assignment?', 'S+A', 'P1', 'capability-matching', 'placement-requested|requirements-recorded|candidates-ranked|candidate-reviewed|bed-assigned|decision-acknowledged'],
        ['B2', 'Assignment-to-physical occupancy', 'Placement Request + Bed Stay + Encounter + Origin + Destination + Transport', 'How much delay occurs after assignment but before actual occupancy?', 'S+A', 'P1', 'movement-and-occupancy', 'bed-assigned|bed-ready|transport-requested|departed-origin|arrived-destination|physical-occupancy-started'],
        ['B3', 'Bed vacation and turnover', 'Bed Stay + Bed + EVS Task + Staff Assignment + Next Placement', 'Which lifecycle causes dirty-to-ready and ready-to-occupied delay?', 'S+A', 'P1', 'queue-to-assignment', 'patient-physically-left|bed-vacated|cleaning-requested|cleaning-started|bed-ready|next-occupancy-started'],
        ['B4', 'Intra-hospital transfer', 'Transfer Request + Encounter + Origin Unit + Destination Unit + Bed Stay', 'Where does level-of-care progression stall?', 'H+S+A', 'P1', 'movement-and-occupancy', 'transfer-requested|destination-accepted|bed-assigned|departed-origin|arrived-destination|transfer-completed'],
        ['B5', 'ICU downgrade / stepdown', 'Encounter + Downgrade Decision + Placement Request + Bed/Unit + Staff Assignment', 'Is delay clinical, placement, staffing, or transport related?', 'S+A', 'P1', 'readiness-synchronization', 'downgrade-decided|placement-requested|receiving-staff-confirmed|bed-ready|patient-transferred|stepdown-occupancy-started'],
        ['B6', 'Observation-to-inpatient/outpatient status', 'Encounter + Status Review + Authorization + Decision + Bed Stay', 'Which review steps cause status delay, rework, or compliance risk?', 'S+A', 'P2', 'exception-rework', 'status-review-opened|clinical-evidence-reviewed|authorization-checked|status-decided|decision-documented|stay-updated'],
        ['B7', 'Discharge readiness and barriers', 'Encounter + Readiness State + Barrier + Owner + Service Request + Action', 'Which unresolved dependency creates medically-ready delay?', 'H+S+A', 'P1', 'intervention-to-outcome', 'expected-discharge-set|clinical-ready-recorded|barrier-opened|owner-acknowledged|barrier-resolved|patient-physically-departed'],
        ['B8', 'Post-acute placement', 'Encounter + Referral + Post-Acute Facility + Authorization + Transport', 'Is delay availability, acceptance, payer, document, or transport driven?', 'S+A', 'P1', 'capability-matching', 'post-acute-referral-sent|facility-screened|facility-accepted|authorization-completed|transport-booked|patient-arrived'],
        ['B9', 'Discharge medication and departure', 'Encounter + Medication Order + Pharmacy Work + Education/Handoff + Transport', 'What prevents a ready patient from physically leaving?', 'H+S+A', 'P1', 'readiness-synchronization', 'discharge-medications-ordered|pharmacy-verification-completed|medications-ready|education-completed|transport-ready|patient-physically-departed'],
        ['B10', 'RTDC forecast-plan-reconcile', 'Unit + Capacity Forecast + Demand Forecast + Huddle + Action + Outcome', 'Do forecast gaps become owned actions, and are they effective?', 'S+A', 'P1', 'intervention-to-outcome', 'forecast-published|gap-detected|huddle-opened|action-assigned|actual-observed|forecast-reconciled'],
        ['B11', 'House huddle and escalation', 'Huddle + Barrier/Signal + Action + Owner + Unit/Service', 'Which issues recur because actions are late, unowned, or ineffective?', 'S+A', 'P1', 'intervention-to-outcome', 'huddle-opened|signal-reviewed|action-assigned|action-accepted|action-completed|outcome-reviewed'],
        ['B12', 'Surge, diversion, and incident command', 'Facility + Capacity State + Alert + Action + External Facility/EMS', 'Which actions stabilize flow under surge, and at what safety cost?', 'S+A', 'P2', 'intervention-to-outcome', 'surge-detected|incident-command-activated|diversion-decided|actions-coordinated|capacity-stabilized|incident-closed'],

        ['C1', 'Surgical booking and readiness', 'OR Case + Patient/Encounter + Appointment + Required Test/Clearance + Authorization', 'Which prerequisite makes a case non-ready or cancelled?', 'H+D+A', 'P1', 'readiness-synchronization', 'case-booked|requirements-recorded|tests-completed|authorization-completed|case-ready|booking-confirmed'],
        ['C2', 'Block allocation, release, and fill', 'Block + OR Suite + Service + Case + Staff Assignment', 'Is access limited by allocation, late release, fill, or staffing?', 'H+A', 'P2', 'queue-to-assignment', 'block-allocated|release-threshold-reached|block-released|candidate-case-matched|staffing-confirmed|slot-filled'],
        ['C3', 'First-case on-time start', 'OR Case + OR Suite + Patient + Team Assignment + Equipment/Tray', 'Which object is last ready at scheduled start?', 'H+A', 'P1', 'readiness-synchronization', 'case-scheduled|patient-ready|team-ready|room-ready|tray-ready|procedure-started'],
        ['C4', 'Day-of-surgery flow', 'OR Case + Pre-op Bay + OR Suite + PACU Bay + Transport', 'Where do patient, room, team, and equipment lifecycles desynchronize?', 'H+D+A', 'P1', 'movement-and-occupancy', 'preop-arrival-recorded|case-ready|wheels-in-recorded|procedure-completed|pacu-arrival-recorded|pacu-departure-recorded'],
        ['C5', 'Intraoperative phase and handoff', 'OR Case + Team + OR Suite + Handoff + Device/Tray', 'Which phase or handoff creates delay, rework, or risk?', 'H+D+A', 'P1', 'handoff', 'sign-in-completed|procedure-started|intraoperative-handoff-initiated|handoff-acknowledged|procedure-ended|sign-out-completed'],
        ['C6', 'Surgical-safety checklist conformance', 'OR Case + Checklist Instance + Team + Reference Model', 'Which required step is missing, late, repeated, or exception-approved?', 'H+S+A', 'P1', 'exception-rework', 'checklist-instantiated|sign-in-completed|time-out-completed|sign-out-completed|exceptions-reviewed|conformance-run-completed'],
        ['C7', 'OR room turnover', 'OR Suite + Prior Case + EVS Task + Next Case + Staff/Equipment', 'Is turnover waiting on cleaning, setup, staff, equipment, or patient?', 'H+A', 'P1', 'readiness-synchronization', 'wheels-out-recorded|turnover-started|cleaning-completed|room-setup-completed|room-ready|next-case-wheels-in-recorded'],
        ['C8', 'PACU and downstream capacity', 'OR Case + PACU Bay + Staff Assignment + Inpatient/ICU Bed + Transport', 'Do cases pool because recovery, staffing, transport, or destination is late?', 'D+H+A', 'P1', 'readiness-synchronization', 'pacu-arrival-recorded|recovery-completed|pacu-discharge-ready|destination-ready|transport-completed|pacu-departure-recorded'],
        ['C9', 'Cancellation and rework', 'OR Case + Cancellation/Exception + Prerequisite + Slot + Replacement Case', 'Which upstream failures waste capacity and how often is it recovered?', 'D+H+A', 'P1', 'exception-rework', 'case-exception-detected|cancellation-decided|slot-released|replacement-case-matched|replacement-case-confirmed|capacity-recovered'],
        ['C10', 'Sterile processing / instrument trays', 'Case + Tray/Set + Sterilization Cycle + Sterile Processor + Transport', 'Do missing, late, incomplete, or reprocessed trays delay cases?', 'S+A', 'P2', 'split-merge-batch', 'tray-requested|tray-assembled|sterilization-completed|tray-released|tray-delivered|tray-consumed'],
        ['C11', 'Cath/EP/IR/endoscopy flow', 'Procedure Case + Room + Team + Device/Supply + Recovery Bay + Bed', 'Which shared resource governs throughput?', 'H+A', 'P2', 'readiness-synchronization', 'case-scheduled|prerequisites-completed|room-team-ready|procedure-started|recovery-completed|case-departed'],
        ['C12', 'Procedure-to-inpatient dependency', 'Procedure Case + Encounter + PACU/Recovery + Bed/Unit + Transport', 'How much elective work is delayed by downstream hospital capacity?', 'D+H+A', 'P1', 'readiness-synchronization', 'procedure-booked|downstream-bed-forecasted|procedure-completed|recovery-ready|bed-ready|inpatient-occupancy-started'],

        ['D1', 'Lab order-to-result', 'Service Request + Specimen + Test + Analyzer + Result + Encounter', 'Is turnaround driven by collection, transport, accession, queue, analyzer, validation, or notification?', 'H+S+A', 'P1', 'request-to-fulfillment', 'test-ordered|specimen-collected|specimen-accessioned|analysis-completed|result-validated|result-notified'],
        ['D2', 'Specimen split/merge/recollection', 'Parent Specimen + Aliquot + Tests + Analyzer + Recollection Request', 'Where do batching, add-ons, insufficient samples, or recollection create delay?', 'H+A', 'P2', 'split-merge-batch', 'specimen-collected|specimen-split|aliquots-routed|quality-exception-detected|recollection-completed|results-merged'],
        ['D3', 'Critical-result communication', 'Result + Encounter + Responsible Team + Communication Task + Acknowledgment', 'Are critical results acknowledged and acted upon reliably?', 'S+A', 'P2', 'handoff', 'critical-result-validated|notification-task-created|responsible-team-contacted|result-acknowledged|action-recorded|communication-closed'],
        ['D4', 'Blood product lifecycle', 'Order + Blood Product Unit + Patient/Encounter + Cooler/Location + Transfusion', 'Where are units allocated, delayed, returned, expired, or wasted?', 'H+S+A', 'P2', 'movement-and-occupancy', 'blood-product-ordered|unit-allocated|unit-issued|unit-delivered|transfusion-completed|unit-disposition-recorded'],
        ['D5', 'Imaging order-to-final report', 'Service Request + Appointment/Queue + Imaging Study + Scanner + Radiologist Assignment', 'Is delay protocol, scheduling, transport, acquisition, interpretation, or signoff?', 'H+S+A', 'P1', 'request-to-fulfillment', 'imaging-ordered|protocol-completed|study-scheduled|images-acquired|study-interpreted|report-finalized'],
        ['D6', 'Imaging critical finding / result communication', 'Imaging Study + Result + Encounter + Communication Task + Responsible Team', 'Are urgent findings acknowledged and resolved?', 'S+A', 'P2', 'handoff', 'urgent-finding-recorded|communication-task-created|responsible-team-contacted|finding-acknowledged|followup-action-recorded|communication-closed'],
        ['D7', 'Pathology specimen-to-diagnosis', 'Specimen + Accession/Case + Block/Slide + Test + Pathologist Assignment + Report', 'Which processing, batching, referral, or review step drives turnaround?', 'H+A', 'P2', 'split-merge-batch', 'specimen-received|case-accessioned|blocks-prepared|slides-reviewed|diagnosis-recorded|report-finalized'],
        ['D8', 'Specialty consult', 'Consult Request + Encounter + Consulting Service + Assignment + Recommendation', 'Is delay in request quality, acceptance, staffing, evaluation, or decision?', 'H+S+A', 'P1', 'request-to-fulfillment', 'consult-requested|request-triaged|consult-accepted|consultant-assigned|evaluation-completed|recommendation-recorded'],
        ['D9', 'PT/OT/SLP therapy clearance', 'Therapy Request + Encounter + Therapist Assignment + Session + Disposition Barrier', 'Which evaluation or equipment dependency blocks progression?', 'H+A', 'P1', 'request-to-fulfillment', 'therapy-requested|request-triaged|therapist-assigned|evaluation-completed|equipment-readied|clearance-recorded'],
        ['D10', 'Respiratory therapy / device workflow', 'Order + Encounter + Device + Therapist Assignment + Treatment', 'Are treatments delayed by device, staffing, location, or order changes?', 'A', 'P2', 'request-to-fulfillment', 'therapy-ordered|device-allocated|therapist-assigned|treatment-started|treatment-completed|device-released'],
        ['D11', 'Medication order-to-administration', 'Medication Order + Pharmacy Work + Medication/Dose + Encounter + Nurse Assignment', 'Where do verification, preparation, delivery, or administration delays occur?', 'H+S+A', 'P2', 'request-to-fulfillment', 'medication-ordered|order-verified|dose-prepared|dose-delivered|dose-administered|administration-documented'],
        ['D12', 'Discharge medication / medication reconciliation', 'Medication Order + Pharmacy Work + Encounter + Education Task + Caregiver/Transport', 'Which object prevents safe, timely discharge?', 'H+S+A', 'P1', 'readiness-synchronization', 'medications-reconciled|discharge-orders-verified|medications-prepared|education-completed|medications-handed-off|departure-cleared'],
        ['D13', 'High-cost/specialty medication authorization', 'Medication Order + Authorization + Pharmacy Work + Payer + Encounter', 'Where does clinical or payer documentation create delay?', 'S+A', 'P2', 'exception-rework', 'specialty-medication-ordered|authorization-requested|documentation-submitted|payer-decision-recorded|medication-prepared|therapy-started'],
        ['D14', 'Nutrition/dietary fulfillment', 'Nutrition Order + Meal/Feed Task + Encounter + Unit + Delivery', 'Which order changes, production, or delivery issues cause misses?', 'A', 'P3', 'request-to-fulfillment', 'nutrition-ordered|diet-validated|meal-produced|delivery-dispatched|meal-delivered|fulfillment-confirmed'],

        ['E1', 'Internal patient transport', 'Transport Job + Encounter + Origin + Destination + Assignment + Vehicle', 'Is delay request quality, dispatch, resource arrival, patient readiness, or destination readiness?', 'H+S+A', 'P1', 'movement-and-occupancy', 'transport-requested|transport-accepted|transporter-assigned|arrived-origin|patient-picked-up|handoff-completed'],
        ['E2', 'External discharge/medical transport', 'Transport Job + Encounter + Vendor/Vehicle + Authorization + Destination', 'Which booking, payer, vendor, or readiness step delays departure?', 'S+A', 'P1', 'request-to-fulfillment', 'external-transport-requested|authorization-completed|vendor-accepted|vehicle-arrived|patient-departed|destination-arrival-confirmed'],
        ['E3', 'EVS task dispatch and execution', 'EVS Task + Room/Bed + Staff Assignment + Supply/Equipment + Placement', 'Is dirty-to-ready delay dispatch, travel, cleaning, inspection, or blockage?', 'S+A', 'P1', 'queue-to-assignment', 'cleaning-requested|task-dispatched|staff-arrived|cleaning-started|inspection-completed|bed-ready'],
        ['E4', 'Staffing plan-to-deployment', 'Shift + Staff Assignment + Unit + Role/Capability + Demand State', 'Are scheduled staff present, deployable, skill-matched, and aligned to demand?', 'S+A', 'P1', 'capability-matching', 'staffing-plan-published|shift-confirmed|staff-clocked-in|capability-matched|staff-deployed|deployment-reconciled'],
        ['E5', 'Workload and task distribution', 'Task + Staff Assignment + Encounter/Location + Queue', 'Are workload, reassignments, interruptions, and overdue work uneven?', 'H+A', 'P2', 'queue-to-assignment', 'task-created|task-prioritized|task-assigned|task-accepted|task-completed|workload-reconciled'],
        ['E6', 'Handoff/collaboration network', 'Handoff + Sending Assignment/Team + Receiving Assignment/Team + Encounter', 'Where do acknowledgments, clarification, or rework cluster?', 'H+A', 'P2', 'handoff', 'handoff-initiated|information-sent|handoff-acknowledged|clarification-requested|responsibility-accepted|handoff-completed'],
        ['E7', 'Equipment/asset request and use', 'Asset Request + Device/Asset + Encounter/Location + Transport/Maintenance Task', 'Is care waiting on locating, cleaning, charging, transport, or maintenance?', 'S+A', 'P2', 'request-to-fulfillment', 'asset-requested|asset-located|asset-readied|asset-delivered|asset-used|asset-returned'],
        ['E8', 'Supply replenishment and stockout', 'Supply Item/Lot + Location + Replenishment Task + Procedure/Encounter', 'Which stockouts or substitutions disrupt work?', 'S+A', 'P2', 'request-to-fulfillment', 'reorder-threshold-reached|replenishment-requested|stockout-detected|substitution-approved|stock-delivered|inventory-reconciled'],
        ['E9', 'Pharmacy/material delivery logistics', 'Delivery Job + Medication/Supply + Origin + Destination + Robot/Staff', 'Which route, batch, handoff, or destination creates delay?', 'A', 'P3', 'split-merge-batch', 'delivery-job-created|items-batched|carrier-assigned|route-started|items-delivered|delivery-acknowledged'],
        ['E10', 'Facilities maintenance', 'Work Order + Asset/Room + Technician Assignment + Part + Operational Impact', 'Which failure and repair paths remove clinical capacity?', 'A', 'P2', 'exception-rework', 'failure-reported|work-order-triaged|technician-assigned|repair-started|repair-completed|capacity-restored'],
        ['E11', 'Environmental isolation/terminal cleaning', 'Encounter + Room + Isolation Requirement + EVS Task + Infection-Control Clearance', 'How do enhanced cleaning and clearance affect capacity?', 'S+A', 'P2', 'readiness-synchronization', 'isolation-requirement-recorded|terminal-cleaning-requested|cleaning-started|cleaning-completed|clearance-approved|room-released'],
        ['E12', 'Waste/linen/material reverse logistics', 'Collection Task + Container/Item + Location + Staff/Vehicle', 'Where do accumulation, pickup, or return cycles disrupt operations?', 'A', 'P3', 'queue-to-assignment', 'collection-threshold-reached|collection-task-created|carrier-assigned|items-collected|items-delivered|cycle-reconciled'],

        ['F1', 'Sepsis time-critical pathway', 'Pathway Instance + Encounter + Orders + Specimens/Results + Medications + Team', 'Which required step is missing, late, out of order, or exception-justified?', 'H+S+A', 'P2', 'exception-rework', 'sepsis-recognized|lactate-ordered|cultures-collected|antibiotics-administered|fluids-administered|bundle-reviewed'],
        ['F2', 'Stroke pathway', 'Pathway Instance + Encounter + Imaging + Result + Medication/Procedure + Team', 'Where do door-to-imaging/read/decision delays occur?', 'H+S+A', 'P2', 'readiness-synchronization', 'stroke-alert-activated|nihss-completed|ct-ordered|ct-completed|ct-interpreted|treatment-decided'],
        ['F3', 'STEMI/trauma/other time-critical pathway', 'Pathway Instance + Encounter + Team + Procedure/Room + Transport', 'Which interdependent resource causes time-critical delay?', 'H+S+A', 'P2', 'readiness-synchronization', 'time-critical-alert-activated|team-activated|diagnostic-confirmed|procedure-room-readied|patient-transported|definitive-treatment-started'],
        ['F4', 'Boarding safety and reassessment', 'Boarded Encounter + Location + Staff Assignment + Medication/Task + Alert', 'Are reassessment, medication, monitoring, and escalation reliable while boarding?', 'S+A', 'P1', 'exception-rework', 'boarding-started|reassessment-scheduled|reassessment-completed|medications-administered|risk-escalated|boarding-ended'],
        ['F5', 'Rapid response / escalation', 'Encounter + Signal + Alert + Team Activation + Intervention + Transfer', 'Is detection, activation, team arrival, intervention, or destination late?', 'S+A', 'P2', 'intervention-to-outcome', 'deterioration-detected|rapid-response-activated|team-arrived|intervention-started|transfer-decided|patient-transferred'],
        ['F6', 'Infection prevention bundle', 'Encounter + Device/Procedure + Staff/Team + Checklist + Observation/Result', 'Which bundle elements or device-duration processes deviate?', 'S+A', 'P2', 'exception-rework', 'bundle-instantiated|device-necessity-reviewed|bundle-elements-completed|observation-recorded|deviation-reviewed|bundle-closed'],
        ['F7', 'Isolation placement and exposure', 'Encounter + Condition/Requirement + Room/Bed + Contact/Staff Assignment + EVS', 'How long until appropriate placement and clearance?', 'S+A', 'P2', 'movement-and-occupancy', 'isolation-required|placement-requested|isolation-room-assigned|exposure-assessed|terminal-cleaning-completed|isolation-cleared'],
        ['F8', 'Medication safety event', 'Medication Order + Pharmacy Work + Dose/Admin + Encounter + Incident', 'Which upstream path and handoff precede an error or near miss?', 'H+S+A', 'P2', 'exception-rework', 'medication-ordered|order-verified|dose-prepared|dose-administered|safety-event-detected|incident-reviewed'],
        ['F9', 'Falls/pressure injury prevention process', 'Encounter + Risk Assessment + Intervention Task + Staff Assignment + Incident', 'Were preventive processes completed and sustained?', 'S+A', 'P3', 'intervention-to-outcome', 'risk-assessed|prevention-plan-created|tasks-assigned|interventions-completed|risk-reassessed|outcome-reviewed'],
        ['F10', 'Incident-to-CAPA', 'Incident + Investigation + Finding + Action + Owner + Metric', 'Do investigations produce timely, completed, effective corrective actions?', 'S+A', 'P2', 'intervention-to-outcome', 'incident-reported|investigation-opened|finding-confirmed|corrective-action-assigned|action-completed|effectiveness-reviewed'],
        ['F11', 'Readmission/cross-encounter transition', 'Patient + Prior Encounter + Follow-up + Medication/Handoff + New Encounter', 'Which transition patterns are associated with return, without claiming causality?', 'H+A', 'P2', 'handoff', 'prior-encounter-discharged|transition-handoff-completed|followup-scheduled|medications-reconciled|new-encounter-started|return-reviewed'],
        ['F12', 'Equity in access and flow', 'Encounter/Request + Service/Facility + Operational Process + Governed Demographics', 'Do delays or variants differ across groups after missingness and small-cell controls?', 'S+A', 'P2', 'data-correction', 'cohort-defined|data-completeness-checked|small-cells-suppressed|flow-measures-computed|differences-reviewed|equity-action-recorded'],
        ['F13', 'Measure-evidence lineage', 'Metric Definition + Source Event/Result + Computation + Review + Published Measure', 'Can every reported measure be reproduced and versioned?', 'S+A', 'P1', 'data-correction', 'metric-defined|source-events-linked|measure-computed|quality-check-completed|measure-reviewed|measure-published'],

        ['G1', 'Utilization review / continued stay', 'Encounter + Review + Authorization + Payer + Documentation Task + Decision', 'Which documentation, review, or payer loop delays status/disposition?', 'S+A', 'P2', 'exception-rework', 'review-due|documentation-assembled|review-submitted|payer-response-received|status-decided|decision-communicated'],
        ['G2', 'Charge capture and coding', 'Encounter/Procedure + Charge Item + Documentation + Coder Task + Claim', 'Where are charges delayed, missing, corrected, or rejected?', 'A', 'P3', 'exception-rework', 'service-completed|charge-captured|documentation-validated|coding-completed|edits-resolved|claim-released'],
        ['G3', 'Claim submission and denial', 'Claim + Encounter + Payer + Denial + Appeal Task + Payment', 'Which upstream process patterns generate rework and preventable denial?', 'A', 'P3', 'exception-rework', 'claim-created|claim-validated|claim-submitted|payer-adjudicated|denial-appealed|payment-reconciled'],
        ['G4', 'Patient financial assistance/counseling', 'Encounter + Coverage + Application + Task + Decision', 'Where do incomplete applications or handoffs create delay?', 'A', 'P3', 'request-to-fulfillment', 'assistance-requested|eligibility-screened|application-submitted|documents-verified|decision-recorded|counseling-completed'],
        ['G5', 'Credentialing and privileging', 'Practitioner + Credential + Privilege + Review Task + Facility/Service', 'Which dependencies delay safe deployment of workforce capacity?', 'A', 'P3', 'request-to-fulfillment', 'application-received|credentials-verified|primary-source-checks-completed|committee-reviewed|privileges-approved|practitioner-deployed'],
        ['G6', 'Hiring/onboarding/education compliance', 'Employee + Position + Credential/Training + Task + Assignment', 'Where does onboarding stall or require rework?', 'A', 'P3', 'request-to-fulfillment', 'candidate-accepted|onboarding-started|credentials-verified|training-completed|assignment-approved|employee-deployed'],
        ['G7', 'Procurement-to-pay', 'Requisition + Purchase Order + Item + Shipment + Invoice + Facility', 'Which supply dependencies threaten operations?', 'S+A', 'P3', 'request-to-fulfillment', 'requisition-approved|purchase-order-issued|shipment-dispatched|goods-received|invoice-matched|payment-released'],
        ['G8', 'Multi-facility transfer/load balancing', 'Transfer Request + Sending/Receiving Facility + Capability + Bed + Transport', 'Which geography/capability edge limits network access?', 'S+A', 'P1', 'capability-matching', 'network-request-received|facilities-ranked|capability-confirmed|transfer-accepted|transport-completed|arrival-reconciled'],
        ['G9', 'Service-line longitudinal journey', 'Patient/Episode + Encounters + Facilities + Service Line + Referrals/Procedures', 'Where does the network lose continuity or create avoidable travel/wait?', 'H+A', 'P2', 'handoff', 'episode-opened|referral-accepted|encounter-completed|procedure-completed|care-transitioned|episode-reviewed'],
        ['G10', 'Ambulatory-inpatient transition', 'Patient/Episode + Appointment + Encounter + Admission/Discharge + Handoff', 'Are follow-up and escalation pathways connected?', 'H+A', 'P2', 'handoff', 'ambulatory-encounter-completed|escalation-decided|admission-completed|inpatient-care-completed|discharge-handoff-completed|followup-completed'],
        ['G11', 'Data-feed and application reliability', 'Source System + Interface/Feed + Event Batch + DQ Finding + Incident + Metric', 'Which technology failure distorts operational truth or delays work?', 'S+A', 'P1', 'data-correction', 'batch-received|batch-validated|quality-finding-opened|incident-escalated|feed-recovered|data-replayed'],
        ['G12', 'Downtime and recovery', 'Downtime Incident + Application/Device + Department + Manual Workaround + Recovery Task', 'How do outages alter work, safety exposure, and recovery?', 'S+A', 'P2', 'exception-rework', 'downtime-detected|incident-declared|workaround-activated|recovery-started|service-restored|backlog-reconciled'],

        ['H1', 'Signal-to-alert lifecycle', 'Metric/Signal + Alert + Unit/Service + Owner', 'Are signals acknowledged, suppressed, escalated, and cleared appropriately?', 'S+A', 'P1', 'intervention-to-outcome', 'signal-detected|alert-opened|owner-acknowledged|alert-escalated|condition-cleared|alert-closed'],
        ['H2', 'Recommendation-to-action', 'Recommendation + Approval + Operational Action + Owner + Affected Objects', 'Which recommendations become timely work, and where do they fail?', 'S+A', 'P1', 'intervention-to-outcome', 'recommendation-drafted|recommendation-reviewed|recommendation-approved|action-assigned|action-completed|outcome-reviewed'],
        ['H3', 'Huddle action closure', 'Huddle + Action + Barrier + Owner + Unit/Encounter', 'Do huddles resolve constraints or repeatedly rediscover them?', 'S+A', 'P1', 'intervention-to-outcome', 'huddle-opened|barrier-reviewed|action-assigned|owner-acknowledged|action-completed|barrier-reassessed'],
        ['H4', 'PDSA/intervention lifecycle', 'Opportunity + PDSA + Intervention + Cohort/Scope + Metric + Outcome Review', 'Was the change implemented, sustained, adapted, or abandoned?', 'S+A', 'P1', 'intervention-to-outcome', 'opportunity-detected|pdsa-planned|intervention-started|measure-observed|outcome-reviewed|disposition-decided'],
        ['H5', 'Reference-model governance', 'Policy/Guideline Version + Process Model + Approver + Conformance Run', 'Which standard version generated a deviation, and is it current?', 'S+A', 'P1', 'data-correction', 'reference-version-drafted|model-validated|model-approved|model-published|conformance-run-completed|version-reviewed'],
        ['H6', 'Model-review and publication', 'Discovered Model + Evidence Window + Reviewer + Approval + Published View', 'Is a map valid, interpretable, and approved for use?', 'A', 'P1', 'data-correction', 'model-discovered|evidence-window-frozen|fitness-validated|review-completed|publication-approved|view-published'],
        ['H7', 'Data-quality issue-to-correction', 'DQ Finding + Source + Mapping/Rule + Owner + Correction + Replay', 'Are defects detected, repaired, and prevented?', 'S+A', 'P0', 'data-correction', 'quality-finding-opened|finding-triaged|owner-assigned|mapping-corrected|data-replayed|finding-closed'],
        ['H8', 'Forecast/recommendation reconciliation', 'Forecast + Recommendation + Action + Actual Outcome + Review', 'Was a prediction accurate and was advice adopted?', 'S+A', 'P1', 'intervention-to-outcome', 'forecast-published|recommendation-issued|action-decided|action-completed|actual-observed|recommendation-reconciled'],
    ];

    /** @return array<string, string> */
    public static function domains(): array
    {
        return self::DOMAINS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function models(): array
    {
        return array_map(function (array $row): array {
            [$id, $name, $core, $question, $evidence, $priority, $pattern, $sequence] = $row;
            $domainCode = $id[0];
            $objects = array_values(array_filter(array_map('trim', explode('+', $core))));
            $steps = array_values(array_filter(explode('|', $sequence)));
            $readiness = self::readiness($id);

            return [
                'process_id' => $id,
                'process_number' => (int) substr($id, 1),
                'domain_code' => $domainCode,
                'domain_name' => self::DOMAINS[$domainCode],
                'name' => $name,
                'core_interaction' => $core,
                'core_objects' => $objects,
                'improvement_question' => $question,
                'evidence_grade' => $evidence,
                'priority' => $priority,
                'interaction_pattern' => $pattern,
                'implementation_wave' => self::implementationWave($id, $priority),
                'current_readiness' => $readiness['status'],
                'readiness_note' => $readiness['note'],
                'source_document' => self::DOCUMENT_ID,
                'catalog_version' => self::CATALOG_VERSION,
                'nodes' => self::nodes($id, $steps, $objects),
                'edges' => self::edges($id, $steps, $pattern),
            ];
        }, self::ROWS);
    }

    /** @return array<string, mixed>|null */
    public static function find(string $processId): ?array
    {
        $processId = strtoupper(trim($processId));

        foreach (self::models() as $model) {
            if ($model['process_id'] === $processId) {
                return $model;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $steps
     * @param  array<int, string>  $objects
     * @return array<int, array<string, mixed>>
     */
    private static function nodes(string $processId, array $steps, array $objects): array
    {
        $last = count($steps) - 1;

        return array_map(function (string $activity, int $index) use ($processId, $objects, $last): array {
            return [
                'node_key' => sprintf('%s-%02d-%s', strtolower($processId), $index + 1, Str::slug($activity)),
                'activity' => $activity,
                'label' => Str::headline($activity),
                'node_kind' => self::nodeKind($activity, $index, $last),
                'ordinal' => $index + 1,
                'object_types' => self::objectsForStep($activity, $objects, $index),
                'required' => true,
                'source_basis' => self::DOCUMENT_ID.' seeded reference',
                'metadata' => [
                    'reference_model' => true,
                    'observed_claim' => false,
                    'catalog_version' => self::CATALOG_VERSION,
                ],
            ];
        }, $steps, array_keys($steps));
    }

    /**
     * @param  array<int, string>  $steps
     * @return array<int, array<string, mixed>>
     */
    private static function edges(string $processId, array $steps, string $pattern): array
    {
        $edges = [];
        for ($index = 0; $index < count($steps) - 1; $index++) {
            $source = sprintf('%s-%02d-%s', strtolower($processId), $index + 1, Str::slug($steps[$index]));
            $target = sprintf('%s-%02d-%s', strtolower($processId), $index + 2, Str::slug($steps[$index + 1]));
            $edges[] = [
                'edge_key' => sprintf('%s-edge-%02d', strtolower($processId), $index + 1),
                'source_node_key' => $source,
                'target_node_key' => $target,
                'label' => self::edgeLabel($pattern),
                'relationship_type' => $pattern,
                'ordinal' => $index + 1,
                'is_exception' => self::nodeKind($steps[$index + 1], $index + 1, count($steps) - 1) === 'exception',
                'metadata' => [
                    'reference_model' => true,
                    'observed_claim' => false,
                ],
            ];
        }

        return $edges;
    }

    /** @return array{status: string, note: string} */
    private static function readiness(string $id): array
    {
        $overrides = [
            'A8' => 'Flow events exist, but placement is not an object and assignment is currently treated as occupancy.',
            'B1' => 'Bed events exist, but Placement Request, capability candidates, and a governed Decision lifecycle are not projected.',
            'B2' => 'Assignment, readiness, departure, arrival, and physical occupancy are not yet distinct canonical facts.',
            'B3' => 'Bed vacancy is projected from a discharge proxy; EVS Task and dirty-to-ready states are not emitted.',
            'B4' => 'Transfers are emitted with a timeless encounter-to-bed relationship rather than a temporal Bed Stay.',
            'C6' => 'Checklist milestones are emitted, but conformance is batch rule checking rather than formal object-centric alignment.',
            'C8' => 'Recovery is currently attached to the OR suite; PACU Stay/Bay and downstream occupancy are absent.',
            'C12' => 'OR phase data exists, but PACU, Bed Stay, and downstream capacity synchronization are absent.',
            'E1' => 'Transport phases are emitted, but dispatch/assignment is currently labeled as pickup and must remain a proxy.',
            'F1' => 'Seeded sepsis events are projected; Pathway Instance, Request, Specimen, Result, and Team objects are not.',
            'F2' => 'Seeded stroke events are projected; Imaging Study, Result, Team, and explicit decision objects are not.',
            'H7' => 'P0 prerequisite: no end-to-end DQ Finding, correction, supersession, and replay lifecycle is projected.',
        ];

        if (in_array($id, self::PARTIAL_PROJECTION, true)) {
            return [
                'status' => 'partial_projection',
                'note' => $overrides[$id] ?? 'The current OCEL log contains a subset of this lifecycle, without a validated bounded view.',
            ];
        }

        if (in_array($id, self::SOURCE_PRESENT, true)) {
            return [
                'status' => 'source_present_not_projected',
                'note' => $overrides[$id] ?? 'Zephyrus has an operational table or deterministic seed seam, but it is not emitted as this governed OCEL model.',
            ];
        }

        return [
            'status' => 'reference_only',
            'note' => $overrides[$id] ?? 'The bounded reference flow is modeled, but an authoritative source contract and OCEL emission are still required.',
        ];
    }

    private static function implementationWave(string $id, string $priority): string
    {
        if ($id === 'H7') {
            return 'foundation';
        }

        $waveOne = ['A8', 'B1', 'B2', 'B3', 'B7', 'E1', 'H1', 'H2', 'H3', 'H4'];
        if (in_array($id, $waveOne, true)) {
            return 'wave_1';
        }

        return match ($priority) {
            'P0' => 'foundation',
            'P1' => 'wave_2',
            'P2' => 'wave_3',
            default => 'wave_4',
        };
    }

    private static function nodeKind(string $activity, int $index, int $last): string
    {
        if ($index === 0) {
            return 'trigger';
        }
        if ($index === $last) {
            return 'outcome';
        }
        if (preg_match('/(cancel|declin|deni|exception|fail|blocked|stockout|incident|finding-opened)/', $activity)) {
            return 'exception';
        }
        if (preg_match('/(decid|approv|accept|assign|review|screen|match|validat|confirm)/', $activity)) {
            return 'decision';
        }

        return 'event';
    }

    /**
     * @param  array<int, string>  $objects
     * @return array<int, string>
     */
    private static function objectsForStep(string $activity, array $objects, int $index): array
    {
        if ($objects === []) {
            return [];
        }

        $activityWords = array_filter(explode(' ', str_replace('-', ' ', strtolower($activity))), fn (string $word) => strlen($word) > 3);
        $matched = [];
        foreach ($objects as $object) {
            $objectWords = array_filter(preg_split('/[^a-z0-9]+/', strtolower($object)) ?: [], fn (string $word) => strlen($word) > 3);
            if (array_intersect($activityWords, $objectWords) !== []) {
                $matched[] = $object;
            }
        }

        // Every event remains anchored to the model's primary lifecycle object;
        // then add a rotating collision object so the map visibly stays OCEL.
        array_unshift($matched, $objects[0]);
        $matched[] = $objects[($index + 1) % count($objects)];

        return array_slice(array_values(array_unique($matched)), 0, 3);
    }

    private static function edgeLabel(string $pattern): string
    {
        return match ($pattern) {
            'queue-to-assignment' => 'queued / assigned',
            'readiness-synchronization' => 'synchronizes',
            'movement-and-occupancy' => 'moves / occupies',
            'handoff' => 'hands off',
            'split-merge-batch' => 'splits / merges',
            'exception-rework' => 'checks / reworks',
            'capability-matching' => 'matches',
            'intervention-to-outcome' => 'acts / measures',
            'data-correction' => 'validates / corrects',
            default => 'advances',
        };
    }
}
