# Zephyrus Real-Time Healthcare Data Acquisition Plan

Date: 2026-06-25
Status: Proposed executable plan
Scope: US hospital operations first, with vendor-neutral seams for non-US or specialty deployments

## 1. Goal

Connect Zephyrus to the transactional healthcare systems required to acquire operational data end to end, in near real time, across Emergency Department, RTDC, perioperative, transport, ancillary, process-improvement, payer, and external-network workflows.

The target is "100% acquisition" in the operational sense: every data element used by a live Zephyrus workflow must have an accountable source system, a primary ingestion method, a fallback or reconciliation method, an owner, an SLA, source lineage, quality checks, and an exception path. It does not mean that arbitrary third-party systems can be read without data-use agreements, vendor enablement, network access, or customer-specific interface work.

## 2. Current Zephyrus Baseline

The repo already has the most important architectural seed: domain consumers do not need to bind directly to EHR payloads. The RTDC design and code define canonical operational events, a dispatcher seam, and projectors that materialize `prod` tables.

Existing surfaces to preserve and extend:

- Laravel/Inertia app with operational domains exposed through `routes/web.php` and `routes/api.php`.
- `prod` read model for operations: `units`, `beds`, `encounters`, `census_snapshots`, `operational_events`, `rtdc_predictions`, `bed_requests`, `bed_placement_decisions`, `ed_visits`, OR tables, transport tables, barriers, huddles, and PDSA cycles.
- `raw` schema with generic source and import tracking.
- `stg` schema with basic validation/transformation tracking.
- `fhir` schema with generic FHIR resource storage and mappings for locations, practitioners, and schedules.
- `star` analytics schema for dimensional projections.
- `app/Rtdc/Events/CanonicalEvent.php`, `app/Rtdc/CensusProjector.php`, and the S2 RTDC design spec establishing the anti-corruption boundary.

Gaps that must be closed before real PHI/transactional feeds:

- No enterprise source registry with tenant, facility, vendor, environment, capability, and BAA/contract metadata.
- No OAuth/SMART credential lifecycle, JWKS rotation, certificate/mTLS tracking, or webhook signature registry.
- No patient, encounter, location, practitioner, order, task, document, and terminology crosswalks across external systems.
- No durable connector watermarks, FHIR subscription state, HL7 ACK state, replay checkpoints, dead-letter workflow, or interface health dashboard.
- No systematic resource-to-projection lineage showing how a Command Center value was sourced.
- Existing `fhir.resources` table is useful but too small for multi-source versioned ingestion at enterprise scale.
- Production PHI readiness must verify SSO/RBAC, no demo auto-auth, audit logging, encryption, log redaction, and minimum-necessary display rules.

## 3. Research Conclusions

### 3.1 Standards Posture

Use a hybrid acquisition strategy. A standards-only plan will not reach full operational coverage in real hospitals because many real-time hospital transactions still arrive through interface engines, HL7 v2 feeds, vendor APIs, SFTP exports, device gateways, or operational SaaS webhooks.

The backbone should be:

- FHIR R4 for certified EHR API reads and vendor-neutral resource representation.
- US Core profiles for US clinical/administrative data access, with versioned profile support because certified systems may expose different US Core versions.
- SMART App Launch and SMART Backend Services for OAuth-based user-facing and system-to-system API access.
- FHIR Bulk Data for historical and population backfills.
- FHIR Subscriptions or R5 Subscription Backport where a source supports event notifications.
- HL7 v2 for ADT, orders, results, scheduling, pharmacy, financial, and high-volume operational events.
- DICOMweb for imaging metadata and imaging object discovery/retrieval.
- X12 and HL7 Da Vinci IGs for eligibility, prior authorization, claims, and payer-provider workflows.
- NCPDP SCRIPT/RTPB for pharmacy benefit, e-prescribing, medication history, and pharmacy-benefit prior authorization contexts.
- Direct/C-CDA, IHE, and TEFCA/QHIN/HIE access for documents and external-network exchange where resource APIs are incomplete.

### 3.2 Version Strategy

Implement version-negotiated adapters, not a single hard-coded standard version.

- Default FHIR client target: R4, because US EHR APIs and US Core remain R4 based.
- Discover actual source capabilities from each FHIR `CapabilityStatement` and SMART `.well-known/smart-configuration`.
- Store `fhir_version`, `us_core_version`, supported resources, supported search params, and supported operations per source.
- Support US Core 6.1.0 where certification/HTI-1 obligations require it, while tracking newer US Core releases such as 9.0.0 as source systems adopt them.
- Do not assume FHIR Subscriptions are available. Prefer them when supported; otherwise use HL7 v2 events, vendor webhooks, or `_lastUpdated` polling.

### 3.3 Real-Time Definition

Use four acquisition modes together:

1. Initial load: FHIR Bulk Data, source DB extracts, or vendor batch exports to establish baseline history.
2. Real-time delta: HL7 v2, FHIR Subscriptions, vendor webhooks, device streams, interface-engine routes, or API polling.
3. Reconciliation: scheduled source-of-truth comparisons against FHIR search/Bulk, extracts, or vendor reports.
4. Exception closure: dead-letter repair, identity merge handling, terminology mapping, and manual review queues.

Target SLOs:

- ADT, bed, census, and transport status: p95 source-to-Zephyrus projection under 60 seconds after source emission.
- ED/OR event milestones: p95 under 2 minutes.
- Lab/radiology/pharmacy status updates: p95 under 5 minutes unless source only permits batch.
- Payer/prior auth status: p95 under 15 minutes for APIs and same-day for batch/clearinghouse feeds.
- Bulk/backfill reconciliation: daily until stable, then at least weekly with alerting.

## 4. Target Architecture

### 4.1 System Flow

```
Source Systems
  -> Connector Layer
  -> Raw Ingestion Ledger
  -> Normalization and Validation
  -> Canonical Event Bus
  -> Domain Projectors
  -> prod Operational Tables
  -> star Analytics and Command Center
  -> Quality, Audit, Replay, and Observability
```

Connector layer types:

