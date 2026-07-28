# Nightingale identity input, enrollment/recovery, first-read, and error source classification

**Status:** Complete evidence classification for this bounded migration slice; no
implementation approval

**Reviewed source commit:** `b1078405de2dacd767ec69da11197f1e755d8277`

**Normative machine-readable ledger:**
[`migration/candidates/v0/source-classification.json`](./migration/candidates/v0/source-classification.json)

**Mechanical verifier:** `scripts/ci/verify-nightingale-source-classification.mjs`

## 1. Executive determination

Sixty-five exact legacy contract, backend, database, iOS, Android, and test files were
reviewed and pinned by SHA-256. The result is intentionally conservative:

1. **Nightingale still has no approved identity input.** Email/password, invitation UUID,
   invitation token, verification code, display name, local account password, durable device
   UUID, and legacy access/refresh tokens remain Hummingbird decisions. None is approved for
   Nightingale.
2. **The repository does not contain a complete patient account-recovery workflow.** The
   database accepts an `account_recovery` challenge purpose and native decoders recognize a
   recovery auth-method label, but there is no patient recovery route, request contract,
   proofing service, UI, completion transaction, session invalidation policy, or recovery
   acceptance suite. Schema vocabulary is not functionality.
3. **Both native clients make an unsafe first-read decision.** iOS uses
   `encounters.data.encounters.first`; Android uses `firstOrNull()`. Backend ordering by
   `valid_from DESC` and grant UUID is neither a patient choice nor a clinical reconciliation
   rule. Nightingale continues to require zero or one verified current self context and
   returns a governed review state when more than one is eligible.
4. **The legacy server has valuable anti-disclosure controls, but they do not constitute a
   Nightingale contract.** Central safe-error mapping, generic projection `404`, request-time
   ownership checks, no-store headers, content allowlists, and audit-before-disclosure are
   principles to reimplement. The Hummingbird route, realm, scopes, schemas, error codes,
   English copy, and release policies remain product-specific.
5. **Native error behavior is materially weaker and inconsistent.** iOS can render server
   messages directly. Android can render the server message for HTTP 422. iOS collapses
   projection `404` to absence, while Android collapses both `403` and `404`. Both collapse
   incompatible patient-state vocabulary to absence. These paths can misrepresent withheld,
   unavailable, incompatible, and genuinely empty information as the same patient state.
6. **No source in this classification is approved for runtime adoption.** The manifest
   mechanically pins all implementation, route, provider, credential migration, first-row
   selection, server-message passthrough, projection-absence conflation, production query,
   production replay, and patient/principal creation permissions to `false`.

This milestone completes source classification, not identity ratification, API
implementation, clinical release, pilot authorization, production data validation, or
deployment.

## 2. Scope and method

### 2.1 In scope

The review covers four connected domains:

- identity data collected or decoded by a patient client;
- enrollment, recovery, session establishment, refresh, and revocation;
- the first read that selects an inpatient context and fans out to patient projections; and
- patient-facing errors, anti-enumeration, non-disclosure, absence, incompatibility, and
  unavailable-state handling.

The evidence set includes:

| Surface                     |  Files | Purpose                                                                |
| --------------------------- | -----: | ---------------------------------------------------------------------- |
| Legacy contract             |      1 | Request/response/security/error compatibility input                    |
| Legacy backend and database |     33 | Routes, gates, controllers, models, policies, services, and migrations |
| Backend tests               |      8 | Existing guarantees and missing negative cases                         |
| Legacy iOS and tests        |     11 | Input lifecycle, transport, models, first-read projection, error UX    |
| Legacy Android and tests    |     12 | Input lifecycle, transport, models, first-read projection, error UX    |
| **Total**                   | **65** | Every file is independently classified and checksum-pinned             |

Category membership overlaps by design. Counts are:

| Category                 | Classified files |
| ------------------------ | ---------------: |
| Identity input           |               44 |
| Enrollment and recovery  |               37 |
| First-read projection    |               47 |
| Error and non-disclosure |               65 |

### 2.2 Out of scope

This review did not:

- connect to or query a production database;
- create a patient, principal, identity link, grant, session, challenge, or projection;
- inspect real patient, encounter, credential, or source-system data;
- approve an identity provider, source adapter, facility, unit, cohort, or relationship;
- register `/api/nightingale/v1` or any operation beneath it;
- generate a network client;
- copy a Hummingbird model, route, schema, credential, storage key, or error code;
- approve patient copy, translations, recovery support, or a clinical projection;
- deploy any code; or
- change the legacy Hummingbird patient application.

### 2.3 Classification vocabulary

| Disposition                  | Meaning                                                                                                                        |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| `reimplement_principle_only` | A narrow safety property may inform new Nightingale code after approval; no source code or product decision is reusable as-is  |
| `evidence_only`              | The source records existing behavior or test technique; it is neither an implementation requirement nor an approval            |
| `held`                       | The behavior depends on unresolved identity, clinical, privacy, source, contract, accessibility, support, or release decisions |
| `reject`                     | The behavior conflicts with the independent product boundary and must not migrate                                              |

The classification is not a code-reuse license. Even a
`reimplement_principle_only` source remains prohibited from runtime adoption by this
milestone.

## 3. End-to-end behavior traced

### 3.1 Legacy identity and enrollment path

