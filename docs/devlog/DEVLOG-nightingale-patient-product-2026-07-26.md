# Nightingale Patient Product — Execution Log

**Initiative plan:**
[nightingale-patient-product-2026-07-26.md](../plans/nightingale-patient-product-2026-07-26.md)

## 2026-07-26 — Product direction and isolated development stream

### Decision record

Nightingale is the new dedicated patient product. Hummingbird is now the staff
operations product only. This is an architecture and product-identity decision, not a
clinical, patient-identity, content-release, pilot, database, migration, or production
deployment authorization.

### Completed evidence

- Created branch `codex/nightingale-patient-product` in a clean worktree at
  `/Users/sudoshi/Github/Zephyrus-nightingale-patient`, based on `origin/main` commit
  `446107ec`.
- Inspected the current independent staff targets (`net.acumenus.hummingbird`) and the
  legacy patient-reference targets (`net.acumenus.hummingbird.patient`) on both iOS and
  Android. The latter remain preserved as reference evidence and are not the Nightingale
  release target.
- Recorded supplied brand sources as RGBA 1254 × 1254 PNGs and pinned their SHA-256
  fingerprints in the initiative plan and product-specific provenance manifests.
- Added `scripts/brand/render-app-icon.swift`, a deterministic AppKit renderer. It created
  opaque iOS masters and Android density launcher derivatives, plus transparent Android
  adaptive-icon foregrounds with an 8% safe-zone inset. Hummingbird uses `#050B12` and
  Nightingale uses `#17120E` as reviewed opaque launcher backgrounds.
- Replaced the Hummingbird Staff iOS AppIcon/brand mark and Android
  launcher/round/foreground derivatives with the supplied hummingbird source. No legacy
  Hummingbird Patient asset or target was changed.
- Created the Nightingale iOS and Android roots, each with its own
  `net.acumenus.nightingale` application identity, unit/UI smoke tests, and an intentionally
  no-network, no-data foundation screen. The Android manifest has no `INTERNET` permission;
  its Gradle boundary task rejects staff product/endpoints. The iOS and Android boundary
  tests assert that live patient and staff endpoint access are disabled.
- Generated the Nightingale Xcode project from `project.yml`. The Nightingale iOS unit and
  UI tests pass on the booted iPhone 17 Pro simulator. Android unit/boundary tests and the
  API 35 (`hb`) emulator instrumentation smoke test pass. Android visual review captured
  the Nightingale Android 12+ splash and foundation screen, and the Hummingbird Staff splash.
- Verified Hummingbird Staff iOS project-generation drift and a Simulator Debug build. Its
  Android Debug APK builds, installs, and displays the supplied hummingbird mark on the
  Android 12+ splash surface.
- Confirmed the existing patient reference already has a distinct API, storage, and
  lifecycle boundary. That is useful migration input, not proof that the new Nightingale
  product is ready or authorized.

### Open work and holds

- Both Nightingale native targets now compile in Debug and Release, and both Hummingbird Staff
  targets compile in Debug and Release after the icon replacement. This does not satisfy
  signing, distribution, rights, or clinical/patient release requirements.
- Actual iOS launcher-surface inspection, Android round/adaptive launcher inspection, Android
  13+ monochrome-icon design, app-store/signing/rights release steps, and all clinical/patient
  authorization gates remain open. The current build and emulator evidence is only a
  foundation verification, not distribution approval.
- The existing patient contract remains governed compatibility input until a Nightingale
  contract/release migration is independently approved.
- No production patient, database record, session, grant, projection release, feature flag,
  migration, deployment, or pilot action was taken.

## 2026-07-26 — Branded privacy and accessibility foundation

### Completed evidence

- Classified the first seven legacy reference sources by safety primitive, product behavior,
  test/fixture-only, or rejected behavior. The classification explicitly holds every unlisted
  reference source and records why no patient API, credential, session, clinical projection,
  messaging behavior, or synthetic patient was migrated.
- Added a Nightingale-only scenic foundation using the supplied nightingale artwork, warm/cool
  low-contrast gradients, opaque content cards, decorative-image semantics, large-text image
  attenuation, iOS high-contrast image withholding, a tested Android high-contrast policy seam,
  and reduced-motion-aware iOS transitions. Android runtime high-contrast integration remains
  held for an approved Nightingale presentation preference rather than a private platform API.
