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

## 2026-07-26 — Foundation accessibility and layout matrix

### Audit finding and bounded correction

- Added the
  [foundation accessibility and layout matrix](../nightingale/FOUNDATION-ACCESSIBILITY-LAYOUT-MATRIX-2026-07-26.md).
- Audited the installed iOS foundation at dark appearance, Increased Contrast, and
  `accessibility-extra-extra-extra-large`. The fixed forest accent measured 2.946:1 against
  black and visibly failed the bounded normal-text contrast gate.
- Replaced the fixed iOS accent with a dynamic light/dark value. Unit-calculated contrast is
  now 7.129:1 against the light system background and 11.324:1 against the dark system
  background.
- Added an explicit Android Material 3 dark scheme selected from
  `isSystemInDarkTheme()`. Eight Android text/container pairs across the light and dark
  schemes now have unit-enforced ratios ranging from 6.031:1 to 16.342:1.
- Kept color as non-essential emphasis. Every current patient state still has explicit text,
  and decorative imagery remains outside accessibility meaning.

### Layout, semantics, and target behavior

- Added stable identifiers/test tags for the product, privacy, and Display comfort headings
  plus both current controls.
- Removed an unnecessary iOS contained accessibility subtree after XCUITest proved it placed
  Display comfort before the visually earlier privacy card. The final accessibility
  sequence is product heading, privacy heading, Display comfort heading, reduce motion, and
  hide imagery.
- Added 44-point minimum iOS toggle rows and rectangular hit shapes.
- Replaced each Android label-plus-thumb interaction with one full-width, 48 dp minimum
  `Role.Switch` row. The visual switch has no separate change handler, so the whole labeled
  row is one state-changing target.
- Top-aligned and width-bounded the Android scroll column. This removes dependence on center
  arrangement when enlarged content exceeds the viewport.
- Added an iOS accessibility-XXXL landscape journey that proves semantic order,
  accessibility-aware auto-scroll/tap behavior, target height, and state changes. Its
  `DynamicTypeSize.accessibility5` adapter is compile-time Debug-only, and the test
  requires a 60-point product-heading height so a no-op adapter cannot pass.
- Added an Android font-scale-2.0 landscape journey that traverses the actual unmerged
  semantics tree, proves ordering, converts 48 dp to device pixels, checks full-row heights,
  changes both states, and restores font scale/orientation in `finally`.

### Native and artifact evidence

- On iPhone 16e Simulator `3F568F29-BE58-49AD-8151-6C2303B4C4E3`, iOS 26.3.1, the
  final normally signed suite passed eight unit and four UI tests with zero failures or
  skips from restored light appearance, normal contrast, and system-large text. The
  largest-text journey itself applied the Debug-only accessibility5 override, produced a
  seven-page landscape hierarchy, and passed. The normally signed Release simulator build
  passed.
- The earlier Release visual inspection remains appearance and reflow evidence; it is not
  used as landscape-contract evidence. For that inspection, the simulator was explicitly
  set to dark appearance,
  `DarkenSystemColors=1`, and accessibility XXXL. Text reflowed and scrolled, the corrected
  accent remained legible, card boundaries were visible, and the stronger contrast policy
  withheld decorative imagery. The local screenshot SHA-256 is
  `f3ca5fb5eb5281424d08858e56fdd381eb06ed5fa47d607dfc1bfc480b08f153`.
- On the `hb` Android 15/API 35 AVD, seven JVM and seven instrumentation tests passed.
  A clean, no-build-cache run also passed the Nightingale product boundary, `lintDebug`,
  `lintVitalRelease`, Debug assembly, and unsigned Release assembly. Gradle reported 125
  actionable tasks: 124 executed and one up-to-date.
- The final Android dark/font-scale-2.0 UI Automator hierarchy exposed the two complete
  checkable rows at 222 pixels each on the 420 dpi portrait surface, approximately 84.6 dp.
  Its SHA-256 is
  `6392d3ef5ebfbcd4ef85b568e94c067b90fb77725ef4197e85f7a82518d13fcf`.
- Android `FLAG_SECURE` still yielded a black application capture. The capture hash
  `5d5c731e9047f2c26407fab4bfa66b40ae59788da3438089db5f479732b53ebc`
  proves the capture boundary, not visual layout. Independent dark-theme physical/secure
  device review remains open.
- Exact local artifacts:
    - iOS Debug executable:
      `92c57ad6fbbe680bdc77d8252c6a144d0b4b90f4a225acadc86159891b34fd1e`;
    - iOS Release executable:
      `182ef77a6a020c4a26212482f09822901f94e3587433bbe490e5cb55be2c4827`;
    - iOS Release application manifest:
      `cc49573008857a7a658978b871553c922bf928577a80a7cece3750e804f6ef0c`;
    - Android Debug APK:
      `4d866ec381399caabd1287fd204e0dc01d3794267aecd5900a69d87d0dd91164`;
    - Android unsigned Release APK:
      `bd3d2994c84fa7de97d2770c257b70b8eb48f0fc59a081f486ecf6ebe03dd4e3`.

### Mechanical enforcement and retained failures

- Extended `verify-nightingale-product-boundary.sh` to require the appearance-aware iOS
  accent, 44-point iOS row target, Android dark scheme, system dark selection, 48 dp
  Android row target, both contrast tests, both largest-text landscape journeys, and the
  iOS accessibility-size adapter inside a Debug-only branch.
- Retained the failed fixed-color ratio and initial semantic-order assertion as findings.
  Also retained, but did not count as evidence, an unsigned iOS Keychain status `-34018`,
  an invalid receiver-scoped Compose import, clipped-coordinate order logic, and an
  unavailable Compose semantics accessor. The first iOS geometry proof also failed at
  `40.67` points because the UIKit launch argument did not affect the SwiftUI hierarchy on
  iOS 26.3.1; the accepted Debug-only adapter produced the intended seven-page hierarchy.
- The master checklist now closes only the bounded current-shell matrix. Full WCAG 2.2 AA,
  VoiceOver/TalkBack human traversal, focus recovery, language expansion/RTL,
  physical-device review, every future journey, and all named reviews remain open.
- Commit/push and exact-SHA CI evidence for this slice remain required before publication is
  fully ratified.
- No production database, patient, principal, identity link, grant, session, encounter,
  projection, preference, message, route, source, migration, deployment, or pilot state was
  read or changed.

## Foundation landscape release-contract correction

- A post-publication threat-surface inventory found that the iOS XCUITest rotated the
  simulator, but the tracked and built Release `Info.plist` declared portrait only.
  Therefore the earlier journey did not, by itself, prove ordinary distributed landscape
  support.
- Added `UIInterfaceOrientationLandscapeLeft` and
  `UIInterfaceOrientationLandscapeRight` to both `nightingale/iosApp/project.yml` and the
  tracked Nightingale `Info.plist`.
- Strengthened the accessibility5 journey to require `XCUIApplication.frame.width` to
  exceed its height before evaluating the seven-page landscape hierarchy, semantic order,
  accessibility-aware auto-scroll, 44-point targets, and state changes.
- The first truly landscape run then exposed an invalid-activation-point failure when the
  old helper queried `isHittable` for a still-offscreen switch. A center-in-viewport
  remediation still admitted a switch whose frame began at y = -4 points; a
  full-frame-in-viewport remediation then exposed XCTest's rotated-coordinate mismatch
  between the 844 by 390 application frame and portrait-space auto-scroll hit coordinates.
  Neither failed run is counted as evidence. The accepted journey waits for each element,
  checks its 44-point minimum frame, lets XCTest auto-scroll during the accessibility
  action, and requires the state-changing tap to succeed. Portrait restoration now lives
  in XCTest teardown so it also runs after a framework-level interaction failure.
- Extended the native boundary verifier to fail if either supported landscape orientation
  disappears from the project specification or application manifest.
- The final current-source iOS run passed all eight unit and four UI tests with no failure
  or skip. The normally signed Release build passed; its built manifest contains portrait,
  landscape-left, and landscape-right, and its executable excludes both Debug-only test
  keys. Current artifact hashes are recorded in the accessibility matrix and above.