```text
native email/password
  -> POST /api/patient/v1/auth/token
    -> Hummingbird product + token-exchange gates
      -> lower-case email lookup
        -> password check, with a dummy hash for unknown accounts
          -> active/unlocked principal check
            -> Hummingbird patient session row
              -> Sanctum access + refresh bearer tokens
                -> native legacy credential store

native invitation UUID + invitation token + code + name + email + password
  -> POST /api/patient/v1/auth/enroll/challenge/verify
    -> Hummingbird product + enrollment gates and throttle
      -> row-lock challenge
        -> verify challenge and code hashes
          -> row-lock grant, identity link, and principal
            -> activate local principal and grant
              -> consume challenge atomically
                -> issue Hummingbird session and bearer tokens
```

Useful properties include comparable unknown-account password work, bounded inputs, row
locking, atomic challenge consumption, one-way secret hashes, failure counters, refresh
rotation, refresh-reuse response, local-store failure cleanup, session revocation, and
auditing.

The path is not suitable for Nightingale because:

- the identity provider is an unapproved local email/password account;
- invitation issuance and delivery authority are outside the patient API and not governed as
  a complete operating workflow;
- the client collects seven enrollment fields and makes local password/shape decisions;
- device identity and metadata are part of the legacy session contract;
- representative enrollment and identity transition behavior are incomplete;
- account recovery is not implemented;
- Hummingbird scopes, routes, models, token names, and storage keys cross the boundary; and
- no Nightingale-specific assurance, correlation, support, or revocation acceptance decision
  exists.

### 3.2 Legacy first-read path

```text
access token
  -> GET legacy profile and encounter grants
    -> server filters active owned grants
      -> server sorts valid_from DESC, then grant UUID
        -> client silently takes first row
          -> client reads raw grant scopes
            -> client concurrently requests Today, Pathway, Pathway Events,
               Discharge Readiness, Rounds Summary, and Care Team
              -> client converts not-found/incompatible responses to nil
                -> snapshot fabricates or infers local presentation state
```

The first list endpoint does not prove that a source encounter is authoritatively current.
It proves only that a legacy grant row passed legacy status, effective-time, relationship,
and policy checks. Its `source_freshness.status = current` claim is derived from the presence
of grant rows, not source health or an admission-state observation.

The clients then compound that uncertainty:

- both choose the first row without a patient or clinical rule;
- both use raw scopes to decide which server endpoints to call;
- neither requires a Nightingale-owned current-inpatient source result;
- neither requires a verified, unmerged, same-principal identity link at the list boundary;
- both map projection failures to nullable local values;
- both withhold incompatible state vocabulary without an explicit incompatibility state;
- iOS can generate fresh random UUIDs when three server identifiers are malformed; and
- fallback copy can imply “no released update” or “no active stay” without distinguishing a
  complete authoritative empty evaluation from dependency failure.

### 3.3 Legacy error path

```text
controller/service/framework failure
  -> patient response decorator
    -> allowlisted legacy code or status-derived fallback
      -> server-owned English message
        -> no-store/no-index response
          -> native decoder
            -> native product-specific mapping
```

The backend decorator correctly discards arbitrary framework messages for covered patient
routes and maps errors through an allowlist. It also adds no-store, no-index, and
authorization variance headers. Those are strong candidate controls.

The end-to-end contract still has gaps:

- `AuthController` constructs responses from internal failure messages before the outer
  decorator repairs them; the safety property therefore depends on complete, correctly
  ordered middleware and exception coverage.
- Validation responses preserve per-field error detail. That may be appropriate, but fields,
  localization, enumeration risk, assistive-technology behavior, and sensitive values need a
  Nightingale decision.
- iOS `PatientFacingError` returns `.server` and `.unauthorized` messages directly.
- Android's generic auth mapping still returns `PatientApiException.message` for HTTP 422.
- iOS recognizes only projection `404` as nullable absence; Android also recognizes `403`.
- A vocabulary-version mismatch becomes `nil` on both platforms.
- A missing client scope becomes `nil` without consulting a patient-facing capability
  contract.
- A projection failure other than the locally swallowed states can fail the broader
  first-load operation; iOS uses a single concurrent tuple and Android loads the bundle
  synchronously.
- Neither client has a complete state model separating `not_released`, `not_authorized`,
  `temporarily_unavailable`, `contract_incompatible`, `source_stale`, `account_review`, and
  `authoritative_empty`.

## 4. Platform parity and delta

