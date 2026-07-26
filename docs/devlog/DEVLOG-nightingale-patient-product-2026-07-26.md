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

## 2026-07-26 — Cross-surface brand ownership and source-predecessor upgrade evidence

### Repository and runtime audit

- Added the
  [cross-surface brand and upgrade audit](../evidence/nightingale/brand-identity-2026-07-26/CROSS-SURFACE-AUDIT.md).
  It inventories Hummingbird and Nightingale application IDs, display names, notification,
  APNs, WidgetKit, Live Activity, app-group, Android widget, shortcut, generated-asset, and
  store-package surfaces.
- Confirmed Hummingbird owns the repository's only current widget surfaces:
  `net.acumenus.hummingbird.HummingbirdWidgets`,
  `group.net.acumenus.hummingbird`, the iOS WidgetKit/Live Activity bundle, and the
  non-exported Android `HouseGlanceReceiver`. These sources contain no Nightingale name,
  namespace, or extension-owned raster/vector artwork.
- Confirmed Hummingbird iOS uses system notification presentation under the staff
  application identity and has no custom notification service/content extension or
  attachment. Android registers four Hummingbird urgency channels but has no FCM service,
  posting implementation, runtime notification permission, or small-icon selection; no
  Android notification visual is claimed.
- Confirmed Nightingale remains notification-, push-, widget-, extension-, Live Activity-,
  app-group-, shortcut-, receiver-, and network-free. This absence is the approved
  foundation behavior.
- Confirmed no repository-owned App Store/Play Store listing directory or store screenshot
  asset exists under the four native product roots. Existing launcher/splash captures remain
  engineering evidence, not distribution material.

### Mechanical controls

- Added `verify-mobile-brand-surfaces.sh` to pin exact Hummingbird, Nightingale, and legacy
  patient-reference identities; Hummingbird widget/push namespace ownership; Nightingale
  negative surfaces; store-package absence; and cross-product name isolation.
- Extended `verify-mobile-brand-assets.sh` to reject identical in-app brand marks and every
  corresponding Android launcher, round, adaptive-foreground, and monochrome asset at every
  density.
- Wired both verifiers into the independent macOS mobile-brand CI job. Both scripts pass
  Bash syntax and live verification.

### Installed-upgrade emulator evidence

- Built predecessor Hummingbird artifacts from detached `origin/main` source
  `84b5f8305a694128423ae489fa4527e4b542927f` and current artifacts from
  `06441141edd38ffd79803a5aed8b8906f6a32a20`.
- On iPhone 17 Pro Simulator, installed predecessor Hummingbird, wrote a synthetic
  application-private canary, installed current Hummingbird over it, and read the exact
  canary afterward. Both artifacts retained app bundle ID `net.acumenus.hummingbird` and
  widget bundle ID `net.acumenus.hummingbird.HummingbirdWidgets`. The simulator reassigned
  the raw container path while preserving the logical app data, so physical path equality
  is correctly not treated as an application contract.
- On the Android API 35 `hb` emulator, repeated the clean predecessor/current replacement
  with `adb install -r`. The exact private canary survived; package ID remained
  `net.acumenus.hummingbird`; and both debug APKs had signer SHA-256
  `663f448870c7cf490608d8816a6d0d93cc5f441c0ff8190f53f02155f19df281`.
- The predecessor/current artifacts use equal development version/build values. The audit
  therefore classifies this as source-predecessor engineering compatibility, not App
  Store/Play Store acceptance, released-user migration, or distribution-signing proof.
- Updated Stream B and the product identity checklist immediately: repository-level
  ownership and engineering upgrade checks are complete, while retained released-artifact,
  external store, signing, version/build increment, installed-widget, protected-state,
  rollback, and actual notification-rendering evidence remain unchecked.

### Final regression

- Re-ran both mobile-brand verifiers, all three Nightingale contract/candidate verifiers
  with negative self-tests, the dependency-free backend verifier with negative self-tests,
  and the native product-boundary verifier. Targeted Prettier, relative-link, Git
  whitespace, and changed-slice production-connection-token checks pass.
- Regenerated the Nightingale Xcode project and passed the normally signed iPhone 17 Pro
  Simulator suite: five unit tests and two UI journeys passed with zero failures. The
  Nightingale Release simulator build passed.
- On the live Android API 35 `hb` emulator, five instrumentation tests passed with zero
  failures. A forced fresh run passed five JVM tests; the Nightingale product boundary,
  lint-vital, Debug assembly, and Release assembly passed.
- The current Hummingbird iOS Release simulator build, including embedded WidgetKit
  validation, passed. The Hummingbird Android Release assembly and lint-vital task passed.