- Added lifecycle privacy covers on iOS and Android with Nightingale-specific copy and
  accessibility identifiers. Foundation content is hidden from assistive interaction while
  the iOS cover is visible.
- Added mandatory Android `FLAG_SECURE`. On API 35 the app remained fully represented in the
  accessibility hierarchy while a system screenshot returned a black frame, confirming that
  the capture control protects visual content without erasing assistive semantics.
- Added `verify-nightingale-product-boundary.sh`, which rejects staff/legacy identifiers,
  staff endpoints, Android network permission, and native network-client symbols and verifies
  the independent iOS/Android application identifiers.

### Verification

- iOS: XcodeGen project generation and the full Nightingale unit/UI suite pass on iPhone 17 Pro
  Simulator. Both the normal foundation and forced privacy-cover states were visually reviewed.
- Android: unit/boundary tests pass; three API 35 instrumentation tests pass for launch copy,
  secure-window enforcement, and lifecycle privacy-cover state. Accessibility-tree inspection
  confirms the complete no-data patient-safe message.
- Boundary: no staff namespace, legacy patient package, staff endpoint, Android network
  permission, or native URL client is present in Nightingale application sources.

### Holds

- This slice contains no patient identity, credential storage, API client, clinical content,
  care-team communication, notification, analytics, production database access, feature
  activation, migration, deployment, or pilot enrollment.
- Remaining legacy sources are not migration-approved. Clinical, privacy/security,
  accessibility, patient-advisor, identity, legal/HIM, and release approvals remain open.

## 2026-07-26 — Product identity and launcher evidence hardening

### Completed evidence

- Added independent Android 13+ monochrome adaptive resources for Hummingbird Staff and
  Nightingale. The generated resources are white alpha silhouettes, not full-color
  foregrounds mislabeled as monochrome artwork.
- Ran the first round-mask review on the Android API 35 emulator, detected that the
  Hummingbird beak was clipped at the original 8% adaptive inset, corrected the
  Hummingbird adaptive/monochrome inset to 20%, rebuilt, reinstalled, and repeated the
  review. The corrected round and themed icons retain the complete subject.
- Captured non-PHI light/dark iOS launcher, Android round adaptive, Android light/dark
  themed-icon, and Android system-splash evidence for both products in
  [the brand evidence record](../evidence/nightingale/brand-identity-2026-07-26/README.md).
- Corrected the deterministic icon renderer so `opaque` outputs are RGB PNGs without alpha
  channels. Regenerated both iOS AppIcon masters and all legacy Android launcher/round
  density outputs.
- Added `verify-app-icon.swift` and `verify-mobile-brand-assets.sh`. They verify source
  checksums, dimensions, alpha-channel policy, visible/transparent pixel presence,
  monochrome pixel purity, Android v33 resource wiring, and cross-product distinction.
- Added the brand verifier as an independent macOS CI job so future non-documentation
  changes cannot silently reintroduce alpha, source drift, cross-product identity, or a
  malformed themed-icon resource.
- Added the
  [product identity and support naming checklist](../nightingale/PRODUCT-IDENTITY-AND-SUPPORT-NAMING-CHECKLIST-2026-07-26.md).
  It records canonical product names and app IDs while leaving all external reservations,
  signing, public support contacts, distribution rights, store metadata, and approvals
  explicitly pending.

### Verification

- Hummingbird Android Debug and Release builds accepted the corrected adaptive and
  monochrome resources.
- Nightingale Android Debug and Release builds and its product-boundary task accepted the
  independent resources.
- The brand-asset verifier confirms both iOS masters and legacy Android launchers have no
  alpha channel, while adaptive and monochrome foregrounds retain required transparency.
- Nightingale’s iOS XCTest/XCUITest scheme passed three tests with zero failures; its
  Android API 35 instrumentation suite passed three tests with zero failures. Hummingbird
  and Nightingale Android unit suites passed, and current Debug/Release builds succeeded.
- No patient data, credential, production database, patient record, feature activation,
  migration, deployment, or store-console mutation was used.

### Holds

- The remaining Stream B cross-surface audit covers notification, widget, installed
  upgrade, and future store-listing surfaces. It is not inferred from launcher evidence.
- Artwork ownership/distribution rights and independent product-design/accessibility
  review remain open.