| Concern                         | Backend reference                                                           | iOS reference                                                   | Android reference                                                                            | Nightingale decision                                                                                                             |
| ------------------------------- | --------------------------------------------------------------------------- | --------------------------------------------------------------- | -------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Primary sign-in                 | Local lower-cased email + password                                          | Email + password                                                | Email + password                                                                             | **Reject.** Provider and assurance are unapproved                                                                                |
| Enrollment input                | Challenge UUID/token/code + name/email/password + optional device           | Same seven human-entered fields                                 | Same seven fields                                                                            | **Hold.** Redesign from approved proofing and recovery policy                                                                    |
| Secret input lifetime           | Request-scoped; hashes persisted for challenge material                     | SwiftUI `@State`; no explicit background clear in this view     | Compose volatile state; clears selected secrets after submit, not a complete lifecycle proof | **Reject current flows.** Future volatile input must clear on background, identity change, sign-out, recovery, and completion    |
| Unknown credential handling     | Dummy password hash and generic invalid-credentials result                  | Displays server message through generic auth error mapping      | Maps 401 generically                                                                         | Reimplement constant-work and anti-enumeration principles; client renders owned localized copy                                   |
| Inactive/locked account         | Distinct allowlisted codes and statuses                                     | May render server message                                       | Mostly status-derived generic copy                                                           | Needs one approved enumeration and support policy                                                                                |
| Recovery                        | Challenge schema enum only; no patient route/service                        | Decoder enum only; no recovery UI                               | Decoder enum only; no recovery UI                                                            | **Not implemented.** Do not infer parity from enum/schema presence                                                               |
| Token/session                   | Sanctum access/refresh family, rotation, revocation, legacy device metadata | Persists legacy access/refresh tokens in Hummingbird Keychain   | Persists legacy access/refresh/session and durable device UUID                               | **Reject migration.** Independent provider, credentials, session model, and storage descriptor required                          |
| Encounter list                  | Returns seven grant-derived fields ordered by `valid_from DESC`, then UUID  | Chooses `.first`                                                | Chooses `.firstOrNull()`                                                                     | **Reject first-row selection.** Zero/one self context only; multiple is governed review                                          |
| Current inpatient truth         | Not proved by encounter list; grant-row update time labeled current         | Trusts returned row                                             | Trusts returned row                                                                          | Require an approved authoritative source port with explicit unavailable/inconsistent/closed/current states                       |
| Client authorization            | Server reauthorizes projections                                             | Raw scopes control request fan-out                              | Raw scopes control request fan-out                                                           | Client scopes never authorize; future capability hints must be separately owned and server always reauthorizes                   |
| Unknown/cross-principal project | Generic `404`                                                               | `404` becomes nil                                               | `403` and `404` become nil                                                                   | Server anti-oracle response plus explicit client-safe disposition; platform behavior must be identical                           |
| Unreleased projection           | Generic `404`                                                               | Becomes nil                                                     | Becomes nil                                                                                  | Do not represent as a clinical empty state; use approved generic unavailable/withheld presentation                               |
| Vocabulary incompatibility      | Version sent in envelope; backend validates configured codes                | Incompatible version becomes nil                                | Incompatible version becomes nil                                                             | Fail closed with a distinct contract-incompatible state, telemetry without PHI, safe retry/update guidance, and no inferred text |
| Malformed projection UUID       | Backend emits stored UUID                                                   | Some item/stage/member UUIDs become new random UUIDs            | Decoder generally retains strings with bounded validation in selected paths                  | Reject whole affected projection; never invent a server identity                                                                 |
| Server error message            | Central decorator emits allowlisted English copy                            | `.server` and `.unauthorized` message can be displayed verbatim | HTTP 422 can display decoded server message                                                  | No server-message passthrough; map stable Nightingale codes to local approved localized copy                                     |
| Validation detail               | Preserves `errors` field                                                    | Decoded through legacy envelope                                 | Decoded through legacy envelope                                                              | Exact safe fields, ordering, localization, announcement, and value suppression require approval                                  |
| No-store                        | Applied by envelope/decorator                                               | Ephemeral URLSession policy is tested                           | Cacheless/no-cache transport policy is tested                                                | Reimplement and verify on every success, error, redirect, background, and OS cache path                                          |
| Audit before disclosure         | Projection success requires durable audit; denials are best effort          | No independent receipt validation                               | No independent receipt validation                                                            | Retain server-side durable audit principle; define failure semantics and correlation contract                                    |

## 5. Source-by-source classification

The ledger is normative for exact hashes and full decisions. The tables below provide a
human-reviewable index so that no grouped statement conceals an unreviewed file.

### 5.1 Contract, route, configuration, and database

| Source                                                                                    | Disposition   | Decisive finding                                                                                         |
| ----------------------------------------------------------------------------------------- | ------------- | -------------------------------------------------------------------------------------------------------- |
| `docs/hummingbird/api-contract/hummingbird-patient.v1.yaml`                               | Evidence only | Inventory all request/response/security/error shapes; copy no operation or schema                        |
| `routes/patient.php`                                                                      | Reject        | Every path, name, middleware stack, feature flag, and token ability is Hummingbird-owned                 |
| `config/hummingbird-patient.php`                                                          | Reject        | Legacy environment flags, token policy, copy, source keys, and policy versions are not Nightingale gates |
| `database/migrations/2026_07_19_000100_create_patient_experience_identity_foundation.php` | Held          | Useful constraints coexist with unapproved principals, purposes, relationships, sessions, and linkage    |
| `database/migrations/2026_07_19_000200_create_patient_experience_projection_kernel.php`   | Held          | Release/provenance concepts require an independently approved Nightingale schema                         |

### 5.2 Backend boundary and controllers

