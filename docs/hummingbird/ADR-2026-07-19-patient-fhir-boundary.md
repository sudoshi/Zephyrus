# ADR: Patient FHIR consumption, projection, and write-back boundary

- **Status:** Accepted
- **Date:** 2026-07-19
- **Decision owners:** Integration Platform, Hummingbird Patient Platform
- **Governance dependencies:** Clinical Informatics, Privacy, Health Information Management, source-system owner
- **Related decisions:**
    - [Patient product boundary](./ADR-2026-07-19-patient-product-boundary.md)
    - [Patient projection source of truth](./ADR-2026-07-19-patient-projection-source-of-truth.md)
    - [Draft patient disclosure matrix](./patient-disclosure-matrix.v1.yaml)

## Context

Zephyrus already has a governed SMART Backend Services/FHIR R4 ingestion control plane.
It allowlists resource types and scopes, requires an active and production-approved source,
uses controlled egress and secret references, persists source versions and provenance, and
projects selected data into Zephyrus operational models. The current default allowlist is
`Encounter` and `Location`; ancillary types are separately feature-gated. Zephyrus also
creates internal FHIR-shaped artifacts for Patient Flow and RPM, but those are not evidence
of an approved EHR write-back integration.

The patient product must not expose raw FHIR resources, treat FHIR availability as patient
release approval, or turn a mobile interaction into an EHR write without a separately
governed reconciliation path. FHIR is one possible source vocabulary. The patient contract
is a narrower, released projection.

## Decision

### 1. The patient API never proxies raw FHIR

Hummingbird Patient receives only `/api/patient/v1` representations. A patient response is
built from the `patient_experience` projection after identity, encounter-grant, release,
sensitivity, freshness, correction/retraction, and relationship evaluation. The response
uses patient UUIDs and approved display content, not FHIR logical IDs, MRNs, raw narratives,
or source identifiers.

### 2. Consumption is explicit and least-privileged

Each FHIR resource family requires all of the following before it can feed a patient
projection:

1. an enabled entry in the integration resource policy;
2. an approved SMART scope and source-resource profile;
3. an active, production-governed source with PHI approval;
4. versioned source storage, provenance, and correction/deletion semantics;
5. a patient projection mapper with tests for late, duplicate, out-of-order, corrected,
   entered-in-error, and deleted resources;
6. a field-level patient release rule in the disclosure matrix;
7. clinical-content and language review for the rendered text.

An integration administrator enabling a resource does not automatically authorize patient
disclosure.

### 3. Resource dispositions

| FHIR R4 resource                               | Initial disposition                                                             | Patient use                                                                           | Required boundary                                                                                                          |
| ---------------------------------------------- | ------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| `Encounter`                                    | Consume now through governed integration                                        | Link the source encounter to the patient encounter projection and active access grant | Do not expose source ID, account number, MRN, internal class, or operational priority                                      |
| `Location`                                     | Consume now through governed integration                                        | Approved facility/unit/room display and transfer freshness                            | Apply location display policy; suppress operational capacity, isolation, and other-patient context                         |
| `ServiceRequest`                               | Consume only when ancillary FHIR flag and source profile are approved           | Approved test/procedure request and preparation milestone                             | No order narrative or result inference; release through care-event projection                                              |
| `Appointment`                                  | Consume only when explicitly enabled and profiled                               | Confirmed or planned care-event timing                                                | Preserve status and uncertainty; never fabricate an ETA                                                                    |
| `ImagingStudy`                                 | Consume only when ancillary FHIR flag and source profile are approved           | Imaging operational completion milestone                                              | No images or diagnostic interpretation in the initial patient projection                                                   |
| `DiagnosticReport`                             | Consume only when ancillary FHIR flag and release policy are approved           | Released result/document reference in a later phase                                   | Result release is independent of resource arrival; corrections and amended status must retract/supersede                   |
| `Specimen`                                     | Consume only when ancillary FHIR flag and source profile are approved           | Approved collection/progress milestone                                                | No specimen identifiers or internal processing queues                                                                      |
| `Observation`                                  | Consume only when ancillary FHIR flag and release policy are approved           | Later released result/vital content                                                   | No raw observation is patient-visible by default; preliminary, corrected, amended, and entered-in-error states fail closed |
| `MedicationRequest`                            | Consume only when ancillary FHIR flag and medication safety review are approved | Later approved medication state                                                       | Never infer indication; reconcile against the designated medication source                                                 |
| `MedicationDispense`                           | Consume only when ancillary FHIR flag and medication safety review are approved | Later approved dispense/readiness state                                               | Dispense does not substitute for administration evidence                                                                   |
| `CarePlan`, `Goal`, `Task`                     | Future consume/project candidates                                               | Pathway, goals, milestones, education, discharge readiness                            | Require an explicit resource-policy addition, profile, mapper, and disclosure rule                                         |
| `CareTeam`, `PractitionerRole`, `Organization` | Future consume/project candidates                                               | Care-team and responsibility display                                                  | Use current assignment/reconciliation sources; suppress private contact, schedule, and internal credential details         |
| `Communication`, `QuestionnaireResponse`       | Future consume/project candidates                                               | Patient questions, structured check-ins, and later governed interoperability          | Zephyrus messaging remains authoritative until a bidirectional integration is separately approved                          |
| `Consent`, `RelatedPerson`                     | Future consume/project candidates                                               | Evidence that informs consent/representative review                                   | Never turn a source resource into an access grant without local verification, scope, effective dates, and revocation state |
| `Procedure`                                    | Future consume/project candidate                                                | Approved procedure state                                                              | Require source and release reconciliation; no inferred outcome or prognosis                                                |
| `DocumentReference`                            | Future consume/project candidate                                                | Link to a designated released document                                                | Prefer a governed source link; do not silently copy the document into a shadow record                                      |
| `Patient`                                      | Identity-link input only when separately approved                               | Enterprise identity correlation                                                       | Store source links encrypted; never expose raw identifiers; pause disclosure on merge ambiguity                            |

