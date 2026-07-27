# Nightingale contract ownership and authorization matrix

**Status:** Governance foundation with a reserved Nightingale route namespace; no API
operation, registered route, usable server, security scheme, client, identity flow, source
query, patient disclosure, mutation, feature flag, pilot, or production use is approved by
this document.

**Decision date:** 2026-07-26

**Machine-verifiable companion:**
[`api-contract/nightingale-foundation.v0.json`](./api-contract/nightingale-foundation.v0.json)

**Held prerequisite companions:**
[identity candidate](./identity/candidates/v0/candidate.json),
[current-inpatient source candidate](./source-candidates/current-inpatient/v0/candidate.json),
their
[decision record](./IDENTITY-SESSION-RECOVERY-AND-SOURCE-CANDIDATE-DECISION-2026-07-26.md),
the held
[patient-journey reference catalog](./PATIENT-JOURNEY-REFERENCE-SCENARIO-CANDIDATE-DECISION-2026-07-27.md),
and the
[complete 256-source migration classification](./JOURNEY-PREFERENCE-PRESENTATION-RELEASE-SOURCE-CLASSIFICATION-2026-07-26.md)

## 1. Outcome

Nightingale now owns a contract-development boundary without pretending that it owns an
active API. The first OpenAPI artifact is intentionally empty:

- zero paths and zero operations;
- no usable server;
- no security scheme or credential format;
- no generated client permission;
- one reserved product namespace with no registered route;
- every activation category explicitly false; and
- a verifier that fails if an operation or legacy/staff route enters the artifact.

This makes ownership concrete while keeping runtime behavior unchanged. It also prevents
the existing Hummingbird Patient contract from becoming the Nightingale contract through a
filename change, route alias, or generated-client shortcut.

## 2. Evidence snapshot and lineage

The source review was performed against the following repository snapshot after merging
`origin/main` commit `84b5f830`:

| Evidence                                                    | SHA-256                                                            | Observed scope                                                                                 | Nightingale use                                                             |
| ----------------------------------------------------------- | ------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------- |
| `docs/hummingbird/api-contract/hummingbird-patient.v1.yaml` | `fb6220b4ef8eb106223624a9785256fdc1603995f281f430a79897905cb45a1b` | OpenAPI 3.1.0; 23 paths; 25 operations; draft/not approved                                     | Compatibility and hazard input only                                         |
| `routes/patient.php`                                        | `e892a9d570668bec6ae54d18cbfd891f7d9df2e2c2f8f6e3689125d6b465af28` | Separate `/api/patient/v1` route group with product/feature/realm/rate gates                   | Current backend evidence; no Nightingale route approval                     |
| `config/hummingbird-patient.php`                            | `d5dafe054077892e649fd44f95edc6bc1bb499e4dffeb4f4390316823db07625` | Default-off legacy flags, draft policy, token/session choices, projection and messaging policy | Control inventory only; legacy names and identity choices are not inherited |

Relevant enforcement was also traced through:

- `EnsureHummingbirdPatientEnabled`, `EnsureHummingbirdPatientFeatureEnabled`, and
  `EnsurePatientRealm`;
- `PatientEncounterAccessGrantPolicy`, `PatientEncounterProjectionPolicy`, and
  `PatientMessageThreadPolicy`;
- `PatientProjectionDisclosureService`, `PatientProjectionContentGuard`, and
  `PatientResponseDecorator`;
- `PatientCommunicationEncounterGuard`, `PatientMessagingPolicyRegistry`, and
  `PatientMessagingService`; and
- the patient principal, session, grant, projection, notification-device, and communication
  models.

The inventory is source-level evidence, not a claim that the current backend is
Nightingale-approved or production-active. No database or deployed configuration was read.

## 3. Ownership and approval responsibilities

Ownership means responsibility for a versioned artifact and its evidence. It does not let
one owner self-approve another discipline's gate.

