# Nightingale route, compatibility, identity, and inpatient-source ADR

**Decision date:** 2026-07-26

**Status:** Adopted for namespace reservation and default-deny foundation only

**Runtime status:** No route, identity provider, source adapter, disclosure, native client, or
production query is enabled

**Related plan:**
[Nightingale Patient Product](../plans/nightingale-patient-product-2026-07-26.md)

**Related candidate:**
[Encounter-access held candidate](./ENCOUNTER-ACCESS-CANDIDATE-DECISION-2026-07-26.md)
and [Today projection held candidate](./TODAY-PROJECTION-CANDIDATE-DECISION-2026-07-26.md)

## 1. Decision summary

Nightingale reserves `/api/nightingale/v1` as its independent backend namespace. Its first
held read-only candidate is named `GET /inpatient-contexts` with operation ID
`listNightingaleInpatientContexts`.

Reservation prevents future collision and makes product ownership reviewable. It does **not**
register a Laravel route, add an OpenAPI path, permit client generation, configure a server,
enable identity, query a source, disclose a handle, or authorize deployment.

Nightingale will not alias, proxy, redirect, mount, or silently rename the legacy
`/api/patient/v1` Hummingbird Patient surface. It will not use the staff
`/api/mobile/v1` surface. Legacy patient routes remain compatibility evidence and an
unchanged reference surface. Any reusable backend behavior must sit behind an independently
reviewed Nightingale adapter and Nightingale-owned request, response, authorization, audit,
and release contracts.

The foundation introduces two request-scoped ports:

1. `NightingaleIdentityBoundary`, whose unconfigured implementation returns
   `unavailable`; and
2. `NightingaleInpatientContextSource`, whose unconfigured implementation performs no query
   and returns `unavailable`.

Only `verified_self` plus `confirmed_current` may continue to the later governed evaluation.
That continuation is explicitly **not** authorization. Identity-link, relationship, grant,
purpose, facility/cohort, cardinality, race, handle, durable-audit, response, and disclosure
gates still must pass before an operation could return data.

## 2. Scope and non-decisions

This ADR decides:

- the reserved Nightingale API namespace;
- the held candidate path and operation name;
- the absence of HTTP compatibility aliases;
- the separation of Nightingale identity from the legacy patient and staff realms;
- the shape and default state of the identity and source prerequisite ports;
- the fail-closed source-state vocabulary; and
- which changes remain prohibited in this foundation.

This ADR does not decide or approve:

- identity proofing, federation, assurance, enrollment, recovery, session duration, device
  binding, step-up, representative access, or break-glass behavior;
- a principal, token, cookie, credential, claim, scope, session, or storage schema;
- a mapping between Nightingale and legacy patient principals, grants, encounter UUIDs, or
  source identifiers;
- the authoritative production encounter connector, freshness interval, facility/unit
  cohort, observation class, boarding rule, transfer/readmission rule, or downtime policy;
- patient-visible encounter content or language;
- an OpenAPI operation, controller, middleware, service-container binding, route file,
  database query, migration, client, app entitlement, or Android network permission;
- non-production or production activation; or
- clinical, privacy/security, accessibility, patient-advisor, legal/HIM, support, operations,
  release, or deployment approval.

## 3. Evidence baseline

The source review was performed against branch `codex/nightingale-patient-product` after its
reconciliation with `origin/main` commit `84b5f830`. The exact reviewed files were:

| Source                                                                                  | SHA-256                                                            | Relevant observation                                                                                                                             |
| --------------------------------------------------------------------------------------- | ------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| `app/Providers/RouteServiceProvider.php`                                                | `c387e94f57e8db13df318d1db3dbdd578fda3f9823538112f244e09ec9556490` | Laravel separately mounts staff/general API, care-pathway API, legacy patient API, and web routes.                                               |
| `routes/patient.php`                                                                    | `e892a9d570668bec6ae54d18cbfd891f7d9df2e2c2f8f6e3689125d6b465af28` | The legacy patient surface uses Hummingbird-specific flags, realm, abilities, throttles, controllers, and route names.                           |
| `bootstrap/app.php`                                                                     | `fed17ddee14eed8dd650954193c7a276f6f41f5aecb946c5bfe264741688d56c` | Patient middleware aliases, exception decoration, and priority ordering are explicitly bound to the legacy patient route family.                 |
| `app/Models/Encounter.php`                                                              | `59e879ebe2780d093cb73328df30ad437519742aedabd0c02cb3a2cf22caa516` | The operational model maps to `prod.encounters`; its `active` scope checks status and deletion only.                                             |
| `app/Services/Patient/PatientEncounterAccessService.php`                                | `a593503e20dc8ba45699af4974f36345a0e85b739fa2f428565c8d3d4b079ef9` | The legacy list service exposes grant-derived fields and filters effective grants but does not itself confirm canonical current inpatient state. |
| `app/Services/Patient/Messaging/PatientCommunicationEncounterGuard.php`                 | `8a65438b3779252e88c35ee1dd91cc40e0d0d74e8018f5ac1a1d7a210a617976` | Messaging treats an encounter as current only when linked, active, not discharged, not deleted, and found under a shared lock.                   |
| `app/Services/Patient/Messaging/PatientCommunicationLifecycleReconciliationService.php` | `2676d63146f7c88ef8a53fc75b4f26abc01a879795edd328e3b3544034787347` | Lifecycle reconciliation distinguishes canonical active, canonical discharged, and unresolved states and uses row locks for mutation safety.     |
| `docs/hummingbird/api-contract/hummingbird-patient.v1.yaml`                             | `fb6220b4ef8eb106223624a9785256fdc1603995f281f430a79897905cb45a1b` | The legacy contract is useful compatibility evidence but carries legacy identifiers, fields, and product decisions.                              |