- Apple/Google records, signing, support endpoints, privacy disclosures, analytics/crash
  boundaries, push identities, and pilot/release authorization remain open.

## 2026-07-26 — Pre-identity protected-state and volatile-input foundation

### Decisions and classification

- Added the
  [identity, recovery, and protected-state decision record](../nightingale/IDENTITY-RECOVERY-AND-PROTECTED-STATE-DECISIONS-2026-07-26.md)
  before adding storage code. It separates local deletion from remote revocation, prohibits
  durable access tokens and device UUIDs, withholds a refresh-token decision, reserves one
  future binding descriptor, defines recovery/account-transition clearing, and records
  device-compromise and memory-zeroization limitations.
- Classified the legacy iOS and Android secure stores as mixed reference sources. Their
  Hummingbird namespaces, access/refresh tokens, session/device UUIDs, and implicit
  migration behavior were rejected.
- Classified legacy iOS/Android message composition as product behavior. The useful
  no-durable-draft principle was retained, while legacy message UI, routing, clinical copy,
  and send operations remain held.

### Implemented foundation

- Added a dormant iOS generic-password Keychain store using the exact Nightingale service,
  the data-protection Keychain, `kSecAttrAccessibleWhenUnlockedThisDeviceOnly`, disabled
  synchronization, explicit status errors, empty-value rejection, and idempotent
  service-wide deletion. There is no access-token, refresh-token, device-identity, network,
  or production caller.
- Added a dormant Android protected-state store using a non-exportable 256-bit AES
  `AndroidKeyStore` key, AES-GCM with a fresh 12-byte IV, authenticated application/schema
  context, a versioned ciphertext envelope, app-private storage, and explicit verified
  deletion of both key and ciphertext. Unknown/corrupt/tampered state fails closed.
- Kept Android application backup disabled and added explicit cloud-backup/device-transfer
  exclusion rules. Both native product-boundary checks now pin that posture.
- Added process-memory-only volatile-input state on both platforms. The active roots clear
  it when the app becomes inactive; tests also pin logout, identity transition, recovery,
  revocation, and local-removal reasons. The implementation explicitly does not claim
  immutable-string memory zeroization.

### Verification

- iOS: the normally signed iPhone 17 Pro Simulator run passed five unit tests and two UI
  tests. The real Keychain test round-tripped a synthetic canary, inspected the
  `WhenUnlockedThisDeviceOnly` and non-synchronizing attributes, and verified idempotent
  deletion. A deliberately unsigned diagnostic run produced `errSecMissingEntitlement`
  (`-34018`), proving the test does not silently bypass a missing platform capability; the
  signed run passed without weakening the query.
- Android: JVM tests, Debug assembly, AndroidTest assembly, and Release assembly pass. The
  API 35 `hb` emulator passed five instrumentation tests, including real Keystore/GCM
  round-trip, ciphertext-not-plaintext inspection, tamper rejection, deletion of key and
  ciphertext, idempotent deletion, and lifecycle draft clearing.
- The Nightingale no-network/product-boundary scans remain green. No network permission,
  API client, patient input UI, or legacy storage namespace was introduced.

### Holds

- The protected stores are platform foundations, not an approved credential design. Real
  identity-provider selection, proofing, representative access, refresh/session policy,
  user-presence policy, recovery, support, penetration testing, and pilot approval remain
  open.
- No production database, patient, credential, identity record, grant, session, API,
  feature flag, migration, deployment, or pilot state was read or changed.

## 2026-07-26 — Empty contract governance and vocabulary reconciliation

### Contract inventory and decisions

- Reviewed the legacy Hummingbird Patient OpenAPI contract (23 paths and 25 operations),
  Laravel patient route group, default-off configuration, middleware, policies, disclosure
  services, projection guards, messaging services, principals, sessions, grants, releases,
  devices, and communication models.
- Added the
  [Nightingale contract ownership and authorization matrix](../nightingale/CONTRACT-OWNERSHIP-AND-AUTHORIZATION-MATRIX-2026-07-26.md).
  It assigns accountable disciplines, defines version/namespace holds, specifies the full
  authorization lattice, records response/mutation invariants, and gives every legacy
  operation an explicit held disposition.