| Concern                             | Accountable role                                      | Required evidence before an operation can enter a candidate contract                                      |
| ----------------------------------- | ----------------------------------------------------- | --------------------------------------------------------------------------------------------------------- |
| Product purpose and patient journey | Nightingale product owner                             | Patient problem, non-goals, user state, failure/recovery journey, support path                            |
| Contract and compatibility          | Patient Experience Platform contract owner            | Operation schema, compatibility classification, deprecation/rollback, generated-fixture diff              |
| Backend boundary                    | Zephyrus Patient Experience Boundary owner            | Route/middleware/service mapping, default-off configuration, authorization and audit tests                |
| Identity and representatives        | Identity/security owner with privacy and legal/HIM    | Proofing, patient/proxy relationship, enrollment, recovery, session, revocation, enumeration controls     |
| Clinical projection/content         | Named clinical safety and content-release authorities | Field provenance, release state, freshness, uncertainty, correction/retraction, patient-language approval |
| Communication workflow              | Nursing/medical operations plus safety owner          | Topic, responsible pool, responder eligibility, service hours, handoff, escalation, downtime, retention   |
| Privacy and security                | Privacy and security owners                           | Data minimization, threat model, storage/log/push controls, penetration findings, residual risks          |
| Accessibility and language          | Accessibility and language/interpreter owners         | Native semantics, large text, assistive journeys, translation lifecycle, accommodation handling           |
| Support and incident response       | Support/operations owner                              | Lost/shared device, lockout, patient escalation, outage, kill switch, incident and recovery runbooks      |
| Release                             | Independent release authority                         | Exact artifact/SHA, CI evidence, rollback, scope/expiry-bound pilot manifest, signed go/no-go             |

No named individuals or delegates are recorded yet, so every operation remains held.

## 4. Version and namespace decisions

### 4.1 Foundation version

`0.0.0-governance` is not an API version. It is a machine-readable assertion that there is
no runnable Nightingale API. Client generation and route registration are prohibited.

### 4.2 Reserved API namespace

The
[route, compatibility, identity, and inpatient-source ADR](./ROUTE-COMPATIBILITY-IDENTITY-SOURCE-ADR-2026-07-26.md)
reserves `/api/nightingale/v1`. The first held relative path is
`/inpatient-contexts`, with operation ID `listNightingaleInpatientContexts`.

The decision rejects aliases, proxies, redirects, second-prefix mounting, and native fallback
to `/api/patient/v1` or `/api/mobile/v1`. Shared internal services remain possible only
behind Nightingale-owned request/response, authorization, audit, and release adapters.

Reservation is not route registration. The foundation retains zero OpenAPI paths, a
non-routable `.invalid` server, no security scheme, no generated client, no Nightingale route
file or controller, and every activation field false. Endpoint discovery,
certificate/pinning posture if applicable, deep-link boundaries, rollout, dual-running,
telemetry separation, deprecation, and rollback remain operation/release decisions.

### 4.3 Versioning rules

- Adding the first operation changes the contract out of foundation status and requires
  every pre-operation gate in the JSON artifact.
- A breaking schema, identifier, authorization, failure, idempotency, release, freshness,
  correction, or audit change requires a new major API version or a documented
  compatibility window.
- Additive fields must remain safe for older clients. A client must withhold content whose
  state-vocabulary or schema version it does not understand.
- Server and client support windows, minimum versions, forced-upgrade behavior, and
  emergency revocation are release decisions, not implicit semantic-version behavior.
- Generated sources never become authoritative. The reviewed OpenAPI artifact and
  authorization evidence remain authoritative.

## 5. Authorization lattice

Every future request must satisfy all applicable layers. Passing one layer never bypasses
another.

```text
operation exists in approved Nightingale contract
  -> Nightingale product and operation release gates are effective
    -> ingress/media/rate/size policy accepts the request
      -> identity realm, principal, session, and credential are valid
        -> patient or representative relationship is currently effective
          -> encounter grant belongs to that principal and permits the exact scope
            -> resource belongs to the same grant and relationship
              -> disclosure release OR mutation capability gates are satisfied
                -> audit succeeds at the required assurance
                  -> patient-safe no-store response
```