- Draft PR CI exposed that the clean Ubuntu and macOS runners do not preinstall `rg`. The
  first Nightingale-boundary and mobile-brand jobs therefore failed before evaluating
  product assertions. Replaced `rg` only inside the three affected standalone verification
  scripts with portable recursive/fixed/extended `grep` equivalents, then re-ran Bash
  syntax, both brand verifiers, the native boundary, all contract/backend verifiers and
  negative self-tests, and Git whitespace checks locally. This is a CI portability fix, not
  a product-boundary relaxation.

### Safety and release holds

- No production patient, database, credential, principal, encounter, route, feature flag,
  migration, deployment, store record, signing key, APNs delivery, FCM delivery, or pilot
  state was read or changed.
- Artwork rights, product-design/accessibility review, Apple/Google ownership, public
  support, privacy disclosure, distribution signing, released-artifact migration, and
  release authorization remain open.

## 2026-07-26 — Identity input, recovery, first-read, and error source classification

### Exact evidence inventory

- Added the
  [detailed source classification](../nightingale/IDENTITY-INPUT-FIRST-READ-ERROR-SOURCE-CLASSIFICATION-2026-07-26.md)
  and a machine-readable source ledger covering 65 exact files at source commit
  `b1078405de2dacd767ec69da11197f1e755d8277`.
- Classified 1 legacy contract, 33 backend/database sources, 8 backend tests, 11 iOS
  sources/tests, and 12 Android sources/tests. Every row records its repository-relative
  path, SHA-256, surface, one or more of four review domains, disposition, and
  source-specific decision.
- The overlapping domains contain 44 identity-input sources, 37 enrollment/recovery
  sources, 47 first-read projection sources, and 65 error/non-disclosure sources.
- Used source and synthetic repository evidence only. No production database, patient,
  principal, identity link, challenge, grant, session, projection, credential, source
  response, feature flag, migration, or deployment state was read or changed.

### Material findings

- Confirmed there is no complete patient account-recovery implementation. The identity
  migration permits an `account_recovery` challenge purpose and native models recognize a
  recovery auth-method label, but there is no recovery route, contract, proofing service,
  UI, completion transaction, old-session invalidation policy, support path, or end-to-end
  recovery test.
- Confirmed iOS silently selects `encounters.data.encounters.first` and Android silently
  selects `firstOrNull()`. Backend ordering by `valid_from DESC` and grant UUID is not a
  patient selection or clinically governed reconciliation rule.
- Confirmed both clients use raw grant scopes for request fan-out. iOS collapses projection
  `404` to absence; Android collapses `403` and `404`; both collapse incompatible
  patient-state vocabulary to absence. These states cannot safely stand for a genuine empty
  care projection.
- Confirmed iOS can substitute newly generated random UUIDs for malformed schedule, pathway
  stage, and care-team member identifiers. Nightingale must reject the affected projection
  instead of inventing a server identity.
- Confirmed iOS can display `.server` and `.unauthorized` response messages directly, while
  Android can display the decoded server message for HTTP 422. Nightingale must map stable
  product-owned codes to reviewed localized client copy and never pass through arbitrary
  server messages.
- Preserved candidate server principles including product/operation default-off gates,
  exact realm/session ownership, one-way challenge secrets, row locking and atomic
  consumption, session rotation/reuse handling, generic anti-oracle projection responses,
  request-time authorization, allowlist-first patient projection content, durable
  audit-before-disclosure, and no-store/no-index response handling.
- Rejected legacy email/password and seven-field invitation defaults, Hummingbird routes,
  provider/models/tokens/storage/device identity, raw grant fields/scopes, first-record
  selection, random identifier substitution, server-message passthrough, and nullable
  projection-disposition conflation.

### Mechanical enforcement

- Added `scripts/ci/verify-nightingale-source-classification.mjs` and wired it into the
  docs-sensitive Nightingale CI job.
- The verifier pins the exact 65-path inventory and every source SHA-256; validates strict
  per-row fields, category counts, surfaces, dispositions, and substantive decisions; and
  confirms the Nightingale configuration and zero-operation contract remain dormant.
- The ledger keeps fourteen permissions false, including implementation, runtime adoption,
  route registration, legacy alias/provider/credential/device reuse, first-record
  selection, server-message passthrough, projection-absence conflation, production data,
  production queries, production replay, and patient/principal creation.
- Negative self-tests prove rejection of implementation/runtime activation, first-record
  selection, server-message passthrough, absence conflation, production replay, a malformed
  hash, source removal, duplicate/replaced inventory, and an `approved` disposition.
- Updated the product plan immediately after the bounded slice passed. The 65-file
  classification item is complete; the broader all-source migration item remains open for
  journey, communication, notification, preference, presentation, synthetic/debug, and
  release sources.

