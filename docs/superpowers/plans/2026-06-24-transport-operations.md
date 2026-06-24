# Transport Operations Section Plan

Date: 2026-06-24

## Purpose

Transport becomes a first-class Zephyrus domain for monitoring and conducting movement across inpatient transport, interfacility transfer, discharge transportation, EMS handoff, and care transitions. It is intentionally broader than the existing perioperative `CaseTransport` model, which remains scoped to OR case movement.

The section should let an operator request, triage, assign, dispatch, track, escalate, complete, and analyze movement. The design uses a canonical event stream so Zephyrus can integrate with EHR, patient-flow, NEMT, EMS, and post-acute networks without letting any single vendor define the internal operational model.

## Research Summary

### Inpatient Patient Flow And Transport

Relevant systems:

- Epic Hospital Patient Flow: facility transfer, bed planning, and patient movement coordination.
- Oracle Health Systems Operations: near-real-time bed management, patient movement, capacity, and transfer-center visibility.
- TeleTracking: operational platform focused on patient flow, automation, enterprise visibility, and capacity.
- ABOUT Healthcare: connected network of care for moving patients into, through, and out of acute care.

Integration posture:

- Prefer HL7 v2 ADT movement events and FHIR `Encounter`, `Location`, `ServiceRequest`, and `Task`.
- Expect customer-specific interface work for legacy patient-flow products.
- Zephyrus should own local SLA, readiness, delay, and assignment state even when an EHR or patient-flow tool is the system of record for encounter location.

### Interfacility Transfer

Relevant systems:

- Epic Transfer Center / Grand Central style workflows.
- Oracle Health Transfer Center / capacity operations.
- ABOUT Healthcare.
- TeleTracking.

Operational capabilities:

- Referring facility and receiving facility tracking.
- Accepting service, bed readiness, clinical acuity, transport mode, and ETA.
- Handoff packet status and receiving-unit acceptance.
- Escalation when bed, authorization, crew, or documentation blocks movement.

### Discharge And NEMT

Relevant systems:

- Ride Health.
- Uber Health.
- Lyft Healthcare and Lyft Concierge API.
- Contracted ambulance and regional NEMT brokers.

Operational capabilities:

- Quote, book, cancel, and track ride status.
- Match mode to patient constraints: ambulatory, wheelchair, stretcher, bariatric, oxygen, BLS, ALS, critical care.
- Minimize PHI sent to transportation vendors.
- Track discharge lounge readiness and late-ride effect on bed release.

### Care Transitions And Post-Acute Referral

Relevant systems:

- CarePort / WellSky Transition and Connect.
- Aidin.
- Bamboo Health Pings.
- PointClickCare Intelligent Transitions.

Operational capabilities:

- Referral status and post-acute acceptance.
- Authorization and packet readiness.
- ADT event notifications after discharge.
- Direct Secure Messaging and C-CDA packet fallback.
- TEFCA/HIE-ready event and document strategy.

### EMS And Prehospital Handoff

Relevant systems:

- Pulsara.
- TigerConnect / Twiage.
- ESO Health Data Exchange.
- ImageTrend Health Information Hub.

Operational capabilities:

- ETA, ePCR, prehospital alert, image/ECG metadata, and team activation.
- ED receiving-room readiness.
- Handoff completion and outcome feedback.

## Standards Backbone

- FHIR `ServiceRequest`: canonical transport, referral, and ride request.
- FHIR `Task`: execution state, assignment, progress, fulfillment, and cancellation.
- FHIR `Encounter`: care context.
- FHIR `Location`: origin, destination, and physical movement points.
- FHIR `Appointment`: scheduled future movement when applicable.
- FHIR `Communication` / `CommunicationRequest`: handoff and coordination messages.
- FHIR `DocumentReference`: transition packets, discharge summary, ePCR, and external documents.
- HL7 v2 ADT: A01/A02/A03/A08/A12/A13-style encounter and movement events.
- Direct Secure Messaging / C-CDA: fallback transition packet delivery.
- TEFCA/HIE: future cross-network event and document exchange posture.

## Product Surface

Routes added in Phase 1:

- `/dashboard/transport`: command center.
- `/transport/requests`: canonical request queue.
- `/transport/dispatch`: dispatcher workbench.
- `/transport/inpatient`: inpatient transport worklist.
- `/transport/transfers`: interfacility transfer worklist.
- `/transport/discharge`: discharge and NEMT worklist.
- `/transport/ems`: EMS handoff worklist.
- `/transport/care-transitions`: post-acute transition worklist.
- `/transport/resources`: team, equipment, and vendor registry.
- `/transport/analytics`: early analytics scorecard.
- `/transport/settings/integrations`: integration roadmap and connector posture.

## Canonical Data Model

Phase 1 creates:

- `prod.transport_requests`: canonical request/task projection for operator worklists.
- `prod.transport_events`: immutable append-only event ledger.

Important fields:

- `request_type`: `inpatient`, `transfer`, `discharge`, `ems`, `care_transition`.
- `priority`: `routine`, `urgent`, `stat`.
- `status`: requested through assignment, dispatch, pickup, en route, handoff, completion, cancellation, failure, and escalation.
- `origin` / `destination`.
- `transport_mode`: ambulatory, wheelchair, stretcher, bed, rideshare, NEMT, BLS, ALS, critical care, EMS, air, courier.
- `risk_flags`: clinical or operational constraints.
- `handoff`: receiving-team completion details.
- `external_system` / `external_id`: vendor, EHR, HIE, or interface-engine mapping.

Future normalization candidates:

- `transport_segments`.
- `transport_resources`.
- `transport_assignments`.
- `transport_vendor_connections`.
- `transport_documents`.
- `transport_transition_links`.