### 5.1 Contract and release gate

- The operation must exist in the current approved Nightingale contract.
- Product, facility, unit/cohort, operation, content, and communication flags are all
  default-off and independently revocable.
- Disabled or unauthorized resource operations use a non-disclosing response. A client
  configuration value cannot authorize an operation the server contract does not contain.

### 5.2 Ingress gate

- HTTPS, supported media type, explicit body-size limit, schema validation, and
  operation-specific rate limiting are mandatory.
- Request IDs are non-secret and cannot be patient/source identifiers.
- Unknown fields fail validation on mutations unless an explicit forward-compatibility
  policy says otherwise.

### 5.3 Identity/session gate

- Only the approved Nightingale patient/representative realm is accepted; staff users and
  staff token abilities are categorically rejected.
- Principal status, lock/closure state, session status, absolute expiry, idle expiry,
  revocation, credential family, assurance level, and risk policy are independently
  evaluated.
- No access/refresh credential format is approved. The legacy Sanctum token-name/ability
  convention is evidence only.
- Pre-proofing failures must not reveal whether an email, phone, medical record, encounter,
  representative relationship, or account exists.
- The held identity/session/recovery candidate pins 64 synthetic outcomes and self-only
  positive-state prerequisites. It supplies no provider, credential, route, binding, or
  authorization, and all representative relationships remain denied.

### 5.4 Relationship and encounter-grant gate

At minimum, a grant decision evaluates:

- exact principal ownership;
- verified patient identity link;
- relationship (`self` or a separately approved representative type);
- purpose of use;
- active status, start, expiry, and revocation;
- opaque Nightingale encounter handle;
- facility/unit/cohort/pilot policy;
- exact operation scope; and
- current grant version.

Unknown relationships, purposes, statuses, scopes, or versions fail closed. The existing
reference projections currently permit `self`; that is not approval for a representative
model. A representative must never inherit all patient fields or communication powers by
default.

### 5.5 Resource-ownership gate

A resource UUID is only a lookup hint. The server must bind the resource to the already
authorized principal, grant, encounter, relationship, and operation. Querying by UUID and
checking later is unsafe if error, timing, audit, or relationship behavior can disclose
existence.

Wrong-principal, wrong-relationship, wrong-grant, wrong-encounter, revoked, expired,
unknown, withdrawn, and omitted resources collapse to the same non-disclosing outcome
unless a reviewed patient-safety need requires a different response after identity is
already established.

### 5.6 Released-projection gate

Patient-visible projections additionally require:

- an effective, approved disclosure-policy version;
- exact projection kind and required scope;
- a relationship allowlist;
- released state and effective release time;
- content-schema and patient-state-vocabulary versions;
- approved field allowlist and content guard;
- source/provenance and observed/generated timestamps;
- freshness class, uncertainty, correction/supersession, and retraction actions;
- no active withdrawal/content action; and
- an auditable disclosure decision.

Draft, review, raw FHIR/EHR, internal note, internal source identifier, inferred risk,
operational score, and unsupported vocabulary are never serialized.

### 5.7 Communication/mutation gate

Communication requires all read gates plus:

- `messaging:read` and `messaging:write` (or future operation-specific scopes);
- a currently active/routable encounter for a new write;
- approved nonurgent topic and urgent-help copy/version;
- effective facility/unit responsibility pool;
- eligible responder and healthy accountable consumer;
- service-hour, escalation, handoff, downtime, retention, correction, and closure policy;
- server-side encryption and redacted logging/audit;
- an idempotency key bound to principal, operation, and canonical payload;
- optimistic concurrency/version checks where state can change; and
- deterministic success, replay, conflict, and ambiguous-network behavior.

The mobile client must never maintain an opaque offline mutation outbox or claim delivery
from a transport acknowledgement alone.

### 5.8 Audit and response gate

- Required audit failure is fail-closed for disclosures/mutations whose evidence must be
  durable; best-effort denial audit cannot turn a denial into an allow.
- Secrets, free text, raw payloads, source identifiers, and push tokens never enter audit
  metadata or logs.