- FHIR REST connector: search, read, history, batch, Bulk Data, CapabilityStatement, SMART auth.
- FHIR subscription connector: creates or receives topic notifications where available.
- HL7 v2 connector: MLLP termination through an interface engine or dedicated receiver, ACK/NACK state, parser, and mapper.
- Vendor REST/webhook connector: transport, staffing, EVS, RTLS, CRM, and operational SaaS APIs.
- DICOMweb connector: QIDO-RS metadata query, WADO-RS retrieve, STOW-RS if Zephyrus ever stores imaging artifacts.
- X12 connector: clearinghouse/SFTP/API ingestion for 270/271, 276/277, 278, 835, 837, and acknowledgments where relevant.
- NCPDP connector: pharmacy benefit/e-prescribing/ePA status where a customer grants access.
- Document connector: C-CDA, Direct, IHE XDS/XCA, TEFCA/QHIN document retrieval.
- File/SFTP connector: fallback for legacy systems, always treated as batch with reconciliation.
- Database replica connector: last resort only, read-only, with explicit data-use and change-management controls.

### 4.2 Source-To-Domain Rule

External payloads never write directly to Zephyrus operational tables. They land in raw/source tables, are normalized, then projected through canonical events or explicit resource projectors.

This protects Zephyrus from vendor quirks:

- Epic, Oracle Health, MEDITECH, athenahealth, and specialty systems can expose similar FHIR resources with different search behavior and extension patterns.
- HL7 v2 feeds vary heavily by site and interface engine configuration.
- SaaS systems often use proprietary status vocabularies.
- A canonical layer keeps dashboards stable while connector mappings evolve.

## 5. Required Schema Expansion

Create a new integration schema or add these tables under `prod`/`raw` with clear ownership. Recommended: `integration` schema for connector operations, `raw` for immutable payloads, `fhir` for resource mirrors and mappings.

### 5.1 Source Registry

- `integration.sources`
  - `source_id`, `tenant_key`, `facility_key`, `source_name`, `vendor`, `system_class`, `environment`, `base_url`, `interface_type`, `active_status`
  - `fhir_version`, `us_core_version`, `smart_supported`, `bulk_supported`, `subscriptions_supported`
  - `contract_status`, `baa_status`, `phi_allowed`, `go_live_status`
  - `created_at`, `updated_at`
- `integration.source_capabilities`
  - one row per source/resource/operation/search parameter.
- `integration.source_endpoints`
  - SMART endpoints, FHIR base URLs, HL7 listener routes, webhook URLs, SFTP roots, DICOMweb bases.
- `integration.source_credentials`
  - only secret references, certificate IDs, JWKS URIs, and rotation metadata. Never raw secrets.

### 5.2 Ingestion Ledger

- `raw.ingest_runs`
  - one record per connector run, Bulk Data job, polling cycle, file import, or replay.
- `raw.inbound_messages`
  - immutable payload envelope: source, message type, external ID, received time, payload hash, storage pointer, parse status.
- `raw.inbound_payload_chunks`
  - optional large payload/NDJSON chunk tracking.
- `raw.dead_letters`
  - parse, validation, mapping, identity, terminology, or projection failures.
- `integration.connector_watermarks`
  - per source/resource/message type: last successful `_lastUpdated`, Bulk transaction time, file timestamp, HL7 sequence, webhook cursor.

### 5.3 FHIR Mirror and Crosswalk

Replace or extend `fhir.resources` into a multi-source model:

- `fhir.resource_versions`
  - `source_id`, `resource_type`, `fhir_id`, `version_id`, `last_updated`, `resource_hash`, `resource_data`, `ingest_run_id`, `deleted_at`.
- `fhir.resource_links`
  - maps external FHIR resources to Zephyrus internal records.
- `integration.identity_links`
  - patient, encounter, practitioner, organization, location, order, task, and document identity crosswalks.
- `integration.patient_merge_events`
  - explicit merge/unmerge handling for HL7 A40/A47 and FHIR Patient.link changes.
- `integration.terminology_maps`
  - source codes to canonical codes, with code system, version, reviewer, and active dates.
- `integration.provenance_records`
  - row-level lineage from raw payload to canonical event to `prod` projection.

### 5.4 Event Bus Tables

Expand `prod.operational_events` or create `integration.canonical_events`:

- `event_id`, `source_id`, `event_type`, `entity_type`, `entity_ref`, `occurred_at`, `received_at`, `payload`, `payload_hash`
- `correlation_id`, `causation_id`, `idempotency_key`, `sequence_key`, `projection_status`
- unique idempotency key per source/message/external event.

Add:

- `integration.event_projection_offsets`
- `integration.event_projection_errors`
- `integration.event_replay_jobs`

## 6. Canonical Event Expansion

Extend `app/Rtdc/Events/CanonicalEvent.php` into a shared operational event namespace. Keep RTDC names compatible but add broader event types.

Core identity:

- `PatientCreated`
- `PatientUpdated`
- `PatientMerged`
- `PatientUnmerged`
- `CoverageUpdated`
- `PractitionerUpdated`
- `LocationUpdated`

Encounter and capacity:

- `EncounterRegistered`
- `EncounterAdmitted`
- `EncounterStarted`
- `EncounterTransferred`
- `EncounterDischarged`
- `EncounterCancelled`
- `EncounterClassChanged`
- `AcuityChanged`
- `BedStatusChanged`
- `BedAssigned`
- `BedCleanStarted`
- `BedCleanCompleted`
- `CensusSnapshotReceived`

ED:

- `EdArrivalRegistered`
- `TriageCompleted`
- `ProviderSeen`
- `DispositionSet`
- `AdmitDecisionMade`
- `EdDeparted`
- `LwbsRecorded`

Perioperative:

- `CaseScheduled`
- `CaseUpdated`
- `CaseCancelled`
- `PatientInPreOp`
- `PatientInRoom`
- `ProcedureStarted`
- `ProcedureEnded`
- `PatientOutOfRoom`
- `PatientInPacu`
- `BlockUpdated`

Orders/results/ancillary:

- `OrderPlaced`
- `OrderCancelled`
- `ResultPreliminary`
- `ResultFinal`
- `SpecimenCollected`
- `ImagingStudyAvailable`
- `MedicationOrdered`
- `MedicationAdministered`
- `MedicationDispensed`
- `DeviceObservationReceived`

Transport and care transitions:

- `TransportRequested`
- `TransportAssigned`
- `TransportDispatched`
- `TransportArrivedPickup`
- `TransportPickedUp`
- `TransportCompleted`
- `TransferRequested`
- `TransferAccepted`
- `ReferralSent`
- `ReferralAccepted`
- `DocumentReceived`

Operations and improvement:

- `BarrierOpened`
- `BarrierUpdated`
- `BarrierResolved`
- `StaffingSnapshotReceived`
- `ResourceUnavailable`
- `PriorAuthRequired`
- `PriorAuthSubmitted`
- `PriorAuthApproved`
- `PriorAuthDenied`
- `QualityMeasureEventReceived`