No production database was queried to make this decision. Schema-level and source-level
evidence is sufficient to determine that the present code contains multiple related
definitions but no approved Nightingale source adapter.

## 4. Current route topology

| Surface                | Current prefix                           | Owner/audience                       | Nightingale disposition                                                               |
| ---------------------- | ---------------------------------------- | ------------------------------------ | ------------------------------------------------------------------------------------- |
| General and staff APIs | `/api/**`, including `/api/mobile/v1/**` | Zephyrus web/staff products          | Prohibited as a Nightingale transport or identity realm                               |
| Care-pathway API       | `/api/care-pathways/v1/**`               | Governed pathway service             | Potential future internal dependency only; never exposed directly to a patient client |
| Legacy patient API     | `/api/patient/v1/**`                     | Former Hummingbird Patient reference | Preserved as compatibility input; no alias, proxy, redirect, or silent rename         |
| Nightingale            | `/api/nightingale/v1/**`                 | Dedicated patient product            | Namespace reserved; zero routes and zero OpenAPI paths                                |

Laravel currently registers the legacy patient surface in
`app/Providers/RouteServiceProvider.php`. This foundation does not add another route group.
There is no `routes/nightingale.php`, no Nightingale controller, no Nightingale middleware
alias, no Nightingale rate limiter, and no route-response decorator.

## 5. Route decision

### 5.1 Reserved namespace

The canonical reserved prefix is:

```text
/api/nightingale/v1
```

The version is part of the path because the patient-facing contract needs an explicit,
independently governable compatibility boundary. Product activation, operation activation,
facility/cohort activation, and contract version remain separate decisions.

Reservation is recorded in three mutually checked artifacts:

- `config/nightingale.php`;
- `docs/nightingale/api-contract/nightingale-foundation.v0.json`; and
- the held encounter-access candidate.

The foundation OpenAPI document retains `paths: {}`, a `.invalid` server, no security
schemes, and no client-generation permission.

### 5.2 First held candidate

The candidate relative path and operation ID are:

```text
GET /inpatient-contexts
operationId: listNightingaleInpatientContexts
```

The name describes patient-facing purpose rather than exposing a source-system Encounter
resource or a legacy authorization-grant collection. The candidate still returns zero or one
opaque Nightingale handle only, as specified by the held-candidate decision and fixtures.

The path is not added to `paths`, `RouteServiceProvider`, a route file, or either native app.

### 5.3 Second held candidate

After the complete source classification and encounter-access candidate, the next
non-runnable patient-journey candidate is:

```text
GET /inpatient-contexts/{encounter_handle}/today
operationId: getNightingaleTodayProjection
```

It consumes only the Nightingale-owned opaque handle from the separately held
encounter-access candidate. It does not accept or expose a legacy encounter, grant,
principal, patient, projection, or source identifier. Its 68 synthetic outcomes define
field-level release, freshness, uncertainty, language, correction, and offline decisions.

This extension reserves candidate intent, not runtime behavior. The path remains absent from
the foundation `paths` object, Laravel route registration, native clients, and every
activation mechanism.

### 5.4 Route-registration preconditions

Before the route can be registered, evidence must prove at least:

1. named contract, backend, identity, privacy/security, accessibility, patient, clinical,
   legal/HIM, support, operations, and release owners;
2. an approved operation in a non-foundation Nightingale contract;
3. an approved identity/session/recovery design and request authentication adapter;
4. an authoritative current-inpatient source adapter with freshness and outage behavior;
5. complete operation-specific authorization and non-disclosure tests;
6. durable request, denial, safety-failure, and disclosure audit behavior;
7. bounded error, cache, rate, body-size, retry, and correlation contracts;
8. generated-client parity fixtures with native networking still default-off;
9. a reviewed threat model and privacy/security test evidence;
10. patient-language, accessibility, support, downtime, rollback, and controlled integration
    approval.