- Successes and errors use `private, no-store, max-age=0`, no indexing, and a stable
  non-diagnostic envelope.
- Response metadata includes request identity, generated time, disclosure-policy version,
  state-vocabulary version, freshness, source-observed time where approved, version, and
  correction/retraction signals.

## 6. Scope and relationship matrix

These are candidate minimums derived from current source controls. They are not final
Nightingale scopes.

| Capability                         | Candidate minimum scope/authority                             | Relationship rule                                                    | Additional mandatory control                                                            |
| ---------------------------------- | ------------------------------------------------------------- | -------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| Profile                            | Valid principal/session                                       | Field-level patient versus representative projection                 | Minimize email/phone/display-name disclosure; non-disclosing identity errors            |
| Accessibility/language preferences | Valid principal/session; server persistence only if necessary | Actor may change only their own experience settings                  | Prefer local settings; split communication consent/channel from display preferences     |
| Encounter list                     | Grant view                                                    | Return only grants effective for exact principal/relationship        | Opaque handles only; no source encounter IDs                                            |
| Today                              | `today:read`                                                  | Projection relationship allowlist                                    | Released projection, freshness, uncertainty, correction/retraction                      |
| My Path/events/discharge/rounds    | `pathway:read`                                                | Projection relationship allowlist, potentially field-specific        | Clinical/content release; no promises or raw notes                                      |
| Care Team                          | `care_team:read`                                              | Projection relationship allowlist                                    | Released roles/contact capabilities only; no schedules or personal contacts             |
| Conversation list/thread           | `messaging:read`                                              | Same grant and relationship as thread                                | Approved policy, non-disclosure, active disclosure rules                                |
| New/reply/amend/close              | `messaging:read` + `messaging:write`                          | Same grant/relationship; field/action-specific representative policy | Active routable encounter, accountable pool, idempotency, concurrency                   |
| Education clarification            | `pathway:read` + messaging read/write                         | Item and projection must be visible to the same relationship         | Opaque education item belongs to current released pathway                               |
| Session management                 | Approved identity/session authority                           | A representative manages only their own sessions                     | Non-disclosing ownership; idempotent revoke; local versus remote outcome separation     |
| Notification registration          | Approved push/device authority                                | Binding belongs to the authenticated actor, not the viewed patient   | Separate provider identity, encryption, generic payload, reinstall/rebind/revoke policy |

## 7. Legacy operation-by-operation disposition

`Candidate` means only that the patient need remains in the Nightingale charter. Every row
is held from implementation and activation.

