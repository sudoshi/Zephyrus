# Nightingale identity, session, recovery, and inpatient-source held-candidate decision

**Decision date:** 2026-07-26

**Status:** Adopted as non-runnable engineering decision evidence only. No identity
provider, proofing method, credential, enrollment channel, session representation, recovery
channel, representative authority, source adapter, source query, facility cohort, freshness
threshold, API operation, client, patient access, non-production integration, pilot, or
production use is approved.

**Runtime state:** Code-owned disabled configuration; unconfigured identity and source
ports; zero Nightingale OpenAPI paths; zero Nightingale routes; zero native network clients.

**Related records:**

- [Product implementation plan](../plans/nightingale-patient-product-2026-07-26.md)
- [Identity, recovery, and protected-state foundation](./IDENTITY-RECOVERY-AND-PROTECTED-STATE-DECISIONS-2026-07-26.md)
- [Route, compatibility, identity, and source ADR](./ROUTE-COMPATIBILITY-IDENTITY-SOURCE-ADR-2026-07-26.md)
- [Encounter-access held candidate](./ENCOUNTER-ACCESS-CANDIDATE-DECISION-2026-07-26.md)
- [Contract ownership and authorization matrix](./CONTRACT-OWNERSHIP-AND-AUTHORIZATION-MATRIX-2026-07-26.md)
- [Identity candidate](./identity/candidates/v0/candidate.json) and
  [64 synthetic identity fixtures](./identity/candidates/v0/fixtures.json)
- [Current-inpatient source candidate](./source-candidates/current-inpatient/v0/candidate.json)
  and
  [42 synthetic source fixtures](./source-candidates/current-inpatient/v0/fixtures.json)

## 1. Decision summary

This slice resolves what can be resolved safely from current source evidence and leaves
policy choices that require accountable human authority explicitly unresolved.

| Boundary         | Adopted now                                                                                                                                                          | Explicitly not adopted                                                                                                                        |
| ---------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| Identity         | Three request-scoped evaluation states: `unavailable`, `denied`, and `verified_self`; only `verified_self` can reach later governed evaluation                       | Identity provider, issuer, protocol, login screen, password, passkey, federated claim, credential, staff identity, or legacy patient identity |
| Audience         | Initial positive-state reasoning is limited to a verified self relationship                                                                                          | Representative, guardian, caregiver, proxy, minor, sensitive-service, or break-glass authority                                                |
| Session          | Fail-closed predicates for missing, expired, revoked, mismatched, reused, risky, unknown, or concurrently changed sessions                                           | Access/refresh token formats, cookies, device binding, durations, limits, rotation protocol, or service implementation                        |
| Enrollment       | Evidence categories and failure behavior only                                                                                                                        | Invitation authority, channel, code format, proofing level, delivery, rate limit, activation, or support process                              |
| Recovery         | Seven safety invariants and five synthetic recovery cases                                                                                                            | Recovery channel, support proof, provider API, replacement credential, patient-facing flow, or activation                                     |
| Protected state  | Existing prohibition on persisted access credentials and durable device identifiers; dormant future-binding descriptor remains test-only                             | Real secret persistence, cross-product migration, cloud synchronization, or production caller                                                 |
| Inpatient source | Four request-scoped states: `unavailable`, `inconsistent`, `confirmed_closed`, and `confirmed_current`; only `confirmed_current` can reach later governed evaluation | Source adapter, database connection, query, raw status mapping, facility/unit/patient-class cohort, threshold, or source identifier           |
| Authorization    | Identity and source are independent prerequisites that can only continue to later evaluation when both are positive                                                  | Granting encounter access, issuing a handle, returning patient data, or activating the held operation                                         |

The design is intentionally stricter than the legacy reference. A positive identity result
does not establish current inpatient status. A positive source result does not establish
identity. Their conjunction still does not establish relationship, grant, purpose,
cardinality, handle ownership, audit completion, race stability, disclosure policy, or
serialization authority.

## 2. Evidence baseline and reproducibility

The review used repository source from branch `codex/nightingale-patient-product`, based on
`origin/main` commit `84b5f830`. Hashes make the observations reproducible and prevent a
later file with the same name from being treated as identical evidence.