## 6. Compatibility decision

### 6.1 Prohibited compatibility mechanisms

Nightingale will not use:

- an HTTP redirect from `/api/nightingale/v1` to `/api/patient/v1`;
- a reverse proxy or route alias to the legacy controllers;
- mounting `routes/patient.php` under a second prefix;
- legacy `patient.*` route names under the Nightingale prefix;
- legacy patient feature flags as Nightingale activation controls;
- legacy patient token names, abilities, realm middleware, response decorator, or app
  storage as an implicit Nightingale compatibility layer;
- staff `/api/mobile/v1` tokens, routes, scopes, or BFF responses;
- native URL rewriting that turns a Nightingale call into a legacy patient call; or
- fallback to a legacy endpoint when a Nightingale response is unavailable.

These mechanisms would make product ownership, authorization, audit, rate limits,
deprecation, incident response, and data disclosure depend on two nominally different
products sharing one runtime contract.

### 6.2 Permitted internal reuse

Internal reuse is allowed only after classification and may include:

- product-neutral cryptographic, auditing, rate-limiting, policy-evaluation, and
  patient-safe response primitives;
- an internal service that receives a Nightingale-owned input and returns a
  Nightingale-owned domain result;
- source adapters that keep raw source identifiers server-side; and
- separately reviewed projections that preserve provenance, release, freshness,
  uncertainty, correction, withdrawal, and localization controls.

Reuse must not expose a legacy model, serializer, controller, middleware stack, route name,
feature flag, token ability, database identifier, or patient-visible label at the Nightingale
boundary.

### 6.3 Legacy lifecycle

This ADR does not retire or change `/api/patient/v1`. The reference apps and contract remain
preserved until a separately authorized retirement plan proves:

- no production consumer depends on the legacy surface;
- necessary records and audit trails meet retention requirements;
- upgrade, support, incident, and rollback paths are approved; and
- removal is independently reviewed and deployed through the protected release workflow.

## 7. Identity decision

### 7.1 Independent realm

Nightingale identity is an independent patient-product realm. A future implementation may
reuse product-neutral infrastructure, but it may not treat any of these as sufficient proof:

- a Laravel staff `User`;
- a staff mobile token;
- the legacy `PatientPrincipal` model;
- the presence of a legacy Sanctum ability;
- a legacy access grant;
- a patient/source identifier supplied by a client; or
- a matching email, name, date of birth, MRN, encounter identifier, or source record.

This foundation does not define a credential or identifier class because doing so before the
proofing/session/recovery decision would accidentally canonize an unapproved schema.

### 7.2 Request-scoped identity port

`NightingaleIdentityBoundary` returns one of:

| State           | Meaning in this foundation                                                                       | May continue?                     |
| --------------- | ------------------------------------------------------------------------------------------------ | --------------------------------- |
| `unavailable`   | No approved adapter is configured, or a required identity dependency is unavailable              | No                                |
| `denied`        | An approved future adapter completed evaluation but did not establish the allowed identity state | No                                |
| `verified_self` | A future approved adapter established its defined self relationship                              | Only to later governed evaluation |

`verified_self` is not authorization. It must not bypass identity-link ownership, grant,
purpose, source, cardinality, audit, race, or response gates.

The only implementation supplied now is `UnconfiguredNightingaleIdentityBoundary`; it always
returns `unavailable`. It has no service-container binding and no HTTP caller.

### 7.3 Identity decisions still required

An implementation is held until the program approves:

- identity authority and proofing strength;
- enrollment invitation/challenge lifetime and channel;
- session/access/refresh representation and rotation;
- reauthentication and step-up triggers;
- device binding and compromised-device handling;
- local logout, remote revocation, account removal, and recovery effects;
- merged/corrected identity behavior;
- representative relationship proof, limits, expiration, and revocation;
- minors, sensitive services, confidential encounters, and legal restrictions;
- support-assisted recovery and fraud escalation;
- audit correlation without credential or raw identifier logging; and
- safe, indistinguishable patient-facing errors.

## 8. Inpatient-source decision

### 8.1 Why the current model is not adopted directly

`prod.encounters` is the current operational census spine and is a plausible adapter input,
not a Nightingale authorization source by itself. Reviewed code uses related but not identical
checks:

- the model `active` scope checks `status = active` and `is_deleted = false`;
- messaging additionally requires `discharged_at IS NULL` and a valid grant source link;
- lifecycle reconciliation treats only `status = discharged` plus a non-null discharge time
  as canonical discharge; other combinations are unresolved; and
- the legacy encounter-list service begins from grants rather than a fresh source-state
  confirmation.

The schema comment describes `active | discharged`, but the migration does not add a database
check constraint for encounter status. Unknown values and contradictory status/timestamp
combinations therefore must fail closed.