## 7. Transactional System Coverage Matrix

### 7.1 Enterprise EHR and Registration

Systems:

- Epic, Oracle Health/Cerner Millennium, MEDITECH Expanse, athenahealth, Altera/Paragon, CPSI/TruBridge, eClinicalWorks, NextGen, and customer-specific registration/EMPI systems.

Primary standards and APIs:

- FHIR R4: `Patient`, `Encounter`, `Account`, `Coverage`, `RelatedPerson`, `Organization`, `Practitioner`, `PractitionerRole`, `Location`, `Provenance`.
- US Core profiles where available.
- SMART Backend Services for system-to-system reads.
- FHIR Bulk Data for historical patient/encounter/clinical backfill.
- HL7 v2 ADT: A01, A02, A03, A04, A05, A06, A07, A08, A11, A12, A13, A17, A28, A31, A40, A47.

Real-time path:

- Prefer ADT feed through interface engine for registration, admission, transfer, discharge, cancel, and merge events.
- Use FHIR polling or Subscription as supplement, not sole source unless the EHR guarantees low-latency eventing.

Zephyrus projections:

- `prod.encounters`, `prod.ed_visits`, `prod.units`, `prod.beds`, `prod.census_snapshots`, `prod.bed_requests`, identity crosswalks.

Phase:

- Phase 2 baseline, Phase 3 real-time.

### 7.2 Bed Management and Patient Flow

Systems:

- Epic Grand Central/Hospital Patient Flow, Oracle Health Systems Operations, TeleTracking, ABOUT Healthcare, LeanTaaS iQueue, Qventus, local bed boards.

Primary standards and APIs:

- HL7 v2 ADT movement and bed-status events.
- FHIR: `Encounter`, `Location`, `Task`, `ServiceRequest`, `Flag`, `CarePlan`, `Communication`, `Observation`.
- Vendor REST/webhooks where available.

Real-time path:

- ADT plus vendor bed-status events.
- Poll vendor bed board only as a fallback.

Zephyrus projections:

- `prod.beds`, `prod.census_snapshots`, `prod.bed_requests`, `prod.bed_placement_decisions`, `prod.barriers`, `prod.rtdc_predictions`.

Phase:

- Phase 3.

### 7.3 Emergency Department

Systems:

- Epic ASAP, Oracle FirstNet, MEDITECH ED, Wellsoft, T-System, Picis ED, local triage/track boards.

Primary standards and APIs:

- HL7 v2 ADT and SIU where ED scheduling is relevant.
- FHIR: `Encounter`, `Patient`, `Observation`, `Condition`, `Procedure`, `ServiceRequest`, `Task`, `Communication`, `Appointment`, `Location`.
- CDS Hooks for ED workflow nudges, not for primary data acquisition.

Real-time path:

- ADT for arrival/status/location/disposition.
- Vendor ED tracking API or event feed for triage, provider-seen, roomed, LWBS, boarding, and departure milestones.
- FHIR fallback using `Encounter` and `Observation` search by patient/date.

Zephyrus projections:

- `prod.ed_visits`, `prod.encounters`, `prod.bed_requests`, `prod.operational_events`, Command Center flow metrics.

Phase:

- Phase 4.

### 7.4 Perioperative, Anesthesia, and Procedural Areas

Systems:

- Epic OpTime/Anesthesia, Oracle SurgiNet/SA Anesthesia, MEDITECH Surgical Services, SIS, Picis, Provation, GI/endoscopy systems.

Primary standards and APIs:

- HL7 v2 SIU for scheduling, ORM/OML for orders, ORU for results and timing/status messages.
- FHIR: `Appointment`, `Schedule`, `Slot`, `ServiceRequest`, `Procedure`, `Encounter`, `Location`, `PractitionerRole`, `Device`, `MedicationAdministration`, `Observation`, `Task`.
- Vendor APIs for first-case starts, room status, block utilization, and anesthesia events when available.

Real-time path:

- Schedule baseline from FHIR/HL7 SIU.
- Case status/times from periop interface feed or vendor API.
- Anesthesia vitals/meds usually require specialty feed; do not assume FHIR coverage.

Zephyrus projections:

- `prod.or_cases`, `prod.or_logs`, `prod.case_metrics`, `prod.case_timings`, `prod.case_resources`, `prod.block_templates`, `prod.block_utilization`, `prod.rooms`.

Phase:

- Phase 4.

### 7.5 Laboratory and Pathology

Systems:

- Epic Beaker, Oracle PathNet, Sunquest, Orchard, SoftLab, Labcorp/Quest interfaces.

Primary standards and APIs:

- HL7 v2 ORM/OML orders, ORU results.
- FHIR: `ServiceRequest`, `Specimen`, `Observation`, `DiagnosticReport`, `Task`.
- LOINC and SNOMED CT terminology maps.

Real-time path:

- HL7 ORU feed for final/preliminary results and status changes.
- FHIR search for reconciliation and missed results.

Zephyrus projections:

- Ancillary delay events, isolation/placement flags, discharge barrier logic, acuity/risk features.

Phase:

- Phase 5.

### 7.6 Imaging, Radiology, and Cardiology

Systems:

- Epic Radiant, Oracle RadNet, Sectra, Visage, GE, Philips, Change/Powerscribe, cardiology PACS.

Primary standards and APIs:

- DICOM/DICOMweb: QIDO-RS, WADO-RS, STOW-RS, UPS-RS.
- HL7 v2 ORM/ORU.
- FHIR: `ImagingStudy`, `DiagnosticReport`, `Observation`, `ServiceRequest`, `DocumentReference`.

Real-time path:

- HL7 order/result status plus DICOMweb metadata query.
- Store imaging metadata and pointers, not image binaries, unless a specific Zephyrus workflow needs them.

Zephyrus projections:

- Ancillary wait states, transport dependencies, result-available milestones, process-mining events.

Phase:

- Phase 5.

### 7.7 Pharmacy, Medication Administration, and Medication History

Systems:

- Epic Willow, Oracle PharmNet, MEDITECH Pharmacy, Omnicell, Pyxis, Surescripts, PBM networks.

Primary standards and APIs:

- HL7 v2 RDE/RDS/RAS and ORU as configured.
- FHIR: `MedicationRequest`, `MedicationAdministration`, `MedicationDispense`, `MedicationStatement`, `Medication`, `AllergyIntolerance`.
- NCPDP SCRIPT, RTPB, and pharmacy benefit ePA where applicable.
- RxNorm, NDC, SNOMED CT.