### 2.1 Legacy identity, enrollment, and session evidence

| Source                                                                                    | SHA-256                                                            | Material observation                                                                                                                                                                  |
| ----------------------------------------------------------------------------------------- | ------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `app/Services/Patient/PatientAuthService.php`                                             | `98fcfd11460870f75eb5ea7cbb6e5cbf4f45fefe2fccc0c1b5ad50f0a0bf214d` | Local email/password exchange, enrollment challenge completion, access/refresh issuance, rotation, family reuse response, and revocation are coupled in one legacy service.           |
| `app/Http/Middleware/EnsurePatientRealm.php`                                              | `0f4c513a0aeb39e8509417e7d3ed885c5a0ed6ba5d31072bd278a411af1dcc67` | Legacy request identity is coupled to Hummingbird token names, abilities, and patient-session state.                                                                                  |
| `app/Http/Controllers/Api/Patient/AuthController.php`                                     | `2e53bd85515c7eabf476724574a0035f5c77236dcdae8fb5d57e61ea016eb6c7` | Enrollment, credential exchange, refresh, and revoke are exposed as legacy patient operations.                                                                                        |
| `app/Http/Requests/Patient/VerifyEnrollmentChallengeRequest.php`                          | `db606dff38332eda36cef5b060479771f331f1d71a3eac83499cf5dde47c5805` | The request is a two-part challenge/code design, not a provider-neutral Nightingale proofing contract.                                                                                |
| `app/Http/Requests/Patient/TokenRequest.php`                                              | `f1030e037737514987c0f1bd9921786652c080bf4a856fc7fd54fc1625680aac` | The legacy credential exchange accepts email/password and device metadata.                                                                                                            |
| `app/Models/Patient/PatientPrincipal.php`                                                 | `25fd0415462d4ad0e63020b40678e79328cebd92e8b9ba9d6dbf9c5052218370` | The existing model carries patient/representative vocabulary but is not proof of an approved Nightingale realm.                                                                       |
| `app/Models/Patient/PatientIdentityLink.php`                                              | `14c975603f2417769e45ccef9d7c82dd1ef9bf89955181522127d09e36645dea` | An identity-link record exists, but current verification, ownership, merge, ambiguity, and assurance must be independently evaluated.                                                 |
| `app/Models/Patient/PatientEnrollmentChallenge.php`                                       | `85fe910b3e4b457013773b80063416798da93208322de67659b939f61d9c7e53` | Challenge storage supports multiple purpose labels; labels do not provide runnable recovery or representative workflows.                                                              |
| `app/Models/Patient/PatientSession.php`                                                   | `b18a83a4bdc92591a2bc4a220d633d84ff5c6febeb853314d0e11299ea5195d6` | Persistent session families and device metadata are legacy choices, not Nightingale requirements.                                                                                     |
| `database/migrations/2026_07_19_000100_create_patient_experience_identity_foundation.php` | `f093d43a7a84b00ee519f6b1392a8aee5018ab1f43d930ea5abf1c4d7ecaa1e2` | The schema admits patient/representative principals, six relationship values, and recovery/invitation challenge purposes, but schema vocabulary is not operational or legal approval. |
| `tests/Feature/Patient/PatientAuthLifecycleTest.php`                                      | `3603200dbd738e14a26679e761950f8f310c28cd32b6ebc30f5b96f7d6860d1d` | The reference tests prove selected legacy enrollment/token/refresh behavior, not provider-neutral Nightingale assurance or recovery.                                                  |
| `tests/Feature/Patient/PatientSessionManagementTest.php`                                  | `8ec0665944bc49c451af6ac40a50ab96e9ccefbbdea51f61a48a960286ad0bce` | The reference tests prove principal-scoped list/revoke behavior and indistinguishable unknown/cross-principal handling, but do not decide Nightingale session policy.                 |

### 2.2 Current-inpatient source evidence

