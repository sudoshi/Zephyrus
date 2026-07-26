# Nightingale encounter-access candidate decision

**Status:** Held candidate and synthetic fixture specification only. This record does not add
an OpenAPI operation, reserve a route, enable identity or networking, approve a backend
implementation, authorize patient disclosure, or permit production use.

**Decision date:** 2026-07-26

**Candidate artifacts:**

- [`candidate.json`](./api-contract/candidates/encounter-access/v0/candidate.json)
- [`fixtures.json`](./api-contract/candidates/encounter-access/v0/fixtures.json)

**Governing records:**

- [Contract ownership and authorization matrix](./CONTRACT-OWNERSHIP-AND-AUTHORIZATION-MATRIX-2026-07-26.md)
- [Patient-state vocabulary classification](./PATIENT-STATE-VOCABULARY-CLASSIFICATION-2026-07-26.md)
- [Empty/default-off contract foundation](./api-contract/nightingale-foundation.v0.json)

## 1. Outcome

The first Nightingale read candidate is the patient need currently represented by legacy
`GET /encounters`: discover whether the authenticated person has a currently eligible
inpatient context that Nightingale may use for later, separately authorized experiences.

The candidate deliberately does less than the legacy operation:

- it returns zero or one Nightingale-owned opaque `encounter_handle`;
- it supports only the `self` relationship;
- it exposes no grant identifier, relationship code, authorization scope, validity window,
  source identifier, or database row version;
- it does not claim that an empty result means the person is not in a hospital;
- it rejects an ambiguous multi-context state instead of silently selecting the first row;
- it requires a verified identity link and authoritative current inpatient-context check;
- it requires request-level audit even for an empty success and a separate durable disclosure
  event for a returned handle; and
- it remains outside the executable OpenAPI artifact until every pre-operation gate is
  approved.

The candidate fixtures define exact success, omission, denial, ambiguity, integrity,
dependency, audit, race, and throttling outcomes. A CI verifier checks the fixtures while
also proving that the runnable Nightingale contract still has zero paths.

## 2. Source snapshot

The analysis used the feature branch at `a1cc3a83`, whose merge base contains
`origin/main` commit `84b5f830`.

| Source                                                                                    | SHA-256                                                            | Evidence used                                                            |
| ----------------------------------------------------------------------------------------- | ------------------------------------------------------------------ | ------------------------------------------------------------------------ |
| `routes/patient.php`                                                                      | `e892a9d570668bec6ae54d18cbfd891f7d9df2e2c2f8f6e3689125d6b465af28` | Legacy product/feature/auth/realm/ability/rate middleware                |
| `app/Http/Controllers/Api/Patient/EncounterController.php`                                | `3de0a2edeb487a890dba2166791f5c36b1ce97d9d82ec1967054d0fad251c81b` | Query, per-grant audit, envelope metadata                                |
| `app/Services/Patient/PatientEncounterAccessService.php`                                  | `a593503e20dc8ba45699af4974f36345a0e85b739fa2f428565c8d3d4b079ef9` | Grant filtering, sorting, legacy projection fields                       |
| `app/Policies/Patient/PatientEncounterAccessGrantPolicy.php`                              | `61ca77c6713c23be4e3ab522edf9f279b517aef52d5d75ebf68d5a1b9d4fd58c` | Active-principal and grant ownership/effective-window checks             |
| `app/Models/Patient/PatientEncounterAccessGrant.php`                                      | `237908876e22fe2c3fcd6e55cedde87046288adb4ddced43368273acf97934e5` | Storage fields, casts, defaults, query scope                             |
| `database/migrations/2026_07_19_000100_create_patient_experience_identity_foundation.php` | `f093d43a7a84b00ee519f6b1392a8aee5018ab1f43d930ea5abf1c4d7ecaa1e2` | Database constraints, uniqueness, effective dates, source linkage        |
| `tests/Feature/Patient/PatientApiBoundaryTest.php`                                        | `2235aca6ad0c2b59c43eb5db7b2eff3d74351b47ae98f4d0528a0cd13b1f2508` | Authentication, default-off, single active row, forbidden linkage fields |
| iOS `PatientAPIModels.swift`                                                              | `93c3250b7292c9abe17e3bb932e39c489332c8becc321663b4b8365a1e468759` | Legacy encounter decoder/model                                           |
| iOS `PatientAppViewModel.swift`                                                           | `74918da1c9af1a263b4535dec933c4c6acf4384bb7afa195997a26e5e1c37694` | First-row selection and downstream request fan-out                       |
| Android `PatientApiModels.kt`                                                             | `a1932c3dc2f037f4006c72e083d57bc6219f63de42f348c7909737707d25e115` | Legacy JSON decoder/model                                                |
| Android `PatientSessionCoordinator.kt`                                                    | `10bfbe8782746390ea71242d1c95ff5f09809b61be40533f91cba1e9acfaada2` | First-row selection and scope-driven fan-out                             |