### Native and static regression evidence

- Ran the new source-classification verifier with all negative mutations, the empty-contract
  verifier, encounter-access candidate verifier, identity/source candidate verifier,
  dependency-free backend verifier, native product-boundary verifier, and both cross-product
  mobile-brand verifiers. All passed.
- Regenerated and compared the Nightingale Xcode project with `project.yml`; no generated
  drift remained. On the normally signed iPhone 17 Pro Simulator, all five unit tests and
  both UI tests passed with zero failures. The Release simulator build passed.
- Started the `hb` Android API 35 emulator and forced fresh execution without the Gradle
  build cache. All five instrumentation tests and five JVM boundary tests passed with zero
  failures. `verifyNightingaleProductBoundary`, `lintVitalRelease`, `assembleDebug`, and
  `assembleRelease` passed in the same successful build.
- Shut down both emulators after evidence collection.
- One preliminary Xcode-project helper invocation supplied the repository root instead of
  its required iOS-app root and correctly exited before generation. One preliminary Gradle
  invocation stopped before task execution because macOS had no system-registered Java
  runtime. Neither is counted as product evidence; the exact checks passed after supplying
  `Nightingale/iosApp` and Android Studio's bundled JDK 21 respectively.

### Holds

- No identity provider, proofing method, enrollment or recovery workflow, representative
  authority, session/credential model, authoritative source adapter, route, operation,
  projection contract, error catalog, patient copy, or production access is approved.
- The Nightingale OpenAPI artifact still has zero paths, Laravel still registers no
  Nightingale route, both apps still have no network client, and no patient-facing data
  capability was added.
- Identity, source, privacy/security, legal/HIM, clinical safety, accessibility/language,
  patient-advisor, support, operations, pilot, release, and deployment approvals remain
  open.

## 2026-07-26 — Communication and notification source classification

### Exact evidence inventory

- Added the
  [communication and notification source classification](../nightingale/COMMUNICATION-AND-NOTIFICATION-SOURCE-CLASSIFICATION-2026-07-26.md)
  and a machine-readable checksum ledger covering 130 unique files at source commit
  `be8405a0f768bf239862b790b3eeae80b8aad2ad`.
- Classified 3 contract/reference sources, 65 backend sources, 5 database migrations, 9
  backend tests, 12 patient iOS sources/tests, 13 patient Android sources/tests, 8 staff iOS,
  7 staff Android, and 8 staff web sources/tests.
- The six overlapping review domains contain 77 patient-contract, 103
  patient-mutation/delivery, 89 staff-handoff/routing, 69
  notification-registration/delivery, 48 native-patient-experience, and 130
  error/offline/urgency sources.
- Recorded 55 evidence-only, 27 principle-only, 42 held, and 6 rejected dispositions through
  17 reusable decision records. Every source retains an individual SHA-256 digest.

### Material findings

- Confirmed the server message core has strong candidate safety properties: current
  encounter/grant and thread revalidation, operation and client-message advisory locks,
  payload digests, immutable encrypted bodies, append-only receipts and routing, content-free
  staff outbox facts, optimistic concurrency, explicit responsibility pools, effective
  eligible responders, fresh consumer heartbeats, and attributable staff actions.
- Confirmed patient notification delivery is absent. The backend registers, encrypts,
  rebinds, and revokes provider tokens but has no APNs/FCM patient provider worker, approved
  payload, delivery lifecycle, or provider receipt reconciliation. The iOS and Android
  patient clients have no corresponding native registration/permission/service lifecycle.
- Confirmed patient clients have manual refresh only. Unlike the staff web/iOS/Android
  surfaces, they have no foreground polling, and patient push is absent.
- Confirmed a human retry after an ambiguous transport outcome generates fresh idempotency
  and client-message identities on both patient clients. The server can safely replay the
  same identifiers, but a later user retry appears as a new mutation and may duplicate a
  request already committed.
- Confirmed `server_accepted`/`sent` is written before the asynchronous `staff_inbox`
  projection. Existing iOS and Android success wording therefore overstates what is proven
  by saying the message was sent to the care team or responsible pool.
- Confirmed a decode-breaking state mismatch: escalation appends patient-visible delivery
  state `escalated`, serialization returns that raw state, while the patient contract and
  iOS delivery enum omit it. Android accepts raw strings but does not precisely map every
  valid ownership/delivery state and checks non-contract ownership value
  `team_acknowledged`.
- Confirmed topic and urgent-help content are static English configuration/code values
  rather than locale-, checksum-, review-, effective-period-, and rollback-bound Nightingale
  release content. iOS and Android do not have proven urgent/offline copy parity.
