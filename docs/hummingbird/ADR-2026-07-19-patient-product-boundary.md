# ADR: Hummingbird Patient is a separate product and authorization realm

- **Status:** Accepted
- **Date:** 2026-07-19
- **Decision owners:** Patient Experience Platform, Hummingbird Platform, and Zephyrus Security Architecture
- **Applies to:** all patient and representative functionality

## Context

The current Hummingbird application is a staff operational companion. Its 14 personas,
`mobile:read`/`mobile:act` abilities, A2P operational context, Flow lenses, recommendations,
For You queue, and push taxonomy assume a workforce identity and an employment/assignment
authorization model.

An inpatient needs a different product promise, disclosure policy, identity proofing model,
relationship model, language, audit trail, cache policy, messaging contract, and support
path. Treating a patient as a fifteenth staff persona would permit accidental coupling to
staff routes and data and would make least-privilege review impractical.

## Decision

Hummingbird Patient is a separately built, signed, deployed, authenticated, and audited
product.

### Server boundary

- Patient endpoints live under `/api/patient/v1` in a separate route file.
- Patient principals use a patient Eloquent provider and provider-constrained Sanctum guard.
- Patient tokens use only `patient:*` abilities.
- Middleware verifies the authenticated actor is a patient principal even when another web
  session is present.
- Patient access uses encounter grants and representative/delegation relationships on every
  request.
- Patient access/disclosure audit is separate from the staff `UserAuditRecorder`.
- Unauthorized and nonexistent encounter resources return the same generic response.
- Patient APIs never serialize `MobilePatientContextService` or other staff BFF payloads.

### Data boundary

- Patient identity, grants, sessions, consent, preferences, communication, projection
  controls, audit, and outbox state live in `patient_experience`.
- `prod.users` remains staff-only.
- `flow_core.patient_identities` remains deidentified operational identity and is not an
  account authority.
- External API identifiers are UUIDs; raw MRN and `patient_ref` never leave trusted server
  projection services.

### Native boundary

- iOS uses a distinct application target, bundle identifier, entitlements, app group,
  Keychain service, universal links, push topic, cache namespace, and analytics/crash project.
- Android uses a distinct application ID and signing/storage/deep-link/push boundary. A
  product flavor is acceptable only if release artifacts cannot include staff endpoints,
  credentials, caches, or debug persona code.
- Patient and Staff may share design tokens, generated tooling, and non-clinical UI
  primitives. They do not share authentication state or PHI persistence.

### Operational boundary

- Patient feature flags default off globally and per facility/unit/cohort.
- Patient compose remains disabled until a staffed responsibility-pool workflow exists.
- Patient Eddy remains disabled unless its independent safety case is approved.

## Rejected options

### Add `patient` to `MobilePersonaCatalog`

Rejected because it inherits staff assumptions and makes operational payload leakage a
configuration error instead of an architectural impossibility.

### Reuse the staff application with a runtime mode switch

Rejected because one binary would contain staff routes and patient routes, complicating
storage, deep-link, push, testing, and incident boundaries.

### Expose the staff A2P response with patient-friendly labels

Rejected because A2P contains staff operational state, recommendations, action semantics,
and access rules. A patient projection must independently govern release, provenance,
freshness, uncertainty, correction, and translation.

## Consequences

- Two native release artifacts and two support/security surfaces must be maintained.
- Shared tooling must preserve compile-time namespace separation.
- Enrollment, recovery, proxy, consent, and identity ambiguity require new workflows.
- Security review becomes simpler because staff/patient reachability is testable as a hard
  boundary.

## Required verification

- Staff tokens and staff web sessions cannot access patient routes.
- Patient tokens cannot access staff BFF, staff auth, administration, machine, or integration
  routes.
- Patient principals never appear in `prod.users` or the staff persona catalog.
- Patient binaries cannot call or decode staff operations.
- Logout/revoke/user-switch wipes only the correct product namespace and cannot affect the
  other product's session.