| Source                                                                                  | SHA-256                                                            | Material observation                                                                                                                          |
| --------------------------------------------------------------------------------------- | ------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------- |
| `app/Models/Encounter.php`                                                              | `59e879ebe2780d093cb73328df30ad437519742aedabd0c02cb3a2cf22caa516` | The `active` scope checks status and deletion, but not the complete current-inpatient lifecycle required here.                                |
| `app/Services/Patient/PatientEncounterAccessService.php`                                | `a593503e20dc8ba45699af4974f36345a0e85b739fa2f428565c8d3d4b079ef9` | The legacy list begins from effective grants and does not independently establish source completeness or canonical current state.             |
| `app/Services/Patient/Messaging/PatientCommunicationEncounterGuard.php`                 | `8a65438b3779252e88c35ee1dd91cc40e0d0d74e8018f5ac1a1d7a210a617976` | Messaging adds a null-discharge requirement and locked grant/source linkage, showing that `status = active` alone is insufficient.            |
| `app/Services/Patient/Messaging/PatientCommunicationLifecycleReconciliationService.php` | `2676d63146f7c88ef8a53fc75b4f26abc01a879795edd328e3b3544034787347` | Reconciliation distinguishes canonical active, canonical discharged, and unresolved combinations, including race-sensitive mutation behavior. |
| `docs/hummingbird/api-contract/hummingbird-patient.v1.yaml`                             | `fb6220b4ef8eb106223624a9785256fdc1603995f281f430a79897905cb45a1b` | The legacy transport is compatibility evidence and cannot define the Nightingale source adapter or identifier policy.                         |

This review was source-only. It did not connect to, query, sample, profile, modify, or replay
production data. Production evidence would not resolve missing product, identity, legal,
clinical-operations, source-governance, or release authority and therefore is not a
permitted shortcut.

## 3. Legacy behavior that is not inherited

### 3.1 Local credentials and token families

The reference flow binds a local email/password principal to persistent access and refresh
credential families and device descriptors. It transactionally activates enrollment,
creates grants, and issues a session. Refresh preserves predecessor evidence to detect
reuse and revoke a family. Those controls are valuable threat inputs, but the following
choices remain rejected for Nightingale:

- email as a canonical patient identifier;
- a local password database as the selected authority;
- Hummingbird token names or abilities;
- persistent access credentials;
- an assumed refresh credential;
- a durable app-generated device UUID;
- device labels as identity proof;
- automatic import of legacy principals, sessions, links, grants, or credentials; and
- shared staff, legacy patient, or Nightingale authentication realms.

### 3.2 Enrollment, recovery, and representatives

The reference schema includes `account_recovery`, `identity_link`, and
`representative_invitation` challenge purposes. It includes `patient` and `representative`
principal types and `self`, `legal_representative`, `guardian`, `caregiver`, `proxy`, and
`other` relationships. Repository inspection did not find a complete runnable recovery or
representative invitation/acceptance/review/revocation route and service lifecycle.

Therefore:

- a schema enum is not an implemented journey;
- an implemented journey would still not establish legal or privacy approval;
- a representative principal is categorically held in the initial candidate;
- every non-self relationship is denied at the identity boundary, without disclosing
  whether the patient, account, invitation, or relationship exists; and
- no patient field or communication ability is inherited by a representative by default.

### 3.3 Operational encounter state

The reviewed source uses multiple related definitions of active/current. The database
schema comment describes `active | discharged`, but the schema does not constrain the
status to those values. Status, deletion, admission/discharge timestamps, linkage,
transfer, correction, merge, and concurrent-change facts can contradict one another.

Nightingale therefore does not equate:

- a row existing with an inpatient context;
- no row with a confirmed closed encounter;
- `status = active` with a complete current determination;
- a valid grant with a current encounter;
- a null discharge timestamp with a valid admission;
- a source record with a patient-visible identifier; or
- successful source evaluation with authorization.

## 4. Identity candidate semantics

The machine-readable candidate defines exactly three states:

| State           | Required interpretation                                                                                                                        | Continue to later governed evaluation? | Authorizes patient access? |
| --------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------: | -------------------------: |
| `unavailable`   | Required identity authority, policy, assurance, principal, session, link, audit, clock, or stable evidence is unavailable or internally unsafe |                                     No |                         No |
| `denied`        | Evaluation completed sufficiently to reject the presented or permitted identity state without disclosing account existence                     |                                     No |                         No |
| `verified_self` | Every candidate self-identity prerequisite is satisfied at the evaluation instant                                                              |                                    Yes |                         No |