The legacy OpenAPI input remains pinned by hash in the broader contract matrix. No database,
deployed configuration, production response, patient, grant, or session was read.

## 3. Current reference flow

The source path is:

```text
legacy product flag
  -> legacy encounters feature flag
    -> Sanctum authentication
      -> patient realm and exact token-name/ability check
        -> active owned session and expiry checks
          -> patient API throttle
            -> viewAny(active principal)
              -> query active, non-revoked, effective grants for principal
                -> per-row view policy
                  -> per-row durable disclosure audit
                    -> serialize seven grant-derived fields
```

The response currently exposes:

- `encounter_uuid`;
- `grant_uuid`;
- `relationship`;
- raw `scopes`;
- `valid_from`;
- `expires_at`; and
- grant-row `version`.

The envelope adds count, maximum row version, a freshness claim based on maximum
`updated_at`, the draft disclosure-policy version, and the draft patient-state-vocabulary
version. Both native clients decode the seven fields and select `encounters.first` for all
subsequent projection requests.

## 4. Material migration blockers

### 4.1 The response overexposes authorization internals

The native clients need a stable context handle; they do not need a grant UUID, raw server
scopes, authorization dates, relationship database value, or grant version to render a
pre-journey state. Returning those values:

- increases correlation and inference surface;
- couples clients to server authorization vocabulary;
- encourages clients to treat cached scopes or dates as authority;
- complicates representative and sensitive-service policy;
- creates unnecessary compatibility obligations; and
- makes a server-side grant model appear to be a patient-facing domain model.

The candidate therefore contains one field only: a separately issued Nightingale encounter
handle.

### 4.2 “First row wins” is unsafe and product-incomplete

Both legacy native clients silently select the first encounter. The backend sorts by
`valid_from DESC`, then grant UUID. This is not a patient decision, a clinical rule, or a
safe transfer/re-admission reconciliation rule. It can become ambiguous during:

- facility transfer;
- merged/split encounters;
- overlapping observation and inpatient records;
- correction or re-registration;
- duplicate identity linkage;
- representative relationships;
- source lag; or
- an improperly closed prior grant.

The initial Nightingale candidate supports zero or one eligible self inpatient context. More
than one is a governed `account_state_requires_review` error. A future multi-context design
requires a clinically released, patient-understandable selector—not ordering by an
authorization timestamp.

### 4.3 Identity-link state is not part of list eligibility

The legacy grant can carry `identity_link_id`, but list filtering and policy do not require:

- a non-null identity link;
- verified status and verification time;
- ownership by the same principal;
- non-revoked state;
- non-merged/non-superseded state; or
- an assurance level adequate for the operation.

Nightingale cannot disclose even an opaque handle until the current identity relationship
is proven at request time.

### 4.4 Current inpatient state is not authoritatively confirmed

The legacy list checks grant state and dates but not the linked operational encounter's
current state. `source_freshness.status` becomes `current` whenever any grant is returned,
even though “current” only describes grant-row presence/update time. It does not prove that
the patient remains admitted, that a transfer is reconciled, or that the source is healthy.

The candidate distinguishes:

- authoritative confirmation of an eligible current inpatient context;
- authoritative confirmation that a context is closed, which is omitted;
- missing or inconsistent source linkage, which requires account review; and
- source unavailability, which returns temporary unavailability instead of a false empty
  state.

### 4.5 Effective-window semantics drift

