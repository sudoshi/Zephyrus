# ADR: Hummingbird Patient is a governed projection, not a shadow clinical record

- **Status:** Accepted
- **Date:** 2026-07-19
- **Decision owners:** Patient Experience Platform, Clinical Data Architecture, and Integration Platform
- **Applies to:** Today, My Path, Care Team, rounds summaries, education, discharge, and patient messaging context

## Context

Zephyrus combines operational data from encounters, RTDC, rounds, perioperative, transport,
ancillary services, staffing, and FHIR/integration sources. Those sources have different
authority, release, correction, timing, and sensitivity rules. A patient interface cannot
safely infer a clinical record by joining whichever operational rows are available.

The patient application needs stable patient-readable states while preserving authoritative
source ownership and visible uncertainty.

## Decision

Hummingbird Patient reads only from explicit, versioned patient projections. A projection is
a released view with provenance; it is not the source of clinical truth.

### Source ownership

- EHR/FHIR sources own clinical record content such as diagnoses, orders, results,
  medications, care plans, goals, and documented instructions where integrated.
- Zephyrus operational domains own workflow state such as bed flow, transport, rounds tasks,
  ancillary milestones, and operational dependencies.
- `patient_experience` owns enrollment, grants, patient-authored goals/preferences,
  education/teach-back interaction state, communication threads, and the released
  patient-readable projection.
- No patient interaction writes directly into an EHR or operational source until a governed,
  reconciled, human-review integration exists.

### Projection contract

Every projected field or event carries:

- source system, resource type, opaque source reference, and source version;
- release-policy version and sensitivity class;
- patient/representative relationship scope;
- source-observed and projection-generated timestamps;
- freshness expectation and stale behavior;
- stable patient state code and approved plain-language rendering;
- uncertainty/confirmation state;
- correction, retraction, and supersession linkage;
- translation/content version;
- cache and notification class.

Raw source payloads are never returned from patient controllers.

### State semantics

The patient vocabulary distinguishes at least:

- requested;
- planned but not confirmed;
- scheduled/confirmed;
- waiting;
- in progress;
- delayed with timing uncertain;
- completed;
- cancelled;
- result pending;
- result released;
- corrected/retracted.

Operational priority, staffing strain, internal queue position, internal risk scores, raw
staff contributions, and staff-only recommendations are prohibited unless a later field-level
release decision explicitly approves a patient-safe derivative.

### Projection processing

- Source events enter a transactional projection/outbox flow.
- Processing is idempotent by source/version and safe under duplicate, late, and out-of-order
  events.
- Release policy is evaluated before persistence into the patient-readable projection and
  again before disclosure.
- Corrections and retractions remain visible as append-only history and invalidate relevant
  caches/notifications.
- Projection lag, failure, orphan, and policy mismatch are observable and alertable.
- Identity ambiguity or merge activity pauses disclosure rather than guessing.

## Patient-authored information

Patient questions, goals, preferences, and teach-back responses are distinct records with
patient provenance. They are not clinical orders, clinician assessments, legal consents, or
staff-authored care-plan facts unless a separate governed workflow promotes them.

## Rounds integration

Patient questions enter a patient-owned queue. A bridge may create a linked rounds question
as a governed system action. Staff contributions remain internal. Only an explicitly
released, plain-language rounds summary returns to the patient projection.

## FHIR alignment

Use FHIR R4 resources where they match source meaning—such as `CarePlan`, `CareTeam`, `Goal`,
`Task`, `Communication`, `Consent`, `QuestionnaireResponse`, `ServiceRequest`, `Procedure`,
and `DiagnosticReport`. FHIR alignment does not bypass local release policy or authorize raw
resource serialization.

## Consequences

- The patient API remains stable when operational source schemas change.
- Projection lag and correction become explicit product states.
- Additional storage and processing are required.
- Content governance is part of implementation, not a final copy-edit pass.

## Required verification

- Duplicate/out-of-order source events converge deterministically.
- Every response field has source, release, freshness, uncertainty, and correction evidence.
- Revoked grants and retracted content disappear from disclosure immediately.
- No raw `patient_ref`, MRN, internal numeric ID, staff note, other-patient data, or
  prohibited operational field reaches the patient contract.
- Source outages produce accessible stale/degraded language rather than false certainty.