“Future candidate” is not an enabled resource, approved scope, or implementation claim.

### 4. Produced artifacts are local until explicitly exported

Zephyrus may create internal FHIR-shaped resources or links for normalization,
interoperability testing, and provenance. Those artifacts remain within the governed
integration/clinical-payload stores. They do not become outbound EHR writes and are not
served directly to the patient app.

### 5. No patient-product EHR write-back is approved

The patient API, native apps, projection services, messaging, goals, teach-back, consent,
and representative flows must not call FHIR `create`, `update`, `patch`, `delete`,
transaction, batch, or operation endpoints. Patient-authored data is recorded in the
patient realm and, where needed, handed to an accountable staff workflow through a
transactional outbox.

Write-back requires a superseding ADR and all of:

- a named source-system owner and destination resource/profile;
- minimum necessary SMART write scope;
- deterministic idempotency and optimistic-concurrency behavior;
- human review where clinical meaning or the legal record may change;
- reconciliation for rejection, partial success, duplicates, late response, and downtime;
- provenance and audit linking patient action, staff approval, outbound request, and source
  acknowledgement;
- correction, amendment, withdrawal, and legal-record policy;
- production-like conformance, security, safety, and rollback evidence.

## Enforcement

- Keep patient FHIR/projection feature flags disabled by default.
- Continue to enforce the server-side FHIR resource allowlist and exact SMART scopes.
- Add each future resource through reviewed configuration and source profiles, never a
  catch-all resource or wildcard scope.
- Keep source credentials in the integration control plane; native apps and patient API
  responses never receive them.
- Contract and authorization tests must reject raw FHIR resource fields, source IDs, MRNs,
  and unreleased content.
- Integration tests must prove corrections, deletions, and grant revocation remove content
  from the next patient response and invalidate caches.
- A future outbound FHIR transport must be a separately named service behind an explicit
  disabled-by-default flag. Patient controllers may not own or instantiate it directly.

## Consequences

- The patient experience can use enterprise-standard data without inheriting FHIR’s full
  clinical and operational disclosure surface.
- Enabling a new FHIR source is deliberately slower because patient release requires a
  second, field-level governance step.
- Patient-authored interactions may initially remain in Zephyrus and route to staff rather
  than updating the legal record automatically.
- Source correction and provenance must be designed before a data family appears in the
  app, which reduces the risk of stale or prematurely released content.

## Revisit criteria

Revisit this ADR only for a named integration and bounded workflow after the source-system
owner proposes an exact FHIR profile and write interaction. A general desire for “FHIR
interoperability” or feature parity is not sufficient authorization.