The database defines `valid_from` as non-null with a default. The service and policy still
accept null `valid_from`, and the legacy OpenAPI explicitly permits null. The model's
`scopeEffective` does not accept null. The iOS reference fixture also supplies null. These
are four different expectations around a security-relevant time boundary.

Nightingale requires a non-null effective start in storage and evaluates all timestamps
against one server-controlled `authorization_evaluated_at`. Clock comparisons never occur
in the client.

### 4.6 Scope and enum integrity are under-specified

The database checks only that `scopes` is a JSON array. It does not constrain each member to
an approved string. The list service returns the raw array; the OpenAPI regex allows a broad
set of strings; both clients use strings to decide which endpoints to call.

The candidate does not return scopes. A malformed or unknown server scope registry is a
data-integrity failure, and every downstream operation must reauthorize server-side. Future
patient-facing availability indicators need their own product vocabulary and cannot be raw
authorization scopes.

### 4.7 Collection version and freshness are not coherent

The maximum per-grant row version is not a monotonic collection version. Adding, revoking,
or omitting a row can leave it unchanged or reduce it. Likewise, maximum `updated_at` does
not establish completeness or source freshness. The candidate uses neither. It records:

- the exact policy version;
- request generation time;
- one authorization evaluation time; and
- whether the result is complete or withheld.

If a future conditional request is needed, it requires a server-generated collection
revision bound to the complete eligible set and policy—not `max(version)`.

### 4.8 Audit coverage is row-oriented

The legacy implementation records one event per returned grant. An empty successful lookup
records no equivalent evaluation event, even though the result is patient-sensitive and can
affect care navigation. The candidate requires:

- one durable request-level evaluation event for every 200 response, including empty;
- one durable handle-disclosure event for the single returned handle;
- no success if either required audit fails;
- best-effort denial audit where a failing denial sink must not become an availability
  oracle; and
- no raw handles, source identifiers, free text, or credentials in audit metadata.

### 4.9 Native decoders do not enforce the contract equally

The iOS model decodes strings and integers but performs no encounter/grant UUID, enum, scope,
or date validation after decode. Android likewise uses raw strings and only performs limited
date validation elsewhere for sessions. Both clients trust the first list element.

Future Nightingale decoders must validate exact fields and types, reject more than one item,
reject unknown fields for the initial contract, validate the Nightingale handle format, and
withhold the whole context on a malformed response. There is no partially trusted row.

### 4.10 Reference test coverage is too narrow for ratification

The principal backend feature test proves one active row is returned and selected source
linkage fields are absent. It does not comprehensively prove:

- wrong-principal exclusion;
- every status and time boundary;
- identity-link ownership/status;
- representative handling;
- purpose of use;
- source encounter status or outage;
- multiple eligible rows;
- malformed registry data;
- transaction/race revalidation;
- empty-result audit;
- per-handle audit failure;
- stable ordering or bounded cardinality;
- exact response-field allowlisting; or
- iOS/Android parity on malformed list responses.

The candidate fixture matrix makes these omissions explicit before implementation starts.

## 5. Candidate product semantics

### 5.1 Purpose

The operation answers one narrow question:

> Is there exactly one currently verified self inpatient context that this signed-in
> Nightingale principal may use as the starting point for separately authorized patient
> experiences?

It does not answer:

- whether the person is clinically admitted in every source;
- which facility/unit/room they occupy;
- which projections exist;
- what care they are receiving;
- which messages they may send;
- whether a representative has access;
- why access is absent; or
- whether a closed, transferred, corrected, or disputed record exists.

### 5.2 Initial cardinality

|                    Eligible contexts after all gates | Candidate outcome                                                                              | Patient-safety reason                                                 |
| ---------------------------------------------------: | ---------------------------------------------------------------------------------------------- | --------------------------------------------------------------------- |
|                                                    0 | `200` with an empty array, but only after complete authoritative evaluation and durable audit  | Does not invent a stay or disclose an ineligible record               |
|                                                    1 | `200` with one Nightingale opaque handle after recheck and durable evaluation/disclosure audit | Supplies only the minimum navigation capability                       |
|                                            2 or more | `409 account_state_requires_review`, no handles                                                | Prevents arbitrary first-row selection and cross-encounter disclosure |
| Unknown because a required dependency is unavailable | `503 temporarily_unavailable`, no handles                                                      | Avoids falsely representing “no stay”                                 |