- Disproved a suspected staff-close decode problem: staff-specific operational reasons stay
  on the staff work item while the patient thread is normalized to `question_answered`, an
  accepted patient contract/iOS value. The negative finding is pinned to prevent future
  overstatement.

### Mechanical enforcement

- Added
  `scripts/ci/build-nightingale-communication-notification-classification.mjs` so the
  explicit curated inventory and hashes can be reproduced after an intentional
  reclassification.
- Added
  `scripts/ci/verify-nightingale-communication-notification-classification.mjs` to pin the
  exact 130 paths, inventory digest, individual hashes, six domain counts, 11 surface
  counts, four disposition counts, 17 decisions, and ten material findings.
- Wired the verifier and its negative self-tests into the docs-sensitive Nightingale CI job.
- The ledger keeps 20 permissions false, including implementation/runtime/route/legacy
  alias/copy adoption, provider/device/channel/payload activation, patient polling, offline
  mutation queuing, retry identity regeneration, acceptance-as-delivery, production
  data/query/replay, and patient/principal creation.
- Negative self-tests prove rejection of notification provider activation, patient polling,
  offline queueing, retry identity regeneration, acceptance-as-delivery, production replay,
  false resolution of the iOS escalation mismatch, false assertion of the staff-close
  hypothesis, malformed hashes, source removal, duplicate inventory paths, and an
  `approved` disposition.
- Updated the master plan, migration classification, contract/authorization checklist,
  Nightingale documentation index, and this execution log immediately after the bounded
  slice passed. The broader all-source classification remains open for journey, preference,
  presentation, synthetic/debug, and release sources.

### Native and static regression evidence

- The new verifier with all negative mutations, the 65-file source verifier, empty-contract
  verifier, encounter-access candidate verifier, identity/source candidate verifier,
  dependency-free backend verifier, native product-boundary verifier, targeted Prettier,
  and Git whitespace checks passed.
- Regenerated and compared the Nightingale Xcode project with `project.yml`; no generated
  drift remained. On iPhone 17 Pro Simulator
  `0A7FAE8C-8902-462D-BB4D-1E216D5BFDC1`, all five unit tests and two UI tests passed with
  zero failures. The signed Release simulator build passed.
- Started the `hb` API 35 / Android 15 AVD and forced fresh execution with the Android Studio
  JDK 21 and no Gradle build cache. Five instrumentation tests and five JVM boundary tests
  passed with zero failures. `verifyNightingaleProductBoundary`, `lintVitalRelease`,
  `assembleDebug`, and `assembleRelease` passed in the same 116-task build.
- Shut down both emulators after evidence collection.

### Safety and release holds

- This section classifies predecessor evidence; it does not implement Nightingale
  communication or notification functionality.
- The Nightingale contract still has zero paths, Laravel still registers no Nightingale
  route, both apps remain network-disabled, and no provider, payload, patient poller,
  offline queue, staff handoff, patient message, or delivery channel was added.
- No production database, patient, principal, grant, session, provider console, runtime
  credential, feature flag, migration, deployment, or release state was read or changed.
- Communication product/clinical operations, state vocabulary, patient language,
  localization, accessibility, privacy/security, identity, source, provider, support,
  incident/downtime, pilot, release, and deployment approvals remain open.

## 2026-07-26 — Complete 255-source migration classification

### Exact inventory closure

- Defined the complete tracked Hummingbird Patient product universe in executable builder
  and verifier logic: both patient-native roots; legacy patient configuration and route;
  patient controllers, requests, models, policies, contracts, and services; patient tests;
  patient-named migrations excluding the unrelated `patient_flow` subsystem; and
  Hummingbird console commands whose filename contains `Patient`.
- The universe contains exactly 255 paths with inventory digest
  `d6f680b73278786f8004826029e6a9413f921db4ce03df8873bde4c23c62d99c`.
  The two prior ledgers cover 122 unique paths in that universe.
- Added the
  [journey, preference, presentation, synthetic, and release classification](../nightingale/JOURNEY-PREFERENCE-PRESENTATION-RELEASE-SOURCE-CLASSIFICATION-2026-07-26.md)
  and a machine-readable ledger for the exact remaining 133 paths at reviewed source commit
  `e8f2b33bca79c4134f2476f41702430da72816d7`. Its path-list digest is
  `dd74e3d050839815f731b02af1b2d3d4886e1837913f17e8bc87244c4ad172d2`.
- Classified the final sources as 41 reusable safety primitives, 20 held reusable product
  behaviors, 28 test/fixture-only sources, and 44 rejected legacy behaviors. Every source
  has one exact class, disposition, surface, decision, domain set, and byte SHA-256.
- The three ledgers now cover 255 of 255 product sources. This closes source classification,
  not migration, functional parity, implementation, approval, or release.

### Patient-journey findings