- Added `nightingale-foundation.v0.json`, an OpenAPI 3.1.1 governance artifact with version
  `0.0.0-governance`, zero paths, zero webhooks, no components/security scheme, a reserved
  `.invalid` placeholder server, no client-generation permission, no route reservation,
  and every runtime/identity/disclosure/mutation/production activation field false.
- Added a dependency-free CI verifier and a dedicated docs-sensitive CI job. The verifier
  rejects any path, webhook, component, security definition, usable server, activation,
  legacy/staff route, production host, or Hummingbird identity entering the foundation.

### Vocabulary and field reconciliation

- Added the
  [patient-state vocabulary classification](../nightingale/PATIENT-STATE-VOCABULARY-CLASSIFICATION-2026-07-26.md)
  after reconciling the backend registry, backend content guard, OpenAPI schema, iOS
  registry/models/rendering, and Android registry/models/rendering.
- Counted 12 backend domains and 49 code-label pairs, versus 8 domains/37 pairs in the iOS
  versioned registry and 9 domains/41 pairs in Android. iOS implements four event-category
  labels outside its versioned registry, bringing implemented label coverage to 41 without
  equivalent governance.
- Found that `goal_author`, `location_status`, and `contact_route` are not centrally
  versioned in either client; goal/contact labels are duplicated in rendering switches and
  Android omits the Today `care_location` and `discharge_outlook` documents.
- Found a material schema-placement conflict: legacy OpenAPI allows category on schedule
  items while backend/native schedule models do not; backend/native pathway events support
  category while legacy OpenAPI does not define it there.
- Recorded materially different unknown-category behavior (closed-enum response failure on
  iOS versus neutral string fallback on Android), the unsafe Nightingale implication of
  treating a missing vocabulary version as compatible, and the backend class's lack of a
  direct version-to-registry binding.

### Verification and holds

- The contract foundation verifier, its negative mutation self-tests, the native
  no-network/product-boundary verifier, formatting checks, link checks, and Git whitespace
  validation pass.
- The Nightingale native applications remain unchanged by this slice. A fresh signed
  iPhone 17 Pro Simulator run passed five unit tests and two UI tests; the Android API 35
  `hb` emulator passed all five instrumentation tests, and Android JVM, Debug, and Release
  tasks passed. The iOS Release Simulator build also passed. These runs reconfirm the
  no-network/protected-state/lifecycle shell; no API or vocabulary runtime exists to
  exercise on a device.
- No route, controller, security scheme, native client, network permission, credential,
  patient input, vocabulary code/label, clinical projection, communication behavior,
  production database, patient record, feature flag, migration, deployment, or pilot state
  was created, read, or changed.
- The contract must remain empty until named owners approve the applicable pre-operation
  gates. All vocabulary codes and labels remain held until one version/checksum-bound
  Nightingale registry and canonical cross-platform fixtures are independently approved.

## 2026-07-26 — First read-only encounter-access held candidate

### Source scrutiny

- Traced the legacy `GET /encounters` request from product/feature gates through Sanctum,
  patient-realm/session/ability middleware, grant policies/query filters, per-row audit,
  response metadata, OpenAPI schema, backend feature coverage, and both native decoders and
  session coordinators.
- Recorded source hashes and the full analysis in the
  [encounter-access candidate decision](../nightingale/ENCOUNTER-ACCESS-CANDIDATE-DECISION-2026-07-26.md).
- Identified that the legacy response exposes grant UUID, raw scopes, relationship, grant
  validity dates, and row version even though the clients need only a navigation handle.
- Identified that both legacy apps silently choose the first encounter, while backend order
  is an authorization-record sort rather than a patient-safe transfer/readmission rule.
- Identified missing list-time proof of verified/current identity-link ownership and current
  inpatient source state; nullable effective-time drift across database/service/policy/
  contract/fixture; raw unvalidated scope arrays; row-oriented audit that omits an empty
  evaluation event; and a non-coherent maximum-row collection version/freshness claim.
- Confirmed the principal backend feature test proves one active-row happy path and selected
  source-field omission, but does not cover the full identity, status, time, source,
  cardinality, data-integrity, audit, race, or cross-platform matrix.

### Held candidate and fixtures

- Added a non-runnable candidate artifact with null route and operation ID, no OpenAPI
  inclusion, no namespace reservation, no client generation/network permission, and every
  activation field false.