The patient UI for an empty result must say that no current stay is available **in
Nightingale right now**, not that the patient is not admitted. It must offer a bedside/support
path. It may not distinguish revoked, wrong-principal, held representative, closed, expired,
or otherwise omitted records.

### 5.3 Relationship

Only `self` is a candidate. Recognized representative relationships are omitted without
confirming their existence. Unknown relationship codes are integrity failures. No
representative row, copy, enrollment behavior, patient field, or communication power is
approved by this decision.

### 5.4 Purpose of use

The exact inpatient purpose code is not yet approved, so it remains an eligibility gate
rather than a committed wire value. A recognized non-inpatient purpose is omitted. An
unknown purpose is an integrity failure. Environment variables cannot define or approve
purpose codes.

## 6. Field decision

| Field                       | Legacy behavior                                  | Candidate decision                   | Rationale                                                                              |
| --------------------------- | ------------------------------------------------ | ------------------------------------ | -------------------------------------------------------------------------------------- |
| `encounter_uuid`            | Returned and used as route handle                | Reject as a cross-product identifier | Nightingale requires a separately issued handle with no implicit legacy/source linkage |
| `grant_uuid`                | Returned                                         | Omit                                 | Client never authorizes itself by grant                                                |
| `relationship`              | Returned as raw enum                             | Omit; candidate is self-only         | No current display or navigation need; representative model held                       |
| `scopes`                    | Returned and drives native requests              | Omit                                 | Raw server authorization vocabulary is not a patient-facing capability contract        |
| `valid_from`                | Returned, nullable in contract/client            | Omit                                 | Server evaluates; client clocks/caches must not authorize                              |
| `expires_at`                | Returned                                         | Omit                                 | Server evaluates on every request; expiry may change/revoke earlier                    |
| `version`                   | Per-grant integer returned; max used in metadata | Omit                                 | Not a coherent collection revision                                                     |
| `encounter_handle`          | Does not exist as Nightingale-owned value        | Sole entry field                     | Minimum opaque navigation handle                                                       |
| location/facility/unit/room | Not in list                                      | Do not add                           | Must come from a separately released patient projection                                |
| admission/discharge/status  | Not in list                                      | Do not add                           | Clinical/operational status requires source and patient-language governance            |
| available surfaces          | Raw scopes imply them                            | Do not add yet                       | Needs an approved product capability vocabulary and operation-specific authorization   |

## 7. Opaque handle requirements

The fixture format is `ntg_enc_` followed by 50 independently generated lower-case base32
symbols (250 bits). Generation must use a cryptographically secure random source without
modulo bias. The prefix identifies only the Nightingale resource type; the random body must
contain no time, facility, patient, source system, database key, grant identifier, or
checksum derived from those values.

Before implementation, the handle design must prove:

- cryptographically secure generation and collision handling;
- case-sensitive canonicalization with no client rewriting;
- one-to-one mapping to the governed authorization record;
- mapping ownership and relationship binding;
- no reuse across principals, representatives, products, environments, or restored
  databases;
- revocation, transfer, merge, correction, and reissue behavior;
- transactional uniqueness;
- HMAC/digest-only audit/search where raw recording is unnecessary;
- no appearance in logs, analytics, crash reports, push payloads, deep links, screenshots,
  clipboard, or support tickets; and
- separate non-production and production namespaces.

The fixture handle is intentionally synthetic and repetitive. It validates shape only and
must never be replayed into an environment.

## 8. Eligibility and serialization lattice

```text
candidate becomes an approved operation in an active Nightingale contract
  -> product, operation, facility, unit/cohort, and pilot gates are effective
    -> ingress, media, request-size, rate, and TLS policy pass
      -> Nightingale realm principal and owned session are active at required assurance
        -> identity link is verified, current, unmerged, and owned by the principal
          -> candidate grant belongs to principal and identity link
            -> relationship is exactly self
              -> purpose is the approved inpatient purpose
                -> grant is active, non-revoked, and effective at one server time
                  -> source linkage is complete and current inpatient state is confirmed
                    -> Nightingale handle mapping is valid and unique
                      -> exactly zero or one candidate remains
                        -> authorization state is rechecked at serialization boundary
                          -> durable evaluation audit succeeds
                            -> durable handle audit succeeds when one handle is returned
                              -> exact no-store envelope is serialized
```