- Confirmed both legacy native apps expose Messages as a fourth top-level destination,
  conflicting with the Nightingale charter’s Today/My Path/Care Team primary structure and
  contextual communication entry points.
- Confirmed iOS combines multiple projection timestamps, stale flags, provenance, and
  uncertainty into one aggregate snapshot context. This cannot prove field-level context
  for the particular screen/card a patient is reading.
- Confirmed Android renders pathway, pathway events, discharge readiness, and rounds
  summary in one My Path view but selects only the first available projection context for
  the header. The remaining displayed subprojections can have different provenance,
  freshness, and uncertainty.
- Carried forward the already proven unsafe first-record encounter selection on both
  platforms. Nightingale’s zero/one candidate and fail-closed multiple-context behavior
  remain required.
- Preserved released-only empty states, patient-safe uncertainty, revision notices,
  urgent-help separation, care-team role framing, and nonclinical authored-input
  separation as held product/safety requirements.
- Confirmed the independent clinical-review/catalog-release-manager service is a strong
  pathway-specific principle but does not establish equivalent two-person release for
  Today, care team, pathway events, discharge readiness, rounds, education, communication,
  or notification content.

### Preference and accessibility findings

- Reconciled seven server preference fields against five fields exposed by each native
  editor. Locale and timezone are not editable natively; server-only `sms` and `none`
  delivery values are omitted; native editors expose only push/email and default a missing
  value to push despite the previously proven absence of patient push delivery.
- Confirmed both platforms preserve a stronger system text-size setting by taking the
  maximum of the system and account-selected scale.
- Confirmed iOS uses the combined system/account reduced-motion choice for privacy-cover
  and scenic-background transitions.
- Confirmed Android decodes, persists, propagates, and announces reduced motion but has no
  main-source branch that changes rendering motion. The setting is semantically inert and
  is not approved for Nightingale until behavior and test coverage exist.
- Required Nightingale to separate device accessibility, account presentation, locale,
  communication consent, delivery channel, and notification-preview ownership instead of
  copying one mixed preferences object.

### Synthetic, provisioning, and release findings

- Confirmed iOS synthetic activation/content are compiler-gated to Debug and Android uses
  debug source-set fixtures plus inert Release stubs. The compile-exclusion property is a
  reusable principle; all Hummingbird hooks, IDs, extras, payloads, and copy are test-only.
- Confirmed `SyntheticPatientProjectionProvisioner` refuses non-testing environments and
  remains test-only.
- Rejected the command-accessible reference provisioners as Nightingale or production
  tooling. Despite default-off, dry-run, synthetic-name, ownership, locking, pending-state,
  redaction, and draft-only safeguards, their `--commit` paths can create or bind an
  operational encounter, principal, identity link, grant, enrollment challenges/secrets,
  policy, cursors, and projections in a deployed database.
- Rejected all legacy Hummingbird Patient project/package/bundle/version/activation and
  icon/scenic/theme resources. Nightingale retains its independent app roots, IDs, supplied
  nightingale artwork, and product boundary.
- Recorded the absence of repository proof for distribution signing, external store
  records, retained released-artifact upgrades, monotonic version history, notification
  identities, store privacy/support metadata, or pilot release.

### Mechanical enforcement and checklist update

- Added
  `scripts/ci/build-nightingale-journey-preference-release-classification.mjs` to reproduce
  the exact 133-source ledger only from the reviewed product universe.
- Added
  `scripts/ci/verify-nightingale-journey-preference-release-classification.mjs` to pin the
  255/122/133 closure, both inventory digests, every file hash, 18 source surfaces, four
  required classes/dispositions, 19 decisions, eight domain counts, and 23 material
  findings.
- Ten negative mutations prove rejection of runtime adoption, production queries, source
  omission, checksum drift, classification weakening, false Android reduced-motion
  coverage, blanket migration approval, universe-count drift, inadequate decision
  rationale, and synthetic release activation.
- Wired the new verifier into the docs-sensitive Nightingale CI job.
- Updated the master plan immediately after the verifier passed: the all-source
  classification item is now complete, while every port, journey, accessibility,
  human-review, pilot, release, and deployment item remains open.
- Updated the migration record, contract/authorization matrix, Nightingale documentation
  index, and this execution log to reflect complete source classification without
  overstating implementation.

### Native and static regression evidence

- Re-ran the empty-contract, encounter-access, identity/source, 65-source,
  communication/notification, final 133-source, backend default-deny, native product
  boundary, mobile brand-asset, and mobile brand-surface verifiers. Every positive and
  negative assertion passed.
- Ran target-file Prettier, JavaScript syntax, generated-ledger equality, relative-link,
  sensitive-literal, and Git whitespace checks. All passed.