- Reduced the candidate success entry to one separately issued Nightingale opaque
  `encounter_handle`; explicitly excluded 12 legacy/source/identity fields and any durable
  native storage.
- Restricted the initial candidate to `self` and zero or one eligible inpatient context.
  More than one returns a generic account-review result; a dependency outage returns
  temporary unavailability rather than a false empty result.
- Defined 42 synthetic cases across success, complete omission, release gating,
  authentication, account/session state, identity/source integrity, unknown registry data,
  opaque-handle integrity, dependency/audit failure, cardinality, race, policy mismatch,
  malformed scope registry, and throttling.
- Added eight exact response templates with bounded patient language, a one-field success
  payload, exact no-store/privacy headers, policy/evaluation metadata, no links, and no
  irrelevant state-vocabulary version.
- Added a dependency-free verifier that checks the held state, foundation zero-path state,
  exact field/header/template/case/audit mappings, handle/request formats, synthetic-only
  fixtures, production/legacy token absence, and all 42 case IDs. Five mutation self-tests
  prove it rejects operation activation, a second encounter, a legacy grant field, a
  weakened identity-link outcome, and production fixture replay.

### Verification and holds

- Both Nightingale contract verifiers (including negative self-tests), the native
  no-network/product-boundary verifier, JSON/Markdown/YAML formatting, relative-link
  checks, JavaScript syntax checks, and Git whitespace checks pass.
- An optional local `PatientApiBoundaryTest` corroboration was attempted against the
  `phpunit.xml`-pinned localhost `zephyrus_test` configuration, but this isolated worktree
  has no `vendor/autoload.php` and the host has no Composer executable. It failed before
  Laravel bootstrap or any database connection and is not counted as passing evidence.
- This slice changes no Laravel route/controller/service/model/migration/configuration and
  no iOS or Android application source. The executable Nightingale OpenAPI artifact still
  has zero paths, and the native applications still have no network client or Android
  internet permission.
- Candidate fixture success is not API success, clinical approval, identity approval, or
  cross-platform runtime parity. Named ownership, route/compatibility ADR, identity and
  source definitions, opaque-handle design, privacy/security/accessibility/patient/support
  review, implementation tests, non-production integration, and release approval all
  remain open.
- No production database, patient, principal, identity link, grant, session, source
  encounter, feature flag, route, migration, deployment, or pilot state was read or changed.

## 2026-07-26 — Route, compatibility, identity, and inpatient-source foundation

### Source reconciliation

- Traced Laravel route ownership through `RouteServiceProvider`, `routes/patient.php`, and
  the bootstrap middleware/exception pipeline. The legacy patient API is explicitly mounted
  under `/api/patient/v1`; staff/general mobile behavior remains under `/api/**`, including
  `/api/mobile/v1`.
- Reconciled `Encounter`, the `prod.encounters` creation migration,
  `PatientEncounterAccessService`, `PatientCommunicationEncounterGuard`, and
  `PatientCommunicationLifecycleReconciliationService`.
- Identified that current operational code uses related but non-identical active/current
  checks. The model scope checks status and deletion, messaging additionally requires a null
  discharge timestamp and grant linkage, and lifecycle reconciliation treats contradictory
  combinations as unresolved.
- Confirmed the encounter migration documents `active | discharged` but does not add a
  database status check constraint. The operational table is therefore a plausible future
  adapter input, not an authorization source or approved Nightingale vocabulary.
- Performed the audit from repository source only. No production database, patient,
  principal, link, grant, session, encounter, configuration, or response was read.

### Adopted architecture

- Added the
  [route, compatibility, identity, and inpatient-source ADR](../nightingale/ROUTE-COMPATIBILITY-IDENTITY-SOURCE-ADR-2026-07-26.md).
- Reserved `/api/nightingale/v1` and the held relative candidate path
  `/inpatient-contexts`, with operation ID `listNightingaleInpatientContexts`.
- Rejected aliases, proxies, redirects, second-prefix route mounting, and native fallback to
  the legacy patient or staff APIs. Internal reuse remains possible only behind
  Nightingale-owned adapters and contracts.
- Updated the foundation and candidate artifacts to pin those design identifiers while
  retaining zero OpenAPI paths, a `.invalid` server, no security scheme, no route
  registration, no client generation/networking, and every activation state false.