| Source                                                               | Disposition                | Decisive finding                                                                                             |
| -------------------------------------------------------------------- | -------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `app/Http/Concerns/RendersPatientEnvelope.php`                       | Reimplement principle only | Exact envelope and no-store behavior are useful; legacy metadata ownership is not                            |
| `app/Http/Controllers/Api/Patient/AuthController.php`                | Held                       | Bounded failure handling is evidence; legacy enrollment/password/refresh paths are held                      |
| `app/Http/Controllers/Api/Patient/EncounterController.php`           | Held                       | Request-time checks and audit help; seven grant fields and grant-derived freshness overexpose internals      |
| `app/Http/Controllers/Api/Patient/EncounterProjectionController.php` | Reimplement principle only | Generic not-found collapse is useful; projection kinds, paths, scopes, and service coupling remain legacy    |
| `app/Http/Controllers/Api/Patient/MeController.php`                  | Reject                     | Profile response exposes identity/contact/verification/preference fields without Nightingale field ownership |
| `app/Http/Controllers/Api/Patient/SessionController.php`             | Reimplement principle only | Owned revocation and unknown/cross-principal equivalence help; session/device contract does not              |
| `app/Http/Requests/Patient/TokenRequest.php`                         | Reject                     | Email/password and durable-device input are unapproved identity-provider choices                             |
| `app/Http/Requests/Patient/VerifyEnrollmentChallengeRequest.php`     | Held                       | Bounded two-part proof is evidence; enrollment shape, password, and device decisions require redesign        |
| `app/Http/Middleware/EnsureHummingbirdPatientEnabled.php`            | Reimplement principle only | Product-default-off is required, but the Hummingbird flag cannot authorize Nightingale                       |
| `app/Http/Middleware/EnsureHummingbirdPatientFeatureEnabled.php`     | Reimplement principle only | Per-operation kill switches are required, with Nightingale-owned operations and approvals                    |
| `app/Http/Middleware/EnsurePatientRealm.php`                         | Reimplement principle only | Exact realm/session ownership is useful evidence; provider, tokens, model, and errors must be independent    |
| `app/Http/Middleware/ProtectPatientResponse.php`                     | Reimplement principle only | Central decoration is useful only with independent route coverage and a Nightingale safe-error registry      |

### 5.3 Backend identity, access, projection, and error sources

| Source                                                                   | Disposition                | Decisive finding                                                                                                         |
| ------------------------------------------------------------------------ | -------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| `app/Models/Patient/PatientPrincipal.php`                                | Reject                     | Local Hummingbird account/password and profile fields cannot migrate                                                     |
| `app/Models/Patient/PatientIdentityLink.php`                             | Held                       | Encrypted/digested linkage is useful; authority, assurance, merge, and revocation lifecycle are unresolved               |
| `app/Models/Patient/PatientEnrollmentChallenge.php`                      | Held                       | Hashing, expiry, attempts, consumption, and revocation help; default enrollment purpose is not recovery governance       |
| `app/Models/Patient/PatientSession.php`                                  | Held                       | Session-family lifecycle is evidence; token/device/network metadata and migration are rejected                           |
| `app/Models/Patient/PatientEncounterAccessGrant.php`                     | Held                       | Ownership/effective state helps; raw scopes, relationship defaults, linkage, and nullability are unresolved              |
| `app/Models/Patient/PatientEncounterProjection.php`                      | Held                       | Immutable release concepts help; kinds, schema, content, scope, and release state need independent ownership             |
| `app/Models/Patient/PatientReleasePolicyVersion.php`                     | Held                       | Versioned effective policy is required; no legacy row or environment selection is approval                               |
| `app/Policies/Patient/PatientEncounterAccessGrantPolicy.php`             | Held                       | Defense in depth lacks identity-link assurance and authoritative current-source gates                                    |
| `app/Policies/Patient/PatientEncounterProjectionPolicy.php`              | Held                       | Policy remains coupled to legacy grants, scopes, relationships, releases, and version selection                          |
| `app/Services/Patient/PatientAuthFailure.php`                            | Reimplement principle only | Typed internal codes help; internal messages must never be serialized directly                                           |
| `app/Services/Patient/PatientAuthService.php`                            | Held                       | Atomic proof/session controls help; local accounts, device fingerprinting, legacy models, and missing recovery block use |
| `app/Services/Patient/PatientEncounterAccessService.php`                 | Held                       | Filtering helps; ordering enables unsafe first-row choice and lacks identity/current-source proof                        |
| `app/Services/Patient/PatientResponseDecorator.php`                      | Reimplement principle only | Allowlists and header controls help; legacy codes, raw field errors, English copy, and coverage need review              |
| `app/Services/Patient/PatientResponseMetadata.php`                       | Held                       | Request/freshness/policy/version metadata helps; aliases and default claims are unapproved                               |
| `app/Services/Patient/Projection/PatientProjectionContentGuard.php`      | Reimplement principle only | Allowlist-first schema and forbidden-source checks are strong; exact patient content contract remains held               |
| `app/Services/Patient/Projection/PatientProjectionDisclosureService.php` | Reimplement principle only | Reauthorization, anti-oracle collapse, release filter, and audit-before-payload help; legacy models do not               |
| `app/Services/Patient/Projection/PatientProjectionStateVocabulary.php`   | Held                       | Code validation helps; registry, version, labels, locale, and free text are not approved                                 |

### 5.4 Backend tests