## API Surface

Phase 1 endpoints:

- `GET /api/transport/overview`
- `GET /api/transport/requests`
- `POST /api/transport/requests`
- `GET /api/transport/requests/{id}`
- `POST /api/transport/requests/{id}/assign`
- `POST /api/transport/requests/{id}/status`
- `POST /api/transport/requests/{id}/cancel`
- `POST /api/transport/requests/{id}/handoff`
- `GET /api/transport/resources`
- `GET /api/transport/vendors`

Future connector endpoints:

- `POST /api/transport/vendors/{id}/quote`
- `POST /api/transport/vendors/{id}/book`
- `POST /api/transport/vendors/{id}/cancel`
- `POST /api/transport/webhooks/{connector}`
- `GET /api/transport/analytics/*`

## Connector Contract

Future connector classes should implement a common contract:

```php
interface TransportConnector
{
    public function capabilities(): array;
    public function createRequest(TransportRequest $request): ExternalTransportReference;
    public function cancelRequest(TransportRequest $request, string $reason): void;
    public function normalizeWebhook(array $payload): TransportEvent;
    public function healthCheck(): ConnectorHealth;
}
```

Connector requirements:

- Idempotency key per external event.
- Signature verification for webhooks.
- Replay protection.
- Minimum-necessary PHI mapping.
- Explicit external-system and external-id storage.
- Local canonical status preserved even when vendor status vocabulary differs.

## Phased Roadmap

### Phase 1: Canonical Command Surface

Delivered in this slice:

- Transport navigation and workflow registration.
- Dashboard, worklists, resources, analytics, and integration pages.
- Database-backed request and event tables.
- Authenticated API for create, assign, status update, cancel, handoff, resources, and vendors.
- Sample request actions so operators can exercise the workflow immediately.

### Phase 2: Inpatient Transport

- ADT/location ingestion.
- Porter/equipment resource model.
- Patient-ready blockers.
- Delay reason taxonomy.
- Bed placement and discharge dependency links with RTDC.

### Phase 3: Discharge And NEMT

- Real connector for selected first vendor.
- Quote/book/cancel/status webhook lifecycle.
- PHI minimization and consent review.
- Discharge lounge synchronization and avoidable bed-hour analytics.

### Phase 4: Transfer Center

- Interfacility acceptance workflow.
- Bed readiness and accepting service.
- Critical-care transport mode selection.
- Handoff packet and receiving-unit completion.

### Phase 5: Care Transitions

- Referral/authorization/packet tracking.
- CarePort/Aidin/Bamboo/PointClickCare connector discovery.
- Direct/C-CDA fallback.
- Post-discharge ADT alerts.

### Phase 6: EMS Handoff

- ETA board.
- ePCR/document reference ingestion.
- Activation workflow.
- Outcome feedback path.

### Phase 7: Optimization

- SLA risk prediction.
- Compatible-move batching.
- Transport mode recommendation.
- Vendor scorecards.
- Delay root-cause analytics.
- Avoidable bed-hours and throughput impact.

## Key Risks

- Vendor APIs require customer-specific contracting and enablement.
- Some care transition systems may only expose portal, Direct, HIE, or interface-engine patterns.
- Rideshare/NEMT workflows require careful PHI minimization.
- ADT and FHIR events can disagree on timing and status; the local event ledger must be authoritative for operational decisions made in Zephyrus.
- Webhook payloads must not be trusted without signature validation and idempotency.

## References

- Epic Hospital Patient Flow: https://www.epic.com/software/hospital-patient-flow/
- Oracle Health Systems Operations: https://www.oracle.com/health/clinical-operations/systems-operations/
- TeleTracking: https://www.teletracking.com/
- ABOUT Healthcare: https://abouthealthcare.com/industry-news/central-logic-integrates-offering-and-rebrands-to-about-signaling-move-to-maximize-a-connected-network-of-care/
- CarePort Transition: https://careporthealth.com/products/transition/
- CarePort Connect: https://careporthealth.com/products/connect/
- CarePort Event Notifications: https://careporthealth.com/interoperability-event-notifications/
- Bamboo Health Pings: https://bamboohealth.com/solutions/pings/
- Aidin: https://www.myaidin.com/
- PointClickCare Intelligent Transitions: https://pointclickcare.com/products/intelligent-transitions/
- Ride Health Platform: https://www.ridehealth.com/platform
- Uber Health API: https://www.uberhealth.com/us/en/api-integration/
- Lyft Healthcare: https://www.lyft.com/healthcare
- Lyft Concierge API: https://help.lyft.com/business/hc/en-us/articles/360001599667-Concierge-API-overview
- Pulsara EMS: https://www.pulsara.com/ems
- TigerConnect / Twiage: https://tigerconnect.com/resources/webinars/introducing-twiage-lp/
- ESO Health Data Exchange: https://www.eso.com/ems/health-data-exchange/
- ImageTrend HIE: https://www.imagetrend.com/platform/health-information-exchange/
- FHIR ServiceRequest: https://build.fhir.org/servicerequest.html
- FHIR Task: https://build.fhir.org/task.html
- FHIR Workflow Communications: https://build.fhir.org/workflow-communications.html
- HL7 ADT A02: https://hl7-definition.caristix.com/v2/HL7v2.8/TriggerEvents/ADT_A02
- Direct Secure Messaging: https://directtrust.org/what-we-do/direct-secure-messaging
- ONC TEFCA: https://healthit.gov/policy/tefca/
- CMS ADT CoP FAQ: https://www.cms.gov/files/document/faqs-interoperability-patient-access-and-cop-event-notifications-may-2021.pdf