- Added `config/nightingale.php` as a code-owned, non-environment-activatable foundation.
  It cannot configure a provider/source adapter or turn on routes, identity, source queries,
  disclosure, mutation, or production.

### Default-deny backend primitives

- Added a request-scoped `NightingaleIdentityBoundary` with `unavailable`, `denied`, and
  `verified_self` states. The only implementation is unconfigured and always unavailable.
  No legacy principal, token, ability, grant, credential, or identifier schema was adopted.
- Added a request-scoped `NightingaleInpatientContextSource` with `unavailable`,
  `inconsistent`, `confirmed_closed`, and `confirmed_current` states. The only implementation
  performs no query and always returns unavailable.
- Added a precondition gate that withholds for 11 of 12 identity/source combinations. The
  single positive combination continues only to later governed evaluation; it never grants
  access and never returns patient data.
- Added no service-container binding, middleware, controller, route file, route group,
  model, migration, database call, network call, serializer, native client, or Android
  internet permission.

### Mechanical evidence

- Added a dependency-free PHP verifier that cross-checks config, foundation, candidate,
  route absence, unconfigured states, and all 12 precondition combinations.
- Added five negative mutations for route activation, identity activation, legacy-realm
  reuse, production source-query permission, and namespace drift.
- Extended the foundation verifier to pin the namespace, no-alias strategy, and additional
  route/source activation fields.
- Extended the candidate verifier to pin the relative path and operation ID while preserving
  the zero-path/held state; added a candidate-path mutation.
- Added a PHPUnit truth-table test for the two ports and precondition gate. Local dependency-
  free verification runs without Laravel or a database; the repository PHPUnit suite will
  run the test after Composer installation in CI.
- Added PHP 8.2 setup and the backend verifier to the independent Nightingale CI job.
- Locally passed PHP syntax checks, the dependency-free backend verifier and its five
  negative mutations, both Node contract verifiers and their negative mutations, the native
  product-boundary scan, targeted Prettier checks, relative-link checks, Git whitespace
  validation, and changed-file production credential/host scans.
- Regenerated the Nightingale Xcode project and passed five unit tests plus two UI journeys
  on the iPhone 17 Pro Simulator with normal local simulator signing. A preceding
  `CODE_SIGNING_ALLOWED=NO` diagnostic run correctly failed only the Keychain canary with
  `errSecMissingEntitlement (-34018)`; it is not counted as evidence. The signed rerun passed
  the canary, privacy cover, no-live-access, protected-state, and volatile-input tests.
- Built the Nightingale iOS Release simulator app successfully.
- On the Android API 35 `hb` emulator, passed all five instrumentation tests, the JVM unit
  suite, the Nightingale product boundary, Android lint-vital, and Debug/Release assembly.

### Checklist and holds

- Checked the plan’s route/compatibility foundation item and the contract matrix’s ADR item.
  Identity proofing/session/recovery, an authoritative source adapter, named owners,
  independent approvals, and every operation/release gate remain unchecked.
- The earlier devlog entry accurately records the candidate’s prior null-path/no-reservation
  state. This section supersedes that design state only; it does not turn the candidate into
  an operation.
- No production patient was created. Production database access remains explicitly outside
  this development stream, and no route, migration, deployment, or feature activation was
  performed.

## 2026-07-26 — Identity/session/recovery and current-inpatient held candidates

### Source scrutiny and decisions

- Reconciled the legacy patient auth service, patient-realm middleware, auth controller and
  requests, principal/identity-link/challenge/session models, identity schema migration,
  auth lifecycle tests, and session-management tests. Recorded exact SHA-256 evidence in the
  [candidate decision](../nightingale/IDENTITY-SESSION-RECOVERY-AND-SOURCE-CANDIDATE-DECISION-2026-07-26.md).
- Confirmed the legacy reference selects local email/password credentials, Hummingbird token
  abilities, persistent refresh families, session/device metadata, and a two-part enrollment
  challenge. Those are evidence inputs and were not adopted as Nightingale requirements.
- Confirmed the schema contains patient/representative principal and relationship vocabulary
  plus recovery/invitation challenge purposes, but the reviewed repository has no complete
  runnable recovery or representative invitation/acceptance/revocation lifecycle. Schema
  vocabulary was not treated as implementation or legal authority.