| Source                                                    | Disposition   | Decisive finding                                                                                 |
| --------------------------------------------------------- | ------------- | ------------------------------------------------------------------------------------------------ |
| `tests/Feature/Patient/PatientApiBoundaryTest.php`        | Evidence only | Reuse default-off, realm, response, and forbidden-field negative-test techniques                 |
| `tests/Feature/Patient/PatientAuthLifecycleTest.php`      | Evidence only | Use atomic proof, lockout, generic denial, rotation/reuse, cleanup, and audit cases as a catalog |
| `tests/Feature/Patient/PatientIdentityFoundationTest.php` | Evidence only | Hash/encryption/handle/append-only tests help; a recovery enum is not a recovery workflow        |
| `tests/Feature/Patient/PatientProjectionApiTest.php`      | Evidence only | Anti-oracle and no-store cases help; legacy projection fixtures are not accepted content         |
| `tests/Feature/Patient/PatientProjectionKernelTest.php`   | Evidence only | Database/content-guard negative techniques help after Nightingale schema approval                |
| `tests/Feature/Patient/PatientSessionManagementTest.php`  | Evidence only | Cross-principal equivalence, repeat revocation, and bounded-session cases help                   |
| `tests/Feature/Patient/PatientTimestampContractTest.php`  | Evidence only | UTC/RFC 3339 and daylight-saving cases are reusable after field ownership                        |
| `tests/Unit/Patient/PatientResponseMetadataTest.php`      | Evidence only | Reveals default/alias behavior; rewrite for an exact Nightingale envelope                        |

### 5.5 iOS sources

| Source                                                                                          | Disposition | Decisive finding                                                                                                    |
| ----------------------------------------------------------------------------------------------- | ----------- | ------------------------------------------------------------------------------------------------------------------- |
| `hummingbird/iosPatientApp/HummingbirdPatient/Features/Authentication/PatientWelcomeView.swift` | Reject      | Seven-field invitation and email/password UX are unapproved; secret state lacks explicit background clearing        |
| `hummingbird/iosPatientApp/HummingbirdPatient/App/PatientAppViewModel.swift`                    | Reject      | First-row, scope fan-out, absence/vocabulary collapse, tuple failure, and server-message passthrough are unsafe     |
| `hummingbird/iosPatientApp/HummingbirdPatient/Networking/PatientAPIClient.swift`                | Reject      | Legacy host/path/operations/bearers/redirects/errors cannot cross the product boundary                              |
| `hummingbird/iosPatientApp/HummingbirdPatient/Networking/PatientAPIModels.swift`                | Reject      | Legacy tokens, identity, grants, projections, errors, enrollment, device, and recovery enums are not a contract     |
| `hummingbird/iosPatientApp/HummingbirdPatient/Networking/PatientSecureStore.swift`              | Reject      | Legacy Keychain service and token schema do not migrate; only a token-agnostic primitive was independently reissued |
| `hummingbird/iosPatientApp/HummingbirdPatient/Models/PatientExperienceSnapshot.swift`           | Reject      | First-record selection, invented UUIDs, inferred empty states, raw scopes, and fallback source copy are prohibited  |
| `hummingbird/iosPatientApp/HummingbirdPatient/Models/PatientStateVocabulary.swift`              | Held        | Registry, version, missing/unknown behavior, localization, and approvals are incomplete                             |

### 5.6 iOS tests

| Source                                                                             | Disposition   | Decisive finding                                                                                   |
| ---------------------------------------------------------------------------------- | ------------- | -------------------------------------------------------------------------------------------------- |
| `hummingbird/iosPatientApp/HummingbirdPatientTests/PatientAPIBoundaryTests.swift`  | Evidence only | Reuse allowlist, HTTPS, host, identifier, redirect, storage, and boundary negative-test techniques |
| `hummingbird/iosPatientApp/HummingbirdPatientTests/PatientAPIClientTests.swift`    | Evidence only | Transport/header/token/error cases help; every asserted legacy operation is non-authoritative      |
| `hummingbird/iosPatientApp/HummingbirdPatientTests/PatientAPIModelTests.swift`     | Evidence only | Malformed envelope, timestamp, field, vocabulary, and decoder cases are a useful catalog           |
| `hummingbird/iosPatientApp/HummingbirdPatientTests/PatientAppViewModelTests.swift` | Evidence only | Test doubles document current first-read/error behavior and gaps, not desired Nightingale outcomes |

### 5.7 Android sources

| Source                                                                                                               | Disposition | Decisive finding                                                                                                         |
| -------------------------------------------------------------------------------------------------------------------- | ----------- | ------------------------------------------------------------------------------------------------------------------------ |
| `hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/PatientAppViewModel.kt`            | Reject      | Legacy identity orchestration, copy, local assurance, scope-derived availability, and error policy do not migrate        |
| `hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/PatientExperienceModels.kt`        | Reject      | Legacy auth mode, enrollment fields, raw scopes, destinations, and projection models are not Nightingale models          |
| `hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/data/PatientApiClient.kt`          | Reject      | Legacy 23-path allowlist, host, bearer/device/enrollment contract, and decoded server messages are prohibited            |
| `hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/data/PatientApiModels.kt`          | Reject      | Legacy token/profile/grant/projection/error/device/session/enrollment/recovery models are not reusable                   |
| `hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/data/PatientSecureStore.kt`        | Reject      | EncryptedSharedPreferences, legacy token/session schema, durable device UUID, alias, and migration are prohibited        |
| `hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/data/PatientSessionCoordinator.kt` | Reject      | FirstOrNull, scope fan-out, 403/404 collapse, vocabulary nulling, refresh persistence, and fallback inference are unsafe |
| `hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/data/PatientStateVocabulary.kt`    | Held        | One checksum-bound cross-platform registry, localization, exact incompatibility behavior, and review are missing         |
| `hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/ui/PatientAuthenticationScreen.kt` | Reject      | Seven-field invitation and email/password UX are unapproved; post-submit clearing does not prove lifecycle clearing      |