|   # | Legacy operation                                                                                                         | Current reference controls observed                                                                                | Nightingale disposition and missing gates                                                                                                                                                                                                                                                                                                                                         |
| --: | ------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
|   1 | `POST /auth/enroll/challenge/verify` (`verifyPatientEnrollmentChallenge`)                                                | Product/feature flag, enrollment throttle, challenge/grant verification, audit                                     | **Hold/re-design.** Identity provider, assurance, delivery, enumeration, representative enrollment, recovery, and support are undecided.                                                                                                                                                                                                                                          |
|   2 | `POST /auth/token` (`exchangePatientCredentials`)                                                                        | Product/feature flag, credential throttle, patient principal/password, session/token creation                      | **Hold/re-design.** No password or credential-exchange design is approved.                                                                                                                                                                                                                                                                                                        |
|   3 | `POST /auth/token/refresh` (`refreshPatientSession`)                                                                     | Patient realm, refresh ability/name, active session, rotation/reuse handling                                       | **Hold/re-design.** Nightingale has intentionally not approved a refresh credential.                                                                                                                                                                                                                                                                                              |
|   4 | `POST /auth/token/revoke` (`revokePatientSession`)                                                                       | Patient realm, access-or-refresh ability, session revocation, audit                                                | **Candidate after identity.** Must define idempotent remote outcome and local/remote ambiguity.                                                                                                                                                                                                                                                                                   |
|   5 | `GET /me` (`getPatientProfile`)                                                                                          | Active patient session, disclosure audit, patient profile serialization                                            | **Candidate/minimize.** Patient versus representative fields and contact-data necessity require review.                                                                                                                                                                                                                                                                           |
|   6 | `PUT /me/preferences` (`updatePatientPreferences`)                                                                       | Active session, allowlisted locale/timezone/display/notification fields, audit                                     | **Candidate/split.** Local accessibility settings should not require server storage; channel/preview settings require consent and notification policy.                                                                                                                                                                                                                            |
|   7 | `PUT /me/notification-devices/{deviceUuid}` (`registerPatientNotificationDevice`)                                        | Feature flag, principal ownership, encrypted token, HMAC digest, rebind/revoke transaction                         | **Hold.** No Nightingale push project, installation identity, payload, consent, provider, or support policy exists.                                                                                                                                                                                                                                                               |
|   8 | `DELETE /me/notification-devices/{deviceUuid}` (`revokePatientNotificationDevice`)                                       | Principal ownership, idempotent revocation state, audit                                                            | **Hold with push.** Must define uninstall/logout/account-switch and provider-deletion evidence.                                                                                                                                                                                                                                                                                   |
|   9 | `GET /me/sessions` (`listPatientSessions`)                                                                               | Active access session, principal-owned sessions, safe device projection                                            | **Candidate after identity.** Device descriptors, shared-device risk, and representative ownership are undecided.                                                                                                                                                                                                                                                                 |
|  10 | `DELETE /me/sessions/{sessionUuid}` (`revokePatientSessionById`)                                                         | Principal-scoped UUID, current-session context, idempotent outcome, audit                                          | **Candidate after identity.** Must not revoke another actor's session or overclaim local cleanup.                                                                                                                                                                                                                                                                                 |
|  11 | `GET /encounters` (`listPatientEncounterAccess`)                                                                         | Principal-owned effective grants, policy, opaque grant/encounter UUIDs, audit                                      | **Held first-read candidate.** The [candidate decision](./ENCOUNTER-ACCESS-CANDIDATE-DECISION-2026-07-26.md) removes grant internals and defines 42 synthetic outcomes, but does not satisfy the approval gates or add an operation.                                                                                                                                              |
|  12 | `GET /encounters/{encounterUuid}/today` (`getPatientTodayProjection`)                                                    | `today:read`, grant policy, effective release policy, released projection, relationship allowlist, content actions | **Replaced as a contract-design input only by the held [Nightingale Today candidate](./TODAY-PROJECTION-CANDIDATE-DECISION-2026-07-26.md).** The new candidate removes legacy identifiers and aggregate context, defines field-level governance and 68 synthetic outcomes, and still adds no operation. Identity/source and every clinical/content/language approval remain open. |
|  13 | `GET /encounters/{encounterUuid}/pathway` (`getPatientPathwayProjection`)                                                | `pathway:read` plus projection release/content controls                                                            | **Core read candidate.** Only clinically released, governed pathway content; no draft catalog or predicted promise.                                                                                                                                                                                                                                                               |
|  14 | `GET /encounters/{encounterUuid}/pathway/events` (`getPatientPathwayEvents`)                                             | `pathway:read` plus append-only/released history controls                                                          | **Candidate after pathway.** Event chronology, source reconciliation, corrections, and understandable state vocabulary require approval.                                                                                                                                                                                                                                          |
|  15 | `GET /encounters/{encounterUuid}/discharge-readiness` (`getPatientDischargeReadiness`)                                   | `pathway:read` plus released projection/content controls                                                           | **Candidate/high scrutiny.** Timing uncertainty, criteria, medications, warning signs, and discharge changes need explicit clinical/HIM rules.                                                                                                                                                                                                                                    |
|  16 | `GET /encounters/{encounterUuid}/rounds/summary` (`getPatientRoundsSummary`)                                             | `pathway:read` plus released summary and separation from staff rounds                                              | **Candidate.** Patient-language release and post-round correction rules are required.                                                                                                                                                                                                                                                                                             |
|  17 | `GET /encounters/{encounterUuid}/care-team` (`getPatientCareTeamProjection`)                                             | `care_team:read` plus released projection/content controls                                                         | **Core read candidate.** Only approved roles and contact capabilities; no schedule, internal role, or personal contact leakage.                                                                                                                                                                                                                                                   |
|  18 | `GET /encounters/{encounterUuid}/message-topics` (`listPatientMessageTopics`)                                            | Messaging feature/policy, grant scopes, active encounter and governed routing                                      | **Hold.** Topics, urgent guidance, pools, responders, service windows, language, and downtime need approval.                                                                                                                                                                                                                                                                      |
|  19 | `GET /encounters/{encounterUuid}/threads` (`listPatientMessageThreads`)                                                  | `messaging:read`, principal/grant policy, active/disclosable encounter, no-store response                          | **Hold with communication.** Retention, post-discharge visibility, proxy access, corrections, and retractions are unresolved.                                                                                                                                                                                                                                                     |
|  20 | `POST /encounters/{encounterUuid}/threads` (`createPatientMessageThread`)                                                | Read/write scopes, active/routable encounter, policy/topic/pool, idempotency, encryption, audit                    | **Hold/high risk.** Accountable routing and deterministic delivery evidence must be proven end to end.                                                                                                                                                                                                                                                                            |
|  21 | `POST /encounters/{encounterUuid}/education/{educationItemUuid}/clarifications` (`requestPatientEducationClarification`) | Pathway + messaging scopes, item in current released projection, policy/routing/idempotency                        | **Hold.** Clarification must never record comprehension, completion, consent, or clinical assessment.                                                                                                                                                                                                                                                                             |
|  22 | `GET /threads/{threadUuid}` (`getPatientMessageThread`)                                                                  | Principal owns thread grant, `messaging:read`, non-disclosing not-found                                            | **Hold with communication.** UUID alone never authorizes; representative/post-discharge rules remain open.                                                                                                                                                                                                                                                                        |
|  23 | `POST /threads/{threadUuid}/messages` (`sendPatientMessage`)                                                             | Read/write, thread policy, active route, thread version, urgent-guidance version, idempotency, audit               | **Hold/high risk.** Network ambiguity, consumer health, delivery states, escalation, and support require proof.                                                                                                                                                                                                                                                                   |
|  24 | `POST /threads/{threadUuid}/messages/{messageUuid}/amend` (`amendPatientMessage`)                                        | Same thread/grant, sender/action rules, concurrency, idempotency, append-only correction/retraction                | **Hold/high risk.** Patient-visible history, retention, notification, and staff reconciliation need approval.                                                                                                                                                                                                                                                                     |
|  25 | `POST /threads/{threadUuid}/close` (`closePatientMessageThread`)                                                         | Same thread/grant, close reason allowlist, concurrency, idempotency, routing transition                            | **Hold/high risk.** Closure authority, reopening/new-thread behavior, handoff, and unresolved-work warnings need approval.                                                                                                                                                                                                                                                        |