Real-time path:

- EHR MAR/pharmacy interface for inpatient medication events.
- NCPDP/PBM APIs for outpatient/pharmacy benefit workflows.

Zephyrus projections:

- Discharge-med barrier, med reconciliation readiness, pharmacy turnaround, prior-auth barrier context.

Phase:

- Phase 5.

### 7.8 Staffing, Scheduling, Credentialing, and Workforce

Systems:

- UKG/Kronos, Workday, Oracle HCM, QGenda, symplr, ShiftWizard, Clairvia, AMiON.

Primary standards and APIs:

- Vendor REST APIs, reports, or database extracts.
- FHIR: `Practitioner`, `PractitionerRole`, `Schedule`, `Slot`, `HealthcareService`, `Organization`.
- HL7 MFN or custom flat files where legacy.

Real-time path:

- API polling/webhook for staffing rosters, call schedules, census staffing targets.
- File fallback for shift plans.

Zephyrus projections:

- Unit staffing snapshots, staffing-adjusted capacity, provider/room availability, forecast constraints.

Phase:

- Phase 5.

### 7.9 Transport, EMS, NEMT, and Care Transitions

Systems:

- Internal transport dispatch, Ride Health, Uber Health, Lyft Healthcare/Concierge, regional ambulance/NEMT vendors, ESO, ImageTrend, Pulsara, TigerConnect/Twiage, CarePort/WellSky, Aidin, Bamboo Health, PointClickCare.

Primary standards and APIs:

- Vendor REST APIs and webhooks.
- FHIR: `ServiceRequest`, `Task`, `Encounter`, `Location`, `Appointment`, `Communication`, `CommunicationRequest`, `DocumentReference`, `Organization`, `Practitioner`, `Provenance`.
- HL7 v2 ADT for encounter context.
- C-CDA/Direct for transition packets where FHIR APIs are incomplete.

Real-time path:

- Vendor status webhooks for request lifecycle.
- Internal canonical events for dispatch and handoff status.

Zephyrus projections:

- `prod.transport_requests`, `prod.transport_events`, `prod.bed_requests`, barriers, discharge readiness.

Phase:

- Phase 4 for internal transport, Phase 6 for EMS and care transitions.

### 7.10 EVS, Facilities, Biomedical, Supply Chain, and RTLS

Systems:

- ServiceNow, Nuvolo, Oracle/Workday ERP, Infor, GHX, Pyxis/Omnicell supplies, CenTrak, Kontakt.io, Sonitor, Rauland/Hillrom nurse call.

Primary standards and APIs:

- Vendor REST/webhooks, message queues, MQTT/device gateways.
- FHIR: `Device`, `DeviceMetric`, `Observation`, `Task`, `SupplyRequest`, `SupplyDelivery`, `Location`.
- GS1 identifiers where supply chain integration is mature.

Real-time path:

- Vendor webhooks or queue feeds for bed cleaning, equipment location, device availability, environmental services work orders.

Zephyrus projections:

- Bed dirty/clean states, blocked beds, equipment bottlenecks, supply constraints, transport readiness.

Phase:

- Phase 5.

### 7.11 Payer, Eligibility, Prior Authorization, Claims, and Revenue-Cycle Signals

Systems:

- Clearinghouses, Availity, Waystar, Change/Optum, Experian, payer FHIR APIs, PBMs, internal revenue-cycle platforms.

Primary standards and APIs:

- X12 270/271, 276/277, 278, 835, 837.
- FHIR: `Coverage`, `CoverageEligibilityRequest`, `CoverageEligibilityResponse`, `Claim`, `ClaimResponse`, `ExplanationOfBenefit`, `InsurancePlan`, `Task`, `DocumentReference`.
- HL7 Da Vinci CRD, DTR, and PAS for prior authorization workflows.
- CMS Interoperability and Prior Authorization APIs where applicable.

Real-time path:

- Clearinghouse APIs/webhooks for eligibility and prior-auth lifecycle.
- FHIR Da Vinci APIs where payer/provider integration supports them.
- X12 batch for reconciliation and historical state.

Zephyrus projections:

- Prior-auth barriers, avoidable days, discharge delays, transfer/referral blocks, payer-related process mining.

Phase:

- Phase 6.

### 7.12 HIE, TEFCA/QHIN, External Records, Public Health, and Quality

Systems:

- TEFCA QHINs, Carequality/CommonWell networks, state/regional HIEs, DirectTrust, public health registries, NHSN, eCR, quality registries.

Primary standards and APIs:

- TEFCA/QHIN exchange, IHE XCA/XDS, C-CDA, FHIR R4, Direct Secure Messaging.
- FHIR: `DocumentReference`, `Binary`, `Composition`, `Bundle`, `DiagnosticReport`, `Observation`, `Condition`, `Immunization`, `MeasureReport`, `Provenance`.
- eCR and public health IGs where customer scope requires.

Real-time path:

- ADT/event notifications from HIEs where available.
- Document discovery/retrieval for external care context.
- FHIR network exchange as TEFCA support matures.

Zephyrus projections:

- External document index, care-transition timeline, quality/process evidence, external encounter context.

Phase:

- Phase 6.

## 8. FHIR Resource Coverage Map

### 8.1 Cross-Cutting Resources

- `Patient`: identity, demographics, MRN/enterprise IDs, merge links.
- `Person` and `Linkage`: cross-record linkage where source supports it.
- `RelatedPerson`: contacts and caregivers.
- `Organization`: facilities, payers, vendors, external organizations.
- `Location`: hospital, department, unit, room, bed, procedural areas.
- `Practitioner`: clinicians and staff.
- `PractitionerRole`: provider role, service, location, specialty.
- `HealthcareService`: clinical services, units, scheduling services.
- `Endpoint`: source endpoints and network addresses where exposed.
- `Provenance`: source lineage for acquired data.
- `AuditEvent`: security/audit events where source emits or requires audit representation.
- `CapabilityStatement`: source capability discovery.
- `OperationOutcome`: API and validation error normalization.

### 8.2 Encounter, Capacity, and RTDC

- `Encounter`: registration, admission, transfer, discharge, ED and inpatient state.
- `Account`: visit/account where billing-account context matters.
- `Coverage`: payer/coverage state for barriers and prior auth.
- `Location`: unit/room/bed modeling.
- `Flag`: isolation, safety, placement, or operational flags.
- `RiskAssessment`: acuity or readmission/clinical risk if source exposes it.
- `CarePlan` and `Goal`: discharge planning and care progression context.
- `Task`: bed assignment, discharge tasks, placement work.