`verified_self` is deliberately named more narrowly than `authenticated`. It says nothing
about an encounter, grant, purpose, source, resource, field, or communication capability.
It cannot be cached as durable authorization or passed from the staff or legacy patient
realm.

### 4.1 Positive-state prerequisites

All of the following must be true under one approved and versioned policy before a future
adapter can return `verified_self`:

1. product and identity policy gates are enabled for the exact environment and cohort;
2. the selected provider/authority has completed an approved evaluation;
3. issuer, audience, client, nonce, signature, expiry, and replay controls are valid;
4. required assurance and any step-up are satisfied;
5. the principal is active, self-relationship, and in the independent Nightingale realm;
6. the request-scoped session is active, unexpired, unreused, acceptable under risk policy,
   owned by that principal, and stable through handoff;
7. exactly one verified/current/unmerged/unrevoked identity link belongs to that principal;
8. no recovery or representative transition is in progress;
9. a durable, redacted identity-evaluation audit is committed; and
10. all later authorization gates remain mandatory.

The candidate does not supply the provider, policy, claims, durations, database model,
middleware, or adapter needed to satisfy those predicates.

### 4.2 Identity fixture coverage

The 64 synthetic cases are exhaustive for this candidate version. Their category counts
are:

| Category       | Cases | Safety purpose                                                                     |
| -------------- | ----: | ---------------------------------------------------------------------------------- |
| Activation     |     2 | Product or identity capability disabled                                            |
| Dependency     |     4 | Provider unconfigured, unavailable, timed out, or malformed                        |
| Evidence       |     8 | Missing, invalid, expired, issuer/audience/client/nonce mismatch, or replay        |
| Assurance      |     3 | Missing/below-policy assurance or incomplete step-up                               |
| Principal      |     8 | Missing, wrong realm, staff, pending, inactive, locked, suspended, or closed       |
| Session        |     6 | Missing, expired, idle-expired, revoked, wrong principal, or wrong realm           |
| Integrity      |     6 | Reuse, risk, unknown state, inconsistent principal, or unresolved limits           |
| Identity link  |     9 | Missing, pending, ambiguous, merged, revoked, unverified, mismatched, or duplicate |
| Recovery       |     5 | Requested, incomplete, uncleared binding, live superseded session, or fresh proof  |
| Representative |     6 | Generic representative plus each currently held relationship class                 |
| Audit          |     1 | Durable evaluation audit unavailable                                               |
| Policy         |     3 | Correlation, clock, or policy-version failure                                      |
| Race           |     2 | Session or identity changes during evaluation/handoff                              |
| Positive       |     1 | Every self prerequisite satisfied                                                  |

Every fixture includes an expected state and audit mode. Both `verified_self` fixtures still
set `authorizes_patient_access` to false. Negative mutation tests prove the verifier rejects
a provider selection, representative activation, access-granting positive state, weakened
cross-principal outcome, or production replay.

## 5. Session decision boundary

No Nightingale session format exists yet. The candidate establishes only fail-closed
properties that any proposed session must satisfy.

| Concern          | Required future proof                                                                     | Current result                                        |
| ---------------- | ----------------------------------------------------------------------------------------- | ----------------------------------------------------- |
| Ownership        | Session belongs to the exact Nightingale principal and realm                              | Wrong principal is unavailable; wrong realm is denied |
| Lifetime         | Absolute and idle expiry are explicit, versioned, and evaluated against a trusted clock   | Missing/expired/idle-expired is denied                |
| Revocation       | Revocation is authoritative and takes effect before disclosure                            | Revoked is denied                                     |
| Rotation/reuse   | A credential family, if approved, detects replay and revokes/contains the affected family | Reuse is denied and durably audited                   |
| Risk             | Risk hold and step-up policy are explicit and do not silently degrade                     | Risk hold is denied                                   |
| Cardinality      | Device/session limits have approved shared-device and accessibility behavior              | Unresolved limit policy is unavailable                |
| Race             | Principal/session/link facts are stable through authorization handoff                     | Concurrent change is unavailable                      |
| Patient language | Errors do not disclose account, identifier, relationship, or encounter existence          | Only bounded state is modeled; no copy is approved    |