- Published the correction as commit
  `80c29e9ff5a745e50e7efdca4c2ac9c3a3df8091`. Exact-SHA
  [CI run 30223467440](https://github.com/sudoshi/Zephyrus/actions/runs/30223467440)
  passed all 18 jobs with no failure or rerun. This supersedes the portrait-era
  landscape-evidence claim while retaining its failed discovery history.

## Foundation threat and clinical-hazard model

### Method and scope

- Added the draft
  [foundation threat and clinical-hazard model](../nightingale/FOUNDATION-THREAT-AND-HAZARD-MODEL-2026-07-26.md).
  It uses NIST CSF 2.0 as an outcome taxonomy, OWASP MASVS as a mobile-control checklist,
  HHS risk-analysis/mobile-privacy guidance for confidentiality/integrity/availability and
  personal-device context, NHS DCB0129/DCB0160 lifecycle concepts as a clinical-risk
  reference, STRIDE, and repository-specific misuse cases.
- The model explicitly makes no NIST, MASVS, HIPAA, DCB0129/DCB0160, medical-device,
  privacy-law, or other compliance claim. It records no named approval and accepts no
  residual risk.
- Bound the assessment to executable baseline
  `80c29e9ff5a745e50e7efdca4c2ac9c3a3df8091`: an offline native shell, zero OpenAPI paths,
  zero registered Nightingale routes, no network client/permission, no identity/provider,
  no source adapter/query, no patient disclosure/mutation, and no production activation.
  Future conceptual components are threat boundaries, not implementation approval.

### Registers and gates

- Versioned 18 protected assets, 14 trust boundaries, 25 implemented-control claims with
  explicit limits, 22 security/privacy threats, 22 clinical hazards, 18 abuse/misuse cases,
  20 activation gates, 18 verification tracks, eight incident classes, and 17 open risks.
- Used a dual view that avoids deceptively low “current risk” scores: every register entry
  records inherent risk if activated without controls, while current exposure is separately
  classified as blocked, present-foundation, future-design, or external.
- Marked wrong-principal/IDOR disclosure and urgent-message false assurance as critical
  activation risks. Wrong encounter, stale/corrected/retracted content, representative
  overreach, inaccessible or misunderstood content, lost/shared-device exposure,
  routing/delivery ambiguity, support social engineering, source contradiction, and
  absent rollback remain high activation risks.
- Recorded that “blocked by non-activation” does not mean safe or accepted: removing a
  route/network/provider/source/disclosure barrier without the corresponding gate is a
  release-blocking defect.
- Added explicit detection signals, containment classes, recovery proof, change triggers,
  named-owner requirements, platform asymmetries, and an ordered ten-step implementation
  sequence. The current foundation has no remote kill switch because it has no live
  capability; a tested kill switch remains mandatory before activation.

### Mechanical enforcement and current holds

- Added `scripts/ci/verify-nightingale-threat-hazard-model.mjs` and wired its self-test mode
  into the Nightingale CI job. It requires the complete identifier sets and draft/
  non-authorization statements, pins all five authoritative method links, rejects implied
  approval checkboxes and prohibited compliance claims, and cross-checks the actual
  backend/contract/native default-off boundaries.
- Eight negative self-tests prove rejection of a removed critical messaging hazard, removed
  draft status, missing NIST input, implied approval checkbox, backend production
  activation, inserted API operation, Android network permission, and iOS live-access
  activation.
- The broader Stream E item remains open: independent clinical-safety/privacy/security
  ratification, red-team, incident tabletop, penetration testing, signed-artifact mobile
  assessment, dependency response, human accessibility/language/patient review, and every
  named approval are still required.
- No production database, patient, principal, encounter, identity link, source, projection,
  message, notification, credential, integration, migration, deployment, pilot, or release
  state was read or changed.
- Published the model as commit `b772b0e3b45daf75fa60aa83721e0eb4ba163e3c`.
  Exact-SHA
  [CI run 30224139839](https://github.com/sudoshi/Zephyrus/actions/runs/30224139839)
  passed all 18 jobs with no failure or rerun.

## Foundation dependency inventory

### Gap and bounded decision

- Reconciled the Stream A exit evidence against the actual repository and found that the
  required generated software-bill-of-materials/dependency-inventory evidence did not
  exist. The identity checklist’s combined build/artifact/SBOM/signing/release-manifest row
  also remained open.
- Chose a precise **governed foundation dependency inventory** instead of labeling a custom
  file as a standards-conformant SBOM. The record explicitly excludes CycloneDX/SPDX
  conformance, license conclusions, vulnerability/exploitability findings, artifact
  checksums, registry/source provenance, build-plugin transitive graphs, signed artifacts,
  and distribution approval.
- Kept the combined identity-checklist row open. Only the repository-local inventory
  identity and current bounded inventory evidence are closed; build artifact names,
  standards-form SBOM naming, signing, and release manifests remain unresolved.

### Deterministic Android graph

- Added the Gradle reporting task
  `:app:writeNightingaleReleaseDependencyResolution`. It queries the structured
  `ResolutionResult` for `releaseRuntimeClasspath`, records direct declarations, unique
  external module components, and unique resolved dependency relationships, then
  deterministically tuple-sorts the output.
- Added the cross-platform generator
  `scripts/ci/generate-nightingale-foundation-dependency-inventory.mjs`. It invokes the
  Gradle task with JDK 17, parses the structured report, derives declared build
  requirements, inspects the iOS source boundary, computes exact source hashes, and writes
  the canonical record without a timestamp.
- The current graph contains seven direct Android Release runtime declarations, 83 unique
  resolved external components, and 457 unique dependency edges. The larger transitive
  count is not represented as 83 first-order product choices.
- An initial local dependency diagnostic failed closed under the shell’s Java 8 runtime
  because the build requires at least JVM 11. It is not evidence. Generation was rerun with
  Android Studio’s JDK 17 and succeeded.

### iOS boundary

- Inspected the XcodeGen application-target source and the bounded iOS root. There is no
  XcodeGen `packages:` block or target `package:` dependency and no `Package.swift`,
  `Package.resolved`, `Podfile`, `Podfile.lock`, `Cartfile`, or `Cartfile.resolved`.
- Recorded zero iOS third-party runtime packages and the four Apple modules imported by the
  application target: `Combine`, `Foundation`, `Security`, and `SwiftUI`.
- Explicitly limited the finding: it does not inventory Apple SDK/OS contents, Xcode or
  compiler components, or a signed archive’s embedded binaries.

### Source identity and enforcement

- Added the canonical generated record at
  `docs/nightingale/supply-chain/foundation-dependency-inventory.v0.json` using schema
  `net.acumenus.nightingale.foundation-dependency-inventory`, version 1.
- Bound it by SHA-256 to the Android app/root/settings Gradle sources, Gradle wrapper
  properties, iOS XcodeGen source, and generator. Any change to a dependency, repository,
  plugin, wrapper, SDK declaration, project source, or generator requires regeneration and
  review.
- Added
  `scripts/ci/verify-nightingale-foundation-dependency-inventory.mjs` to the docs-sensitive
  Nightingale CI job. It verifies product/release identity, scope limitations, source
  hashes, exact direct declarations, selected component versions, graph uniqueness/order/
  referential integrity, reconciled counts, build requirements, the zero-package iOS
  state, and the non-authorization statements.
- Nine negative mutations prove rejection of patient-data and production-access claims, a
  standards overclaim, stale source hash, removed direct dependency, duplicate component,
  unknown graph target, asserted iOS third-party package, and live-release approval claim.

### Documentation and residual risk

- Added the detailed
  [foundation dependency inventory decision and evidence](../nightingale/FOUNDATION-DEPENDENCY-INVENTORY-2026-07-26.md)
  and linked the generated record from the Nightingale documentation index.
- Updated the plan to record the bounded Stream A dependency-inventory exit evidence while
  leaving external identifier reservation, signing, stores, live features, and release
  gates open.
- Updated `THR-SC-001` to reference the generated source-hash-bound Release runtime
  inventory. `RISK-007` remains open because exact-SHA CI and graph drift detection do not
  provide an approved vulnerability/provenance response program.
- No patient, patient record, production database, production API, identity, grant,
  session, credential, route, network permission, source adapter, disclosure, mutation,
  message, notification, migration, deployment, pilot, or release state was read or
  changed.
- Published the bounded inventory as commit
  `60e599d90df368606f7e4ca9c5a44424570a3d6f`. Exact-SHA
  [CI run 30224908508](https://github.com/sudoshi/Zephyrus/actions/runs/30224908508)
  completed with all 18 jobs green and no rerun.

## Governed Nightingale background catalog and dedicated native CI

### Product direction and asset admission

- Adopted the seven images supplied under `nightingale/backgrounds` as the dedicated
  Nightingale background collection. They are decorative atmosphere only; no filename,
  species, clinical state, pathway state, acuity, urgency, care-team action, or outcome is
  exposed or inferred.
- Added `nightingale/backgrounds/backgrounds.v1.json` as the immutable machine-readable
  lineage record. It retains each source filename, source SHA-256, source dimensions, and
  source byte count plus the exact app-derivative path, SHA-256, dimensions, and byte count.
- Created exactly seven quality-82, optimized progressive JPEG derivatives. Sources with a
  long edge above 2400 pixels were downsampled without upscaling; smaller sources retained
  their dimensions. `jpegtran -copy none -optimize -progressive` removed EXIF, XMP,
  Photoshop/IPTC, comments, GPS-capable application markers, and other non-pixel
  application metadata.
- The committed derivative set totals 4,439,974 bytes. The approximately 33 MB source
  binaries are not duplicated in this stream; their exact fingerprints remain in the
  manifest. A durable source archive and applicable license/attribution record remain
  mandatory before any external, pilot, marketing, store, or production distribution.

### Cross-platform experience and accessibility contract

- Packaged the exact same seven derivatives in both native products. Android compiles the
  shared `drawable-nodpi` resource root; iOS packages the same files as loose bundle
  resources through its generated Xcode project. No independently recompressed platform
  variant exists.
- Aligned selection across platforms to
  `floorMod(local Gregorian epoch day since 1970-01-01, 7)`. The photo remains stable
  throughout a local day and maps to the same catalog entry on iOS and Android, including
  mathematical wraparound for negative epoch days. The iOS implementation carries only
  the local timezone into a Gregorian calendar, and a non-Gregorian device-calendar
  regression test prevents identifier-dependent drift.
- Prohibited carousel, parallax, timer-driven rotation, video, autonomous pan/zoom,
  clinical-event selection, and automatic motion loops. The existing short,
  patient-triggered presentation transition snaps when reduced motion is effective.
- Kept the photo outside the accessibility tree and input path. “Hide decorative imagery”
  removes the photo and decorative mark without removing text, headings, controls, focus
  order, or actions. iOS also withholds photography under Reduce Transparency or Increased
  Contrast; Android uses its supported high-contrast signal.
- Moved the product header and privacy-cover content onto strong cards. A governed
  system-surface gradient separates the photo from the content layer, all readable text
  remains on opaque or near-opaque surfaces, and stronger supported contrast/transparency
  settings force full opacity.

### Asset and Release enforcement

- Added `scripts/ci/verify-nightingale-background-assets.mjs`. It parses every JPEG marker
  and every progressive scan through the final end-of-image marker, verifies the exact
  file set, IDs, paths, source/derivative hashes, bytes, dimensions, orientation,
  no-upscale/2400-pixel bounds, progressive encoding, uniqueness, accessibility policy,
  selection rule, and explicit foundation-only rights state.
- The verifier includes negative mutations for a missing catalog entry, unsubstantiated
  rights approval, moving selection policy, duplicate derivative identity, binary tamper,
  unexpected derivative, and metadata injected after an entropy-coded image scan.
- Wired the asset verifier into the Nightingale native product-boundary script. Added
  platform Release artifact verifiers that check exact app identity/version, permission/
  network/deep-link/test-hook absence, embedded surfaces and dependencies, and the exact
  seven packaged JPEG hashes.
- Added dedicated Nightingale Android and iOS CI jobs for source/governance checks,
  dependency drift, Debug/Release unit tests and lint/builds, Release artifact inspection,
  Android API 35 instrumentation, iOS XCTest/XCUITest, and retained verification
  artifacts. This stream no longer relies on the documentation-sensitive foundation job
  as its only native gate.

### Local native verification

- Background manifest verification and all seven fail-closed mutations passed.
- The native product boundary and XcodeGen reproducibility checks passed.
- iOS normally signed Debug build-for-testing succeeded; 9/9 XCTest unit tests and 4/4
  XCUITest journeys passed on the iPhone 16e simulator running iOS 26.3.1. The unit suite
  covers exact resource loading and the shared day-index contract; the UI suite covers
  positive background status, imagery suppression persistence, privacy cover, and
  largest-text landscape behavior.
- An initial unsigned iOS unit invocation reproduced Keychain status `-34018` in the
  protected-state canary. The new background test passed in that run. The accepted normally
  signed configuration then passed all nine tests; the unsigned failure is retained as
  environment evidence, not counted as a product failure or passing result.
- iOS unsigned Release build and artifact verification passed. The exact local executable/
  manifest hashes are recorded in the accessibility/layout matrix.
- Android Debug and Release unit suites each passed 8/8 from forced task execution.
  `verifyNightingaleProductBoundary`, `lintDebug`, `lintRelease`, Debug assembly, unsigned
  Release assembly, and the exact Release APK boundary/hash scan all passed.
- Android API 35 instrumentation passed 7/7 with no failure or skip. `FLAG_SECURE` continued
  to produce a black ADB capture; capture protection was not weakened for visual review.
  The semantic hierarchy exposed no background filename or species token and retained all
  patient-readable text and controls.
- The inspected 1170×2532 iOS image-bearing foundation screenshot had SHA-256
  `07494f15eadc37916613fd502cc43a8ccaffc7ce2a31a1b1c9d9a51abe208272`.
  It showed a supplied photo behind the strong system-surface gradient and readable cards.
  This is local diagnostic evidence, not patient-advisor, rights, marketing, store,
  distribution, or release approval.

### Dependency identity and current holds

- Regenerated the deterministic foundation dependency inventory after the Android resource
  source-set and iOS resource-phase changes. Component identity remains seven direct
  Android declarations, 83 Release runtime components, 457 dependency edges, zero iOS
  third-party packages, and four Apple system-module imports; only the governed source
  hashes changed.
- Added the comprehensive background governance/integration record and updated the master
  plan, documentation index, accessibility/layout matrix, migration classification, and
  threat/hazard model. The plan checklist now distinguishes completed native engineering
  from open rights, human review, physical-device, signing, store, pilot, and release gates.
- No production database or patient source was accessed. No patient, principal, encounter,
  grant, identity link, session, clinical projection, message, notification, migration,
  deployment, pilot, or release state was created, read, or changed.
- Commit, push, and exact-SHA CI ratification remain required for this background/CI slice.

## Offline privacy/release controls and production reference reconciliation

### Privacy declarations and fail-closed enforcement

- Added one exact iOS `PrivacyInfo.xcprivacy` to the Nightingale application bundle. It
  declares tracking false, an empty collected-data array for the current offline
  foundation, no tracking domains, and one required-reason API category:
  app-local `UserDefaults` under reason `CA92.1`. It does not make a claim about an
  unapproved future connected product or substitute for App Store Connect declarations.
- Added an Android Network Security Configuration with cleartext denied, only system
  certificate authorities trusted, and no debug override. The manifest independently
  declares `usesCleartextTraffic="false"`, retains no `INTERNET` permission, retains
  backup/transfer exclusion, and references the exact policy resource.
- Extended the native product-boundary verifier, Gradle boundary task, iOS XCTest,
  Android instrumentation, and both Release artifact verifiers. They now fail closed on
  privacy-manifest drift, Android transport-policy drift, network permission, unapproved
  network-client primitives, logging/analytics/crash primitives, tracking primitives, and
  clipboard/pasteboard access.
- Added
  [foundation privacy and release-control evidence](../nightingale/FOUNDATION-PRIVACY-RELEASE-CONTROLS-2026-07-26.md)
  and linked it from the Nightingale index. Updated the draft threat/hazard model to 26
  implemented-control claims and ten negative self-tests. This remains engineering
  evidence only; privacy/security approval, future data flows, store declarations,
  signed distribution, penetration testing, and live access remain open.

### Accepted iOS simulator and Release evidence

- Regenerated the Xcode project and confirmed no drift. A normally signed Debug
  build-for-testing passed on the iPhone 16e simulator running iOS 26.3.1 under Xcode
  26.3.
- The complete accepted XCTest run passed 10/10 unit tests and 4/4 XCUITest journeys with
  no failure or skip. This includes parsing the privacy manifest from the built
  application bundle rather than trusting only the repository source.
- The unsigned Release build and exact bundle verifier passed. The Release executable
  SHA-256 is
  `0edc91abecf0a5952db48c0a3a74e39da63d2a19b7a63bbb2d8cf222350ee521`;
  the packaged privacy-manifest SHA-256 is
  `b20184a87e6392f293d241c8888b0bab1774d7cce2586165e87f8e070948a32a`.
- Visually inspected the active shell and inactive privacy cover at 1170×2532. The active
  image SHA-256 is
  `34e123006715badf38832a4cd05f40b14951043a55a7881865d8eec215c39600`;
  the cover image SHA-256 is
  `f7852c9065b5d3c19847168ed978a8b360b015e1f053f5e5e5d9e6392b54be0b`.
  The supplied decorative background remained subordinate to readable cards, and the
  inactive cover withheld the underlying content.

### Accepted Android emulator and Release evidence

- Debug and Release unit suites each passed 8/8. `lintDebug`, `lintRelease`, Debug
  assembly, unsigned Release assembly, the Gradle product-boundary task, and the exact
  Release APK verifier passed.
- The API 35 emulator instrumentation suite passed 8/8 with no failure or skip. The
  installed-package test proves the Nightingale package ID, denied `INTERNET` permission,
  platform cleartext policy false, and absent backup flag.
- The first instrumentation compilation failed because the new platform-policy test used
  the incorrect `android.net.NetworkSecurityPolicy` import. That attempt is not accepted
  evidence. The import was corrected to `android.security.NetworkSecurityPolicy`, and the
  complete 8/8 suite then passed.
- The Debug APK SHA-256 is
  `576788a39e6e7122bb5994c6bdfb60b6f755e6d47d9f5e750c30f8c9aae7e778`;
  the Release APK SHA-256 is
  `d009d9fc72cc5cb626216e3793eebff67612a53100a6f4dae26b0c1084b9b950`.
- `FLAG_SECURE` produced an all-black 1080×2400 shell capture with SHA-256
  `c35bacdb98b522206335afa5b9baffd2e4e3352a40749bb747e469cd403af514`.
  The decoded image contained zero nonblack pixels and only fully opaque alpha. The
  inspected accessibility hierarchy SHA-256 is
  `a84d25001b2d46ac2d21ed16946c2f632d76742d3f3ca9d22a8f1dae682aaa1c`;
  required patient-safe tokens were present, while Hummingbird identity, background
  filenames, patient UUIDs, and encounter UUIDs were absent.
- The compiled manifest and resource inventory plus the installed platform-policy test
  prove the transport policy. A diagnostic attempt to address the compiled XML by its
  source filename through `aapt2 dump xmltree` did not locate that compressed resource
  path and is not treated as evidence.

### Authorized production reference-patient audit

- Used the separately granted production authorization only for a transaction-scoped
  `BEGIN READ ONLY` audit over TLS. No credential, patient name, contact field, source
  identifier, bearer material, enrollment hash, or token was printed, persisted, or added
  to repository artifacts.
- The exact synthetic reference key already resolves to one active, nondeleted,
  nondischarged encounter owned by the existing operational reference provisioner.
  Therefore the requested sample-patient outcome already exists and a duplicate encounter
  was neither needed nor safe.
- The existing command-owned identity foundation contains exactly one safe
  pending/inactive principal, one verified/nonrevoked identity link, and one
  pending-or-active/nonrevoked encounter grant. It also contains two enrollment challenges
  that retain `issued` status but are both expired by time; zero are currently unexpired.
- Performed no production mutation, migration, provisioning command, correction,
  enrollment refresh, activation, deployment, or patient disclosure. The audited records
  are legacy Hummingbird reference fixtures, not a Nightingale identity/source decision.
  The Nightingale migration classification rejects that provisioner as deployed product
  behavior, so this audit does not authorize reuse, reissuance of enrollment material, or
  patient activation.

### Current boundary

- The completed checklist entries cover only the bounded offline privacy carry-forward and
  the read-only reconciliation of an already-existing synthetic production reference.
- Identity/provider selection, real patient linkage, current-inpatient source binding,
  field-level content release, connected API contracts, messaging, notifications,
  accessibility conformance, independent clinical/privacy/security review, distribution,
  pilot, and production release all remain open and default denied.
- Scoped commit, push, and exact-SHA CI ratification remain required for this slice.

## 2026-07-27 — Foundation accessibility and language readiness

### Audit and bounded implementation

- Audited the current iOS and Android offline shells against the remaining Stream E
  language-expansion, RTL, heading, and status-announcement gaps. The audit found Android's
  privacy title lacked heading semantics, status changes lacked an explicit restrained
  announcement policy, visible copy was not governed through one exact cross-platform
  contract, and neither native suite exercised text expansion or RTL.
- Added an exact 15-key English source contract in iOS `Localizable.xcstrings` and Android
  `res/values/strings.xml`. Every visible foundation string now resolves through the
  native localization system. The new self-testing verifier rejects missing/extra/drifted
  copy, runtime literals, unapproved iOS locales, Release pseudolocales, missing headings,
  assertive Android announcements, or missing pseudolanguage journeys.
- Exposed exactly three ordered headings on both platforms. Android's privacy heading now
  carries Compose heading semantics.
- Added exactly two restrained status-notification paths. Android uses polite live-region
  semantics. iOS posts a localized, low-priority `UIAccessibility` announcement only
  after the user changes reduced motion or decorative imagery.
- Added a compile-time Debug-only iOS RTL layout adapter and build-type-specific Android
  language-readiness policy. Android Debug maps only the `ar-XB` pseudolocale to RTL;
  Android Release always uses the platform direction and contains no `ar-XB` literal.
  Pseudolocales remain tests, not translations.

### Accepted native and artifact evidence

- The signed iOS Debug unit suite passed 11/11 on the iPhone 16e simulator running iOS
  26.3.1 under Xcode 26.3.
- The complete iOS UI suite passed 6/6. The added double-length journey proved rendered
  copy expansion, landscape reflow, 44-point controls, and both state changes. The RTL
  journey proved a heading moved from left-leading to right-leading alignment, retained
  the exact five-element semantic order, and preserved an operable mirrored switch.
- The iOS unsigned Release build and exact verifier passed. The Release application
  contains exactly `en.lproj`, its compiled strings parse to all 15 exact values, and no
  test hook or pseudolocale is present. The Release executable SHA-256 is
  `3667f3c743ec7b5ddce915beef2f0c96ead628d2d166d563de2f7e6bf3a1b698`.
- Android Debug and Release unit suites each passed 8/8. `lintDebug`, `lintRelease`, Debug
  assembly, unsigned Release assembly, and the exact Release APK verifier passed.
- The complete Android API 35 emulator instrumentation suite passed 10/10. The new journey
  proved `en-XA` expansion, `ar-XB` bidirectional markers, RTL direction on the actual
  tagged shell, exact semantic order, and a reachable/operable switch. The Debug APK
  contains `en-rXA` and `ar-rXB`; the Release APK contains neither.
- The Android Release APK SHA-256 is
  `28b055b8873f8db9cf98a24edacdbf74349fbb6c6fd013175df12bca4e7f673e`.
  `FLAG_SECURE` retained an all-black capture. The non-PHI `en-XA`/`ar-XB`
  accessibility hierarchies and iOS visual captures are retained under
  `docs/evidence/nightingale/accessibility-language-readiness-2026-07-27/`.
- Regenerated the dependency inventory because iOS now imports the Apple UIKit system
  module for accessibility announcements and Android's build source changed. It retains
  seven direct Android declarations, 83 resolved components, 457 edges, zero iOS
  third-party packages, and now records five Apple system modules.

### Corrected failures retained

- The first Android instrumentation compilation used unavailable
  `SemanticsConfiguration.getOrNull`; `getOrElseNullable` is the accepted implementation.
- The first iOS compilation proved SwiftUI did not expose the attempted
  `accessibilityLiveRegion` modifier. The accepted low-priority UIKit announcement policy
  compiled and passed the complete suite.
- Apple locale/text-direction launch arguments alone did not mirror the English-only
  SwiftUI bundle. The accepted Debug-only direction adapter produced changed geometry and
  is absent from Release behavior.
- The first iOS RTL switch assertion assumed a clean persisted state. The accepted test
  records the pre-tap value and proves an actual change.
- Android's first locale cleanup waited for an empty resource locale even though system
  fallback is nonempty. The accepted helper separately validates app locale selection and
  nonempty resource selection.
- The first Android `ar-XB` run rendered bidirectional markers but left the Compose tagged
  shell LTR under instrumentation. The Debug-only policy now mirrors the actual shell;
  the Release implementation remains platform-directed.
- The first Android direction assertion inspected the instrumentation framework's outer
  root rather than Nightingale's tagged shell. The accepted assertion targets
  `nightingale-safe-shell`.
- One intermediate Compose edit omitted an outer brace and failed both Debug and Release
  compilation. It was corrected before the full unit, lint, assembly, instrumentation,
  and artifact runs. None of these failed preflights is accepted as passing evidence.

### Checklist, threat model, and remaining boundary

- Checked only the new bounded accessibility/language-readiness subitem and immediate
  sequence item 20. The broad WCAG/platform-guidance item and every named human-review
  item remain unchecked.
- Added `CTRL-027` to the draft threat/hazard model and increased its verifier to 27 exact
  controls plus 11 negative self-tests. The new control explicitly withholds translation,
  human assistive-technology, future-screen, and WCAG claims.
- Added the full
  [accessibility/language-readiness evidence](../nightingale/FOUNDATION-ACCESSIBILITY-LANGUAGE-READINESS-2026-07-27.md)
  and linked the retained screenshots/hierarchies from the Nightingale index.
- No clinical or patient-data UI, route, identity/provider, source adapter, network client,
  database query, disclosure, mutation, message, notification, enrollment, migration,
  deployment, pilot, or production activation was added or enabled.
- The already-existing synthetic production reference outcome remains unchanged. This
  slice performed no production database access or mutation.
- Scoped commit, push, and exact-SHA CI ratification remain required for this slice.

## 2026-07-27 — Current-main integration and independent iOS release-boundary correction

### Exact-SHA failure and source-ledger revalidation

- Exact-SHA CI run `30278248743` passed 19 of 20 jobs and failed only the Nightingale
  contract-foundation job. The exact failure was a stale `routes/api.php` digest in the
  evidence-only communication/notification source ledger; all native iOS and Android jobs
  passed.
- Synchronized the isolated Nightingale feature stream with `origin/main` commit
  `b6ea087747d7ea88c8a076f06f4c91a2636ea029`. The streams had no overlapping changed
  paths, and the merge completed without conflict.
- Revalidated every one of the 130 classified communication/notification source paths
  against that exact main commit. All paths match it. Only `routes/api.php` changed from
  the prior evidence snapshot.
- Inspected the twelve-line route delta. It adds three Zephyrus staff/operations reads:
  scoped Arena per-case conformance, an aggregate patient-flow epoch, and a scoped
  patient-flow journey. It adds no Nightingale route, communication operation,
  notification provider, patient-native transport, or communication state.
- Reissued the generated ledger with the current route digest and advanced its reviewed
  source commit/date. The `routes_rejected` disposition, all ten required findings, all
  17 decisions, and every default-deny constraint remain unchanged.

### Legacy release-path collision found and corrected

- The deeper product-universe pass found a material mainline regression: the historical
  `hummingbird/iosPatientApp` project, generated project, and Info.plist had been renamed
  to the Nightingale bundle/display identity, and a Nightingale App Store export profile
  had been placed inside that legacy root. This created two competing Nightingale iOS
  sources and contradicted the independent-product decision.
- Correction commit `85316fbc5794735a77a0a4fa0e0096e18db4240b` restores the three
  legacy identity-bearing files to their exact pre-collision Hummingbird Patient bytes.
  The Nightingale export policy now lives under `nightingale/iosApp`; the non-secret Apple
  registry points only to `nightingale/iosApp`, `Nightingale.xcodeproj`, and the
  `Nightingale` scheme.
- Added a fail-closed IPA identity verifier. It reads an IPA without extraction, rejects
  unsafe paths or multiple top-level applications, and requires the expected bundle ID,
  build number, marketing version, and `APPL` package type. Its self-test passes one
  positive and rejects wrong-bundle, wrong-build, duplicate-app, and malformed-archive
  mutations.
- The root TestFlight helper now requires a registry bundle ID, decimal-only build number,
  an exact safe export-cleanup target, and a verified exported IPA before it can report a
  successful export. No archive, signature, upload, App Store query, tester distribution,
  or pilot action was performed.
- Product-boundary CI now rejects legacy Nightingale identity, a misplaced export profile,
  registry reversal, export-policy drift, missing independent distribution documentation,
  or IPA-verifier failure. The generated legacy Xcode project, mobile brand surfaces,
  product boundary, plist parsing, helper syntax, and Nightingale Release build settings
  all pass locally.

### Corrected universe and dependent evidence

- The only new tracked source retained under the legacy patient roots is
  `hummingbird/iosPatientApp/.gitignore`. It is classified as a reusable generated-artifact
  hygiene principle, not product behavior. The complete migration universe is therefore
  256 paths: 122 unique prior-ledger sources plus 134 final-slice sources.
- The full-universe digest is
  `a307e1957df7ef78eb61a9a9123f3902fd8929ebb3aaeb4dce48f2c88fb4a881`;
  the final-slice path digest is
  `4301de88a9071214001c2a58aa8fbc624bb49f24bb566428c8a1ef98aa44c13d`.
  The final slice contains 42 reusable safety primitives, 20 held product behaviors, 28
  test/fixture-only sources, and 44 rejected legacy behaviors.
- Reissued the dependent, still-held Today evidence with the corrected universe identity.
  Its 68 synthetic outcomes, 24 adversarial mutations, 14 direct-source checksums, empty
  executable contract, and all activation=false conditions remain unchanged.
- Checked only the new mainline-collision correction and revalidation checklist section.
  The broad signed-distribution/store/upgrade item remains open, as do every live identity,
  source, clinical content, communication, notification, independent review, pilot, and
  release gate.

### Current-main native reacceptance and lifecycle correction

- Re-ran the signed Nightingale iOS Debug suite on the iPhone 16e simulator running iOS
  26.3.1. All 11 unit tests and all six UI journeys passed, including double-length text,
  accessibility text in landscape, Debug-only RTL, display preferences, the offline
  privacy message, and the lifecycle privacy cover.
- Built the independent Nightingale iOS target in unsigned Release configuration and
  passed the exact Release-artifact verifier. Its bundle identifier is
  `net.acumenus.nightingale`; its executable SHA-256 is
  `a747b4efbfdd33e94c39fa996e76f9798ed51232e4412c57e1798b48394615f9`.
- Independently built the restored legacy Hummingbird Patient iOS target in unsigned
  Release configuration and passed its patient-transport artifact verifier. Its bundle
  identifier is `net.acumenus.hummingbird.patient`; its executable SHA-256 is
  `098e92a7be150ad25c3572c4ea1c0976e218dc76ec1fff0105551c9f03c1bdeb`.
  This proves the two Release products no longer collide; it does not approve either
  artifact for signing or distribution.
- Android Debug and Release unit suites each passed 8/8. `lintDebug`, `lintRelease`,
  Debug assembly, unsigned Release assembly, and the exact Release APK verifier passed
  under Android Studio's bundled Java 21 runtime. The Debug APK SHA-256 is
  `6511676a6ce092b2a868b1bc6f28027f7a60dd13f27da1e994a74473fdab2b47`;
  the unsigned Release APK SHA-256 remains
  `28b055b8873f8db9cf98a24edacdbf74349fbb6c6fd013175df12bca4e7f673e`.
- The first complete Android emulator run passed nine of ten journeys but exposed a
  harness race: changing `LocaleManager.applicationLocales` automatically destroyed and
  recreated the Activity while the helper also called `ActivityScenario.recreate()`.
  The helper now waits for the platform-created replacement Activity to reach `RESUMED`
  with the requested resource locale instead of issuing a competing recreate. The failed
  case then passed alone, and the complete API 35 suite passed 10/10.
- The first Gradle preflight used the shell's Java 8 runtime and failed before project
  configuration. It is not accepted evidence. The complete accepted command used Android
  Studio's Java 21 runtime and passed all requested unit, lint, assembly, and emulator
  tasks.
- Test correction commit `45ddf907c0f15e378b37a1b0726724e346cb29fd` is the new exact
  reviewed source commit for the final 134-source classification. The product-universe
  and final-slice path sets and digests are unchanged; the changed Android test source
  byte checksum and dependent Today direct-source evidence are regenerated from that
  commit.
- No production database access, mutation, migration, provisioning, deployment, patient
  disclosure, live identity/source binding, networking, message, notification, or
  activation occurred in this reacceptance slice. Exact-SHA CI remains required after the
  scoped evidence commit is published.

## 2026-07-27 — Cross-surface patient-journey reference scenarios

### Reconciliation and bounded decision

- Reconciled the master plan's required patient scenarios against the held encounter,
  identity/session/recovery, current-inpatient-source, Today, communication/notification,
  and complete migration-classification evidence. This was a repository-only review; no
  production database, deployed service, patient, representative, clinician, message,
  source response, or runtime configuration was accessed.
- Added a held, non-runnable candidate covering exactly 15 families through 27 synthetic
  no-PHI cases: admission; transfer; procedure; test delay and result release; pre-round
  question and released response; shift handoff; changed discharge estimate; discharge
  with an open thread; identity correction; representative invitation/scope/expiry/
  revocation; language and interpreter support; visual/hearing/motor/cognitive/low-literacy
  accommodations; sensitive-data denial; source outage/staleness; and content retraction/
  correction.
- The catalog makes cross-surface transition expectations explicit without approving
  patient functionality. Source events do not become patient releases; server acceptance
  does not become staff delivery/read/review; representative and sensitive-resource cases
  remain fully withheld; outages do not become empty care plans; stale display is
  field-policy-specific; and retraction/correction propagates atomically.
- Every case requires the same 13 global approval gates plus a scenario-specific approval.
  Every response remains separate-release-gated, atomic, no-store, content-free-audited,
  inaccessible to offline PHI or queued mutation, and bound to kill-switch/release-
  withdrawal/cache-handle-draft purge requirements.

### Reproducible artifacts and enforcement

- Added deterministic builder
  `scripts/ci/build-nightingale-patient-journey-reference-candidate.mjs`, which emits the
  candidate and fixtures under
  `docs/nightingale/api-contract/candidates/patient-journeys/v0/`.
- Bound the candidate to 12 exact source artifacts at reviewed commit
  `a825db89ec1efaf4b2c55a26e04d41161445b001`. The source inventory digest is
  `aed369c71dd22596ed09c908746e529617c3a62e1c92cfea5cb595895245ffea`;
  the canonical fixture digest is
  `525c6f9264a14687b906485fcd105dd689c41e207785355ebf4f31a38f569de9`.
- Added an independent verifier that rejects an executable path or activation, any of 19
  runtime/production permissions becoming true, family/case drift, a runnable or
  non-synthetic fixture, missing release/no-store/approval controls, representative or
  sensitive disclosure, concrete identifiers, endpoint exposure, delivery-state
  overstatement, offline PHI/queue/stale-current behavior, accessibility information/action
  loss, weakened identity/outage/retraction behavior, source drift, or fixture drift.
- The verifier reproduces both artifacts from the deterministic builder, passes the
  canonical record, and rejects all 23 adversarial mutations. It is
  now part of the existing Nightingale contract-foundation CI job.
- The initial verifier run correctly rejected an over-broad assumption that every value
  under `x-nightingale-activation` is Boolean; the foundation also contains the exact
  string `default: disabled` and an approval-list array. The accepted verifier explicitly
  checks the nine activation Booleans plus the disabled default.
- The next run rejected the admission case because it had only the global approval set.
  The accepted fixture now also requires an explicit admission-context and first-release
  policy. Neither failed preflight is accepted as passing evidence.

### Checklist and remaining boundary

- Checked only the existing Stream D reference-scenario item. The broad implementation
  item for Today, My Path, Care Team, education/teach-back, discharge, and communication
  remains unchecked, as do generic unknown/revoked/cross-principal proof, all named
  approvals, production-like integration, pilot, distribution, and deployment.
- The executable Nightingale contract still contains zero paths. All route, controller,
  provider, client, native networking, identity-provider, source-adapter, database query,
  clinical release, communication mutation, notification, representative creation,
  production, migration, deployment, and pilot permissions remain false.
- Exact-SHA CI ratification remains required after this scoped candidate slice is
  committed and pushed.

### Exact-SHA security preflight correction

- Exact-SHA CI run `30288424956` passed the Nightingale contract-foundation job,
  including the new catalog and all 23 negative self-tests, but the history secret scan
  rejected one line of this devlog before the overall run could qualify as release
  evidence.
- The retained redacted scanner report identifies rule `generic-api-key`, this document,
  line 1917, and commit `e72abafaaba728ebb8d7aedde154c3086d6a09b9`. Inspection of the
  source and redacted evidence confirmed that adjacent ordinary prose was parsed as a
  key/value construction and that the captured value was the non-secret prose fragment
  `notification/delivery`; no credential, token, endpoint, patient datum, or production
  value was present.
- Rephrased the live document to remove the ambiguous construction and added an
  AND-scoped history exception for only this exact document and captured word. No path
  wildcard, rule disablement, credential-shaped regex, or production-secret exception was
  introduced. The failed run remains failed evidence; a new exact-SHA history and
  working-tree scan is required.

## 2026-07-27 — Route-free generic non-disclosure foundation

### Bounded implementation

- Added Nightingale-owned relationship, current-context-binding, resource-release, and
  disclosure-disposition vocabularies plus a pure generic non-disclosure gate under
  `app/Nightingale/Disclosure`.
- The gate accepts no identifier or patient value and has no framework, route, controller,
  provider, source, database, audit-writer, or native caller. It cannot activate or
  disclose anything.
- Unknown, revoked, expired, cross-principal, wrong-encounter, omitted-resource, and failed
  upstream-precondition states all produce the exact same public failure tuple: status
  `404`, code `not_found`, and cache policy `private, no-store, max-age=0`. There is no
  message, internal reason, identifier, redirect, or retry field.
- Only the all-positive combination of prerequisite continuation, active relationship,
  matching current context, and released resource returns
  `continue_to_governed_projection_evaluation`. That disposition is explicitly weaker than
  authorization or clinical release.

### Independent proof

- Expanded the backend PHPUnit foundation to 21 tests and 111 assertions. Seven named
  cases directly cover the master-plan requirement, and the exhaustive test evaluates all
  40 combinations: one continues and 39 withhold.
- Expanded the dependency-free backend verifier to pin the exact state vocabularies,
  public tuple, one/39 cardinality, and runtime non-registration. It repeats the 40-row
  truth table independently of PHPUnit and remains part of the existing contract CI job.
- Added
  `docs/nightingale/GENERIC-NON-DISCLOSURE-FOUNDATION-2026-07-27.md` with the evaluation
  matrix, audit boundary, runtime limitations, evidence inventory, verification commands,
  and unresolved operation-specific requirements.
- Checked only the Stream D generic non-disclosure item. Operation-specific identity,
  source, authorization, audit, timing-equivalence, HTTP-edge, clinical/content,
  language, native-client, reviewer, pilot, and production requirements remain open.
- No production database access, mutation, migration, patient creation, route, identity or
  source binding, patient disclosure, network client, clinical content, communication,
  notification, representative access, activation, distribution, or deployment occurred
  in this implementation slice.

### Native regression reacceptance

- Booted the iPhone 16e simulator on iOS 26.3.1 and ran the complete signed Debug test
  scheme from a fresh DerivedData directory. All 11 Nightingale unit tests and all six
  UI journeys passed with zero failures or skips, including persistence, double-length
  reflow, largest-text landscape, foundation copy, lifecycle privacy cover, and
  right-to-left layout.
- Cold-booted the wiped `hb` Android 15/API 35 AVD without a snapshot. The accepted Java
  21/Android SDK invocation reran all eight Debug JVM tests, all eight Release JVM tests,
  and all ten installed instrumentation journeys with zero failures, errors, or skips.
- The first Android invocation was not accepted evidence because the isolated shell did not
  export `ANDROID_HOME`; Gradle stopped before dependency resolution or test execution.
  The accepted invocation explicitly pinned Android Studio Java 21, `ANDROID_HOME`, and
  `ANDROID_SDK_ROOT`.
- Both emulators were shut down after result-XML reconciliation. This native pass proves
  that the backend-only non-disclosure slice did not regress the current offline products;
  it does not make the new backend sample reachable from either app.

## 2026-07-27 — Authorized Nightingale production sample clone

### Direction change and source preservation

- The prior production audit had deliberately remained read-only because the plan then
  prohibited a Nightingale production sample. The operator subsequently gave an explicit
  direction to create the Nightingale sample and to use the deprecated Hummingbird Patient
  reference patient as its source template.
- Preserved the Hummingbird operational encounter, principal, identity link, grant, and
  expired enrollment-challenge records unchanged. No Hummingbird credential, challenge,
  external UUID, keyed digest, encrypted source reference, projection content, or
  product-owner value was copied.
- Cloned only the safe synthetic shape: patient principal type, `en-US` locale,
  `America/New_York` timezone, medical/surgical unit 85, no bed, acuity 2, and an active
  operational/not-discharged state.

### Transactional creation

- The first serializable, advisory-locked transaction created exactly one
  `prod.encounters` row with patient reference `demo-nightingale-reference-inpatient` and
  owner `nightingale-reference-patient-provisioner-v1`. Exact zero-cardinality, owner
  collision, unit availability, lock-timeout, statement-timeout, constraint, and
  pre-commit row checks were enforced.
- The second serializable transaction selected exactly one safe Hummingbird template
  principal, generated a fresh UUIDv7, and created exactly one principal with display name
  `Nightingale Reference Patient`. It is synthetic, product-tagged `nightingale`, pending,
  inactive, and has no email, phone, password, verification timestamp, or authentication
  history.
- A fresh read-only reconciliation proved one exact operational row and one exact
  principal, with zero Nightingale identity links, encounter grants, enrollment
  challenges, sessions, access-audit events, and notification devices. It also reproved
  the original Hummingbird encounter/principal/identity/grant one/one/one/one
  cardinalities.

### Boundary

- The sample cannot log in or reach patient data. It has no database-level identity/
  encounter association, no projection, no route, no source adapter, and no native caller.
  Nightingale's executable contract remains empty and both native applications remain
  offline.
- No migration, clinical content, representative record, message, notification, feature
  activation, pilot enrollment, application deployment, or teardown occurred.
- The complete secret-free preflight, transaction, reconciliation, residual-risk, and
  follow-up record is
  `docs/evidence/nightingale/production-sample-patient-2026-07-27/README.md`.

### Direction-confirmation reconciliation

- After the operator reiterated that Nightingale must use the deprecated Hummingbird
  Patient sample, reran the production proof in a repeatable-read, read-only transaction.
  It reconfirmed the exact one/one Nightingale encounter/principal result; zero
  Nightingale identity links, grants, challenges, sessions, audit events, and notification
  devices; and the unchanged one/one/one/one Hummingbird
  encounter/principal/identity-link/grant result.
- Corrected the evidence vocabulary to name the exact lineage keys:
  `preferences.provisioning.source_template_product=hummingbird_patient` and
  `preferences.provisioning.source_template_owner=hummingbird-patient-reference-identity-provisioner-v1`.
  A first metadata predicate used a nonexistent generic `source_template` path; the
  follow-up isolated that query mistake and proved the stored sample itself was correct.
- No duplicate sample, identity binding, encounter grant, content projection, route,
  activation, migration, deployment, or other production write was required or performed.

## 2026-07-27 — Current-main reconciliation at `a979e786`

### Upstream merge and fail-closed evidence refresh

- Fetched and merged `origin/main` at
  `a979e78630c214f6f77d96ce702227da6c03a9b6` without conflicts. The upstream slice
  contains the Flow 4D per-patient conformance surface and iOS CI build-chain
  improvements; it does not add a Nightingale route or native patient capability.
- The Nightingale contract chain correctly stopped at the 130-file
  communication/notification ledger because `routes/api.php` had changed. The five
  preceding contract, encounter, Today, journey, and identity/source verifiers all
  passed before that fail-closed stop.
- Reviewed the exact 15-addition/five-deletion route delta. It gates the existing staff
  Arena conformance read and adds a staff-only scene read plus a governed Eddy
  exception-note proposal. These are staff operations behind Arena, Flow 4D, and
  scoped-patient controls; they add no Nightingale patient communication, notification,
  client transport, delivery-state claim, or migration permission.
- Extended the generated ledger with an exact source-revalidation record containing the
  upstream commit, previous/current SHA-256 values, line counts, unchanged
  classification impact, and bounded rationale. The verifier now rejects commit, date,
  source, digest, line-count, impact, and rationale drift and includes a negative
  mutation that attempts to promote the impact to `approved`.
- This reconciliation changes no Nightingale checklist item, product behavior, route,
  source adapter, identity binding, content release, production record, native client,
  or activation state.

## 2026-07-27 — Namespace closure and activation-state separation

### Implementation

- Added a deterministic Nightingale namespace manifest covering 12 exact native source
  files, ten shared accessibility IDs, four iOS Debug hooks, ten fully qualified
  persistent identifiers, zero telemetry events, and zero diagnostic channels.
- Fully qualified the Android presentation keys and both platforms' future-session-binding
  identifiers under `net.acumenus.nightingale`. Strengthened native tests and the
  product-boundary scanner so short or legacy identifiers fail closed.
- Added an independent manifest verifier that reproduces builder output byte-for-byte,
  validates source SHA-256 values, rejects legacy Hummingbird Patient tokens and
  unregistered logging/analytics primitives, and exercises eight negative mutations.
  Contract CI and both native product-boundary jobs invoke it.
- Added separate PHP state types for institutional clinical approval, patient-content
  release, product feature activation, pilot enrollment, and source-connector deployment.
  All five default to negative states in code-owned configuration without environment
  hooks.
- Added a route-free conjunctive gate. The independent verifier and PHPUnit enumerate all
  32 combinations: 31 return `hold`; only the all-positive row returns
  `continue_to_operation_specific_release_evaluation`. That continuation is explicitly not
  identity, disclosure, production, clinical release, or pilot authorization.
- Extended the empty executable contract and all dependent evidence verifiers with five
  separate false activation facts. Regenerated the held patient-journey candidate after
  its exact contract-source checksum changed. No route, provider binding, source query,
  patient content, message, notification, telemetry, diagnostic channel, migration, or
  deployment was added.

### Backend and contract acceptance

- The focused backend suite passed 23 tests and 149 assertions.
- The complete Nightingale contract chain passed, including the empty contract, encounter
  candidate, Today candidate, 15-family/27-case patient-journey catalog, 64-case
  identity/source decisions, 65-source identity ledger, 130-source communication ledger,
  134-source journey ledger, threat/hazard model, dependency inventory, namespace
  manifest, backend verifier, and native product boundary.
- The namespace manifest reproduced exactly and all eight negative mutations failed as
  required. The activation proof retained exactly one limited continuation and 31 holds.

### Native acceptance

- Rebuilt from isolated `/tmp` roots. Android passed eight Debug and eight Release unit
  tests, Debug/Release lint, Debug/Release assembly, and the unsigned Release APK boundary
  verifier with zero test failures, errors, or skips.
- Ran a fresh iOS build-for-testing and the iPhone 16e simulator on iOS 26.3.1. The
  xcresult summaries recorded 11/11 unit tests and 6/6 UI journeys, with zero failures or
  skips. The unsigned Release application and exact binary boundary verifier also passed.
- The shared `hb` Android AVD was already occupied by a separate Claude process. Preserved
  that process and instead cold-booted a wiped, isolated `nightingale-codex` Android
  15/API 35 AVD on port 5556. All 10/10 installed journeys passed with zero failures,
  errors, or skips. Shut down only the isolated AVD and the Nightingale iPhone simulator;
  the pre-existing Android emulator remained untouched.

### Checklist result and residual boundary

- Checked the bounded Stream C namespace-reissue item and Stream D
  approval/release/activation/enrollment/connector-separation item. The master checklist
  now records 41 of 54 items complete, or 75.93%.
- The remaining 13 items continue to require live operation contracts and implementations,
  approved identity/source/content/reviewer decisions, signed-distribution evidence,
  human validation, integration exercises, or pilot authorization. The sample patient
  remains pending/inactive and unreachable.

## 2026-07-27 — Current-main duplicate-identity reconciliation

### Collision and resolution

- Fetched `origin/main` at `ea14d2bd2be9656555dfba35da2dbf28e74730e3`.
  It included unrelated Flow 4D plan commit `f63e5994` and PR #101, which renamed the
  deprecated Hummingbird Patient native/backend/contract surfaces to Nightingale.
- Merged the complete upstream graph at `bcaae3da`; then forward-reverted only PR #101 at
  `c11d462c`. This preserves the unrelated Flow 4D change and published history while
  preventing two native roots and two backend configurations from claiming one
  Nightingale identity.
- Resolved all overlapping paths against the exact previously accepted
  `21e4de17e27c258955c0b14ba490f9d0d4be16b5` tree. The boundary-restoration tree at
  `c11d462c` differs from that baseline only in the Flow 4D plan, and that path is
  byte-identical to `f63e5994`.
- Reconfirmed the canonical split: `nightingale/iosApp` and
  `nightingale/androidApp` own `net.acumenus.nightingale`; the deprecated
  `hummingbird/*PatientApp` roots retain `net.acumenus.hummingbird.patient`.
  `config/nightingale.php` remains route-free and inert; the legacy Hummingbird Patient
  configuration/contract remains migration input.

### Complete reacceptance

- The 13-stage contract, candidate, classification, threat/hazard, dependency, namespace,
  backend, and native-boundary chain passed, including all negative self-tests and exact
  256-source predecessor coverage.
- The focused Laravel suite passed 23 tests and 149 assertions using a worktree-local
  dependency tree whose `composer.lock` SHA-256 matched the canonical checkout.
- On a freshly booted iPhone 16e simulator running iOS 26.3.1, Nightingale passed 11/11
  unit tests and 6/6 UI journeys with no failures or skips. The unsigned Release
  application passed the complete artifact boundary.
- Android passed 8/8 Debug and 8/8 Release unit tests after forced uncached execution,
  both lint variants, both assemblies, and the unsigned Release APK boundary.
- Preserved the other process's API 35 emulator on port 5554. A separate read-only API 35
  instance on port 5556 passed 10/10 installed journeys with zero failures, errors, or
  skips, then only that instance was shut down. The iOS simulator was also shut down.

### Checklist and safety accounting

- Added the exact reconciliation record under
  `docs/evidence/nightingale/current-main-reconciliation-2026-07-27/`.
- No new functional checkbox is credited. This restores an already-completed boundary, so
  the master checklist remains 41/54 (75.93%) with all 13 approval, implementation,
  distribution, human-review, integration, and pilot items open.
- No production database access, sample-patient mutation, route, source query, identity
  provider, content release, notification, signing, upload, deployment, or activation
  occurred. The authorized Nightingale sample remains pending/inactive and unreachable.

## 2026-07-27 — Default-off controlled-pilot manifest foundation

### Configuration and governance boundary

- Added a generated, non-runnable controlled-pilot candidate covering the exact
  facility, unit, cohort, language, exclusion, support-hour, validity, prerequisite,
  named-approval, audit, rollback, and kill-switch fields required before an external
  pilot go/no-go review.
- Kept the committed template empty and fail-closed: revision zero, draft/inactive, no
  facility/unit/cohort/language/support values, zero allowed enrollment, no validity
  window, no prerequisite/approval records, an engaged kill switch, and no review request
  or runtime activation.
- Defined opaque `ngf_`, `ngu_`, and `ngc_` handle classes; separate released
  inclusion/exclusion policies; a 25-enrollment technical ceiling; prohibited automatic
  enrollment; canonical locale tags with released interpreter coverage; and withhold on
  unknown language or unavailable eligibility/exclusion inputs.
- Required IANA-style timezone configuration, non-overlapping local weekly support
  windows, separately released urgent-help guidance, and a fixed uncovered-hours
  disposition that withholds new enrollment without exposing staffing availability.
- Required exact UTC start/expiry timestamps, a maximum validity of 168 hours, explicit
  fail-closed expiry, and a new manifest for renewal. The seven-day ceiling is a technical
  authorization lifetime rather than a patient length-of-stay or pilot-duration claim.
- Required eight prerequisite records and seven distinct named approval roles. The
  candidate records no real approver and does not appoint one.
- Required nine append-only, durable-before-change audit events with nine content-free
  fields. Patient identifiers, clinical values, and message bodies are prohibited; audit
  failure holds.
- Required a default-engaged kill switch, exact rollback target and verification records,
  and no enrollment or disclosure after expiry. No kill-switch or rollback service was
  implemented.

### Mechanical acceptance

- Added a deterministic builder and an independent verifier. The verifier reproduces both
  JSON artifacts exactly, binds four foundation/activation sources by SHA-256, requires
  the executable contract to retain zero paths, and requires every executable activation
  fact plus every backend production/disclosure/mutation default to remain negative.
- Evaluated 34 synthetic no-PHI cases. Exactly 33 hold. The single complete synthetic
  case may proceed only to `eligible_for_external_go_no_go_review_only`, which is
  expressly not activation, enrollment, deployment, disclosure, clinical release, or
  production authorization.
- Exercised 25 adversarial artifact mutations. Corrected the self-test harness during
  implementation so a mutation counts only if validation itself rejects it; a synthetic
  “did not fail” assertion can no longer be mistaken for a passing negative test.
- Integrated the verifier into the docs-sensitive Nightingale contract CI job and the
  native product-boundary chain.
- The full 14-stage Nightingale contract/backend/native-boundary chain passed. The
  focused Laravel suite remained 23/23 tests and 149 assertions.

### Native emulator reacceptance

- Booted an isolated iPhone 16e simulator on iOS 26.3.1. The generated Xcode project
  matched `project.yml`; 11/11 unit tests and 6/6 UI journeys passed with zero failures or
  skips. The unsigned Release Simulator application passed the exact identity, privacy,
  dependency, no-network/no-deep-link/no-test-hook, English-copy, and background boundary.
  The isolated simulator was shut down.
- Preserved the other process's API 35 Android emulator on port 5554. Started a second
  read-only `hb` instance on port 5556. Both lint variants, both assemblies, and the
  unsigned Release APK boundary passed; all 10/10 installed journeys passed with zero
  failures, errors, or skips.
- The first Android JVM invocation restored cached outputs and was not accepted as fresh
  execution. A forced `--rerun-tasks` pass executed all 45 actionable tasks and passed
  8/8 Debug plus 8/8 Release tests with zero failures, errors, or skips.
- Terminated only the port-5556 Android instance. The pre-existing port-5554 emulator
  remained connected.

### Checklist and safety accounting

- Checked the first Stream F item and added immediate-sequence milestone 22. Exact
  checklist reconciliation is now 42 checked, 12 open, 54 total: **77.78% complete**.
- Added the decision record under
  `docs/nightingale/CONTROLLED-PILOT-MANIFEST-FOUNDATION-2026-07-27.md` and exact
  acceptance evidence under
  `docs/evidence/nightingale/controlled-pilot-manifest-2026-07-27/`.
- No production database access, sample-patient change, real pilot scope, identity/source
  selection, patient operation, enrollment, content release, audit sink, route, client,
  signing, distribution, deployment, or activation occurred. The sample remains
  pending/inactive and unreachable.