## 8. Candidate response contract invariants

Before a read operation can be specified, its fixture matrix must prove:

- an envelope with `data`, `meta`, and `links`;
- exact opaque-handle formats with no database keys or source linkage;
- `policy_version`, `state_vocabulary_version`, generated/as-of time, data version, and
  operation-specific freshness/source-observed time;
- explicit no-data, stale, source-unavailable, content-withheld, corrected, superseded, and
  retracted states;
- field-level provenance retained server-side and only patient-safe source language exposed;
- deterministic locale/translation fallback without inventing a clinical explanation;
- unknown enum/vocabulary behavior that withholds rather than guesses; and
- identical non-disclosure behavior for unknown, wrong-principal, wrong-relationship,
  wrong-encounter, revoked, expired, omitted, and withdrawn resources.

Before a mutation can be specified, its fixture matrix must additionally prove:

- canonical request digest and idempotency scope;
- replay of identical content versus conflict on different content;
- optimistic concurrency and current urgent-guidance/policy version;
- exact audit event and redacted fields;
- server acceptance, routing, delivery, handoff, and patient-visible state definitions;
- ambiguous timeout/retry behavior with no false success;
- correction/retraction and retention behavior; and
- logout/revocation preventing replay.

## 9. Required evidence before the first operation

- [ ] Named owners and independent approvers for every applicable row in section 3.
- [x] ADR for route namespace and compatibility/deprecation behavior, mechanically pinned
      with route registration still prohibited.