The candidate does not decide whether a refresh credential exists. If one is later proposed,
its need, format, sender constraint or binding, rotation, user presence, device loss,
recovery, revocation, storage, expiry, and support behavior require separate approval.

## 6. Recovery invariants

A future recovery proposal must preserve every invariant below:

1. begin with no trusted local identity;
2. clear the old local binding before accepting a replacement;
3. require independently verified fresh proof;
4. revoke superseded server sessions before returning a positive identity state;
5. never reveal whether an identifier belongs to a patient before proofing;
6. never call an ambiguous remote outcome successful; and
7. provide a reviewed patient-support path before activation.

`recovery_fresh_proof_complete` is a design fixture, not an implemented recovery path. It
can return candidate state `verified_self` only when the full positive-state prerequisites
also hold. It still does not authorize access. No recovery email, SMS, voice call, bedside
code, help-desk workflow, or provider API has been selected.

## 7. Representative boundary

Initial candidate access is self-only. Legal representative, guardian, caregiver, proxy,
and other relationships remain held because they require at least:

- authoritative relationship source and proof;
- patient consent or applicable legal authority;
- start, expiry, suspension, revocation, and re-verification;
- minor/dependent and age-of-majority transitions;
- confidential encounter and sensitive-service exclusions;
- field-level versus operation-level authority;
- whether the representative can send, amend, or close communications;
- audit actor/subject separation;
- notification routing and shared-device behavior;
- patient and representative visibility of relationship changes;
- non-disclosing invitation, acceptance, decline, and recovery; and
- clinical, privacy, security, legal/HIM, accessibility, patient-advisor, and support
  approval.

No representative is converted to self, and no self result can be reused to impersonate a
representative or patient.

## 8. Current-inpatient source candidate semantics

The source candidate defines exactly four states:

| State               | Required interpretation                                                                                          | Continue to later governed evaluation? | Authorizes patient access? |
| ------------------- | ---------------------------------------------------------------------------------------------------------------- | -------------------------------------: | -------------------------: |
| `unavailable`       | Source, policy, scope, linkage, freshness, audit, clock, or completeness cannot support a reliable determination |                                     No |                         No |
| `inconsistent`      | Available facts conflict, cardinality is unsafe, or lifecycle/version/transition evidence cannot be reconciled   |                                     No |                         No |
| `confirmed_closed`  | Exactly one coherent lifecycle is authoritatively confirmed closed under the approved policy                     |                                     No |                         No |
| `confirmed_current` | Exactly one coherent current inpatient lifecycle is confirmed at the evaluation instant                          |                                    Yes |                         No |

`confirmed_closed` is intentionally distinct from unavailable and inconsistent. A future
patient journey may need a governed transition response after discharge, but the current
encounter-access candidate cannot continue on a closed state.

### 8.1 Positive and closed prerequisites

A future adapter must prove at least:

- an approved authoritative source, query contract, precedence rule, and policy version;
- a trusted evaluation clock and approved freshness rule;
- a verified identity-to-source linkage without exposing source identifiers;
- explicit facility, unit, patient-class, and cohort inclusion;
- exactly one coherent lifecycle;
- complete admission, status, transfer, discharge, deletion, and version facts required by
  policy;
- no unresolved merge, correction, retraction, transfer, or concurrent change;
- stable evidence through governed handoff; and
- durable source-evaluation audit.

For `confirmed_closed`, discharge finality and absence of a conflicting active or pending
transfer record are also required.

### 8.2 Freshness is intentionally unresolved

The candidate does not invent a numeric maximum source age. Its policy version, evaluation
clock, and `maximum_source_age_seconds` remain null, and `thresholds_approved` remains false.
A threshold depends on the authoritative source's delivery guarantees, clinical-operational
meaning, unit/facility workflow, downtime behavior, safety analysis, and support plan.

Until approved:

- a missing observation time is unavailable;
- stale evidence is unavailable;
- an untrusted clock is unavailable;
- a missing policy is unavailable; and
- no default number may be introduced in config, fixtures, native code, or an adapter.

