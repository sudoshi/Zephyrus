# Hummingbird ASAP Pilot-Completion Devlog — 2026-07-24

**Plan:** [Hummingbird ASAP Pilot-Completion Plan](../plans/hummingbird-asap-pilot-completion-2026-07-24.md)
**Status:** active execution reset; bounded staff increments accepted locally;
no patient feature enabled by this entry.

## Baseline

- The governing Hummingbird plan has 196 checked and 277 unchecked checklist items
  (473 total), reconciled from every Markdown checkbox on 2026-07-25. This is an
  unweighted work-item count, not a clinical-readiness
  percentage.
- The program is reset to a controlled inpatient-pilot cutline: approved
  Today/My Path/Care Team/discharge projections plus accountable secure messaging
  for one facility and two units.
- Patient Eddy, proxy access, attachments, patient push delivery, offline message
  queues, post-discharge handoff, broad staff parity, and general availability are
  deliberately outside that pilot cutline.
- The worktree is clean after two bounded staff increments: native Eddy streaming
  (`2c60052b`) and server-governed patient-communication action affordances
  (`7764bde1`). Neither authorizes deployment or patient feature enablement.

## Daily control board

| Release slice                            | Owner                               | Status                    | Decision/dependency                                                                   | Evidence                                                                                                                                                                                               | Flag state                            | Next action                                                     |
| ---------------------------------------- | ----------------------------------- | ------------------------- | ------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------- | --------------------------------------------------------------- |
| Native staff Eddy SSE completion         | Engineering                         | Ready for review          | No external decision                                                                  | Laravel BFF 9 tests/66 assertions; stream service 3/13; Android 122 Debug JVM + 26 API 35 AVD; iPhone 17 Pro 110 tests; contract/ledger verifiers pass                                                 | Staff-only; no patient flag           | Include with scoped commit; no deployment                       |
| Staff communication action affordances   | Engineering                         | Accepted locally          | No external decision                                                                  | PHP 19 tests/446 assertions; web 14 tests + production build; Android API 35 12 tests; iOS 93 unit + 4 UI tests; all client controls fail closed                                                       | Staff-only; no patient flag           | Hold for review; do not expand while pilot gates block          |
| Staff and patient DTO fixture provenance | Engineering                         | Accepted locally          | No external decision                                                                  | 8 factory-seeded staff BFF captures; 6 test-only patient BFF captures plus 1 labelled derived forward-compatibility probe; PHP, iOS, Android decoders; iPhone 17 Pro and Android API 35 journeys green | Contract-only; no patient flag        | Add generated artifacts; keep approved-source fixtures separate |
| Reference-patient dry-run integrity      | Engineering                         | Accepted locally          | No external decision                                                                  | Local regression proves preview does not create an encounter or opaque-resolver row; remote read-only preview finds sole active encounter `10040` / unit `85`                                          | All patient flags remain off          | Keep identity, draft, and release gates unchanged               |
| Pilot scope and governance               | Product / clinical / privacy        | Blocked                   | Facility, units, cohort, disclosure policy, identity, language, SLA, escalation owner | None yet                                                                                                                                                                                               | All patient flags remain off          | Convene decision meeting within one business day                |
| Approved patient source/release adapter  | Integration / clinical content      | Blocked                   | Named source system, source contract, review/release owner                            | Draft/reconciliation kernel exists; no approved production adapter or release authority                                                                                                                | All patient exposure flags remain off | Select source and write source contract                         |
| Patient native vertical slice            | Native / accessibility              | Contract ratified locally | Released pilot projection contract                                                    | Six test-only BFF fixtures decode through both production model layers; iPhone 17 Pro UI and Android API 35 journey are green                                                                          | All patient flags remain off          | Bind the same harness to approved-source release fixtures       |
| Accountable communication pilot          | Nursing ops / support / engineering | Blocked                   | Responsibility pools, shifts, topics, SLA, support desk                               | Local workflow foundations exist; pilot configuration and deployed E2E do not                                                                                                                          | Messaging remains off                 | Configure two pilot unit pools and tabletop                     |
| Integrated pilot rehearsal               | Release / independent reviewers     | Not started               | Waves 1–4 exit evidence                                                               | None yet                                                                                                                                                                                               | All patient flags remain off          | Schedule after source, governance, and workflow gates           |

## Evidence convention

Each subsequent entry must cite the exact commit SHA, command output/test count,
simulator or emulator target, feature-flag state, decision record, and unresolved
blocker. Narrative progress without those artifacts is not an accepted update.

## 2026-07-25 — Cross-platform evidence reconciliation and pilot-control template

**Tested source head:** `72ea2862325a5728e261c0022f78cd2da374ccc5`
**Evidence/doc commit:** recorded with this documentation change

### Completed evidence and control work

- Reconciled every Markdown checkbox in the governing Hummingbird parity plan:
  196 checked and 277 unchecked items (473 total). This is an unweighted
  work-item count, not a clinical, privacy, accessibility, or release-readiness
  score.
- Replaced stale assertions that the focused Patient PHP suite could not run
  locally. The isolated `zephyrus_test` boundary was available and the complete
  `tests/Feature/Patient` suite now has current evidence.
- Published the
  [fail-closed controlled-pilot configuration manifest](../operations/HUMMINGBIRD-CONTROLLED-PILOT-CONFIGURATION-MANIFEST.md).
  It inventories the patient-realm, Care Pathways, and Virtual Rounds patient
  bridge controls; requires default, owner, classification, audit evidence,
  rollback, and expiry for each; and leaves every authorization-time value
  unassigned/off.

### Verification

| Boundary            | Command / target                                                                                                                            | Result                                                                                         |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| Patient backend     | `php artisan test tests/Feature/Patient --stop-on-failure` against isolated `zephyrus_test` PostgreSQL                                      | 205 tests, 3,535 assertions passed in 61.09 seconds                                            |
| Android patient app | `./gradlew --no-daemon connectedDebugAndroidTest --rerun-tasks --console=plain` on `hb(AVD)` / API 35                                       | 17 instrumentation tests passed; build successful                                              |
| iOS patient app     | `xcodebuild test -project HummingbirdPatient.xcodeproj -scheme HummingbirdPatient -destination 'platform=iOS Simulator,name=iPhone 17 Pro'` | 85 tests passed; 0 failed, skipped, or expected failures (iPhone 17 Pro, iOS Simulator 26.3.1) |

### Explicit remaining boundary

This evidence and template do not approve a facility, unit, cohort, source,
projection release, identity/enrollment workflow, message policy, staffing
pool, feature flag, visual asset, production patient, database change, or
deployment. The required signed decisions, controlled test environment,
governed source/release chain, multidisciplinary accessibility review, and
independent release approval remain active blockers.

## 2026-07-25 — Patient journey instrumentation stability repair

**Implementation commit:** `58d13b62cb1a0d45d7eaa6b80c6407941d399766`

### Completed implementation

- Exact-head CI run `30181379346` exposed one Android API 35 failure in the
  existing synthetic reference journey. The job had already completed source
  boundary, Debug/Release unit, Debug/Release lint, and Debug/Release assembly
  checks. The failure was a Compose `SnapshotStateObserver` cross-thread
  exception while the test traversed the `patient-content` lazy semantics tree
  after successive scrolls; it was not a patient-content assertion, API call,
  authorization, or privacy-policy failure.
- Added one `patientContent()` test helper that waits for Compose idleness
  before each lazy `patient-content` semantics traversal. The established
  journey assertions and synthetic data remain intact; this only prevents test
  code from reading the tree while the UI thread is completing layout after a
  prior scroll.

### Verification

| Boundary                                          | Command / target                                                                                                                                                                                                                                                                                   | Result                                                             |
| ------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------ |
| Former exact failure                              | `./gradlew --no-daemon connectedDebugAndroidTest --rerun-tasks --console=plain -Pandroid.testInstrumentationRunnerArguments.class=net.acumenus.hummingbird.patient.PatientPrimaryJourneyInstrumentedTest#syntheticReferenceJourneyKeepsEveryPrimarySurfaceReadableAndSecure` on `hb(AVD)` / API 35 | 1 instrumentation test, 0 failures/errors/skips                    |
| Android full patient regression                   | `./gradlew --no-daemon connectedDebugAndroidTest --rerun-tasks --console=plain` on `hb(AVD)` / API 35                                                                                                                                                                                              | 17 instrumentation tests, 0 failures/errors/skips                  |
| Android static analysis                           | `./gradlew --no-daemon lintDebug --console=plain`                                                                                                                                                                                                                                                  | Passed                                                             |
| Product boundaries and governed patient artifacts | `scripts/ci/verify-hummingbird-patient-boundary.sh source hummingbird/androidPatientApp`; patient contract, accessibility-matrix, and disclosure-matrix verifiers                                                                                                                                  | All passed; accessibility matrix remains draft and not pilot-ready |

### Explicit remaining boundary

This is a regression-harness synchronization repair only. It neither changes
patient UI behavior nor configures a patient API, creates or activates a
patient, enables a feature flag, changes care-pathway content, authorizes a
pilot, migrates data, deploys an application, or resolves the independent
identity, clinical, privacy, accessibility, governance, and release gates.

## 2026-07-25 — Patient default-off access parity increment

**Implementation commit:** `932de20e72fd362860ecfd82d5bef557ca49068a`

### Completed implementation

- Corrected a signed-out safety-parity defect: iOS already disabled enrollment
  and sign-in submission when the patient API was absent, but Android only
  displayed an off-state warning while still enabling a complete local form.
  Android now keeps both submission actions disabled unless live patient API
  access is explicitly configured.
- The Android off-state now uses patient-readable language that explains no
  care information will be requested until configuration is explicit, plus a
  stable `patient-api-off-state` tag for regression evidence. The application
  still permits local mode selection and typing, matching iOS, but does not
  offer a misleading network submission affordance.
- Added a production-path Android activity journey that completes both an
  invitation and sign-in form under the default-off build and proves their
  actions remain disabled. A separate Compose-only configured-state journey
  proves that the same complete invitation enables only when `networkEnabled`
  is true and passes the exact transient form to the supplied callback.

### Verification

| Boundary                             | Command / target                                                                                                                                                                                                                                                                        | Result                                                                                                                                                |
| ------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| Android default-off/configured forms | `./gradlew connectedDebugAndroidTest --rerun-tasks --console=plain -Pandroid.testInstrumentationRunnerArguments.class=net.acumenus.hummingbird.patient.PatientAuthenticationSmokeTest,net.acumenus.hummingbird.patient.PatientAuthenticationFormInstrumentedTest` on `hb(AVD)` / API 35 | 3 instrumentation tests, 0 failures/errors/skips; default-off invitation/sign-in actions were disabled and configured invitation submission was exact |
| Android full patient regression      | `./gradlew connectedDebugAndroidTest --rerun-tasks --console=plain` on `hb(AVD)` / API 35                                                                                                                                                                                               | 17 instrumentation tests, 0 failures/errors/skips                                                                                                     |
| iOS equivalent boundary              | `xcodebuild -project HummingbirdPatient.xcodeproj -scheme HummingbirdPatient -destination 'platform=iOS Simulator,name=iPhone 17 Pro' -only-testing:HummingbirdPatientUITests/PatientReferenceJourneyUITests/testDefaultBuildFailsClosedWithAReadableWelcomeAndNoPatientRequest test`   | 1 UI test, 0 failures; API-off state visible and sign-in disabled                                                                                     |

### Explicit remaining boundary

This only aligns a default-off native UI boundary. It does not configure a
patient API, establish identity proofing or wrong-patient prevention, issue or
activate an invitation, create a patient principal, enable a feature flag,
insert a production patient, authorize a pilot, migrate data, or deploy an
application. Enrollment remains subject to approved identity, privacy,
clinical, and release workflows.

## 2026-07-25 — Patient essential-action minimum-target increment

**Implementation commit:** `db495bc2677801b8f7a63973eacf25dccb5204d9`

### Completed implementation