### 5.8 Android tests

| Source                                                                                                                   | Disposition   | Decisive finding                                                                           |
| ------------------------------------------------------------------------------------------------------------------------ | ------------- | ------------------------------------------------------------------------------------------ |
| `hummingbird/androidPatientApp/app/src/test/java/net/acumenus/hummingbird/patient/PatientAppViewModelTest.kt`            | Evidence only | Reuse state, secret-clearing, generic-error, and disabled-network test techniques          |
| `hummingbird/androidPatientApp/app/src/test/java/net/acumenus/hummingbird/patient/data/PatientEndpointBoundaryTest.kt`   | Evidence only | Reuse exact operation, HTTPS, host, identifier, and disabled-network negative tests        |
| `hummingbird/androidPatientApp/app/src/test/java/net/acumenus/hummingbird/patient/data/PatientEnvelopeDecoderTest.kt`    | Evidence only | Reuse malformed envelope, UUID, length, time, vocabulary, revision, and bounded-list cases |
| `hummingbird/androidPatientApp/app/src/test/java/net/acumenus/hummingbird/patient/data/PatientSessionCoordinatorTest.kt` | Evidence only | Refresh, cleanup, first-read, absence, and vocabulary cases form a gap catalog             |

## 6. Detailed decision: identity input

### 6.1 Inputs rejected as Nightingale defaults

The following legacy values are not approved Nightingale inputs:

- email address as the primary subject locator;
- a locally verified password;
- invitation/challenge UUID;
- invitation bearer token;
- short verification code;
- patient-entered display name;
- password and password confirmation during enrollment;
- client-created durable device UUID;
- device name, app version, and OS version as identity/session fields;
- Hummingbird access token;
- Hummingbird refresh token;
- Hummingbird session UUID;
- staff or legacy patient identity;
- raw grant UUID or raw encounter UUID; and
- Hummingbird relationship or scope codes.

Rejecting a default does not mean the field can never exist. It means a named identity owner,
privacy/security owner, legal/HIM owner, accessibility/language owner, support owner, and
product owner must define why it exists, who is authoritative, how it is verified, how it is
corrected, what is retained, what is displayed, how it is localized, and how it fails without
enumeration.

### 6.2 Minimum decision required before identity implementation

The identity decision must define:

1. authoritative issuer, audience, client, realm, and provider;
2. initial and step-up assurance levels by operation;
3. patient and representative subject types;
4. relationship proof, expiry, revocation, merge, and dispute behavior;
5. account discovery and enumeration resistance;
6. credential binding and device-replacement behavior;
7. session creation, rotation, theft/reuse response, idle expiry, absolute expiry, risk hold,
   and logout;
8. support-assisted escalation that cannot bypass proofing;
9. recovery proof, old-binding invalidation, notification, cooling-off, and fraud response;
10. field-level profile ownership and minimization;
11. audit fields and prohibited audit content;
12. localization, accessibility, and patient-safe copy;
13. production key ownership and rotation;
14. non-production isolation and synthetic test identities; and
15. rollback that disables access without orphaning or silently widening relationships.

Until those decisions are approved, `config/nightingale.php` must keep the provider `null`
and identity disabled.

## 7. Detailed decision: enrollment and recovery

### 7.1 What exists

The reference implementation contains a reasonably defensive encounter-enrollment
transaction:

- the challenge row is locked;
- challenge token and verification code are checked against one-way hashes;
- usability, expiry, failed attempts, lockout, consumption, and revocation are evaluated;
- grant, identity link, and principal rows are locked;
- principal/link/grant ownership consistency is checked;
- principal activation, grant activation, challenge consumption, session issue, and audit
  occur within the governed flow; and
- invalid challenge cases collapse to one legacy error.

These are evidence for future invariant design.

### 7.2 What does not exist

No complete recovery feature exists. Specifically, there is no:

- Nightingale or Hummingbird patient recovery route;
- recovery request/response schema;
- recovery-start endpoint;
- recovery-completion endpoint;
- recovery proofing provider;
- recovery delivery service;
- recovery UI on iOS or Android;
- lost-device flow;
- change-email or change-phone proof;
- old-credential and old-session invalidation transaction;
- recovery replay or race policy;
- notification-to-prior-channel behavior;
- cooldown or fraud-review behavior;
- support escalation boundary;
- representative recovery policy;
- recovery audit acceptance matrix; or
- emulator end-to-end recovery test.

The `account_recovery` database check value and `.recovery`/`"recovery"` decoder values only
reserve vocabulary. They must not be represented as implemented functionality or migration
readiness.

### 7.3 Enrollment/recovery result

The whole feature remains **held**. Future work may reimplement row locking, one-way secret
storage, attempt bounds, atomic consumption, session invalidation, and anti-enumeration after
the identity decision. It must not copy the legacy route, local account, challenge material,
purpose codes, delivery methods, model rows, fixtures, or patient copy.

## 8. Detailed decision: first-read projection

### 8.1 Required Nightingale sequence

The initial Nightingale read remains:

```text
approved operation in active independent contract
  -> default-off product/operation/facility/cohort gates
    -> verified Nightingale principal and owned session at required assurance
      -> verified, current, unmerged identity link owned by principal
        -> self relationship only
          -> approved inpatient purpose
            -> active, non-revoked, effective authorization
              -> authoritative current-inpatient source result
                -> zero eligible contexts: authoritative empty
                -> one eligible context: disclose one Nightingale handle
                -> more than one: account state requires review
                  -> recheck before serialization
                    -> durable evaluation audit
                      -> durable disclosure audit when a handle is returned
                        -> exact no-store response
```

No projection is requested until this context decision succeeds.

### 8.2 Projection disposition must be explicit

Each future projection read must return or internally preserve one of these independently
defined states:

| Disposition               | Meaning                                                                                 | Patient presentation requirement                                                           |
| ------------------------- | --------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| `released_current`        | Approved content exists and passed all authorization, source, policy, and release gates | Render exact approved projection with freshness, provenance, and uncertainty               |
| `released_stale`          | Released content is still eligible but source freshness exceeded its approved threshold | Render only if policy allows, with approved stale guidance                                 |
| `not_released`            | No currently released patient projection exists                                         | Generic patient-safe unavailable copy; never imply no care activity                        |
| `not_authorized`          | Principal/relationship/grant does not permit disclosure                                 | Anti-oracle response; no existence confirmation                                            |
| `temporarily_unavailable` | Required source, audit, policy, or service is unavailable                               | Do not convert to “no stay” or empty clinical content                                      |
| `contract_incompatible`   | Client cannot safely understand the response/vocabulary                                 | Withhold content, show safe update/support guidance, emit PHI-free compatibility telemetry |
| `account_review`          | Identity/source/cardinality state is inconsistent                                       | No handles or content; approved support path                                               |
| `malformed`               | Response violates exact schema, identifiers, bounds, timestamps, or registry            | Reject the complete affected projection; invent no fields or identifiers                   |

The public anti-oracle status may intentionally collapse multiple internal dispositions, but
the client still needs one approved, non-inferential presentation contract. A nullable model
alone is insufficient.

### 8.3 Failure containment

The future first-read coordinator must define whether projection reads are independently
contained or whether selected failures invalidate the whole screen. At minimum:

- identity/context failure invalidates all downstream data;
- contract or identifier failure invalidates the affected projection and may require a
  protective full-state transition;
- authorization is never cached from raw client scopes;
- stale data is never labeled current;
- one projection service failure does not silently erase unrelated released information;
- partial success cannot mix different principals, contexts, policy versions, or
  authorization evaluations;
- refresh/retry is bounded and idempotent;
- backgrounding clears volatile identity and patient-authored input;
- all retained data is bound to principal, session, context, contract, and release version;
  and
- sign-out, recovery, identity change, grant change, source inconsistency, or realm mismatch
  clears protected state before another read.

## 9. Detailed decision: error and non-disclosure

### 9.1 Required error architecture

Nightingale errors must be separated into four layers:

1. **Internal diagnostic cause:** server logs and protected audit only; no raw patient,
   source, credential, route, query, exception, or stack material in the public response.
2. **Stable contract code:** product-owned, operation-appropriate, versioned, and
   anti-enumerating.
3. **Client disposition:** a closed enum that drives state transitions and retry/clear
   behavior; unknown values fail closed.
4. **Patient copy:** locally owned, reviewed, localized, accessible, and never taken from an
   arbitrary server message.

The client must not display `message` from a decoded server failure. The server may still
send approved copy for non-native consumers, but native apps map the stable code to their own
versioned local catalog and record a PHI-free compatibility event when the code is unknown.

### 9.2 Required equivalence sets

At the public boundary, the following groups require indistinguishable status, body shape,
header behavior, material timing controls, and absence of identifying audit content:

- unknown identity and known identity with wrong credential;
- unknown challenge, wrong secret, wrong code, expired, consumed, revoked, locked, wrong
  principal, wrong identity link, and wrong grant;
- unknown context, cross-principal, wrong relationship, revoked/expired/suspended grant,
  missing scope, unreleased, retracted, and policy-ineligible projection where existence must
  not be disclosed;
- unknown session and cross-principal session;
- unknown/closed/revoked resources in any future mutation; and
- unsupported legacy path and disabled Nightingale operation.

Internal audit can retain a bounded reason code where policy permits, but public behavior
must not become an oracle through code, copy, fields, headers, response length, ordering,
cache behavior, retry timing, or redirect.

### 9.3 Required client clearing behavior

| Trigger                                | Volatile identity input                             | Credentials/binding                                   | Patient projection cache               | Patient-authored draft |
| -------------------------------------- | --------------------------------------------------- | ----------------------------------------------------- | -------------------------------------- | ---------------------- |
| App background/inactive                | Clear                                               | Retain only approved device-bound descriptor          | Cover; retention per approved policy   | Clear                  |
| Authentication failure                 | Clear secrets; retain only safe locator if approved | No new binding                                        | Clear mismatched state                 | Clear                  |
| Enrollment completion/failure          | Clear all proof material                            | Persist only approved binding after full success      | Load only after identity/context proof | Clear                  |
| Recovery requested/completed/failed    | Clear                                               | Invalidate according to approved recovery transaction | Clear before new binding               | Clear                  |
| Principal/relationship/context changes | Clear                                               | Re-evaluate/clear                                     | Clear synchronously                    | Clear                  |
| Session revoked/expired/reuse detected | Clear                                               | Clear                                                 | Clear synchronously                    | Clear                  |
| Sign-out                               | Clear                                               | Clear                                                 | Clear                                  | Clear                  |
| Contract/vocabulary incompatibility    | Clear active form secrets                           | Retain only if identity remains valid                 | Withhold incompatible content          | Clear if schema-bound  |