### 8.3 Source fixture coverage

The 42 synthetic cases are exhaustive for this candidate version:

| Category                 | Cases | Safety purpose                                                          |
| ------------------------ | ----: | ----------------------------------------------------------------------- |
| Positive / closed        |     2 | Exactly one fully proven current or closed lifecycle                    |
| Activation               |     2 | Product or source capability disabled                                   |
| Dependency               |     4 | Adapter/source/database unavailable or timed out                        |
| Freshness                |     4 | Policy, observation time, staleness, or clock failure                   |
| Scope                    |     4 | Facility, unit, patient class, or cohort policy not eligible/evaluable  |
| Linkage                  |     4 | Missing, unverified, duplicate, or ambiguous patient linkage            |
| Lifecycle                |    10 | Missing/unknown/deleted/contradictory status and timestamps             |
| Concurrency / transition |     5 | Missing version, source race, transfer, correction/merge, or retraction |
| Absence / completeness   |     2 | No qualifying evidence or required field absent                         |
| Integrity / policy       |     3 | Malformed source, missing policy, or policy-version mismatch            |
| Cardinality              |     1 | More than one current context                                           |
| Audit                    |     1 | Durable source evaluation cannot be recorded                            |

A source outage or missing record never becomes `confirmed_closed`. A stale record never
becomes `confirmed_current`. A contradictory lifecycle never becomes a false empty result.
Negative mutation tests prove the verifier rejects source-query activation, a stale/current
misclassification, a configured source adapter, or a runtime OpenAPI path.

## 9. Combined precondition behavior

The existing backend foundation enumerates all 12 identity/source combinations.

```text
verified_self + confirmed_current
        |
        v
continue_to_governed_evaluation
        |
        +--> identity-link ownership
        +--> relationship
        +--> grant and purpose
        +--> facility/cohort
        +--> opaque handle
        +--> zero/one cardinality
        +--> race recheck
        +--> request/disclosure audit
        +--> response and field-release policy

every other combination --> withhold
```

The continuation disposition is an internal prerequisite result only. It cannot be
serialized as a success, stored as an access grant, used as a native session, or interpreted
as approval to query or disclose data.

## 10. Mechanical enforcement

The new dependency-free verifier cross-checks:

- the foundation OpenAPI artifact still has zero paths and every activation false;
- `config/nightingale.php` remains code-owned, provider/source-null, route-disabled,
  query-disabled, disclosure-disabled, mutation-disabled, and production-disabled;
- candidate identities and held statuses;
- every runtime provider, credential, route, adapter, query, and database field remains
  null or prohibited;
- every candidate activation gate remains false;
- PHP enum values exactly equal the machine-readable candidate state vocabularies;
- only `verified_self` and `confirmed_current` permit later governed evaluation;
- no state authorizes patient access;
- identity and source fixture sets contain exactly 64 and 42 unique required cases;
- every case has its pinned outcome and audit mode;
- fixtures are synthetic-only, contain no credentials/source identifiers, and prohibit
  production replay;
- source freshness thresholds remain unselected; and
- network endpoints, credential assignments, email/IP literals, and raw patient/source
  identifier field names do not enter the machine-readable candidates.

Nine negative mutations verify rejection of:

1. selecting an identity provider;
2. activating representatives;
3. making `verified_self` authorize access;
4. weakening a cross-principal session fixture;
5. permitting production fixture replay;
6. enabling a source query;
7. calling stale source evidence current;
8. adding a runtime OpenAPI path; and
9. configuring a legacy source adapter.

CI runs this verifier with its negative self-tests alongside the foundation, encounter
candidate, backend truth-table, and native no-network/product-boundary verifiers.

## 11. Approval gates before implementation

Candidate design is complete for this version; adoption is not. Implementation remains held
until evidence names accountable owners and independently resolves:

### Identity, enrollment, and session

- provider/authority, protocol, issuer, audience, client, key lifecycle, and downtime;
- proofing level, evidence sources, accessibility alternatives, and fraud controls;
- invitation/enrollment authority, channel, expiry, rate limits, retries, and support;
- session representation, absolute/idle expiry, rotation, revocation, risk, and limits;
- access/refresh credential need, binding, storage, user presence, and deletion;
- generic error taxonomy and durable redacted audit events; and
- provider/data-processing/privacy/security/legal review.