- Reconciled the current-inpatient evidence from `Encounter`, encounter-access, messaging
  guard, and lifecycle reconciliation sources. Multiple related but non-identical current
  definitions remain; no operational table or status value was promoted directly to
  Nightingale authorization.
- Performed the work from source and synthetic fixtures only. No production database,
  patient, principal, identity link, grant, session, encounter, response, or credential was
  read, replayed, inserted, changed, or used for validation.

### Held machine-readable candidates

- Added a non-runnable identity/session/recovery candidate with null provider, credential,
  refresh credential, enrollment channel, recovery channel, route, and operation. Every
  identity, enrollment, session, recovery, representative, integration, and production
  activation remains false.
- Restricted the initial positive identity state to self. Staff and legacy patient realms
  are rejected, representatives remain held, access-credential persistence and durable
  device identifiers remain prohibited, and `verified_self` explicitly does not authorize
  patient access.
- Added 64 synthetic identity cases spanning activation, provider/evidence/assurance,
  principal, session, identity-link, recovery, representative, audit, policy, and race
  outcomes. Every case has a pinned result and audit mode.
- Added a non-runnable current-inpatient source candidate with null adapter, query contract,
  database connection, route, operation, policy version, evaluation clock, and freshness
  threshold. Every source/cohort/integration/production activation remains false.
- Defined unavailable, inconsistent, confirmed-closed, and confirmed-current source states.
  Only confirmed-current may continue to later governed evaluation, and it still does not
  authorize access.
- Added 42 synthetic source cases spanning positive/closed, activation, dependencies,
  freshness, scope, linkage, lifecycle, completeness, concurrency, transitions,
  cardinality, policy, and audit. Missing/stale evidence cannot become current; no record or
  outage cannot become confirmed closed; contradictions cannot become a false empty result.

### Mechanical evidence

- Added `verify-nightingale-identity-source-candidates.mjs`. It cross-checks the empty
  foundation contract, disabled PHP configuration, PHP enum vocabularies, held candidate
  fields, all 106 exact case outcomes/audit modes, synthetic-only/no-production-replay
  declarations, null freshness choices, and credential/source-identifier containment.
- Added nine negative mutations that must reject identity-provider selection,
  representative activation, identity-as-authorization, a weakened cross-principal session
  outcome, production replay, source-query activation, stale-as-current classification, an
  OpenAPI runtime path, and a configured legacy source adapter.
- Added the verifier and its negative self-tests to the docs-sensitive Nightingale CI job.
- Updated the product plan, contract checklist, migration classification, protected-state
  decision, Nightingale index, and this execution log immediately after the verified
  candidate-design slice.
- Re-ran the empty-contract, encounter-candidate, identity/source-candidate, dependency-free
  backend, and native product-boundary verifiers, including all negative self-tests.
  JavaScript syntax, JSON parsing, targeted formatting, relative links, Git whitespace, and
  changed-slice production-connection-token scans pass.
- Re-ran the normally signed iPhone 17 Pro Simulator suite: five unit tests and two UI
  journeys passed with zero failures, including the real Keychain canary. The iOS Release
  simulator build passed.
- Re-ran Android on the `hb` API 35 emulator: five instrumentation tests passed with zero
  failures. A forced fresh JVM run passed five tests; the product boundary, lint-vital, and
  Debug/Release assemblies passed.
- Two preliminary Android runner invocations failed before instrumentation because the
  shell lacked an explicit SDK path and then reaped a detached emulator. Neither is counted
  as product evidence; the same tasks passed after setting the Java/SDK paths and keeping
  the API 35 emulator in a persistent session.

### Holds

- This completes candidate-state design and synthetic fixture coverage only. No provider,
  proofing method, credential, session representation, enrollment/recovery channel,
  representative authority, authoritative source, adapter/query, cohort, lifecycle mapping,
  freshness threshold, or patient-facing language is approved.
- Identity, source, authorization, privacy/security, clinical operations, legal/HIM,
  accessibility, patient-advisor, support, data-governance, integration, pilot, release, and
  deployment approvals remain open.
- The Nightingale OpenAPI document still has zero paths, Laravel still has zero Nightingale
  routes or bindings, both native apps still have zero network clients, and the correct
  runtime user experience remains the no-live-access foundation shell.