- Regenerated the Nightingale Xcode project from `project.yml` and confirmed no generated
  project drift. On iPhone 17 Pro Simulator
  `0A7FAE8C-8902-462D-BB4D-1E216D5BFDC1`, all five unit tests and two UI tests passed with
  zero failures. The normally signed Release simulator build passed. The simulator was
  shut down after the run.
- Cold-booted the `hb` Android 15/API 35 AVD with wiped data and no snapshot. Using Android
  Studio JDK 21 and `--no-build-cache`, all five instrumentation tests and five JVM tests
  passed; `verifyNightingaleProductBoundary`, `lintVitalRelease`, `assembleDebug`, and
  `assembleRelease` passed. Gradle reported 117 actionable tasks: 116 executed and one
  up-to-date. The emulator was shut down after the run.
- A preliminary Android attempt reached `connectedDebugAndroidTest` after the JVM and
  boundary tasks but correctly failed with `No connected devices!` because the headless
  emulator process inherited and exited with its boot shell. A second detached boot was
  stopped before Gradle when the same process-lifetime issue was confirmed. Neither attempt
  is counted as device evidence. The final run held the emulator in a persistent foreground
  session and reran the complete clean task set successfully.

### Safety and release holds

- This work is repository-source classification only. It adds no Nightingale route,
  operation, identity provider, source adapter, client, network permission, projection,
  preference persistence, message, notification, synthetic runtime, migration, or release.
- No production database, patient, principal, identity link, grant, session, encounter,
  preference, message, goal, pathway draft/review/release, feature flag, migration,
  deployment, or pilot state was read or changed.
- Named product, patient-advisor, clinical/content, nursing, medical-staff, pharmacy,
  privacy/security, accessibility, language/interpreter, legal/HIM, identity, source,
  support, operations, release, and deployment approvals remain open.

## 2026-07-26 — Held Today projection candidate

### Contract boundary

- Added the
  [Nightingale Today projection candidate decision](../nightingale/TODAY-PROJECTION-CANDIDATE-DECISION-2026-07-26.md)
  and synthetic candidate artifacts under
  `docs/nightingale/api-contract/candidates/today/v0/`.
- Reserved only the held intent
  `GET /inpatient-contexts/{encounter_handle}/today` with operation ID
  `getNightingaleTodayProjection`. The executable foundation still has zero paths, no
  Laravel route is registered, and no backend or native runtime uses the candidate.
- The request accepts only the separately held Nightingale opaque inpatient-context handle.
  It accepts no body, query, source identifier, legacy identifier, patient identifier,
  principal identifier, grant identifier, or client-supplied authorization scope.
- Every candidate activation field remains false: product, operation, identity, inpatient
  source, projection source, clinical content release, localization, disclosure, native
  clients, non-production integration, and production.

### Field and section decisions

- Replaced the legacy composite document-level context with a complete governance context
  beside every patient-visible value: release, freshness, uncertainty, language,
  correction, and offline behavior.
- Defined exactly eight sections: headline, summary, schedule, next steps, care location,
  discharge outlook, questions, and notices. Headline and summary are mandatory released
  sections.
- Defined explicit `released`, `released-empty`, and `not-available` states for optional
  sections. Released-empty carries a mandatory notice that zero released items does not
  mean no care is planned. Not-available contains no patient content.
- Added Nightingale-only opaque content-revision and schedule-item handle formats. Legacy
  encounter, grant, projection, principal, staff, patient, FHIR, EHR, and source identifiers
  remain prohibited.
- Restricted freshness to current or explicitly approved stale. Approved stale requires a
  field-level patient notice; unknown mandatory freshness fails closed.
- Required a field locale, approved source-language or translation release, and approved
  plain-language review. Silent locale fallback and unapproved machine translation remain
  prohibited.
- Required corrected values to carry a patient notice. A root correction notice cannot
  reveal the withdrawn value, actor, reason, target/replacement identifier, or staff review
  record.
- Kept every candidate field online-only with durable client storage prohibited. This does
  not approve background refresh, push, notifications, offline storage, or cache
  invalidation.

### Synthetic coverage and mechanical enforcement

- Added 68 synthetic cases across 18 bounded response templates:
    - 12 governed success variants;
    - 20 product, identity, authorization, non-disclosure, lifecycle, and handle-integrity
      outcomes;
    - 24 source, release, field-governance, language, freshness, content-safety, and
      vocabulary outcomes; and
    - 12 structure, audit, serialization, and throttling outcomes.
- Added one exact synthetic request template with only the held method, namespace, path,
  valid opaque handle, empty query, null body, and authentication-context requirement.