### Recovery and representatives

- lost/stolen/shared/replaced-device behavior;
- independent proof and support-assisted escalation;
- ambiguous provider outcomes and idempotent retry;
- superseded session containment;
- relationship proof, scope, start/expiry/revocation, minors, sensitive services, and
  confidential encounters;
- actor-versus-subject audit and notification rules; and
- patient/representative language, accessibility, and support validation.

### Inpatient source

- authoritative source and precedence;
- exact adapter and query contract;
- facility/unit/patient-class/cohort configuration;
- admission, observation, boarding, transfer, readmission, discharge, deletion, merge,
  correction, and retraction semantics;
- freshness threshold, clock, time-zone, source-observed time, and downtime behavior;
- linkage, cardinality, completeness, version/race, and audit controls;
- opaque handle issuance and source-identifier containment; and
- approved synthetic/deidentified non-production integration and rollback.

### Cross-cutting authorization and release

- operation-specific non-disclosure and IDOR tests;
- request, denial, safety-failure, and disclosure audit durability;
- threat model, abuse cases, mobile security test, privacy review, and penetration testing;
- patient advisor, accessibility, language, clinical/content, legal/HIM, support,
  operations, data-governance, and release approvals;
- default-off feature/cohort controls and production-like failover tests; and
- protected-main exact-SHA CI and manual release evidence.

## 12. Implementation sequence after approval

When the applicable gates are approved, implementation should proceed in independently
reviewable slices:

1. add the approved policy schemas and exact synthetic fixtures without runtime bindings;
2. implement provider and source adapters behind the existing ports, default-off and
   non-production-only;
3. add provider/source contract tests, nondisclosure tests, outage tests, clock/freshness
   tests, race tests, audit tests, and representative/recovery tests;
4. add an operation to a new non-foundation Nightingale OpenAPI version only after the
   route owner and authorization matrix are approved;
5. generate isolated Nightingale clients and retain native networking behind a default-off
   compile/runtime boundary;
6. validate canonical fixtures across Laravel, iOS, and Android on signed simulator and
   API 35 emulator targets;
7. perform approved deidentified non-production integration with rollback and support
   exercises;
8. authorize a bounded pilot separately; and
9. merge and release only through protected `main`, exact-SHA green CI, deployment check,
   and the canonical manual deploy path.

No step may infer completion of the next step. A green fixture verifier is not provider
approval, a bound adapter is not route approval, a route is not patient disclosure
approval, and a successful deployment is not clinical or pilot authorization.

## 13. Residual risks and current disposition

| Risk                                                  | Current control                                                                               | Residual state                                     |
| ----------------------------------------------------- | --------------------------------------------------------------------------------------------- | -------------------------------------------------- |
| Legacy identity silently becomes Nightingale identity | Independent realm, provider null, legacy realm prohibited, verifier mutation                  | Held until provider/proofing approval              |
| Representative gains patient-wide access              | Self-only candidate, all representative fixtures denied                                       | Held until relationship and field/action policy    |
| Recovery trusts a lost or copied device               | No durable access credential/device UUID; recovery begins with no trusted local identity      | Held until independent proof and support design    |
| Source outage appears as no encounter/discharge       | Unavailable is distinct from confirmed closed; no source query exists                         | Held until adapter/downtime policy                 |
| Stale or contradictory encounter appears current      | Freshness unresolved fails unavailable; contradictions fail inconsistent                      | Held until policy/adapter testing                  |
| Positive prerequisite is mistaken for authorization   | All state artifacts set authorization false; 12-state gate continues only to later evaluation | Requires continued verifier and review enforcement |
| Source identifiers leak to clients/fixtures           | Port returns state only; candidate forbids source identifiers; no client exists               | Requires adapter and response review               |
| Production data is used to accelerate design          | Explicit prohibition; synthetic-only fixtures; production replay verifier                     | No production access in this slice                 |

The correct current user-visible behavior remains the no-live-access foundation shell.