### 8.2 Request-scoped source port

`NightingaleInpatientContextSource` returns only:

| State               | Required interpretation                                                                                   | May continue?                     |
| ------------------- | --------------------------------------------------------------------------------------------------------- | --------------------------------- |
| `unavailable`       | Adapter is unconfigured, source cannot be reached, freshness is unprovable, or required linkage is absent | No                                |
| `inconsistent`      | Source facts conflict, cardinality is unsafe, or an unknown state is encountered                          | No                                |
| `confirmed_closed`  | The authoritative adapter confirms the context is no longer current                                       | No                                |
| `confirmed_current` | The approved adapter confirms a current inpatient context at its required freshness                       | Only to later governed evaluation |

The port does not return `patient_ref`, `encounter_id`, MRN, source-system identifiers, or a
patient-visible handle. The only implementation supplied now is
`UnconfiguredNightingaleInpatientContextSource`; it performs no database/network query and
always returns `unavailable`.

### 8.3 Future adapter obligations

Before an adapter may return `confirmed_current`, it must prove:

- the authoritative source and source-of-truth precedence;
- identity-to-source linkage and ownership;
- encounter class and admitted/inpatient eligibility;
- facility, unit, cohort, observation, boarding, transfer, and readmission rules;
- admitted, discharged, deleted, cancelled, merged, corrected, and unknown-state handling;
- source-observed time, maximum age, clock skew, and stale behavior;
- duplicate/multiple-current handling;
- transactional or equivalent race protection before serialization;
- source outage, database outage, partial read, timeout, and recovery behavior;
- server-side-only source identifiers and opaque-handle issuance;
- request/evaluation/disclosure audit correlation; and
- no false empty result when the source cannot prove completeness.

## 9. Fail-closed precondition truth table

The foundation gate checks only identity and source prerequisites:

| Identity                 | Source                 | Disposition                       |
| ------------------------ | ---------------------- | --------------------------------- |
| `verified_self`          | `confirmed_current`    | `continue_to_governed_evaluation` |
| Any other identity state | Any source state       | `withhold`                        |
| Any identity state       | Any other source state | `withhold`                        |

`continue_to_governed_evaluation` must never be serialized or interpreted as access granted.
At least identity-link, relationship, grant, purpose, cohort, handle, cardinality, race,
audit, and response gates remain.

## 10. Mechanical enforcement

The following controls make this ADR falsifiable:

- `config/nightingale.php` is code-owned, contains no `env()` activation hook, and keeps
  route, identity, source, disclosure, mutation, and production states disabled;
- `nightingale-foundation.v0.json` has the reserved namespace but zero paths, no security
  scheme, and a non-routable `.invalid` server;
- the held candidate pins its relative path and operation ID while keeping OpenAPI inclusion,
  route registration, client generation, networking, and every activation state false;
- default implementations return `unavailable` and execute no database/network code;
- the precondition gate truth table is covered by dependency-free verification and PHPUnit;
- CI negative tests reject route/identity/source/production activation, namespace drift,
  operation activation, response widening, legacy fields, and production fixture replay;
- CI rejects a Nightingale route registration in `RouteServiceProvider` and the existence of
  `routes/nightingale.php`; and
- the native product boundary continues to reject network clients, staff/legacy identifiers,
  and Android internet permission.

## 11. Consequences

### Positive

- Product ownership and compatibility are explicit before runtime code exists.
- The legacy patient and staff realms cannot become Nightingale authentication by accident.
- Source ambiguity and outages withhold rather than become false “no encounter” results.
- A future implementation has narrow ports for independently reviewed adapters.
- Path/operation naming can be reviewed against patient purpose without exposing grant or
  source-system resource semantics.

### Costs

- Nightingale cannot use the legacy patient controllers as a shortcut.
- Identity and source adapters require explicit designs, tests, and approvals.
- Some reusable legacy behavior will need extraction behind product-neutral services.
- Runtime feature progress remains intentionally slower than a rename until the
  authorization and source definitions are complete.

### Residual risk

Namespace reservation and default-deny ports do not prove that a future adapter is correct.
The most material open risks are identity/recovery design, representative access,
authoritative inpatient eligibility, source freshness, multi-encounter handling, audit
durability, handle lifecycle, and patient-safe failure behavior.

## 12. Reversal and supersession

Changing the prefix, compatibility strategy, identity states, source states, or candidate
path requires:

1. a superseding ADR;
2. synchronized changes to config, foundation contract, candidate, fixtures/verifiers, plan,
   and devlog;
3. renewed collision and compatibility analysis;
4. passing negative tests proving no silent alias or activation; and
5. independent review before route registration.

Removing the reservation alone does not authorize falling back to the legacy patient or
staff APIs.