- Added 14 direct source-byte checksums in addition to the already-pinned 255-source product
  universe digest. The direct evidence spans the legacy patient contract, controller,
  disclosure service, content guard, policy, model, migration, feature test, and both
  native Today decode/presentation paths.
- Added an independent verifier that checks the zero-path foundation, every disabled
  permission, exact root/evidence/request/response/field schemas, no-store headers, empty
  response links, error-code mapping, all fixture outcomes and audit modes, field context
  completeness, section-state contradictions, handle formats/uniqueness, deterministic
  timestamp order, source hashes, and prohibited environment/credential/legacy identifier
  literals.
- Twenty-four adversarial self-tests prove rejection of path drift, OpenAPI inclusion,
  production activation, aggregate freshness, missing field context, stale content without
  a notice, locale drift, released-empty content, production replay, foundation path
  activation, checksum drift, fixture removal, durable client caching, and semantic drift
  in every governed success variant.
- Wired the verifier into the Nightingale contract-foundation CI job.
- Updated the master checklist, route ADR, migration record, authorization matrix, and
  Nightingale documentation index immediately after the candidate verifier passed.

### Verification and safety status

- Before this slice, exact commit
  `08837f8295e929cb860053a92b55772501eab61f` completed CI run
  `30216291263` with all 18 jobs successful, including patient iOS/Android, staff
  iOS/Android, backend quality and shards, frontend, browser, DAST, Arena, security, brand,
  and Nightingale contract gates.
- The Today builder reproduces the candidate artifacts; JavaScript syntax, all seven
  Nightingale governance/classification verifiers and their negative tests, the
  dependency-free backend foundation, native product boundary, mobile brand asset/surface,
  target-file Prettier, relative-link, sensitive-literal, and Git whitespace checks pass.
- Regenerated the Nightingale Xcode project and confirmed no drift. On iPhone 16e Simulator
  `3F568F29-BE58-49AD-8151-6C2303B4C4E3` running iOS 26.3.1, all five unit tests and two UI
  tests passed with zero failures. The normally signed Release simulator build passed, and
  the simulator was shut down.
- Cold-booted the wiped `hb` Android 15/API 35 AVD with no snapshot and ran Gradle from
  `clean` with Android Studio JDK 21 and `--no-build-cache`. Five JVM tests and five
  instrumentation tests passed; `verifyNightingaleProductBoundary`, `lintVitalRelease`,
  `assembleDebug`, and `assembleRelease` passed. Gradle reported 117 actionable tasks: 116
  executed and one up-to-date. The emulator was shut down.
- Commit/push and exact-SHA CI evidence for this Today slice remain required before the
  slice is fully ratified.
- No production database, patient, principal, identity link, grant, session, encounter,
  projection, content release, source, feature flag, migration, deployment, or pilot state
  was read or changed.
- Named identity/source, field-level clinical/content/language, privacy/security,
  accessibility, patient-advisor, legal/HIM, support, operations, release, and deployment
  approvals remain open. No route, adapter, query, generated client, or native journey may
  be implemented from this candidate until those gates are independently satisfied.

## 2026-07-26 — Device-local display-comfort foundation

### Decision and patient boundary

- Added the
  [presentation-preferences foundation decision](../nightingale/PRESENTATION-PREFERENCES-FOUNDATION-DECISION-2026-07-26.md).
- Implemented exactly two Nightingale-owned device choices on iOS and Android: reduce
  motion and hide decorative imagery.
- Kept both choices separate from care-account, locale, clinical, communication, consent,
  delivery, notification, identity, and encounter state. The patient-facing card explicitly
  says the settings never change care information.
- Chose stronger-setting precedence: either a system reduced-motion request or the local
  Nightingale request removes governed decorative motion; either the local imagery choice
  or a stronger system contrast/transparency condition withholds decorative art.
- Decided that Nightingale will not roam these settings through an account, API, or cloud
  key-value store. Android backup/device-transfer rules exclude shared preferences. iOS
  operating-system backup/restore behavior remains unratified and is not represented as an
  app synchronization feature.

### Native implementation

- Added a pure iOS presentation policy and `UserDefaults`-backed Nightingale namespace with
  full product-specific keys. The UI-test reset hook is compiled only in Debug.
- Added a pure Android presentation policy and private Nightingale `SharedPreferences`
  namespace. Writes commit synchronously, and the UI snapshot changes only after a
  successful commit.
- iOS now consumes system Reduce Motion, Reduce Transparency, Increased Contrast,
  accessibility text size, and color scheme. Android consumes the zero animator-duration
  condition, Android 14+ system contrast, and current font scale.
- Both apps hide the foreground and scenic bird artwork when requested, retain every
  essential heading/copy/control, and attenuate imagery at accessibility text size.