- Added a shared iOS `patientMinimumInteractiveTarget` modifier. When applied
  inside a patient-action `Button` label, it establishes a 44-point minimum
  layout and content shape without changing clinical content or action
  semantics. The iOS policy now covers care-access retry/secure exit, generic
  state-card recovery, pathway-to-message and education-clarification actions,
  message correction/reply controls, and device revocation/confirmation.
- Added Android's `patientMinimumInteractiveTarget` policy with a 48dp minimum
  layout size. It is applied to care-access retry/secure exit, the pathway and
  education message-entry controls, preference submission, and device
  revocation/confirmation. Android's public Compose layout assertions directly
  measure the tagged controls rather than inferring their size from text or a
  visual screenshot.
- The iOS UI measurements directly cover unavailable-care retry/secure exit
  plus device revocation and destructive confirmation. During the first
  measurement pass, the test correctly exposed that a frame outside a SwiftUI
  `Button` was not the button accessibility element. The implementation was
  corrected at the label level before the final focused tests passed.
- Updated the draft acceptance matrix to show limited automated evidence for
  `patient_accessibility.target_size_and_precision_independence`. It remains
  draft and not pilot-ready; all human target-size, spacing, alternative-input,
  and low-dexterity validation remains explicitly open.

### Verification

| Boundary                            | Command / target                                                                                                                                                                                                                                                                              | Result                                                                                                                                                                                                                                                                                           |
| ----------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| iOS measured essential controls     | Focused `xcodebuild` UI tests for unavailable-care recovery and other-device revocation on iPhone 17 Pro / iOS 26.3                                                                                                                                                                           | 2 UI tests, 0 failures; measured 44-point retry, secure-exit, revocation, and destructive-confirmation controls                                                                                                                                                                                  |
| iOS broad reference journey         | `xcodebuild -only-testing:HummingbirdPatientUITests/PatientReferenceJourneyUITests/testReferenceJourneyExposesCarePathTeamAndSafeMessagingLanguage test` on iPhone 17 Pro / iOS 26.3                                                                                                          | 1 UI test, 0 failures after a fresh launch                                                                                                                                                                                                                                                       |
| iOS native suite stability          | `xcodebuild ... test`; then `xcodebuild ... -only-testing:HummingbirdPatientUITests test` on iPhone 17 Pro / iOS 26.3                                                                                                                                                                         | Initial combined invocation: 73/73 unit and 11/12 UI tests; the one reference journey stalled in XCUITest snapshot/animation monitoring after thread selection. It passed unchanged alone (1/1), and the full UI bundle then passed 12/12. No application source changed between the retry runs. |
| Android measured essential controls | `./gradlew connectedDebugAndroidTest --rerun-tasks --console=plain -Pandroid.testInstrumentationRunnerArguments.class=net.acumenus.hummingbird.patient.PatientPrimaryJourneyInstrumentedTest,net.acumenus.hummingbird.patient.PatientSessionManagementInstrumentedTest` on `hb(AVD)` / API 35 | 13 instrumentation tests, 0 failures/errors/skips; measured 48dp layout bounds                                                                                                                                                                                                                   |
| Android full native regression      | `./gradlew connectedDebugAndroidTest --rerun-tasks --console=plain` on `hb(AVD)` / API 35                                                                                                                                                                                                     | 16 instrumentation tests, 0 failures/errors/skips                                                                                                                                                                                                                                                |

### Explicit remaining boundary

This is a policy-and-regression increment, not a WCAG certification or a motor
accessibility sign-off. It does not prove all controls meet target-size/spacing
expectations on compact and large devices, or that one-handed use, Switch
Control, external keyboard, tremor, or low-dexterity needs are supported. A
qualified accessibility evaluator and patient advisor must perform the matrix's
named review before pilot enrollment. No patient was created or activated; no
release, production database, feature flag, or deployment changed.

## 2026-07-25 — Patient screen-reader heading landmark increment

### Completed implementation

- Added a shared iOS `patientAccessibilityHeading` modifier. It applies the native
  VoiceOver heading trait and a non-clinical stable identifier to a visible care
  landmark; the identifier supports regression evidence and is not exposed as care
  content.
- Applied the marker to all primary iOS patient surfaces (Today, My Path, Care
  Team, Messages), the no-active-care state, and device-management. It also marks
  the key Today’s plan, Learning and preparation, Your team, and Your conversations
  sections. The marker deliberately does not change patient content, navigation,
  authorization, scenario data, feature flags, or urgent-help wording.
- Marked the Android Hummingbird Patient application title as a Compose heading,
  matching the existing Android section-heading pattern. The Android API 35
  journey now asserts heading semantics for the application title, Learning and
  preparation, and Your conversations; the iOS journey asserts that the stable
  landmarks remain discoverable through every primary tab.
- Updated the draft acceptance matrix from no automated evidence to limited
  automated landmark evidence for the critical screen-reader criterion. The
  criterion remains subject to every listed human validation and the matrix remains
  `draft_requires_multidisciplinary_approval` / `not_pilot_ready`.

### Verification

| Boundary                    | Command / target                                                                                                                                                                                                                                                                                               | Result                                                                                                        |
| --------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| iOS heading landmarks       | `xcodebuild -project HummingbirdPatient.xcodeproj -scheme HummingbirdPatient -destination 'platform=iOS Simulator,name=iPhone 17 Pro' -only-testing:HummingbirdPatientUITests/PatientReferenceJourneyUITests/testPrimaryCareLandmarksRemainDiscoverableAcrossEveryPatientTab test` on iPhone 17 Pro / iOS 26.3 | 1 UI test, 0 failures; Today, My Path, Care Team, Messages, and each named key section were discoverable      |
| Android heading semantics   | `./gradlew connectedDebugAndroidTest --rerun-tasks --console=plain -Pandroid.testInstrumentationRunnerArguments.class=net.acumenus.hummingbird.patient.PatientPrimaryJourneyInstrumentedTest` on `hb(AVD)` / API 35                                                                                            | 7 instrumentation tests, 0 failures/errors/skips; Compose `Heading` semantics asserted on the named landmarks |
| Full native regression      | `xcodebuild ... test` on iPhone 17 Pro / iOS 26.3; `./gradlew connectedDebugAndroidTest --rerun-tasks --console=plain` on `hb(AVD)` / API 35                                                                                                                                                                   | iOS: 85 tests, 0 failures (73 unit plus 12 UI); Android: 16 instrumentation tests, 0 failures/errors/skips    |
| Acceptance-matrix integrity | `php scripts/verify-hummingbird-patient-accessibility-matrix.php`                                                                                                                                                                                                                                              | 12 criteria: 8 automated-evidence, 2 human-validation, 2 not-started; draft and not pilot-ready               |

### Explicit remaining boundary

This increment proves only that the named native semantic markers remain present
in the synthetic regression journeys. It does not prove VoiceOver rotor order,
TalkBack linear focus order/action menus, accessible names and hints throughout
the app, modal/error-state announcements, switch-control behavior, language
access, or patient comprehension. Those reviews require the named qualified
accessibility evaluator and patient advisor before pilot enrollment. No patient
was created or activated; no release, production database, feature flag, or
deployment changed.

## 2026-07-25 — Patient scenic-background parity and integrity guard

### Completed implementation

- Corrected the Android patient scene assignment to match the shared patient
  visual language: Airy Flight for welcome/loading, Calm Green for Today and
  account surfaces, Warm Motion for pathway and empty states, and Care
  Connection for Care Team, Messages, and access-verification error. The change
  separates loading, empty/unavailable, account, and error states instead of
  silently reusing one image for unrelated patient moments.
- Added independent iOS XCTest and Android JVM assertions for the exact scene
  mapping, so a future asset reassignment must deliberately update both native
  product expectations.
- Added `scripts/verify-hummingbird-patient-visual-assets.sh`, a cross-platform
  SHA-256 verifier for the four repository sources, four byte-identical iOS
  copies, and four optimized Android derivatives. The Hummingbird CI contract
  lane now runs it. The provenance record remains explicit that checksum proof
  is technical lineage, not copyright, licensing, attribution, or release
  approval.

### Verification

| Boundary                      | Command / target                                                                                                                                      | Result                                                                                  |
| ----------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| Asset lineage                 | `bash scripts/verify-hummingbird-patient-visual-assets.sh`                                                                                            | 12 source/iOS/Android SHA-256 pins matched the provenance ledger                        |
| Android unit / release        | `./gradlew testDebugUnitTest --tests net.acumenus.hummingbird.patient.ui.PatientVisualAssetPolicyTest`; `./gradlew testReleaseUnitTest --rerun-tasks` | focused policy suite passed; 104 release JVM tests, 0 failures/errors/skips             |
| Android emulator              | `./gradlew connectedDebugAndroidTest --rerun-tasks --console=plain` on `hb(AVD)` / API 35                                                             | 16 instrumented tests, 0 failures/errors/skips                                          |
| Android static analysis       | `./gradlew lintDebug --console=plain`                                                                                                                 | passed                                                                                  |
| iOS Simulator                 | `xcodebuild ... -only-testing:HummingbirdPatientTests/PatientAPIBoundaryTests test` on iPhone 17 Pro                                                  | 12 tests, 0 failures                                                                    |
| Native product boundaries     | both source-boundary scans plus `verify-hummingbird-patient-xcode-project.sh`                                                                         | Android and iOS source boundaries passed; generated Xcode project matched `project.yml` |
| Documentation / patch quality | target-file Prettier check and `git diff --check`                                                                                                     | passed                                                                                  |

### Remaining boundary

The backgrounds remain app-local and decorative; their release is still on hold
until Product Design and Legal/Compliance record source rights, any required
attribution/model releases, focal-point approvals, named release ownership, and
approval evidence. No patient feature flag, production patient, or deployment
changed in this increment.

## 2026-07-24 — Native staff Eddy no-store SSE increment

### Completed implementation

- The shared Laravel stream proxy now parses incremental upstream frames rather
  than forwarding the upstream terminal `complete` frame verbatim. It relays only
  token/error frames, persists the assistant result, emits a server-persisted clean
  `complete` reply, then emits the separately sanitized persisted proposal and
  `[DONE]`. Its response is `Cache-Control: no-store, no-cache, max-age=0` plus
  `Pragma: no-cache`.
- iOS (`URLSession.AsyncBytes`) and Android (`HttpURLConnection` Flow) consume the
  BFF stream through dedicated non-idempotent transports. They use no-store headers,
  a 45-second inactivity limit, cancellation when the visible scope disappears,
  no local transcript/cache, no automatic replay, and no generated idempotency key.
- Both chat surfaces update one pending assistant bubble with provisional token text,
  suppress a partial `<propose_action>` marker in defense in depth, and finalize only
  with the server-persisted clean reply. A stream cannot open, approve, or execute an
  action; approval remains a separate fetch-on-open, explicit-human flow.
- The OpenAPI summary, mobile reference, capability ledger, and governing checklist
  now distinguish completed native streaming from still-open history deletion,
  deployed role evidence, and autonomous action.

### Verification

