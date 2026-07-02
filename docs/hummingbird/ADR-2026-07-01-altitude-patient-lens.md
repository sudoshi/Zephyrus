# ADR: Patient/Encounter Lens Is Mobile A2P, Not A Fifth Altitude

**Status:** Accepted
**Date:** 2026-07-01
**Scope:** Hummingbird Altitude 2.0 BFF and native clients

## Decision

Hummingbird will model the patient/encounter operational view as `A2P`, the deepest mobile
drill leaf under `A2`, rather than creating a fifth Zephyrus altitude.

## Rationale

Zephyrus 2.0 keeps four altitudes:

- `A0`: glance
- `A1`: workspace
- `A2`: drill/explain/action
- `A3`: study/retrospective

Patient-centered mobile detail is reached from red or amber operational signals: bed request,
transport, EVS turn, OR case, barrier, approval, huddle action, or staffing dependency. That
makes it a drill destination, not a separate analytical level. Keeping it under `A2` preserves
one shared altitude language while still giving authorized workers a reusable patient-centered
leaf.

## Consequences

- Mobile lists and pushes remain PHI-minimized and carry `patient_context_ref`, not raw patient
  detail.
- `GET /api/mobile/v1/patients/{patientRef}/operational-context` returns the reusable `A2P`
  packet after authorized detail entry.
- Eddy receives patient context only through the event/context packet path and remains
  drafts-only; human approval stays outside agent authority.
- Desktop-grade trends and process study remain `A3` links back into Zephyrus.