- iOS removes privacy-cover and local presentation transitions under reduced motion.
  Android replaces the scenic alpha tween with `snap()` and a zero-millisecond policy.
- Added equivalent Nightingale-only labels, identifiers/test tags, checked states, and
  effect explanations on both platforms.

### Mechanical enforcement

- Extended `verify-nightingale-product-boundary.sh` to require the two policy sources and
  exact preference namespaces, reject account/cloud-sync APIs, require the iOS reset hook
  inside a Debug conditional, and reject production test-clearing/inspection APIs.
- Unit truth tables cover standard, local reduced/hidden, stronger system, and
  accessibility-text behavior.
- Persistence tests prove the exact Nightingale key set and reject Hummingbird, patient,
  account, and token namespace leakage.
- Device UI tests enable both controls, verify patient-facing effect copy, relaunch or
  recreate the application, and verify the persisted checked states.

### Native evidence

- Regenerated the iOS Xcode project. On iPhone 16e Simulator
  `3F568F29-BE58-49AD-8151-6C2303B4C4E3`, iOS 26.3.1, all 10 tests passed with zero
  failures or skips. The normally signed Release simulator build succeeded.
- Visually inspected the clean installed iOS Release application. The supplied
  Nightingale artwork remains calming and subordinate to text; the privacy boundary is
  legible; the two controls and explanatory copy are reachable.
- The iOS Release executable scan excluded the Debug reset hook, Hummingbird name,
  application network URLs, and cloud-key-value API. It contained only the expected Apple
  property-list DTD URL among URL-shaped strings.
- The exact iOS Debug and Release simulator executable SHA-256 values are
  `5f203f74c9b98f7700e00d52c05e0b676b8c458f07c385825cc59695709b3ca9` and
  `663b1f9906e718dbd235512b1bc90cc9f9e47738a718bb71ac8cb686c6db0e81`.
- Cold-booted the `hb` Android 15/API 35 AVD without a snapshot. Six JVM and six
  instrumentation tests passed. `verifyNightingaleProductBoundary`, `lintVitalRelease`,
  `assembleDebug`, and `assembleRelease` passed; Gradle reported 117 actionable tasks,
  with 116 executed and one up-to-date.
- A manual UI Automator check found the Nightingale heading, privacy copy, Display comfort
  heading, two checkable controls, and their explanations. Both controls stayed checked
  with enabled-state copy after a cold relaunch.
- Android `FLAG_SECURE` intentionally made external emulator screenshots black. The black
  capture is not treated as visual-layout proof; Compose instrumentation and the live
  accessibility hierarchy establish rendered semantics and state. Independent human
  device review remains open.
- The Android unsigned Release APK declared no `INTERNET` permission. Its DEX excluded
  legacy Hummingbird, test preference APIs, Zephyrus endpoint, API-path, and WebSocket
  tokens. Generic AndroidX/Compose diagnostic/schema URLs were classified as library
  strings rather than application endpoints.
- The exact Android Debug and unsigned Release APK SHA-256 values are
  `ac851797682524dde8d739d9b6f4aa4eaaa0b77cc4e322b48e1ec21ec7149e1c` and
  `9c23e30f1c7211f969ecb2cf68698591f74de409a3d689cf116c68e64fded3e1`.

### Reproducibility and failed preflights

- Initial Android preflights failed before product verification because the shell did not
  select a Java runtime or Android SDK. Selecting the Android Studio JDK and local SDK then
  exposed an unavailable high-text-contrast API and a missing Compose delegated-state
  import.
- Replaced the unavailable API with the Android 14+ `UiModeManager.contrast` seam and added
  the required import. The complete clean task set then passed. None of the preliminary
  failures is counted as evidence.
- A broad Android Release URL scan found only platform/library diagnostics and schema
  strings. The final boundary assertion uses forbidden application endpoint tokens plus
  the independent no-`INTERNET` manifest check, and does not misrepresent bundled library
  text as application networking.

### Checklist and residual status

- Updated the master plan immediately: the bounded display-comfort subset is complete, and
  the broader primitive-port item remains open for patient API and vocabulary work.
- Closed the checklist decisions for device/account separation, intentional non-roaming,
  effective reduced motion, and exact local artifact binding.
- Left the compound largest-text, reflow, focus, contrast, target-size, screen-reader,
  landscape, language-expansion, and images-disabled item open because only the bounded
  imagery subset is proven.
- Full WCAG 2.2 AA conformance, physical-device visual review, iOS backup/restore behavior,
  distribution signing/store evidence, and named patient-advisor/accessibility/privacy/
  clinical/language/support/release approvals remain open.
- No production database, patient, principal, identity link, grant, session, encounter,
  account preference, clinical projection, message, route, source, migration, deployment,
  or pilot state was read or changed.