- [x] Non-runnable identity/session/recovery and current-inpatient-source candidate state
      designs, exact synthetic fixture matrices, and negative verifier mutations, with every
      provider/adapter/route/query/client/activation field still held.
- [x] Source-by-source evidence classification for the bounded identity-input,
      enrollment/recovery, first-read, and error/non-disclosure slice: 65 exact files,
      SHA-256 lineage, platform delta, missing-recovery finding, and negative enforcement of
      zero runtime/route/provider/production adoption.
- [x] Source-by-source evidence classification for the bounded communication and
      notification slice: 130 exact files, SHA-256 lineage, patient-to-staff delivery trace,
      native parity delta, ten pinned findings, and negative enforcement of zero
      runtime/route/provider/channel/payload/polling/offline-queue/production adoption.
- [x] Source-by-source evidence classification for the remaining journey, preference,
      accessibility-presentation, synthetic/debug, persistence, and release slice: 134 exact
      files, SHA-256 lineage, full 256-source universe closure, native context/preference/
      motion/navigation deltas, rejected deployed reference provisioning, and negative
      enforcement of zero implementation/runtime/source/mutation/production/deployment
      adoption.
- [x] Held, non-runnable Today projection candidate: Nightingale-owned opaque handles,
      explicit empty-versus-unavailable section semantics, field-level release/freshness/
      uncertainty/language/correction/offline context, bounded correction/retraction and
      generic non-disclosure behavior, 68 synthetic cases, 14 direct-source checksums, and
      24 adversarial verifier mutations, with the executable contract still at zero paths.
- [x] Held, non-runnable cross-surface patient-journey reference catalog: 15 required
      admission-through-retraction families, 27 synthetic cases, 12 exact evidence-source
      checksums, and 23 adversarial mutations. It keeps every operation, representative,
      clinical release, communication, notification, offline PHI, source, database,
      production, and deployment permission false.
- [x] Route-free generic non-disclosure foundation: exact relationship, context-binding,
      resource, and public-disposition vocabularies; one identifier-free 404 tuple for all
      unknown, revoked, expired, cross-principal, wrong-encounter, omitted-resource, and
      failed-precondition cases; and an independently repeated 40-row truth table with one
      non-authorizing continue row and 39 withholds.
- [ ] Approved Nightingale identity, representative, enrollment, recovery, and session
      contract.
- [ ] Approved authoritative current-inpatient source, lifecycle, cohort, linkage,
      freshness, outage, audit, and adapter contract.
- [ ] Operation-specific authorization and non-disclosure matrix with automated tests.
- [ ] Patient-visible field/source/release/freshness/uncertainty/correction/translation
      matrix.
- [ ] Error/envelope/cache/rate/body-size/audit contract and fixtures.
- [ ] Mobile generated-client boundary and binary scan with network still default-off.
- [ ] Threat model and privacy/security review.
- [ ] Patient-advisor, accessibility, language, clinical/content, legal/HIM, support, and
      operations review.
- [ ] Default-off non-production integration plan and rollback.

Until these gates are met, `paths` remains empty.

The encounter-access, Today, patient-journey reference, identity/source, and three
source-classification ledgers are pre-contract evidence for these gates, not proof that an
approval gate is complete. The route/compatibility ADR, default-deny prerequisite ports,
the previously recorded 174 identity/source/encounter/Today cases, 40 route-free generic
non-disclosure permutations, 27 additional cross-surface journey cases, complete 256-source
inventory, and detailed native/backend deltas exist, but
no owner is named, identity provider/source adapter is approved, clinical field matrix is
released, or backend/native operation parity implementation exists.