### 8.3 Emergency Department

- `Encounter`: ED visit lifecycle and class.
- `Observation`: triage acuity, vitals, ESI-like signals, symptoms where coded.
- `Condition`: ED diagnoses/problems.
- `Procedure`: ED procedures.
- `ServiceRequest`: imaging/lab/consult orders.
- `Task`: operational work items.
- `Communication`: handoffs and coordination.

### 8.4 Perioperative

- `Appointment`, `Schedule`, `Slot`: block and case scheduling.
- `ServiceRequest`: procedure/order request.
- `Procedure`: performed procedure.
- `Encounter`: surgical encounter or procedural episode.
- `Location`: room/procedural area.
- `PractitionerRole`: surgeon/anesthesia/team roles.
- `Observation`: case timing/vitals/clinical measurements where appropriate.
- `Device`: equipment and implants where relevant.
- `MedicationAdministration`: anesthesia or perioperative med events if available.

### 8.5 Ancillary and Diagnostics

- `ServiceRequest`: orders.
- `Specimen`: specimen lifecycle.
- `Observation`: discrete results.
- `DiagnosticReport`: lab, pathology, radiology reports.
- `ImagingStudy`: imaging metadata.
- `DocumentReference` and `Binary`: reports, notes, transition packets.

### 8.6 Medication and Pharmacy

- `Medication`, `MedicationRequest`, `MedicationAdministration`, `MedicationDispense`, `MedicationStatement`.
- `AllergyIntolerance`.
- `DetectedIssue` where source supports med safety issues.

### 8.7 Transport and Care Transitions

- `ServiceRequest`: requested movement/referral/transport.
- `Task`: execution lifecycle and assignment.
- `Appointment`: scheduled future movement.
- `Communication` and `CommunicationRequest`: handoff and coordination.
- `DocumentReference`: transition packets and ePCR documents.
- `Consent`: only when customer workflows require consent tracking.

### 8.8 Payer and Prior Authorization

- `Coverage`.
- `CoverageEligibilityRequest` and `CoverageEligibilityResponse`.
- `Claim` and `ClaimResponse`.
- `ExplanationOfBenefit`.
- `InsurancePlan`.
- `Task` for prior-auth workflow state.
- `Questionnaire` and `QuestionnaireResponse` for DTR-style documentation.
- `DocumentReference` for supporting information.

### 8.9 Integration Operations

- `Bundle`: batch/search/bulk and transaction containers.
- `Subscription`, `SubscriptionTopic`, `SubscriptionStatus`: event notification where supported.
- `MessageHeader`: FHIR messaging where source uses it.
- `Parameters`: operation requests/responses.
- `Group`: Bulk Data group export cohorts.

## 9. Connector Contracts

Create PHP interfaces under `app/Integrations/Healthcare/Contracts`.

```php
interface HealthcareConnector
{
    public function sourceKey(): string;
    public function capabilities(): ConnectorCapabilities;
    public function healthCheck(): ConnectorHealth;
    public function backfill(BackfillRequest $request): IngestRun;
    public function poll(PollRequest $request): IngestRun;
    public function handleWebhook(WebhookEnvelope $webhook): IngestRun;
    public function replay(ReplayRequest $request): IngestRun;
}
```

```php
interface SourceMessageNormalizer
{
    public function supports(InboundMessage $message): bool;
    public function normalize(InboundMessage $message): NormalizedPayload;
}
```

```php
interface CanonicalEventMapper
{
    /** @return list<CanonicalOperationalEvent> */
    public function map(NormalizedPayload $payload): array;
}
```

```php
interface ProjectionHandler
{
    public function supports(CanonicalOperationalEvent $event): bool;
    public function project(CanonicalOperationalEvent $event): void;
}
```

Implementation requirements:

- All connectors must be idempotent.
- Every external message must receive an immutable raw record before parsing.
- Every projection must be replayable from canonical events.
- Every dropped/failed/unknown message must land in dead letters with a reason.
- No connector may log raw PHI, credentials, bearer tokens, refresh tokens, signed URLs, or vendor payload bodies.

## 10. File and Module Plan

Create:

- `app/Integrations/Healthcare/Contracts/*`
- `app/Integrations/Healthcare/DTO/*`
- `app/Integrations/Healthcare/SourceRegistry/*`
- `app/Integrations/Fhir/*`
- `app/Integrations/Hl7V2/*`
- `app/Integrations/DicomWeb/*`
- `app/Integrations/X12/*`
- `app/Integrations/Documents/*`
- `app/Integrations/Vendor/*`
- `app/Models/Integration/*`
- `app/Models/Fhir/ResourceVersion.php`
- `app/Console/Commands/IntegrationDiscoverSourceCommand.php`
- `app/Console/Commands/IntegrationBackfillCommand.php`
- `app/Console/Commands/IntegrationPollCommand.php`
- `app/Console/Commands/IntegrationReplayCommand.php`
- `app/Jobs/Integrations/*`
- `app/Services/CanonicalEventProjector.php`
- `app/Services/DataQuality/*`
- `resources/js/Pages/Admin/Integrations/*`
- `tests/Feature/Integrations/*`
- `tests/Unit/Integrations/*`
- `tests/fixtures/fhir/*`
- `tests/fixtures/hl7v2/*`
- `tests/fixtures/x12/*`

Modify:

- `app/Rtdc/Events/CanonicalEvent.php` or replace with a broader `App\Integrations\Events\CanonicalOperationalEvent` while keeping compatibility constructors.
- `app/Rtdc/CensusProjector.php` to consume expanded events.
- `app/Services/CommandCenterDataService.php` only after live projections are stable.
- Existing API controllers only where live source freshness/status should be displayed.

## 11. Execution Roadmap

### Phase 0: Source Inventory and Legal/Network Readiness

Duration: 1 to 3 weeks per customer environment.