## 10. Principles eligible for future reimplementation

After the relevant approvals, these bounded principles can be reimplemented under
Nightingale ownership:

- product and operation default-off gates;
- independent identity realm and exact session ownership;
- comparable work for unknown and known credential failures;
- one-way storage of short-lived proof material;
- bounded attempts, expiry, consumption, revocation, and replay protection;
- row locking and atomic enrollment/recovery/session transitions;
- session rotation, reuse detection, revocation, and local-store failure cleanup;
- encrypted source linkage plus purpose-separated keyed lookup digests;
- self-only initial relationship;
- authoritative source states that distinguish unavailable, inconsistent, closed, and
  current;
- zero/one initial context cardinality;
- request-time authorization and serialization-boundary recheck;
- random product-owned opaque handles;
- allowlist-first patient projection schemas;
- forbidden raw source and staff-only fields;
- immutable release, provenance, uncertainty, correction, and audit concepts;
- audit-before-disclosure;
- generic anti-oracle public responses;
- centralized safe-error mapping;
- exact no-store/no-index headers;
- strict identifier, enum, timestamp, size, and collection validation;
- no redirects, embedded credentials, disk HTTP cache, or unapproved production host;
- device-only non-synchronizing protected storage for an approved token-agnostic binding;
- secret zeroization where platform/runtime permits; and
- negative-test mutation patterns across backend, iOS, and Android.

Every item still needs a Nightingale-owned implementation, contract, tests, and approvals.

## 11. Behaviors explicitly rejected

The following must not appear in Nightingale:

- alias, proxy, redirect, or generated client for `/api/patient/v1`;
- reuse of the Hummingbird patient realm, models, provider, credentials, tokens, sessions,
  storage keys, device UUID, grants, encounter UUIDs, routes, scopes, flags, policy versions,
  or source keys;
- automatic migration of a legacy patient credential or protected-store record;
- email/password as an assumed identity default;
- a seven-field invitation form copied from either native client;
- treating an `account_recovery` enum as a recovery feature;
- client authorization from raw scopes;
- selecting the first encounter or projection because of server/database ordering;
- inventing UUIDs when the server sends malformed identifiers;
- mapping missing scope, forbidden, not found, unreleased, stale, incompatible, unavailable,
  and genuine empty to one unqualified `nil`;
- presenting raw server or framework error messages;
- allowing environment variables to approve identity providers, patient sources, state
  vocabulary, content, operations, or relationships;
- using grant-row update time as proof of current inpatient state;
- treating a maximum row version as a collection revision;
- exposing raw grant fields to make client navigation decisions;
- querying production to discover or validate a design before source/identity authority;
- replaying candidate fixtures in any environment; or
- creating a sample production patient under this milestone.

## 12. Mechanical enforcement

The new verifier:

- parses the classification as strict JSON;
- requires the exact classification identifier, date, scope, and unapproved status;
- requires all fourteen global permission flags to remain `false`;
- requires exactly 65 unique safe repository-relative paths;
- pins the sorted source inventory by SHA-256;
- verifies every classified file exists and its content SHA-256 matches;
- requires exact fields for every source row;
- requires known surfaces, categories, and dispositions;
- requires a substantive file-specific decision;
- pins category counts so a domain cannot be silently emptied;
- requires every surface and disposition to remain represented;
- confirms the Nightingale configuration still has no route, network client, provider,
  production query, disclosure, mutation, or production activation;
- confirms the contract foundation remains dormant; and
- rejects credential-like literals in the classification manifest.

Its mutation self-test proves rejection of:

- implementation activation;
- runtime adoption;
- first-record selection;
- server-message passthrough;
- projection-absence conflation;
- production replay;
- malformed source hashes;
- removed sources;
- duplicate/replaced source paths; and
- an `approved` source disposition.

The verifier runs in the Nightingale CI job beside the empty-contract, encounter-candidate,
identity/source-candidate, backend-foundation, and product-boundary checks.

## 13. Remaining gates

Completion of this classification does not close the following:

- [ ] Named identity-provider and assurance decision
- [ ] Enrollment proofing and delivery decision
- [ ] Full account-recovery decision and support operating model
- [ ] Representative relationship, consent, revocation, and sensitive-service decision
- [ ] Nightingale-owned profile field and preference ownership
- [ ] Nightingale session, credential, and device-binding design
- [ ] Authoritative inpatient source adapter approval
- [ ] Independent API operation approval
- [ ] Exact first-read response and error contract
- [ ] Closed client disposition model and localized copy catalog
- [ ] Projection content, vocabulary, provenance, uncertainty, freshness, and release approval
- [ ] Backend/iOS/Android canonical fixture parity
- [ ] Threat model, privacy/security, clinical safety, legal/HIM, accessibility/language,
      patient-advisor, support, operations, and release approval
- [ ] Non-production integration with synthetic identities and source data
- [ ] Production-readiness evidence, exact-SHA CI, and protected-main release

The next implementation slice should begin only after named independent identity,
proofing/recovery, source, contract, privacy/security, and patient-language decisions exist.
Until then, the correct runtime state remains the existing no-route, no-provider,
no-network-client, no-production-query Nightingale foundation.