Every unknown status, relationship, purpose, policy version, mapping state, or scope-registry
value fails closed. A client-provided handle, scope, date, relationship, or source value can
never enter this decision.

## 9. Outcome equivalence classes

The 42 synthetic cases are executable documentation. Key equivalence rules are:

| Class                         | Examples                                                                                                                                                               | Exact result                                                         |
| ----------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------- |
| Complete omission             | no grants, revoked/suspended/pending/closed/expired, future/expired window, wrong principal, held representative, recognized purpose mismatch, source-confirmed closed | `200 success_empty`; one durable evaluation audit; no reason exposed |
| Release unavailable           | product or operation disabled                                                                                                                                          | `404 not_found`; best-effort denial audit                            |
| Authentication absent/invalid | no credential; missing/expired/revoked session                                                                                                                         | `401 authentication_required`; no account/grant detail               |
| Realm/account denied          | wrong realm, inactive or locked principal                                                                                                                              | `403 access_unavailable`; generic account-safe copy                  |
| Identity/source ambiguity     | missing/revoked/merged/mismatched identity link, missing source link, multiple eligible contexts, authorization race                                                   | `409 account_state_requires_review`; no handles                      |
| Invalid governed data         | unknown relationship/status/purpose, malformed/colliding handle, malformed scope registry, policy mismatch                                                             | `503 temporarily_unavailable`; no partial result                     |
| Dependency/audit outage       | source unavailable, database unavailable, required audit unavailable                                                                                                   | `503 temporarily_unavailable`; never translate outage to empty       |
| Throttle                      | principal-specific limit exceeded                                                                                                                                      | `429 rate_limited`; no existence information                         |
| Eligible                      | exactly one fully verified self inpatient context                                                                                                                      | `200 success_one`; request and disclosure audits                     |

Wrong-principal and held-representative rows are deliberately indistinguishable from no
candidate row. Invalid data is not silently omitted because returning a partial “complete”
list could conceal a safety-relevant integrity problem.

## 10. Exact candidate response

The canonical one-handle success shape is:

```json
{
    "data": {
        "encounters": [
            {
                "encounter_handle": "ntg_enc_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
            }
        ]
    },
    "meta": {
        "request_id": "ntg_req_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
        "generated_at": "2026-07-26T15:00:00Z",
        "policy_version": "nightingale-encounter-access-policy.v0-candidate",
        "authorization_evaluated_at": "2026-07-26T15:00:00Z",
        "completeness": "complete"
    },
    "links": {}
}
```

Required HTTP headers are:

```text
Cache-Control: private, no-store, max-age=0
Pragma: no-cache
X-Robots-Tag: noindex, nofollow, noarchive
Vary: Authorization
Content-Type: application/json
```

The candidate does not include `state_vocabulary_version`: it contains no patient-facing
state code or label. Adding irrelevant metadata would imply a dependency that does not
exist. It also includes no self link, because a route has not been assigned and an endpoint
URL is not patient data.

## 11. Concurrency and audit requirements

Implementation must define one evaluation snapshot or equivalent optimistic transaction:

1. capture one server-controlled authorization time;
2. evaluate principal/session/identity/grant/relationship/purpose/source/handle state;
3. reject more than one eligible context;
4. recheck all mutable record versions immediately before serialization;
5. persist the request-level evaluation event;
6. persist the handle-disclosure event when applicable; and
7. serialize only after required audit commits.

A source connector that cannot participate in the database transaction must return a
versioned/expiry-bounded confirmation that is revalidated under the approved source policy.
Downstream operations still repeat authorization; a prior list response never grants access.

The request audit may record governed reason codes and internal foreign keys. Patient-visible
responses collapse them to the fixture templates. Raw handles, source identifiers, patient
names, care text, credentials, request bodies, IP addresses in application logs, and free
text are not permitted audit metadata.