- [ ] Create `docs/superpowers/specs/source-system-inventory-template.md`.
- [ ] Inventory every source system by facility, department, vendor, environment, owner, data class, and integration method.
- [ ] Mark system of record for each required operational concept: patient, encounter, bed, unit, case, order, result, transport, staffing, task, document, payer status.
- [ ] Capture BAA/data-use status and PHI permission for each source.
- [ ] Capture network requirements: VPN, IP allow lists, mTLS, SFTP, interface engine route, webhook ingress.
- [ ] Capture vendor enablement: Epic app registration, Oracle code console, MEDITECH Greenfield, athenahealth developer app, interface-engine request tickets.
- [ ] Decide first customer/source order. Recommended first: EHR ADT + location + patient/encounter + bed board.
- [ ] Define go-live SLOs and escalation owners.
- [ ] Verify production auth posture: SSO/RBAC enforced, no demo auto-login, admin-only integration settings.

Exit criteria:

- Source inventory covers every live dashboard metric and planned workflow.
- Every required source has an access plan or an explicitly accepted temporary gap.
- Legal/security approves the first PHI ingestion slice.

### Phase 1: Integration Foundation

Duration: 2 to 4 weeks.

- [x] Add `integration` schema and source registry migrations.
- [x] Add raw ingestion ledger, dead-letter, connector watermark, and provenance migrations.
- [x] Extend `fhir` schema for multi-source resource versions.
- [x] Add identity and terminology crosswalk tables.
- [x] Add canonical event table with idempotency and replay metadata.
- [x] Build connector contract interfaces and DTOs.
- [x] Build source registry service.
- [ ] Build credential reference service using env/secret-manager references, not database secrets.
- [x] Build canonical event writer with idempotency enforcement.
- [ ] Build projection runner with per-projector offsets.
- [ ] Build dead-letter service and admin read APIs.
- [x] Add integration health endpoint: `/api/admin/integrations/health`.
- [ ] Add admin page for source status, runs, watermarks, dead letters, and replay jobs.
- [ ] Add PHI-safe structured logging and correlation IDs.
- [x] Add feature tests for idempotency, event write, dead-letter write, and replay behavior.
- [ ] Add projection offset handling tests once the general projection runner lands.

Exit criteria:

- A synthetic connector can write raw messages, map canonical events, project into `prod`, fail into dead letters, and replay. Implemented by `SyntheticHealthcareConnector`.
- Integration health is visible in UI and API. API implemented at `/api/admin/integrations/health`; UI remains pending.
- No raw PHI appears in logs during tests.

### Phase 2: FHIR R4/US Core Baseline and Bulk Backfill

Duration: 3 to 5 weeks.

- [ ] Implement SMART discovery from `.well-known/smart-configuration`.
- [ ] Implement CapabilityStatement discovery and store supported resources/search params.
- [ ] Implement SMART Backend Services with private-key JWT where source supports it.
- [ ] Implement authorization-code flow only for admin/test launch flows where needed.
- [ ] Implement FHIR REST client with pagination, rate limiting, retries, OperationOutcome capture, and `application/fhir+json`.
- [ ] Implement FHIR Bulk Data kickoff/status/file/delete workflow.
- [ ] Store downloaded NDJSON files in encrypted object/file storage with pointer records in `raw`.
- [ ] Parse Bulk Data NDJSON into `fhir.resource_versions`.
- [ ] Implement resource mappers for `Patient`, `Encounter`, `Location`, `Organization`, `Practitioner`, `PractitionerRole`, `Coverage`, `DiagnosticReport`, `Observation`, `Procedure`, `ServiceRequest`, `DocumentReference`, `MedicationRequest`.
- [ ] Build identity crosswalk for patient, encounter, location, practitioner, and order IDs.
- [ ] Project baseline `prod.units`, `prod.beds`, `prod.encounters`, `prod.locations`, `prod.providers`, and OR reference data where source coverage allows.
- [ ] Add a daily reconciliation job comparing Zephyrus counts to FHIR searches/Bulk exports.
- [ ] Add Inferno-style conformance test plan for each source where applicable.

Exit criteria:

- Zephyrus can run a full historical backfill from at least one FHIR sandbox or customer test source.
- Source capabilities are persisted and visible.
- Baseline identity/location/encounter projections can be rebuilt from stored resources.

### Phase 3: Real-Time ADT, Bed, Census, and Patient Flow

Duration: 3 to 6 weeks.

- [ ] Choose HL7 v2 ingress mode:
  - Recommended: interface engine terminates MLLP and posts normalized JSON to Zephyrus internal API.
  - Alternative: Zephyrus-side MLLP receiver service when no interface engine is available.
- [ ] Implement HL7 v2 parser adapter and fixtures for ADT A01/A02/A03/A04/A08/A11/A12/A13/A40/A47.
- [ ] Implement ACK/NACK state tracking and replay from interface engine.
- [ ] Map ADT messages to canonical events.
- [ ] Handle patient merge/unmerge and encounter correction safely.
- [ ] Project events into `prod.encounters`, `prod.beds`, and `prod.census_snapshots`.
- [ ] Implement bed board reconciliation against FHIR `Location`/`Encounter` or vendor extract.
- [ ] Add RTDC-specific data quality checks: occupied beds <= staffed beds, no active encounter in two beds, no bed assigned to two active encounters, no transfer without active encounter unless configured.
- [ ] Broadcast updated census to existing Reverb channels.
- [ ] Update Command Center freshness badges and source status labels.

Exit criteria:

- ADT test feed drives census changes in Zephyrus within SLO.
- Replay rebuilds `census_snapshots` from canonical events.
- Merge and cancel events do not duplicate patients or encounters.

### Phase 4: ED, Perioperative, and Transport Transactional Coverage

Duration: 4 to 8 weeks.

- [ ] ED: map arrivals, triage, provider-seen, disposition, admit decision, boarding, departure, LWBS.
- [ ] ED: add source-specific mapper for first EHR/vendor.
- [ ] OR: ingest schedules via FHIR `Appointment`/`Schedule`/`Slot` and/or HL7 SIU.
- [ ] OR: ingest case status and timing milestones from periop feed/API.
- [ ] OR: project into `prod.or_cases`, `prod.or_logs`, `prod.case_metrics`, and `prod.block_utilization`.
- [ ] Transport: implement internal connector contract around `prod.transport_requests` and `prod.transport_events`.
- [ ] Transport: implement first external vendor webhook adapter if a vendor is selected.
- [ ] Build unified timeline view per encounter from canonical events.
- [ ] Update Command Center metrics to show source freshness for ED, OR, and transport bands.
- [ ] Add end-to-end tests from sample messages to dashboard payload values.

Exit criteria:

- Command Center flow band can run without representative seed data for ED/OR/transport in the connected environment.
- ED and OR metric values are traceable to raw source payloads and canonical events.