| Boundary               | Command / target                                                             | Result                                                                                                        |
| ---------------------- | ---------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| Laravel mobile BFF     | `php artisan test tests/Feature/Mobile/Eddy/EddyMobileBffTest.php --compact` | 9 passed / 66 assertions                                                                                      |
| Laravel stream service | `php artisan test tests/Feature/Eddy/EddyStreamTest.php --compact`           | 3 passed / 13 assertions                                                                                      |
| Laravel formatting     | `./vendor/bin/pint` on the three changed PHP files                           | passed (Pint's bundled dependency emitted a non-source deprecation notice)                                    |
| Contract/ledger        | four Hummingbird verifier scripts                                            | 85 contract operations, 60 staff operations, 52 capabilities / 101 routes, and 25 patient operations verified |
| Android JVM            | `testDebugUnitTest --rerun-tasks`                                            | 122 tests, 0 failures/errors/skips                                                                            |
| Android emulator       | `connectedDebugAndroidTest --rerun-tasks` on `emulator-5554` / API 35        | 26 tests, 0 failures                                                                                          |
| iOS simulator          | `xcodebuild test` on iPhone 17 Pro / iOS 26.3.1                              | 110 tests, 0 failures/skips                                                                                   |

### Remaining boundary

This entry is a staff-only local change. It does not deploy, enable a patient flag,
activate the pending reference patient, or change patient release/governance status.

## 2026-07-24 — Server-governed staff communication action affordances

### Completed implementation

- The staff communication BFF now supplies a per-current-user `actions` object with
  `can_claim`, `can_reply`, and `can_close`. It derives each value from the current
  ability, role capability, effective pool membership, ownership, assignment, and
  open state; it does not trust a client-side interpretation of thread status.
- The web, iOS, and Android clients render direct actions only when the exact server
  boolean is true. A missing or malformed action object fails closed. The server still
  authorizes and locks every mutation independently, so the UI hint cannot grant an
  action.
- New native action-forbidden journeys prove that a thread which visually resembles an
  actionable escalation does not offer claim, reply, send, or close when the server
  denies them. The Hummingbird-background patient presentation remains visible and
  readable in the inspected iOS state.

### Verification

| Boundary                  | Command / target                                                                        | Result                                                                                             |
| ------------------------- | --------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| Laravel communication API | `php artisan test tests/Feature/Patient/StaffPatientCommunicationApiTest.php --compact` | 19 passed / 446 assertions                                                                         |
| Staff contract            | `php scripts/verify-hummingbird-staff-contract.php`                                     | 60 operations; all 9 communication operations match Laravel and the bounded schemas/offline policy |
| Web                       | focused Vitest communications suite and `npm run build`                                 | 14 passed; production build passed                                                                 |
| Android                   | focused JVM suite plus API 35 emulator `connectedDebugAndroidTest`                      | 12 communication UI tests, 0 failures                                                              |
| iOS                       | iPhone 17 Pro Simulator                                                                 | 93 unit tests and 4 communications UI tests, 0 failures; forbidden-action screenshot inspected     |

### Remaining boundary

This does not configure a pilot responsibility pool, establish an authoritative
service-ownership feed, add patient push, or prove deployed end-to-end delivery. It
is therefore staff safety evidence, not patient-pilot enablement evidence.

## 2026-07-25 — Factory-backed shared DTO fixture provenance

### Completed implementation

- All eight shared staff DTO fixtures now have exact source endpoint, regeneration
  test, and factory-seeded BFF provenance in
  `docs/hummingbird/api-contract/fixtures/fixture-provenance.v1.json`.
- `SharedDtoFixtureRegenerationTest` captures Altitude home, For You, Activity,
  staff patient operational context, and Transport queue through real Laravel BFF
  routes. The existing Flow capture test now accepts the same unified opt-in command.
  Native fixture assertions validate stable semantics and grammar rather than a
  previous run's opaque handles, database sequence values, or content hash.
- The default Flow/shared capture tests now compare all eight checked-in staff
  fixtures with deterministic BFF semantics and fail stale artifacts without
  writing. They canonicalize only grammar-checked database-sequence identifiers
  and catalog hashes; every user-visible, authorization, status, relationship, and
  envelope value remains exact. Only `HUMMINGBIRD_FIXTURE_DUMP=1` rewrites a
  fixture, so CI cannot silently replace a reviewed contract artifact.
- The new capture found that the staff patient-context BFF leaked source vocabulary
  (`"none"`) for `header.isolation_required`, despite the published native Boolean
  contract. `MobilePatientContextService` now normalizes false-like source values
  and preserves a true value for an actual requirement; a non-dump feature test
  proves both paths.

### Verification

| Boundary                  | Command / target                                            | Result                                                                                    |
| ------------------------- | ----------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| Laravel fixture freshness | Flow/shared capture tests plus `MobileSharedDtoFixtureTest` | 7 passed / 170 assertions; all 8 factory-seeded BFF artifacts match without writing       |
| Explicit Laravel capture  | unified fixture command, including Flow                     | 3 passed / 32 assertions; only explicit flag rewrites the 5 non-Flow and 3 Flow artifacts |
| Swift contract decoder    | `decode-shared-fixtures.swift`                              | decoded all 8 fixtures                                                                    |
| Kotlin contract decoder   | `SharedDtoFixtureDecodeTest`                                | 15 tests, 0 failures                                                                      |
| iOS Simulator             | iPhone 17 Pro / `HummingbirdTests`                          | 93 tests, 0 failures                                                                      |
| Android emulator          | API 35 `hb(AVD)` / communications UI suite                  | 12 tests, 0 failures, including 200% text and unavailable-action states                   |

### Remaining boundary

Fixture provenance eliminates a contract-ratification blind spot; it does not generate
the Swift/Kotlin models, provide patient-production source data, activate a patient flag,
or replace source/governance/pilot approval requirements.

## 2026-07-25 — Patient-care projection fixture ratification

### Completed implementation

- `PatientProjectionFixtureRegenerationTest` captures six DTO fixtures from the real
  patient BFF response boundary: Today, My Path, pathway events, discharge readiness,
  rounds summary, and Care Team. It freezes time and uses a deterministic request ID
  only in the `testing` runtime.
- The source is explicitly `SyntheticPatientProjectionProvisioner`, which is a
  testing-only projection provisioner. No fixture contains a real patient, remote
  production database data, clinical source payload, release approval, credential, or
  enabled feature flag. `fixtures/patient/fixture-provenance.v1.json` records this
  distinction so a synthetic contract fixture cannot be mistaken for release evidence.
- The writer preserves the raw API JSON before formatting. That retains an empty
  `links` object as `{}` rather than silently changing its type to `[]`, a defect a
  PHP array conversion would otherwise conceal.
- The default test path now compares all six checked-in fixtures with deterministic
  BFF responses and fails without writing if any artifact is stale. The explicit
  `HUMMINGBIRD_PATIENT_FIXTURE_DUMP=1` path is the only writer, so a CI run cannot
  silently alter the reviewed fixture set.
- PHP validates every envelope, no-store contract, opaque handles, freshness,
  forbidden raw identity/source keys, provenance, and both native decoder harnesses.
  The production apps decode the same six fixtures through their existing patient
  models, not a duplicate test-only DTO.

### Verification

| Boundary                   | Command / target                                                                                                                   | Result                                                                                             |
| -------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| Laravel fixture freshness  | `php artisan test tests/Feature/Patient/PatientProjectionFixtureRegenerationTest.php --compact`                                    | 1 passed / 72 assertions, no writes                                                                |
| Laravel fixture capture    | `HUMMINGBIRD_PATIENT_FIXTURE_DUMP=1 php artisan test tests/Feature/Patient/PatientProjectionFixtureRegenerationTest.php --compact` | 1 passed / 66 assertions, explicit writer only                                                     |
| Laravel fixture validation | combined freshness and `PatientSharedDtoFixtureTest` command                                                                       | 4 passed / 200 assertions                                                                          |
| Patient contract guards    | contract, baseline, accessibility, and disclosure verifier scripts                                                                 | 25 governed routes; 85 baseline operations; accessibility/disclosure remain draft, not pilot-ready |
| iOS model decode           | iPhone 17 Pro / iOS 26.3.1, selected `PatientAPIModelTests` XCTest                                                                 | 1 passed / 0 failures                                                                              |
| iOS UI journey             | iPhone 17 Pro / iOS 26.3.1, `PatientReferenceJourneyUITests`                                                                       | passed / 0 failures                                                                                |
| Android model decode       | `PatientProjectionFixtureDecodeTest` JVM suite                                                                                     | 6 passed / 0 failures/errors/skips                                                                 |
| Android UI journey         | API 35 `hb(AVD)` / `PatientPrimaryJourneyInstrumentedTest`                                                                         | 7 passed / 0 failures/errors/skips                                                                 |

### Remaining boundary

This establishes local, deterministic contract evidence and native emulator/simulator
evidence. It does not generate the native DTOs, replace the selected approved clinical
source adapter, create a clinical release, alter the existing remote reference encounter,
enable a patient flag, or satisfy pilot governance and independent-review gates.

## 2026-07-25 — Patient forward-compatibility fixture ratification

### Completed implementation

- `patient-pathway-events-forward-compatible.json` is a deterministic **test-only**
  derivation of the testing-runtime pathway-events BFF capture. Its provenance names it
  as derived, rather than presenting it as an additional source capture or release
  fixture.
- The fixture proves explicit `null` handling, an unknown `future_navigation` category,
  additive nested fields in `data`, `meta`, and `links`, exact integer preservation for
  `9007199254740993`, a precision-preserving decimal string, and a 256-item notice list.
- The iOS decoder maps an unknown pathway event category to generic `Care update`; the
  Android presentation vocabulary maps it to generic `Status being confirmed`. Neither
  app renders the unrecognized server value to a patient.

### Verification

| Boundary                            | Command / target                                                                                          | Result                             |
| ----------------------------------- | --------------------------------------------------------------------------------------------------------- | ---------------------------------- |
| Laravel patient projection boundary | `PatientProjectionFixtureRegenerationTest`, `PatientSharedDtoFixtureTest`, and `PatientProjectionApiTest` | 18 passed / 779 assertions         |
| iOS native decoder                  | iPhone 17 Pro / iOS 26.3.1, `testForwardCompatiblePathwayEventsFixtureFallsBackToPatientSafeVocabulary`   | 1 passed / 0 failures              |
| Android native decoder              | `PatientProjectionFixtureDecodeTest` JVM suite                                                            | 7 passed / 0 failures/errors/skips |
| Android patient journey             | API 35 `hb` emulator / `PatientPrimaryJourneyInstrumentedTest`                                            | 7 passed / 0 failures/errors/skips |

### Remaining boundary

This is deterministic compatibility evidence for manually maintained client models. It
does not create generated DTOs, prove behavior against an approved source/release,
change any patient feature flag, or satisfy clinical, accessibility, privacy, or pilot
acceptance gates.

## 2026-07-25 — Staff Flow high-volume synchronization ratification

### Completed implementation

- The server already serves the high-volume staff Flow Window through a constrained
  `since` cursor: only append-only events and census snapshots are narrowed; current
  projections, geometry, bed status, and duties are renewed in full. Malformed or
  out-of-window cursors fail with `422 invalid_since` instead of silently becoming a
  potentially misleading full refresh.
- Static, non-live Flow geometry (`/flow/floors` and `/flow/spaces3d`) has a strong
  `ETag`/`If-None-Match` 304 path. The iOS and Android runtime paths use the Flow
  Window cursor; iOS now has direct native coverage for request construction, cursor
  echo decoding, duplicate-safe historical merge, and full current-state replacement.
- This control is intentionally confined to the staff Flow BFF. It does not change the
  private no-store treatment of patient projections or authorize a patient offline cache.

### Verification

| Boundary                      | Command / target                                                     | Result                              |
| ----------------------------- | -------------------------------------------------------------------- | ----------------------------------- |
| Laravel Flow contract         | `php artisan test tests/Feature/Mobile/FlowWindowTest.php --compact` | 22 passed / 865 assertions          |
| iOS native delta contract     | iPhone 17 Pro / iOS 26.3.1, `FlowWindowDeltaTests`                   | 3 passed / 0 failures               |
| Android native delta contract | `FlowDeltaTest` Debug and Release; `FlowCacheLogicTest` Debug        | 8/8 + 8/8 + 5/5 passed / 0 failures |
| Android staff regression      | API 35 `hb` emulator / full Debug instrumentation                    | 27 passed / 0 failures/errors/skips |

### Remaining boundary

This ratifies the bounded staff high-volume-read strategy. It does not establish
generated clients, make every mobile read cacheable, or apply caching to patient data.

## 2026-07-25 — Reference-patient dry-run made no-write

### Completed implementation

- `MobilePatientContextReferenceStore` now distinguishes deterministic opaque-handle
  derivation from issuance. Only issuance creates or refreshes the resolver row.
- The synthetic-reference provisioner uses derivation for preview and returns the
  explicit `patient_context_ref_issued=false` marker. An explicit commit retains the
  existing issuance behavior.
- The reference-provisioner regression now proves that the default command creates
  neither a `prod.encounters` row nor an `ops.patient_operational_context_cache` row.

### Verification

| Boundary                  | Command / target                                                                                 | Result                                                                                             |
| ------------------------- | ------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------- |
| Local regression          | `php artisan test tests/Feature/Mobile/HummingbirdReferencePatientProvisionerTest.php --compact` | 2 passed / 14 assertions                                                                           |
| Canonical database safety | `php artisan zephyrus:database-safety --json`                                                    | local runtime transaction and default are read-only                                                |
| Remote reconciliation     | `php artisan hummingbird:seed-reference-patient --unit-id=85 --json`                             | dry run found the existing active command-owned encounter `10040`; no context reference was issued |

### Remaining boundary

This verifies and preserves the synthetic reference exercise. It does not activate the
pending identity/grant, issue or reveal enrollment material, approve a clinical
projection, release any draft, enable a patient flag, or authorize a pilot.

## 2026-07-25 — Pre-round question and clarification journey ratification

### Completed implementation

- The patient `rounds_question` topic is an approved-policy, default-off composition
  path with a fixed **nonurgent** class. Its iOS and Android composers now state that
  a care team may review the question before a care conversation, that review does not
  promise discussion in a particular round, and that immediate needs use the existing
  urgent-help route.
- The server bridge remains explicit and fail-closed: eligible patient content appears
  in the matching authorized staff rounds workspace only after a capability-bearing
  staff member promotes it. Promotion produces a `rounds.questions` workflow record
  with bridge provenance and audit/event facts, not an order, care-plan mutation, or
  patient access to the staff workspace. Withdrawal, correction, repeat promotion,
  grant revocation, and bridge disablement preserve the same boundary.
- A staff resolution creates one deliberately generic patient-visible outcome without
  copying staff-rounds content into the patient message ledger. The post-round summary
  read surface remains separately governed and patient-readable.
- The education “teach-back” surface is intentionally a request for clarification,
  not an attestation. The API, durable association, iOS composer, and Android dialog
  contain no consent, comprehension, completion, clinical assessment, order, or
  care-plan field; unreleased education cannot create a message or fact.

### Verification

| Boundary                                        | Command / target                                                                                                                                                                             | Result                                                                                                                            |
| ----------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| Laravel rounds + messaging + patient projection | `php artisan test tests/Feature/Rounds/PatientRoundQuestionPromotionTest.php tests/Feature/Patient/PatientMessagingApiTest.php tests/Feature/Patient/PatientProjectionApiTest.php --compact` | 49 passed / 1,145 assertions                                                                                                      |
| Laravel clarification boundary                  | `php artisan test tests/Feature/Patient/PatientEducationClarificationApiTest.php --compact`                                                                                                  | 3 passed / 35 assertions                                                                                                          |
| iOS native                                      | iPhone 17 Pro / iOS 26.3.1: `PatientRoundsQuestionTopicTests` and `PatientReferenceJourneyUITests/testReferenceJourneyExposesCarePathTeamAndSafeMessagingLanguage`                           | 3 unit tests and 1 UI journey passed / 0 failures                                                                                 |
| Android native                                  | API 35 `hb` emulator / `connectedDebugAndroidTest --rerun-tasks`                                                                                                                             | 15 tests passed / 0 failures, errors, or skips; the journey selects the rounds topic and checks the nonurgent/no-guarantee notice |

### Remaining boundary

The staff lifecycle still lacks a governed patient-visible acknowledge, defer, and
route state machine; only explicit promotion and terminal generic resolution are
ratified here. The released rounds-summary reader does not establish an approved
production source, clinical-release owner, pilot responsibility pool, feature enablement,
or a patient production deployment. All patient flags remain off.

## 2026-07-25 — Accountable pre-round question lifecycle ratification

### Completed implementation

- The staff lifecycle now uses one accountable workflow rather than a parallel rounds
  messaging implementation. In Patient Communications, an effective responsibility-pool
  member claims a question to acknowledge it, sends an encrypted patient-visible reply to
  answer it, or reroutes it only to an eligible governed destination. Those existing
  mutations already write the capability-gated staff action, routing, and delivery-receipt
  ledgers in one transaction.
- Patient message serialization now exposes `state_updated_at`: the timestamp of the
  authoritative latest patient-visible delivery receipt, falling back to the message's
  sent time where no receipt exists. iOS and Android decode it defensively and display it
  beside the patient-facing delivery state. No routing identifier, staff identity,
  internal reason, or staff-only note crosses the patient boundary.
- A staff member who can resolve the exact promoted question may now choose **Defer for
  later review** from the authorized Virtual Rounds workspace. The question remains open.
  A one-to-one, append-only, content-free
  `patient_communications.round_question_promotion_deferrals` fact links only the
  promotion, actor, policy/digests, timestamp, and encrypted generic patient status. It
  rejects a distinct second deferral, exact-replays the original idempotency key, and
  suppresses disclosure if the independent bridge/patient-policy checks fail closed.
- The staff workspace deliberately links to the existing Patient Communications queue for
  acknowledge, reply, and reroute. It does not create another staff-message API or make a
  patient question an order, care-plan update, staff response, routing rationale, or
  promise that a particular round will occur.

### Verification

| Boundary                                      | Command / target                                                                                                                                                                                     | Result                                                                                        |
| --------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| Laravel lifecycle, messaging, and staff queue | `php artisan test tests/Feature/Rounds/PatientRoundQuestionPromotionTest.php tests/Feature/Patient/StaffPatientCommunicationApiTest.php tests/Feature/Patient/PatientMessagingApiTest.php --compact` | 57 passed / 1,074 assertions                                                                  |
| Laravel regression after formatting           | `php artisan test tests/Feature/Rounds/PatientRoundQuestionPromotionTest.php tests/Feature/Patient/PatientMessagingApiTest.php --compact`                                                            | 37 passed / 620 assertions                                                                    |
| Backend/static formatting                     | Pint on touched PHP; targeted Prettier; `git diff --check`                                                                                                                                           | passed                                                                                        |
| iOS patient API + simulator                   | iPhone 17 Pro / iOS 26.3.1, `PatientAPIClientTests`                                                                                                                                                  | 13 passed / 0 failures                                                                        |
| iOS reference journey                         | iPhone 17 Pro / iOS 26.3.1, `PatientReferenceJourneyUITests/testReferenceJourneyExposesCarePathTeamAndSafeMessagingLanguage`                                                                         | passed                                                                                        |
| Android decoder                               | Debug JVM, `PatientEnvelopeDecoderTest`                                                                                                                                                              | passed                                                                                        |
| Android patient journey                       | API 35 `hb` emulator, `connectedDebugAndroidTest --rerun-tasks`                                                                                                                                      | 15 passed / 0 failures, errors, or skips; includes the acknowledged-state timestamp assertion |

### Remaining boundary

This ratifies the governed, default-off application path only. It does not approve a
patient pilot, enable any patient feature, release a clinical rounds summary, make a
clinical statement, expose staff-only reasoning, create a production patient, or deploy
the migration/application change. The repository-wide TypeScript compiler continues to
stop on pre-existing `Hooks`/`hooks` and `Components`/`components` duplicate-casing
paths; targeted changed-file formatting passed.

## 2026-07-25 — Governed Today-projection contract parity ratification

### Completed implementation

- Reconciled the actual patient BFF, release guard, and native consumers for the
  existing optional Today fields. `PatientScheduleItem.category` was documented in
  the patient OpenAPI but could not cross the backend content guard; the guard now
  accepts only the versioned patient-language category registry (`test`, `procedure`,
  `transport`, or `other`) and rejects unrecognized/internal values before release.
- The deterministic test-only reference BFF now emits a category, released care
  location, bounded discharge outlook, and patient-visible question. No production
  source, patient content, clinical approval, or feature flag was introduced.
- The fixture-regeneration gate now compares the complete JSON value rather than
  whitespace. This preserves deterministic BFF-content drift detection while allowing
  the checked-in patient-contract fixture to use the repository's formatter.
- Android now decodes the full existing Today shape instead of silently dropping
  `care_location`, `discharge_outlook`, and `questions`. It renders a released care
  location, an uncertainty-labelled discharge-planning card, safe schedule category
  wording, and combines released questions with released next steps. iOS decodes the
  typed schedule category and displays its approved generic label in the released plan
  detail; its existing location and discharge-outlook behavior is now fixture-covered.
- Android top-level destination changes reset the scroll position to the selected
  surface header. The API 35 emulator exposed this after navigating deep into Today
  and then opening My Path; without the reset, an information-updated notice at the
  Path header was present but off-screen. This prevents a patient from arriving in a
  different care surface at an unrelated deep offset.

### Verification

| Boundary                                                                                     | Command / target                                                                                                                                                                                                                                                                                                           | Result                                                                                                                               |
| -------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| Laravel release guard, deterministic BFF, fixtures, DTO evidence, and patient projection API | `php artisan test tests/Unit/Patient/PatientProjectionStateVocabularyTest.php tests/Feature/Patient/PatientProjectionKernelTest.php tests/Feature/Patient/PatientProjectionFixtureRegenerationTest.php tests/Feature/Patient/PatientSharedDtoFixtureTest.php tests/Feature/Patient/PatientProjectionApiTest.php --compact` | 29 passed / 879 assertions                                                                                                           |
| iOS contract and view model                                                                  | iPhone 17 Pro / iOS 26.3.1: `PatientAPIModelTests`, `PatientStateVocabularyTests`, and `PatientAppViewModelTests`                                                                                                                                                                                                          | 38 passed / 0 failures                                                                                                               |
| Android contract and view model                                                              | Debug JVM: `PatientEnvelopeDecoderTest`, `PatientProjectionFixtureDecodeTest`, `PatientSessionCoordinatorTest`, and `PatientStateVocabularyTest`                                                                                                                                                                           | passed                                                                                                                               |
| Android patient journey                                                                      | API 35 `hb` emulator: `connectedDebugAndroidTest --rerun-tasks`                                                                                                                                                                                                                                                            | 15 passed / 0 failures, errors, or skips; includes the newly visible Today location/discharge content and cross-surface scroll reset |

### Remaining boundary

This ratifies contract and UI parity for information that has already crossed the
separate governed patient projection release boundary. It does not create a patient
record, source adapter, clinical release, exact clinical schedule/result, message
routing change, feature enablement, pilot authorization, migration, or deployment.

## 2026-07-25 — Patient schedule-status vocabulary v2 contract ratification

### Completed implementation

- Expanded the coordinated patient-safe schedule taxonomy from its initial seven
  generic states to include `scheduled`, `waiting`, `transport_requested`,
  `result_pending`, and `result_released`. The authoritative default-English labels
  are versioned as `patient-state-vocabulary.v2-draft`; the backend guard, envelope
  metadata, OpenAPI, deterministic fixtures, iOS, and Android use the same version.
- Both native apps deliberately withhold a projection that declares an incompatible
  state-vocabulary version. Neither client title-cases an internal code. On iOS, the
  approved schedule label is shown with the existing timing-certainty treatment; on
  Android, the mapped label reaches the existing patient-plan card.
- The deterministic, test-only Today projection and both debug reference scenarios
  contain **A test update** in the `result_pending` state. It renders only **Result
  not available yet** with generic next-step language. It contains no result value,
  interpretation, source payload, or clinical release assertion.

### Verification

| Boundary                                                      | Command / target                                                                                                                                 | Result                                                                              |
| ------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------- |
| Laravel registry, guard, metadata, fixtures, and DTO evidence | focused Patient state/fixture/projection suite                                                                                                   | 21 passed / 347 assertions                                                          |
| Android native contract and mapping                           | Debug JVM: `PatientStateVocabularyTest`, `PatientEnvelopeDecoderTest`, `PatientProjectionFixtureDecodeTest`, and `PatientSessionCoordinatorTest` | passed / 0 failures                                                                 |
| iOS native contract and mapping                               | iPhone 17 Pro / iOS 26.3.1: `PatientAPIModelTests`, `PatientStateVocabularyTests`, and `PatientAppViewModelTests`                                | 38 passed / 0 failures                                                              |
| iOS rendered journey                                          | iPhone 17 Pro / iOS 26.3.1: `PatientReferenceJourneyUITests/testReferenceJourneyExposesCarePathTeamAndSafeMessagingLanguage`                     | 1 passed / 0 failures; verifies the generic pending-result label                    |
| Android rendered journey                                      | API 35 `hb` emulator / `connectedDebugAndroidTest --rerun-tasks`                                                                                 | 15 passed / 0 failures, errors, or skips; verifies the generic pending-result label |

### Remaining boundary

This ratifies a patient-language status taxonomy and its client compatibility gate.
It does not approve a clinical source, create a result, determine an organization’s
result-release policy, activate a patient feature, authorize a pilot, run a migration,
or deploy an application change.

## 2026-07-25 — Today context projection-reuse ratification

### Completed implementation

- Audited the existing patient contracts before adding a field. Both apps already fetch
  the independently authorized Today, Pathway, and Care Team projections and preserve
  their released patient-safe content. No parallel Today schema, route, source call,
  or duplicate projection was needed.
- Today now renders the released current care stage, care-team summary/member names and
  roles, and pathway goals from those existing projections. Cards retain the originating
  Pathway or Care Team provenance and are omitted if that projection is unavailable;
  they never infer a stage, staff assignment, goal, or contact route.
- The Android reference journey now uses the scroll container for a long, composable
  care view rather than relying on a child node’s incidental position. This surfaced
  during the emulator run after Today gained the additional patient-readable context.

### Verification

| Boundary                 | Command / target                                                                                                             | Result                                                                                 |
| ------------------------ | ---------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| Android native mapping   | Debug JVM: `PatientSessionCoordinatorTest` and `PatientStateVocabularyTest`                                                  | passed / 0 failures                                                                    |
| iOS native mapping       | iPhone 17 Pro / iOS 26.3.1: `PatientAppViewModelTests` and `PatientAPIModelTests`                                            | 34 passed / 0 failures                                                                 |
| iOS rendered journey     | iPhone 17 Pro / iOS 26.3.1: `PatientReferenceJourneyUITests/testReferenceJourneyExposesCarePathTeamAndSafeMessagingLanguage` | 1 passed / 0 failures; verifies current-stage, team, and goal cards                    |
| Android rendered journey | API 35 `hb` emulator / `connectedDebugAndroidTest --rerun-tasks`                                                             | 15 passed / 0 failures, errors, or skips; verifies current-stage, team, and goal cards |

### Remaining boundary

This ratifies a no-new-data composition of content that has already crossed its own
released patient-projection boundary. It does not create a patient record, change a
grant or scope, add a production source/reconciliation worker, release clinical content,
enable a patient feature, authorize a pilot, migrate data, or deploy an application.

## 2026-07-25 — Delayed schedule presentation safety ratification

### Completed implementation

- A released schedule item with the `delayed` code no longer renders its free-text
  `time_window`, detail, preparation, or timing-confidence wording in either native
  patient app. Both apps substitute the fixed patient-safe text **Timing is being
  updated** and **The timing for this step has changed. Your care team will explain
  what happens next.** This prevents an unverified ETA or operational explanation from
  being restated as patient guidance.
- iOS certainty calculation now gives `delayed`, `waiting`, and `result_pending`
  precedence over every upstream timing-confidence value. Those states render as **Being
  clarified** rather than **Expected**; `completed` and `result_released` still retain
  their confirmed treatment.
- Deterministic native inputs use a source-specific time, detail, and preparation. The
  mapper assertions require the fixed safe presentation instead, and the debug-only
  reference scenarios render the same generic delayed card for both emulators.

### Verification

| Boundary                 | Command / target                                                                                                             | Result                                                                     |
| ------------------------ | ---------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------- |
| Android native mapper    | Debug JVM: `PatientSessionCoordinatorTest`                                                                                   | passed / 0 failures                                                        |
| iOS native mapper        | iPhone 17 Pro / iOS 26.3.1: `PatientAppViewModelTests`                                                                       | 24 passed / 0 failures                                                     |
| iOS rendered journey     | iPhone 17 Pro / iOS 26.3.1: `PatientReferenceJourneyUITests/testReferenceJourneyExposesCarePathTeamAndSafeMessagingLanguage` | 1 passed / 0 failures; verifies generic delayed wording                    |
| Android rendered journey | API 35 `hb` emulator / `connectedDebugAndroidTest --rerun-tasks`                                                             | 15 passed / 0 failures, errors, or skips; verifies generic delayed wording |

### Remaining boundary

This ratifies a defensive client rendering rule. It does not review or approve a source
delay reason, determine an ETA, inspect staffing/capacity, approve clinical wording,
release a patient projection, enable a patient feature, authorize a pilot, migrate data,
or deploy an application.

## 2026-07-25 — Today rounds-summary projection-reuse ratification

### Completed implementation

- Audited the existing patient-realm `GET /encounters/{encounterUuid}/rounds/summary`
  projection before changing the native views. It already requires the separate
  `rounds_summary` patient feature gate and the existing `pathway:read` encounter
  grant; both clients already fetch it independently, preserve patient-facing
  provenance, and treat an unavailable release as absent.
- Today now composes only that already released content: the plain-language headline,
  summary, approximate discussion window, released topic summaries, provenance, and
  released next steps/questions. It appears after the existing current-stage,
  care-team, and care-goal context, allowing a patient to orient to what the team
  discussed without navigating away from Today.
- No route, schema, API operation, source adapter, staff-workspace access, content
  generation, grant/scope, feature-flag default, or patient record changed. A missing,
  retracted, or feature-disabled rounds release produces no fallback card; neither app
  invents a conversation summary, topic, timing, or next step.

### Verification

| Boundary                 | Command / target                                                                                                                                            | Result                                                                                                   |
| ------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| Android native mapping   | Debug JVM: `PatientSessionCoordinatorTest`                                                                                                                  | passed / 0 failures                                                                                      |
| iOS native + UI journey  | iPhone 17 Pro / iOS 26.3.1: `PatientAppViewModelTests` and `PatientReferenceJourneyUITests/testReferenceJourneyExposesCarePathTeamAndSafeMessagingLanguage` | 25 passed / 0 failures; verifies the Today rounds-summary and conversation-next-steps cards              |
| Android rendered journey | API 35 `hb` emulator / `connectedDebugAndroidTest --rerun-tasks`                                                                                            | 15 passed / 0 failures, errors, or skips; verifies the Today rounds-summary, topics, and next-step cards |

### Remaining boundary

This ratifies reuse of a separate, already governed patient projection in native
presentation only. It does not approve an upstream rounds source, author/review a
clinical summary, release clinical content, expose the staff Virtual Rounds workspace,
create a production patient, enable the default-off feature, authorize a pilot, migrate
data, deploy an application, or close the related journey checklist item.

## 2026-07-25 — Patient messaging topic-and-response expectation ratification

### Completed implementation

- Confirmed that the patient API exposes only patient-safe topic labels, descriptions,
  and the required policy response window. The server keeps the accountability-pool
  key/digest internal, resolves routing only on the locked write path, and rejects a
  fresh write when the active encounter/grant/scopes, approved policy, urgent guidance,
  response window, encryption key, staffed pool, or handoff-readiness boundary is not
  satisfied.
- Aligned the native patient language around **Typical response**. iOS now labels the
  configured response window in each conversation row, thread header, and composer;
  Android uses the same wording in its topic chooser and thread header. This makes the
  non-promissory expectation recognizable without converting it into a delivery or
  clinical guarantee.
- The immediate-help presentation remains above messaging and states that messages are
  not emergency monitoring or live chat. The patient selects a topic, never an
  individual clinician or internal responsibility pool. No route, policy shape,
  content, feature default, or routing authority changed.

### Verification

| Boundary                   | Command / target                                                                                                             | Result                                                                                              |
| -------------------------- | ---------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| Patient BFF policy/routing | `php artisan test tests/Feature/Patient/PatientMessagingApiTest.php --compact`                                               | 28 passed / 496 assertions; includes topic disclosure and internal routing-metadata omission        |
| iOS rendered journey       | iPhone 17 Pro / iOS 26.3.1: `PatientReferenceJourneyUITests/testReferenceJourneyExposesCarePathTeamAndSafeMessagingLanguage` | 1 passed / 0 failures; verifies accessible typical-response meaning in a conversation header        |
| Android rendered journey   | API 35 `hb` emulator / `connectedDebugAndroidTest --rerun-tasks`                                                             | 15 passed / 0 failures, errors, or skips; verifies the selected-topic typical-response presentation |

### Remaining boundary

This ratifies the governed, default-off technical pathway only. It does not establish a
clinical response-time SLA, enable patient messaging, staff a pool, authorize a pilot,
expose routing metadata, create a production patient, migrate data, or deploy an
application.

## 2026-07-25 — Patient discharge equipment-and-transport preparation ratification

### Completed implementation

- Extended the existing patient `discharge_readiness` projection with two optional,
  additive patient-language lists: `equipment` and `transport`. The content guard accepts
  only strings for those fields and retains the existing fail-closed top-level allowlist and
  forbidden-key scan. It additionally rejects explicit ETA/time, dispatch, queue/capacity,
  driver/vehicle, route, and assigned-transport wording from those lists. It does not
  introduce a new route, source adapter, policy, feature flag, or release state.
- Updated the OpenAPI contract, disclosure matrix, deterministic testing-only projection
  provisioner, and shared JSON fixture so the BFF contract stays aligned with both native
  decoders. The reference language says that equipment needs and the getting-home plan are
  being checked or planned; it does not promise an item, a ride, an exact departure time,
  a route, or a staff assignment.
- Both native My Path experiences render a section only when its separately released list
  is nonempty: **Equipment and supplies for home** and **Getting home**. The final
  discharge reminder explicitly leaves confirmation to the care team. These additions do
  not infer missing content and do not expose operational transport state.

### Verification

| Boundary                             | Command / target                                                                                                                                                      | Result                                                                                                       |
| ------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| Projection safety and BFF contract   | `PatientProjectionKernelTest` + `PatientProjectionApiTest`; then `PatientProjectionFixtureRegenerationTest` + `PatientSharedDtoFixtureTest`                           | 21 passed / 630 assertions, then 5 passed / 243 assertions; the controlled fixture dump also passed (1 / 66) |
| iOS model and rendered journey       | iPhone 17 Pro / iOS 26.3.1: fixture decoder + bootstrap-model tests; `PatientReferenceJourneyUITests/testReferenceJourneyExposesCarePathTeamAndSafeMessagingLanguage` | 2 model tests, then 1 rendered journey passed; no failures or skips                                          |
| Android decoder and rendered journey | API 35 `hb` emulator: focused decoder/fixture/coordinator JVM tests and `connectedDebugAndroidTest --rerun-tasks`                                                     | 43 focused JVM tests and all 15 instrumentation tests passed; 0 failures, errors, or skips                   |

### Remaining boundary

This is a default-off, additive contract and presentation increment. It does not approve
an equipment or transport source, make a clinical or operational arrangement, release
real patient content, create or activate a production patient, authorize a pilot, migrate
data, or deploy an application. Production disclosure still requires clinical review,
source lineage, an approved release workflow, patient-advisor validation, and separate
activation authority.

## 2026-07-25 — Patient preference-and-goal Messages handoff ratification

### Completed implementation

- Added one explicit **Open Messages** control to the existing **Share what matters to
  you** guidance on both native My Path views. It is a patient-controlled navigation
  handoff only: it selects the existing Messages destination and does not preselect a
  topic, identify a clinician or responsibility pool, create a draft, transmit a message,
  or alter a care plan, order, consent, assessment, or goal.
- Kept the established patient-language boundary in place directly above the handoff:
  preferences and personal goals are discussed through the separately released,
  non-urgent Messages topics and require team review. The Messages surface itself retains
  its immediate-help guidance and feature/policy gating.
- Corrected the iOS accessibility structure after simulator evidence showed that the
  card-level test identifier had hidden the nested control. The card now explicitly
  contains child accessibility elements; the control has an accessible patient-safe hint
  that opening Messages creates no message. Android supplies a labelled, test-tagged
  control in the same calm pathway card.
- Corrected a stale Android synthetic-reference assertion so it recognizes intentionally
  labelled synthetic provenance as distinct from a live `Source:` label. This preserves
  the fixture's explicit nonclinical/non-live boundary rather than making it appear to
  claim a production source.

### Verification

| Boundary                     | Command / target                                                                                                             | Result                                                                                                          |
| ---------------------------- | ---------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| iOS rendered handoff         | iPhone 17 Pro / iOS 26.3.1: `PatientReferenceJourneyUITests/testReferenceJourneyExposesCarePathTeamAndSafeMessagingLanguage` | 1 passed / 0 failures or skips; verifies My Path → Open Messages → guarded Messages state → My Path             |
| Android Debug JVM            | `:app:testDebugUnitTest --tests 'net.acumenus.hummingbird.patient.*'`                                                        | 100 passed / 0 failures after the synthetic-provenance assertion correction                                     |
| Android rendered handoff     | API 35 `hb` emulator / `connectedDebugAndroidTest --rerun-tasks`                                                             | 15 passed / 0 failures, errors, or skips; verifies My Path → Open Messages → guarded Messages context → My Path |
| Documentation and diff gates | targeted Prettier check and `git diff --check`                                                                               | passed                                                                                                          |

### Remaining boundary

This is a default-off navigation and accessibility increment. It does not enable patient
messaging, approve a policy, staff a responsibility pool, expose staff routing metadata,
authorize a pilot, create a production patient, migrate data, release clinical content,
or deploy an application. Family/proxy delegation, staff-review workflow validation,
production policy/source approval, patient-advisor validation, and activation authority
remain separate requirements.

## 2026-07-25 — Education clarification conversation handoff ratification

### Completed implementation

- Made the already governed **Ask for an explanation** flow complete from a patient’s
  perspective. When the source-bound request succeeds, both native apps open the existing
  guarded Messages surface, where the ordinary returned conversation and its
  patient-visible status can be followed. No new route, payload field, topic, recipient,
  service-pool disclosure, content source, or projection was introduced.
- Preserved patient agency on Android: if a patient chooses another top-level destination
  while the secure request is in flight, the completion does not force them into Messages.
  The confirmed request remains in the secure conversation list for them to open later.
- Retained the strict safety boundary. The request is only for an item in the current
  released education projection and carries only the existing question, client UUID,
  immediate-help guidance version, and idempotency key. It records no understanding,
  completion, consent, clinical assessment, order, or care-plan mutation.

### Verification

| Boundary                | Command / target                                                                                                             | Result                                                                                                                       |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| iOS endpoint boundary   | iPhone 17 Pro / iOS 26.3.1: `PatientAPIClientTests/testEducationClarificationUsesOnlyReleasedItemPathAndContentOnlyBody`     | 1 passed / 0 failures or skips; validates the released-item path and absence of completion/consent/assessment payload fields |
| iOS rendered regression | iPhone 17 Pro / iOS 26.3.1: `PatientReferenceJourneyUITests/testReferenceJourneyExposesCarePathTeamAndSafeMessagingLanguage` | 1 passed / 0 failures or skips                                                                                               |
| Android state behavior  | focused `PatientAppViewModelTest` + `PatientSessionCoordinatorTest`                                                          | passed; covers success-to-Messages and preservation of an intervening patient navigation choice                              |
| Android rendered path   | API 35 `hb` emulator / `connectedDebugAndroidTest --rerun-tasks`                                                             | 15 passed / 0 failures, errors, or skips                                                                                     |

### Remaining boundary

This improves discoverability of the existing default-off clarification conversation. It
does not turn a request into a teach-back assessment, establish comprehension, enable
messaging, approve education content, enable a source, authorize a pilot, create a
production patient, migrate data, or deploy an application. Clinical teach-back review,
interpreter/language accommodation, patient-advisor validation, and activation authority
remain separate requirements.

## 2026-07-25 — Patient messaging-status language parity ratification

### Completed implementation

- Reconciled the Android patient messaging display with the existing patient BFF and iOS
  contract. Android now gives the same patient-safe meaning to every supported thread
  ownership state: waiting for the team, with the team, seen by the team, responded,
  finding the right team member, receiving added attention, and conversation closed.
  Corresponding message delivery receipts use the same bounded language.
- Removed the synthetic Android-only `team_acknowledged` wire value. The Debug-only
  reference fixture now uses the contract's `acknowledged` value, so emulator evidence
  exercises a real BFF state rather than normalizing an undocumented test-only variant.
- Kept the least-disclosure boundary intact. These labels never reveal a named clinician,
  responsibility pool, staff availability, routing reason, or escalation mechanism. An
  unexpected Android wire string renders **Status being confirmed** instead of a guessed
  operational status; a closed thread remains closed even if an inconsistent ownership
  string arrives.

### Verification

| Boundary                         | Command / target                                                                                                                | Result                                                                                                      |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| Android presentation mapping     | `:app:testDebugUnitTest --tests 'net.acumenus.hummingbird.patient.ui.PatientMessagingPresentationTest'`                         | 3 passed / 0 failures                                                                                       |
| Android rendered reference state | API 35 `hb` emulator: `PatientPrimaryJourneyInstrumentedTest#syntheticMessagingKeepsImmediateHelpAboveComposeAndPendingThreads` | 1 passed / 0 failures; renders **Seen by your care team** from the normalized `acknowledged` contract state |
| iOS regression                   | iPhone 17 Pro / iOS 26.3.1: `PatientReferenceJourneyUITests/testReferenceJourneyExposesCarePathTeamAndSafeMessagingLanguage`    | 1 passed / 0 failures or skips                                                                              |

### Remaining boundary

This closes the patient-language presentation row only. It does not activate messaging,
change routing, persist a new receipt, create a production patient, expose operational
metadata, approve an SLA, authorize a pilot, migrate data, or deploy an application.

## 2026-07-25 — Native enrollment invitation-validation parity ratification

### Completed implementation

- Reconciled the pre-submission enrollment behavior across the two native patient apps.
  Android now has one shared `clientValidationMessage` rule used by both its Compose form
  and view model; it validates a parseable UUID invitation ID, a 32-character minimum
  invitation token, six-character minimum verification code, nonblank display name,
  basic email shape, and a matching 12-character minimum password pair. The sign-in
  control now has the same basic email-and-nonempty-password guard as iOS.
- The Android **Continue securely** action and its IME path are disabled/short-circuited
  until that check succeeds. The test-only gateway proves a malformed invitation causes
  zero enrollment network calls. Field test tags make the rendered gate explicit rather
  than relying on position or copy alone.
- iOS now also trims the name and email before enabling **Verify and join**, preventing a
  whitespace-only display name from passing the visual gate. Its rendered test exercises
  the disabled initial state and the enabled plausible-invitation state. The test dismisses
  the iOS simulator's native strong-password suggestion before entering its synthetic
  password; that system sheet otherwise consumes the automated first keystroke.
- These are deliberately client-side plausibility checks, not authorization. The existing
  server enrollment transaction remains the authority for the challenge hash and lock,
  verified identity link, active encounter grant, one-time use, retry/lock behavior, and
  password policy. Manual invitation entry is available; a camera or QR-scanning workflow
  is not claimed or implemented.

### Verification

| Boundary                    | Command / target                                                                                                        | Result                                                                                                          |
| --------------------------- | ----------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| Server enrollment authority | `php artisan test tests/Feature/Patient/PatientAuthLifecycleTest.php`                                                   | 6 passed / 74 assertions; covers challenge-bound activation, locking, and invalid-attempt audit behavior        |
| Android client gate         | Debug JVM: `PatientAppViewModelTest`                                                                                    | 22 passed / 0 failures; includes malformed invitation with zero enrollment calls                                |
| Android rendered form       | API 35 `hb` emulator: `PatientAuthenticationSmokeTest`                                                                  | 2 passed / 0 failures, errors, or skips; begins disabled and enables only after a plausible complete invitation |
| Android static analysis     | `:app:lintDebug`                                                                                                        | passed                                                                                                          |
| iOS rendered form           | iPhone 17 Pro / iOS 26.3.1: `PatientReferenceJourneyUITests/testEnrollmentRequiresACompleteInvitationBeforeItCanSubmit` | 1 passed / 0 failures or skips                                                                                  |

### Remaining boundary

This closes the manual native-entry parity item only. It does not deliver an enrollment
challenge, perform approved identity proofing, confirm the patient/encounter before
activation, create or activate a production patient, enable a patient flag, scan a QR
code, approve a clinical/patient content source, authorize a pilot, migrate data, or
deploy an application. Secure challenge delivery, proofing assurance, wrong-patient
prevention confirmation, accessibility/usability review, clinical governance, and
independent activation authority remain separate required gates.

## 2026-07-25 — No-active-encounter fail-closed parity ratification

### Completed implementation

- Audited the two native session boundaries against the active-grant contract. The
  server exposes only active, non-revoked, in-window grants. Android already converts
  an empty grant collection into a dedicated **No active hospital stay** state; iOS
  previously retained its tab shell with empty care sections.
- iOS now treats an empty current-encounter collection as a hard local content boundary:
  it immediately removes the `PatientExperienceSnapshot`, messaging state,
  device-session state, and account-preference state. The only retained local material is
  the protected session token, allowing the patient to choose **Check again** if a new
  care connection is later granted. **Exit securely** clears that token and returns to
  the normal access screen.
- The patient-facing screen is intentionally generic. It says that no active hospital
  stay is available, keeps the existing immediate-help instructions visible, offers no
  reason for the missing grant, shows no tabs or prior clinical content, and uses the
  existing local Hummingbird imagery beneath opaque readable cards. A Debug-only,
  no-network preview exists solely for simulator coverage and is compiled out of release
  builds.

### Verification

| Boundary                            | Command / target                                                                                                            | Result                                                                                                                                     |
| ----------------------------------- | --------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| iOS state boundary                  | iPhone 17 Pro / iOS 26.3.1: `PatientAppViewModelTests`                                                                      | 25 passed / 0 failures; the new empty-grant test proves no snapshot, no projection calls, generic language, and no messaging/session state |
| iOS rendered/accessibility boundary | iPhone 17 Pro / iOS 26.3.1: `PatientReferenceJourneyUITests/testNoActiveEncounterHidesAllCareTabsAndKeepsUrgentHelpVisible` | 1 passed / 0 failures or skips; reviewed capture shows no tabs, prominent urgent help, readable cards, and the local decorative background |
| Android equivalent baseline         | Debug JVM: `PatientSessionCoordinatorTest.noEncounterReturnsExplicitEmptyState`                                             | passed / 0 failures; proves the active-grant-empty outcome has no Today call and enters the explicit empty state                           |
| Diff safety                         | `git diff --check`                                                                                                          | passed                                                                                                                                     |

### Remaining boundary

This aligns the client behavior for an already empty active-grant response. It does not
define an approved post-discharge portal/retention policy, infer a discharge/transfer/
merge/correction reason, poll or push a lifecycle event while the app is open, create or
activate a production patient, enable a feature flag, approve a patient source, authorize
a pilot, migrate data, or deploy an application. Those source, policy, operational, and
deployed end-to-end requirements remain separate gates.

## 2026-07-25 — Enrollment principal/grant and opaque-handle boundary ratification

### Completed implementation

- Confirmed the complete server-side enrollment transition rather than treating the
  native invitation form as proof: a single locked transaction validates the bound,
  verified identity link and active grant; activates the pending patient principal and
  grant; consumes the challenge; writes the audit fact; and issues the patient session.
- Confirmed that the patient realm does not define raw MRN, patient-reference,
  encounter-reference, or bearer-secret columns. Source linkage material remains
  protected server-side; it is not an app contract.
- Strengthened the patient encounter API boundary regression. Its response is an explicit
  allowlist of opaque encounter/grant UUIDs, relationship, scopes, validity, and version;
  the regression now asserts that no MRN, patient reference, encounter reference, source
  ID, or source-linkage field appears. The iOS and Android patient decoders consume that
  opaque UUID contract rather than source identifiers.

### Verification

| Boundary                                      | Command / target                                                                                                                                                                         | Result                     |
| --------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------- |
| Enrollment, storage, and serialization bounds | `php artisan test --compact tests/Feature/Patient/PatientAuthLifecycleTest.php tests/Feature/Patient/PatientIdentityFoundationTest.php tests/Feature/Patient/PatientApiBoundaryTest.php` | 30 passed / 618 assertions |
| Markdown and diff integrity                   | `npx --no-install prettier --check <two changed Markdown files>` and `git diff --check`                                                                                                  | passed                     |

### Remaining boundary

This closes the already-implemented principal/grant and opaque mobile-handle checklist
row. It does not perform approved identity proofing, show a sufficient wrong-patient
confirmation, create or activate a production patient, enable a flag, authorize a pilot,
migrate data, or deploy an application.

## 2026-07-25 — Foreground patient-access revalidation parity

### Completed implementation

- Both native patient apps now keep their privacy cover in place when returning from the
  background while they revalidate the protected session and active encounter grant. The
  prior care snapshot is not made visible during that check.
- A confirmed empty encounter collection removes the entire local care surface: snapshot,
  messages, device-session view, and account preferences. The patient sees only a generic
  **No active hospital stay** screen, immediate-help guidance, **Check again**, and
  **Exit securely**. The protected token is retained only for the patient's explicit
  recheck; exit clears it.
- A transient foreground verification failure is deliberately fail-closed rather than
  showing potentially stale care content. Both apps use a distinct, generic **We can’t
  confirm your care access** state with the same urgent-help and explicit retry/exit
  controls. It does not disclose a technical failure, encounter history, or a reason an
  access grant may be absent.
- iOS uses a scene-phase gate and Android uses an activity-lifecycle gate. Debug-only,
  no-network previews make the withheld-content state renderable in native automation;
  the Android hook is compiled from its Debug source set and the iOS preview is enclosed
  in `#if DEBUG`.
- The Android privacy-cover instrumentation journey now backgrounds the task to
  `Lifecycle.State.CREATED` rather than relying on the emulator-sensitive intermediate
  `STARTED` transition. This still invokes `onPause`, which is the production boundary
  that applies the privacy cover, and it resumes through the same foreground-revalidation
  path.

### Verification

| Boundary                                | Command / target                                                                                                                         | Result                                                                                                                            |
| --------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| iOS model access boundary               | iPhone 17 Pro / iOS 26.3.1: `PatientAppViewModelTests`                                                                                   | 27 passed / 0 failures; empty-grant and transport-failure rechecks purge the care surface while retaining retry-only tokens       |
| iOS rendered/accessibility boundary     | iPhone 17 Pro / iOS 26.3.1: `testForegroundAccessVerificationFailureHidesAllCareTabsAndKeepsUrgentHelpVisible` and no-active counterpart | 2 passed / 0 failures; reviewed capture shows opaque readable cards, urgent help, decorative imagery, retry/exit, and no tabs     |
| Android model access boundary           | Debug JVM: `PatientAppViewModelTest`, `PatientSessionCoordinatorTest`, and `PatientLaunchHooksTest`                                      | 46 passed / 0 failures; vanished-grant and verification-unavailable states leave no care snapshot, messaging, or preference state |
| Android rendered/accessibility boundary | API 35 `hb` emulator: full `connectedDebugAndroidTest` patient suite                                                                     | 16 passed / 0 failures; explicit empty and access-verification-unavailable states render without care content                     |
| Android static analysis                 | `:app:lintDebug`                                                                                                                         | passed                                                                                                                            |

### Remaining boundary

This ratifies strict client-side revalidation on foreground return. It does not establish
an approved patient-data retention rule after discharge, source-driven transfer/merge/
correction semantics, active-session revocation push or polling, identity proofing, a
production patient, feature activation, pilot authorization, migration, or deployment.

## 2026-07-25 — Staff action-capability transition fails closed

### Completed implementation

- Corrected a PR-discovered staff communications safety defect. During a protected
  server-transition response, the routing projection can intentionally omit mutable-action
  capability data while it is being replaced or withdrawn. The web inbox previously assumed
  the field existed and could crash before it removed obsolete source detail.
- The work-item type now records that capability data can be absent. Every claim/reply/close
  affordance treats that absence as **no authority**; retained-versus-refreshed action
  differences still count as projection drift and trigger the existing stale-detail purge.
- The transition-polling regression uses an actionless source row/detail and proves that
  content may remain readable only as already-authorized detail while **Assign to me**,
  **Send response**, and **Close communication** are all absent. No client-side fallback
  reconstructs permission or emits a mutation.

### Verification

| Boundary                       | Command / target                                            | Result                                                               |
| ------------------------------ | ----------------------------------------------------------- | -------------------------------------------------------------------- |
| Focused transition regression  | `vitest run PatientCommunicationTransitionPolling.test.tsx` | 1 file / 7 tests passed; omitted action capabilities withhold writes |
| Full frontend regression suite | `npm test`                                                  | 147 files / 655 tests passed                                         |
| Formatting and diff integrity  | Prettier target check and `git diff --check`                | passed                                                               |

### Remaining boundary

This fixes a fail-closed web presentation defect in the existing staff communication
slice. It does not add a recipient, modify server-side authorization, establish a
responsibility-pool/coverage feed, enable messaging, approve a pilot, create a production
patient, migrate data, or deploy an application.

## 2026-07-25 — Deterministic mobile BFF fixture ratification

### Completed implementation

- Diagnosed two exact-SHA CI fixture-regeneration failures as test-runtime artifacts, not a
  BFF behavior change: staff patient-context handles are correctly derived from the runtime's
  dedicated signing key, and the Flow barrier action's identifier correctly derives from a
  database sequence.
- Hardened the fixture comparator so it normalizes only those opaque/runtime values. Distinct
  patient-context handles retain distinct canonical placeholders, while repeated references
  retain their equality relationship; the BFF still must provide a valid opaque-handle grammar
  in every expected field.
- Added a direct regression covering repeated versus distinct patient handles, plus dynamic
  barrier IDs and their action endpoint. This keeps the native shared-fixture gate meaningful
  without pinning a deployment-specific signing key or database sequence value in source.

### Verification

| Boundary                                   | Command / target                                                                                                           | Result                                                                                                    |
| ------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------- |
| Fresh-key shared DTO and Flow fixture gate | `HUMMINGBIRD_PATIENT_CONTEXT_KEY=<test key> php artisan test SharedDtoFixtureRegenerationTest FlowFixtureRegenerationTest` | 4 passed / 44 assertions; fixture grammar, alias relationship, and sequence handling remain deterministic |
| PHP formatting and diff integrity          | `./vendor/bin/pint --test <two changed PHP files>` and `git diff --check`                                                  | passed                                                                                                    |

### Remaining boundary

This ratifies CI determinism for the existing native shared-fixture evidence. It does not change
the patient-context key policy, disclose source patient identifiers, enable any patient feature,
replace the production signing key, approve a source, authorize a pilot, migrate data, or deploy
an application.

## 2026-07-25 — Durable staff-inbox handoff claim leases

### Completed implementation

- Strengthened the content-free staff-inbox outbox consumer with immutable, time-bounded
  `claimed` delivery-attempt facts. An advisory candidate read is followed by an outbox row lock
  and a second eligibility decision before a worker can claim work; processing and failure
  recording both require that same claim UUID and attempt number to remain current.
- A second worker cannot process an active claim. If a worker stalls past its bounded lease, the
  next worker first appends a content-free `handoff_claim_lease_expired` recovery fact and only
  then obtains a new immutable claim. This preserves an auditable sequence without storing message
  content or silently mutating the original outbox fact.
- Added configuration for the lease duration, bounded to 30–900 seconds. The default is 120
  seconds. The aggregate heartbeat remains degraded while any unexpired staff-inbox outbox fact is
  unresolved, including an in-flight or retrying handoff.

### Verification

| Boundary                                                                                  | Command / target                                                                    | Result                     |
| ----------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------- | -------------------------- |
| Staff-inbox durable claim, duplicate-worker, expired-lease, retry, and readiness behavior | `php artisan test tests/Feature/Patient/PatientStaffMessageHandoffConsumerTest.php` | 13 passed / 125 assertions |
| PHP syntax and diff integrity                                                             | `php -l` on consumer/config and `git diff --check`                                  | passed                     |

### Remaining boundary

This makes the existing staff-inbox consumer's in-flight work durable and recoverable. It does not
implement projection or push consumers, dead-letter remediation operations, alerting/SLOs,
production runbooks, a patient activation, a production patient, a migration, or a deployment.

## 2026-07-25 — Content-free staff-handoff health and incident boundary

### Completed implementation

- Added `hummingbird:patient-message-handoff-health`, a read-only aggregate report for the
  staff-inbox delivery ledger. It reports only activation state, schema readiness, consumer
  heartbeat freshness, and counts for pending, active-lease, expired-lease, retry, terminal,
  delivered, and unknown delivery states; it does not emit patient, encounter, thread, outbox,
  worker, routing, or message data.
- The reporter distinguishes a fresh-but-degraded consumer from a missing/stale heartbeat. A
  scheduled retry is a warning; missing schema/heartbeat, terminal delivery failure, or an
  unrecognized immutable attempt state is critical and returns a nonzero command exit code.
- Registered the same aggregate reporter as the required `patient_message_handoff` Admin System
  Health component. It is healthy while both staff-handoff governance gates are off, warns for a
  partial activation, and, once active, records only content-free counts/statuses in the
  append-only health ledger. A transition into critical emits exactly one existing operational
  alert; a persistent critical state does not re-page.
- Added the draft [patient-message handoff operator runbook](../operations/HUMMINGBIRD-PATIENT-MESSAGE-HANDOFF-RUNBOOK.md).
  It makes the current boundary explicit: investigate through approved operations, never mutate
  append-only delivery facts or create a duplicate outbox item, and do not treat the report as a
  patient-messaging activation or terminal-requeue capability.

### Verification

| Boundary                                                                                                                                          | Command / target                                                                                       | Result                                        |
| ------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ | --------------------------------------------- |
| Handoff ledger, claim lease, aggregate report, disabled-state liveness, Admin System Health alert transition/deduplication, and content exclusion | `php artisan test tests/Feature/Patient/PatientStaffMessageHandoffConsumerTest.php`                    | 16 passed / 170 assertions                    |
| Native patient revalidation before this backend-only slice (`a87662e1`; native sources unchanged)                                                 | Android API 35 `connectedDebugAndroidTest --rerun-tasks`; iPhone 17 Pro / iOS 26.3.1 `xcodebuild test` | Android 16 passed; iOS 81 passed / 0 failures |

### Remaining boundary

This supplies read-only visibility, transition-deduplicated critical alert evidence, and a safe
incident boundary. It does not implement an approved terminal-failure supersession/requeue
workflow, numeric SLO/response-time ownership, projection/push consumers, production rehearsal,
patient activation, a production patient, a migration, or deployment.

## 2026-07-25 — Patient high-contrast secondary-text correction and native revalidation

### Completed implementation

- Corrected a patient-accessibility defect found during simulator review: the saved **Prefer high
  contrast** setting already removed the decorative Hummingbird scenery and made cards opaque, but
  explanatory copy that explicitly used SwiftUI's semantic secondary color could remain too muted.
- Added one patient-scoped secondary-text modifier. It preserves normal semantic secondary styling
  unless either the operating system has increased contrast or the patient has saved high contrast.
  In high-contrast presentation it uses near-black text on light surfaces and near-white text on
  dark surfaces. This is intentional application behavior; SwiftUI's
  `colorSchemeContrast` environment is system-owned and is not overwritten.
- Applied that modifier to all patient-facing secondary copy across the welcome, Today, My Path,
  Care Team, Messages, account, privacy-cover, loading, shared card, and Hummingbird-background
  surfaces. The existing high-contrast background rule continues to suppress decorative imagery
  rather than reducing its opacity.
- Strengthened the native UI journey so it saves Extra Large text plus high contrast, closes the
  preferences sheet, verifies the exposed `patient-presentation-extra_large-high-contrast` state,
  verifies the visible reading-preferences notice, and captures the care screen. The capture used
  only the debug synthetic-reference scenario and visibly labels it as not a real patient.

### Verification

| Boundary                           | Command / target                                                                                                                                                 | Result                                                                                                                    |
| ---------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| Focused saved-preference journey   | iPhone 17 Pro / iOS 26.3.1: `PatientSessionManagementUITests/testSavedAccessibilityPreferencesApplyAHighContrastExtraLargeCareView`                              | 1 passed / 0 failures; saved state and visible notice verified                                                            |
| Full iOS patient target            | `xcodebuild test -project HummingbirdPatient.xcodeproj -scheme HummingbirdPatient -destination 'platform=iOS Simulator,id=0A7FAE8C-8902-462D-BB4D-1E216D5BFDC1'` | 81 passed / 0 failures on iPhone 17 Pro / iOS 26.3.1                                                                      |
| Visual simulator review            | Focused-test screenshot attachment                                                                                                                               | reviewed: no decorative imagery, high-contrast text/borders, Extra Large text, and synthetic-reference disclosure visible |
| Android patient regression context | API 35 `hb` emulator: `connectedDebugAndroidTest --rerun-tasks` before this iOS-only change                                                                      | 16 passed / 0 failures; Android source was not changed by this correction                                                 |

### Remaining boundary

This makes the saved high-contrast choice deterministic for patient explanatory copy and adds
device evidence for the synthetic reference scenario. It does not establish WCAG 2.2 AA
conformance, complete VoiceOver/TalkBack or language-access validation, ratify a clinical release,
activate any feature, create a production patient, migrate data, or deploy an application.

## 2026-07-25 — Android/iOS persisted-presentation state parity

### Completed implementation

- Corrected the Android patient root test tag so it carries both normalized saved dimensions:
  text size (`standard`, `large`, or `extra_large`) and contrast
  (`standard-contrast` or `high-contrast`). The prior tag encoded only contrast, which made the
  Android emulator proof weaker than the corresponding iOS accessibility identifier.
- Unknown text-size values fail safely to the `standard` tag rather than creating an arbitrary
  automation state. The Android unit test covers standard, Extra Large/high-contrast, and unknown
  text-size inputs.
- Updated the synthetic API 35 journey to save Extra Large plus high contrast, close the
  preferences screen, and require `patient-presentation-extra_large-high-contrast` before it
  checks the visible in-care reading-preferences notice. This is an evidence/semantics correction;
  Android's existing high-contrast Material palette and no-scenery policy are unchanged.

### Verification

| Boundary                                 | Command / target                                                                                     | Result                                                                          |
| ---------------------------------------- | ---------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| Android presentation-state unit boundary | `testDebugUnitTest --tests net.acumenus.hummingbird.patient.ui.PatientPresentationAccessibilityTest` | 2 passed / 0 failures                                                           |
| Android connected patient regression     | API 35 `hb` emulator: `connectedDebugAndroidTest --rerun-tasks`                                      | 16 passed / 0 failures                                                          |
| iOS cross-platform comparator            | iPhone 17 Pro / iOS 26.3.1 suite from the preceding high-contrast change                             | 81 passed / 0 failures; iOS source was unchanged by this Android-only increment |

### Remaining boundary

This aligns native persisted-presentation evidence. It does not complete a system accessibility
matrix, formal contrast measurement, VoiceOver/TalkBack review, localization, clinical content
approval, production enrollment, a production patient, migration, or deployment.

## 2026-07-25 — Safe local synthetic-reference test-database teardown

### Completed implementation

- Tightened the PHPUnit isolated-database teardown after it encountered a session that the test
  role was not permitted to terminate. The teardown previously attempted to terminate every
  connection to its random `zephyrus_test_<12-hex>` database before dropping it.
- The query now terminates only sessions owned by `current_user`. This preserves the required
  cleanup of the test runner's own connections while avoiding an attempt to terminate an
  administrator, monitoring, or backup connection. Test bootstrap continues to fail before
  provision if the database host is not loopback or the database name is not test-scoped.
- Added a support-level regression that locks the ownership predicate into the teardown query. A
  second complete run of the guarded synthetic-reference provisioner suite exited without the
  previous cleanup warning.

### Verification

| Boundary                        | Command / target                                                                                                                      | Result                                         |
| ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------- |
| Test-database teardown syntax   | `php -l tests/Support/IsolatedTestDatabase.php` and the new support test                                                              | passed                                         |
| Reference-patient safety matrix | `php artisan test` for the isolated-database support test plus reference encounter, identity, and draft-projection provisioner suites | 20 passed / 254 assertions; no cleanup warning |

### Remaining boundary

This hardens local test isolation only. It does not inspect, create, activate, or enroll a patient
in production; issue a production credential; publish a draft; enable a feature; migrate data; or
deploy an application.

## 2026-07-25 — Explicit cross-platform patient-background crop contract

### Completed implementation

- Replaced reliance on the native image-view defaults with named, static centered aspect-fill
  policies in both patient renderers. Android supplies `ContentScale.Crop` plus
  `Alignment.Center`; iOS supplies `.fill` plus `.center`. The photography remains decorative,
  static, and clipped to the viewport — it does not pan, parallax-scroll, or animate behind care
  content.
- Added Android JVM and iOS XCTest assertions for the crop policy. This makes a refactor that
  changes the focal-point behavior visible before it reaches a patient build.
- Re-ran the Android API 35 patient suite after the renderer change, including the scenic surface
  at 200% font scale. The full suite passed with 16 tests and no failures.

### Verification

| Boundary                                      | Command / target                                                                                                                                                                                                         | Result                           |
| --------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------- |
| Android crop-policy unit boundary             | `./gradlew testDebugUnitTest --tests net.acumenus.hummingbird.patient.ui.PatientVisualAssetPolicyTest --rerun-tasks`                                                                                                     | 4 passed / 0 failures            |
| Android release crop-policy unit boundary     | `./gradlew testReleaseUnitTest --tests net.acumenus.hummingbird.patient.ui.PatientVisualAssetPolicyTest --rerun-tasks`                                                                                                   | 4 passed / 0 failures            |
| Android patient scenic surface and regression | API 35 `hb` emulator: `./gradlew connectedDebugAndroidTest --rerun-tasks`                                                                                                                                                | 16 passed / 0 failures / 0 skips |
| iOS crop-policy unit boundary                 | iPhone 17 Pro: `xcodebuild -project HummingbirdPatient.xcodeproj -scheme HummingbirdPatient -destination 'platform=iOS Simulator,name=iPhone 17 Pro' -only-testing:HummingbirdPatientTests/PatientAPIBoundaryTests test` | 13 passed / 0 failures           |
| iOS patient regression                        | iPhone 17 Pro / iOS 26.3.1: `xcodebuild -project HummingbirdPatient.xcodeproj -scheme HummingbirdPatient -destination 'platform=iOS Simulator,name=iPhone 17 Pro' test`                                                  | 83 passed / 0 failures / 0 skips |

### Remaining boundary

This removes framework-default ambiguity from the runtime crop policy. It does not establish
asset rights, approve the visible focal point on every supported viewport, prove contrast against
every composited region, exercise an unavailable-image path, complete independent accessibility
review, activate a patient feature, create a production patient, migrate data, or deploy.

## 2026-07-25 — Reproducible Android scenic-layer contrast gate

### Completed implementation

- Added `scripts/audit-hummingbird-patient-scenic-contrast.php`, a local-file-only PHP/GD audit
  that scans every pixel of all four bundled Android derivatives. It reproduces the default scenic
  composition: 46% image alpha over the active Material surface, followed by the exact 68% ->
  84% -> 96% vertical surface scrim. The script reports the worst-case file and coordinates for
  every evaluated semantic foreground, fails closed if the Android renderer/theme no longer
  contain the audited literals, and exits nonzero below the WCAG AA normal-text threshold.
- Added the audit to the Hummingbird capability/contracts CI lane and explicitly installed the
  required GD extension in that lane. The existing checksum verifier remains the source/copy
  integrity gate that runs immediately before this visual calculation.
- The full-frame Android minima pass the normal-text gate: light/dark `onSurface` is
  11.591:1/9.727:1 and light/dark `onSurfaceVariant` is 6.634:1/7.393:1. A centered runtime crop
  can remove pixels but cannot create a lower contrast than this full-frame scan.

### Verification

| Boundary                        | Command / target                                               | Result                                  |
| ------------------------------- | -------------------------------------------------------------- | --------------------------------------- |
| PHP syntax                      | `php -l scripts/audit-hummingbird-patient-scenic-contrast.php` | passed                                  |
| Android default scenic contrast | `php scripts/audit-hummingbird-patient-scenic-contrast.php`    | 4 semantic-color checks passed / 0 fail |

### Remaining boundary

This is a reproducible Android default-renderer numerical gate only. It does not test iOS
dynamic system colors, large-text/high-contrast surfaces that intentionally use stricter veils,
an unavailable-image scenario, focal-point suitability on supported viewports, asset licensing,
formal accessibility conformance, patient/family usability, a patient activation, production
data, migration, or deployment.

## 2026-07-25 — Patient-selectable decorative-scenery fallback

### Completed implementation

- Added one default-on, nonclinical `hide_scenery` preference to the patient-only profile contract,
  Laravel validation/persistence allowlist, iOS model, and Android model. The JSON preference object
  already governs account presentation settings, so this is additive and requires no schema migration.
- Both preference screens now offer **Show Hummingbird background images** with patient-readable
  explanation. Turning it off removes decorative photography and retains the same opaque care
  surface, including loading/error cards; high contrast and iOS Reduce Transparency remain stronger
  overrides. The setting never changes care content, urgent-help guidance, routing, or a clinical
  preference.
- Both native care views identify the active choice as **background images off**. Synthetic-reference
  saves remain device-local and explicitly say no patient account changed.

### Verification

| Boundary                               | Command / target                                                                                                 | Result                           |
| -------------------------------------- | ---------------------------------------------------------------------------------------------------------------- | -------------------------------- |
| Backend patient preference contract    | `php artisan test tests/Feature/Patient/PatientApiBoundaryTest.php`                                              | 14 passed / 495 assertions       |
| Android model/rendering policy         | Focused Debug JVM patient decoder, endpoint, presentation, and visual-policy tests                               | passed                           |
| Android saved-preference journey       | API 35 `hb` emulator: `./gradlew connectedDebugAndroidTest --rerun-tasks`                                        | 16 passed / 0 failures / 0 skips |
| iOS model/rendering policy             | iPhone 17 Pro focused `PatientAPIClientTests`, `PatientAPIModelTests`, and `PatientPresentationPreferencesTests` | 26 passed / 0 failures           |
| iOS saved-preference journey           | iPhone 17 Pro / iOS 26.3.1 `testSavedAccessibilityPreferencesApplyAHighContrastExtraLargeCareView`               | 1 passed / 0 failures            |
| iOS full patient regression            | iPhone 17 Pro / iOS 26.3.1 `xcodebuild test`                                                                     | 84 passed / 0 failures / 0 skips |
| Patient contract and source boundaries | `php scripts/verify-hummingbird-patient-contract.php`; accessibility matrix; iOS/Android patient-boundary scans  | passed                           |

### Remaining boundary

This makes image suppression an explicit, patient-controlled presentation fallback. It does not
approve background focal points or asset rights, establish cross-platform system Reduce Transparency
equivalence, complete the full accessibility/device/language matrix, activate a patient feature,
create a production patient, migrate data, or deploy.