## 12. Future backend implementation requirements

No implementation is approved, but a reviewable change would need:

- a Nightingale-owned contract operation and route/compatibility ADR;
- a separate default-off Nightingale configuration boundary, not an alias to Hummingbird
  flags;
- a Nightingale realm and session decision completed first;
- an allowlisted purpose/relationship/status/scope registry in code;
- a verified identity-link join and ownership assertion;
- an approved operational source adapter and current-inpatient definition;
- a new Nightingale handle mapping with uniqueness and revocation rules;
- a bounded query that cannot return more than the initial maximum;
- one consistent effective-time predicate shared by query and policy;
- deterministic transaction/recheck/audit behavior;
- exact response-resource classes rather than model serialization;
- generic errors and the exact no-store headers;
- no access to staff APIs or patient free text; and
- default-off non-production integration before any production consideration.

The current `PatientEncounterAccessService` may inform tests, but it cannot simply be called
and its projection renamed.

## 13. Future native implementation requirements

Until the contract operation is approved, neither Nightingale app may contain its route,
network client, decoder, or fixture runtime. A future native slice must:

- generate or implement models only from the approved Nightingale contract;
- accept an exact zero-item result and reject more than one item or any malformed item;
- accept only the exact handle pattern and exact response fields;
- never select `.first` as an ambiguity strategy;
- never persist the handle to preferences, files, logs, analytics, pasteboard, notifications,
  deep links, or screenshots;
- clear the handle on logout, identity transition, recovery, revocation, account removal, or
  an authentication-invalid response;
- treat a handle as a lookup capability, never proof of authorization;
- show the approved “not available in Nightingale right now” empty experience;
- show outage/account-review guidance without claiming the person is or is not admitted;
- avoid offline or cached continuation into patient data;
- pass identical malformed, unknown, zero, one, and multiple-item fixtures on iOS and
  Android; and
- preserve the privacy cover and Android secure-window controls.

## 14. Required automated evidence before contract inclusion

Backend/contract tests must cover every fixture plus:

- exact route absence while held and default-off behavior after introduction;
- staff, wrong-realm, wrong-principal, wrong-identity-link, and representative
  non-disclosure;
- every grant status, revocation, and boundary-time comparison;
- exact purpose/relationship/status/scope registries;
- verified/merged/revoked identity links and ownership mismatch;
- source closed, transferred, corrected, stale, missing, and unavailable states;
- zero, one, duplicate, and multiple eligible contexts;
- handle generation entropy, collision, environment isolation, rotation, and revocation;
- transaction/race mutation between query, audit, and serialization;
- request-level and disclosure-audit success/failure;
- database, source, audit, and policy outages;
- exact allowlist serialization and forbidden-field/binary scans;
- no-cache headers for every success/error;
- rate limiting without cross-principal coupling;
- load bounds and query plan;
- iOS and Android fixture parity; and
- logout/recovery/revocation clearing with no durable handle residue.

Testing must use synthetic records in isolated test databases. Production patient creation
or replay of these fixtures is prohibited.

## 15. Open decisions and approvals

- [ ] Name the accountable product, contract, backend, identity, privacy/security,
      accessibility, support, clinical-safety, source-integration, and release owners.
- [ ] Approve the Nightingale route namespace and compatibility/deprecation ADR.
- [ ] Approve identity proofing, assurance, session, recovery, and representative model.
- [ ] Define the exact eligible inpatient context across admission, observation, transfer,
      leave, discharge, correction, downtime, and merge.
- [ ] Approve the purpose/relationship/status/scope registries.
- [ ] Approve opaque handle generation, storage, rotation, collision, environment, logging,
      and revocation design.
- [ ] Resolve zero/one initial pilot bounds and the future multi-context experience with
      patient advisors and operational owners.
- [ ] Approve error and empty-state language through patient, accessibility, language,
      support, privacy, and clinical-safety review.
- [ ] Implement and independently review all backend/contract/native tests.
- [ ] Complete threat model, penetration testing, non-production integration, rollback, and
      exact-SHA release evidence.

Until every applicable gate is satisfied, the Nightingale OpenAPI `paths` object remains
empty, the route and operation ID remain null, and native network access remains disabled.