### Phase 5: Ancillary, Staffing, EVS, RTLS, Pharmacy, and Device Signals

Duration: 4 to 10 weeks depending on source availability.

- [ ] Implement lab order/result mapper for HL7 ORM/OML/ORU and FHIR `Observation`/`DiagnosticReport`.
- [ ] Implement imaging status mapper for HL7 orders/results and DICOMweb metadata.
- [ ] Implement pharmacy/MAR mapper for HL7 RDE/RDS/RAS and FHIR medication resources where available.
- [ ] Implement staffing roster connector for selected WFM system.
- [ ] Implement EVS/facilities connector for bed cleaning and blocked-bed work orders.
- [ ] Implement RTLS/equipment connector for devices and transport-critical resources.
- [ ] Add terminology maps for LOINC, SNOMED CT, RxNorm, NDC, CPT/HCPCS, ICD-10-CM, local units, local services, and bed/location codes.
- [ ] Add quality checks for stale ancillary feeds and impossible timestamps.
- [ ] Project ancillary delays into process-mining and barrier workflows.

Exit criteria:

- Zephyrus can explain bed/flow delays from at least lab, imaging, pharmacy, staffing, or EVS sources rather than operator notes alone.
- Source freshness and missing-feed alerts are visible.

### Phase 6: Payer, HIE/TEFCA, Public Health, and External Documents

Duration: 6 to 12 weeks, usually parallel with payer/HIE contracting.

- [ ] Implement clearinghouse or payer API connector for eligibility and authorization status.
- [ ] Add Da Vinci CRD/DTR/PAS readiness model even if first integration remains X12.
- [ ] Implement `DocumentReference`/C-CDA ingestion and document metadata indexing.
- [ ] Implement Direct/IHE/HIE connector if customer has available network access.
- [ ] Track TEFCA/QHIN endpoint participation where customer participates through a QHIN or HIE.
- [ ] Project payer and document status into discharge, transfer, transport, and process-improvement barriers.
- [ ] Add consent/opt-out metadata where Provider Access or external exchange requires it.

Exit criteria:

- Prior auth/eligibility/referral/document delays can appear as first-class barriers.
- External documents are indexed with provenance and not silently blended into internal source-of-truth tables.

### Phase 7: Analytics, ML, and Completion Gates

Duration: ongoing after core feeds are stable.

- [ ] Build `star` projections from canonical events, not one-off app queries.
- [ ] Add source freshness dimensions to all star facts.
- [ ] Add data quality scorecards per domain.
- [ ] Train/validate forecasts only on source-labelled, quality-checked data.
- [ ] Add model feature lineage for predictions.
- [ ] Add data acquisition SLO dashboards.
- [ ] Add monthly source-capability drift checks.
- [ ] Add quarterly replay disaster-recovery drill.

Exit criteria:

- Every Command Center metric can show: source, last refresh, quality state, lineage, and fallback state.
- Analytics jobs fail closed or visibly degrade when source feeds are stale.

## 12. First 30-Day Implementation Sprint

Week 1:

- [ ] Write source inventory template and live-data element matrix.
- [ ] Add `integration` schema migrations for sources, capabilities, runs, messages, dead letters, watermarks, canonical events, projection offsets.
- [ ] Add Eloquent models and factories for integration tables.
- [ ] Add PHI-safe log sanitizer tests.

Week 2:

- [ ] Implement connector contracts and synthetic connector.
- [ ] Implement canonical event writer and projection runner.
- [ ] Implement admin integration health API.
- [ ] Add dead-letter listing and replay-request API.
- [ ] Add unit tests for idempotency and replay.

Week 3:

- [ ] Implement FHIR discovery and basic R4 client.
- [ ] Implement CapabilityStatement persistence.
- [ ] Implement resource version mirror for `Patient`, `Encounter`, `Location`, and `Practitioner`.
- [ ] Implement source crosswalk tables and mappers for those resources.
- [ ] Test against a local HAPI FHIR server or public sandbox.

Week 4:

- [ ] Implement HL7 v2 ADT parser fixtures and mappers.
- [ ] Map ADT A01/A02/A03/A08/A40 into canonical events.
- [ ] Project ADT events into `prod.encounters`, `prod.beds`, and `prod.census_snapshots`.
- [ ] Add source freshness badge to Command Center payload.
- [ ] Produce go/no-go checklist for first customer EHR test feed.

## 13. Data Quality and Reconciliation Controls

Required checks:

- Identity: no unreviewed patient merge creates duplicate active patients.
- Encounter: no active inpatient encounter without source encounter ID.
- Bed: no active bed assignment collision.
- Location: every external bed/location maps to one Zephyrus unit/bed or a reviewed unmapped queue.
- Time: source timestamps must be timezone-normalized and cannot move critical milestones backward without correction event.
- Status: local status vocabularies must map to canonical statuses or fail into review.
- Completeness: counts by source and Zephyrus projection must reconcile by facility/unit/date.
- Freshness: feed age alerts per source and domain.
- Coverage: every live dashboard metric must have source-backed numerator and denominator definitions.

Quality tables:

- `integration.data_quality_rules`
- `integration.data_quality_runs`
- `integration.data_quality_findings`
- `integration.metric_lineage`
- `integration.source_freshness_snapshots`

## 14. Security, Privacy, and Compliance

Non-negotiable gates before production PHI:

- SSO/RBAC enforced for all users.
- Admin integration settings limited to authorized roles.
- Secrets stored outside database or as encrypted secret references only.
- OAuth tokens encrypted and rotated; refresh tokens never logged.
- SMART Backend Services use asymmetric client authentication where supported.
- TLS/mTLS for external interfaces when supported.
- Webhook signature verification and replay protection.
- HL7 v2 connections restricted by network and interface-engine controls.
- PHI redaction in logs, exceptions, queue payload displays, and failed job UI.
- Audit log for data access, connector actions, replay jobs, credential changes, and admin reads.
- Minimum necessary payload selection per workflow.
- Data retention schedule for raw payloads and NDJSON files.
- BAA/data-use status tracked per source.
- Patient opt-out/consent metadata tracked where payer/HIE/provider-access workflows require it.
- Synthetic/demo data clearly separated from real PHI and never co-mingled under the same source.

## 15. Testing Strategy

Unit tests:

- Connector capability parsing.
- FHIR pagination and OperationOutcome handling.
- HL7 parser and segment mapping.
- Canonical event idempotency.
- Projection handlers.
- Terminology maps.

Contract tests:

- Sample FHIR resources for every mapped resource.
- Sample HL7 ADT/SIU/ORM/ORU/RDE/RAS messages.
- Sample X12 envelopes where payer integration is in scope.
- Sample vendor webhooks for transport/EVS/staffing.

Integration tests:

- Local HAPI FHIR server for FHIR R4 search/read/history.
- Synthetic Bulk Data NDJSON import.
- Interface-engine test route or fixture runner for HL7.
- DICOMweb test server for imaging metadata where needed.
- Reverb broadcast check from canonical event to UI refresh.

Replay tests:

- Rebuild `prod.encounters`, `prod.beds`, `prod.census_snapshots`, `prod.ed_visits`, and OR/transport projections from canonical events.
- Verify deterministic results after replay.

Load tests:

- 10,000 ADT messages/hour sustained.
- 1,000 ED milestone events/hour sustained.
- 100,000 FHIR resources in Bulk Data import.
- Back-pressure behavior for dead-letter bursts.

Acceptance tests:

- Command Center metric lineage displays source, timestamp, and quality state.
- Live metric changes when source event is ingested.
- Stale source degrades visibly instead of silently using old values.
- No synthetic data appears in live mode unless source is explicitly marked synthetic.

## 16. Production Cutover Model

Use domain-by-domain cutover. Do not flip the whole hospital at once.

1. Read-only shadow mode:
   - Ingest real source data.
   - Do not display as authoritative.
   - Compare with seeded/current dashboard outputs and source reports.
2. Parallel validation:
   - Show live metrics to admins only.
   - Reconcile daily with source owners.
   - Close dead-letter categories.
3. Limited go-live:
   - Enable one domain, one facility, one shift.
   - Keep source freshness and fallback banners visible.
4. Full domain go-live:
   - Replace synthetic data for that domain.
   - Add on-call alerts and source owner escalation.
5. Continuous assurance:
   - Daily freshness and reconciliation jobs.
   - Weekly data-quality review until stable.
   - Monthly source capability drift check.

## 17. Acceptance Criteria for "100% End-to-End Acquisition"

Zephyrus can claim end-to-end acquisition for a deployment only when all are true:

- Every live workflow data element is in the source inventory.
- Every element has an authoritative source and fallback/reconciliation path.
- Every source has a connector, credentials, ownership, SLA, and health check.
- Every raw payload is immutable, traceable, and replayable or explicitly documented as non-replayable.
- Every projection row has lineage back to source message/resource/version.
- Every feed has freshness monitoring.
- Every mapping failure lands in a reviewable dead-letter queue.
- Every identity/terminology conflict has a review path.
- Every live dashboard metric has source-backed numerator/denominator definitions.
- Synthetic data is fully disabled or clearly labelled outside live production metrics.
- Security/privacy gates have passed.
- Source owners sign off on parity for the first go-live window.

## 18. Key Risks and Mitigations

Risk: EHR FHIR APIs are not truly event-real-time for all operational milestones.

- Mitigation: use HL7 v2 ADT/SIU/ORM/ORU feeds and vendor operational APIs for real-time deltas; use FHIR for baseline, enrichment, reconciliation, and standards-based reads.

Risk: Source systems expose partial FHIR search support.

- Mitigation: persist CapabilityStatement details and write connector behavior per source capability, not by assumption.

Risk: Patient merge events corrupt downstream operational projections.

- Mitigation: treat merges as explicit canonical events with reviewable lineage; never overwrite identity links without merge provenance.

Risk: Local code systems make data look complete but semantically wrong.

- Mitigation: terminology map with reviewer, version, and unmapped-code dead letters.

Risk: Raw payloads create PHI retention exposure.

- Mitigation: encrypt raw storage, define retention, store payload hashes/pointers, redact logs, and restrict admin access.

Risk: A single broad connector becomes unmaintainable.

- Mitigation: keep connector, normalizer, mapper, and projector separate; test each with fixtures.

Risk: Dashboard trust erodes from stale feeds.

- Mitigation: show source freshness and degrade visibly; do not silently use stale values.

## 19. External Sources Used

- HL7 FHIR R4 RESTful API: https://hl7.org/fhir/R4/http.html
- HL7 US Core Implementation Guide, current CI v9.0.0 page and profile list: https://build.fhir.org/ig/HL7/US-Core/
- HL7 FHIR Bulk Data Access current published v3.0.0 page: https://hl7.org/fhir/uv/bulkdata/
- HL7 SMART App Launch v2.2.0: https://build.fhir.org/ig/HL7/smart-app-launch/
- HL7 CDS Hooks v2.0.1: https://cds-hooks.hl7.org/
- HL7 FHIR Subscriptions R5 Backport STU 1.1: https://hl7.org/fhir/uv/subscriptions-backport/STU1.1/
- ONC HTI-1 final rule overview: https://healthit.gov/regulations/hti-rules/hti-1-final-rule/
- ONC TEFCA overview: https://healthit.gov/policy/tefca/
- Sequoia/RCE TEFCA FHIR roadmap: https://rce.sequoiaproject.org/three-year-fhir-roadmap-for-tefca/
- CMS Interoperability and Prior Authorization Final Rule CMS-0057-F: https://www.cms.gov/initiatives/burden-reduction/overview/interoperability/policies-regulations/cms-interoperability-prior-authorization-final-rule-cms-0057-f
- HL7 Da Vinci PAS current published IG: https://hl7.org/fhir/us/davinci-pas/
- DICOMweb standard overview: https://www.dicomstandard.org/using/dicomweb
- X12 health care transaction flow: https://x12.org/flow/health-care
- CMS e-prescribing standards and requirements: https://www.cms.gov/medicare/regulations-guidance/electronic-prescribing/adopted-standard-and-transactions
- NCPDP ePrescribing industry information: https://www.ncpdp.org/Resources/ePrescribing-Industry-Information
- Epic on FHIR: https://fhir.epic.com/
- Epic on FHIR documentation: https://fhir.epic.com/Documentation
- Oracle Health Millennium FHIR R4 overview: https://docs.oracle.com/en/industries/health/millennium-platform-apis/mfrap/r4_overview.html
- Oracle Health Bulk Data Access: https://docs.oracle.com/en/industries/health/millennium-platform-apis/mfbda/bulk_data_access.html
- MEDITECH Greenfield Workspace: https://ehr.meditech.com/ehr-solutions/greenfield-workspace
- athenahealth FHIR APIs: https://docs.athenahealth.com/api/docs/fhir-apis
